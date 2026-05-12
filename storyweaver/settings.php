<?php
/**
 * StoryWeaver — User settings page (§5.6).
 *
 * Allows logged-in users to manage their API keys, AI settings, change
 * password, and update profile information.
 */

require_once __DIR__ . '/_lib/auth_check.php';
require_once __DIR__ . '/_lib/api_keys.php';
require_once __DIR__ . '/_lib/ai_settings.php';

// Must be logged in
$user = current_user();
if ($user === null) {
    flash('error', 'Please log in to access settings.');
    redirect(auth_url('login'));
}

$base = base_url();

// Handle POST actions
if (is_post()) {
    csrf_check();
    $form_action = $_POST['form_action'] ?? '';

    switch ($form_action) {
        case 'save_ai_settings':
            handle_save_ai_settings($user);
            break;
        case 'reset_ai_settings':
            handle_reset_ai_settings($user);
            break;
        case 'save_ai_schema':
            handle_save_ai_schema($user);
            break;
        case 'reset_ai_schema':
            handle_reset_ai_schema($user);
            break;
        case 'update_profile':
            handle_update_profile($user);
            break;
        case 'change_password':
            handle_change_password($user);
            break;
    }
}

$tab = $_GET['tab'] ?? 'keys';
if (!in_array($tab, ['keys', 'ai', 'profile', 'password'], true)) {
    $tab = 'keys';
}
$my_keys = api_keys_for_user($user['id']);
$ai_preferences = ai_user_settings($user['id']);
$story_schema_text = ai_story_json_schema();
$schema_edit_level = ai_story_schema_edit_level();
$can_edit_schema = ai_user_can_edit_story_schema($user);
$can_manage_schema_permissions = ai_user_can_manage_schema_permissions($user);

// Determine which key would be auto-selected (§3.1 priority)
$primary_key = api_key_select_for_user($user['id']);
$primary_key_id = $primary_key ? $primary_key['id'] : null;

// Keys that can be chosen as a fallback: own keys + any "all"-scoped active keys
$all_keys = api_keys_read();
$default_public_key_id = api_key_default_public_id();
$available_fallback_keys = array_values(array_filter($all_keys, function ($k) use ($user) {
    if (($k['status'] ?? '') !== 'active') {
        return false;
    }
    return ($k['owner_user_id'] ?? '') === $user['id']
        || ($k['scope'] ?? '') === 'all';
}));

$shared_active_keys = [];
if (($user['role'] ?? '') === 'admin') {
    foreach ($all_keys as $key) {
        if (($key['scope'] ?? '') !== 'all' || ($key['status'] ?? '') !== 'active') {
            continue;
        }

        $owner_label = trim((string) ($key['owner_username'] ?? ''));
        if ($owner_label === '') {
            $owner = user_find_by_id((string) ($key['owner_user_id'] ?? ''));
            $owner_label = trim((string) ($owner['username'] ?? ''));
        }
        if ($owner_label === '') {
            $owner_label = 'Unknown owner';
        }

        $shared_active_keys[] = [
            'id' => $key['id'],
            'label' => $key['label'],
            'provider' => $key['provider'],
            'model_text' => $key['model_text'] ?? '',
            'owner' => $owner_label,
        ];
    }
}

// Render page
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= h(csrf_token()) ?>">
    <title>Settings — StoryWeaver</title>
    <?php render_brand_favicon_links(); ?>
    <link rel="stylesheet" href="<?= h($base) ?>/_themes/<?= h(theme_css()) ?>">
