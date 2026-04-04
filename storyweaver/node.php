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

// Read the node (check quarantine for editors/admins)
$check_quarantine = $user && role_level($user['role']) >= role_level('editor');
$node = node_read($story_id, $node_id, $check_quarantine);

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

// Quarantine notice
$is_quarantined = ($node['location'] === 'quarantine');

// AI availability for this user
$user_id_for_key = $user ? $user['id'] : null;
$selected_key = api_key_select_for_user($user_id_for_key);
$ai_available = ($selected_key !== null);
$has_image_model = $ai_available && !empty($selected_key['model_image']);
$has_images = !empty(glob(sw_root() . '/_assets/images/' . $node_id . '-*'));

// Get all active keys available to this user
$all_active_keys = [];
$all_keys = api_keys_read();
foreach ($all_keys as $k) {
    if ($k['status'] === 'active') {
        if ($k['scope'] === 'all') {
            // "all" scoped keys are visible to everyone including guests
            $all_active_keys[] = ['id' => $k['id'], 'label' => $k['label'], 'provider' => $k['provider']];
        } elseif ($user && $k['scope'] === 'self' && $k['owner_user_id'] === $user['id']) {
            $all_active_keys[] = ['id' => $k['id'], 'label' => $k['label'], 'provider' => $k['provider']];
        }
    }
}

// Per-story theme: read root node's sw_meta for story_theme
$story_theme = '';
$root_id = story_find_root($story_id);
$is_root_node = ($node_id === $root_id);
if ($is_root_node) {
    $story_theme = $node['sw_meta']['story_theme'] ?? '';
} elseif ($root_id) {
    $root_node = node_read($story_id, $root_id);
    if ($root_node) {
        $story_theme = $root_node['sw_meta']['story_theme'] ?? '';
    }
}
$effective_theme = $story_theme !== '' ? $story_theme : theme_css();

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
    <link rel="stylesheet" href="<?= h($base) ?>/_themes/<?= h($effective_theme) ?>">
