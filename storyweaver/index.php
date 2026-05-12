<?php
/**
 * StoryWeaver — Landing / Story List page.
 *
 * Lists all stories, shows auth status, and provides the entry point
 * for creating new adventures.
 */

require_once __DIR__ . '/_lib/auth_check.php';
require_once __DIR__ . '/_lib/api_keys.php';
require_once __DIR__ . '/_lib/nodes.php';

// First-run: redirect to setup if no users exist
if (!users_exists()) {
    redirect(auth_url('setup'));
}

$user = current_user();
$base = base_url();
$can_edit_announcement = $user !== null && ($user['role'] ?? '') === 'admin';
$can_jump_latest = $user !== null && ($user['role'] ?? '') === 'admin';

/**
 * Convert stored rich-text HTML into a plain-text length-checking string.
 */
function index_plain_text_from_html(string $html): string
{
    $html = str_ireplace(['<br />', '<br/>', '<br>'], "\n", $html);
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
    $text = preg_replace("/\R{3,}/u", "\n\n", $text) ?? $text;
    return trim($text);
}

/**
 * Decode and sanitize rich-text announcement paragraphs.
 *
 * @throws LengthException When the announcement exceeds the plain-text limit.
 * @return array<int, string>
 */
function index_decode_announcement_paragraphs(string $json): array
{
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return [];
    }

    $clean = [];
    $plain_text_parts = [];
    foreach ($decoded as $paragraph) {
        if (!is_string($paragraph)) {
            continue;
        }

        $sanitized = sanitize_paragraph_html($paragraph);
        if ($sanitized === '') {
            continue;
        }

        $plain = index_plain_text_from_html($sanitized);
        if ($plain === '') {
            continue;
        }

        $clean[] = $sanitized;
        $plain_text_parts[] = $plain;
    }

    $plain_text = trim(implode("\n\n", $plain_text_parts));
    if (mb_strlen($plain_text) > 4000) {
        throw new LengthException('Announcement text must stay within 4000 characters.');
    }

    return $clean;
}

if (is_post()) {
    csrf_check();

    if (!$can_edit_announcement) {
        flash('error', 'Admin access required.');
        redirect(app_url('index'));
    }

    if (($_POST['form_action'] ?? '') === 'save_announcement') {
        try {
            $paragraph_json = (string) ($_POST['announcement_paragraphs'] ?? '');
            if ($paragraph_json !== '') {
                site_announcement_save_paragraphs(index_decode_announcement_paragraphs($paragraph_json));
            } else {
                site_announcement_save((string) ($_POST['announcement'] ?? ''));
            }
            flash('success', 'Announcement updated.');
        } catch (LengthException $e) {
            flash('error', $e->getMessage());
        }
        redirect(app_url('index'));
    }
}

$announcement = site_announcement_text();
$announcement_html = site_announcement_html();
$announcement_paragraphs = site_announcement_paragraphs();
$announcement_editor_json = json_encode($announcement_paragraphs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($announcement_editor_json)) {
    $announcement_editor_json = '[]';
}

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

/**
 * Build a compact story-card snippet from the root node.
 */
function index_story_card_snippet(array $root_node): string
{
    $scenario = trim((string) (($root_node['sw_meta']['scenario_essentials'] ?? '')));
    if ($scenario !== '') {
        $text = $scenario;
    } else {
        $parts = [];
        foreach (($root_node['paragraphs'] ?? []) as $paragraph) {
            $clean = html_entity_decode(strip_tags((string) $paragraph), ENT_QUOTES, 'UTF-8');
            $clean = trim(preg_replace('/\s+/u', ' ', $clean) ?? $clean);
            if ($clean !== '') {
                $parts[] = $clean;
            }
        }
        $text = implode(' ', $parts);
    }

    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    return $text === '' ? '' : mb_strimwidth($text, 0, 180, '…', 'UTF-8');
}

/**
 * Return the first root-node image URL for use on the story card.
 */
