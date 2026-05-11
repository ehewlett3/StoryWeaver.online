<?php
/**
 * StoryWeaver — In-app help and documentation.
 */

require_once __DIR__ . '/_lib/auth_check.php';

$user = current_user();
$base = base_url();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= h(csrf_token()) ?>">
    <title>Help — StoryWeaver</title>
    <?php render_brand_favicon_links(); ?>
    <link rel="stylesheet" href="<?= h($base) ?>/_themes/<?= h(theme_css()) ?>">
</head>
<body>
    <?php render_main_nav($user, 'help'); ?>

    <div class="sw-container">
        <?php foreach (get_flashes() as $type => $messages): ?>
            <?php foreach ($messages as $message): ?>
                <div class="sw-flash sw-flash-<?= h($type) ?>">
                    <?= h($message) ?>
                    <button class="sw-flash-dismiss" aria-label="Dismiss">&times;</button>
                </div>
            <?php endforeach; ?>
        <?php endforeach; ?>

        <header class="sw-help-hero">
            <p class="sw-help-kicker">❓ Help</p>
            <h1>How StoryWeaver works</h1>
            <p class="sw-help-intro">
                StoryWeaver is a flat-file fantasy storytelling app. Stories can be played in a mobile-friendly choose-your-own-adventure flow,
                continued AI-dungeon-style by typing custom actions, written fully by hand, or built from any mix of those approaches.
            </p>
        </header>

        <section class="sw-help-grid">
            <a class="sw-help-card" href="#getting-started">
                <h2>🚀 Getting started</h2>
                <p>Create an account, begin a story, and choose how much AI help you want.</p>
            </a>
            <a class="sw-help-card" href="#story-flow">
                <h2>📚 Story flow</h2>
                <p>Understand roots, pages, choices, continuations, and custom actions.</p>
            </a>
            <a class="sw-help-card" href="#ai-tools">
                <h2>✨ AI tools</h2>
                <p>Learn prompt preview, Scenario Essentials, regeneration, and images.</p>
            </a>
            <a class="sw-help-card" href="#settings-keys">
                <h2>🔑 Settings & keys</h2>
                <p>Configure providers, models, fallbacks, and personal settings.</p>
            </a>
            <a class="sw-help-card" href="#moderation">
                <h2>🛡️ Moderation</h2>
                <p>See how concerns, quarantine, approval, and roles work.</p>
            </a>
            <a class="sw-help-card" href="#themes">
                <h2>🎨 Themes</h2>
                <p>Apply site themes and story-specific themes for different moods.</p>
            </a>
            <a class="sw-help-card" href="#advanced-help">
                <h2>🧰 Advanced help</h2>
                <p>Expand setup instructions for self-hosting the open-source app.</p>
            </a>
        </section>

        <section id="getting-started" class="sw-help-section">
            <h2>🚀 Getting started</h2>
            <p><strong>Guests</strong> can read stories, follow branches, and use shared AI keys. Logged-in contributors can also edit their own pages and manage their own API keys.</p>
            <p>Use <strong>📚 Stories</strong> to return to the main list. Use <strong>❓ Help</strong> any time you need a refresher on features or roles.</p>
            <p>To begin, click <strong>Begin New Story</strong>. You can start manually, let AI generate the opening, or start with AI and then branch into manual or custom-action play whenever you like.</p>
            <p>Admins can also post a rich-text homepage <strong>Announcement</strong> above the story list for site news, events, or maintenance notes.</p>
        </section>

        <section id="story-flow" class="sw-help-section">
            <h2>📚 Story flow</h2>
            <p>Each story starts with a <strong>root page</strong>. Every later page belongs to the same story and records the choice that led there.</p>
            <ul class="sw-help-list">
                <li><strong>Choice links</strong> open an existing child page.</li>
                <li><strong>Pending choices</strong> do not have a child page yet, so following them creates the next page.</li>
                <li><strong>Custom actions</strong> let you type your own next move in an AI-dungeon-style flow instead of choosing one of the listed options.</li>
                <li><strong>Edit</strong> opens the rich-text editor for pages you are allowed to change.</li>
                <li><strong>Latest Page</strong> lets admins jump straight to the newest page in a story from the story list or while viewing that story.</li>
                <li><strong>Mixed play</strong> means one story can freely combine tap-to-choose branches, typed actions, AI continuation, and hand-written scenes.</li>
            </ul>
        </section>

        <section id="ai-tools" class="sw-help-section">
            <h2>✨ AI tools</h2>
            <p><strong>Scenario Essentials</strong> are story-wide notes stored on the first page. They are carried forward into future AI continuation prompts so tone, premise, and constraints remain consistent.</p>
            <ul class="sw-help-list">
                <li><strong>Generate with AI</strong> creates the next page and three choices when a text model is available.</li>
                <li><strong>Preview Prompts</strong> shows the exact system prompt, story context, and image prompt that would be sent.</li>
                <li><strong>Regenerate Story</strong> creates a candidate replacement for the current page and its unlinked choices, then lets you compare the old and new versions before deciding.</li>
                <li><strong>Generate Image</strong> and <strong>Regenerate Image</strong> create illustrations for the current page. Image regeneration lets you compare versions side by side.</li>
                <li><strong>Abort</strong> appears on long-running generation overlays so you can cancel if a provider stalls instead of waiting for the full timeout.</li>
                <li><strong>Regeneration guidance</strong> lets you add optional steering text when regenerating story text or images.</li>
            </ul>
        </section>

        <section id="settings-keys" class="sw-help-section">
            <h2>🔑 Settings & API keys</h2>
            <p>Open <strong>⚙️ Settings</strong> to manage AI providers and your account.</p>
            <ul class="sw-help-list">
                <li><strong>API Keys</strong> store provider, base URL, text model, image model, scope, and an optional fallback key.</li>
                <li><strong>Fetch Models</strong> asks the configured provider for available models and fills the dropdowns.</li>
                <li><strong>Edit</strong> lets you rename a key and change its settings without revealing the stored secret.</li>
                <li><strong>Default Public API Key</strong> lets admins choose which shared <strong>All</strong>-scoped key guests and other users get by default when more than one shared key is active.</li>
                <li><strong>Profile</strong> updates your username and email.</li>
                <li><strong>Password</strong> changes your login password.</li>
            </ul>
            <p>Registered users are encouraged to bring their own AI API key. Keep it scoped to <strong>Self</strong> for private use, or switch it to <strong>All</strong> if you want to support the site by sharing it with everyone.</p>
        </section>

        <section id="moderation" class="sw-help-section">
            <h2>🛡️ Moderation, concerns, and quarantine</h2>
            <p>Any reader can flag a page for concern. Editors and admins can review those flags, move content to quarantine, restore it, or delete it.</p>
            <ul class="sw-help-list">
                <li><strong>Concern Queue</strong> lists flagged pages awaiting review.</li>
                <li><strong>Quarantine</strong> hides content from public browsing while keeping it available to authorized users.</li>
                <li><strong>Authorized access</strong> includes editors/admins and the story owner, so quarantined work can still be continued and repaired.</li>
            </ul>
        </section>

        <section id="themes" class="sw-help-section">
            <h2>🎨 Themes and visual style</h2>
            <p>StoryWeaver ships with multiple built-in themes. Admins can change the site-wide theme from the Admin dashboard, and story owners can set a per-story theme from the root page.</p>
            <p>The active site theme affects application chrome and standard story styling. A per-story theme only changes that story’s pages.</p>
        </section>

        <section id="roles" class="sw-help-section">
            <h2>👥 Roles</h2>
            <ul class="sw-help-list">
                <li><strong>Guest / Viewer</strong> — read and continue stories using shared AI keys.</li>
                <li><strong>Contributor</strong> — edit your own pages and manage your own API keys.</li>
                <li><strong>Editor</strong> — edit any page, review concerns, and manage quarantine.</li>
                <li><strong>Admin</strong> — manage users, site themes, all keys, and prompt preview.</li>
            </ul>
        </section>

        <section id="advanced-help" class="sw-help-advanced">
            <details>
                <summary>Advanced: install and self-host the open-source app</summary>
                <div class="sw-help-advanced-content">
                    <p>StoryWeaver runs as a flat-file PHP app. You do not need a database, build pipeline, or package manager.</p>
                    <ol class="sw-help-steps">
                        <li>Clone the repository or upload the files to an Apache web host.</li>
                        <li>Serve the <code>storyweaver/</code> directory from a document root or subdirectory.</li>
                        <li>Enable <code>mod_rewrite</code> and allow <code>.htaccess</code> overrides so private folders stay protected and extensionless URLs work.</li>
                        <li>Make the <code>storyweaver/</code> tree writable so the app can create users, stories, keys, and generated images.</li>
                        <li>Visit the site and complete the first-run setup to create the initial admin account.</li>
                    </ol>
                    <code class="sw-help-code">git clone https://github.com/ehewlett3/StoryWeaver.online.git
cd StoryWeaver.online
php -S localhost:8080</code>
                    <p>For Apache deployments, enable rewrite support and <code>AllowOverride All</code>. The built-in PHP server is convenient for local development, but it does not honor the private-directory <code>.htaccess</code> protections used in production.</p>
                </div>
            </details>
        </section>
    </div>

    <script src="<?= h($base) ?>/_assets/sw.js?v=<?= filemtime(sw_root() . '/_assets/sw.js') ?>"></script>
</body>
</html>
