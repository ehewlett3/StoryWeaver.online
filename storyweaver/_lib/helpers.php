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

/**
 * Detect whether the current request is using HTTPS.
 */
function request_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $forwarded = array_map('trim', explode(',', (string) $_SERVER['HTTP_X_FORWARDED_PROTO']));
        foreach ($forwarded as $proto) {
            if (strtolower($proto) === 'https') {
                return true;
            }
        }
    }

    return (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
}

/**
 * Get the cookie path for the current installation.
 */
function session_cookie_path(): string
{
    $base = base_url();
    return $base === '' ? '/' : $base . '/';
}

/**
 * Start the application session with hardened cookie settings.
 */
function sw_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name('storyweaver_session');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');

    if (!headers_sent()) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => session_cookie_path(),
            'domain' => '',
            'secure' => request_is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    session_start();
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
    sw_start_session();
    $_SESSION['_flash'][$type][] = $message;
}

/**
 * Retrieve and clear all flash messages.
 *
 * @return array Associative array keyed by type, each containing an array of messages.
 */
function get_flashes(): array
{
    sw_start_session();
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

/**
 * Build an application URL using extensionless public routes.
 *
 * @param string $route Route name without extension, e.g. "help" or "node".
 * @param array  $query Optional query parameters.
 * @return string URL path relative to the current installation root.
 */
function app_url(string $route = 'index', array $query = []): string
{
    $base = rtrim(base_url(), '/');
    $route = trim($route, '/');

    if ($route === '' || $route === 'index') {
        $url = $base === '' ? '/' : $base . '/';
    } else {
        $url = ($base === '' ? '' : $base) . '/' . $route;
    }

    if (!empty($query)) {
        $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    return $url;
}

/**
 * Build a URL for the auth controller.
 */
function auth_url(string $action, array $query = []): string
{
    return app_url('auth', array_merge(['action' => $action], $query));
}

/**
 * Build a URL for the API controller.
 */
function api_url(string $action = '', array $query = []): string
{
    if ($action !== '') {
        $query = array_merge(['action' => $action], $query);
    }
    return app_url('api', $query);
}

/**
 * Build a URL for viewing a story node.
 */
function node_url(string $story_id, string $node_id): string
{
    return app_url('node', ['story' => $story_id, 'id' => $node_id]);
}

/**
 * Build a URL for editing a story node.
 */
function edit_url(string $story_id, string $node_id): string
{
    return app_url('edit', ['story' => $story_id, 'id' => $node_id]);
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
    sw_start_session();
    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf_token'];
}

/**
 * Get a validated host name for absolute URLs and mail headers.
 */
function request_host(): string
{
    $candidates = [
        $_SERVER['SERVER_NAME'] ?? '',
        $_SERVER['HTTP_HOST'] ?? '',
    ];

    foreach ($candidates as $candidate) {
        $candidate = trim((string) $candidate);
        if ($candidate === '') {
            continue;
        }

        if (preg_match('/^\[([a-f0-9:.]+)\](?::\d{1,5})?$/i', $candidate, $m)) {
            $host = $m[1];
        } else {
            $host = preg_replace('/:\d{1,5}$/', '', $candidate);
        }

        if (!is_string($host) || $host === '') {
            continue;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $host;
        }

        if (preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i', $host)) {
            return strtolower($host);
        }
    }

    return 'localhost';
}

/**
 * Get the request origin for building absolute URLs.
 */
function request_origin(): string
{
    $scheme = request_is_https() ? 'https' : 'http';
    $host = request_host();
    $forwarded_port = (string) ($_SERVER['HTTP_X_FORWARDED_PORT'] ?? '');
    if ($forwarded_port !== '' && ctype_digit($forwarded_port)) {
        $port = (int) $forwarded_port;
    } elseif ($scheme === 'https' && !empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $port = 443;
    } else {
        $port = (int) ($_SERVER['SERVER_PORT'] ?? 0);
    }
    $default_port = $scheme === 'https' ? 443 : 80;

    if ($port > 0 && $port !== $default_port) {
        if (str_contains($host, ':') && $host[0] !== '[') {
            $host = '[' . $host . ']';
        }
        $host .= ':' . $port;
    }

    return $scheme . '://' . $host;
}

/**
 * Build a safe From address for locally generated email.
 */
function mail_from_address(): string
{
    $host = request_host();
    if (!filter_var($host, FILTER_VALIDATE_IP) && str_contains($host, '.')) {
        return 'noreply@' . $host;
    }

    return 'noreply@storyweaver.local';
}

/**
 * Render a POST-only logout control with CSRF protection.
 */
function render_logout_button(string $base_url, string $label = 'Log out'): void
{
    ?>
    <form method="POST" action="<?= h(auth_url('logout')) ?>" style="display:inline; margin:0;">
        <?= csrf_field() ?>
        <button type="submit" style="background:none; border:0; padding:0; color:inherit; font:inherit; cursor:pointer;">
            <?= h($label) ?>
        </button>
    </form>
    <?php
}

/**
 * Render a single top-nav link with icon and active state.
 */
function render_main_nav_link(string $href, string $icon, string $label, bool $active = false): void
{
    ?>
    <li>
        <a href="<?= h($href) ?>"
           class="sw-nav-link<?= $active ? ' sw-nav-link-active' : '' ?>"
           title="<?= h($label) ?>"
           aria-label="<?= h($label) ?>">
            <span class="sw-nav-icon" aria-hidden="true"><?= h($icon) ?></span>
            <span class="sw-nav-label"><?= h($label) ?></span>
        </a>
    </li>
    <?php
}

/**
 * Render shared favicon and app-icon link tags.
 */
function render_brand_favicon_links(): void
{
    $base = base_url();
    ?>
    <link rel="icon" type="image/png" href="<?= h($base) ?>/_assets/sw-fav.png">
    <link rel="apple-touch-icon" href="<?= h($base) ?>/_assets/sw-fav.png">
    <?php
}

/**
 * Render the shared top navigation bar.
 */
function render_main_nav(?array $user, string $active = ''): void
{
    $needs_setup = function_exists('users_exists') && !users_exists();
    ?>
    <nav class="sw-nav">
        <a href="<?= h(app_url('index')) ?>" class="sw-nav-brand" title="StoryWeaver home" aria-label="StoryWeaver home">
            <img src="<?= h(base_url()) ?>/_assets/sw-logo.png" class="sw-nav-brand-icon" alt="" aria-hidden="true">
            <span class="sw-nav-brand-text">StoryWeaver</span>
        </a>
        <ul class="sw-nav-links">
            <?php render_main_nav_link(app_url('index'), '📚', 'Stories', $active === 'stories'); ?>
            <?php render_main_nav_link(app_url('help'), '❓', 'Help', $active === 'help'); ?>

            <?php if ($user !== null): ?>
                <?php render_main_nav_link(app_url('settings'), '⚙️', 'Settings', $active === 'settings'); ?>
                <?php if (role_level((string) ($user['role'] ?? 'viewer')) >= role_level('editor')): ?>
                    <?php render_main_nav_link(app_url('admin'), '🛡️', 'Admin', $active === 'admin'); ?>
                <?php endif; ?>
                <li>
                    <span class="sw-nav-user" title="<?= h((string) ($user['username'] ?? 'User')) ?>" aria-label="<?= h((string) ($user['username'] ?? 'User')) ?>">
                        <span class="sw-nav-icon" aria-hidden="true">👤</span>
                        <span class="sw-nav-label"><?= h((string) ($user['username'] ?? 'User')) ?></span>
                    </span>
                </li>
                <li>
                    <form method="POST" action="<?= h(auth_url('logout')) ?>" class="sw-nav-form">
                        <?= csrf_field() ?>
                        <button type="submit" class="sw-nav-link sw-nav-button" title="Log out" aria-label="Log out">
                            <span class="sw-nav-icon" aria-hidden="true">↪</span>
                            <span class="sw-nav-label">Log out</span>
                        </button>
                    </form>
                </li>
            <?php elseif ($needs_setup): ?>
                <?php render_main_nav_link(auth_url('setup'), '🚀', 'Setup', $active === 'setup'); ?>
            <?php else: ?>
                <?php render_main_nav_link(auth_url('login'), '🔐', 'Log in', $active === 'login'); ?>
                <?php render_main_nav_link(auth_url('register'), '✨', 'Register', $active === 'register'); ?>
            <?php endif; ?>
        </ul>
    </nav>
    <?php
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
        // Return JSON if the request accepts it (e.g. api.php calls)
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $content_type = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($accept, 'application/json') || str_contains($content_type, 'application/json')) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => false, 'error' => 'Invalid or missing CSRF token.']);
        } else {
            echo 'Invalid or missing CSRF token.';
        }
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

