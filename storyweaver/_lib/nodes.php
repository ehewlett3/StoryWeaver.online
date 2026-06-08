<?php
/**
 * StoryWeaver — Story node HTML template and CRUD helpers.
 *
 * Manages story directories and node HTML files under stories/.
 * Every node is a self-contained HTML file matching the §2.3 template.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/users.php';

/* ------------------------------------------------------------------
 * Constants
 * ----------------------------------------------------------------*/

/** Filesystem path to the stories directory. */
define('STORIES_DIR', sw_root() . '/stories');

/** Filesystem path to the quarantine directory. */
define('QUARANTINE_DIR', sw_root() . '/quarantine');

/**
 * Resolve the filesystem path for a node, checking both stories and quarantine.
 *
 * @return string|null Full path if found, null otherwise.
 */
function node_resolve_path(string $story_id, string $node_id): ?string
{
    $path = STORIES_DIR . '/' . $story_id . '/' . $node_id . '.html';
    if (file_exists($path)) return $path;
    $path = QUARANTINE_DIR . '/' . $story_id . '/' . $node_id . '.html';
    if (file_exists($path)) return $path;
    return null;
}

/* ------------------------------------------------------------------
 * Story Management
 * ----------------------------------------------------------------*/

/**
 * Create a new story directory and its root node.
 *
 * @param string $title     Story title.
 * @param string $author_id User ID of the creator (or 'anonymous').
 * @param array  $paragraphs Optional initial paragraphs for the root node.
 * @param array  $node_meta  Optional root-node metadata fields.
 * @return array ['story_id' => string, 'node_id' => string]
 */
function story_create(string $title, string $author_id = 'anonymous', array $paragraphs = [], array $node_meta = []): array
{
    $story_id = generate_id('story_');
    $story_dir = STORIES_DIR . '/' . $story_id;

    if (!is_dir($story_dir)) {
        mkdir($story_dir, 0755, true);
    }

    $node_id = node_create($story_id, array_merge([
        'parent_id'    => '',
        'choice_taken' => '',
        'author_id'    => $author_id,
        'title'        => $title,
        'paragraphs'   => $paragraphs ?: ['Begin your story here…'],
        'choices'      => [],
    ], $node_meta));

    return ['story_id' => $story_id, 'node_id' => $node_id];
}

/**
 * Find the root node of a story (the one with empty sw-parent-id).
 *
 * @param string $story_id Story ID.
 * @return string|null Root node ID, or null if not found.
 */
function story_find_root(string $story_id): ?string
{
    $dirs = [
        STORIES_DIR . '/' . $story_id,
        QUARANTINE_DIR . '/' . $story_id,
    ];

    foreach ($dirs as $story_dir) {
        if (!is_dir($story_dir)) {
            continue;
        }

        $files = glob($story_dir . '/node_*.html');
        foreach ($files as $file) {
            $html = file_get_contents($file);
            if ($html === false) continue;

            // Root node has empty sw-parent-id
            if (preg_match('/name="sw-parent-id"\s+content=""/i', $html)) {
                return basename($file, '.html');
            }
        }
    }

    return null;
}

/**
 * Get the title of a story by reading its root node.
 *
 * @param string $story_id Story ID.
 * @return string Story title, or the story_id as fallback.
 */
function story_get_title(string $story_id): string
{
    $root_id = story_find_root($story_id);
    if ($root_id === null) {
        return $story_id;
    }

    $node = node_read($story_id, $root_id, true);
    return $node['title'] ?? $story_id;
}

/**
 * Find the dedicated public archive story for homepage announcements.
 *
 * @return array{story_id:string,root_node_id:string}|null
 */
function story_find_announcements_archive(): ?array
{
    foreach ([STORIES_DIR, QUARANTINE_DIR] as $base_dir) {
        foreach (glob($base_dir . '/story_*') ?: [] as $story_dir) {
            if (!is_dir($story_dir)) {
                continue;
            }

            $story_id = basename($story_dir);
            if (!validate_id($story_id, 'story_')) {
                continue;
            }

            $root_id = story_find_root($story_id);
            if ($root_id === null) {
                continue;
            }

            $root = node_read($story_id, $root_id, true);
            if ($root !== null && story_node_is_announcements_archive($root)) {
                return [
                    'story_id' => $story_id,
                    'root_node_id' => $root_id,
                ];
            }
        }
    }

    return null;
}

/**
 * Return whether a parsed root node belongs to the announcements archive.
 */
function story_node_is_announcements_archive(array $node): bool
{
    $meta = is_array($node['sw_meta'] ?? null) ? $node['sw_meta'] : [];
    return (string) ($meta['system_story'] ?? '') === 'announcements';
}

/**
 * Return whether the story is the dedicated announcements archive.
 */
function story_is_announcements_archive(string $story_id): bool
{
    $root_id = story_find_root($story_id);
    if ($root_id === null) {
        return false;
    }

    $root = node_read($story_id, $root_id, true);
    return $root !== null && story_node_is_announcements_archive($root);
}

/**
 * Story-node edit permission, including system-story overrides.
 */
function story_user_can_edit_node(string $story_id, array $node, ?array $user): bool
{
    if ($user === null) {
        return false;
    }

    if (story_is_announcements_archive($story_id)) {
        return ($user['role'] ?? '') === 'admin';
    }

    return ($node['author_id'] ?? '') === ($user['id'] ?? '')
        || role_level((string) ($user['role'] ?? 'viewer')) >= role_level('editor');
}

/**
 * Return whether this user may create or generate child nodes in a story.
 */
function story_user_can_continue_story(string $story_id, ?array $user): bool
{
    if (story_is_announcements_archive($story_id)) {
        return $user !== null && ($user['role'] ?? '') === 'admin';
    }

    return true;
}

/**
 * Archive the current homepage announcement as the newest node in the announcements story.
 *
 * @param array<int, string> $paragraphs Sanitized rich-text fragments.
 * @return array{story_id:string,node_id:string,created:bool}
 */
function story_archive_announcement(array $paragraphs, string $announcement_title, string $admin_user_id): array
{
    $announcement_title = normalize_story_title($announcement_title);
    if ($announcement_title === '') {
        $announcement_title = 'Announcement ' . date('M j, Y');
    }

    $paragraphs = array_values(array_filter($paragraphs, static function ($paragraph): bool {
        return is_string($paragraph) && trim($paragraph) !== '';
    }));

    $archive_paragraphs = array_merge([
        '<strong>' . h($announcement_title) . '</strong>',
    ], $paragraphs);

    $archive = story_find_announcements_archive();
    $meta = [
        'created_by' => $admin_user_id,
        'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'visibility' => 'public',
        'system_story' => 'announcements',
        'hidden_from_index' => true,
        'announcement_title' => $announcement_title,
        'history' => [
            [
                'action' => 'announcement_archived',
                'by' => $admin_user_id,
                'at' => gmdate('Y-m-d\TH:i:s\Z'),
            ],
        ],
    ];

    if ($archive === null) {
        $result = story_create('Past Announcements', $admin_user_id, $archive_paragraphs, [
            'sw_meta' => $meta,
            'visibility' => 'public',
            'shared_user_ids' => [],
        ]);
        return [
            'story_id' => $result['story_id'],
            'node_id' => $result['node_id'],
            'created' => true,
        ];
    }

    $story_id = $archive['story_id'];
    $previous_root_id = $archive['root_node_id'];
    $previous_root = node_read($story_id, $previous_root_id, true);
    $previous_title = 'Previous announcement';
    if ($previous_root !== null) {
        $previous_meta = is_array($previous_root['sw_meta'] ?? null) ? $previous_root['sw_meta'] : [];
        $previous_title = normalize_story_title((string) ($previous_meta['announcement_title'] ?? '')) ?: $previous_title;
    }

    $new_root_id = node_create($story_id, [
        'parent_id' => '',
        'choice_taken' => '',
        'author_id' => $admin_user_id,
        'title' => 'Past Announcements',
        'paragraphs' => $archive_paragraphs,
        'choices' => [
            [
                'id' => 1,
                'text' => $previous_title,
                'node' => $previous_root_id . '.html',
            ],
        ],
        'sw_meta' => $meta,
    ]);

    if ($previous_root !== null) {
        node_update_relationship($story_id, $previous_root_id, $new_root_id, $previous_title);
    }

    return [
        'story_id' => $story_id,
        'node_id' => $new_root_id,
        'created' => false,
    ];
}

