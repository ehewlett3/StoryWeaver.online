<?php
/**
 * StoryWeaver — Session & auth helper.
 *
 * Included by every page via require_once. Starts the session, loads
 * shared helpers, and provides convenience functions for checking
 * the current user's identity and permissions.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/users.php';

// Ensure all required directories and security files exist
sw_ensure_directories();

// Start session if not already active
sw_start_session();

/**
 * Role hierarchy — higher index = more permissions.
 */
define('SW_ROLES', [
    'viewer'      => 0,
    'contributor' => 1,
    'editor'      => 2,
    'admin'       => 3,
]);

/**
 * Get the numeric permission level for a role name.
 *
 * @param string $role Role name.
 * @return int Permission level (0 if unknown).
 */
function role_level(string $role): int
{
    return SW_ROLES[$role] ?? 0;
}

/**
 * Get the currently logged-in user, or null if not authenticated.
 *
 * Reads the user ID from the session, then loads the full user record
 * from users.json. Returns null if session has no user or user no longer exists.
 *
 * @return array|null The user record, or null.
 */
function current_user(): ?array
{
    $uid = $_SESSION['user_id'] ?? null;
    if ($uid === null) {
        return null;
    }
    return user_find_by_id($uid);
}

/**
 * Check whether any user is currently logged in.
 *
 * @return bool
 */
function is_logged_in(): bool
{
    return current_user() !== null;
}

/**
 * Require the current user to have at least the given role.
 *
 * If not logged in, redirects to the login page.
 * If logged in but insufficient role, sends a 403 response.
 *
 * @param string $minimum_role Minimum required role (e.g. 'editor').
 * @return array The current user record (guaranteed to meet the role requirement).
 */
function require_role(string $minimum_role = 'contributor'): array
{
    $user = current_user();

    if ($user === null) {
        flash('error', 'Please log in to continue.');
        redirect(base_url() . '/auth.php?action=login');
    }

    if (role_level($user['role']) < role_level($minimum_role)) {
        http_response_code(403);
        ob_start();
        render_main_nav($user, '');
        $nav = ob_get_clean();
        ob_start();
        render_brand_favicon_links();
        $branding = ob_get_clean();
        echo '<!DOCTYPE html><html><head><title>403 Forbidden</title>'
           . $branding
           . '<link rel="stylesheet" href="' . h(base_url()) . '/_themes/' . h(theme_css()) . '">'
           . '</head><body>'
           . $nav
           . '<div class="sw-container sw-text-center sw-mt-3">'
           . '<h1>403 — Forbidden</h1>'
           . '<p>You do not have permission to access this page.</p>'
           . '<a href="' . h(base_url()) . '/index.php">← Back to home</a>'
           . '</div></body></html>';
        exit;
    }

    return $user;
}

/**
 * Log in a user by storing their ID in the session.
 *
 * Regenerates the session ID to prevent session fixation.
 *
 * @param string $user_id The user's ID.
 * @return void
 */
function auth_login(string $user_id): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = $user_id;
}

/**
 * Log out the current user by destroying the session.
 *
 * @return void
 */
function auth_logout(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'] ?? session_cookie_path(),
            'domain' => $params['domain'] ?? '',
            'secure' => (bool) ($params['secure'] ?? request_is_https()),
            'httponly' => (bool) ($params['httponly'] ?? true),
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }

    session_destroy();
}
