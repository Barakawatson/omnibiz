<?php
// ============================================================
// "Keep me signed in" — persistent login tokens
// ------------------------------------------------------------
// WHAT THIS REPLACED
// The old implementation set a cookie containing nothing but a
// username in readable JSON:
//
//     setcookie('remember_admin', json_encode(['username' => $u]), …);
//
// login.php trusted it, looked the username up and started a
// session — with no password check and no signature of any kind.
// Anyone who could set a cookie in their own browser could type
// {"username":"admin"} and become an administrator. It was the
// most serious hole in the system.
//
// HOW THIS WORKS INSTEAD  (the selector/validator pattern)
// The cookie holds two random values joined by a colon:
//
//     <selector>:<validator>          e.g. a3f1…:9c2b…
//
//   * selector  — 16 random bytes, hex. Used ONLY to find the row.
//                 It is indexed and unique, so the lookup is one
//                 indexed read and does not depend on the secret.
//   * validator — 32 random bytes, hex. The actual secret. Only its
//                 SHA-256 digest is stored, and it is compared with
//                 hash_equals() so the comparison is constant-time.
//
// Consequences that matter:
//   * The cookie carries no username and no user id, so it cannot
//     be forged or aimed at a particular account.
//   * A database dump cannot be replayed — the digests are not
//     usable as cookies.
//   * Every use rotates the validator, so a cookie captured from a
//     browser stops working as soon as the real user visits again.
//   * A selector that exists with the WRONG validator means someone
//     is using a stolen or guessed cookie. Every token for that user
//     is destroyed, forcing a real password login.
//
// Why SHA-256 and not password_hash(): the validator is 32 bytes of
// CSPRNG output, not a low-entropy password. There is no dictionary
// to defend against, and this runs on every page load — bcrypt here
// would buy nothing and cost real time.
// ============================================================

/** Cookie name. Deliberately NOT the old 'remember_admin'. */
if (!defined('REMEMBER_COOKIE')) { define('REMEMBER_COOKIE', 'mira_remember'); }

/** How long a token stays valid. */
if (!defined('REMEMBER_TTL_DAYS')) { define('REMEMBER_TTL_DAYS', 30); }

/** Most simultaneous remembered devices per user. */
if (!defined('REMEMBER_MAX_PER_USER')) { define('REMEMBER_MAX_PER_USER', 5); }

/**
 * Cookie parameters. `secure` follows the connection rather than being
 * hard-coded: the shop runs on plain HTTP today, and setting `secure`
 * there would silently stop the cookie ever being sent. It turns itself
 * on the moment the app is served over HTTPS.
 */
function rememberCookieParams(int $expires): array {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['SERVER_PORT'] ?? '') == 443)
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    return [
        'expires'  => $expires,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $https,
        'httponly' => true,   // JavaScript can never read it
        'samesite' => 'Lax',  // not sent on cross-site POSTs
    ];
}

/** Drop any expired rows. Cheap, indexed, and keeps the table honest. */
function rememberPurgeExpired(mysqli $conn): void {
    @$conn->query("DELETE FROM admin_remember_tokens WHERE expires_at < NOW()");
}

/**
 * Issue a token for a user and set the cookie.
 * Called only after a real password login.
 */
