<?php
/**
 * StoryWeaver — Admin Dashboard (§5.5).
 *
 * Tabs: Concern Queue, Quarantine, API Keys, Users, Themes.
 * Access: Concern Queue + Quarantine require editor+.
 * API Keys, Users, Themes require admin.
 */

require_once __DIR__ . '/_lib/auth_check.php';
require_once __DIR__ . '/_lib/moderation.php';
require_once __DIR__ . '/_lib/api_keys.php';
require_once __DIR__ . '/_lib/nodes.php';

// Must be at least editor to access admin page
$user = current_user();
if (!$user || !in_array($user['role'], ['editor', 'admin'])) {
    flash('error', 'You do not have permission to access the admin dashboard.');
    redirect(base_url() . '/index.php');
}

$base = base_url();
$tab = $_GET['tab'] ?? 'concerns';
$is_admin = ($user['role'] === 'admin');

// Tab access control
$editor_tabs = ['concerns', 'quarantine'];
$admin_tabs  = ['keys', 'users', 'themes'];

if (in_array($tab, $admin_tabs) && !$is_admin) {
    $tab = 'concerns';
}

// Load data for current tab
$concerns = [];
$quarantine_log = [];
$all_keys = [];
$all_users = [];
$story_titles = [];

switch ($tab) {
    case 'concerns':
        $concerns = concern_get_open();
        break;
    case 'quarantine':
        $quarantine_log = quarantine_get_log();
        break;
    case 'keys':
        if ($is_admin) {
            $all_keys = api_keys_read();
        }
        break;
    case 'users':
        if ($is_admin) {
            $all_users = users_list();
        }
        break;
}

