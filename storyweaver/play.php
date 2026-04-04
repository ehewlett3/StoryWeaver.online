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
    $scenario = trim($_POST['scenario_essentials'] ?? '');
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
        $key_record = api_key_select_for_user($user_id);
        if ($key_record !== null) {
            try {
                $system_prompt = get_system_prompt($scenario);
                $user_message  = build_opening_prompt($title, $scenario);

                $provider = new AIProvider($key_record);
                $raw_response = $provider->generateText($system_prompt, $user_message);
                $parsed = parse_ai_response($raw_response);

                if ($parsed === null) {
                    // Retry with repair prompt
                    $repair_msg = build_repair_prompt($raw_response);
                    $raw_response = $provider->generateText($system_prompt, $repair_msg);
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

                    $result = story_create(h($title), $author_id, $paragraphs);

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
            }
        }
    }

    // Manual fallback: create blank story
    $result = story_create(h($title), $author_id, []);

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

    // Verify parent node exists (check quarantine for editors)
    $user = current_user();
    $check_q = $user && role_level($user['role']) >= role_level('editor');
    $parent = node_read($story_id, $parent_node_id, $check_q);
    if ($parent === null) {
        flash('error', 'Parent page not found.');
        redirect(base_url() . '/index.php');
    }

    $author_id = $user ? $user['id'] : 'anonymous';
    $user_id   = $user ? $user['id'] : null;
    $title = story_get_title($story_id);

    // Try AI generation
    $key_record = api_key_select_for_user($user_id);
    if ($key_record !== null) {
        try {
            $entries = reconstruct_context($story_id, $parent_node_id);
            $entries = truncate_context($entries);
            $system_prompt = get_system_prompt();
            $user_message  = build_story_prompt($entries, $chosen);

            $provider = new AIProvider($key_record);
            $raw_response = $provider->generateText($system_prompt, $user_message);
            $parsed = parse_ai_response($raw_response);

            if ($parsed === null) {
                $repair_msg = build_repair_prompt($raw_response);
                $raw_response = $provider->generateText($system_prompt, $repair_msg);
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
