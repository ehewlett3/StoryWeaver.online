<?php
/**
 * StoryWeaver — Shared utility functions.
 *
 * Included by auth_check.php (and therefore every page).
 * Provides atomic file I/O, JSON helpers, CSRF, flash messages, etc.
 */

/** Debug flag — controls error reporting. Override via _data/config.json later. */
define('SW_DEBUG', false);

if (SW_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

/* ------------------------------------------------------------------
 * File I/O
 * ----------------------------------------------------------------*/

/**
 * Write content to a file atomically.
 *
 * Writes to a temporary file first, then renames to the target path.
 * Prevents partial/corrupt files on crash or concurrent access.
 *
 * @param string $path    Destination file path.
 * @param string $content File content to write.
 * @return void
 * @throws RuntimeException on write or rename failure.
 */
function atomic_write(string $path, string $content): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    $bytes = file_put_contents($tmp, $content, LOCK_EX);
    if ($bytes === false) {
        @unlink($tmp);
        throw new RuntimeException("Failed to write temporary file: $tmp");
    }
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException("Failed to rename temporary file to: $path");
    }
}

/**
 * Read and decode a JSON file.
 *
 * @param string $path    Path to the JSON file.
 * @param array  $default Value to return if the file does not exist or is invalid.
 * @return array Decoded JSON data.
 */
function json_read(string $path, array $default = []): array
{
    if (!file_exists($path)) {
        return $default;
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        return $default;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $default;
}

/**
 * Encode data as JSON and write it atomically to a file.
 *
 * @param string $path Path to write.
 * @param array  $data Data to encode.
 * @return void
 */
function json_write(string $path, array $data): void
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    atomic_write($path, $json . "\n");
}

/* ------------------------------------------------------------------
 * ID Generation
 * ----------------------------------------------------------------*/

/**
 * Generate a random ID with a given prefix.
 *
 * Format: prefix + 8 hex chars (e.g. "usr_3f9a1b2c").
 *
 * @param string $prefix ID prefix (e.g. "usr_", "story_", "node_", "key_").
 * @return string Generated ID.
 */
function generate_id(string $prefix = ''): string
{
    return $prefix . bin2hex(random_bytes(4));
}

/**
 * Validate that an ID matches the expected format: prefix + 8 hex chars.
 *
 * Prevents path traversal by rejecting IDs with slashes, dots, etc.
 *
 * @param string $id     The ID to validate.
 * @param string $prefix Expected prefix (e.g. "story_", "node_").
 * @return bool True if the ID is valid.
 */
function validate_id(string $id, string $prefix): bool
{
    return preg_match('/^' . preg_quote($prefix, '/') . '[a-f0-9]{8}$/', $id) === 1;
}

/* ------------------------------------------------------------------
 * Output Helpers
 * ----------------------------------------------------------------*/

/**
 * HTML-escape a string for safe output.
 *
 * @param string|null $str String to escape.
 * @return string Escaped string.
 */
function h(?string $str): string
{
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Send a redirect header and exit.
 *
 * @param string $url URL to redirect to.
 * @return never
 */
function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

/**
 * Check if the current request is a POST.
 *
 * @return bool
 */
function is_post(): bool
{
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

/* ------------------------------------------------------------------
 * Flash Messages (session-based, one-time display)
 * ----------------------------------------------------------------*/

/**
 * Store a flash message in the session.
 *
 * @param string $type    Message type: 'success', 'error', 'info'.
 * @param string $message The message text.
 * @return void
 */
function flash(string $type, string $message): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['_flash'][$type][] = $message;
}

/**
 * Retrieve and clear all flash messages.
 *
 * @return array Associative array keyed by type, each containing an array of messages.
 */
function get_flashes(): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $messages = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $messages;
}

/* ------------------------------------------------------------------
 * Base URL Detection
 * ----------------------------------------------------------------*/

/**
 * Detect the base URL of the StoryWeaver installation.
 *
 * Returns a path like "/storyweaver" (no trailing slash).
 *
 * @return string Base URL path.
 */
function base_url(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $base = rtrim(dirname($script), '/');
    return $base;
}

/* ------------------------------------------------------------------
 * CSRF Protection
 * ----------------------------------------------------------------*/

/**
 * Get (or generate) the current CSRF token.
 *
 * Stores the token in the session. Call this to embed in forms.
 *
 * @return string The CSRF token.
 */
function csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf_token'];
}

/**
 * Render a hidden CSRF input field for use in forms.
 *
 * @return string HTML hidden input element.
 */
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf_token" value="' . h(csrf_token()) . '">';
}

