<?php
/**
 * StoryWeaver — User settings page (§5.6).
 *
 * Allows logged-in users to manage their API keys, change password,
 * and update profile information. Tabs: API Keys, Profile, Password.
 */

require_once __DIR__ . '/_lib/auth_check.php';
require_once __DIR__ . '/_lib/api_keys.php';

// Must be logged in
$user = current_user();
if ($user === null) {
    flash('error', 'Please log in to access settings.');
    redirect(base_url() . '/auth.php?action=login');
}

$base = base_url();

// Handle POST actions (profile, password)
if (is_post()) {
    csrf_check();
    $form_action = $_POST['form_action'] ?? '';

    switch ($form_action) {
        case 'update_profile':
            handle_update_profile($user);
            break;
        case 'change_password':
            handle_change_password($user);
            break;
    }
}

$tab = $_GET['tab'] ?? 'keys';
$my_keys = api_keys_for_user($user['id']);

// Determine which key would be auto-selected (§3.1 priority)
$primary_key = api_key_select_for_user($user['id']);
$primary_key_id = $primary_key ? $primary_key['id'] : null;

// Render page
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= h(csrf_token()) ?>">
    <title>Settings — StoryWeaver</title>
    <link rel="stylesheet" href="<?= h($base) ?>/_themes/<?= h(theme_css()) ?>">
</head>
<body>
    <nav class="sw-nav">
        <a href="<?= h($base) ?>/index.php" class="sw-nav-brand">🧶 StoryWeaver</a>
        <ul class="sw-nav-links">
            <li><a href="<?= h($base) ?>/settings.php">⚙️ Settings</a></li>
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

        <h1>Settings</h1>

        <!-- Tabs -->
        <div class="sw-tabs">
            <a href="?tab=keys" class="sw-tab <?= $tab === 'keys' ? 'sw-tab-active' : '' ?>">🔑 API Keys</a>
            <a href="?tab=profile" class="sw-tab <?= $tab === 'profile' ? 'sw-tab-active' : '' ?>">👤 Profile</a>
            <a href="?tab=password" class="sw-tab <?= $tab === 'password' ? 'sw-tab-active' : '' ?>">🔒 Password</a>
        </div>

        <?php if ($tab === 'keys'): ?>
        <!-- ─── API Keys Tab ─── -->
        <div class="sw-settings-section">
            <h2>Your API Keys</h2>
            <p class="sw-text-muted">Configure AI provider keys to enable story generation.</p>

            <?php if (empty($my_keys)): ?>
                <p class="sw-text-muted" style="margin: 1.5rem 0;">No API keys configured yet. Add one below to enable AI-powered story generation.</p>
            <?php else: ?>
                <div class="sw-key-list">
                    <?php foreach ($my_keys as $key): ?>
                        <div class="sw-key-item" data-key-id="<?= h($key['id']) ?>">
                            <div class="sw-key-info">
                                <strong><?= h($key['label']) ?></strong>
                                <span class="sw-badge sw-badge-<?= $key['status'] === 'active' ? 'success' : 'warning' ?>">
                                    <?= $key['status'] === 'active' ? '✓ Active' : '⚠️ Unavailable' ?>
                                </span>
                                <?php if ($key['id'] === $primary_key_id): ?>
                                    <span class="sw-badge sw-badge-primary">★ Primary</span>
                                <?php endif; ?>
                                <span class="sw-text-muted">
                                    <?= h($key['provider']) ?> · <?= h($key['model_text']) ?>
                                    · scope: <?= h($key['scope']) ?>
                                </span>
                                <?php if ($key['last_failure']): ?>
                                    <span class="sw-text-muted sw-text-sm">Last failure: <?= h($key['last_failure']) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="sw-key-actions">
                                <button type="button" class="sw-btn sw-btn-sm sw-btn-secondary sw-key-test"
                                        data-key-id="<?= h($key['id']) ?>">🧪 Test</button>
                                <?php if ($key['status'] === 'unavailable'): ?>
                                    <button type="button" class="sw-btn sw-btn-sm sw-btn-secondary sw-key-reactivate"
                                            data-key-id="<?= h($key['id']) ?>">🔄 Reactivate</button>
                                <?php else: ?>
                                    <button type="button" class="sw-btn sw-btn-sm sw-btn-secondary sw-key-deactivate"
                                            data-key-id="<?= h($key['id']) ?>">⏸ Deactivate</button>
                                <?php endif; ?>
                                <button type="button" class="sw-btn sw-btn-sm sw-btn-danger sw-key-delete"
                                        data-key-id="<?= h($key['id']) ?>">🗑 Delete</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <h3 style="margin-top:2rem;">Add New Key</h3>
            <form id="sw-add-key-form" class="sw-form">
                <div class="sw-form-row">
                    <div class="sw-form-group">
                        <label for="key-label">Label</label>
                        <input type="text" id="key-label" class="sw-input" placeholder="e.g. My OpenAI Key" required>
                    </div>
                    <div class="sw-form-group">
                        <label for="key-provider">Provider</label>
                        <select id="key-provider" class="sw-input">
                            <option value="openai">OpenAI</option>
                            <option value="anthropic">Anthropic</option>
                            <option value="ollama">Ollama (local)</option>
                            <option value="custom">Custom (OpenAI-compatible)</option>
                        </select>
                    </div>
                </div>

                <div class="sw-form-group">
                    <label for="key-base-url">Base URL</label>
                    <input type="url" id="key-base-url" class="sw-input"
                           placeholder="https://api.openai.com/v1">
                    <span class="sw-text-muted sw-text-sm">Auto-filled for known providers. Set manually for Ollama/custom.</span>
                </div>

                <div class="sw-form-group" id="key-api-key-group">
                    <label for="key-api-key">API Key</label>
                    <input type="password" id="key-api-key" class="sw-input"
                           placeholder="sk-...">
                    <span class="sw-text-muted sw-text-sm">Not needed for local Ollama.</span>
                </div>

                <div class="sw-form-row">
                    <div class="sw-form-group">
                        <label for="key-model-text">Text Model</label>
                        <input type="text" id="key-model-text" class="sw-input"
                               placeholder="gpt-4o" required>
                    </div>
                    <div class="sw-form-group">
                        <label for="key-model-image">Image Model <span class="sw-text-muted">(optional)</span></label>
                        <input type="text" id="key-model-image" class="sw-input"
                               placeholder="dall-e-3">
                    </div>
                </div>

                <div class="sw-form-group">
                    <label for="key-scope">Scope</label>
                    <select id="key-scope" class="sw-input">
                        <option value="self">Self — only you can use this key</option>
                        <option value="all">All — share with all users</option>
                    </select>
                </div>

                <div class="sw-form-actions">
                    <button type="submit" class="sw-btn sw-btn-primary">Add Key</button>
                    <span id="sw-key-form-status" class="sw-editor-status"></span>
                </div>
            </form>
        </div>

        <?php elseif ($tab === 'profile'): ?>
        <!-- ─── Profile Tab ─── -->
        <div class="sw-settings-section">
            <h2>Profile</h2>
            <form method="POST" action="<?= h($base) ?>/settings.php?tab=profile">
                <input type="hidden" name="form_action" value="update_profile">
                <input type="hidden" name="_csrf_token" value="<?= h(csrf_token()) ?>">

                <div class="sw-form-group">
                    <label for="profile-username">Username</label>
                    <input type="text" id="profile-username" name="username" class="sw-input"
                           value="<?= h($user['username']) ?>" required>
                </div>

                <div class="sw-form-group">
                    <label for="profile-email">Email</label>
                    <input type="email" id="profile-email" name="email" class="sw-input"
                           value="<?= h($user['email']) ?>" required>
                </div>

                <button type="submit" class="sw-btn sw-btn-primary">Save Profile</button>
            </form>
        </div>

        <?php elseif ($tab === 'password'): ?>
        <!-- ─── Password Tab ─── -->
        <div class="sw-settings-section">
            <h2>Change Password</h2>
            <form method="POST" action="<?= h($base) ?>/settings.php?tab=password">
                <input type="hidden" name="form_action" value="change_password">
                <input type="hidden" name="_csrf_token" value="<?= h(csrf_token()) ?>">

                <div class="sw-form-group">
                    <label for="current-password">Current Password</label>
                    <input type="password" id="current-password" name="current_password" class="sw-input" required>
                </div>

                <div class="sw-form-group">
                    <label for="new-password">New Password</label>
                    <input type="password" id="new-password" name="new_password" class="sw-input"
                           minlength="8" required>
                </div>

                <div class="sw-form-group">
                    <label for="confirm-password">Confirm New Password</label>
                    <input type="password" id="confirm-password" name="confirm_password" class="sw-input"
                           minlength="8" required>
                </div>

                <button type="submit" class="sw-btn sw-btn-primary">Change Password</button>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <script src="<?= h($base) ?>/_assets/sw.js"></script>
