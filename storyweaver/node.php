<?php
/**
 * StoryWeaver — Node renderer.
 *
 * Reads a story node HTML file and wraps it in the active theme shell
 * with navigation, editor toolbar (for eligible users), and wired-up
 * pending choices.
 *
 * URL: node.php?story=[story-id]&id=[node-id]
 */

require_once __DIR__ . '/_lib/auth_check.php';
require_once __DIR__ . '/_lib/nodes.php';
require_once __DIR__ . '/_lib/api_keys.php';

$story_id = $_GET['story'] ?? '';
$node_id  = $_GET['id'] ?? '';
$base     = base_url();
$user     = current_user();

// Validate inputs
if ($story_id === '' || $node_id === ''
    || !validate_id($story_id, 'story_') || !validate_id($node_id, 'node_')) {
    http_response_code(404);
    render_404('Missing or invalid story/page ID.');
    exit;
}

// Read the node, including quarantined stories the author may still access
$node = node_read_for_user($story_id, $node_id, $user);

if ($node === null) {
    http_response_code(404);
    render_404('Story node not found.');
    exit;
}

// Determine permissions
$can_edit = false;
$can_flag = true;
if ($user) {
    $is_author = ($node['author_id'] === $user['id']);
    $is_editor = (role_level($user['role']) >= role_level('editor'));
    $can_edit = $is_author || $is_editor;
}
$can_jump_latest = $user !== null && (($user['role'] ?? '') === 'admin');
$latest_node_id = $can_jump_latest ? story_find_latest_node($story_id, true) : null;

// Quarantine notice
$is_quarantined = ($node['location'] === 'quarantine');

// AI availability for this user — build separate text and image key lists
$user_id_for_key = $user ? $user['id'] : null;
$selected_key = api_key_select_for_user($user_id_for_key);
$has_images = !empty(glob(sw_root() . '/_assets/images/' . $node_id . '-*'));

$all_active_keys = [];
$text_keys = [];
$image_keys = [];
$all_keys = api_keys_read();
foreach ($all_keys as $k) {
    if ($k['status'] !== 'active') continue;
    $visible = ($k['scope'] === 'all')
            || ($user && $k['scope'] === 'self' && $k['owner_user_id'] === $user['id']);
    if (!$visible) continue;

    $entry = [
        'id'          => $k['id'],
        'label'       => $k['label'],
        'provider'    => $k['provider'],
        'model_text'  => $k['model_text'] ?? '',
        'model_image' => $k['model_image'] ?? '',
    ];
    $all_active_keys[] = $entry;
    if (!empty($k['model_text']))  $text_keys[]  = $entry;
    if (!empty($k['model_image'])) $image_keys[] = $entry;
}
$ai_available = !empty($text_keys);
$has_image_model = !empty($image_keys);
$can_regenerate_story = $user && $can_edit && $ai_available && node_can_regenerate($node);
$pending_choices = node_pending_choices($node);
$pending_choice_count = count($pending_choices);
$pending_choices_need_review = !empty($node['sw_meta']['pending_choices_need_review']) && $pending_choice_count > 0;
$story_scenario_essentials = '';
$can_access_quarantine_story = story_user_can_access_quarantine($story_id, $user);