function rememberIssue(mysqli $conn, int $userId): bool {
    if ($userId <= 0) { return false; }

    try {
        $selector  = bin2hex(random_bytes(16));   // 32 hex chars
        $validator = bin2hex(random_bytes(32));   // 64 hex chars
    } catch (Throwable $e) {
        // No CSPRNG available - refuse rather than fall back to
        // something predictable.
        error_log('rememberIssue: no secure randomness available: ' . $e->getMessage());
        return false;
    }

    $hash    = hash('sha256', $validator);
    $expires = time() + (REMEMBER_TTL_DAYS * 86400);
    $expiry  = date('Y-m-d H:i:s', $expires);
    $agent   = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

    $stmt = $conn->prepare("INSERT INTO admin_remember_tokens
        (user_id, selector, validator_hash, user_agent, expires_at)
        VALUES (?, ?, ?, ?, ?)");
    if (!$stmt) { return false; }
    $stmt->bind_param('issss', $userId, $selector, $hash, $agent, $expiry);
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) { return false; }

    // Keep the number of live devices bounded, oldest first. A user who
    // ticks the box on every till they touch should not accumulate
    // tokens forever.
    $trim = $conn->prepare(
        "DELETE FROM admin_remember_tokens
          WHERE user_id = ? AND id NOT IN (
              SELECT id FROM (
                  SELECT id FROM admin_remember_tokens
                   WHERE user_id = ? ORDER BY created_at DESC LIMIT " . (int)REMEMBER_MAX_PER_USER . "
              ) keep
          )");
    if ($trim) {
        $trim->bind_param('ii', $userId, $userId);
        $trim->execute();
        $trim->close();
    }

    setcookie(REMEMBER_COOKIE, $selector . ':' . $validator, rememberCookieParams($expires));
    return true;
}

/** Expire the cookie in the browser. */
function rememberClearCookie(): void {
    if (isset($_COOKIE[REMEMBER_COOKIE])) {
        setcookie(REMEMBER_COOKIE, '', rememberCookieParams(time() - 3600));
        unset($_COOKIE[REMEMBER_COOKIE]);
    }
    // The pre-fix cookie. Anyone still holding one is carrying a
    // forgeable credential, so clear it on sight and never read it.
    if (isset($_COOKIE['remember_admin'])) {
        setcookie('remember_admin', '', rememberCookieParams(time() - 3600));
        unset($_COOKIE['remember_admin']);
    }
}

/** Delete one token by selector (used on logout). */
function rememberForgetSelector(mysqli $conn, string $selector): void {
    $stmt = $conn->prepare("DELETE FROM admin_remember_tokens WHERE selector = ?");
    if (!$stmt) { return; }
    $stmt->bind_param('s', $selector);
    $stmt->execute();
    $stmt->close();
}

/**
 * Delete every token for a user — the "sign me out everywhere" action.
 * Call this whenever the account's trust changes: password changed,
 * role changed, account deactivated, or a stolen token detected.
 */
function rememberForgetUser(mysqli $conn, int $userId): void {
    if ($userId <= 0) { return; }
    $stmt = $conn->prepare("DELETE FROM admin_remember_tokens WHERE user_id = ?");
    if (!$stmt) { return; }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();
}

/** The selector half of the current cookie, or '' when there is none. */
function rememberCurrentSelector(): string {
    $raw = (string)($_COOKIE[REMEMBER_COOKIE] ?? '');
    if ($raw === '' || strpos($raw, ':') === false) { return ''; }
    [$selector] = explode(':', $raw, 2);
    return preg_match('/^[a-f0-9]{32}$/', $selector) ? $selector : '';
}

/**
 * Validate the cookie and, on success, return the user row and rotate
 * the token. Returns null for every failure — expired, unknown,
 * tampered, deactivated, or an invalid role.
 *
 * The caller is responsible for starting the session. This function
 * deliberately does NOT touch $_SESSION, so it stays testable and has
 * one job.
 */
function rememberValidate(mysqli $conn): ?array {
    $raw = (string)($_COOKIE[REMEMBER_COOKIE] ?? '');
    if ($raw === '') { return null; }

    // Shape check before touching the database.
    $parts = explode(':', $raw, 2);
    if (count($parts) !== 2) { rememberClearCookie(); return null; }
    [$selector, $validator] = $parts;
    if (!preg_match('/^[a-f0-9]{32}$/', $selector)
     || !preg_match('/^[a-f0-9]{64}$/', $validator)) {
        rememberClearCookie();
        return null;
    }

    $stmt = $conn->prepare(
        "SELECT t.id, t.user_id, t.validator_hash, t.expires_at,
                a.username, a.role, a.status
           FROM admin_remember_tokens t
           JOIN `admin` a ON a.id = t.user_id
          WHERE t.selector = ?
          LIMIT 1");
    if (!$stmt) { return null; }
    $stmt->bind_param('s', $selector);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) { rememberClearCookie(); return null; }

    // Expired: remove it rather than leaving it to the purge.
    if (strtotime($row['expires_at']) < time()) {
        rememberForgetSelector($conn, $selector);
        rememberClearCookie();
        return null;
    }

    // The security decision. Constant-time so the comparison cannot be
    // probed a character at a time.
    if (!hash_equals($row['validator_hash'], hash('sha256', $validator))) {
        // A real selector with the wrong secret is not a typo — it is
        // someone replaying or guessing. Burn every token this user has,
        // so the genuine owner is forced back through a password login
        // and the attacker's copy dies with it.
        rememberForgetUser($conn, (int)$row['user_id']);
        rememberClearCookie();
        error_log('remember_me: validator mismatch for user_id ' . (int)$row['user_id']
                . ' - all tokens revoked (possible stolen cookie).');
        return null;
    }

    // The account must still be allowed in. A remembered cookie must
    // never outlive a deactivation or a retired role.
    if (($row['status'] ?? 'active') !== 'active'
        || !in_array($row['role'] ?? '', allSystemRoles(), true)) {
        rememberForgetUser($conn, (int)$row['user_id']);
        rememberClearCookie();
        return null;
    }

    // ---- Rotate ------------------------------------------------------
    // A fresh validator every time, so a cookie copied off this browser
    // is useful only until the real user comes back. The selector is
    // kept, which keeps this a single indexed UPDATE.
    try {
        $newValidator = bin2hex(random_bytes(32));
    } catch (Throwable $e) {
        error_log('remember_me: rotation failed, no CSPRNG: ' . $e->getMessage());
        return null;
    }

    $newHash = hash('sha256', $newValidator);
    $expires = time() + (REMEMBER_TTL_DAYS * 86400);
    $expiry  = date('Y-m-d H:i:s', $expires);

    $upd = $conn->prepare(
        "UPDATE admin_remember_tokens
            SET validator_hash = ?, expires_at = ?, last_used_at = NOW()
          WHERE id = ?");
    if ($upd) {
        $upd->bind_param('ssi', $newHash, $expiry, $row['id']);
        $upd->execute();
        $upd->close();
    }

    setcookie(REMEMBER_COOKIE, $selector . ':' . $newValidator, rememberCookieParams($expires));

    return [
        'id'       => (int)$row['user_id'],
        'username' => $row['username'],
        'role'     => $row['role'],
    ];
}