/**
 * Read shared site content stored in _data/site_content.json.
 *
 * @return array{announcement: string, announcement_paragraphs: array<int, string>}
 */
function site_content_read(): array
{
    $data = json_read(data_path('site_content.json'), [
        'announcement' => '',
        'announcement_paragraphs' => [],
    ]);

    $paragraphs = [];
    foreach (($data['announcement_paragraphs'] ?? []) as $paragraph) {
        if (!is_string($paragraph)) {
            continue;
        }

        $paragraph = trim($paragraph);
        if ($paragraph !== '') {
            $paragraphs[] = $paragraph;
        }
    }

    return [
        'announcement' => (string) ($data['announcement'] ?? ''),
        'announcement_paragraphs' => $paragraphs,
    ];
}

/**
 * Persist shared site content.
 *
 * @param array{announcement?: string, announcement_paragraphs?: array<int, string>} $data
 */
function site_content_write(array $data): void
{
    $current = site_content_read();
    $merged = array_merge($current, $data);

    $paragraphs = [];
    foreach (($merged['announcement_paragraphs'] ?? []) as $paragraph) {
        if (!is_string($paragraph)) {
            continue;
        }

        $paragraph = trim($paragraph);
        if ($paragraph !== '') {
            $paragraphs[] = $paragraph;
        }
    }

    json_write(data_path('site_content.json'), [
        'announcement' => (string) ($merged['announcement'] ?? ''),
        'announcement_paragraphs' => $paragraphs,
    ]);
}

