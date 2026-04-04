<?php
/**
 * StoryWeaver — Moderation helpers (§7, §8).
 *
 * Manages the concern queue and quarantine operations.
 * Concern queue and quarantine log are stored in _data/moderation.json.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/nodes.php';

/** Path to the moderation data file. */
define('MODERATION_FILE', data_path('moderation.json'));

/* ------------------------------------------------------------------
 * Moderation Data I/O
 * ----------------------------------------------------------------*/

/**
 * Read the moderation data file.
 *
 * @return array ['concern_queue' => [...], 'quarantine_log' => [...]]
 */
function moderation_read(): array
{
    $data = json_read(MODERATION_FILE);
    if ($data === null) {
        return ['concern_queue' => [], 'quarantine_log' => []];
    }
    if (!isset($data['concern_queue'])) $data['concern_queue'] = [];
    if (!isset($data['quarantine_log'])) $data['quarantine_log'] = [];
    return $data;
}

/**
 * Write the moderation data file atomically.
 *
 * @param array $data The full moderation data.
 * @return void
 */
function moderation_write(array $data): void
{
    json_write(MODERATION_FILE, $data);
}

/* ------------------------------------------------------------------
 * Concern Queue (§8)
 * ----------------------------------------------------------------*/

/**
 * Add a concern to the queue.
 *
 * Any user (including anonymous) can flag a node for concern.
 *
 * @param string $node_id    Node being flagged.
 * @param string $story_id   Story the node belongs to.
 * @param string $reason     Reason text (max 500 chars).
 * @param string $flagged_by User ID or 'anonymous'.
 * @return void
 */
function concern_add(string $node_id, string $story_id, string $reason, string $flagged_by = 'anonymous'): void
{
    $reason = mb_substr(trim($reason), 0, 500);

    $data = moderation_read();
    $data['concern_queue'][] = [
        'id'         => generate_id('flag_'),
        'node_id'    => $node_id,
        'story_id'   => $story_id,
        'reason'     => $reason,
        'flagged_by' => $flagged_by,
        'flagged_at' => gmdate('c'),
        'status'     => 'open',
    ];
    moderation_write($data);
}

/**
 * Get all open concerns from the queue.
 *
 * @return array Array of open concern records.
 */
function concern_get_open(): array
{
    $data = moderation_read();
    return array_values(array_filter($data['concern_queue'], fn($c) => $c['status'] === 'open'));
}

/**
 * Get all concerns (open and dismissed).
 *
 * @return array All concern records.
 */
function concern_get_all(): array
{
    $data = moderation_read();
    return $data['concern_queue'];
}

/**
 * Dismiss a concern by its ID.
 *
 * @param string $concern_id The concern's ID.
 * @return bool True if found and dismissed.
 */
function concern_dismiss(string $concern_id): bool
{
    $data = moderation_read();
    foreach ($data['concern_queue'] as &$c) {
        if ($c['id'] === $concern_id && $c['status'] === 'open') {
            $c['status'] = 'dismissed';
            moderation_write($data);
            return true;
        }
    }
    return false;
}

/* ------------------------------------------------------------------
 * Subtree Walker
 * ----------------------------------------------------------------*/

/**
 * Find all descendant node IDs of a given node within a story.
 *
 * Recursively follows choices to build the full subtree.
 *
 * @param string $story_id The story ID.
 * @param string $node_id  The root node of the subtree.
 * @param string $location Where to look: 'stories' or 'quarantine'.
 * @return array List of node IDs (including the starting node).
 */
function node_get_subtree(string $story_id, string $node_id, string $location = 'stories'): array
{
    $result = [$node_id];
    $base_dir = ($location === 'quarantine') ? QUARANTINE_DIR : STORIES_DIR;
    $path = $base_dir . '/' . $story_id . '/' . $node_id . '.html';

    if (!file_exists($path)) {
        return $result;
    }

    $html = file_get_contents($path);
    if ($html === false) {
        return $result;
    }

    $node = node_parse_html($html, $location);

    foreach ($node['choices'] as $choice) {
        if ($choice['node'] !== null) {
            $child_id = basename($choice['node'], '.html');
            if (validate_id($child_id, 'node_')) {
                $descendants = node_get_subtree($story_id, $child_id, $location);
                $result = array_merge($result, $descendants);
            }
        }
    }

    return array_unique($result);
}

