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
    redirect(base_url() . '/index.php');
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
 * Create a new story with a root node.
 *
 * Expects POST fields: title (required), scenario_essentials (optional), use_ai (optional).
 * If AI is available and not explicitly skipped, generates opening via AI.
 * Otherwise creates a blank root node and redirects to edit.php.
 */
function handle_new_story(): void
{
    $title    = trim($_POST['title'] ?? '');
    $scenario = normalize_scenario_essentials((string) ($_POST['scenario_essentials'] ?? ''));
    $use_ai   = ($_POST['use_ai'] ?? '1') === '1';

    if ($title === '') {
        flash('error', 'A story title is required.');
        redirect(base_url() . '/index.php');
    }

    if (strlen($title) > 200) {
        $title = substr($title, 0, 200);
    }

    $user = current_user();
    $author_id = $user ? $user['id'] : 'anonymous';
    $user_id   = $user ? $user['id'] : null;

    // Try AI generation if requested and key available
    if ($use_ai) {
        $key_id = trim($_POST['key_id'] ?? '');
        if ($key_id !== '' && validate_id($key_id, 'key_')) {
            $key_record = api_key_find_by_id($key_id);
            if ($key_record === null || $key_record['status'] !== 'active') {
                $key_record = null;
            } elseif (($key_error = api_key_access_error($key_record, $user)) !== null) {
                flash('error', $key_error);
                redirect(base_url() . '/index.php');
            }
        } else {
            $key_record = api_key_select_for_user($user_id);
        }
        if ($key_record !== null) {
            try {
                $key_record = api_key_prepare_for_use($key_record);
                $prompt_bundle = build_opening_prompt_bundle($title, $scenario);

                $provider = new AIProvider($key_record);
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

                    $result = story_create(h($title), $author_id, $paragraphs, [
                        'ai_model'            => $key_record['model_text'] ?? '',
                        'ai_provider'         => $key_record['provider'] ?? '',
                        'ai_key_label'        => $key_record['label'] ?? '',
                        'scenario_essentials' => $prompt_bundle['scenario_essentials'],
                    ]);

                    // Update with AI choices
                    if (!empty($parsed['choices'])) {
                        node_update_choices($result['story_id'], $result['node_id'], $parsed['choices']);
                    }

                    flash('success', 'Story created with AI! Review and edit as you wish.');
                    redirect(base_url() . '/node.php?story=' . urlencode($result['story_id'])
                           . '&id=' . urlencode($result['node_id']));
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
    $result = story_create(h($title), $author_id, [], [
        'scenario_essentials' => $scenario,
    ]);

    // Guests can't edit, so redirect to node view; logged-in users go to editor
    if ($user) {
        flash('success', 'Story created! Write your opening below.');
        redirect(base_url() . '/edit.php?story=' . urlencode($result['story_id'])
               . '&id=' . urlencode($result['node_id']));
    } else {
        flash('success', 'Story created! Log in to edit, or continue with AI.');
        redirect(base_url() . '/node.php?story=' . urlencode($result['story_id'])
               . '&id=' . urlencode($result['node_id']));
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

    // Use custom choice if provided, otherwise use the clicked choice
    $chosen = $custom_choice !== '' ? $custom_choice : $choice_text;

    if ($story_id === '' || $parent_node_id === '') {
        flash('error', 'Missing story or parent page information.');
        redirect(base_url() . '/index.php');
    }

    if (!validate_id($story_id, 'story_') || !validate_id($parent_node_id, 'node_')) {
        flash('error', 'Invalid story or page ID.');        redirect(base_url() . '/index.php');
    }

    if ($chosen === '') {
        flash('error', 'Please select or type a choice to continue.');
        redirect(base_url() . '/node.php?story=' . urlencode($story_id)
               . '&id=' . urlencode($parent_node_id));
    }

    // Verify parent node exists, including quarantined stories the author may still access
    $user = current_user();
    $parent = node_read_for_user($story_id, $parent_node_id, $user);
    if ($parent === null) {
        flash('error', 'Parent page not found.');
        redirect(base_url() . '/index.php');
    }
    $check_q = ($parent['location'] ?? 'stories') === 'quarantine';

    $author_id = $user ? $user['id'] : 'anonymous';
    $user_id   = $user ? $user['id'] : null;
    $title = story_get_title($story_id);
    $child_location = ($parent['location'] ?? 'stories') === 'quarantine' ? 'quarantine' : 'stories';

    // Try AI generation
    $key_record = api_key_select_for_user($user_id);
    if ($key_record !== null) {
        try {
            $key_record = api_key_prepare_for_use($key_record);
            $prompt_bundle = build_continuation_prompt_bundle($story_id, $parent_node_id, $chosen, $check_q);

            $provider = new AIProvider($key_record);
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

                flash('success', 'The story continues! Edit if you like.');
                redirect(base_url() . '/node.php?story=' . urlencode($story_id)
                       . '&id=' . urlencode($child_node_id));
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
        redirect(base_url() . '/edit.php?story=' . urlencode($story_id)
               . '&id=' . urlencode($child_node_id));
    } else {
        flash('success', 'Page created! Log in to edit, or continue with AI.');
        redirect(base_url() . '/node.php?story=' . urlencode($story_id)
               . '&id=' . urlencode($child_node_id));
    }
}
