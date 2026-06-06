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
    redirect(auth_url('login'));
}

// Validate inputs
if ($story_id === '' || $node_id === ''
    || !validate_id($story_id, 'story_') || !validate_id($node_id, 'node_')) {
    flash('error', 'Invalid story or page ID.');
    redirect(app_url('index'));
}

// Read the node, including quarantined stories the author may still access
$node = node_read_for_user($story_id, $node_id, $user);
if ($node === null) {
    flash('error', 'Page not found.');
    redirect(app_url('index'));
}

if (!story_user_can_edit_node($story_id, $node, $user)) {
    flash('error', 'You do not have permission to edit this page.');
    redirect(node_url($story_id, $node_id));
}

$title = $node['title'] ?: 'Edit Node';
$cancel_url = node_url($story_id, $node_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= h(csrf_token()) ?>">
    <title>Edit — <?= h($title) ?> — StoryWeaver</title>
    <?php render_brand_favicon_links(); ?>
    <link rel="stylesheet" href="<?= h($base) ?>/_themes/<?= h(theme_css()) ?>">
</head>
<body>
    <?php render_main_nav($user, 'stories'); ?>

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
            <a href="<?= h(app_url('index')) ?>">All Stories</a> ›
            <a href="<?= h(node_url($story_id, $node_id)) ?>"><?= h($title) ?></a> ›
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
             data-api-url="<?= h(app_url('api')) ?>"
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
                        <?= render_editor_fragment_html((string) $para) . "\n" ?>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="sw-para" contenteditable="true"></p>
                <?php endif; ?>
            </div>

            <!-- Source editor (hidden by default) -->
            <textarea id="sw-editor-source" class="sw-editor-source"></textarea>
        </div>
    </div>

    <script src="<?= h($base) ?>/_assets/sw.js?v=<?= filemtime(sw_root() . '/_assets/sw.js') ?>"></script>
</body>
</html>