</head>
<body>
    <nav class="sw-nav">
        <a href="<?= h($base) ?>/index.php" class="sw-nav-brand">🧶 StoryWeaver</a>
        <ul class="sw-nav-links">
            <?php if ($user): ?>
                <li><a href="<?= h($base) ?>/settings.php">⚙️</a></li>
                <?php if (in_array($user['role'], ['editor', 'admin'])): ?>
                    <li><a href="<?= h($base) ?>/admin.php">🛡️</a></li>
                <?php endif; ?>
                <li><span class="sw-nav-user"><?= h($user['username']) ?></span></li>
                <li><a href="<?= h($base) ?>/auth.php?action=logout">Log out</a></li>
            <?php else: ?>
                <li><a href="<?= h($base) ?>/auth.php?action=login">Log in</a></li>
                <li><a href="<?= h($base) ?>/auth.php?action=register">Register</a></li>
            <?php endif; ?>
        </ul>
    </nav>

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

        <?php if ($can_edit || $can_flag): ?>
        <div class="sw-node-toolbar">
            <?php if ($can_edit): ?>
                <a href="<?= h($base) ?>/edit.php?story=<?= h($story_id) ?>&id=<?= h($node_id) ?>"
                   class="sw-btn sw-btn-sm sw-btn-secondary">✏️ Edit</a>
            <?php endif; ?>
            <?php if ($can_flag): ?>
                <a href="<?= h($base) ?>/api.php?action=flag_concern&node=<?= h($node_id) ?>"
                   class="sw-btn sw-btn-sm sw-btn-secondary">⚑ Flag</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Breadcrumb -->
        <nav class="sw-breadcrumb">
            <a href="<?= h($base) ?>/index.php">All Stories</a> ›
            <?php
            $root_id = story_find_root($story_id);
            if ($root_id && $root_id !== $node_id): ?>
                <a href="<?= h($base) ?>/node.php?story=<?= h($story_id) ?>&id=<?= h($root_id) ?>"><?= h($node['title']) ?></a>
            <?php else: ?>
                <span><?= h($node['title']) ?></span>
            <?php endif; ?>
            <?php if ($node['choice_taken'] !== ''): ?>
                › <span class="sw-text-muted">"<?= h($node['choice_taken']) ?>"</span>
            <?php endif; ?>
        </nav>

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

        <!-- Story Content -->
        <article class="sw-node-content">
            <?php foreach ($node['paragraphs'] as $para): ?>
                <p class="sw-para"><?= $para ?></p>
            <?php endforeach; ?>
            <?php if (empty($node['paragraphs'])): ?>
                <p class="sw-para sw-text-muted"><em>This page has no content yet.
                <?php if ($can_edit): ?>
                    <a href="<?= h($base) ?>/edit.php?story=<?= h($story_id) ?>&id=<?= h($node_id) ?>">Write something →</a>
                <?php endif; ?>
                </em></p>
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

        <!-- AI Indicator -->
        <?php if ($ai_available): ?>
        <div class="sw-ai-indicator">
            <span class="sw-ai-badge">✨ AI: <?= h($selected_key['label']) ?></span>
            <?php if (count($all_active_keys) > 1): ?>
                <select id="sw-key-picker" class="sw-input sw-input-sm">
                    <?php foreach ($all_active_keys as $ak): ?>
                        <option value="<?= h($ak['id']) ?>" <?= $ak['id'] === $selected_key['id'] ? 'selected' : '' ?>>
                            <?= h($ak['label']) ?> (<?= h($ak['provider']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
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
        </div>
        <?php endif; ?>

        <!-- Choices -->
        <?php if (!empty($node['choices']) || true): ?>
        <section class="sw-choices">
            <?php if (!empty($node['choices'])): ?>
                <h2>What do you do?</h2>
                <ul>
                    <?php foreach ($node['choices'] as $choice): ?>
                        <li>
                            <?php if (!empty($choice['quarantined'])): ?>
                                <?php if ($user && role_level($user['role']) >= role_level('editor')): ?>
                                    <?php $child_id = basename($choice['node'], '.html'); ?>
                                    <a href="<?= h($base) ?>/node.php?story=<?= h($story_id) ?>&id=<?= h($child_id) ?>"
                                       class="sw-choice-quarantined">
                                        <?= h($choice['text']) ?> <span class="sw-text-muted">[quarantined]</span>
                                    </a>
                                <?php else: ?>
                                    <span class="sw-text-muted"><?= h($choice['text']) ?> [unavailable]</span>
                                <?php endif; ?>
                            <?php elseif ($choice['node'] !== null): ?>
                                <?php
                                $child_id = basename($choice['node'], '.html');
                                ?>
                                <a href="<?= h($base) ?>/node.php?story=<?= h($story_id) ?>&id=<?= h($child_id) ?>">
                                    <?= h($choice['text']) ?>
                                </a>
                            <?php else: ?>
                                <a href="#" class="sw-choice-pending"
                                   data-choice-id="<?= (int)$choice['id'] ?>"
                                   data-choice-text="<?= h($choice['text']) ?>">
                                    <?= h($choice['text']) ?> <span class="sw-text-muted">(pending)</span>
                                </a>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <!-- Custom choice form -->
            <form class="sw-custom-choice" action="<?= h($base) ?>/play.php" method="POST">
                <input type="hidden" name="story_id" value="<?= h($story_id) ?>">
                <input type="hidden" name="parent_node_id" value="<?= h($node_id) ?>">
                <input type="hidden" name="_csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="text" name="custom_choice" class="sw-input"
                       placeholder="Or type your own action…">
                <button type="submit" class="sw-btn sw-btn-primary">Continue →</button>
            </form>

            <?php if ($ai_available && empty($node['choices']) && !empty($node['paragraphs'])): ?>
                <!-- AI continuation for manually-started nodes -->
                <button type="button" id="sw-ai-continue-btn" class="sw-btn sw-btn-secondary sw-btn-ai"
                        data-story-id="<?= h($story_id) ?>"
                        data-node-id="<?= h($node_id) ?>">
                    ✨ Generate with AI
                </button>
            <?php endif; ?>
        </section>
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
                <a class="sw-back" href="<?= h($base) ?>/node.php?story=<?= h($story_id) ?>&id=<?= h($node['parent_id']) ?>">
                    ← Back
                </a>
            <?php else: ?>
                <a class="sw-back" href="<?= h($base) ?>/index.php">← All Stories</a>
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
            <span class="sw-flag-concern">
                <a href="#" id="sw-flag-concern-btn"
                   data-story-id="<?= h($story_id) ?>"
                   data-node-id="<?= h($node_id) ?>"
                   title="Flag this page for review">⚑ Flag</a>
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

    <script src="<?= h($base) ?>/_assets/sw.js"></script>
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
    <link rel="stylesheet" href="<?= h($base) ?>/_themes/<?= h(theme_css()) ?>">
</head>
<body>
    <nav class="sw-nav">
        <a href="<?= h($base) ?>/index.php" class="sw-nav-brand">🧶 StoryWeaver</a>
        <ul class="sw-nav-links">
            <?php if ($user): ?>
                <li><a href="<?= h($base) ?>/settings.php">⚙️</a></li>
                <li><span class="sw-nav-user"><?= h($user['username']) ?></span></li>
                <li><a href="<?= h($base) ?>/auth.php?action=logout">Log out</a></li>
            <?php else: ?>
                <li><a href="<?= h($base) ?>/auth.php?action=login">Log in</a></li>
                <li><a href="<?= h($base) ?>/auth.php?action=register">Register</a></li>
            <?php endif; ?>
        </ul>
    </nav>
    <div class="sw-container sw-text-center sw-mt-3">
        <h1>404 — Page Not Found</h1>
        <p><?= h($message) ?></p>
        <a href="<?= h($base) ?>/index.php" class="sw-btn sw-btn-secondary sw-mt-2">← Back to Stories</a>
    </div>
    <script src="<?= h($base) ?>/_assets/sw.js"></script>
</body>
</html>
    <?php
}
