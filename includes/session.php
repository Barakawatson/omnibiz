<?php
// ============================================================
// Session bootstrap
// ------------------------------------------------------------
// THE PROBLEM THIS SOLVES
// PHP's own defaults leave the session cookie unprotected, and this
// XAMPP install ships with them: session.cookie_httponly was empty,
// session.cookie_samesite was empty, and session.use_strict_mode was
// 0. That means:
//
//   * Any injected script could read document.cookie and walk away
//     with a signed-in manager's session - the CSRF token lives in
//     the session, so stealing the cookie defeats that too.
//   * The cookie's cross-site behaviour depended entirely on the
//     browser's own default, which is not something to rely on.
//   * PHP accepted ANY session id the browser offered, so an
//     attacker could plant a known id (a link, a stray Set-Cookie
//     from another app on this host) and wait for the victim to log
//     in with it - session fixation.
//
// Fixing this in php.ini would work on this machine and be lost the
// day the shop's XAMPP is reinstalled or the app is copied to
// another PC. Setting it in code means the protection travels with
// the application.
//
// WHY A SEPARATE FILE
// Five entry points start the session - auth.php, csrf.php,
// login.php, logout.php and access-denied.php. Configuration that
// must happen BEFORE session_start() is worthless if one of them
// starts the session first with the old defaults, so they all call
// appSessionStart() and none of them call session_start() directly.
//
// Including this file has no side effects; it only defines
// functions. Nothing starts until appSessionStart() is called.
// ============================================================

/** Session cookie name. Distinct so a neighbouring app on the same
 *  XAMPP host cannot collide with (or clobber) ours at path '/'. */
if (!defined('APP_SESSION_NAME')) { define('APP_SESSION_NAME', 'mira_session'); }

/**
 * Is this request being served over HTTPS?
 *
 * The shop runs on plain HTTP today, so `secure` cannot be hardcoded
 * on - the cookie would simply never be sent and nobody could log in.
 * It turns itself on the moment the app is served over TLS.
 */
function appIsHttps(): bool {
    return (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443)
        || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
}

/**
 * Start the session with hardened cookie attributes.
 *
 * Safe to call repeatedly and from any entry point - it does nothing
 * if a session is already running, which is what makes it a drop-in
 * replacement for the scattered session_start() calls.
 */
function appSessionStart(): void {
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    // Reject a session id that PHP did not itself issue. This is the
    // anti-fixation control; session_regenerate_id() at login (see
    // admin/login.php) is the other half.
    @ini_set('session.use_strict_mode', '1');
    // Never accept the id from a URL - it leaks through Referer
    // headers, browser history and anything the user pastes.
    @ini_set('session.use_only_cookies', '1');
    @ini_set('session.use_trans_sid', '0');

    session_name(APP_SESSION_NAME);

    session_set_cookie_params([
        // 0 = the cookie dies with the browser. Staying signed in is
        // the remember-me token's job (includes/remember_me.php), not
        // a long-lived session cookie.
        'lifetime' => 0,
        // Matches rememberCookieParams() so both of this app's
        // cookies are scoped the same way.
        'path'     => '/',
        'domain'   => '',
        'secure'   => appIsHttps(),
        'httponly' => true,   // JavaScript can never read it
        'samesite' => 'Lax',  // not sent on cross-site POSTs
    ]);

    session_start();
}