function admin_story_title(array &$story_titles, string $story_id): string
{
    if (!isset($story_titles[$story_id])) {
        $story_titles[$story_id] = story_get_title($story_id);
    }
    return $story_titles[$story_id];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= h(csrf_token()) ?>">
    <title>Admin Dashboard — StoryWeaver</title>
    <link rel="stylesheet" href="<?= h($base) ?>/_themes/<?= h(theme_css()) ?>">
</head>
<body>

<?php render_main_nav($user, 'admin'); ?>

<div class="sw-container">

    <!-- Flash messages -->
    <?php foreach (get_flashes() as $type => $messages): ?>
        <?php foreach ($messages as $message): ?>
            <div class="sw-flash sw-flash-<?= h($type) ?>"><?= h($message) ?></div>
        <?php endforeach; ?>
    <?php endforeach; ?>

    <h1>Admin Dashboard</h1>

    <!-- Tabs -->
    <div class="sw-tabs">
        <a href="?tab=concerns" class="sw-tab <?= $tab === 'concerns' ? 'sw-tab-active' : '' ?>">
            ⚑ Concerns
            <?php if (count(concern_get_open()) > 0): ?>
                <span class="sw-badge sw-badge-danger"><?= count(concern_get_open()) ?></span>
            <?php endif; ?>
        </a>
        <a href="?tab=quarantine" class="sw-tab <?= $tab === 'quarantine' ? 'sw-tab-active' : '' ?>">
            🔒 Quarantine
        </a>
        <?php if ($is_admin): ?>
            <a href="?tab=keys" class="sw-tab <?= $tab === 'keys' ? 'sw-tab-active' : '' ?>">
                🔑 API Keys
            </a>
            <a href="?tab=users" class="sw-tab <?= $tab === 'users' ? 'sw-tab-active' : '' ?>">
                👥 Users
            </a>
            <a href="?tab=themes" class="sw-tab <?= $tab === 'themes' ? 'sw-tab-active' : '' ?>">
                🎨 Themes
            </a>
        <?php endif; ?>
    </div>

    <!-- Tab Content -->
    <div class="sw-tab-content">

    <?php if ($tab === 'concerns'): ?>
        <!-- ===== Concern Queue ===== -->
        <h2>Concern Queue</h2>
        <?php if (empty($concerns)): ?>
            <p class="sw-text-muted">No open concerns. 🎉</p>
        <?php else: ?>
            <div class="sw-admin-list">
                <?php foreach ($concerns as $concern): ?>
                    <div class="sw-admin-item">
                        <div class="sw-admin-item-info">
                            <strong>
                                <a href="<?= h($base) ?>/node.php?story=<?= h($concern['story_id']) ?>&id=<?= h($concern['node_id']) ?>">
                                    <?= h(admin_story_title($story_titles, $concern['story_id'])) ?>
                                </a>
                            </strong>
                            <span class="sw-text-muted">
                                <?= h($concern['story_id']) ?> · page <?= h($concern['node_id']) ?>
                                · flagged by <?= h($concern['flagged_by']) ?>
                                · <?= h($concern['flagged_at']) ?>
                            </span>
                            <?php if ($concern['reason'] !== ''): ?>
                                <p class="sw-concern-reason">"<?= h($concern['reason']) ?>"</p>
                            <?php endif; ?>
                        </div>
                        <div class="sw-admin-item-actions">
                            <button type="button" class="sw-btn sw-btn-sm sw-btn-secondary sw-admin-action"
                                    data-action="dismiss_concern"
                                    data-id="<?= h($concern['id']) ?>">
                                ✓ Dismiss
                            </button>
                            <button type="button" class="sw-btn sw-btn-sm sw-btn-danger sw-admin-action"
                                    data-action="flag_review"
                                    data-story-id="<?= h($concern['story_id']) ?>"
                                    data-node-id="<?= h($concern['node_id']) ?>"
                                    data-concern-id="<?= h($concern['id']) ?>">
                                🔒 Quarantine
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    <?php elseif ($tab === 'quarantine'): ?>
        <!-- ===== Quarantine ===== -->
        <h2>Quarantined Pages</h2>
        <?php if (empty($quarantine_log)): ?>
            <p class="sw-text-muted">No pages in quarantine.</p>
        <?php else: ?>
            <div class="sw-admin-list">
                <?php foreach ($quarantine_log as $entry): ?>
                    <div class="sw-admin-item">
                        <div class="sw-admin-item-info">
                            <strong>
                                <a href="<?= h($base) ?>/node.php?story=<?= h($entry['story_id']) ?>&id=<?= h($entry['node_id']) ?>">
                                    <?= h(admin_story_title($story_titles, $entry['story_id'])) ?>
                                </a>
                            </strong>
                            <span class="sw-text-muted">
                                <?= h($entry['story_id']) ?> · page <?= h($entry['node_id']) ?>
                                · <?= count($entry['subtree']) ?> page(s)
                                · quarantined by <?= h($entry['flagged_by']) ?>
                                · <?= h($entry['flagged_at']) ?>
                            </span>
                        </div>
                        <div class="sw-admin-item-actions">
                            <button type="button" class="sw-btn sw-btn-sm sw-btn-primary sw-admin-action"
                                    data-action="approve_node"
                                    data-story-id="<?= h($entry['story_id']) ?>"
                                    data-node-id="<?= h($entry['node_id']) ?>">
                                ✓ Approve &amp; Restore
                            </button>
                            <button type="button" class="sw-btn sw-btn-sm sw-btn-danger sw-admin-action"
                                    data-action="delete_node"
                                    data-story-id="<?= h($entry['story_id']) ?>"
                                    data-node-id="<?= h($entry['node_id']) ?>">
                                🗑️ Delete
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    <?php elseif ($tab === 'keys' && $is_admin): ?>
        <!-- ===== API Keys (Admin) ===== -->
        <h2>All API Keys</h2>
        <?php if (empty($all_keys)): ?>
            <p class="sw-text-muted">No API keys configured.</p>
        <?php else: ?>
            <div class="sw-admin-list">
                <?php foreach ($all_keys as $key): ?>
                    <div class="sw-admin-item">
                        <div class="sw-admin-item-info">
                            <strong><?= h($key['label']) ?></strong>
                            <span class="sw-badge sw-badge-<?= $key['status'] === 'active' ? 'success' : 'warning' ?>">
                                <?= $key['status'] === 'active' ? '✓ Active' : '⚠️ ' . h($key['status']) ?>
                            </span>
                            <span class="sw-text-muted">
                                <?= h($key['provider']) ?> · <?= h($key['model_text']) ?>
                                · scope: <?= h($key['scope']) ?>
                                · owner: <?= h($key['owner_user_id']) ?>
                            </span>
                        </div>
                        <div class="sw-admin-item-actions">
                            <?php if ($key['status'] === 'active'): ?>
                                <button type="button" class="sw-btn sw-btn-sm sw-btn-danger sw-admin-action"
                                        data-action="deactivate_api_key"
                                        data-id="<?= h($key['id']) ?>">
                                    Deactivate
                                </button>
                            <?php else: ?>
                                <button type="button" class="sw-btn sw-btn-sm sw-btn-secondary sw-admin-action"
                                        data-action="reactivate_api_key"
                                        data-id="<?= h($key['id']) ?>">
                                    Reactivate
                                </button>
                            <?php endif; ?>
                            <button type="button" class="sw-btn sw-btn-sm sw-btn-danger sw-admin-action"
                                    data-action="delete_api_key"
                                    data-id="<?= h($key['id']) ?>">
                                Delete
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    <?php elseif ($tab === 'users' && $is_admin): ?>
        <!-- ===== Users (Admin) ===== -->
        <h2>User Management</h2>
        <div class="sw-admin-list">
            <?php foreach ($all_users as $u): ?>
                <div class="sw-admin-item">
                    <div class="sw-admin-item-info">
                        <strong><?= h($u['username']) ?></strong>
                        <span class="sw-badge sw-badge-<?= $u['role'] === 'admin' ? 'primary' : 'success' ?>">
                            <?= h($u['role']) ?>
                        </span>
                        <span class="sw-text-muted">
                            <?= h($u['email'] ?? '') ?>
                            · joined <?= h($u['created_at'] ?? 'unknown') ?>
                        </span>
                    </div>
                    <?php if ($u['id'] !== $user['id']): ?>
                        <div class="sw-admin-item-actions">
                            <select class="sw-input sw-input-sm sw-admin-role-select"
                                    data-user-id="<?= h($u['id']) ?>"
                                    data-current-role="<?= h($u['role']) ?>">
                                <option value="viewer" <?= $u['role'] === 'viewer' ? 'selected' : '' ?>>Viewer</option>
                                <option value="contributor" <?= $u['role'] === 'contributor' ? 'selected' : '' ?>>Contributor</option>
                                <option value="editor" <?= $u['role'] === 'editor' ? 'selected' : '' ?>>Editor</option>
                                <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                            </select>
                            <button type="button" class="sw-btn sw-btn-sm sw-btn-danger sw-admin-action"
                                    data-action="delete_user"
                                    data-id="<?= h($u['id']) ?>">
                                Delete
                            </button>
                        </div>
                    <?php else: ?>
                        <div class="sw-admin-item-actions">
                            <span class="sw-text-muted">(you)</span>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

    <?php elseif ($tab === 'themes' && $is_admin): ?>
        <!-- ===== Themes (Admin) ===== -->
        <h2>Theme Management</h2>
        <?php
        $themes_data = themes_read();
        $active_theme = $themes_data['active'];
        $edit_theme = $_GET['edit'] ?? '';
        $edit_css = '';
        if ($edit_theme !== '' && file_exists(sw_root() . '/_themes/' . basename($edit_theme))) {
            $edit_css = file_get_contents(sw_root() . '/_themes/' . basename($edit_theme));
        }
        ?>

        <?php if ($edit_theme !== '' && $edit_css !== ''): ?>
            <!-- CSS Editor -->
            <div class="sw-form-group">
                <h3>Editing: <?= h($edit_theme) ?></h3>
                <form method="POST" action="<?= h($base) ?>/api.php?action=save_theme_css">
                    <input type="hidden" name="_csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="theme_file" value="<?= h($edit_theme) ?>">
                    <textarea name="css" class="sw-input sw-theme-editor" rows="25"><?= h($edit_css) ?></textarea>
                    <div class="sw-modal-actions" style="margin-top:0.75rem">
                        <a href="<?= h($base) ?>/admin.php?tab=themes" class="sw-btn sw-btn-secondary">Cancel</a>
                        <button type="submit" class="sw-btn sw-btn-primary">💾 Save CSS</button>
                    </div>
                </form>
            </div>
        <?php else: ?>
            <div class="sw-admin-list">
                <?php foreach ($themes_data['themes'] as $theme): ?>
                    <div class="sw-admin-item">
                        <div class="sw-admin-item-info">
                            <strong><?= h($theme['name']) ?></strong>
                            <span class="sw-text-muted"><?= h($theme['file']) ?></span>
                            <?php if ($theme['file'] === $active_theme): ?>
                                <span class="sw-badge sw-badge-success">✓ Active</span>
                            <?php endif; ?>
                        </div>
                        <div class="sw-admin-item-actions">
                            <a href="<?= h($base) ?>/admin.php?tab=themes&edit=<?= h($theme['file']) ?>"
                               class="sw-btn sw-btn-sm sw-btn-secondary">
                                ✏️ Edit CSS
                            </a>
                            <a href="<?= h($base) ?>/index.php?preview_theme=<?= h($theme['file']) ?>"
                               class="sw-btn sw-btn-sm sw-btn-secondary" target="_blank">
                                👁️ Preview
                            </a>
                            <?php if ($theme['file'] !== $active_theme): ?>
                                <button type="button" class="sw-btn sw-btn-sm sw-btn-primary sw-admin-action"
                                        data-action="apply_theme"
                                        data-theme="<?= h($theme['file']) ?>">
                                    🎨 Apply
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    <?php endif; ?>

    </div><!-- .sw-tab-content -->

</div><!-- .sw-container -->

<script src="<?= h($base) ?>/_assets/sw.js?v=<?= filemtime(sw_root() . '/_assets/sw.js') ?>"></script>
</body>
</html>