/**
 * Validate the CSRF token from the current POST request.
 *
 * On failure, sends a 403 response and exits.
 *
 * @return void
 */
function csrf_check(string $submitted = ''): void
{
    if ($submitted === '') {
        $submitted = $_POST['_csrf_token'] ?? '';
    }
    if (!hash_equals(csrf_token(), $submitted)) {
        http_response_code(403);
        echo 'Invalid or missing CSRF token.';
        exit;
    }
}

/* ------------------------------------------------------------------
 * Path Helpers
 * ----------------------------------------------------------------*/

/**
 * Get the absolute filesystem path to the StoryWeaver root directory.
 *
 * @return string Directory path (no trailing slash).
 */
function sw_root(): string
{
    return dirname(__DIR__);
}

/**
 * Ensure all required data directories exist with .htaccess protection.
 * Called once per request from auth_check.php.
 */
function sw_ensure_directories(): void
{
    $root = sw_root();
    $deny_htaccess = "# Deny all direct HTTP access to this directory.\nRequire all denied\n";

    $protected_dirs = ['_data', '_mail', 'quarantine'];
    foreach ($protected_dirs as $dir) {
        $dir_path = $root . '/' . $dir;
        if (!is_dir($dir_path)) {
            mkdir($dir_path, 0755, true);
        }
        $htaccess = $dir_path . '/.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, $deny_htaccess);
        }
    }

    // Non-protected data directories
    $open_dirs = ['stories', '_assets/images'];
    foreach ($open_dirs as $dir) {
        $dir_path = $root . '/' . $dir;
        if (!is_dir($dir_path)) {
            mkdir($dir_path, 0755, true);
        }
    }
}

/**
 * Get the absolute path to the _data directory.
 *
 * @return string
 */
function data_path(string $file = ''): string
{
    $path = sw_root() . '/_data';
    if ($file !== '') {
        $path .= '/' . ltrim($file, '/');
    }
    return $path;
}

/* ------------------------------------------------------------------
 * Theme Helpers
 * ----------------------------------------------------------------*/

/**
 * Read the themes configuration.
 *
 * @return array ['active' => string, 'themes' => array]
 */
function themes_read(): array
{
    $data = json_read(data_path('themes.json'));
    if ($data === null) {
        return ['active' => 'default.css', 'themes' => [
            ['name' => 'Default (Light)', 'file' => 'default.css'],
            ['name' => 'Dark', 'file' => 'dark.css'],
        ]];
    }
    return $data;
}

/**
 * Get the active theme CSS filename.
 *
 * @return string e.g. "default.css"
 */
function theme_active(): string
{
    $themes = themes_read();
    return $themes['active'] ?? 'default.css';
}

/**
 * Get the theme CSS path for use in <link> tags on PHP pages.
 *
 * Respects ?preview_theme= query parameter for admins.
 *
 * @return string Theme filename (e.g. "default.css" or "dark.css")
 */
function theme_css(): string
{
    // Preview theme via query param (any page)
    if (isset($_GET['preview_theme'])) {
        $preview = basename($_GET['preview_theme']);
        if (file_exists(sw_root() . '/_themes/' . $preview)) {
            return $preview;
        }
    }
    return theme_active();
}

/**
 * Apply a theme site-wide by rewriting CSS links in all story HTML files.
 *
 * Rewrites <link rel="stylesheet"> tags in every HTML under stories/ and quarantine/.
 * Updates themes.json active field.
 *
 * @param string $theme_file The theme CSS filename (e.g. "dark.css").
 * @return int Number of files rewritten.
 */
function theme_apply(string $theme_file): int
{
    $theme_file = basename($theme_file);
    if (!file_exists(sw_root() . '/_themes/' . $theme_file)) {
        return -1;
    }

    // Update themes.json
    $themes = themes_read();
    $themes['active'] = $theme_file;
    json_write(data_path('themes.json'), $themes);

    // Rewrite all HTML files under stories/ and quarantine/
    $count = 0;
    $dirs = [STORIES_DIR, QUARANTINE_DIR];

    foreach ($dirs as $base_dir) {
        if (!is_dir($base_dir)) continue;
        $files = glob($base_dir . '/*/*.html');
        foreach ($files as $file) {
            $html = file_get_contents($file);
            if ($html === false) continue;

            $new_html = preg_replace(
                '/<link rel="stylesheet" href="[^"]*\/_themes\/[^"]*">/',
                '<link rel="stylesheet" href="../../_themes/' . htmlspecialchars($theme_file) . '">',
                $html
            );

            if ($new_html !== null && $new_html !== $html) {
                atomic_write($file, $new_html);
                $count++;
            }
        }
    }

    return $count;
}
