<?php
/**
 * StoryWeaver — In-place node editor.
 *
 * Loads a node's paragraphs into contenteditable elements with a
 * rich-text toolbar. Save writes back to the HTML file via api.php.
 *
 * URL: edit.php?story=[story-id]&id=[node-id]
 */

require_once __DIR__ . '/_lib/auth_check.php';
require_once __DIR__ . '/_lib/nodes.php';

$story_id = $_GET['story'] ?? '';
$node_id  = $_GET['id'] ?? '';
$base     = base_url();
$user     = current_user();

// Must be logged in to edit
if ($user === null) {
    flash('error', 'Please log in to edit story pages.');
    redirect($base . '/auth.php?action=login');
}

// Validate inputs
if ($story_id === '' || $node_id === ''
    || !validate_id($story_id, 'story_') || !validate_id($node_id, 'node_')) {
    flash('error', 'Invalid story or page ID.');
    redirect($base . '/index.php');
}

// Read the node (check quarantine for editors/admins)
$check_quarantine = role_level($user['role']) >= role_level('editor');
$node = node_read($story_id, $node_id, $check_quarantine);
if ($node === null) {
    flash('error', 'Page not found.');
    redirect($base . '/index.php');
}

// Permission check: author can edit own, editor+ can edit any
$is_author = ($node['author_id'] === $user['id']);
$is_editor = (role_level($user['role']) >= role_level('editor'));

if (!$is_author && !$is_editor) {
    flash('error', 'You do not have permission to edit this page.');
    redirect($base . '/node.php?story=' . urlencode($story_id) . '&id=' . urlencode($node_id));
}

$title = $node['title'] ?: 'Edit Node';
$cancel_url = $base . '/node.php?story=' . urlencode($story_id) . '&id=' . urlencode($node_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= h(csrf_token()) ?>">
    <title>Edit — <?= h($title) ?> — StoryWeaver</title>
    <link rel="stylesheet" href="<?= h($base) ?>/_themes/<?= h(theme_css()) ?>">
</head>
<body>
    <nav class="sw-nav">
        <a href="<?= h($base) ?>/index.php" class="sw-nav-brand">🧶 StoryWeaver</a>
        <ul class="sw-nav-links">
            <li><a href="<?= h($base) ?>/settings.php">⚙️</a></li>
            <li><span class="sw-nav-user"><?= h($user['username']) ?></span></li>
            <li><a href="<?= h($base) ?>/auth.php?action=logout">Log out</a></li>
        </ul>
    </nav>

    <div class="sw-container">
        <?php
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

        <nav class="sw-breadcrumb">
            <a href="<?= h($base) ?>/index.php">All Stories</a> ›
            <a href="<?= h($base) ?>/node.php?story=<?= h($story_id) ?>&id=<?= h($node_id) ?>"><?= h($title) ?></a> ›
            <span>Editing</span>
        </nav>

        <?php if ($node['choice_taken'] !== ''): ?>
            <p class="sw-text-muted sw-mb-2" style="font-style:italic;">
                ▸ <?= h($node['choice_taken']) ?>
            </p>
        <?php endif; ?>

        <div id="sw-editor"
             data-story-id="<?= h($story_id) ?>"
             data-node-id="<?= h($node_id) ?>"
             data-csrf-token="<?= h(csrf_token()) ?>"
             data-api-url="<?= h($base) ?>/api.php"
             data-cancel-url="<?= h($cancel_url) ?>">

            <!-- Toolbar -->
            <div id="sw-editor-toolbar" class="sw-editor-toolbar">
                <button type="button" id="sw-editor-bold" class="sw-btn sw-btn-secondary sw-btn-sm"
                        title="Bold (Ctrl+B)"><strong>B</strong></button>
                <button type="button" id="sw-editor-italic" class="sw-btn sw-btn-secondary sw-btn-sm"
                        title="Italic (Ctrl+I)"><em>I</em></button>

                <div class="sw-editor-toolbar-separator"></div>

                <button type="button" id="sw-editor-add-para" class="sw-btn sw-btn-secondary sw-btn-sm"
                        title="Add paragraph">+ ¶</button>
                <button type="button" id="sw-editor-source-toggle" class="sw-btn sw-btn-secondary sw-btn-sm"
                        title="Toggle source mode">&lt;/&gt; Source</button>

                <div class="sw-editor-toolbar-spacer"></div>

                <span id="sw-editor-status" class="sw-editor-status"></span>

                <div class="sw-editor-toolbar-separator"></div>

                <button type="button" id="sw-editor-cancel" class="sw-btn sw-btn-secondary sw-btn-sm">Cancel</button>
                <button type="button" id="sw-editor-save" class="sw-btn sw-btn-primary sw-btn-sm">💾 Save</button>
            </div>

            <!-- Visual editor -->
            <div id="sw-editor-content" class="sw-editor-content">
                <?php if (!empty($node['paragraphs'])): ?>
                    <?php foreach ($node['paragraphs'] as $para): ?>
                        <p class="sw-para" contenteditable="true"><?= $para ?></p>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="sw-para" contenteditable="true"></p>
                <?php endif; ?>
            </div>

            <!-- Source editor (hidden by default) -->
            <textarea id="sw-editor-source" class="sw-editor-source"></textarea>
        </div>
    </div>

    <script src="<?= h($base) ?>/_assets/sw.js"></script>
</body>
</html>