/**
 * Permanently delete a full story, including quarantined pages and generated images.
 */
function story_delete(string $story_id): bool
{
    if (!validate_id($story_id, 'story_')) {
        return false;
    }

    $story_dirs = [
        STORIES_DIR . '/' . $story_id,
        QUARANTINE_DIR . '/' . $story_id,
    ];
    $node_ids = [];

    foreach ($story_dirs as $story_dir) {
        foreach (glob($story_dir . '/node_*.html') ?: [] as $node_path) {
            $node_id = basename($node_path, '.html');
            if (validate_id($node_id, 'node_')) {
                $node_ids[$node_id] = true;
            }
        }
    }

    $found_story_dir = false;
    foreach ($story_dirs as $story_dir) {
        if (!is_dir($story_dir)) {
            continue;
        }

        $found_story_dir = true;
        if (!story_delete_directory($story_dir)) {
            return false;
        }
    }

    if (!$found_story_dir) {
        return false;
    }

    foreach (array_keys($node_ids) as $node_id) {
        foreach (glob(sw_root() . '/_assets/images/' . $node_id . '-*') ?: [] as $image_path) {
            @unlink($image_path);
        }
    }

    return true;
}

/**
 * Recursively remove a story-owned directory after validating its location.
 */
function story_delete_directory(string $dir): bool
{
    $real_dir = realpath($dir);
    $allowed_roots = array_filter([
        realpath(STORIES_DIR),
        realpath(QUARANTINE_DIR),
    ]);

    if ($real_dir === false || !is_dir($real_dir)) {
        return false;
    }

    $inside_allowed_root = false;
    foreach ($allowed_roots as $root) {
        if ($real_dir !== $root && str_starts_with($real_dir, $root . DIRECTORY_SEPARATOR)) {
            $inside_allowed_root = true;
            break;
        }
    }
    if (!$inside_allowed_root) {
        return false;
    }

    $items = scandir($real_dir);
    if ($items === false) {
        return false;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $real_dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path) && !is_link($path)) {
            if (!story_delete_directory($path)) {
                return false;
            }
            continue;
        }

        if (!@unlink($path)) {
            return false;
        }
    }

    return @rmdir($real_dir);
}

/**
 * Normalize a story title for storage.
 */
function normalize_story_title(string $title): string
{
    $title = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);
    if ($title === '') {
        return '';
    }

    if (mb_strlen($title, 'UTF-8') > 200) {
        $title = mb_substr($title, 0, 200, 'UTF-8');
    }

    return $title;
}

/**
 * Rename a story by updating the stored title on every node in the story.
 */
function story_update_title(string $story_id, string $title, string $updated_by = ''): bool
{
    $title = normalize_story_title($title);
    if ($title === '') {
        return false;
    }

    $root_id = story_find_root($story_id);
    if ($root_id === null) {
        return false;
    }

    if ($updated_by !== '') {
        $root = node_read($story_id, $root_id, true);
        if ($root !== null) {
            $meta = $root['sw_meta'] ?? [];
            $meta = node_meta_append_history($meta, $updated_by, 'story_renamed');
            node_update_meta($story_id, $root_id, $meta);
        }
    }

    $safe_title = h($title);
    $updated = false;
    $locations = [
        'stories' => STORIES_DIR . '/' . $story_id,
        'quarantine' => QUARANTINE_DIR . '/' . $story_id,
    ];

    foreach ($locations as $location => $story_dir) {
        if (!is_dir($story_dir)) {
            continue;
        }

        foreach (glob($story_dir . '/node_*.html') ?: [] as $file) {
            $html = file_get_contents($file);
            if ($html === false) {
                continue;
            }

            $node = node_parse_html($html, $location);
            $new_html = node_generate_html([
                'story_id'     => $node['story_id'] ?: $story_id,
                'node_id'      => $node['node_id'] ?: basename($file, '.html'),
                'parent_id'    => $node['parent_id'],
                'choice_taken' => $node['choice_taken'],
                'author_id'    => $node['author_id'],
                'title'        => $safe_title,
                'paragraphs'   => $node['paragraphs'],
                'choices'      => $node['choices'],
                'created_at'   => $node['created_at'],
                'flagged'      => $node['flagged'],
                'sw_meta'      => $node['sw_meta'] ?? null,
            ]);

            atomic_write($file, $new_html);
            $updated = true;
        }
    }

    return $updated;
}

/**
 * Get metadata for the most recently added node in a story.
 *
 * Falls back to file modification time when a node lacks a parseable timestamp.
 *
 * @param string $story_id Story ID.
 * @param bool   $include_quarantine Whether to include quarantined nodes.
 * @return array<string, mixed>|null Latest node metadata, or null if none were found.
 */
function story_latest_node_info(string $story_id, bool $include_quarantine = false): ?array
{
    $dirs = [STORIES_DIR . '/' . $story_id];
    if ($include_quarantine) {
        $dirs[] = QUARANTINE_DIR . '/' . $story_id;
    }

    $latest = null;
    $latest_sort = PHP_INT_MIN;
    $latest_mtime = PHP_INT_MIN;

    foreach ($dirs as $story_dir) {
        if (!is_dir($story_dir)) {
            continue;
        }

        $location = str_starts_with($story_dir, QUARANTINE_DIR) ? 'quarantine' : 'stories';
        foreach (glob($story_dir . '/node_*.html') as $file) {
            $html = file_get_contents($file);
            if ($html === false) {
                continue;
            }

            $node = node_parse_html($html, $location);
            $node_id = basename($file, '.html');
            $created_at = strtotime((string) ($node['created_at'] ?? ''));
            $mtime = filemtime($file);
            $mtime = $mtime === false ? 0 : $mtime;
            $sort_value = $created_at !== false ? $created_at : $mtime;

            if (
                $latest === null
                || $sort_value > $latest_sort
                || ($sort_value === $latest_sort && $mtime > $latest_mtime)
            ) {
                $latest = [
                    'node_id' => $node_id,
                    'created_at' => (string) ($node['created_at'] ?? ''),
                    'author_id' => (string) ($node['author_id'] ?? ''),
                    'location' => $location,
                ];
                $latest_sort = $sort_value;
                $latest_mtime = $mtime;
            }
        }
    }

    return $latest;
}

/**
 * Find the most recently added node in a story.
 *
 * @param string $story_id Story ID.
 * @param bool   $include_quarantine Whether to include quarantined nodes.
 * @return string|null Latest node ID, or null if none were found.
 */
function story_find_latest_node(string $story_id, bool $include_quarantine = false): ?string
{
    $latest = story_latest_node_info($story_id, $include_quarantine);
    return is_array($latest) ? (string) ($latest['node_id'] ?? '') ?: null : null;
}

/**
 * Append a history entry to an sw_meta array.
 */
function node_meta_append_history(array $meta, string $user_id, string $action, string $ai_model = ''): array
{
    if (!isset($meta['history']) || !is_array($meta['history'])) {
        $meta['history'] = [];
    }

    $meta['history'][] = [
        'action'   => $action,
        'by'       => $user_id,
        'at'       => gmdate('Y-m-d\TH:i:s\Z'),
        'ai_model' => $ai_model !== '' ? $ai_model : null,
    ];

    return $meta;
}


/**
 * Return normalized story-wide privacy metadata from the root node.
 * Missing metadata is public for backward compatibility.
 *
 * @return array{visibility:string,shared_user_ids:array<int,string>,owner_user_id:string,root_node_id:string|null}
 */