</body>
</html>
<?php

/* ======================================================================
 * POST HANDLERS
 * ====================================================================*/

/**
 * Handle profile update (username, email).
 *
 * @param array $user Current user record.
 * @return void
 */
function handle_update_profile(array $user): void
{
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');

    if ($username === '' || strlen($username) < 3 || strlen($username) > 30) {
        flash('error', 'Username must be 3–30 characters.');
        redirect(base_url() . '/settings.php?tab=profile');
    }

    if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        flash('error', 'Username may only contain letters, numbers, and underscores.');
        redirect(base_url() . '/settings.php?tab=profile');
    }

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('error', 'A valid email address is required.');
        redirect(base_url() . '/settings.php?tab=profile');
    }

    // Check for username collision with another user
    $existing = user_find_by_username($username);
    if ($existing !== null && $existing['id'] !== $user['id']) {
        flash('error', 'That username is already taken.');
        redirect(base_url() . '/settings.php?tab=profile');
    }

    $existing_email = user_find_by_email($email);
    if ($existing_email !== null && $existing_email['id'] !== $user['id']) {
        flash('error', 'That email is already in use.');
        redirect(base_url() . '/settings.php?tab=profile');
    }

    user_update($user['id'], [
        'username' => $username,
        'email'    => $email,
    ]);

    flash('success', 'Profile updated.');
    redirect(base_url() . '/settings.php?tab=profile');
}

/**
 * Handle password change.
 *
 * @param array $user Current user record.
 * @return void
 */
function handle_change_password(array $user): void
{
    $current  = $_POST['current_password'] ?? '';
    $new_pass = $_POST['new_password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (!user_verify_password($user, $current)) {
        flash('error', 'Current password is incorrect.');
        redirect(base_url() . '/settings.php?tab=password');
    }

    if (strlen($new_pass) < 8) {
        flash('error', 'New password must be at least 8 characters.');
        redirect(base_url() . '/settings.php?tab=password');
    }

    if ($new_pass !== $confirm) {
        flash('error', 'New passwords do not match.');
        redirect(base_url() . '/settings.php?tab=password');
    }

    user_update($user['id'], [
        'password_hash' => password_hash($new_pass, PASSWORD_BCRYPT),
    ]);

    flash('success', 'Password changed successfully.');
    redirect(base_url() . '/settings.php?tab=password');
}