function index_story_card_thumbnail_url(string $root_node_id, string $base_url): ?string
{
    $images = glob(sw_root() . '/_assets/images/' . $root_node_id . '-*');
    if (empty($images)) {
        return null;
    }

    sort($images, SORT_NATURAL);
    return $base_url . '/_assets/images/' . basename($images[0]);
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
        $root_node_id = null;

        // Find the root node within the public stories directory only.
        foreach ($nodes as $node_file) {
            $html = file_get_contents($node_file);
            if ($html === false) continue;

            $parsed = node_parse_html($html, 'stories');
            if (($parsed['parent_id'] ?? '') === '') {
                $root_node = $parsed;
                $root_node_id = basename($node_file, '.html');
                break;
            }
        }

        if ($root_node === null || $root_node_id === null) {
            continue;
        }

        $author_id = (string) ($root_node['author_id'] ?? 'anonymous');
        $author = $author_id;
        if (str_starts_with($author_id, 'usr_')) {
            $author_user = user_find_by_id($author_id);
            if ($author_user) {
                $author = $author_user['username'];
            }
        }

        $stories[] = [
            'story_id' => $story_id,
            'title' => (string) ($root_node['title'] ?? $story_id),
            'created' => (string) ($root_node['created_at'] ?? ''),
            'author' => $author,
            'node_count' => count($nodes),
            'root_node' => $root_node_id,
            'latest_node' => $can_jump_latest ? story_find_latest_node($story_id, true) : null,
            'thumbnail_url' => index_story_card_thumbnail_url($root_node_id, $base),
            'snippet' => index_story_card_snippet($root_node),
        ];
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
    <?php render_brand_favicon_links(); ?>
    <link rel="stylesheet" href="<?= h($base) ?>/_themes/<?= h(theme_css()) ?>">
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

        <?php if ($announcement !== '' || $can_edit_announcement): ?>
            <section class="sw-card sw-announcement-card">
                <details id="sw-announcement-panel" class="sw-announcement-panel" open>
                    <summary>
                        <span class="sw-card-title sw-announcement-panel-title">News and Announcements</span>
                    </summary>
                    <div class="sw-announcement-panel-body">
                        <?php if ($announcement_html !== ''): ?>
                            <div class="sw-announcement-content"><?= $announcement_html ?></div>
                        <?php elseif (!$can_edit_announcement): ?>
                            <p class="sw-text-muted">No announcement at the moment.</p>
                        <?php endif; ?>

                        <?php if ($can_edit_announcement): ?>
                            <details class="sw-announcement-editor" id="sw-announcement-details" <?= $announcement === '' ? 'open' : '' ?>>
                                <summary><?= $announcement === '' ? 'Add announcement' : 'Edit announcement' ?></summary>
                                <form method="POST" action="<?= h(app_url('index')) ?>" class="sw-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="form_action" value="save_announcement">
                                    <input type="hidden" id="sw-announcement-paragraphs-input" name="announcement_paragraphs" value="<?= h($announcement_editor_json) ?>">
                                    <div class="sw-form-group" style="margin-top:0.75rem;">
                                        <label>Homepage announcement</label>
                                        <div id="sw-announcement-editor"
                                             class="sw-announcement-rich-editor"
                                             data-original-paragraphs="<?= h($announcement_editor_json) ?>">
                                            <div id="sw-announcement-toolbar" class="sw-editor-toolbar">
                                                <button type="button" id="sw-announcement-bold" class="sw-btn sw-btn-secondary sw-btn-sm"
                                                        title="Bold (Ctrl+B)"><strong>B</strong></button>
                                                <button type="button" id="sw-announcement-italic" class="sw-btn sw-btn-secondary sw-btn-sm"
                                                        title="Italic (Ctrl+I)"><em>I</em></button>

                                                <div class="sw-editor-toolbar-separator"></div>

                                                <button type="button" id="sw-announcement-add-para" class="sw-btn sw-btn-secondary sw-btn-sm"
                                                        title="Add paragraph">+ ¶</button>
                                                <button type="button" id="sw-announcement-source-toggle" class="sw-btn sw-btn-secondary sw-btn-sm"
                                                        title="Toggle source mode">&lt;/&gt; Source</button>

                                                <div class="sw-editor-toolbar-spacer"></div>

                                                <span id="sw-announcement-editor-status" class="sw-editor-status"></span>

                                                <div class="sw-editor-toolbar-separator"></div>

                                                <button type="button" id="sw-announcement-editor-cancel" class="sw-btn sw-btn-secondary sw-btn-sm">Cancel</button>
                                                <button type="submit" id="sw-announcement-editor-save" class="sw-btn sw-btn-primary sw-btn-sm">💾 Save</button>
                                            </div>

                                            <div id="sw-announcement-editor-content" class="sw-editor-content">
                                                <?php if (!empty($announcement_paragraphs)): ?>
                                                    <?php foreach ($announcement_paragraphs as $paragraph): ?>
                                                        <p class="sw-para" contenteditable="true"><?= $paragraph ?></p>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <p class="sw-para" contenteditable="true"></p>
                                                <?php endif; ?>
                                            </div>

                                            <textarea id="sw-announcement-editor-source" class="sw-editor-source"></textarea>
                                        </div>
                                        <noscript>
                                            <textarea name="announcement"
                                                      class="sw-input"
                                                      rows="5"
                                                      maxlength="4000"
                                                      placeholder="Share site news, maintenance notes, or featured events."><?= h($announcement) ?></textarea>
                                        </noscript>
                                    </div>
                                </form>
                            </details>
                        <?php endif; ?>
                    </div>
                </details>
            </section>
        <?php endif; ?>

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
                    <div class="sw-story-item">
                        <a href="<?= h(node_url($story['story_id'], $story['root_node'])) ?>"
                           class="sw-story-item-main">
                            <?php if (!empty($story['thumbnail_url'])): ?>
                                <div class="sw-story-item-thumb-wrap">
                                    <img src="<?= h($story['thumbnail_url']) ?>" alt="" class="sw-story-item-thumb" loading="lazy">
                                </div>
                            <?php endif; ?>
                            <div class="sw-story-item-body">
                                <div class="sw-story-item-head">
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
                                </div>
                                <?php if (($story['snippet'] ?? '') !== ''): ?>
                                    <p class="sw-story-item-snippet"><?= h($story['snippet']) ?></p>
                                <?php endif; ?>
                            </div>
                        </a>
                        <?php if (
                            $can_jump_latest
                            && !empty($story['latest_node'])
                            && $story['latest_node'] !== $story['root_node']
                        ): ?>
                            <div class="sw-story-item-actions">
                                <a href="<?= h(node_url($story['story_id'], (string) $story['latest_node'])) ?>"
                                   class="sw-btn sw-btn-sm sw-btn-secondary">
                                    🕒 Latest Page
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- New Story Modal -->
    <div id="sw-new-story-modal" class="sw-modal-backdrop">
        <div class="sw-modal">
            <h2>Begin New Story</h2>
            <form method="POST" action="<?= h(app_url('play')) ?>">
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
                              rows="6" maxlength="4000"
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

    <script src="<?= h($base) ?>/_assets/sw.js?v=<?= filemtime(sw_root() . '/_assets/sw.js') ?>"></script>
</body>
</html>