function story_privacy_info(string $story_id): array
{
    $info = [
        'visibility' => 'public',
        'shared_user_ids' => [],
        'owner_user_id' => '',
        'root_node_id' => null,
    ];

    $root_id = story_find_root($story_id);
    if ($root_id === null) {
        return $info;
    }

    $info['root_node_id'] = $root_id;
    $root = node_read($story_id, $root_id, true);
    if ($root === null) {
        return $info;
    }

    $meta = $root['sw_meta'] ?? [];
    $visibility = (string) ($meta['visibility'] ?? 'public');
    $info['visibility'] = $visibility === 'private' ? 'private' : 'public';

    $shared = $meta['shared_user_ids'] ?? [];
    if (is_array($shared)) {
        $info['shared_user_ids'] = array_values(array_unique(array_filter(array_map('strval', $shared), function ($id) {
            return validate_id($id, 'usr_');
        })));
    }

    $created_by = (string) ($meta['created_by'] ?? '');
    if (validate_id($created_by, 'usr_')) {
        $info['owner_user_id'] = $created_by;
    } elseif (validate_id((string) ($root['author_id'] ?? ''), 'usr_')) {
        $info['owner_user_id'] = (string) $root['author_id'];
    }

    return $info;
}

/**
 * Return true when the user can read/play any node in the story.
 */
function story_user_can_access(string $story_id, ?array $user): bool
{
    $info = story_privacy_info($story_id);
    if ($info['visibility'] !== 'private') {
        return true;
    }

    if ($user === null) {
        return false;
    }

    if (($user['role'] ?? '') === 'admin') {
        return true;
    }

    $user_id = (string) ($user['id'] ?? '');
    if ($user_id === '') {
        return false;
    }

    return $user_id === $info['owner_user_id'] || in_array($user_id, $info['shared_user_ids'], true);
}

/**
 * Return true when the user can change a story's privacy/share list.
 */
function story_user_can_manage_access(string $story_id, ?array $user): bool
{
    if ($user === null) {
        return false;
    }

    if (($user['role'] ?? '') === 'admin') {
        return true;
    }

    $info = story_privacy_info($story_id);
    $user_id = (string) ($user['id'] ?? '');
    return $user_id !== '' && $user_id === $info['owner_user_id'];
}

/**
 * Replace root story privacy metadata.
 */
