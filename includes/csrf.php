<?php
// ============================================================
// CSRF protection
// ------------------------------------------------------------
// THE PROBLEM THIS SOLVES
// Every page here is authenticated by a session cookie, and the
// browser attaches that cookie to ANY request to this origin -
// including one triggered by a different site the user happens to
// have open. Without a token, a page on the open internet could
// contain:
//
//     <form action="http://shop-pc:8081/Home/admin/manage-users.php"
//           method="post">
//       <input name="add_user" value="1">
//       <input name="username" value="backdoor">
//       <input name="password" value="…">
//       <input name="role" value="admin">
//     </form>
//     <script>document.forms[0].submit()</script>
//
// A manager who merely visits that page while signed in has just
// created an administrator, silently. The same trick voids sales,
// closes the day, deletes records and posts journal entries.
//
// HOW THIS WORKS
// A random token is generated once per session and required on
// every state-changing request. An attacking site can make the
// browser SEND a request, but the same-origin policy stops it
// READING our pages - so it cannot learn the token, and its forged
// request is rejected.
//
// Two ways to present it:
//   * Forms  - a hidden field, emitted by csrfField().
//   * fetch() - an `X-CSRF-Token` header (JSON bodies have no form
//               fields, and this also blocks simple-request forgery
//               because a custom header forces a CORS preflight).
//
// WHY SameSite=Lax IS NOT ENOUGH ON ITS OWN
// Modern browsers default cookies to SameSite=Lax, which already
// blocks most cross-site POSTs. But that is a browser default, not
// a guarantee: older browsers, an embedded webview, or a future
// configuration change all remove it. The token is enforced by the
// server, so it holds regardless of what the client does.
//
// GET requests are never checked - they must not change state
// anyway, and requiring a token on them would break plain links.
// ============================================================

require_once __DIR__ . '/session.php';
appSessionStart();

/** Form field / header name. */
if (!defined('CSRF_FIELD')) { define('CSRF_FIELD', 'csrf_token'); }
if (!defined('CSRF_HEADER')) { define('CSRF_HEADER', 'HTTP_X_CSRF_TOKEN'); }

/**
 * The current session's token, generated on first use.
 *
 * One token for the whole session rather than one per form: a shop
 * user keeps several tabs open (till, stock, reports), and per-form
 * tokens would invalidate each other and produce failures that look
 * random to the user. A session-lifetime token is the right trade
 * here - it is still unguessable and still unreadable cross-origin.
 */
function csrfToken(): string {
    if (empty($_SESSION[CSRF_FIELD])) {
        try {
            $_SESSION[CSRF_FIELD] = bin2hex(random_bytes(32));
        } catch (Throwable $e) {
            // No CSPRNG - fail closed rather than issue a guessable
            // token that would give false assurance.
            error_log('csrf: no secure randomness available: ' . $e->getMessage());
            $_SESSION[CSRF_FIELD] = '';
        }
    }
    return (string)$_SESSION[CSRF_FIELD];
}

/** Hidden input for a form. Echo this inside every POST form. */
function csrfField(): string {
    return '<input type="hidden" name="' . CSRF_FIELD . '" value="'
         . htmlspecialchars(csrfToken(), ENT_QUOTES) . '">';
}

/** The token as an HTML meta tag, for scripts that need to read it. */
function csrfMeta(): string {
    return '<meta name="csrf-token" content="'
         . htmlspecialchars(csrfToken(), ENT_QUOTES) . '">';
}

/** The token supplied by the request, from a field or a header. */
function csrfSubmitted(): string {
    if (isset($_POST[CSRF_FIELD])) { return (string)$_POST[CSRF_FIELD]; }
    if (isset($_SERVER[CSRF_HEADER])) { return (string)$_SERVER[CSRF_HEADER]; }
    return '';
}

/**
 * True when the request carries the right token.
 * hash_equals() so the comparison cannot be probed a byte at a time.
 */
function csrfValid(): bool {
    $expected = (string)($_SESSION[CSRF_FIELD] ?? '');
    $given    = csrfSubmitted();
    if ($expected === '' || $given === '') { return false; }
    return hash_equals($expected, $given);
}

/**
 * Gate a state-changing request. Call this as the FIRST thing inside
 * every POST handler, before anything is read or written.
 *
 * Only POST is checked. On failure nothing is executed: JSON callers
 * get 419 with a machine-readable code, page callers get a flash
 * message and a redirect back to where they were.
 *
 * $redirect - where to send a rejected page request. Defaults to the
 *             current script, which is what the POST/Redirect/GET
 *             convention here expects.
 */
function csrfRequire(?string $redirect = null): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { return; }
    if (csrfValid()) { return; }

    $message = 'That request could not be verified, so nothing was saved. '
             . 'This usually means the page was open for a long time - '
             . 'please reload and try again.';

    error_log('csrf: rejected POST to ' . ($_SERVER['SCRIPT_NAME'] ?? '?')
            . ' from ' . ($_SERVER['REMOTE_ADDR'] ?? '?')
            . ' referer ' . ($_SERVER['HTTP_REFERER'] ?? 'none'));

    // JSON endpoints live under /api/ (same test authDeny() uses).
    //
    // 403 rather than the 419 some frameworks use for this: 419 is not an
    // IANA-registered code, and Apache rewrites unknown codes to 500 -
    // which would tell the caller "server broke" instead of "rejected".
    // The `error: 'csrf'` field is what clients should branch on.
    if (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/api/') !== false) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'ok'      => false,
            'error'   => 'csrf',
            'message' => $message,
        ]);
        exit;
    }

    $_SESSION['inv_flash'] = ['type' => 'danger', 'msg' => $message];
    header('Location: ' . ($redirect ?: basename($_SERVER['SCRIPT_NAME'] ?? 'index.php')));
    exit;
}

/**
 * Roll the token. Called on privilege boundaries - sign in and sign
 * out - so a token captured before a login cannot be used after it.
 */
function csrfRotate(): void {
    unset($_SESSION[CSRF_FIELD]);
    csrfToken();
}
