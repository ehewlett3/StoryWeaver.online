<?php
/**
 * StoryWeaver — Play handler (story creation controller).
 *
 * Non-rendering PHP controller. Receives POST requests to create
 * new stories or continue from choices. In Phase 2 (no AI), always
 * redirects to edit.php for manual content creation.
 */

require_once __DIR__ . '/_lib/auth_check.php';
require_once __DIR__ . '/_lib/nodes.php';
require_once __DIR__ . '/_lib/api_keys.php';
require_once __DIR__ . '/_lib/AIProvider.php';
require_once __DIR__ . '/_lib/context.php';

// Must be POST
if (!is_post()) {
    redirect(app_url('index'));
}

csrf_check();

$action = $_POST['action'] ?? '';

// Route to handler
switch ($action) {
    case 'new_story':
        handle_new_story();
        break;
    default:
        handle_continue_choice();
        break;
}

/* ======================================================================
 * ACTION HANDLERS
 * ====================================================================*/

/**
 * Build an AI provider client using the active user's generation settings.
 */
function play_ai_provider(array $key_record, ?array $user = null): AIProvider
{
    return new AIProvider($key_record, ai_generation_options_for_user($user));
}

/**
 * Create a new story with a root node.
 *
 * Expects POST fields: title (required), story_opening/scenario_essentials (optional), use_ai (optional).
 * If AI is available and not explicitly skipped, generates opening via AI.
 * Otherwise creates a blank root node and redirects to edit.php.
 */
function handle_new_story(): void
{
    $title    = normalize_story_title((string) ($_POST['title'] ?? ''));
    $story_opening = (string) ($_POST['story_opening'] ?? '');
    $scenario = normalize_scenario_essentials((string) ($_POST['scenario_essentials'] ?? ''));
    $use_ai   = ($_POST['use_ai'] ?? '1') === '1';
    $story_visibility = (string) ($_POST['story_visibility'] ?? 'public');
    $auto_generate_images = ($_POST['auto_generate_images'] ?? '0') === '1';
    $image_key_id = trim((string) ($_POST['image_key_id'] ?? ''));

    if ($title === '') {
        flash('error', 'A story title is required.');
        redirect(app_url('index'));
    }

    $user = current_user();
    $author_id = $user ? $user['id'] : 'anonymous';
    $user_id   = $user ? $user['id'] : null;
    $story_visibility = ($user && $story_visibility === 'private') ? 'private' : 'public';
    $auto_generate_images = $user !== null && $auto_generate_images;
    $image_key_id = validate_id($image_key_id, 'key_') ? $image_key_id : '';
    $opening_paragraphs = normalize_story_opening_paragraphs($story_opening);

    // Try AI generation if requested and key available
    if ($use_ai) {
        $key_id = trim($_POST['key_id'] ?? '');
        if ($key_id !== '' && validate_id($key_id, 'key_')) {
            $key_record = api_key_find_by_id($key_id);
            if ($key_record === null || $key_record['status'] !== 'active') {
                $key_record = null;
            } elseif (($key_error = api_key_access_error($key_record, $user)) !== null) {
                flash('error', $key_error);
                redirect(app_url('index'));
            }
        } else {
            $key_record = api_key_select_for_user($user_id);
        }
        if ($key_record !== null) {
            try {
                $key_record = api_key_prepare_for_use($key_record);
                sw_close_session();
                $prompt_bundle = build_opening_prompt_bundle($title, $scenario, $user, $story_opening);

                $provider = play_ai_provider($key_record, $user);
                $raw_response = $provider->generateText($prompt_bundle['system_prompt'], $prompt_bundle['story_context']);
                $parsed = parse_ai_response($raw_response);

                if ($parsed === null) {
                    // Retry with repair prompt
                    $repair_msg = build_repair_prompt($raw_response);
                    $raw_response = $provider->generateText($prompt_bundle['system_prompt'], $repair_msg);
                    $parsed = parse_ai_response($raw_response);
                }

                if ($parsed !== null) {
                    $paragraphs = [];
                    foreach ($parsed['paragraphs'] as $p) {
                        $paragraphs[] = sanitize_paragraph_html($p);
                    }
                    if (empty($paragraphs)) {
                        $paragraphs = ['The story begins…'];
                    }
                    if (!empty($opening_paragraphs)) {
                        $paragraphs = array_merge($opening_paragraphs, $paragraphs);
                    }

                    $result = story_create(h($title), $author_id, $paragraphs, [
                        'ai_model'             => $key_record['model_text'] ?? '',
                        'ai_provider'          => $key_record['provider'] ?? '',
                        'ai_key_label'         => $key_record['label'] ?? '',
                        'scenario_essentials'  => $prompt_bundle['scenario_essentials'],
                        'visibility'           => $story_visibility,
                        'shared_user_ids'      => [],
                        'auto_generate_images' => $auto_generate_images,
                        'auto_image_key_id'    => $auto_generate_images ? $image_key_id : '',
                    ]);

                    // Update with AI choices
                    if (!empty($parsed['choices'])) {
                        node_update_choices($result['story_id'], $result['node_id'], $parsed['choices']);
                    }
                    $redirect_url = $auto_generate_images
                        ? app_url('node', ['story' => $result['story_id'], 'id' => $result['node_id'], 'auto_image' => '1'])
                        : node_url($result['story_id'], $result['node_id']);

                    flash('success', 'Story created with AI! Review and edit as you wish.');
                    redirect($redirect_url);
                }
            } catch (RuntimeException $e) {
                // AI failed — fall through to manual creation
                if (str_contains($e->getMessage(), 'Authentication failed')) {
                    api_key_mark_unavailable($key_record['id'], $e->getMessage());
                }
                flash('error', 'AI generation failed: ' . $e->getMessage());
            }
        }
    }

    // Manual fallback: create blank story
    $result = story_create(h($title), $author_id, $opening_paragraphs, [
        'scenario_essentials'  => $scenario,
        'visibility'           => $story_visibility,
        'shared_user_ids'      => [],
        'auto_generate_images' => $auto_generate_images,
        'auto_image_key_id'    => $auto_generate_images ? $image_key_id : '',
    ]);

    // Guests can't edit, so redirect to node view; logged-in users go to editor
    if ($user) {
        flash('success', 'Story created! Write your opening below.');
        redirect(edit_url($result['story_id'], $result['node_id']));
    } else {
        flash('success', 'Story created! Log in to edit, or continue with AI.');
        redirect(node_url($result['story_id'], $result['node_id']));
    }
}

