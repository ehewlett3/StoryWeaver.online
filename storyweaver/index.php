<?php
/**
 * StoryWeaver — Landing / Story List page.
 *
 * Lists all stories, shows auth status, and provides the entry point
 * for creating new adventures.
 */

require_once __DIR__ . '/_lib/auth_check.php';
require_once __DIR__ . '/_lib/api_keys.php';

// First-run: redirect to setup if no users exist
if (!users_exists()) {
    redirect(base_url() . '/auth.php?action=setup');
}

$user = current_user();
$base = base_url();

// Get available AI keys for the new story key picker (text-capable keys only)
$user_id_for_key = $user ? $user['id'] : null;
$all_active_keys = [];
$all_keys = api_keys_read();
foreach ($all_keys as $k) {
    if ($k['status'] !== 'active' || empty($k['model_text'])) continue;
    $visible = ($k['scope'] === 'all')
            || ($user && $k['scope'] === 'self' && $k['owner_user_id'] === $user['id']);
    if (!$visible) continue;
    $all_active_keys[] = [
        'id'         => $k['id'],
        'label'      => $k['label'],
        'provider'   => $k['provider'],
        'model_text' => $k['model_text'],
    ];
}

// ─── Scan stories directory for existing stories ───
$stories = [];
$stories_dir = sw_root() . '/stories';
if (is_dir($stories_dir)) {
    $dirs = array_filter(glob($stories_dir . '/story_*'), 'is_dir');
    foreach ($dirs as $dir) {
        $story_id = basename($dir);
        $nodes = glob($dir . '/node_*.html');
        $root_node = null;

        // Find the root node (no sw-parent-id, or empty sw-parent-id)
        foreach ($nodes as $node_file) {
            $html = file_get_contents($node_file);
            if ($html === false) continue;

            // Check if this node has no parent (root node)
            if (preg_match('/meta\s+name="sw-parent-id"\s+content=""\s*/i', $html) ||
                !preg_match('/meta\s+name="sw-parent-id"\s+content="[^"]+"/i', $html)) {

                // Extract title
                $title = $story_id;
                if (preg_match('/<title>([^<]+)<\/title>/i', $html, $m)) {
                    $title = trim(explode('—', $m[1])[0]);
                }

                // Extract creation date
                $created = '';
                if (preg_match('/meta\s+name="sw-created-at"\s+content="([^"]+)"/i', $html, $m)) {
                    $created = $m[1];
                }

                // Extract author
                $author_id = 'anonymous';
                if (preg_match('/meta\s+name="sw-author-id"\s+content="([^"]+)"/i', $html, $m)) {
                    $author_id = $m[1];
                }
                $author = $author_id;
                if (str_starts_with($author_id, 'usr_')) {
                    $author_user = user_find_by_id($author_id);
                    if ($author_user) {
                        $author = $author_user['username'];
                    }
                }

                $root_node = basename($node_file, '.html');
                $stories[] = [
                    'story_id'  => $story_id,
                    'title'     => $title,
                    'created'   => $created,
                    'author'    => $author,
                    'node_count'=> count($nodes),
                    'root_node' => $root_node,
                ];
                break;
            }
        }
    }

    // Sort by creation date, newest first
    usort($stories, function ($a, $b) {
        return strcmp($b['created'], $a['created']);
    });
}

// ─── Render ───
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= h(csrf_token()) ?>">
    <title>StoryWeaver — Adventures Await</title>
    <link rel="stylesheet" href="<?= h($base) ?>/_themes/<?= h(theme_css()) ?>">
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

        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.5rem;">
            <h1>Stories</h1>
            <button id="sw-new-story-btn" class="sw-btn sw-btn-primary">
                + Begin New Story
            </button>
        </div>

        <?php if (empty($stories)): ?>
            <div class="sw-empty-state">
                <p>No stories yet.</p>
                <p class="sw-text-muted">Adventures will appear here once the first story is created.</p>
            </div>
        <?php else: ?>
            <div class="sw-story-list">
                <?php foreach ($stories as $story): ?>
                    <a href="<?= h($base) ?>/node.php?story=<?= h($story['story_id']) ?>&id=<?= h($story['root_node']) ?>"
                       class="sw-story-item" style="text-decoration:none; color:inherit;">
                        <div>
                            <div class="sw-story-item-title"><?= h($story['title']) ?></div>
                            <div class="sw-story-item-meta">
                                by <?= h($story['author']) ?>
                                · <?= $story['node_count'] ?> page<?= $story['node_count'] !== 1 ? 's' : '' ?>
                            </div>
                        </div>
                        <div class="sw-story-item-meta">
                            <?php if ($story['created']): ?>
                                <?= h(date('M j, Y', strtotime($story['created']))) ?>
                            <?php endif; ?>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- New Story Modal -->
    <div id="sw-new-story-modal" class="sw-modal-backdrop">
        <div class="sw-modal">
            <h2>Begin New Story</h2>
            <form method="POST" action="<?= h($base) ?>/play.php">
                <input type="hidden" name="action" value="new_story">
                <input type="hidden" name="_csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="use_ai" id="sw-use-ai" value="1">

                <div class="sw-form-group">
                    <label for="story-title">Story Title</label>
                    <input type="text" id="story-title" name="title" class="sw-input"
                           placeholder="e.g. The Lost Temple of Shadows" required autofocus
                           maxlength="200">
                </div>

                <div class="sw-form-group">
                    <label for="scenario-essentials">Scenario Essentials <span class="sw-text-muted">(optional)</span></label>
                    <textarea id="scenario-essentials" name="scenario_essentials" class="sw-input"
                              rows="3" maxlength="1000"
                              placeholder="e.g. Medieval fantasy, the hero is a young blacksmith, dark forest setting…"></textarea>
                    <span class="sw-text-muted sw-text-sm">Help the AI set the scene. Leave blank for a surprise.</span>
                </div>

                <?php if (count($all_active_keys) > 1): ?>
                <div class="sw-form-group">
                    <label for="sw-key-picker-modal">Text AI Model</label>
                    <select id="sw-key-picker-modal" name="key_id" class="sw-input sw-input-sm">
                        <?php foreach ($all_active_keys as $ak): ?>
                            <option value="<?= h($ak['id']) ?>"><?= h($ak['label']) ?> — <?= h($ak['model_text']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="sw-modal-actions">
                    <button type="button" class="sw-btn sw-btn-secondary sw-modal-cancel">Cancel</button>
                    <button type="button" class="sw-btn sw-btn-secondary" id="sw-start-manual"
                            title="Skip AI — write the opening yourself">✏️ Start Manually</button>
                    <button type="submit" class="sw-btn sw-btn-primary">✨ Generate with AI →</button>
                </div>
            </form>
        </div>
    </div>

    <script src="<?= h($base) ?>/_assets/sw.js"></script>
</body>
</html>