// Per-story theme: read root node's sw_meta for story_theme
$story_theme = '';
$root_id = story_find_root($story_id);
$is_root_node = ($node_id === $root_id);
if ($is_root_node) {
    $story_theme = $node['sw_meta']['story_theme'] ?? '';
    $story_scenario_essentials = trim((string) ($node['sw_meta']['scenario_essentials'] ?? ''));
} elseif ($root_id) {
    $root_node = node_read($story_id, $root_id, true);
    if ($root_node) {
        $story_theme = $root_node['sw_meta']['story_theme'] ?? '';
        $story_scenario_essentials = trim((string) ($root_node['sw_meta']['scenario_essentials'] ?? ''));
    }
}
$effective_theme = $story_theme !== '' ? $story_theme : theme_css();
$editable_story_title = html_entity_decode((string) ($node['title'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
$can_rename_story = $user !== null
    && $is_root_node
    && role_level((string) ($user['role'] ?? 'viewer')) >= role_level('editor');

$linked_choices = [];
$display_pending_choices = [];
foreach (($node['choices'] ?? []) as $choice) {
    if (!empty($choice['quarantined'])) {
        continue;
    }

    if (($choice['node'] ?? null) !== null) {
        $linked_choices[] = $choice;
    } else {
        $display_pending_choices[] = $choice;
    }
}
$collapse_pending_choices = !empty($linked_choices) && !empty($display_pending_choices);
$show_end_actions = $can_edit || $can_regenerate_story;
$show_image_actions = $has_image_model || ($user && $can_edit);
$can_manage_ai_choices = $user && $can_edit && $ai_available && node_can_regenerate($node);
$can_delete_final_page = $user && $can_edit && node_can_regenerate($node);
$default_text_key_id = '';
if (!empty($text_keys)) {
    $default_text_key_id = (string) (($selected_key['id'] ?? '') ?: ($text_keys[0]['id'] ?? ''));
}

// Can this user change story theme? (story creator or admin, on root node only)
$can_change_theme = false;
if ($user && $is_root_node) {
    $root_meta = $node['sw_meta'] ?? [];
    $created_by = $root_meta['created_by'] ?? '';
    $can_change_theme = ($created_by === $user['id'] || $user['role'] === 'admin');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= h(csrf_token()) ?>">
    <title><?= h($node['title']) ?> — <?= h($node_id) ?> — StoryWeaver</title>
    <?php render_brand_favicon_links(); ?>
    <link rel="stylesheet" href="<?= h($base) ?>/_themes/<?= h($effective_theme) ?>">
</head>
<body>
    <?php render_main_nav($user, 'stories'); ?>

    <div class="sw-container">
        <?php
        // Render flash messages
        $flashes = get_flashes();
        foreach ($flashes as $type => $messages) {
            foreach ($messages as $msg) {
                echo '<div class="sw-flash sw-flash-' . h($type) . '">'
                   . h($msg)
                   . '<button class="sw-flash-dismiss" aria-label="Dismiss">&times;</button>'
                   . '</div>';
            }
        }
        ?>

        <?php if ($is_quarantined): ?>
            <div class="sw-flash sw-flash-warning">
                ⚠️ This node is in quarantine and not visible to the public.
            </div>
        <?php endif; ?>

        <div class="sw-node-toolbar">
            <div class="sw-node-toolbar-left">
                <nav class="sw-breadcrumb sw-breadcrumb-toolbar" aria-label="Breadcrumb">
                    <a href="<?= h(app_url('index')) ?>">All Stories</a> ›
                    <?php if ($root_id && $root_id !== $node_id): ?>
                        <a href="<?= h(node_url($story_id, $root_id)) ?>"><?= h($node['title']) ?></a>
                    <?php else: ?>
                        <span><?= h($node['title']) ?></span>
                    <?php endif; ?>
                    <?php if ($node['choice_taken'] !== ''): ?>
                        › <span class="sw-text-muted"><?= h($node['choice_taken']) ?></span>
                    <?php endif; ?>
                </nav>
            </div>
            <div class="sw-node-toolbar-right">
                <?php if ($can_jump_latest && $latest_node_id !== null && $latest_node_id !== $node_id): ?>
                    <a href="<?= h(node_url($story_id, $latest_node_id)) ?>"
                       class="sw-btn sw-btn-sm sw-btn-secondary">🕒 Latest Page</a>
                <?php endif; ?>
                <?php if ($can_flag): ?>
                    <button type="button"
                            id="sw-flag-concern-btn"
                            class="sw-btn sw-btn-sm sw-btn-secondary"
                            data-story-id="<?= h($story_id) ?>"
                            data-node-id="<?= h($node_id) ?>"
                            title="Flag this page for review">
                        ⚑ Flag
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($is_root_node && ($story_scenario_essentials !== '' || $can_edit)): ?>
            <details class="sw-story-scenario" id="sw-story-scenario-details">
                <summary class="sw-story-scenario-summary">Scenario Essentials</summary>
                <?php if ($can_edit): ?>
                    <div class="sw-story-scenario-editor">
                        <textarea id="sw-story-scenario-input"
                                  class="sw-input"
                                  rows="8"
                                  maxlength="4000"
                                  data-story-id="<?= h($story_id) ?>"
                                  data-original-value="<?= h($story_scenario_essentials) ?>"
                                  placeholder="Add enduring story setup, tone, characters, or constraints that should stay with the story."><?= h($story_scenario_essentials) ?></textarea>
                        <div class="sw-story-scenario-actions">
                            <button type="button" id="sw-save-story-scenario-btn" class="sw-btn sw-btn-sm sw-btn-secondary">
                                Save Scenario Essentials
                            </button>
                            <button type="button" id="sw-cancel-story-scenario-btn" class="sw-btn sw-btn-sm sw-btn-secondary">
                                Cancel
                            </button>
                            <span id="sw-story-scenario-status" class="sw-editor-status"></span>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="sw-story-scenario-content"><?= nl2br(h($story_scenario_essentials)) ?></div>
                <?php endif; ?>
            </details>
        <?php endif; ?>

        <?php if ($can_rename_story): ?>
            <details class="sw-story-title" id="sw-story-title-details">
                <summary class="sw-story-title-summary">Story Title</summary>
                <div class="sw-story-title-editor">
                    <input type="text"
                           id="sw-story-title-input"
                           class="sw-input"
                           maxlength="200"
                           data-story-id="<?= h($story_id) ?>"
                           data-original-value="<?= h($editable_story_title) ?>"
                           value="<?= h($editable_story_title) ?>">
                    <div class="sw-story-title-actions">
                        <button type="button" id="sw-save-story-title-btn" class="sw-btn sw-btn-sm sw-btn-secondary">
                            Save Story Title
                        </button>
                        <button type="button" id="sw-cancel-story-title-btn" class="sw-btn sw-btn-sm sw-btn-secondary">
                            Cancel
                        </button>
                        <span id="sw-story-title-status" class="sw-editor-status"></span>
                    </div>
                </div>
            </details>
        <?php endif; ?>

        <?php if ($is_quarantined): ?>
            <div class="sw-alert sw-alert-warning">
                ⚠️ This page is in quarantine. Only editors and admins can view it.
                <?php if ($user && role_level($user['role']) >= role_level('editor')): ?>
                    <div style="margin-top:0.5rem">
                        <button type="button" class="sw-btn sw-btn-sm sw-btn-primary sw-admin-action"
                                data-action="approve_node"
                                data-story-id="<?= h($story_id) ?>"
                                data-node-id="<?= h($node_id) ?>">
                            ✓ Approve &amp; Restore
                        </button>
                        <button type="button" class="sw-btn sw-btn-sm sw-btn-danger sw-admin-action"
                                data-action="delete_node"
                                data-story-id="<?= h($story_id) ?>"
                                data-node-id="<?= h($node_id) ?>">
                            🗑️ Delete
                        </button>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="sw-node-reading-panel">
            <!-- Story Content -->
            <article class="sw-node-content">
                <?php foreach ($node['paragraphs'] as $para): ?>
                    <p class="sw-para"><?= $para ?></p>
                <?php endforeach; ?>
                <?php if (empty($node['paragraphs'])): ?>
                    <p class="sw-para sw-text-muted"><em>This page has no content yet.
                    <?php if ($can_edit): ?>
                        <a href="<?= h(edit_url($story_id, $node_id)) ?>">Write something →</a>
                    <?php endif; ?>
                    </em></p>
                <?php endif; ?>

                <?php if ($show_end_actions): ?>
                    <div class="sw-node-end-actions" aria-label="Story actions">
                        <?php if ($can_edit): ?>
                            <a href="<?= h(edit_url($story_id, $node_id)) ?>"
                               class="sw-node-end-action sw-node-end-action-edit"
                               title="Edit page"
                               aria-label="Edit page">✎</a>
                        <?php endif; ?>
                        <?php if ($can_delete_final_page): ?>
                            <button type="button"
                                    id="sw-delete-final-page-btn"
                                    class="sw-node-end-action sw-node-end-action-delete"
                                    data-story-id="<?= h($story_id) ?>"
                                    data-node-id="<?= h($node_id) ?>"
                                    title="Delete page"
                                    aria-label="Delete page">🗑</button>
                        <?php endif; ?>
                        <?php if ($can_regenerate_story): ?>
                            <button type="button"
                                    id="sw-regenerate-story-btn"
                                    class="sw-node-end-action sw-node-end-action-regenerate"
                                    data-ai-text-control="regenerate"
                                    data-story-id="<?= h($story_id) ?>"
                                    data-node-id="<?= h($node_id) ?>"
                                    title="Regenerate story"
                                    aria-label="Regenerate story">↻</button>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </article>

            <!-- Images -->
            <div class="sw-images" id="sw-images">
                <?php
                // Show existing images for this node
                $image_glob = glob(sw_root() . '/_assets/images/' . $node_id . '-*');
                if (!empty($image_glob)) {
                    foreach ($image_glob as $img_path) {
                        $img_url = $base . '/_assets/images/' . basename($img_path);
                        echo '<div class="sw-image-wrap">';
                        echo '<img src="' . h($img_url) . '" alt="Story illustration" class="sw-node-image">';
                        if ($can_edit) {
                            echo '<button type="button" class="sw-image-delete-btn" data-image-url="' . h($img_url) . '" title="Delete image">&times;</button>';
                        }
                        echo '</div>';
                    }
                }
                ?>
            </div>

            <?php if ($show_image_actions): ?>
                <div class="sw-node-image-actions" id="sw-node-image-actions">
                    <?php if ($has_image_model && !$has_images): ?>
                        <button type="button" id="sw-gen-image-btn" class="sw-btn sw-btn-sm sw-btn-secondary"
                                data-story-id="<?= h($story_id) ?>" data-node-id="<?= h($node_id) ?>">
                            🖼️ Generate Image
                        </button>
                    <?php elseif ($has_image_model && $has_images): ?>
                        <button type="button" id="sw-regen-image-btn" class="sw-btn sw-btn-sm sw-btn-secondary"
                                data-story-id="<?= h($story_id) ?>" data-node-id="<?= h($node_id) ?>"
                                data-existing-image="<?= h($base . '/_assets/images/' . basename($image_glob[0])) ?>">
                            🖼️ Regenerate Image
                        </button>
                    <?php endif; ?>

                    <?php if ($user && $can_edit): ?>
                        <details class="sw-inline-action-menu sw-image-action-menu" id="sw-image-action-menu">
                            <summary class="sw-btn sw-btn-sm sw-btn-secondary sw-inline-action-toggle"
                                     title="More image actions"
                                     aria-label="More image actions">▾</summary>
                            <div class="sw-inline-action-menu-panel">
                                <label for="sw-image-upload" class="sw-inline-action-item">Upload image</label>
                            </div>
                        </details>
                        <input type="file" id="sw-image-upload" accept="image/png,image/jpeg,image/gif,image/webp"
                               data-story-id="<?= h($story_id) ?>" data-node-id="<?= h($node_id) ?>"
                               style="display:none">
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        </div>

        <!-- Choices -->
        <section class="sw-choices">
            <div class="sw-choices-header">
                <h2>Choose Now!</h2>
                <?php if ($can_manage_ai_choices): ?>
                    <button type="button"
                            id="sw-open-pending-choices-btn"
                            class="sw-btn sw-btn-sm sw-btn-secondary"
                            data-ai-text-control="choices"
                            aria-haspopup="dialog"
                            aria-controls="sw-pending-choice-modal">
                        Get New Choices
                    </button>
                <?php endif; ?>
            </div>

            <?php if (!empty($linked_choices)): ?>
                <ul class="sw-choice-list">
                    <?php foreach ($linked_choices as $choice): ?>
                        <?php $child_id = basename((string) $choice['node'], '.html'); ?>
                        <li>
                            <a href="<?= h(node_url($story_id, $child_id)) ?>">
                                <?= h($choice['text']) ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if (!empty($display_pending_choices)): ?>
                <?php if ($collapse_pending_choices): ?>
                    <details class="sw-choice-accordion">
                        <summary>More choices</summary>
                        <ul class="sw-choice-list sw-choice-list-secondary">
                            <?php foreach ($display_pending_choices as $choice): ?>
                                <li>
                                    <a href="#" class="sw-choice-pending"
                                       data-choice-id="<?= (int) $choice['id'] ?>"
                                       data-choice-text="<?= h($choice['text']) ?>">
                                        <?= h($choice['text']) ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </details>
                <?php else: ?>
                    <ul class="sw-choice-list">
                        <?php foreach ($display_pending_choices as $choice): ?>
                            <li>
                                <a href="#" class="sw-choice-pending"
                                   data-choice-id="<?= (int) $choice['id'] ?>"
                                   data-choice-text="<?= h($choice['text']) ?>">
                                    <?= h($choice['text']) ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php endif; ?>

            <!-- Custom choice form -->
            <form class="sw-custom-choice" action="<?= h(app_url('play')) ?>" method="POST">
                <input type="hidden" name="story_id" value="<?= h($story_id) ?>">
                <input type="hidden" name="parent_node_id" value="<?= h($node_id) ?>">
                <input type="hidden" name="_csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="use_ai" value="1">
                <input type="hidden" name="key_id" value="<?= h($default_text_key_id) ?>">
                <input type="text" name="custom_choice" class="sw-input"
                       placeholder="Or type your own action…">
                <button type="submit" class="sw-btn sw-btn-primary">Continue →</button>
            </form>

            <?php if ($ai_available || $has_image_model || ($user && $user['role'] === 'admin')): ?>
                <div class="sw-choice-ai-row">
                    <div class="sw-ai-indicator">
                        <?php if ($ai_available): ?>
                            <label class="sw-ai-badge" for="sw-text-key-picker">✨ Text:</label>
                            <select id="sw-text-key-picker" class="sw-input sw-input-sm">
                                <option value="human">Human — write it yourself</option>
                                <?php foreach ($text_keys as $ak): ?>
                                    <option value="<?= h($ak['id']) ?>" <?= $default_text_key_id === (string) $ak['id'] ? 'selected' : '' ?>>
                                        <?= h($ak['label']) ?> — <?= h($ak['model_text']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>

                        <?php if ($has_image_model): ?>
                            <?php if (count($image_keys) > 1): ?>
                                <label class="sw-ai-badge" for="sw-image-key-picker">🖼️ Image:</label>
                                <select id="sw-image-key-picker" class="sw-input sw-input-sm">
                                    <?php foreach ($image_keys as $ak): ?>
                                        <option value="<?= h($ak['id']) ?>">
                                            <?= h($ak['label']) ?> — <?= h($ak['model_image']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php elseif (count($image_keys) === 1): ?>
                                <span class="sw-ai-badge">🖼️ Image: <?= h($image_keys[0]['label']) ?> — <?= h($image_keys[0]['model_image']) ?></span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>

                    <?php if ($user && $user['role'] === 'admin'): ?>
                        <button type="button" id="sw-preview-prompt-btn" class="sw-btn sw-btn-sm sw-btn-secondary"
                                data-story-id="<?= h($story_id) ?>" data-node-id="<?= h($node_id) ?>">
                            🔍 Preview Prompts
                        </button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($ai_available && empty($node['choices']) && !empty($node['paragraphs'])): ?>
                <!-- AI continuation for manually-started nodes -->
                <button type="button" id="sw-ai-continue-btn" class="sw-btn sw-btn-secondary sw-btn-ai"
                        data-ai-text-control="continue"
                        data-story-id="<?= h($story_id) ?>"
                        data-node-id="<?= h($node_id) ?>">
                    ✨ Generate with AI
                </button>
            <?php endif; ?>
        </section>

        <?php if ($can_manage_ai_choices): ?>
            <div id="sw-pending-choice-modal" class="sw-modal-backdrop" aria-hidden="true">
                <div class="sw-modal sw-pending-choice-modal" role="dialog" aria-modal="true" aria-labelledby="sw-pending-choice-modal-title">
                    <div class="sw-modal-header">
                        <h2 id="sw-pending-choice-modal-title">Pending Choices</h2>
                        <button type="button" class="sw-modal-close" id="sw-close-pending-choices-btn" aria-label="Close">&times;</button>
                    </div>

                    <div class="sw-alert <?= $pending_choices_need_review ? 'sw-alert-warning' : 'sw-alert-info' ?> sw-pending-choice-review">
                        <strong>
                            <?php if ($pending_choice_count === 0): ?>
                                This page does not have any pending choices yet.
                            <?php else: ?>
                                <?= $pending_choices_need_review
                                    ? 'This page was edited, so its pending choices may need updating.'
                                    : 'This page still has pending choices you can maintain here.' ?>
                            <?php endif; ?>
                        </strong>
                        <p>
                            <?php if ($pending_choice_count === 0): ?>
                                Generate a fresh set of AI choices for this page, or keep writing manually.
                            <?php elseif ($ai_available): ?>
                                Regenerate just the pending choices with AI, or edit/delete them manually below.
                            <?php else: ?>
                                No text AI key is currently available, so edit or delete the pending choices manually below.
                            <?php endif; ?>
                        </p>
                        <?php if ($ai_available): ?>
                            <button type="button"
                                    id="sw-regenerate-pending-choices-btn"
                                    class="sw-btn sw-btn-sm sw-btn-secondary"
                                    data-idle-text="<?= h($pending_choice_count > 0 ? '✨ Regenerate Pending Choices' : '✨ Get New Choices') ?>"
                                    data-story-id="<?= h($story_id) ?>"
                                    data-node-id="<?= h($node_id) ?>">
                                <?= $pending_choice_count > 0 ? '✨ Regenerate Pending Choices' : '✨ Get New Choices' ?>
                            </button>
                        <?php endif; ?>
                    </div>

                    <?php if ($pending_choice_count > 0): ?>
                        <div class="sw-pending-choice-manager">
                            <?php foreach ($pending_choices as $choice): ?>
                                <div class="sw-pending-choice-item"
                                     data-story-id="<?= h($story_id) ?>"
                                     data-node-id="<?= h($node_id) ?>"
                                     data-choice-id="<?= (int) $choice['id'] ?>">
                                    <label class="sw-pending-choice-label" for="sw-pending-choice-<?= (int) $choice['id'] ?>">
                                        Pending choice
                                    </label>
                                    <div class="sw-pending-choice-row">
                                        <input type="text"
                                               id="sw-pending-choice-<?= (int) $choice['id'] ?>"
                                               class="sw-input sw-pending-choice-input"
                                               value="<?= h($choice['text']) ?>"
                                               maxlength="160">
                                        <button type="button" class="sw-btn sw-btn-sm sw-btn-secondary sw-pending-choice-save-btn">
                                            Save
                                        </button>
                                        <button type="button" class="sw-btn sw-btn-sm sw-btn-danger sw-pending-choice-delete-btn">
                                            Delete
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($can_change_theme): ?>
        <!-- Per-story theme picker (story creator / admin on root node) -->
        <section class="sw-story-theme-section" style="margin-top:1rem">
            <details>
                <summary class="sw-btn sw-btn-sm sw-btn-secondary">🎨 Story Theme</summary>
                <div style="margin-top:0.5rem">
                    <select id="sw-story-theme-select" class="sw-input" data-story-id="<?= h($story_id) ?>">
                        <option value="">Default (site theme)</option>
                        <?php
                        $themes_data = themes_read();
                        foreach ($themes_data['themes'] as $t):
                        ?>
                        <option value="<?= h($t['file']) ?>" <?= $story_theme === $t['file'] ? 'selected' : '' ?>>
                            <?= h($t['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" id="sw-apply-story-theme-btn" class="sw-btn sw-btn-sm sw-btn-primary" style="margin-top:0.5rem">
                        Apply
                    </button>
                </div>
            </details>
        </section>
        <?php endif; ?>

        <!-- Footer -->
        <footer class="sw-node-footer">
            <?php if ($node['parent_id'] !== ''): ?>
                <a class="sw-back" href="<?= h(node_url($story_id, $node['parent_id'])) ?>">
                    ← Back
                </a>
            <?php else: ?>
                <a class="sw-back" href="<?= h(app_url('index')) ?>">← All Stories</a>
            <?php endif; ?>
            <span class="sw-text-muted">
                <?= h($node['node_id']) ?>
                · <?= h(date('M j, Y', strtotime($node['created_at']))) ?>
                <?php
                $meta = $node['sw_meta'] ?? null;
                if ($meta && !empty($meta['ai_generated'])):
                ?>
                · <span title="Generated by <?= h($meta['ai_model'] ?? 'AI') ?>">🤖 <?= h($meta['ai_model'] ?? 'AI') ?></span>
                <?php endif; ?>
            </span>
            <?php if ($user && in_array($user['role'], ['editor', 'admin']) && !$is_quarantined): ?>
                <span class="sw-flag-review">
                    <a href="#" id="sw-flag-review-btn"
                       data-story-id="<?= h($story_id) ?>"
                       data-node-id="<?= h($node_id) ?>"
                       title="Move this page to quarantine">🔒 Quarantine</a>
                </span>
            <?php endif; ?>
        </footer>
    </div>

    <script src="<?= h($base) ?>/_assets/sw.js?v=<?= filemtime(sw_root() . '/_assets/sw.js') ?>"></script>
</body>
</html>
<?php

/**
 * Render a 404 page for missing nodes.
 *
 * @param string $message Error message to display.
 * @return void
 */
function render_404(string $message): void
{
    $base = base_url();
    $user = current_user();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 — StoryWeaver</title>
    <?php render_brand_favicon_links(); ?>
    <link rel="stylesheet" href="<?= h($base) ?>/_themes/<?= h(theme_css()) ?>">
</head>
<body>
    <?php render_main_nav($user, 'stories'); ?>
    <div class="sw-container sw-text-center sw-mt-3">
        <h1>404 — Page Not Found</h1>
        <p><?= h($message) ?></p>
        <a href="<?= h(app_url('index')) ?>" class="sw-btn sw-btn-secondary sw-mt-2">← Back to Stories</a>
    </div>
    <script src="<?= h($base) ?>/_assets/sw.js?v=<?= filemtime(sw_root() . '/_assets/sw.js') ?>"></script>
</body>
</html>
    <?php
}