/* ------------------------------------------------------------------
 * Quarantine Operations (§7)
 * ----------------------------------------------------------------*/

/**
 * Move a node and its subtree to quarantine.
 *
 * Moves files from stories/[story_id]/ to quarantine/[story_id]/.
 * Updates the parent node's choices to show a quarantined placeholder.
 * Logs the action.
 *
 * @param string $story_id   Story ID.
 * @param string $node_id    Node to quarantine (and all descendants).
 * @param string $flagged_by User ID of the editor/admin who flagged.
 * @return bool True if successful.
 */
function quarantine_move(string $story_id, string $node_id, string $flagged_by): bool
{
    $subtree = node_get_subtree($story_id, $node_id, 'stories');
    $quarantine_dir = QUARANTINE_DIR . '/' . $story_id;

    if (!is_dir($quarantine_dir)) {
        mkdir($quarantine_dir, 0755, true);
    }

    // Move each node file to quarantine
    foreach ($subtree as $nid) {
        $src = STORIES_DIR . '/' . $story_id . '/' . $nid . '.html';
        $dst = $quarantine_dir . '/' . $nid . '.html';
        if (file_exists($src)) {
            rename($src, $dst);
        }
    }

    // Update parent node's choices to mark as quarantined
    $node = node_read($story_id, $node_id, true);
    if ($node !== null && $node['parent_id'] !== '') {
        $parent = node_read($story_id, $node['parent_id']);
        if ($parent !== null) {
            $choices = $parent['choices'];
            foreach ($choices as &$choice) {
                if ($choice['node'] !== null) {
                    $child_id = basename($choice['node'], '.html');
                    if ($child_id === $node_id) {
                        $choice['quarantined'] = true;
                    }
                }
            }
            unset($choice);
            node_update_choices($story_id, $node['parent_id'], $choices);
        }
    }

    // Log the quarantine action
    $data = moderation_read();
    $data['quarantine_log'][] = [
        'id'           => generate_id('qlog_'),
        'node_id'      => $node_id,
        'story_id'     => $story_id,
        'subtree'      => $subtree,
        'flagged_by'   => $flagged_by,
        'flagged_at'   => gmdate('c'),
        'original_choice_text' => null,  // populated below if found
    ];

    // Store the original choice text for restoration
    if ($node !== null && $node['choice_taken'] !== '') {
        $last = count($data['quarantine_log']) - 1;
        $data['quarantine_log'][$last]['original_choice_text'] = $node['choice_taken'];
    }

    moderation_write($data);
    return true;
}

/**
 * Restore a node and its subtree from quarantine.
 *
 * Moves files from quarantine/[story_id]/ back to stories/[story_id]/.
 * Restores the parent node's choice link. Removes quarantine log entry.
 *
 * @param string $story_id Story ID.
 * @param string $node_id  The root node to restore.
 * @return bool True if successful.
 */