/**
 * Return the current homepage announcement paragraphs as sanitized HTML fragments.
 */
function site_announcement_paragraphs(): array
{
    $content = site_content_read();
    $paragraphs = $content['announcement_paragraphs'] ?? [];
    if (!empty($paragraphs)) {
        return $paragraphs;
    }

    $legacy = trim((string) ($content['announcement'] ?? ''));
    if ($legacy === '') {
        return [];
    }

    $blocks = preg_split('/\R{2,}/u', $legacy) ?: [$legacy];
    $paragraphs = [];
    foreach ($blocks as $block) {
        $block = trim($block);
        if ($block === '') {
            continue;
        }

        $paragraphs[] = nl2br(h($block), false);
    }

    return $paragraphs;
}

/**
 * Return the current homepage announcement HTML.
 */
function site_announcement_html(): string
{
    $html = '';
    foreach (site_announcement_paragraphs() as $paragraph) {
        $html .= '<p class="sw-para">' . $paragraph . "</p>\n";
    }

    return trim($html);
}

/**
 * Return the current homepage announcement text.
 */
function site_announcement_text(): string
{
    $paragraphs = site_announcement_paragraphs();
    if (empty($paragraphs)) {
        return trim((string) (site_content_read()['announcement'] ?? ''));
    }

    $parts = [];
    foreach ($paragraphs as $paragraph) {
        $text = str_ireplace(['<br />', '<br/>', '<br>'], "\n", $paragraph);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim($text);
        if ($text !== '') {
            $parts[] = $text;
        }
    }

    return trim(implode("\n\n", $parts));
}

/**
 * Save the homepage announcement text.
 */
function site_announcement_save(string $text): void
{
    site_content_write([
        'announcement' => trim(mb_substr($text, 0, 4000)),
        'announcement_paragraphs' => [],
    ]);
}

/**
 * Save the homepage announcement as sanitized rich-text paragraphs.
 *
 * @param array<int, string> $paragraphs
 */
function site_announcement_save_paragraphs(array $paragraphs): void
{
    $clean = [];
    foreach ($paragraphs as $paragraph) {
        if (!is_string($paragraph)) {
            continue;
        }

        $paragraph = trim($paragraph);
        if ($paragraph !== '') {
            $clean[] = $paragraph;
        }
    }

    site_content_write([
        'announcement' => '',
        'announcement_paragraphs' => $clean,
    ]);
}

/* ------------------------------------------------------------------
 * Theme Helpers
 * ----------------------------------------------------------------*/

/**
 * Read the themes configuration.
 *
 * @return array ['active' => string, 'themes' => array]
 */
function built_in_themes(): array
{
    return [
        ['name' => 'StoryWeaver Online', 'file' => 'loom.css'],
        ['name' => 'Classic Light', 'file' => 'default.css'],
        ['name' => 'Dark', 'file' => 'dark.css'],
    ];
}

/**
 * Read the themes configuration.
 *
 * @return array ['active' => string, 'themes' => array]
 */
function themes_read(): array
{
    $data = json_read(data_path('themes.json'));
    $themes = [];
    $seen = [];

    foreach (built_in_themes() as $theme) {
        $file = (string) ($theme['file'] ?? '');
        if ($file === '') {
            continue;
        }
        $themes[] = $theme;
        $seen[$file] = true;
    }

    foreach (($data['themes'] ?? []) as $theme) {
        $file = (string) ($theme['file'] ?? '');
        if ($file === '' || isset($seen[$file])) {
            continue;
        }
        $themes[] = $theme;
        $seen[$file] = true;
    }

    return [
        'active' => $data['active'] ?? 'loom.css',
        'themes' => $themes,
    ];
}

/**
 * Get the active theme CSS filename.
 *
 * @return string e.g. "default.css"
 */
function theme_active(): string
{
    $themes = themes_read();
    return $themes['active'] ?? 'loom.css';
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