function story_update_privacy(string $story_id, string $visibility, array $shared_user_ids, string $updated_by): bool
{
    $root_id = story_find_root($story_id);
    if ($root_id === null) {
        return false;
    }

    $root = node_read($story_id, $root_id, true);
    if ($root === null) {
        return false;
    }

    $clean_shared = array_values(array_unique(array_filter(array_map('strval', $shared_user_ids), function ($id) {
        return validate_id($id, 'usr_');
    })));

    $meta = $root['sw_meta'] ?? [];
    $meta['visibility'] = $visibility === 'private' ? 'private' : 'public';
    $meta['shared_user_ids'] = $clean_shared;
    $meta['privacy_updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
    $meta['privacy_updated_by'] = $updated_by;
    $meta = node_meta_append_history($meta, $updated_by, 'story_privacy_updated');

    node_update_meta($story_id, $root_id, $meta);
    return true;
}

/**
 * Build public user summaries for a story's share list.
 *
 * @return array<int, array{id:string,username:string,role:string}>
 */
function story_shared_users(array $shared_user_ids): array
{
    $users = [];
    foreach ($shared_user_ids as $user_id) {
        $user = user_find_by_id((string) $user_id);
        if ($user === null) {
            continue;
        }
        $users[] = [
            'id' => (string) ($user['id'] ?? ''),
            'username' => (string) ($user['username'] ?? ''),
            'role' => (string) ($user['role'] ?? ''),
        ];
    }
    return $users;
}

/**
 * Return the root node sw-meta for story-wide settings.
 */
function story_root_meta(string $story_id): array
{
    $root_id = story_find_root($story_id);
    if ($root_id === null) {
        return [];
    }
    $root = node_read($story_id, $root_id, true);
    return is_array($root['sw_meta'] ?? null) ? $root['sw_meta'] : [];
}

/**
 * Return true when story pages should auto-generate images after AI text.
 */
function story_auto_images_enabled(string $story_id): bool
{
    $meta = story_root_meta($story_id);
    return !empty($meta['auto_generate_images']);
}

/**
 * Return the preferred image key ID for story auto-image generation.
 */
function story_auto_image_key_id(string $story_id): string
{
    $meta = story_root_meta($story_id);
    $key_id = (string) ($meta['auto_image_key_id'] ?? '');
    return validate_id($key_id, 'key_') ? $key_id : '';
}

/**
 * Return true when story image generation should include saved guidance.
 */
function story_image_guidance_enabled(string $story_id): bool
{
    $meta = story_root_meta($story_id);
    return !empty($meta['image_guidance_enabled']) && trim((string) ($meta['image_guidance'] ?? '')) !== '';
}

/**
 * Return saved story-level image generation guidance.
 */
function story_image_guidance(string $story_id): string
{
    $meta = story_root_meta($story_id);
    return trim((string) ($meta['image_guidance'] ?? ''));
}

/**
 * Update story-level image generation preferences.
 */
function story_update_image_settings(string $story_id, bool $auto_generate_images, string $image_key_id, bool $guidance_enabled, string $guidance, string $updated_by): bool
{
    $root_id = story_find_root($story_id);
    if ($root_id === null) {
        return false;
    }

    $root = node_read($story_id, $root_id, true);
    if ($root === null) {
        return false;
    }

    $guidance = trim($guidance);
    if (mb_strlen($guidance, 'UTF-8') > 1200) {
        $guidance = mb_substr($guidance, 0, 1200, 'UTF-8');
    }

    $meta = is_array($root['sw_meta'] ?? null) ? $root['sw_meta'] : [];
    $meta['auto_generate_images'] = $auto_generate_images;
    if ($auto_generate_images && validate_id($image_key_id, 'key_')) {
        $meta['auto_image_key_id'] = $image_key_id;
    } elseif (!$auto_generate_images) {
        unset($meta['auto_image_key_id']);
    }
    $meta['image_guidance_enabled'] = $guidance_enabled && $guidance !== '';
    $meta['image_guidance'] = $guidance;
    $meta = node_meta_append_history($meta, $updated_by, 'story_image_settings_updated');
    node_update_meta($story_id, $root_id, $meta);
    return true;
}

/* ------------------------------------------------------------------
 * Node CRUD
 * ----------------------------------------------------------------*/

/**
 * Create a new node in a story.
 *
 * Generates a node ID, builds the HTML from the template, writes the file.
 *
 * @param string $story_id Story ID.
 * @param array  $params   Node parameters:
 *   - parent_id    (string) Parent node ID, or '' for root.
 *   - choice_taken (string) The choice text that led here, or '' for root.
 *   - author_id    (string) User ID or 'anonymous'.
 *   - title        (string) Story title (used in <title> and breadcrumb).
 *   - paragraphs   (array)  Array of paragraph strings.
 *   - choices      (array)  Array of ['id' => int, 'text' => string, 'node' => string|null].
 *   - location     (string) Optional 'stories' or 'quarantine' output location.
 * @return string The generated node ID.
 */
function node_create(string $story_id, array $params): string
{
    $node_id = generate_id('node_');
    $location = ($params['location'] ?? 'stories') === 'quarantine' ? 'quarantine' : 'stories';

    $html = node_generate_html(array_merge($params, [
        'story_id' => $story_id,
        'node_id'  => $node_id,
    ]));

    node_write_file($story_id, $node_id, $html, $location);

    return $node_id;
}

/**
 * Read and parse a node HTML file into structured data.
 *
 * @param string $story_id Story ID.
 * @param string $node_id  Node ID.
 * @param bool   $check_quarantine Also check quarantine/ if not found in stories/.
 * @return array|null Parsed node data, or null if file not found. Keys:
 *   story_id, node_id, parent_id, choice_taken, created_at, author_id,
 *   flagged, title, paragraphs (array), choices (array), raw_html.
 */
function node_read(string $story_id, string $node_id, bool $check_quarantine = false): ?array
{
    $path = STORIES_DIR . '/' . $story_id . '/' . $node_id . '.html';
    $location = 'stories';

    if (!file_exists($path) && $check_quarantine) {
        $path = QUARANTINE_DIR . '/' . $story_id . '/' . $node_id . '.html';
        $location = 'quarantine';
        if (!file_exists($path)) {
            return null;
        }
    } elseif (!file_exists($path)) {
        return null;
    }

    $html = file_get_contents($path);
    if ($html === false) {
        return null;
    }

    return node_parse_html($html, $location);
}

/**
 * Return true when the current user may access a quarantined story.
 */
function story_user_can_access_quarantine(string $story_id, ?array $user): bool
{
    if ($user === null) {
        return false;
    }

    if (role_level((string) ($user['role'] ?? 'viewer')) >= role_level('editor')) {
        return true;
    }

    $root_id = story_find_root($story_id);
    if ($root_id === null) {
        return false;
    }

    $root = node_read($story_id, $root_id, true);
    if ($root === null) {
        return false;
    }

    $user_id = (string) ($user['id'] ?? '');
    $created_by = (string) (($root['sw_meta']['created_by'] ?? ''));

    return $user_id !== '' && (
        ($root['author_id'] ?? '') === $user_id
        || $created_by === $user_id
    );
}

/**
 * Return true when the current user may access a quarantined node.
 */
function node_user_can_access_quarantine(string $story_id, array $node, ?array $user): bool
{
    if (($node['location'] ?? 'stories') !== 'quarantine') {
        return true;
    }

    if ($user === null) {
        return false;
    }

    if (role_level((string) ($user['role'] ?? 'viewer')) >= role_level('editor')) {
        return true;
    }

    $user_id = (string) ($user['id'] ?? '');
    if ($user_id !== '' && ($node['author_id'] ?? '') === $user_id) {
        return true;
    }

    return story_user_can_access_quarantine($story_id, $user);
}

/**
 * Read a node if it is publicly visible or accessible to the given user in quarantine.
 */
function node_read_for_user(string $story_id, string $node_id, ?array $user): ?array
{
    if (!story_user_can_access($story_id, $user)) {
        return null;
    }

    $node = node_read($story_id, $node_id, false);
    if ($node !== null) {
        return $node;
    }

    $node = node_read($story_id, $node_id, true);
    if ($node === null) {
        return null;
    }

    return node_user_can_access_quarantine($story_id, $node, $user) ? $node : null;
}

/**
 * Parse a node's raw HTML into structured data.
 *
 * @param string $html     The raw HTML content.
 * @param string $location Where the node was found ('stories' or 'quarantine').
 * @return array Parsed node data.
 */
function node_parse_html(string $html, string $location = 'stories'): array
{
    $data = [
        'story_id'     => '',
        'node_id'      => '',
        'parent_id'    => '',
        'choice_taken' => '',
        'created_at'   => '',
        'author_id'    => '',
        'flagged'      => 'false',
        'title'        => '',
        'paragraphs'   => [],
        'choices'      => [],
        'raw_html'     => $html,
        'location'     => $location,
    ];

    // Extract meta tags
    $meta_map = [
        'sw-story-id'    => 'story_id',
        'sw-node-id'     => 'node_id',
        'sw-parent-id'   => 'parent_id',
        'sw-choice-taken' => 'choice_taken',
        'sw-created-at'  => 'created_at',
        'sw-author-id'   => 'author_id',
        'sw-flagged'     => 'flagged',
    ];

    foreach ($meta_map as $meta_name => $key) {
        if (preg_match('/name="' . preg_quote($meta_name, '/') . '"\s+content="([^"]*)"/i', $html, $m)) {
            $data[$key] = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }

    // Extract title (before the " — " separator)
    if (preg_match('/<title>([^<]+)<\/title>/i', $html, $m)) {
        $parts = explode(' — ', $m[1], 2);
        $data['title'] = trim($parts[0]);
    }

    // Extract paragraphs / rich blocks from the article body.
    if (class_exists('DOMDocument')) {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded) {
            $xpath = new DOMXPath($dom);
            $article = $xpath->query('//article[contains(concat(" ", normalize-space(@class), " "), " sw-node-content ")]')->item(0);
            if ($article instanceof DOMElement) {
                foreach (iterator_to_array($article->childNodes) as $child) {
                    if ($child->nodeType === XML_TEXT_NODE) {
                        $text = trim((string) $child->textContent);
                        if ($text !== '') {
                            $data['paragraphs'][] = h($text);
                        }
                        continue;
                    }

                    if (!$child instanceof DOMElement) {
                        continue;
                    }

                    $tag = strtolower($child->tagName);
                    if ($tag === 'p' && str_contains(' ' . $child->getAttribute('class') . ' ', ' sw-para ')) {
                        $data['paragraphs'][] = trim(dom_node_inner_html($child));
                        continue;
                    }

                    if (rich_content_tag_is_block($tag)) {
                        $data['paragraphs'][] = trim($dom->saveHTML($child));
                    }
                }
            }
        }
    }

    if (empty($data['paragraphs']) && preg_match_all('/<p class="sw-para">(.*?)<\/p>/s', $html, $matches)) {
        $data['paragraphs'] = $matches[1];
    }

    // Extract choices from data-choices-json attribute
    if (preg_match("/data-choices-json='(\[.*?\])'/s", $html, $m)) {
        $choices = json_decode(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'), true);
        if (is_array($choices)) {
            $data['choices'] = $choices;
        }
    }

    // Extract sw-meta comment block
    $data['sw_meta'] = null;
    if (preg_match('/<!-- sw-meta: ({.*?}) -->/s', $html, $m)) {
        $meta = json_decode($m[1], true);
        if (is_array($meta)) {
            $data['sw_meta'] = $meta;
        }
    }

    return $data;
}

/**
 * Update the paragraphs in an existing node HTML file.
 *
 * Replaces the content inside <article class="sw-node-content"> with
 * new <p class="sw-para"> elements. Does not touch other parts of the HTML.
 *
 * @param string $story_id   Story ID.
 * @param string $node_id    Node ID.
 * @param array  $paragraphs Array of paragraph HTML strings (already sanitized).
 * @return bool True if successful.
 */
function node_update_paragraphs(string $story_id, string $node_id, array $paragraphs): bool
{
    $path = node_resolve_path($story_id, $node_id);
    if ($path === null) {
        return false;
    }

    $html = file_get_contents($path);
    if ($html === false) {
        return false;
    }

    // Build new paragraph HTML
    $para_html = render_rich_content_fragments($paragraphs);

    // Replace the article content
    $pattern = '/(<article class="sw-node-content">)\s*.*?\s*(<\/article>)/s';
    $replacement = "$1\n" . $para_html . "  $2";
    $new_html = preg_replace($pattern, $replacement, $html, 1);

    if ($new_html === null || $new_html === $html && !empty($paragraphs)) {
        return false;
    }

    atomic_write($path, $new_html);
    return true;
}

/**
 * Return the inner HTML of a DOM node.
 */
function dom_node_inner_html(DOMNode $node): string
{
    $html = '';
    foreach (iterator_to_array($node->childNodes) as $child) {
        $html .= $node->ownerDocument?->saveHTML($child) ?? '';
    }
    return $html;
}

/**
 * Whether a tag should be treated as a standalone rich block.
 */
function rich_content_tag_is_block(string $tag): bool
{
    return in_array($tag, [
        'blockquote', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'hr', 'li', 'ol', 'pre', 'style', 'ul',
    ], true);
}

/**
 * Whether a stored fragment is a block element rather than paragraph inner HTML.
 */
function rich_content_fragment_is_block(string $fragment): bool
{
    $fragment = trim($fragment);
    if ($fragment === '') {
        return false;
    }

    if (preg_match('/^<([a-z0-9:-]+)\b/i', $fragment, $match) !== 1) {
        return false;
    }

    return rich_content_tag_is_block(strtolower($match[1]));
}

/**
 * Render stored rich fragments for story/article output.
 *
 * @param array<int, string> $fragments
 */
function render_rich_content_fragments(array $fragments, string $indent = '    ', bool $ensure_empty = true): string
{
    $html = '';
    foreach ($fragments as $fragment) {
        if (!is_string($fragment)) {
            continue;
        }

        $fragment = trim($fragment);
        if ($fragment === '') {
            continue;
        }

        if (rich_content_fragment_is_block($fragment)) {
            $html .= (preg_replace('/^/m', $indent, $fragment) ?? ($indent . $fragment)) . "\n";
        } else {
            $html .= $indent . '<p class="sw-para">' . $fragment . "</p>\n";
        }
    }

    if ($html === '' && $ensure_empty) {
        return $indent . "<p class=\"sw-para\"></p>\n";
    }

    return $html;
}

/**
 * Render a stored fragment for the paragraph editor visual mode.
 */
function render_editor_fragment_html(string $fragment): string
{
    $fragment = trim($fragment);
    if ($fragment === '') {
        return '<p class="sw-para" contenteditable="true"></p>';
    }

    if (!rich_content_fragment_is_block($fragment)) {
        return '<p class="sw-para" contenteditable="true">' . $fragment . '</p>';
    }

    if (preg_match('/^<([a-z0-9:-]+)\b/i', $fragment, $match) !== 1) {
        return '<p class="sw-para" contenteditable="true">' . $fragment . '</p>';
    }

    $tag = strtolower($match[1]);
    if (in_array($tag, ['hr', 'style'], true)) {
        return $fragment;
    }

    if (preg_match('/\bcontenteditable\s*=/i', $fragment) === 1) {
        return $fragment;
    }

    return preg_replace('/^<([a-z0-9:-]+)\b/i', '<$1 contenteditable="true"', $fragment, 1) ?? $fragment;
}

/**
 * Append a modification entry to the node's sw-meta history.
 *
 * @param string $story_id Story ID.
 * @param string $node_id  Node ID.
 * @param string $user_id  Who made the change.
 * @param string $action   Action type (e.g. 'edited', 'ai_regenerated').
 * @param string $ai_model Optional AI model used.
 * @return void
 */
function node_meta_log(string $story_id, string $node_id, string $user_id, string $action, string $ai_model = ''): void
{
    $path = node_resolve_path($story_id, $node_id);
    if ($path === null) return;

    $html = file_get_contents($path);
    if ($html === false) return;

    if (preg_match('/<!-- sw-meta: ({.*?}) -->/s', $html, $m)) {
        $meta = json_decode($m[1], true);
        if (!is_array($meta)) $meta = [];
        $meta = node_meta_append_history($meta, $user_id, $action, $ai_model);
        $new_comment = '<!-- sw-meta: ' . json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ' -->';
        $html = str_replace($m[0], $new_comment, $html);
    } else {
        // No existing meta — inject one after <head>
        $meta = node_meta_append_history([], $user_id, $action, $ai_model);
        $new_comment = '<!-- sw-meta: ' . json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ' -->';
        $html = preg_replace('/<head>/', "<head>\n  " . $new_comment, $html, 1);
    }

    atomic_write($path, $html);
}

/**
 * Replace the sw-meta block in a node's HTML file.
 *
 * @param string $story_id Story ID.
 * @param string $node_id  Node ID.
 * @param array  $meta     Complete metadata array to write.
 */
function node_update_meta(string $story_id, string $node_id, array $meta): void
{
    $path = node_resolve_path($story_id, $node_id);
    if ($path === null) return;

    $html = file_get_contents($path);
    if ($html === false) return;

    $new_comment = '<!-- sw-meta: ' . json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ' -->';

    if (preg_match('/<!-- sw-meta: ({.*?}) -->/s', $html, $m)) {
        $html = str_replace($m[0], $new_comment, $html);
    } else {
        $html = preg_replace('/<head>/', "<head>\n  " . $new_comment, $html, 1);
    }

    atomic_write($path, $html);
}

/**
 * Return true when a choice has not been linked to a child page yet.
 */
function node_choice_is_pending(array $choice): bool
{
    return empty($choice['node']);
}

/**
 * Return all pending choices for a node.
 *
 * @return array<int, array>
 */
function node_pending_choices(array $node): array
{
    $pending = [];
    foreach (($node['choices'] ?? []) as $choice) {
        if (node_choice_is_pending($choice)) {
            $pending[] = $choice;
        }
    }

    return $pending;
}

/**
 * Count pending choices on a node.
 */
function node_pending_choice_count(array $node): int
{
    return count(node_pending_choices($node));
}

/**
 * Update the choices section in an existing node HTML file.
 *
 * @param string $story_id Story ID.
 * @param string $node_id  Node ID.
 * @param array  $choices  Array of ['id' => int, 'text' => string, 'node' => string|null].
 * @return bool True if successful.
 */
function node_update_choices(string $story_id, string $node_id, array $choices): bool
{
    $node = node_read($story_id, $node_id, true);
    if ($node === null) {
        return false;
    }

    // Rebuild the full HTML with updated choices
    $html = node_generate_html([
        'story_id'     => $node['story_id'],
        'node_id'      => $node['node_id'],
        'parent_id'    => $node['parent_id'],
        'choice_taken' => $node['choice_taken'],
        'author_id'    => $node['author_id'],
        'title'        => $node['title'],
        'paragraphs'   => $node['paragraphs'],
        'choices'      => $choices,
        'created_at'   => $node['created_at'],
        'flagged'      => $node['flagged'],
        'sw_meta'      => $node['sw_meta'] ?? null,
    ]);

    $path = node_resolve_path($story_id, $node_id)
         ?? STORIES_DIR . '/' . $story_id . '/' . $node_id . '.html';
    atomic_write($path, $html);
    return true;
}

/**
 * Update a node's parent/choice relationship while preserving content and metadata.
 */
function node_update_relationship(string $story_id, string $node_id, string $parent_id, string $choice_taken): bool
{
    $node = node_read($story_id, $node_id, true);
    if ($node === null) {
        return false;
    }

    $html = node_generate_html([
        'story_id'     => $node['story_id'],
        'node_id'      => $node['node_id'],
        'parent_id'    => $parent_id,
        'choice_taken' => $choice_taken,
        'author_id'    => $node['author_id'],
        'title'        => $node['title'],
        'paragraphs'   => $node['paragraphs'],
        'choices'      => $node['choices'],
        'created_at'   => $node['created_at'],
        'flagged'      => $node['flagged'],
        'sw_meta'      => $node['sw_meta'] ?? null,
    ]);

    $path = node_resolve_path($story_id, $node_id)
         ?? STORIES_DIR . '/' . $story_id . '/' . $node_id . '.html';
    atomic_write($path, $html);
    return true;
}

/**
 * Replace a node's paragraphs, choices, and metadata in one write.
 */
function node_replace_content(string $story_id, string $node_id, array $paragraphs, array $choices, array $sw_meta): bool
{
    $node = node_read($story_id, $node_id, true);
    if ($node === null) {
        return false;
    }

    $html = node_generate_html([
        'story_id'     => $node['story_id'],
        'node_id'      => $node['node_id'],
        'parent_id'    => $node['parent_id'],
        'choice_taken' => $node['choice_taken'],
        'author_id'    => $node['author_id'],
        'title'        => $node['title'],
        'paragraphs'   => $paragraphs,
        'choices'      => $choices,
        'created_at'   => $node['created_at'],
        'flagged'      => $node['flagged'],
        'sw_meta'      => $sw_meta,
    ]);

    $path = node_resolve_path($story_id, $node_id)
         ?? STORIES_DIR . '/' . $story_id . '/' . $node_id . '.html';
    atomic_write($path, $html);
    return true;
}

/**
 * Return true when a node can be safely regenerated in place.
 */
function node_can_regenerate(array $node): bool
{
    foreach (($node['choices'] ?? []) as $choice) {
        if (!empty($choice['node'])) {
            return false;
        }
    }

    return true;
}

/**
 * Delete a leaf/final node and restore its parent link to a pending choice.
 */
function node_delete_leaf(string $story_id, string $node_id): bool
{
    $node = node_read($story_id, $node_id, true);
    if ($node === null || !node_can_regenerate($node)) {
        return false;
    }

    $parent_id = (string) ($node['parent_id'] ?? '');
    $original_parent_choices = null;
    if ($parent_id !== '') {
        $parent = node_read($story_id, $parent_id, true);
        if ($parent === null) {
            return false;
        }

        $original_parent_choices = $parent['choices'] ?? [];
        $choices = $original_parent_choices;
        $child_filename = $node_id . '.html';
        $choice_text = trim((string) ($node['choice_taken'] ?? ''));
        $updated = false;
        $max_choice_id = 0;

        foreach ($choices as &$choice) {
            $max_choice_id = max($max_choice_id, (int) ($choice['id'] ?? 0));
            if ((string) ($choice['node'] ?? '') !== $child_filename) {
                continue;
            }

            $choice['node'] = null;
            if (trim((string) ($choice['text'] ?? '')) === '' && $choice_text !== '') {
                $choice['text'] = $choice_text;
            }
            $updated = true;
            break;
        }
        unset($choice);

        if (!$updated && $choice_text !== '') {
            $choices[] = [
                'id' => $max_choice_id + 1,
                'text' => $choice_text,
                'node' => null,
            ];
        }

        if (!node_update_choices($story_id, $parent_id, $choices)) {
            return false;
        }
    }

    $path = node_resolve_path($story_id, $node_id);
    if ($path === null || !@unlink($path)) {
        if ($parent_id !== '' && is_array($original_parent_choices)) {
            node_update_choices($story_id, $parent_id, $original_parent_choices);
        }
        return false;
    }

    foreach (glob(sw_root() . '/_assets/images/' . $node_id . '-*') ?: [] as $image_path) {
        @unlink($image_path);
    }

    foreach ([STORIES_DIR . '/' . $story_id, QUARANTINE_DIR . '/' . $story_id] as $story_dir) {
        if (!is_dir($story_dir)) {
            continue;
        }

        $entries = scandir($story_dir);
        if ($entries !== false && count($entries) <= 2) {
            @rmdir($story_dir);
        }
    }

    return true;
}

/**
 * Link a parent node's choice to a newly created child node.
 *
 * Finds the choice matching the given text and sets its 'node' field
 * to point to the new child. If the choice doesn't exist (custom choice),
 * appends it.
 *
 * @param string $story_id      Story ID.
 * @param string $parent_node_id Parent node ID.
 * @param string $choice_text   The choice text that was taken.
 * @param string $child_node_id The new child node ID.
 * @return bool True if successful.
 */
function node_link_choice(string $story_id, string $parent_node_id, string $choice_text, string $child_node_id): bool
{
    $parent = node_read($story_id, $parent_node_id, true);
    if ($parent === null) {
        return false;
    }

    $choices = $parent['choices'];
    $found = false;

    foreach ($choices as &$choice) {
        if ($choice['text'] === $choice_text && $choice['node'] === null) {
            $choice['node'] = $child_node_id . '.html';
            $found = true;
            break;
        }
    }
    unset($choice);

    // If not found, add as a new choice (custom choice path)
    if (!$found) {
        $next_id = count($choices) + 1;
        $choices[] = [
            'id'   => $next_id,
            'text' => $choice_text,
            'node' => $child_node_id . '.html',
        ];
    }

    return node_update_choices($story_id, $parent_node_id, $choices);
}

/**
 * Update the text of a single pending choice.
 */
function node_update_pending_choice_text(string $story_id, string $node_id, int $choice_id, string $choice_text): bool
{
    $node = node_read($story_id, $node_id, true);
    if ($node === null) {
        return false;
    }

    $choices = $node['choices'] ?? [];
    $updated = false;

    foreach ($choices as &$choice) {
        if ((int) ($choice['id'] ?? 0) !== $choice_id || !node_choice_is_pending($choice)) {
            continue;
        }

        $choice['text'] = $choice_text;
        $updated = true;
        break;
    }
    unset($choice);

    if (!$updated) {
        return false;
    }

    return node_update_choices($story_id, $node_id, $choices);
}

/**
 * Delete a single pending choice from a node.
 */
function node_delete_pending_choice(string $story_id, string $node_id, int $choice_id): bool
{
    $node = node_read($story_id, $node_id, true);
    if ($node === null) {
        return false;
    }

    $choices = [];
    $deleted = false;

    foreach (($node['choices'] ?? []) as $choice) {
        if ((int) ($choice['id'] ?? 0) === $choice_id && node_choice_is_pending($choice)) {
            $deleted = true;
            continue;
        }
        $choices[] = $choice;
    }

    if (!$deleted) {
        return false;
    }

    return node_update_choices($story_id, $node_id, array_values($choices));
}

/**
 * Replace only the pending choices on a node, preserving linked choices.
 *
 * @param array<int, string> $pending_texts
 */
function node_replace_pending_choices(string $story_id, string $node_id, array $pending_texts): bool
{
    $node = node_read($story_id, $node_id, true);
    if ($node === null) {
        return false;
    }

    $choices = $node['choices'] ?? [];
    $replacement_index = 0;
    $max_choice_id = 0;

    foreach ($choices as &$choice) {
        $max_choice_id = max($max_choice_id, (int) ($choice['id'] ?? 0));
        if (!node_choice_is_pending($choice)) {
            continue;
        }

        if (!array_key_exists($replacement_index, $pending_texts)) {
            return false;
        }

        $choice['text'] = $pending_texts[$replacement_index];
        $replacement_index++;
    }
    unset($choice);

    while ($replacement_index < count($pending_texts)) {
        $max_choice_id++;
        $choices[] = [
            'id' => $max_choice_id,
            'text' => $pending_texts[$replacement_index],
            'node' => null,
        ];
        $replacement_index++;
    }

    return node_update_choices($story_id, $node_id, $choices);
}

/* ------------------------------------------------------------------
 * Node File I/O
 * ----------------------------------------------------------------*/

/**
 * Write a node HTML file atomically.
 *
 * Creates the story directory if it doesn't exist.
 *
 * @param string $story_id  Story ID.
 * @param string $node_id   Node ID.
 * @param string $html      Complete HTML content.
 * @param string $location  Output location: stories or quarantine.
 * @return void
 */
function node_write_file(string $story_id, string $node_id, string $html, string $location = 'stories'): void
{
    $base_dir = $location === 'quarantine' ? QUARANTINE_DIR : STORIES_DIR;
    $dir = $base_dir . '/' . $story_id;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    atomic_write($dir . '/' . $node_id . '.html', $html);
}

/**
 * Get the filesystem path for a node.
 *
 * @param string $story_id Story ID.
 * @param string $node_id  Node ID.
 * @return string Absolute file path.
 */
function node_path(string $story_id, string $node_id): string
{
    return STORIES_DIR . '/' . $story_id . '/' . $node_id . '.html';
}

/* ------------------------------------------------------------------
 * HTML Template
 * ----------------------------------------------------------------*/

/**
 * Generate the full HTML for a story node matching the §2.3 template.
 *
 * @param array $params Node parameters:
 *   - story_id     (string) Story ID.
 *   - node_id      (string) Node ID.
 *   - parent_id    (string) Parent node ID, or '' for root.
 *   - choice_taken (string) Choice text that led here, or '' for root.
 *   - author_id    (string) User ID or 'anonymous'.
 *   - title        (string) Story title.
 *   - paragraphs   (array)  Array of paragraph HTML strings.
 *   - choices      (array)  Array of ['id'=>int, 'text'=>string, 'node'=>string|null].
 *   - created_at   (string) Optional ISO timestamp (generated if empty).
 *   - flagged      (string) Optional 'true'/'false' (defaults to 'false').
 *   - ai_model     (string) Optional AI model used for generation.
 *   - ai_provider  (string) Optional AI provider name.
 *   - ai_key_label (string) Optional AI key label.
 *   - scenario_essentials (string) Optional story-wide scenario text.
 *   - sw_meta      (array)  Optional complete metadata block to preserve.
 * @return string Complete HTML document.
 */
function node_generate_html(array $params): string
{
    $story_id     = $params['story_id'];
    $node_id      = $params['node_id'];
    $parent_id    = $params['parent_id'] ?? '';
    $choice_taken = $params['choice_taken'] ?? '';
    $author_id    = $params['author_id'] ?? 'anonymous';
    $title        = $params['title'] ?? 'Untitled Story';
    $paragraphs   = $params['paragraphs'] ?? [];
    $choices      = $params['choices'] ?? [];
    $created_at   = $params['created_at'] ?? gmdate('Y-m-d\TH:i:s\Z');
    $flagged      = $params['flagged'] ?? 'false';

    // AI generation metadata (if AI-generated)
    $ai_model     = $params['ai_model'] ?? '';
    $ai_provider  = $params['ai_provider'] ?? '';
    $ai_key_label = $params['ai_key_label'] ?? '';

    // Build metadata comment block
    $meta_log = $params['sw_meta'] ?? null;
    if (!is_array($meta_log)) {
        $meta_log = [
            'created_by' => $author_id,
            'created_at' => $created_at,
        ];
        if (!empty($params['scenario_essentials'])) {
            $meta_log['scenario_essentials'] = (string) $params['scenario_essentials'];
        }
        if (array_key_exists('visibility', $params)) {
            $meta_log['visibility'] = ((string) $params['visibility']) === 'private' ? 'private' : 'public';
        }
        if (array_key_exists('shared_user_ids', $params) && is_array($params['shared_user_ids'])) {
            $meta_log['shared_user_ids'] = array_values(array_unique(array_filter(array_map('strval', $params['shared_user_ids']), function ($id) {
                return validate_id($id, 'usr_');
            })));
        }
        if (array_key_exists('auto_generate_images', $params)) {
            $meta_log['auto_generate_images'] = !empty($params['auto_generate_images']);
        }
        if (!empty($params['auto_image_key_id']) && validate_id((string) $params['auto_image_key_id'], 'key_')) {
            $meta_log['auto_image_key_id'] = (string) $params['auto_image_key_id'];
        }
        if ($ai_model !== '') {
            $meta_log['ai_generated'] = true;
            $meta_log['ai_model'] = $ai_model;
            $meta_log['ai_provider'] = $ai_provider;
            $meta_log['ai_key_label'] = $ai_key_label;
        }
        $meta_log['history'] = [
            [
                'action' => 'created',
                'by'     => $author_id,
                'at'     => $created_at,
                'ai_model' => $ai_model ?: null,
            ],
        ];
    }
    $meta_comment = '<!-- sw-meta: ' . json_encode($meta_log, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ' -->';

    // Active theme for the CSS link
    $theme_css    = theme_active();

    // Find root node for breadcrumb link
    $root_id = story_find_root($story_id);
    $root_link = $root_id ?: '#';

    // Build paragraphs HTML
    $para_html = render_rich_content_fragments($paragraphs);

    // Build choices JSON and list HTML
    $choices_json = h(json_encode($choices, JSON_UNESCAPED_UNICODE));
    $choices_list_html = '';
    foreach ($choices as $c) {
        $text = h($c['text']);
        if ($c['node'] !== null) {
            $choices_list_html .= '      <li><a href="' . h(basename((string) $c['node'], '.html')) . '">' . $text . "</a></li>\n";
        } else {
            $choices_list_html .= '      <li><a href="#" class="sw-choice-pending" data-choice-id="'
                                . (int)$c['id'] . '">' . $text . "</a></li>\n";
        }
    }

    // Back link
    $back_html = '';
    if ($parent_id !== '') {
        $back_html = '<a class="sw-back" href="' . h($parent_id) . '">← Back</a>';
    }

    // Assemble the full HTML document
    $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  {$meta_comment}
  <meta charset="UTF-8">
  <meta name="sw-story-id" content="{$story_id}">
  <meta name="sw-node-id" content="{$node_id}">
  <meta name="sw-parent-id" content="{$parent_id}">
  <meta name="sw-choice-taken" content="{$choice_taken}">
  <meta name="sw-created-at" content="{$created_at}">
  <meta name="sw-author-id" content="{$author_id}">
  <meta name="sw-flagged" content="{$flagged}">
  <link rel="icon" type="image/png" href="../../_assets/sw-fav.png">
  <link rel="apple-touch-icon" href="../../_assets/sw-fav.png">
  <link rel="stylesheet" href="../../_themes/{$theme_css}">
  <title>{$title} — {$node_id}</title>
</head>
<body data-sw-node="true">

  <nav class="sw-breadcrumb">
    <a href="../../">All Stories</a> ›
    <a href="{$root_link}">{$title}</a>
  </nav>

  <article class="sw-node-content">
{$para_html}  </article>

  <div class="sw-images">
  </div>

  <section class="sw-choices" data-choices-json='{$choices_json}'>
    <h2>What do you do?</h2>
    <ul>
{$choices_list_html}    </ul>
    <form class="sw-custom-choice" action="../../play" method="POST">
      <input type="hidden" name="story_id" value="{$story_id}">
      <input type="hidden" name="parent_node_id" value="{$node_id}">
      <input type="hidden" name="_csrf_token" value="">
      <input type="text" name="custom_choice" placeholder="Or type your own action…">
      <button type="submit">Continue →</button>
    </form>
  </section>

  <footer class="sw-node-footer">
    {$back_html}
    <span class="sw-flag-concern">
      <a href="../../api?action=flag_concern&node={$node_id}">⚑ Flag for review</a>
    </span>
  </footer>

  <script src="../../_assets/sw.js"></script>
</body>
</html>
HTML;

    return $html;
}

/* ------------------------------------------------------------------
 * Sanitization
 * ----------------------------------------------------------------*/

/**
 * Sanitize a paragraph of HTML from the contenteditable editor.
 *
 * Allows safe inline formatting tags and safe inline CSS, while stripping
 * everything unsafe including scripts, event handlers, dangerous URLs,
 * and dangerous CSS values.
 *
 * @param string $html Raw HTML from the editor.
 * @return string Sanitized HTML.
 */
function sanitize_paragraph_html(string $html): string
{
    $html = trim($html);
    if ($html === '') {
        return '';
    }

    if (!class_exists('DOMDocument')) {
        error_log('StoryWeaver DOM extension is unavailable; sanitizing paragraphs as plain text.');
        return nl2br(h(strip_tags($html)), false);
    }

    $dom = new DOMDocument('1.0', 'UTF-8');
    $wrapped = '<div>' . $html . '</div>';

    $previous = libxml_use_internal_errors(true);
    $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if (!$loaded) {
        return '';
    }

    $root = $dom->getElementsByTagName('div')->item(0);
    if (!$root instanceof DOMElement) {
        return '';
    }

    $allowed = [
        'a' => ['href', 'title', 'target', 'rel', 'style'],
        'abbr' => ['title', 'style'],
        'b' => ['style'],
        'blockquote' => ['style'],
        'br' => [],
        'code' => ['style'],
        'del' => ['style'],
        'div' => ['style'],
        'em' => ['style'],
        'h1' => ['style'],
        'h2' => ['style'],
        'h3' => ['style'],
        'h4' => ['style'],
        'h5' => ['style'],
        'h6' => ['style'],
        'i' => ['style'],
        'ins' => ['style'],
        'kbd' => ['style'],
        'li' => ['style'],
        'mark' => ['style'],
        'ol' => ['style'],
        'pre' => ['style'],
        'q' => ['cite', 'style'],
        'small' => ['style'],
        'span' => ['style'],
        'strong' => ['style'],
        'style' => ['type'],
        'sub' => ['style'],
        'sup' => ['style'],
        'u' => ['style'],
        'ul' => ['style'],
    ];

    sanitize_paragraph_node($root, $allowed);

    $clean = '';
    foreach (iterator_to_array($root->childNodes) as $child) {
        $clean .= $dom->saveHTML($child);
    }

    return trim($clean);
}

/**
 * Recursively sanitize a DOM fragment against the paragraph allowlist.
 *
 * @param DOMNode $node
 * @param array<string, array<int, string>> $allowed
 */
function sanitize_paragraph_node(DOMNode $node, array $allowed): void
{
    if (in_array($node->nodeType, [XML_COMMENT_NODE, XML_PI_NODE], true)) {
        if ($node->parentNode !== null) {
            $node->parentNode->removeChild($node);
        }
        return;
    }

    $children = [];
    foreach ($node->childNodes as $child) {
        $children[] = $child;
    }

    foreach ($children as $child) {
        sanitize_paragraph_node($child, $allowed);

        if ($child->nodeType !== XML_ELEMENT_NODE) {
            continue;
        }

        $tag = strtolower($child->nodeName);
        if (!isset($allowed[$tag])) {
            if (in_array($tag, ['script', 'iframe', 'object', 'embed', 'svg', 'math', 'template'], true)) {
                $node->removeChild($child);
                continue;
            }

            while ($child->firstChild !== null) {
                $node->insertBefore($child->firstChild, $child);
            }
            $node->removeChild($child);
            continue;
        }

        if (!$child instanceof DOMElement || !$child->hasAttributes()) {
            continue;
        }

        $attribute_names = [];
        foreach ($child->attributes as $attribute) {
            $attribute_names[] = $attribute->nodeName;
        }

        foreach ($attribute_names as $attribute_name) {
            if (!in_array(strtolower($attribute_name), $allowed[$tag], true)) {
                $child->removeAttribute($attribute_name);
            }
        }

        if ($child->hasAttribute('style')) {
            $style = sanitize_inline_css($child->getAttribute('style'));
            if ($style === '') {
                $child->removeAttribute('style');
            } else {
                $child->setAttribute('style', $style);
            }
        }

        if ($tag === 'style') {
            $css = sanitize_stylesheet_css($child->textContent);
            if ($css === '') {
                $node->removeChild($child);
                continue;
            }

            while ($child->firstChild !== null) {
                $child->removeChild($child->firstChild);
            }
            $child->appendChild($child->ownerDocument->createTextNode($css));
            $child->setAttribute('type', 'text/css');
            continue;
        }

        if ($tag === 'a' && $child->hasAttribute('href')) {
            $href = trim(html_entity_decode($child->getAttribute('href'), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if (!is_safe_story_href($href)) {
                $child->removeAttribute('href');
            } else {
                $child->setAttribute('href', $href);
            }

            if ($child->hasAttribute('target')) {
                $target = strtolower(trim($child->getAttribute('target')));
                if (!in_array($target, ['_blank', '_self'], true)) {
                    $child->removeAttribute('target');
                } else {
                    $child->setAttribute('target', $target);
                }
            }

            if (strtolower((string) $child->getAttribute('target')) === '_blank') {
                $child->setAttribute('rel', 'noopener noreferrer');
            } elseif ($child->hasAttribute('rel')) {
                $rel = trim(preg_replace('/[^a-z0-9\-\s]+/iu', ' ', $child->getAttribute('rel')) ?? '');
                if ($rel === '') {
                    $child->removeAttribute('rel');
                } else {
                    $child->setAttribute('rel', $rel);
                }
            }
        }
    }
}

/**
 * Sanitize allowlisted inline CSS declarations.
 */
function sanitize_inline_css(string $css): string
{
    $css = preg_replace('#/\*.*?\*/#s', '', $css) ?? $css;
    $allowed_properties = array_fill_keys([
        'background', 'background-color', 'border', 'border-color', 'border-radius',
        'border-style', 'border-width', 'color', 'font-family', 'font-size',
        'font-style', 'font-weight', 'letter-spacing', 'line-height', 'list-style',
        'list-style-position', 'list-style-type', 'margin', 'margin-left', 'margin-right',
        'padding', 'padding-left', 'padding-right', 'text-align', 'text-decoration',
        'text-transform', 'white-space',
    ], true);

    $clean = [];
    foreach (preg_split('/;(?![^()]*\))/u', $css) ?: [] as $declaration) {
        if (!str_contains($declaration, ':')) {
            continue;
        }

        [$property, $value] = explode(':', $declaration, 2);
        $property = strtolower(trim($property));
        $value = trim($value);

        if ($property === '' || $value === '' || !isset($allowed_properties[$property])) {
            continue;
        }

        $safe_value = sanitize_inline_css_value($value);
        if ($safe_value === '') {
            continue;
        }

        $clean[] = $property . ': ' . $safe_value;
    }

    return implode('; ', $clean);
}

/**
 * Sanitize embedded stylesheet CSS.
 */
function sanitize_stylesheet_css(string $css): string
{
    $css = preg_replace('#/\*.*?\*/#s', '', $css) ?? $css;
    $rules = [];

    if (preg_match_all('/([^{}]+)\{([^{}]*)\}/u', $css, $matches, PREG_SET_ORDER) === false) {
        return '';
    }

    foreach ($matches as $match) {
        $selector = trim($match[1]);
        $declarations = trim($match[2]);
        if ($selector === '' || preg_match('/[@<>]/', $selector) === 1) {
            continue;
        }

        if (preg_match('/^[A-Za-z0-9\s\.\#\-\_\>\+\~\:\,\*\[\]\=\"\'\(\)]+$/', $selector) !== 1) {
            continue;
        }

        $safe_declarations = sanitize_inline_css($declarations);
        if ($safe_declarations === '') {
            continue;
        }

        $rules[] = $selector . ' { ' . $safe_declarations . '; }';
    }

    return implode("\n", $rules);
}

/**
 * Sanitize a single inline CSS value.
 */
function sanitize_inline_css_value(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if (preg_match('/(?:expression\s*\(|javascript:|vbscript:|data:|url\s*\(|@import|behavior\s*:|-moz-binding)/iu', $value) === 1) {
        return '';
    }

    if (preg_match('/[{}<>]/u', $value) === 1) {
        return '';
    }

    return preg_replace('/\s+/u', ' ', $value) ?? '';
}

/**
 * Check whether an anchor href is safe to render in story content.
 */
function is_safe_story_href(string $href): bool
{
    if ($href === '') {
        return false;
    }

    $decoded = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $collapsed = preg_replace('/[\x00-\x20\x7F]+/u', '', $decoded);
    if (!is_string($collapsed) || $collapsed === '') {
        return false;
    }

    if (str_starts_with($collapsed, '#')
        || str_starts_with($collapsed, '/')
        || str_starts_with($collapsed, '?')
        || str_starts_with($collapsed, './')
        || str_starts_with($collapsed, '../')) {
        return true;
    }

    $scheme = parse_url($collapsed, PHP_URL_SCHEME);
    if ($scheme === null) {
        return true;
    }

    return in_array(strtolower($scheme), ['http', 'https', 'mailto'], true);
}

/**
 * Save an image file to the assets directory and return its relative URL.
 *
 * @param string $node_id      The node ID this image belongs to.
 * @param string $image_data   Raw binary image data.
 * @param string $extension    File extension (default "png").
 * @return string The image URL path relative to the storyweaver root.
 */
function node_save_image(string $node_id, string $image_data, string $extension = 'png'): string
{
    $images_dir = sw_root() . '/_assets/images';
    if (!is_dir($images_dir)) {
        mkdir($images_dir, 0755, true);
    }

    // Whitelist safe image extensions
    $safe_extensions = ['png', 'jpg', 'jpeg', 'gif', 'webp'];
    $extension = in_array($extension, $safe_extensions, true) ? $extension : 'png';

    $timestamp = time();
    $random = bin2hex(random_bytes(4));
    $filename = $node_id . '-' . $timestamp . '-' . $random . '.' . $extension;
    $filepath = $images_dir . '/' . $filename;

    atomic_write($filepath, $image_data);

    return '/_assets/images/' . $filename;
}