function quarantine_restore(string $story_id, string $node_id): bool
{
    $subtree = node_get_subtree($story_id, $node_id, 'quarantine');
    $stories_dir = STORIES_DIR . '/' . $story_id;

    if (!is_dir($stories_dir)) {
        mkdir($stories_dir, 0755, true);
    }

    // Move each node file back to stories
    foreach ($subtree as $nid) {
        $src = QUARANTINE_DIR . '/' . $story_id . '/' . $nid . '.html';
        $dst = $stories_dir . '/' . $nid . '.html';
        if (file_exists($src)) {
            rename($src, $dst);
        }
    }

    // Restore parent node's choice link (remove quarantined flag)
    $node = node_read($story_id, $node_id);
    if ($node !== null && $node['parent_id'] !== '') {
        $parent = node_read($story_id, $node['parent_id']);
        if ($parent !== null) {
            $choices = $parent['choices'];
            foreach ($choices as &$choice) {
                if ($choice['node'] !== null) {
                    $child_id = basename($choice['node'], '.html');
                    if ($child_id === $node_id && !empty($choice['quarantined'])) {
                        unset($choice['quarantined']);
                        break;
                    }
                }
            }
            unset($choice);
            node_update_choices($story_id, $node['parent_id'], $choices);
        }
    }

    // Remove quarantine log entry
    $data = moderation_read();
    $data['quarantine_log'] = array_values(array_filter(
        $data['quarantine_log'],
        fn($entry) => $entry['node_id'] !== $node_id || $entry['story_id'] !== $story_id
    ));
    moderation_write($data);

    // Clean up empty quarantine story directory
    $q_dir = QUARANTINE_DIR . '/' . $story_id;
    if (is_dir($q_dir) && count(glob($q_dir . '/*')) === 0) {
        rmdir($q_dir);
    }

    return true;
}

/**
 * Delete a quarantined node and its subtree permanently.
 *
 * Removes all files from quarantine/[story_id]/.
 * Removes the choice placeholder from the parent node.
 * Removes the quarantine log entry.
 *
 * @param string $story_id Story ID.
 * @param string $node_id  The root node to delete.
 * @return bool True if successful.
 */
function quarantine_delete(string $story_id, string $node_id): bool
{
    $subtree = node_get_subtree($story_id, $node_id, 'quarantine');

    // Delete each node file from quarantine
    foreach ($subtree as $nid) {
        $path = QUARANTINE_DIR . '/' . $story_id . '/' . $nid . '.html';
        if (file_exists($path)) {
            unlink($path);
        }
        // Also remove any associated images
        $image_pattern = sw_root() . '/_assets/images/' . $nid . '-*';
        foreach (glob($image_pattern) as $img) {
            unlink($img);
        }
    }

    // Remove the quarantined choice placeholder from parent
    // First find the node in the log to get its parent info
    $data = moderation_read();
    foreach ($data['quarantine_log'] as $entry) {
        if ($entry['node_id'] === $node_id && $entry['story_id'] === $story_id) {
            // Try to read the quarantined node to find its parent
            // (it may already be deleted, so we check the log)
            break;
        }
    }

    // Try to clean up parent's choice referencing this node
    // We need to read the node before deleting it, but it's already deleted above.
    // Instead, scan the parent for [quarantined] markers.
    // This is best-effort — the parent may have the placeholder.
    $story_dir = STORIES_DIR . '/' . $story_id;
    if (is_dir($story_dir)) {
        foreach (glob($story_dir . '/*.html') as $file) {
            $html = file_get_contents($file);
            if ($html !== false && str_contains($html, '[quarantined]')) {
                $parent_nid = basename($file, '.html');
                $parent = node_read($story_id, $parent_nid);
                if ($parent !== null) {
                    $choices = $parent['choices'];
                    $changed = false;
                    foreach ($choices as $i => $choice) {
                        if ($choice['node'] === null && str_ends_with($choice['text'], ' [quarantined]')) {
                            unset($choices[$i]);
                            $changed = true;
                        }
                    }
                    if ($changed) {
                        node_update_choices($story_id, $parent_nid, array_values($choices));
                    }
                }
            }
        }
    }

    // Remove quarantine log entry
    $data['quarantine_log'] = array_values(array_filter(
        $data['quarantine_log'],
        fn($entry) => $entry['node_id'] !== $node_id || $entry['story_id'] !== $story_id
    ));
    moderation_write($data);

    // Clean up empty quarantine story directory
    $q_dir = QUARANTINE_DIR . '/' . $story_id;
    if (is_dir($q_dir) && count(glob($q_dir . '/*')) === 0) {
        rmdir($q_dir);
    }

    return true;
}

/**
 * Get all quarantine log entries.
 *
 * @return array Quarantine log entries.
 */
function quarantine_get_log(): array
{
    $data = moderation_read();
    return $data['quarantine_log'];
}