</head>
<body>
    <?php render_main_nav($user, 'settings'); ?>

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
            <a href="?tab=ai" class="sw-tab <?= $tab === 'ai' ? 'sw-tab-active' : '' ?>">🤖 AI</a>
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
                        <?php
                        $key_payload = [
                            'id' => $key['id'],
                            'label' => $key['label'],
                            'provider' => $key['provider'],
                            'base_url' => $key['base_url'] ?? '',
                            'model_text' => $key['model_text'] ?? '',
                            'model_image' => $key['model_image'] ?? '',
                            'scope' => $key['scope'] ?? 'self',
                            'fallback_key_id' => $key['fallback_key_id'] ?? '',
                        ];
                        ?>
                        <div class="sw-key-item"
                             data-key-id="<?= h($key['id']) ?>"
                             data-key='<?= h(json_encode($key_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'>
                            <div class="sw-key-info">
                                <strong><?= h($key['label']) ?></strong>
                                <span class="sw-badge sw-badge-<?= $key['status'] === 'active' ? 'success' : 'warning' ?>">
                                    <?= $key['status'] === 'active' ? '✓ Active' : '⚠️ Unavailable' ?>
                                </span>
                                <?php if ($key['id'] === $primary_key_id): ?>
                                    <span class="sw-badge sw-badge-primary">★ Primary</span>
                                <?php endif; ?>
                                <?php if (($key['scope'] ?? '') === 'all' && $key['id'] === $default_public_key_id): ?>
                                    <span class="sw-badge sw-badge-primary">🌐 Default Public</span>
                                <?php endif; ?>
                                <span class="sw-text-muted">
                                    <?= h($key['provider']) ?> · <?= h($key['model_text']) ?>
                                    · scope: <?= h($key['scope']) ?>
                                    <?php if (!empty($key['fallback_key_id'])): ?>
                                        <?php
                                        $fb_label = '';
                                        foreach ($all_keys as $k) {
                                            if ($k['id'] === $key['fallback_key_id']) {
                                                $fb_label = $k['label'];
                                                break;
                                            }
                                        }
                                        ?>
                                        · fallback: <?= h($fb_label ?: $key['fallback_key_id']) ?>
                                    <?php endif; ?>
                                </span>
                                <?php if (!empty($key['base_url'])): ?>
                                    <span class="sw-text-muted sw-text-sm">Endpoint: <?= h($key['base_url']) ?></span>
                                <?php endif; ?>
                                <?php if ($key['last_failure']): ?>
                                    <span class="sw-text-muted sw-text-sm">Last failure: <?= h($key['last_failure']) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="sw-key-actions">
                                <button type="button" class="sw-btn sw-btn-sm sw-btn-secondary sw-key-edit"
                                        data-key-id="<?= h($key['id']) ?>">✏️ Edit</button>
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

            <?php if (($user['role'] ?? '') === 'admin' && count($shared_active_keys) > 1): ?>
                <div class="sw-settings-section" style="margin-top:1.5rem;">
                    <h3>Default Public API Key</h3>
                    <p class="sw-text-muted">Choose which shared key guests and users without a personal key should get by default.</p>
                    <div class="sw-form-group">
                        <label for="sw-default-public-key">Shared key</label>
                        <select id="sw-default-public-key" class="sw-input">
                            <option value="">Automatic — newest active shared key</option>
                            <?php foreach ($shared_active_keys as $shared_key): ?>
                                <option value="<?= h($shared_key['id']) ?>" <?= $shared_key['id'] === $default_public_key_id ? 'selected' : '' ?>>
                                    <?= h($shared_key['label']) ?> — <?= h($shared_key['owner']) ?> (<?= h($shared_key['provider']) ?> · <?= h($shared_key['model_text']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="sw-form-actions">
                        <button type="button" id="sw-save-default-public-key-btn" class="sw-btn sw-btn-secondary">Save Default Public Key</button>
                        <span id="sw-default-public-key-status" class="sw-editor-status"></span>
                    </div>
                </div>
            <?php endif; ?>

            <h3 id="sw-key-form-heading" style="margin-top:2rem;">Add New Key</h3>
            <form id="sw-add-key-form" class="sw-form">
                <input type="hidden" id="key-id" value="">
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
                            <option value="gemini">Google Gemini</option>
                            <option value="ollama">Ollama (local)</option>
                            <option value="custom">Custom (OpenAI-compatible)</option>
                        </select>
                    </div>
                </div>

                <div class="sw-form-group">
                    <label for="key-base-url">Base URL</label>
                    <input type="url" id="key-base-url" class="sw-input"
                           placeholder="https://api.openai.com/v1">
                    <span class="sw-text-muted sw-text-sm">Auto-filled for known providers. For Ollama use <code>http://&lt;host&gt;:11434/v1</code> — the <code>/v1</code> is required.</span>
                </div>

                <div class="sw-form-group" id="key-api-key-group">
                    <label for="key-api-key">API Key</label>
                    <input type="password" id="key-api-key" class="sw-input"
                            placeholder="sk-...">
                    <span id="sw-key-api-key-note" class="sw-text-muted sw-text-sm">Not needed for local Ollama.</span>
                </div>

                <div class="sw-form-row">
                    <div class="sw-form-group">
                        <label for="key-model-text">Text Model</label>
                        <input type="text" id="key-model-text" class="sw-input"
                               placeholder="gpt-4o" required>
                        <select id="key-model-text-select" class="sw-input sw-model-select" disabled>
                            <option value="">Choose from fetched models…</option>
                        </select>
                    </div>
                    <div class="sw-form-group">
                        <label for="key-model-image">Image Model <span class="sw-text-muted">(optional)</span></label>
                        <input type="text" id="key-model-image" class="sw-input"
                               placeholder="dall-e-3">
                        <select id="key-model-image-select" class="sw-input sw-model-select" disabled>
                            <option value="">Choose from fetched models…</option>
                        </select>
                    </div>
                </div>

                <div class="sw-form-group">
                    <label for="key-scope">Scope</label>
                    <select id="key-scope" class="sw-input">
                        <option value="self">Self — only you can use this key</option>
                        <option value="all">All — share with all users</option>
                    </select>
                </div>

                <?php if (!empty($available_fallback_keys)): ?>
                <div class="sw-form-group">
                    <label for="key-fallback">Fallback Key <span class="sw-text-muted">(optional)</span></label>
                    <select id="key-fallback" class="sw-input">
                        <option value="">— None —</option>
                        <?php foreach ($available_fallback_keys as $fk): ?>
                            <option value="<?= h($fk['id']) ?>">
                                <?= h($fk['label']) ?> (<?= h($fk['provider']) ?> · <?= h($fk['model_text']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="sw-text-muted sw-text-sm">
                        Used automatically if this key can't reach its endpoint (e.g. Ollama is offline).
                        One level only — the fallback won't chain further.
                    </span>
                </div>
                <?php endif; ?>

                <div class="sw-form-actions">
                    <button type="button" id="sw-fetch-models-btn" class="sw-btn sw-btn-secondary">🔄 Fetch Models</button>
                    <button type="submit" id="sw-key-submit-btn" class="sw-btn sw-btn-primary">Add Key</button>
                    <button type="button" id="sw-key-cancel-edit" class="sw-btn sw-btn-secondary" hidden>Cancel Edit</button>
                    <span id="sw-key-form-status" class="sw-editor-status"></span>
                </div>
            </form>
        </div>

        <?php elseif ($tab === 'ai'): ?>
        <div class="sw-settings-section">
            <h2>AI Prompt &amp; Model Settings</h2>
            <p class="sw-text-muted">
                These settings apply to your own text-generation requests. The shared JSON schema below is appended separately so you can tune the prompt text without editing the output structure.
            </p>

            <form method="POST" action="<?= h(app_url('settings', ['tab' => 'ai'])) ?>" class="sw-form">
                <input type="hidden" name="form_action" value="save_ai_settings">
                <input type="hidden" name="_csrf_token" value="<?= h(csrf_token()) ?>">

                <div class="sw-form-group">
                    <label for="ai-system-prompt" title="This is the editable text portion of your story-generation system prompt. The shared JSON schema is inserted automatically before the Rules section when possible.">Story system prompt</label>
                    <textarea id="ai-system-prompt"
                              name="system_prompt_body"
                              class="sw-input"
                              rows="14"><?= h($ai_preferences['system_prompt_body']) ?></textarea>
                    <span class="sw-text-muted sw-text-sm">
                        Edit the instructions and rules used for story text generation. The shared JSON schema stays separate so the response format can still be managed centrally.
                    </span>
                </div>

                <div class="sw-settings-grid">
                    <div class="sw-form-group">
                        <label for="ai-temperature" title="Temperature controls randomness. Lower values make output steadier and more predictable; higher values allow more variation.">Temperature</label>
                        <input type="number" id="ai-temperature" name="temperature" class="sw-input" min="0" max="2" step="0.05"
                               value="<?= h((string) $ai_preferences['temperature']) ?>">
                        <span class="sw-text-muted sw-text-sm">Passed only to models that support custom temperature.</span>
                    </div>

                    <div class="sw-form-group">
                        <label for="ai-top-p" title="Top P limits token sampling to the most likely slice of the distribution. Lower values narrow the output; 1.0 leaves it unconstrained.">Top P</label>
                        <input type="number" id="ai-top-p" name="top_p" class="sw-input" min="0" max="1" step="0.05"
                               value="<?= h((string) $ai_preferences['top_p']) ?>">
                        <span class="sw-text-muted sw-text-sm">Useful for tightening or widening variation without changing the schema.</span>
                    </div>

                    <div class="sw-form-group">
                        <label for="ai-max-tokens" title="Max output tokens caps the length of the model response. Higher values allow longer replies, but some providers may ignore or reject this control.">Max Output Tokens</label>
                        <input type="number" id="ai-max-tokens" name="max_tokens" class="sw-input" min="128" max="8192" step="1"
                               value="<?= h((string) $ai_preferences['max_tokens']) ?>">
                        <span class="sw-text-muted sw-text-sm">Only sent to providers/models that support an explicit response length limit.</span>
                    </div>
                </div>

                <div class="sw-form-actions">
                    <button type="submit" class="sw-btn sw-btn-primary">Save My AI Settings</button>
                </div>
            </form>

            <form method="POST" action="<?= h(app_url('settings', ['tab' => 'ai'])) ?>" class="sw-settings-inline-form">
                <input type="hidden" name="form_action" value="reset_ai_settings">
                <input type="hidden" name="_csrf_token" value="<?= h(csrf_token()) ?>">
                <button type="submit" class="sw-btn sw-btn-secondary">Reset My AI Settings to Defaults</button>
            </form>
        </div>

        <div class="sw-settings-section" style="margin-top:2rem;">
            <h2>Shared JSON Schema</h2>
            <p class="sw-text-muted">
                This schema is appended to story-generation prompts for every user. Changing it can break generation if it no longer matches the parser’s expected <code>paragraphs</code> and <code>choices</code> structure.
            </p>

            <?php if ($can_edit_schema): ?>
                <form method="POST" action="<?= h(app_url('settings', ['tab' => 'ai'])) ?>" class="sw-form">
                    <input type="hidden" name="form_action" value="save_ai_schema">
                    <input type="hidden" name="_csrf_token" value="<?= h(csrf_token()) ?>">

                    <div class="sw-form-group">
                        <label for="ai-story-schema" title="This is the shared response schema appended to story-generation prompts. Keep the required paragraphs and choices structure intact.">Story JSON schema</label>
                        <textarea id="ai-story-schema"
                                  name="story_json_schema"
                                  class="sw-input sw-theme-editor"
                                  rows="12"><?= h($story_schema_text) ?></textarea>
                        <span class="sw-text-muted sw-text-sm">Be careful: schema changes take effect immediately for story generation and prompt preview.</span>
                    </div>

                    <?php if ($can_manage_schema_permissions): ?>
                        <div class="sw-form-group">
                            <label for="ai-schema-edit-level" title="Choose the minimum logged-in role allowed to edit the shared schema text.">Who can edit the shared schema?</label>
                            <select id="ai-schema-edit-level" name="schema_edit_level" class="sw-input">
                                <option value="admin" <?= $schema_edit_level === 'admin' ? 'selected' : '' ?>>Admins only</option>
                                <option value="editor" <?= $schema_edit_level === 'editor' ? 'selected' : '' ?>>Admins + editors</option>
                                <option value="contributor" <?= $schema_edit_level === 'contributor' ? 'selected' : '' ?>>Admins + editors + contributors</option>
                            </select>
                            <span class="sw-text-muted sw-text-sm">Admins always retain access, even when the permission is extended.</span>
                        </div>
                    <?php endif; ?>

                    <div class="sw-form-actions">
                        <button type="submit" class="sw-btn sw-btn-primary">Save Shared Schema</button>
                    </div>
                </form>

                <form method="POST" action="<?= h(app_url('settings', ['tab' => 'ai'])) ?>" class="sw-settings-inline-form">
                    <input type="hidden" name="form_action" value="reset_ai_schema">
                    <input type="hidden" name="_csrf_token" value="<?= h(csrf_token()) ?>">
                    <button type="submit" class="sw-btn sw-btn-secondary">
                        <?= $can_manage_schema_permissions ? 'Reset Default JSON Schema and Permissions' : 'Reset JSON Schema to Default' ?>
                    </button>
                </form>
            <?php else: ?>
                <div class="sw-form-group">
                    <label for="ai-story-schema-readonly">Current shared schema</label>
                    <textarea id="ai-story-schema-readonly"
                              class="sw-input sw-theme-editor"
                              rows="12"
                              readonly><?= h($story_schema_text) ?></textarea>
                    <span class="sw-text-muted sw-text-sm">
                        You can edit your own story system prompt above, but this shared schema currently requires <?= h($schema_edit_level) ?> access to change.
                    </span>
                </div>
            <?php endif; ?>
        </div>

        <?php elseif ($tab === 'profile'): ?>
        <!-- ─── Profile Tab ─── -->
        <div class="sw-settings-section">
            <h2>Profile</h2>
            <form method="POST" action="<?= h(app_url('settings', ['tab' => 'profile'])) ?>">
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
            <form method="POST" action="<?= h(app_url('settings', ['tab' => 'password'])) ?>">
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

    <script src="<?= h($base) ?>/_assets/sw.js?v=<?= filemtime(sw_root() . '/_assets/sw.js') ?>"></script>
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
    $current_username = (string) ($user['username'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');

    if ($username === '' || strlen($username) < 3 || strlen($username) > 30) {
        flash('error', 'Username must be 3–30 characters.');
        redirect(app_url('settings', ['tab' => 'profile']));
    }

    if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        flash('error', 'Username may only contain letters, numbers, and underscores.');
        redirect(app_url('settings', ['tab' => 'profile']));
    }

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('error', 'A valid email address is required.');
        redirect(app_url('settings', ['tab' => 'profile']));
    }

    // Check for username collision with another user
    $existing = user_find_by_username($username);
    if ($existing !== null && $existing['id'] !== $user['id']) {
        flash('error', 'That username is already taken.');
        redirect(app_url('settings', ['tab' => 'profile']));
    }

    $existing_email = user_find_by_email($email);
    if ($existing_email !== null && $existing_email['id'] !== $user['id']) {
        flash('error', 'That email is already in use.');
        redirect(app_url('settings', ['tab' => 'profile']));
    }

    if ($username !== $current_username) {
        try {
            api_keys_reencrypt_for_username($user['id'], $current_username, $username);
        } catch (RuntimeException $e) {
            flash('error', 'Profile update failed while re-encrypting API keys: ' . $e->getMessage());
            redirect(app_url('settings', ['tab' => 'profile']));
        }
    }

    user_update($user['id'], [
        'username' => $username,
        'email'    => $email,
    ]);

    flash('success', 'Profile updated.');
    redirect(app_url('settings', ['tab' => 'profile']));
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
        redirect(app_url('settings', ['tab' => 'password']));
    }

    if (strlen($new_pass) < 8) {
        flash('error', 'New password must be at least 8 characters.');
        redirect(app_url('settings', ['tab' => 'password']));
    }

    if ($new_pass !== $confirm) {
        flash('error', 'New passwords do not match.');
        redirect(app_url('settings', ['tab' => 'password']));
    }

    user_update($user['id'], [
        'password_hash' => password_hash($new_pass, PASSWORD_BCRYPT),
    ]);

    flash('success', 'Password changed successfully.');
    redirect(app_url('settings', ['tab' => 'password']));
}

/**
 * Save per-user AI prompt and generation settings.
 */
function handle_save_ai_settings(array $user): void
{
    try {
        ai_settings_update_user((string) $user['id'], [
            'system_prompt_body' => (string) ($_POST['system_prompt_body'] ?? ''),
            'temperature'        => $_POST['temperature'] ?? null,
            'top_p'              => $_POST['top_p'] ?? null,
            'max_tokens'         => $_POST['max_tokens'] ?? null,
        ]);
        flash('success', 'AI settings updated.');
    } catch (InvalidArgumentException $e) {
        flash('error', $e->getMessage());
    }

    redirect(app_url('settings', ['tab' => 'ai']));
}

/**
 * Reset per-user AI settings back to defaults.
 */
function handle_reset_ai_settings(array $user): void
{
    try {
        ai_settings_reset_user((string) $user['id']);
        flash('success', 'Your AI settings were reset to defaults.');
    } catch (InvalidArgumentException $e) {
        flash('error', $e->getMessage());
    }

    redirect(app_url('settings', ['tab' => 'ai']));
}

/**
 * Save the shared story schema and, for admins, its edit permissions.
 */
function handle_save_ai_schema(array $user): void
{
    if (!ai_user_can_edit_story_schema($user)) {
        flash('error', 'You do not have permission to edit the shared JSON schema.');
        redirect(app_url('settings', ['tab' => 'ai']));
    }

    $settings = [
        'story_json_schema' => (string) ($_POST['story_json_schema'] ?? ''),
    ];

    if (ai_user_can_manage_schema_permissions($user)) {
        $settings['schema_edit_level'] = (string) ($_POST['schema_edit_level'] ?? 'admin');
    }

    ai_settings_update_site($settings);
    flash('success', 'Shared JSON schema updated.');
    redirect(app_url('settings', ['tab' => 'ai']));
}

/**
 * Reset the shared schema text back to its default.
 */
function handle_reset_ai_schema(array $user): void
{
    if (!ai_user_can_edit_story_schema($user)) {
        flash('error', 'You do not have permission to reset the shared JSON schema.');
        redirect(app_url('settings', ['tab' => 'ai']));
    }

    $include_permissions = ai_user_can_manage_schema_permissions($user);
    ai_settings_reset_site($include_permissions);
    flash('success', $include_permissions
        ? 'The shared JSON schema and permissions were reset to defaults.'
        : 'The shared JSON schema was reset to its default.');
    redirect(app_url('settings', ['tab' => 'ai']));
}
