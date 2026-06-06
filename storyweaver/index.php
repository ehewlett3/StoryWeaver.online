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
$story_sort_options = [
    'created' => 'Date created',
    'updated' => 'Date updated',
    'title' => 'Title',
    'author' => 'Author',
    'pages' => 'Number of pages',
];
$story_sort = strtolower(trim((string) ($_GET['sort'] ?? 'created')));
if (!isset($story_sort_options[$story_sort])) {
    $story_sort = 'created';
}
$story_sort_dir = strtolower(trim((string) ($_GET['dir'] ?? 'desc')));
if (!in_array($story_sort_dir, ['asc', 'desc'], true)) {
    $story_sort_dir = 'desc';
}

/**
 * Convert stored rich-text HTML into a plain-text length-checking string.
 */
function index_plain_text_from_html(string $html): string
{
    return rich_html_to_text($html);
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

// Get available AI keys for the new story model pickers.
$user_id_for_key = $user ? $user['id'] : null;
$all_active_keys = [];
$text_active_keys = [];
$image_active_keys = [];
$all_keys = api_keys_read();
foreach ($all_keys as $k) {
    if (($k['status'] ?? '') !== 'active') continue;
    $visible = (($k['scope'] ?? '') === 'all')
            || ($user && ($k['scope'] ?? '') === 'self' && ($k['owner_user_id'] ?? '') === $user['id']);
    if (!$visible) continue;

    $entry = [
        'id'          => $k['id'],
        'label'       => $k['label'],
        'provider'    => $k['provider'],
        'model_text'  => $k['model_text'] ?? '',
        'model_image' => $k['model_image'] ?? '',
    ];
    $all_active_keys[] = $entry;
    if (!empty($entry['model_text'])) {
        $text_active_keys[] = $entry;
    }
    if (!empty($entry['model_image'])) {
        $image_active_keys[] = $entry;
    }
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
            $clean = rich_html_to_text((string) $paragraph);
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
function index_story_card_thumbnail_url(string $story_id, string $root_node_id): ?string
{
    $images = glob(sw_root() . '/_assets/images/' . $root_node_id . '-*');
    if (empty($images)) {
        return null;
    }

    sort($images, SORT_NATURAL);
    return image_url($story_id, $root_node_id, basename($images[0]));
}

/**
 * Normalize story-card text for sorting.
 */
function index_story_sort_text(string $value): string
{
    return mb_strtolower(
        trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')),
        'UTF-8'
    );
}

/**
 * Convert a stored node timestamp to a sortable integer.
 */
function index_story_sort_timestamp(string $value): int
{
    $timestamp = strtotime($value);
    return $timestamp === false ? 0 : $timestamp;
}

/**
 * Format a story-card date label.
 */
function index_story_card_date_label(string $value): string
{
    $timestamp = strtotime($value);
    return $timestamp === false ? '' : date('M j, Y', $timestamp);
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

        if (!story_user_can_access($story_id, $user)) {
            continue;
        }
        $privacy_info = story_privacy_info($story_id);

        $author_id = (string) ($root_node['author_id'] ?? 'anonymous');
        $author = $author_id;
        if (str_starts_with($author_id, 'usr_')) {
            $author_user = user_find_by_id($author_id);
            if ($author_user) {
                $author = $author_user['username'];
            }
        }

        $latest_public = story_latest_node_info($story_id, false);
        $latest_any = $can_jump_latest ? story_latest_node_info($story_id, true) : null;
        $created_at = (string) ($root_node['created_at'] ?? '');
        $updated_at = (string) ($latest_public['created_at'] ?? $created_at);

        $stories[] = [
            'story_id' => $story_id,
            'title' => (string) ($root_node['title'] ?? $story_id),
            'created' => $created_at,
            'updated' => $updated_at,
            'author' => $author,
            'node_count' => count($nodes),
            'root_node' => $root_node_id,
            'latest_node' => $can_jump_latest ? (string) ($latest_any['node_id'] ?? '') : null,
            'thumbnail_url' => index_story_card_thumbnail_url($story_id, $root_node_id),
            'snippet' => index_story_card_snippet($root_node),
            'visibility' => $privacy_info['visibility'],
        ];
    }

    usort($stories, function ($a, $b) use ($story_sort, $story_sort_dir) {
        $comparison = 0;

        switch ($story_sort) {
            case 'updated':
                $comparison = index_story_sort_timestamp((string) ($a['updated'] ?? ''))
                    <=> index_story_sort_timestamp((string) ($b['updated'] ?? ''));
                break;
            case 'title':
                $comparison = strcmp(
                    index_story_sort_text((string) ($a['title'] ?? '')),
                    index_story_sort_text((string) ($b['title'] ?? ''))
                );
                break;
            case 'author':
                $comparison = strcmp(
                    index_story_sort_text((string) ($a['author'] ?? '')),
                    index_story_sort_text((string) ($b['author'] ?? ''))
                );
                break;
            case 'pages':
                $comparison = ((int) ($a['node_count'] ?? 0)) <=> ((int) ($b['node_count'] ?? 0));
                break;
            case 'created':
            default:
                $comparison = index_story_sort_timestamp((string) ($a['created'] ?? ''))
                    <=> index_story_sort_timestamp((string) ($b['created'] ?? ''));
                break;
        }

        if ($comparison === 0) {
            $comparison = strcmp(
                index_story_sort_text((string) ($a['title'] ?? '')),
                index_story_sort_text((string) ($b['title'] ?? ''))
            );
        }

        if ($comparison === 0) {
            $comparison = strcmp((string) ($a['story_id'] ?? ''), (string) ($b['story_id'] ?? ''));
        }

        return $story_sort_dir === 'asc' ? $comparison : -$comparison;
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
                                                        <?= render_editor_fragment_html((string) $paragraph) . "\n" ?>
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

        <div class="sw-story-list-toolbar">
            <div class="sw-story-list-heading">
                <h1>Stories</h1>
                <?php if (!empty($stories)): ?>
                    <details class="sw-story-filter-menu">
                        <summary class="sw-story-filter-toggle">
                            <span class="sw-filter-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">
                                    <path d="M3 5h18l-7 8v5l-4 2v-7L3 5z"></path>
                                </svg>
                            </span>
                            Filter
                        </summary>
                        <form method="GET" action="<?= h(app_url('index')) ?>" class="sw-story-sort-form">
                            <label for="sw-story-sort" class="sw-story-sort-label">Sort by</label>
                            <select id="sw-story-sort" name="sort" class="sw-input sw-input-sm">
                                <?php foreach ($story_sort_options as $sort_value => $sort_label): ?>
                                    <option value="<?= h($sort_value) ?>" <?= $story_sort === $sort_value ? 'selected' : '' ?>>
                                        <?= h($sort_label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <label for="sw-story-sort-dir" class="sw-story-sort-label">Order</label>
                            <select id="sw-story-sort-dir" name="dir" class="sw-input sw-input-sm">
                                <option value="desc" <?= $story_sort_dir === 'desc' ? 'selected' : '' ?>>Descending</option>
                                <option value="asc" <?= $story_sort_dir === 'asc' ? 'selected' : '' ?>>Ascending</option>
                            </select>
                            <button type="submit" class="sw-btn sw-btn-secondary sw-btn-sm">Apply</button>
                        </form>
                    </details>
                <?php endif; ?>
            </div>
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
                                         <div class="sw-story-item-title">
                                             <?= h($story['title']) ?>
                                             <?php if (($story['visibility'] ?? 'public') === 'private'): ?>
                                                 <span class="sw-badge sw-badge-muted">Private</span>
                                             <?php endif; ?>
                                         </div>
                                         <div class="sw-story-item-meta">
                                             by <?= h($story['author']) ?>
                                             · <?= $story['node_count'] ?> page<?= $story['node_count'] !== 1 ? 's' : '' ?>
                                         </div>
                                     </div>
                                     <div class="sw-story-item-date-group">
                                         <?php $created_label = index_story_card_date_label((string) ($story['created'] ?? '')); ?>
                                         <?php if ($created_label !== ''): ?>
                                             <div class="sw-story-item-meta">
                                                 <span class="sw-story-item-meta-label">Created:</span> <?= h($created_label) ?>
                                             </div>
                                         <?php endif; ?>
                                         <?php $updated_label = index_story_card_date_label((string) ($story['updated'] ?? '')); ?>
                                         <?php if ($updated_label !== ''): ?>
                                             <div class="sw-story-item-meta">
                                                 <span class="sw-story-item-meta-label">Updated:</span> <?= h($updated_label) ?>
                                             </div>
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
                <input type="hidden" name="story_visibility" id="sw-new-story-visibility" value="public">
                <input type="hidden" name="auto_generate_images" id="sw-new-story-auto-images" value="0">

                <div class="sw-form-group">
                    <label for="story-title">Story Title</label>
                    <input type="text" id="story-title" name="title" class="sw-input"
                           placeholder="e.g. The Lost Temple of Shadows" required autofocus
                           maxlength="200">
                </div>

                <div class="sw-form-group">
                    <label for="story-opening">Story Opening <span class="sw-text-muted">(optional)</span></label>
                    <textarea id="story-opening" name="story_opening" class="sw-input"
                              rows="5" maxlength="6000"
                              placeholder="Write the first lines or paragraphs exactly as the story should begin."></textarea>
                    <span class="sw-text-muted sw-text-sm">This text becomes the start of the first page; generated text will be appended after it.</span>
                </div>

                <div class="sw-form-group">
                    <label for="scenario-essentials">Story Guidelines <span class="sw-text-muted">(optional)</span></label>
                    <textarea id="scenario-essentials" name="scenario_essentials" class="sw-input"
                              rows="6" maxlength="4000"
                              placeholder="e.g. Medieval fantasy, the hero is a young blacksmith, dark forest setting…"></textarea>
                    <span class="sw-text-muted sw-text-sm">Help the AI set the scene. Leave blank for a surprise.</span>
                </div>

                <div class="sw-form-group">
                    <label>AI Models</label>
                    <div class="sw-new-story-model-grid">
                        <div>
                            <label for="sw-key-picker-modal" class="sw-text-muted sw-text-sm">Text</label>
                            <select id="sw-key-picker-modal" name="key_id" class="sw-input sw-input-sm" <?= empty($text_active_keys) ? 'disabled' : '' ?>>
                                <?php if (empty($text_active_keys)): ?>
                                    <option value="">No text model available</option>
                                <?php else: ?>
                                    <?php foreach ($text_active_keys as $ak): ?>
                                        <option value="<?= h($ak['id']) ?>"><?= h($ak['label']) ?> — <?= h($ak['model_text']) ?></option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div>
                            <label for="sw-image-key-picker-modal" class="sw-text-muted sw-text-sm">Image</label>
                            <select id="sw-image-key-picker-modal" name="image_key_id" class="sw-input sw-input-sm" <?= empty($image_active_keys) ? 'disabled' : '' ?>>
                                <?php if (empty($image_active_keys)): ?>
                                    <option value="">No image model available</option>
                                <?php else: ?>
                                    <?php foreach ($image_active_keys as $ak): ?>
                                        <option value="<?= h($ak['id']) ?>"><?= h($ak['label']) ?> — <?= h($ak['model_image']) ?></option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="sw-new-story-toggle-row">
                    <button type="button" id="sw-new-story-private-toggle"
                            class="sw-btn sw-btn-sm sw-btn-secondary"
                            data-state="public" <?= $user ? '' : 'disabled' ?>>
                        Public Story
                    </button>
                    <button type="button" id="sw-new-story-auto-image-toggle"
                            class="sw-btn sw-btn-sm sw-btn-secondary"
                            data-state="off" <?= (!empty($image_active_keys) && $user) ? '' : 'disabled' ?>>
                        Auto Pictures Off
                    </button>
                </div>

                <div class="sw-modal-actions">
                    <button type="button" class="sw-btn sw-btn-secondary sw-modal-cancel">Cancel</button>
                    <button type="button" class="sw-btn sw-btn-secondary" id="sw-start-manual"
                            title="Skip AI — write the opening yourself">✏️ Start Manually</button>
                    <button type="submit" class="sw-btn sw-btn-primary" <?= empty($text_active_keys) ? 'disabled title="No text AI model is available"' : '' ?>>✨ Generate with AI →</button>
                </div>
            </form>
        </div>
    </div>

    <script src="<?= h($base) ?>/_assets/sw.js?v=<?= filemtime(sw_root() . '/_assets/sw.js') ?>"></script>
</body>
</html>