/**
 * Continue from a choice on an existing node.
 *
 * Expects POST fields: story_id, parent_node_id, and either:
 *   - choice (text of a pre-defined choice that was clicked)
 *   - custom_choice (user-typed custom action text)
 *
 * If AI is available, generates the next node via AI and redirects to node.php.
 * Otherwise creates a blank child node and redirects to edit.php.
 */
function handle_continue_choice(): void
{
    $story_id       = trim($_POST['story_id'] ?? '');
    $parent_node_id = trim($_POST['parent_node_id'] ?? '');
    $choice_text    = trim($_POST['choice'] ?? '');
    $custom_choice  = trim($_POST['custom_choice'] ?? '');
    $use_ai         = ($_POST['use_ai'] ?? '1') === '1';
    $key_id         = trim((string) ($_POST['key_id'] ?? ''));

    // Use custom choice if provided, otherwise use the clicked choice
    $chosen = $custom_choice !== '' ? $custom_choice : $choice_text;

    if ($story_id === '' || $parent_node_id === '') {
        flash('error', 'Missing story or parent page information.');
        redirect(app_url('index'));
    }

    if (!validate_id($story_id, 'story_') || !validate_id($parent_node_id, 'node_')) {
        flash('error', 'Invalid story or page ID.');        redirect(app_url('index'));
    }

    if ($chosen === '') {
        flash('error', 'Please select or type a choice to continue.');
        redirect(node_url($story_id, $parent_node_id));
    }

    // Verify parent node exists, including quarantined stories the author may still access
    $user = current_user();
    $parent = node_read_for_user($story_id, $parent_node_id, $user);
    if ($parent === null) {
        flash('error', 'Parent page not found.');
        redirect(app_url('index'));
    }
    if (!story_user_can_continue_story($story_id, $user)) {
        flash('error', 'Only admins can continue the announcements archive.');
        redirect(node_url($story_id, $parent_node_id));
    }
    $check_q = ($parent['location'] ?? 'stories') === 'quarantine';

    $author_id = $user ? $user['id'] : 'anonymous';
    $user_id   = $user ? $user['id'] : null;
    $title = story_get_title($story_id);
    $child_location = ($parent['location'] ?? 'stories') === 'quarantine' ? 'quarantine' : 'stories';

    // Try AI generation
    if ($use_ai) {
        if ($key_id !== '' && validate_id($key_id, 'key_')) {
            $key_record = api_key_find_by_id($key_id);
            if ($key_record === null || $key_record['status'] !== 'active') {
                $key_record = null;
            } elseif (($key_error = api_key_access_error($key_record, $user)) !== null) {
                flash('error', $key_error);
                redirect(node_url($story_id, $parent_node_id));
            }
        } else {
            $key_record = api_key_select_for_user($user_id);
        }
    } else {
        $key_record = null;
    }

    if ($key_record !== null) {
        try {
            $key_record = api_key_prepare_for_use($key_record);
            sw_close_session();
            $prompt_bundle = build_continuation_prompt_bundle($story_id, $parent_node_id, $chosen, $check_q, $user, $key_record);

            $provider = play_ai_provider($key_record, $user);
            $raw_response = $provider->generateText($prompt_bundle['system_prompt'], $prompt_bundle['story_context']);
            $parsed = parse_ai_response($raw_response);

            if ($parsed === null) {
                $repair_msg = build_repair_prompt($raw_response);
                $raw_response = $provider->generateText($prompt_bundle['system_prompt'], $repair_msg);
                $parsed = parse_ai_response($raw_response);
            }

            if ($parsed !== null) {
                $paragraphs = [];
                foreach ($parsed['paragraphs'] as $p) {
                    $paragraphs[] = sanitize_paragraph_html($p);
                }
                if (empty($paragraphs)) {
                    $paragraphs = ['The story continues…'];
                }

                $child_node_id = node_create($story_id, [
                    'parent_id'    => $parent_node_id,
                    'choice_taken' => h($chosen),
                    'author_id'    => $author_id,
                    'title'        => $title,
                    'paragraphs'   => $paragraphs,
                    'choices'      => $parsed['choices'] ?? [],
                    'ai_model'     => $key_record['model_text'] ?? '',
                    'ai_provider'  => $key_record['provider'] ?? '',
                    'ai_key_label' => $key_record['label'] ?? '',
                    'location'     => $child_location,
                ]);

                node_link_choice($story_id, $parent_node_id, $chosen, $child_node_id);
                $redirect_url = story_auto_images_enabled($story_id)
                    ? app_url('node', ['story' => $story_id, 'id' => $child_node_id, 'auto_image' => '1'])
                    : node_url($story_id, $child_node_id);

                flash('success', 'The story continues! Edit if you like.');
                redirect($redirect_url);
            }
        } catch (RuntimeException $e) {
            // AI failed — fall through to manual
            if (str_contains($e->getMessage(), 'Authentication failed')) {
                api_key_mark_unavailable($key_record['id'], $e->getMessage());
            }
            flash('error', 'AI generation failed: ' . $e->getMessage());
        }
    }

    // Manual fallback: create blank child node
    $child_node_id = node_create($story_id, [
        'parent_id'    => $parent_node_id,
        'choice_taken' => h($chosen),
        'author_id'    => $author_id,
        'title'        => $title,
        'paragraphs'   => [],
        'choices'      => [],
        'location'     => $child_location,
    ]);

    // Update parent's choices to link to the new child
    node_link_choice($story_id, $parent_node_id, $chosen, $child_node_id);

    // Guests can't edit, redirect to node view; logged-in users go to editor
    if ($user) {
        flash('success', 'Continue the story from here!');
        redirect(edit_url($story_id, $child_node_id));
    } else {
        flash('success', 'Page created! Log in to edit, or continue with AI.');
        redirect(node_url($story_id, $child_node_id));
    }
}
