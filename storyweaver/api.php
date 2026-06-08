<?php
/**
 * StoryWeaver — Internal AJAX endpoint.
 *
 * All actions are POST with JSON or form body.
 * Returns JSON: {"ok": true, ...} or {"ok": false, "error": "..."}.
 */

require_once __DIR__ . '/_lib/auth_check.php';
require_once __DIR__ . '/_lib/nodes.php';
require_once __DIR__ . '/_lib/api_keys.php';
require_once __DIR__ . '/_lib/AIProvider.php';
require_once __DIR__ . '/_lib/context.php';
require_once __DIR__ . '/_lib/moderation.php';

// Get the action
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Set JSON content type for standard API responses
if (!in_array($action, ['save_theme_css', 'stream_generate_node', 'stream_regenerate_node'], true)) {
    header('Content-Type: application/json; charset=UTF-8');
}

// Must have users set up
if (!users_exists()) {
    json_error('Application not set up.', 500);
}

// Route to handler
switch ($action) {
    case 'save_node_text':
        handle_save_node_text();
        break;
    case 'update_pending_choice':
        handle_update_pending_choice();
        break;
    case 'delete_pending_choice':
        handle_delete_pending_choice();
        break;
    case 'generate_node':
        handle_generate_node();
        break;
    case 'stream_generate_node':
        handle_stream_generate_node();
        break;
    case 'stream_regenerate_node':
        handle_stream_regenerate_node();
        break;
    case 'abort_generation':
        handle_abort_generation();
        break;
    case 'regenerate_node':
        handle_regenerate_node();
        break;
    case 'regenerate_pending_choices':
        handle_regenerate_pending_choices();
        break;
    case 'apply_regenerated_node':
        handle_apply_regenerated_node();
        break;
    case 'test_api_key':
        handle_test_api_key();
        break;
    case 'save_api_key':
        handle_save_api_key();
        break;
    case 'update_api_key':
        handle_update_api_key();
        break;
    case 'list_api_models':
        handle_list_api_models();
        break;
    case 'deactivate_api_key':
        handle_deactivate_api_key();
        break;
    case 'reactivate_api_key':
        handle_reactivate_api_key();
        break;
    case 'delete_api_key':
        handle_delete_api_key();
        break;
    case 'set_default_public_api_key':
        handle_set_default_public_api_key();
        break;
    case 'generate_image':
        handle_generate_image();
        break;
    case 'set_story_image_settings':
        handle_set_story_image_settings();
        break;
    case 'flag_concern':
        handle_flag_concern();
        break;
    case 'flag_review':
        handle_flag_review();
        break;
    case 'approve_node':
        handle_approve_node();
        break;
    case 'delete_node':
        handle_delete_node();
        break;
    case 'delete_final_page':
        handle_delete_final_page();
        break;
    case 'delete_story':
        handle_delete_story();
        break;
    case 'dismiss_concern':
        handle_dismiss_concern();
        break;
    case 'change_role':
        handle_change_role();
        break;
    case 'delete_user':
        handle_delete_user();
        break;
    case 'apply_theme':
        handle_apply_theme();
        break;
    case 'save_theme_css':
        handle_save_theme_css();
        break;
    case 'set_story_theme':
        handle_set_story_theme();
        break;
    case 'rename_story':
        handle_rename_story();
        break;
    case 'update_story_scenario':
        handle_update_story_scenario();
        break;
    case 'set_story_visibility':
        handle_set_story_visibility();
        break;
    case 'grant_story_access':
        handle_grant_story_access();
        break;
    case 'revoke_story_access':
        handle_revoke_story_access();
        break;
    case 'delete_image':
        handle_delete_image();
        break;
    case 'upload_image':
        handle_upload_image();
        break;
    case 'preview_prompt':
        handle_preview_prompt();
        break;
    default:
        json_error('Unknown action.', 400);
}

/* ======================================================================
 * ACTION HANDLERS
 * ====================================================================*/

/**
 * Build an AI provider client using the active user's generation settings.
 */
function api_ai_provider(array $key_record, ?array $user = null): AIProvider
{
    return new AIProvider($key_record, ai_generation_options_for_user($user));
}

/**
 * Save updated paragraph text for a node.
 *
 * Expects POST JSON body: { story_id, node_id, paragraphs: [string, ...] }
 * Requires contributor+ (own nodes) or editor+ (any node).
 */
function handle_save_node_text(): void
{
    $user = current_user();
    if ($user === null) {
        json_error('Authentication required.', 401);
    }

    // Parse JSON body
    $input = get_json_input();

    $story_id   = $input['story_id'] ?? '';
    $node_id    = $input['node_id'] ?? '';
    $paragraphs = $input['paragraphs'] ?? [];
    $csrf       = $input['_csrf_token'] ?? '';

    // CSRF check (manual since this is JSON, not form POST)
    if (!hash_equals(csrf_token(), $csrf)) {
        json_error('Invalid CSRF token.', 403);
    }

    // Validate inputs
    if ($story_id === '' || $node_id === ''
        || !validate_id($story_id, 'story_') || !validate_id($node_id, 'node_')) {
        json_error('Invalid story_id or node_id.', 400);
    }

    if (!is_array($paragraphs)) {
        json_error('Paragraphs must be an array.', 400);
    }

    // Read the existing node, including quarantined stories the author may still access
    $node = node_read_for_user($story_id, $node_id, $user);
    if ($node === null) {
        json_error('Node not found.', 404);
    }

    if (!story_user_can_edit_node($story_id, $node, $user)) {
        json_error('You do not have permission to edit this node.', 403);
    }

    // Sanitize each paragraph
    $clean = [];
    foreach ($paragraphs as $p) {
        if (!is_string($p)) continue;
        $sanitized = sanitize_paragraph_html($p);
        if ($sanitized !== '') {
            $clean[] = $sanitized;
        }
    }

    if (empty($clean)) {
        json_error('At least one paragraph is required.', 400);
    }

    // Update the node
    $ok = node_update_paragraphs($story_id, $node_id, $clean);
    if (!$ok) {
        json_error('Failed to save node text.', 500);
    }

    $pending_choice_count = node_pending_choice_count($node);
    $sw_meta = is_array($node['sw_meta'] ?? null) ? $node['sw_meta'] : [];
    if ($pending_choice_count > 0) {
        $sw_meta['pending_choices_need_review'] = true;
    } else {
        unset($sw_meta['pending_choices_need_review']);
    }
    $sw_meta = node_meta_append_history($sw_meta, $user['id'], 'edited');
    node_update_meta($story_id, $node_id, $sw_meta);

    json_success([
        'message' => 'Node text saved.',
        'review_pending_choices' => ($pending_choice_count > 0),
        'pending_choice_count' => $pending_choice_count,
    ]);
}

/**
 * Update the text of a single pending choice.
 *
 * Expects POST JSON body: { story_id, node_id, choice_id, choice_text, _csrf_token }
 */
function handle_update_pending_choice(): void
{
    $user = current_user();
    if ($user === null) {
        json_error('Authentication required.', 401);
    }

    $input = get_json_input();
    csrf_check($input['_csrf_token'] ?? '');

    $story_id = trim((string) ($input['story_id'] ?? ''));
    $node_id = trim((string) ($input['node_id'] ?? ''));
    $choice_id = (int) ($input['choice_id'] ?? 0);
    $choice_text = normalize_choice_text((string) ($input['choice_text'] ?? ''));

    if (!validate_id($story_id, 'story_') || !validate_id($node_id, 'node_') || $choice_id < 1) {
        json_error('Invalid story, page, or choice ID.', 400);
    }

    if ($choice_text === '') {
        json_error('Choice text is required.', 400);
    }

    $node = node_read_for_user($story_id, $node_id, $user);
    if ($node === null) {
        json_error('Node not found.', 404);
    }

    if (!story_user_can_edit_node($story_id, $node, $user)) {
        json_error('You do not have permission to edit this page.', 403);
    }

    if (!node_update_pending_choice_text($story_id, $node_id, $choice_id, $choice_text)) {
        json_error('Pending choice not found.', 404);
    }

    $sw_meta = is_array($node['sw_meta'] ?? null) ? $node['sw_meta'] : [];
    unset($sw_meta['pending_choices_need_review']);
    $sw_meta = node_meta_append_history($sw_meta, $user['id'], 'pending_choice_edited');
    node_update_meta($story_id, $node_id, $sw_meta);

    json_success(['message' => 'Pending choice updated.']);
}

/**
 * Delete a single pending choice.
 *
 * Expects POST JSON body: { story_id, node_id, choice_id, _csrf_token }
 */
function handle_delete_pending_choice(): void
{
    $user = current_user();
    if ($user === null) {
        json_error('Authentication required.', 401);
    }

    $input = get_json_input();
    csrf_check($input['_csrf_token'] ?? '');

    $story_id = trim((string) ($input['story_id'] ?? ''));
    $node_id = trim((string) ($input['node_id'] ?? ''));
    $choice_id = (int) ($input['choice_id'] ?? 0);

    if (!validate_id($story_id, 'story_') || !validate_id($node_id, 'node_') || $choice_id < 1) {
        json_error('Invalid story, page, or choice ID.', 400);
    }

    $node = node_read_for_user($story_id, $node_id, $user);
    if ($node === null) {
        json_error('Node not found.', 404);
    }

    if (!story_user_can_edit_node($story_id, $node, $user)) {
        json_error('You do not have permission to edit this page.', 403);
    }

    if (!node_delete_pending_choice($story_id, $node_id, $choice_id)) {
        json_error('Pending choice not found.', 404);
    }

    $sw_meta = is_array($node['sw_meta'] ?? null) ? $node['sw_meta'] : [];
    unset($sw_meta['pending_choices_need_review']);
    $sw_meta = node_meta_append_history($sw_meta, $user['id'], 'pending_choice_deleted');
    node_update_meta($story_id, $node_id, $sw_meta);

    json_success(['message' => 'Pending choice deleted.']);
}

/**
 * Generate a new story node using AI.
 *
 * Expects POST JSON: { story_id, parent_node_id, choice_text, scenario_essentials?, _csrf_token }
 * Reconstructs context, calls AI, parses response, creates node, returns node URL.
 */
function handle_generate_node(): void
{
    $input = get_json_input();
    $csrf  = $input['_csrf_token'] ?? '';

    if (!hash_equals(csrf_token(), $csrf)) {
        json_error('Invalid CSRF token.', 403);
    }

    $story_id       = $input['story_id'] ?? '';
    $parent_node_id = $input['parent_node_id'] ?? '';
    $choice_text    = trim($input['choice_text'] ?? '');
    $story_opening  = (string) ($input['story_opening'] ?? '');
    $scenario       = normalize_scenario_essentials((string) ($input['scenario_essentials'] ?? ''));
    $title          = normalize_story_title((string) ($input['title'] ?? ''));
    $key_id         = trim($input['key_id'] ?? '');
    $image_key_id   = trim((string) ($input['image_key_id'] ?? ''));
    $story_visibility = (string) ($input['story_visibility'] ?? 'public');
    $auto_generate_images = !empty($input['auto_generate_images']);

    // Determine the user for key selection
    $user = current_user();
    $user_id = $user ? $user['id'] : null;
    $author_id = $user ? $user['id'] : 'anonymous';
    $story_visibility = ($user && $story_visibility === 'private') ? 'private' : 'public';
    $auto_generate_images = $user !== null && $auto_generate_images;
    $image_key_id = validate_id($image_key_id, 'key_') ? $image_key_id : '';
    $opening_paragraphs = normalize_story_opening_paragraphs($story_opening);

    // Select API key (optionally by specific key_id)
    if ($key_id !== '' && validate_id($key_id, 'key_')) {
        $key_record = api_key_find_by_id($key_id);
        if ($key_record === null || $key_record['status'] !== 'active') {
            $key_record = null;
        } elseif (($key_error = api_key_access_error($key_record, $user)) !== null) {
            json_error($key_error, 403);
        }
    } else {
        $key_record = api_key_select_for_user($user_id);
    }
    if ($key_record === null) {
        json_error('No AI key available. Add one in Settings or write manually.', 400);
    }
    try {
        $key_record = api_key_prepare_for_use($key_record);
    } catch (RuntimeException $e) {
        json_error($e->getMessage(), 500);
    }

    // Is this a new story opening or a continuation?
    $is_opening = ($parent_node_id === '' && $title !== '');

    if (!$is_opening) {
        // Continuation: validate IDs
        if (!validate_id($story_id, 'story_') || !validate_id($parent_node_id, 'node_')) {
            json_error('Invalid story or page ID.', 400);
        }
        if ($choice_text === '') {
            json_error('Choice text is required.', 400);
        }

        $parent = node_read_for_user($story_id, $parent_node_id, $user);
        if ($parent === null) {
            json_error('Parent page not found.', 404);
        }
        if (!story_user_can_continue_story($story_id, $user)) {
            json_error('Only admins can continue the announcements archive.', 403);
        }
        $check_quarantine = (($parent['location'] ?? 'stories') === 'quarantine');
    } else {
        $check_quarantine = false;
        $parent = null;
    }

    sw_close_session();

    $prompt_bundle = $is_opening
        ? build_opening_prompt_bundle($title, $scenario, $user, $story_opening)
        : build_continuation_prompt_bundle($story_id, $parent_node_id, $choice_text, $check_quarantine, $user, $key_record);
    $system_prompt = $prompt_bundle['system_prompt'];
    $story_context = $prompt_bundle['story_context'];

    // Call AI with retry on parse failure; fall back to fallback_key_id on connection errors
    $active_key = $key_record;
    $provider   = api_ai_provider($active_key, $user);
    $parsed     = null;
    $last_error = '';
    $raw_response = '';

    for ($attempt = 0; $attempt < 2; $attempt++) {
        try {
            if ($attempt === 0) {
                $raw_response = $provider->generateText($system_prompt, $story_context);
            } else {
                $repair_msg = build_repair_prompt($raw_response);
                $raw_response = $provider->generateText($system_prompt, $repair_msg);
            }

            $parsed = parse_ai_response($raw_response);
            if ($parsed !== null) {
                break;
            }
            $last_error = 'AI response was not valid JSON.';
        } catch (RuntimeException $e) {
            $last_error = $e->getMessage();

            // Auth failures: mark unavailable immediately, no fallback
            if (str_contains($last_error, 'Authentication failed')) {
                api_key_mark_unavailable($active_key['id'], $last_error);
                json_error('API key authentication failed and has been deactivated. ' . $last_error, 401);
            }
            break;
        }
    }

    // On connection error, try the fallback key (primary stays active — outage may be transient)
    if ($parsed === null && api_key_is_connection_error($last_error)) {
        $fallback = api_key_get_fallback($key_record);
        if ($fallback !== null) {
            try {
                $active_key = api_key_prepare_for_use($fallback);
            } catch (RuntimeException $e) {
                json_error($e->getMessage(), 500);
            }
            $prompt_bundle = $is_opening
                ? build_opening_prompt_bundle($title, $scenario, $user, $story_opening)
                : build_continuation_prompt_bundle($story_id, $parent_node_id, $choice_text, $check_quarantine, $user, $active_key);
            $system_prompt = $prompt_bundle['system_prompt'];
            $story_context = $prompt_bundle['story_context'];
            $provider     = api_ai_provider($active_key, $user);
            $last_error   = '';
            $raw_response = '';

            for ($attempt = 0; $attempt < 2; $attempt++) {
                try {
                    if ($attempt === 0) {
                        $raw_response = $provider->generateText($system_prompt, $story_context);
                    } else {
                        $repair_msg = build_repair_prompt($raw_response);
                        $raw_response = $provider->generateText($system_prompt, $repair_msg);
                    }

                    $parsed = parse_ai_response($raw_response);
                    if ($parsed !== null) {
                        break;
                    }
                    $last_error = 'AI response was not valid JSON.';
                } catch (RuntimeException $e) {
                    $last_error = $e->getMessage();

                    if (str_contains($last_error, 'Authentication failed')) {
                        api_key_mark_unavailable($active_key['id'], $last_error);
                        json_error('API key authentication failed and has been deactivated. ' . $last_error, 401);
                    }
                    break;
                }
            }
        }
    }

    if ($parsed === null) {
        api_key_mark_unavailable($active_key['id'], $last_error);
        json_error('AI generation failed: ' . $last_error, 502);
    }

    // Sanitize paragraphs
    $paragraphs = [];
    foreach ($parsed['paragraphs'] as $p) {
        $paragraphs[] = sanitize_paragraph_html($p);
    }
    if (empty($paragraphs)) {
        $paragraphs = [$is_opening ? 'The story begins…' : 'The story continues…'];
    }
    if ($is_opening && !empty($opening_paragraphs)) {
        $paragraphs = array_merge($opening_paragraphs, $paragraphs);
    }

    // Build choices (all pending — node is null)
    $choices = $parsed['choices'] ?? [];

    if ($is_opening) {
        // Create new story + root node
        $result = story_create(h($title), $author_id, $paragraphs, [
            'ai_model'             => $active_key['model_text'] ?? '',
            'ai_provider'          => $active_key['provider'] ?? '',
            'ai_key_label'         => $active_key['label'] ?? '',
            'scenario_essentials'  => $prompt_bundle['scenario_essentials'],
            'visibility'           => $story_visibility,
            'shared_user_ids'      => [],
            'auto_generate_images' => $auto_generate_images,
            'auto_image_key_id'    => $auto_generate_images ? $image_key_id : '',
        ]);
        $story_id = $result['story_id'];
        $node_id  = $result['node_id'];

        // Update with AI-generated choices
        if (!empty($choices)) {
            node_update_choices($story_id, $node_id, $choices);
        }
    } else {
        // Create child node
        $node_id = node_create($story_id, [
            'parent_id'    => $parent_node_id,
            'choice_taken' => h($choice_text),
            'author_id'    => $author_id,
            'title'        => story_get_title($story_id),
            'paragraphs'   => $paragraphs,
            'choices'      => $choices,
            'ai_model'     => $active_key['model_text'] ?? '',
            'ai_provider'  => $active_key['provider'] ?? '',
            'ai_key_label' => $active_key['label'] ?? '',
            'location'     => (($parent['location'] ?? 'stories') === 'quarantine' ? 'quarantine' : 'stories'),
        ]);

        // Link parent's choice to the new child
        node_link_choice($story_id, $parent_node_id, $choice_text, $node_id);
    }

    $auto_image_enabled = $is_opening ? $auto_generate_images : story_auto_images_enabled($story_id);
    $done_url = node_url($story_id, $node_id);
    if ($auto_image_enabled) {
        $done_url = app_url('node', [
            'story' => $story_id,
            'id' => $node_id,
            'auto_image' => '1',
        ]);
    }

    $base = base_url();
    json_success([
        'story_id' => $story_id,
        'node_id'  => $node_id,
        'url'      => $done_url,
        'ending'   => $parsed['ending'] ?? false,
    ]);
}

/**
 * Generate a regenerated candidate for an existing node without applying it.
 *
 * Expects POST JSON: { story_id, node_id, key_id?, _csrf_token }
 */
function handle_regenerate_node(): void
{
    $user = current_user();
    if ($user === null) {
        json_error('Authentication required.', 401);
    }

    $input = get_json_input();
    csrf_check($input['_csrf_token'] ?? '');

    $story_id = trim($input['story_id'] ?? '');
    $node_id  = trim($input['node_id'] ?? '');
    $key_id   = trim($input['key_id'] ?? '');
    $steer_prompt = trim((string) ($input['steer_prompt'] ?? ''));

    if (!validate_id($story_id, 'story_') || !validate_id($node_id, 'node_')) {
        json_error('Invalid story or page ID.', 400);
    }

    $node = node_read_for_user($story_id, $node_id, $user);
    if ($node === null) {
        json_error('Story node not found.', 404);
    }

    if (!story_user_can_edit_node($story_id, $node, $user)) {
        json_error('You do not have permission to regenerate this page.', 403);
    }

    if (!node_can_regenerate($node)) {
        json_error('This page cannot be regenerated after child pages have been created.', 400);
    }

    if ($key_id !== '' && validate_id($key_id, 'key_')) {
        $key_record = api_key_find_by_id($key_id);
        if ($key_record === null || ($key_record['status'] ?? '') !== 'active') {
            $key_record = null;
        } elseif (($key_error = api_key_access_error($key_record, $user)) !== null) {
            json_error($key_error, 403);
        }
    } else {
        $key_record = api_key_select_for_user($user['id']);
    }

    if ($key_record === null) {
        json_error('No AI key available.', 400);
    }

    try {
        $key_record = api_key_prepare_for_use($key_record);
    } catch (RuntimeException $e) {
        json_error($e->getMessage(), 500);
    }

    $is_root = ($node['parent_id'] ?? '') === '';
    if ($is_root) {
        $root_scenario = trim((string) (($node['sw_meta']['scenario_essentials'] ?? '')));
        if ($root_scenario === '') {
            $root_scenario = story_get_scenario_essentials($story_id);
        }
        $prompt_bundle = build_opening_prompt_bundle($node['title'] ?? story_get_title($story_id), $root_scenario, $user);
    } else {
        $choice_taken = trim((string) ($node['choice_taken'] ?? ''));
        if ($choice_taken === '') {
            json_error('This page is missing the choice text needed for regeneration.', 400);
        }
        $prompt_bundle = build_continuation_prompt_bundle($story_id, $node['parent_id'], $choice_taken, true, $user, $key_record);
    }

    sw_close_session();

    $system_prompt = $prompt_bundle['system_prompt'];
    $story_context = append_story_regeneration_guidance($prompt_bundle['story_context'], $steer_prompt);
    $active_key = $key_record;
    $provider = api_ai_provider($active_key, $user);
    $parsed = null;
    $last_error = '';
    $raw_response = '';

    for ($attempt = 0; $attempt < 2; $attempt++) {
        try {
            if ($attempt === 0) {
                $raw_response = $provider->generateText($system_prompt, $story_context);
            } else {
                $repair_msg = build_repair_prompt($raw_response);
                $raw_response = $provider->generateText($system_prompt, $repair_msg);
            }

            $parsed = parse_ai_response($raw_response);
            if ($parsed !== null) {
                break;
            }
            $last_error = 'AI response was not valid JSON.';
        } catch (RuntimeException $e) {
            $last_error = $e->getMessage();
            if (str_contains($last_error, 'Authentication failed')) {
                api_key_mark_unavailable($active_key['id'], $last_error);
                json_error('API key authentication failed and has been deactivated. ' . $last_error, 401);
            }
            break;
        }
    }

    if ($parsed === null && api_key_is_connection_error($last_error)) {
        $fallback = api_key_get_fallback($key_record);
        if ($fallback !== null) {
            try {
                $active_key = api_key_prepare_for_use($fallback);
            } catch (RuntimeException $e) {
                json_error($e->getMessage(), 500);
            }
            if (!$is_root) {
                $prompt_bundle = build_continuation_prompt_bundle($story_id, $node['parent_id'], $choice_taken, true, $user, $active_key);
                $system_prompt = $prompt_bundle['system_prompt'];
                $story_context = append_story_regeneration_guidance($prompt_bundle['story_context'], $steer_prompt);
            }
            $provider = api_ai_provider($active_key, $user);
            $last_error = '';
            $raw_response = '';

            for ($attempt = 0; $attempt < 2; $attempt++) {
                try {
                    if ($attempt === 0) {
                        $raw_response = $provider->generateText($system_prompt, $story_context);
                    } else {
                        $repair_msg = build_repair_prompt($raw_response);
                        $raw_response = $provider->generateText($system_prompt, $repair_msg);
                    }

                    $parsed = parse_ai_response($raw_response);
                    if ($parsed !== null) {
                        break;
                    }
                    $last_error = 'AI response was not valid JSON.';
                } catch (RuntimeException $e) {
                    $last_error = $e->getMessage();
                    if (str_contains($last_error, 'Authentication failed')) {
                        api_key_mark_unavailable($active_key['id'], $last_error);
                        json_error('API key authentication failed and has been deactivated. ' . $last_error, 401);
                    }
                    break;
                }
            }
        }
    }

    if ($parsed === null) {
        api_key_mark_unavailable($active_key['id'], $last_error);
        json_error('AI generation failed: ' . $last_error, 502);
    }

    $paragraphs = [];
    foreach ($parsed['paragraphs'] as $paragraph) {
        $paragraphs[] = sanitize_paragraph_html($paragraph);
    }
    if (empty($paragraphs)) {
        $paragraphs = [$is_root ? 'The story begins…' : 'The story continues…'];
    }

    json_success([
        'story_id'            => $story_id,
        'node_id'             => $node_id,
        'current_paragraphs'  => $node['paragraphs'] ?? [],
        'current_choices'     => $node['choices'] ?? [],
        'paragraphs'          => $paragraphs,
        'choices'             => $parsed['choices'] ?? [],
        'ai_model'            => $active_key['model_text'] ?? '',
        'ai_provider'         => $active_key['provider'] ?? '',
        'ai_key_label'        => $active_key['label'] ?? '',
        'scenario_essentials' => $prompt_bundle['scenario_essentials'] ?? '',
        'ending'              => $parsed['ending'] ?? false,
    ]);
}

/**
 * Regenerate only the pending choices on an existing node.
 *
 * Expects POST JSON: { story_id, node_id, key_id?, steer_prompt?, _csrf_token }
 */
function handle_regenerate_pending_choices(): void
{
    $user = current_user();
    if ($user === null) {
        json_error('Authentication required.', 401);
    }

    $input = get_json_input();
    csrf_check($input['_csrf_token'] ?? '');

    $story_id = trim((string) ($input['story_id'] ?? ''));
    $node_id = trim((string) ($input['node_id'] ?? ''));
    $key_id = trim((string) ($input['key_id'] ?? ''));
    $steer_prompt = trim((string) ($input['steer_prompt'] ?? ''));

    if (!validate_id($story_id, 'story_') || !validate_id($node_id, 'node_')) {
        json_error('Invalid story or page ID.', 400);
    }

    $node = node_read_for_user($story_id, $node_id, $user);
    if ($node === null) {
        json_error('Story node not found.', 404);
    }

    if (!story_user_can_edit_node($story_id, $node, $user)) {
        json_error('You do not have permission to edit this page.', 403);
    }

    $pending_choices = node_pending_choices($node);
    $pending_choice_count = count($pending_choices);
    $target_choice_count = $pending_choice_count > 0 ? $pending_choice_count : 3;

    if ($key_id !== '' && validate_id($key_id, 'key_')) {
        $key_record = api_key_find_by_id($key_id);
        if ($key_record === null || ($key_record['status'] ?? '') !== 'active') {
            $key_record = null;
        } elseif (($key_error = api_key_access_error($key_record, $user)) !== null) {
            json_error($key_error, 403);
        }
    } else {
        $key_record = api_key_select_for_user($user['id']);
    }

    if ($key_record === null) {
        json_error('No AI key available.', 400);
    }

    try {
        $key_record = api_key_prepare_for_use($key_record);
    } catch (RuntimeException $e) {
        json_error($e->getMessage(), 500);
    }

    $locked_choices = [];
    foreach (($node['choices'] ?? []) as $choice) {
        if (!node_choice_is_pending($choice) && trim((string) ($choice['text'] ?? '')) !== '') {
            $locked_choices[] = trim((string) $choice['text']);
        }
    }

    sw_close_session();

    $prompt_bundle = build_pending_choices_prompt_bundle(
        $story_id,
        $node_id,
        $target_choice_count,
        $locked_choices,
        true,
        $user,
        $key_record
    );
    $system_prompt = $prompt_bundle['system_prompt'];
    $story_context = append_story_regeneration_guidance($prompt_bundle['story_context'], $steer_prompt);

    $active_key = $key_record;
    $provider = api_ai_provider($active_key, $user);
    $choices = null;
    $last_error = '';
    $raw_response = '';

    for ($attempt = 0; $attempt < 2; $attempt++) {
        try {
            if ($attempt === 0) {
                $raw_response = $provider->generateText($system_prompt, $story_context);
            } else {
                $repair_msg = build_repair_prompt($raw_response);
                $raw_response = $provider->generateText($system_prompt, $repair_msg);
            }

            $choices = parse_ai_choices_response($raw_response, $target_choice_count);
            if ($choices !== null) {
                break;
            }
            $last_error = 'AI response was not valid choices JSON.';
        } catch (RuntimeException $e) {
            $last_error = $e->getMessage();
            if (str_contains($last_error, 'Authentication failed')) {
                api_key_mark_unavailable($active_key['id'], $last_error);
                json_error('API key authentication failed and has been deactivated. ' . $last_error, 401);
            }
            break;
        }
    }

    if ($choices === null && api_key_is_connection_error($last_error)) {
        $fallback = api_key_get_fallback($key_record);
        if ($fallback !== null) {
            try {
                $active_key = api_key_prepare_for_use($fallback);
            } catch (RuntimeException $e) {
                json_error($e->getMessage(), 500);
            }

            $prompt_bundle = build_pending_choices_prompt_bundle(
                $story_id,
                $node_id,
                $target_choice_count,
                $locked_choices,
                true,
                $user,
                $active_key
            );
            $system_prompt = $prompt_bundle['system_prompt'];
            $story_context = append_story_regeneration_guidance($prompt_bundle['story_context'], $steer_prompt);
            $provider = api_ai_provider($active_key, $user);
            $last_error = '';
            $raw_response = '';

            for ($attempt = 0; $attempt < 2; $attempt++) {
                try {
                    if ($attempt === 0) {
                        $raw_response = $provider->generateText($system_prompt, $story_context);
                    } else {
                        $repair_msg = build_repair_prompt($raw_response);
                        $raw_response = $provider->generateText($system_prompt, $repair_msg);
                    }

                    $choices = parse_ai_choices_response($raw_response, $target_choice_count);
                    if ($choices !== null) {
                        break;
                    }
                    $last_error = 'AI response was not valid choices JSON.';
                } catch (RuntimeException $e) {
                    $last_error = $e->getMessage();
                    if (str_contains($last_error, 'Authentication failed')) {
                        api_key_mark_unavailable($active_key['id'], $last_error);
                        json_error('API key authentication failed and has been deactivated. ' . $last_error, 401);
                    }
                    break;
                }
            }
        }
    }

    if ($choices === null) {
        api_key_mark_unavailable($active_key['id'], $last_error);
        json_error('AI generation failed: ' . $last_error, 502);
    }

    $normalized_choices = [];
    foreach ($choices as $choice_text) {
        $choice_text = normalize_choice_text($choice_text);
        if ($choice_text !== '') {
            $normalized_choices[] = $choice_text;
        }
    }

    if (count($normalized_choices) !== $target_choice_count) {
        json_error('AI generation failed: the regenerated choices were incomplete.', 502);
    }

    if (!node_replace_pending_choices($story_id, $node_id, $normalized_choices)) {
        json_error('Failed to update pending choices.', 500);
    }

    $sw_meta = is_array($node['sw_meta'] ?? null) ? $node['sw_meta'] : [];
    unset($sw_meta['pending_choices_need_review']);
    $sw_meta = node_meta_append_history($sw_meta, $user['id'], 'pending_choices_regenerated', $active_key['model_text'] ?? '');
    node_update_meta($story_id, $node_id, $sw_meta);

    json_success([
        'message' => 'Pending choices regenerated.',
        'choices' => $normalized_choices,
        'ai_model' => $active_key['model_text'] ?? '',
        'ai_provider' => $active_key['provider'] ?? '',
        'ai_key_label' => $active_key['label'] ?? '',
    ]);
}

/**
 * Stream a regenerated candidate for an existing node without applying it.
 *
 * Expects POST JSON: { story_id, node_id, key_id?, _csrf_token }
 */
function handle_stream_regenerate_node(): void
{
    header('Content-Type: text/event-stream; charset=UTF-8');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');
    ignore_user_abort(false);

    while (ob_get_level()) ob_end_clean();

    $user = current_user();
    if ($user === null) {
        sse_event('error', ['error' => 'Authentication required.']);
        exit;
    }

    $input = get_json_input();
    $csrf  = $input['_csrf_token'] ?? '';
    if (!hash_equals(csrf_token(), $csrf)) {
        sse_event('error', ['error' => 'Invalid CSRF token.']);
        exit;
    }

    $story_id = trim($input['story_id'] ?? '');
    $node_id  = trim($input['node_id'] ?? '');
    $key_id   = trim($input['key_id'] ?? '');
    $request_id = trim((string) ($input['request_id'] ?? ''));
    $steer_prompt = trim((string) ($input['steer_prompt'] ?? ''));

    $track_abort = sw_is_generation_request_id($request_id);
    if ($track_abort) {
        sw_generation_clear_abort($request_id);
    }
    $abort_requested = static function () use ($track_abort, $request_id): bool {
        return connection_aborted() || ($track_abort && sw_generation_abort_requested($request_id));
    };
    sw_set_abort_handler($abort_requested);
    register_shutdown_function(static function () use ($track_abort, $request_id): void {
        sw_set_abort_handler(null);
        if ($track_abort) {
            sw_generation_clear_abort($request_id);
        }
    });

    if (!validate_id($story_id, 'story_') || !validate_id($node_id, 'node_')) {
        sse_event('error', ['error' => 'Invalid story or page ID.']);
        exit;
    }

    $node = node_read_for_user($story_id, $node_id, $user);
    if ($node === null) {
        sse_event('error', ['error' => 'Story node not found.']);
        exit;
    }

    if (!story_user_can_edit_node($story_id, $node, $user)) {
        sse_event('error', ['error' => 'You do not have permission to regenerate this page.']);
        exit;
    }

    if (!node_can_regenerate($node)) {
        sse_event('error', ['error' => 'This page cannot be regenerated after child pages have been created.']);
        exit;
    }

    if ($key_id !== '' && validate_id($key_id, 'key_')) {
        $key_record = api_key_find_by_id($key_id);
        if ($key_record === null || ($key_record['status'] ?? '') !== 'active') {
            $key_record = null;
        } elseif (($key_error = api_key_access_error($key_record, $user)) !== null) {
            sse_event('error', ['error' => $key_error]);
            exit;
        }
    } else {
        $key_record = api_key_select_for_user($user['id']);
    }

    if ($key_record === null) {
        sse_event('error', ['error' => 'No AI key available.']);
        exit;
    }

    if ($abort_requested()) {
        exit;
    }

    try {
        $key_record = api_key_prepare_for_use($key_record);
    } catch (RuntimeException $e) {
        sse_event('error', ['error' => $e->getMessage()]);
        exit;
    }

    sse_event('info', ['key_label' => $key_record['label'] ?? 'Unknown']);
    sw_close_session();

    $is_root = ($node['parent_id'] ?? '') === '';
    if ($is_root) {
        $root_scenario = trim((string) (($node['sw_meta']['scenario_essentials'] ?? '')));
        if ($root_scenario === '') {
            $root_scenario = story_get_scenario_essentials($story_id);
        }
        $prompt_bundle = build_opening_prompt_bundle($node['title'] ?? story_get_title($story_id), $root_scenario, $user);
    } else {
        $choice_taken = trim((string) ($node['choice_taken'] ?? ''));
        if ($choice_taken === '') {
            sse_event('error', ['error' => 'This page is missing the choice text needed for regeneration.']);
            exit;
        }
        $prompt_bundle = build_continuation_prompt_bundle($story_id, $node['parent_id'], $choice_taken, true, $user, $key_record);
    }

    if ($abort_requested()) {
        exit;
    }

    $system_prompt = $prompt_bundle['system_prompt'];
    $story_context = append_story_regeneration_guidance($prompt_bundle['story_context'], $steer_prompt);
    $provider = api_ai_provider($key_record, $user);
    $provider->setAbortHandler($abort_requested);
    $active_key = $key_record;
    $tokens_sent = 0;
    $raw_response = '';

    $stream_chunk_handler = function (string $chunk) use (&$tokens_sent) {
        echo "event: token\ndata: " . str_replace("\n", "\ndata: ", $chunk) . "\n\n";
        flush();
        $tokens_sent++;
    };

    try {
        $raw_response = $provider->generateTextStream($system_prompt, $story_context, $stream_chunk_handler);
    } catch (RuntimeException $e) {
        if (ai_generation_aborted($e) || $abort_requested()) {
            exit;
        }
        $err_msg = $e->getMessage();

        if (str_contains($err_msg, 'Authentication failed')) {
            api_key_mark_unavailable($active_key['id'], $err_msg);
            sse_event('error', ['error' => $err_msg]);
            exit;
        }

        if ($tokens_sent === 0 && api_key_is_connection_error($err_msg)) {
            $fallback = api_key_get_fallback($key_record);
            if ($fallback !== null) {
                try {
                    $active_key = api_key_prepare_for_use($fallback);
                } catch (RuntimeException $e3) {
                    sse_event('error', ['error' => $e3->getMessage()]);
                    exit;
                }
                if (!$is_root) {
                    $prompt_bundle = build_continuation_prompt_bundle($story_id, $node['parent_id'], $choice_taken, true, $user, $active_key);
                    $system_prompt = $prompt_bundle['system_prompt'];
                    $story_context = append_story_regeneration_guidance($prompt_bundle['story_context'], $steer_prompt);
                }
                $provider = api_ai_provider($active_key, $user);
                $provider->setAbortHandler($abort_requested);
                sse_event('info', ['key_label' => $active_key['label'] ?? 'Unknown', 'fallback' => true]);

                try {
                    $raw_response = $provider->generateTextStream($system_prompt, $story_context, $stream_chunk_handler);
                } catch (RuntimeException $e2) {
                    if (ai_generation_aborted($e2) || $abort_requested()) {
                        exit;
                    }
                    if (str_contains($e2->getMessage(), 'Authentication failed')) {
                        api_key_mark_unavailable($active_key['id'], $e2->getMessage());
                    }
                    sse_event('error', ['error' => $e2->getMessage()]);
                    exit;
                }
            } else {
                sse_event('error', ['error' => $err_msg]);
                exit;
            }
        } else {
            sse_event('error', ['error' => $err_msg]);
            exit;
        }
    }

    if ($abort_requested()) {
        exit;
    }

    $parsed = parse_ai_response($raw_response);
    if ($parsed === null) {
        try {
            $repair_msg = build_repair_prompt($raw_response);
            sse_event('info', ['message' => 'Parsing response…']);
            $raw_response = $provider->generateText($system_prompt, $repair_msg);
            $parsed = parse_ai_response($raw_response);
        } catch (RuntimeException $e) {
            if (ai_generation_aborted($e) || $abort_requested()) {
                exit;
            }
            // Ignore retry failure
        }
    }

    if ($abort_requested()) {
        exit;
    }

    if ($parsed === null) {
        api_key_mark_unavailable($active_key['id'], 'JSON parse failure after retry');
        sse_event('error', ['error' => 'AI response could not be parsed.']);
        exit;
    }

    $paragraphs = [];
    foreach ($parsed['paragraphs'] as $paragraph) {
        $paragraphs[] = sanitize_paragraph_html($paragraph);
    }
    if (empty($paragraphs)) {
        $paragraphs = [$is_root ? 'The story begins…' : 'The story continues…'];
    }

    sse_event('done', [
        'ok'                  => true,
        'story_id'            => $story_id,
        'node_id'             => $node_id,
        'current_paragraphs'  => $node['paragraphs'] ?? [],
        'current_choices'     => $node['choices'] ?? [],
        'paragraphs'          => $paragraphs,
        'choices'             => $parsed['choices'] ?? [],
        'ai_model'            => $active_key['model_text'] ?? '',
        'ai_provider'         => $active_key['provider'] ?? '',
        'ai_key_label'        => $active_key['label'] ?? '',
        'scenario_essentials' => $prompt_bundle['scenario_essentials'] ?? '',
        'ending'              => $parsed['ending'] ?? false,
    ]);
    exit;
}

/**
 * Apply an accepted regenerated candidate to an existing node.
 *
 * Expects POST JSON: { story_id, node_id, paragraphs, choices, ai_model, ai_provider, ai_key_label, scenario_essentials?, _csrf_token }
 */
function handle_apply_regenerated_node(): void
{
    $user = current_user();
    if ($user === null) {
        json_error('Authentication required.', 401);
    }

    $input = get_json_input();
    csrf_check($input['_csrf_token'] ?? '');

    $story_id = trim($input['story_id'] ?? '');
    $node_id  = trim($input['node_id'] ?? '');

    if (!validate_id($story_id, 'story_') || !validate_id($node_id, 'node_')) {
        json_error('Invalid story or page ID.', 400);
    }

    $node = node_read_for_user($story_id, $node_id, $user);
    if ($node === null) {
        json_error('Story node not found.', 404);
    }

    if (!story_user_can_edit_node($story_id, $node, $user)) {
        json_error('You do not have permission to update this page.', 403);
    }

    if (!node_can_regenerate($node)) {
        json_error('This page can no longer accept a regenerated version because child pages now exist.', 400);
    }

    $input_paragraphs = $input['paragraphs'] ?? [];
    if (!is_array($input_paragraphs) || empty($input_paragraphs)) {
        json_error('Regenerated text is missing.', 400);
    }

    $paragraphs = [];
    foreach ($input_paragraphs as $paragraph) {
        if (!is_string($paragraph)) {
            continue;
        }
        $clean = sanitize_paragraph_html($paragraph);
        if ($clean !== '') {
            $paragraphs[] = $clean;
        }
    }
    if (empty($paragraphs)) {
        json_error('Regenerated text is empty after sanitization.', 400);
    }

    $input_choices = $input['choices'] ?? [];
    if (!is_array($input_choices)) {
        $input_choices = [];
    }

    $choices = [];
    foreach (array_values($input_choices) as $index => $choice) {
        if (!is_array($choice)) {
            continue;
        }

        $text = trim((string) ($choice['text'] ?? ''));
        if ($text === '') {
            continue;
        }

        $choices[] = [
            'id'   => isset($choice['id']) ? (int) $choice['id'] : ($index + 1),
            'text' => $text,
            'node' => null,
        ];
    }

    $meta = is_array($node['sw_meta'] ?? null) ? $node['sw_meta'] : [];
    $meta['created_by'] = $meta['created_by'] ?? ($node['author_id'] ?? $user['id']);
    $meta['created_at'] = $meta['created_at'] ?? ($node['created_at'] ?? gmdate('Y-m-d\TH:i:s\Z'));
    $meta['ai_generated'] = true;
    $meta['ai_model'] = trim((string) ($input['ai_model'] ?? ''));
    $meta['ai_provider'] = trim((string) ($input['ai_provider'] ?? ''));
    $meta['ai_key_label'] = trim((string) ($input['ai_key_label'] ?? ''));

    if (($node['parent_id'] ?? '') === '') {
        $scenario = normalize_scenario_essentials((string) ($input['scenario_essentials'] ?? ''));
        if ($scenario === '') {
            $scenario = trim((string) ($meta['scenario_essentials'] ?? ''));
        }
        if ($scenario !== '') {
            $meta['scenario_essentials'] = $scenario;
        }
    }

    $meta = node_meta_append_history($meta, $user['id'], 'regenerated', $meta['ai_model']);

    if (!node_replace_content($story_id, $node_id, $paragraphs, $choices, $meta)) {
        json_error('Failed to update the regenerated page.', 500);
    }

    $base = base_url();
    json_success([
        'story_id' => $story_id,
        'node_id'  => $node_id,
        'url'      => node_url($story_id, $node_id),
        'ending'   => !empty($input['ending']),
    ]);
}

/**
 * Stream-generate a new story node via SSE.
 *
 * Same inputs as generate_node, but sends Server-Sent Events with tokens
 * as they arrive, then creates the node and sends a "done" event.
 *
 * SSE events:
 *   event: token     data: {"text": "..."}
 *   event: done      data: {"ok": true, "story_id": "...", "node_id": "...", "url": "..."}
 *   event: error     data: {"error": "..."}
 */
function handle_stream_generate_node(): void
{
    // Override content type for SSE
    header('Content-Type: text/event-stream; charset=UTF-8');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no'); // nginx
    ignore_user_abort(false);

    // Disable output buffering
    while (ob_get_level()) ob_end_clean();

    $input = get_json_input();
    $csrf  = $input['_csrf_token'] ?? '';

    if (!hash_equals(csrf_token(), $csrf)) {
        sse_event('error', ['error' => 'Invalid CSRF token.']);
        exit;
    }

    $story_id       = $input['story_id'] ?? '';
    $parent_node_id = $input['parent_node_id'] ?? '';
    $choice_text    = trim($input['choice_text'] ?? '');
    $story_opening  = (string) ($input['story_opening'] ?? '');
    $scenario       = normalize_scenario_essentials((string) ($input['scenario_essentials'] ?? ''));
    $title          = normalize_story_title((string) ($input['title'] ?? ''));
    $key_id         = trim($input['key_id'] ?? '');
    $image_key_id   = trim((string) ($input['image_key_id'] ?? ''));
    $story_visibility = (string) ($input['story_visibility'] ?? 'public');
    $auto_generate_images = !empty($input['auto_generate_images']);
    $request_id     = trim((string) ($input['request_id'] ?? ''));

    $track_abort = sw_is_generation_request_id($request_id);
    if ($track_abort) {
        sw_generation_clear_abort($request_id);
    }
    $abort_requested = static function () use ($track_abort, $request_id): bool {
        return connection_aborted() || ($track_abort && sw_generation_abort_requested($request_id));
    };
    sw_set_abort_handler($abort_requested);
    register_shutdown_function(static function () use ($track_abort, $request_id): void {
        sw_set_abort_handler(null);
        if ($track_abort) {
            sw_generation_clear_abort($request_id);
        }
    });

    $user = current_user();
    $user_id = $user ? $user['id'] : null;
    $author_id = $user ? $user['id'] : 'anonymous';
    $story_visibility = ($user && $story_visibility === 'private') ? 'private' : 'public';
    $auto_generate_images = $user !== null && $auto_generate_images;
    $image_key_id = validate_id($image_key_id, 'key_') ? $image_key_id : '';
    $opening_paragraphs = normalize_story_opening_paragraphs($story_opening);

    // Select API key (optionally by specific key_id)
    if ($key_id !== '' && validate_id($key_id, 'key_')) {
        $key_record = api_key_find_by_id($key_id);
        if ($key_record === null || $key_record['status'] !== 'active') {
            $key_record = null;
        } elseif (($key_error = api_key_access_error($key_record, $user)) !== null) {
            sse_event('error', ['error' => $key_error]);
            exit;
        }
    } else {
        $key_record = api_key_select_for_user($user_id);
    }

    if ($key_record === null) {
        sse_event('error', ['error' => 'No AI key available.']);
        exit;
    }
    if ($abort_requested()) {
        exit;
    }
    try {
        $key_record = api_key_prepare_for_use($key_record);
    } catch (RuntimeException $e) {
        sse_event('error', ['error' => $e->getMessage()]);
        exit;
    }

    // Send the key label so the client knows which key is being used
    sse_event('info', ['key_label' => $key_record['label'] ?? 'Unknown']);

    $is_opening = ($parent_node_id === '' && $title !== '');

    if (!$is_opening) {
        if (!validate_id($story_id, 'story_') || !validate_id($parent_node_id, 'node_')) {
            sse_event('error', ['error' => 'Invalid story or page ID.']);
            exit;
        }
        if ($choice_text === '') {
            sse_event('error', ['error' => 'Choice text is required.']);
            exit;
        }
        $parent = node_read_for_user($story_id, $parent_node_id, $user);
        if ($parent === null) {
            sse_event('error', ['error' => 'Parent page not found.']);
            exit;
        }
        if (!story_user_can_continue_story($story_id, $user)) {
            sse_event('error', ['error' => 'Only admins can continue the announcements archive.']);
            exit;
        }
        $check_quarantine = (($parent['location'] ?? 'stories') === 'quarantine');
    } else {
        $check_quarantine = false;
        $parent = null;
    }

    sw_close_session();

    $prompt_bundle = $is_opening
        ? build_opening_prompt_bundle($title, $scenario, $user, $story_opening)
        : build_continuation_prompt_bundle($story_id, $parent_node_id, $choice_text, $check_quarantine, $user, $key_record);
    if ($abort_requested()) {
        exit;
    }
    $system_prompt = $prompt_bundle['system_prompt'];
    $story_context = $prompt_bundle['story_context'];

    $provider   = api_ai_provider($key_record, $user);
    $provider->setAbortHandler($abort_requested);
    $active_key = $key_record;

    // Track tokens sent so we know whether we can still fall back transparently
    $tokens_sent = 0;
    $raw_response = '';

    $stream_chunk_handler = function (string $chunk) use (&$tokens_sent) {
        echo "event: token\ndata: " . str_replace("\n", "\ndata: ", $chunk) . "\n\n";
        flush();
        $tokens_sent++;
    };

    try {
        $raw_response = $provider->generateTextStream($system_prompt, $story_context, $stream_chunk_handler);
    } catch (RuntimeException $e) {
        if (ai_generation_aborted($e) || $abort_requested()) {
            exit;
        }
        $err_msg = $e->getMessage();

        if (str_contains($err_msg, 'Authentication failed')) {
            api_key_mark_unavailable($active_key['id'], $err_msg);
            sse_event('error', ['error' => $err_msg]);
            exit;
        }

        // If no tokens were sent yet and a fallback key is configured, try it transparently
        if ($tokens_sent === 0 && api_key_is_connection_error($err_msg)) {
            $fallback = api_key_get_fallback($key_record);
            if ($fallback !== null) {
                try {
                    $active_key = api_key_prepare_for_use($fallback);
                } catch (RuntimeException $e3) {
                    sse_event('error', ['error' => $e3->getMessage()]);
                    exit;
                }
                $prompt_bundle = $is_opening
                    ? build_opening_prompt_bundle($title, $scenario, $user, $story_opening)
                    : build_continuation_prompt_bundle($story_id, $parent_node_id, $choice_text, $check_quarantine, $user, $active_key);
                $system_prompt = $prompt_bundle['system_prompt'];
                $story_context = $prompt_bundle['story_context'];
                $provider   = api_ai_provider($active_key, $user);
                $provider->setAbortHandler($abort_requested);
                sse_event('info', ['key_label' => $active_key['label'] ?? 'Unknown', 'fallback' => true]);

                try {
                    $raw_response = $provider->generateTextStream($system_prompt, $story_context, $stream_chunk_handler);
                } catch (RuntimeException $e2) {
                    if (ai_generation_aborted($e2) || $abort_requested()) {
                        exit;
                    }
                    if (str_contains($e2->getMessage(), 'Authentication failed')) {
                        api_key_mark_unavailable($active_key['id'], $e2->getMessage());
                    }
                    sse_event('error', ['error' => $e2->getMessage()]);
                    exit;
                }
            } else {
                sse_event('error', ['error' => $err_msg]);
                exit;
            }
        } else {
            sse_event('error', ['error' => $err_msg]);
            exit;
        }
    }

    if ($abort_requested()) {
        exit;
    }

    // Parse the accumulated response
    $parsed = parse_ai_response($raw_response);
    if ($parsed === null) {
        // Retry without streaming
        try {
            $repair_msg = build_repair_prompt($raw_response);
            sse_event('info', ['message' => 'Parsing response…']);
            $raw_response = $provider->generateText($system_prompt, $repair_msg);
            $parsed = parse_ai_response($raw_response);
        } catch (RuntimeException $e) {
            if (ai_generation_aborted($e) || $abort_requested()) {
                exit;
            }
            // Ignore retry failure
        }
    }

    if ($abort_requested()) {
        exit;
    }

    if ($parsed === null) {
        api_key_mark_unavailable($active_key['id'], 'JSON parse failure after retry');
        sse_event('error', ['error' => 'AI response could not be parsed.']);
        exit;
    }

    // Sanitize paragraphs
    $paragraphs = [];
    foreach ($parsed['paragraphs'] as $p) {
        $paragraphs[] = sanitize_paragraph_html($p);
    }
    if (empty($paragraphs)) {
        $paragraphs = [$is_opening ? 'The story begins…' : 'The story continues…'];
    }
    if ($is_opening && !empty($opening_paragraphs)) {
        $paragraphs = array_merge($opening_paragraphs, $paragraphs);
    }

    $choices = $parsed['choices'] ?? [];

    // AI metadata from the key actually used (may be the fallback)
    $ai_meta = [
        'ai_model'     => $active_key['model_text'] ?? '',
        'ai_provider'  => $active_key['provider'] ?? '',
        'ai_key_label' => $active_key['label'] ?? '',
    ];

    if ($is_opening) {
        $result = story_create(h($title), $author_id, $paragraphs, array_merge($ai_meta, [
            'scenario_essentials'  => $prompt_bundle['scenario_essentials'],
            'visibility'           => $story_visibility,
            'shared_user_ids'      => [],
            'auto_generate_images' => $auto_generate_images,
            'auto_image_key_id'    => $auto_generate_images ? $image_key_id : '',
        ]));
        $story_id = $result['story_id'];
        $node_id  = $result['node_id'];
        if (!empty($choices)) {
            node_update_choices($story_id, $node_id, $choices);
        }
    } else {
        $node_id = node_create($story_id, array_merge([
            'parent_id'    => $parent_node_id,
            'choice_taken' => h($choice_text),
            'author_id'    => $author_id,
            'title'        => story_get_title($story_id),
            'paragraphs'   => $paragraphs,
            'choices'      => $choices,
            'location'     => (($parent['location'] ?? 'stories') === 'quarantine' ? 'quarantine' : 'stories'),
        ], $ai_meta));
        node_link_choice($story_id, $parent_node_id, $choice_text, $node_id);
    }

    $auto_image_enabled = $is_opening ? $auto_generate_images : story_auto_images_enabled($story_id);
    $done_url = node_url($story_id, $node_id);
    if ($auto_image_enabled) {
        $done_url = app_url('node', [
            'story' => $story_id,
            'id' => $node_id,
            'auto_image' => '1',
        ]);
    }

    $base = base_url();
    sse_event('done', [
        'ok'         => true,
        'story_id'   => $story_id,
        'node_id'    => $node_id,
        'url'        => $done_url,
        'ending'     => $parsed['ending'] ?? false,
        'paragraphs' => $paragraphs,
        'choices'    => $choices,
    ]);
    exit;
}

/**
 * Send a Server-Sent Event.
 *
 * @param string $event Event name.
 * @param array  $data  Data to JSON-encode.
 * @return void
 */
function sse_event(string $event, array $data): void
{
    echo "event: " . $event . "\n";
    echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    flush();
}

/**
 * Return true when an AI request ended because the user cancelled it.
 */
function ai_generation_aborted(RuntimeException $e): bool
{
    return $e->getMessage() === 'Generation aborted by user.';
}

/**
 * Mark an in-flight generation request as aborted.
 */
function handle_abort_generation(): void
{
    $input = get_json_input();
    csrf_check((string) ($input['_csrf_token'] ?? ''));

    $request_id = trim((string) ($input['request_id'] ?? ''));
    if (!sw_is_generation_request_id($request_id)) {
        json_error('Invalid request ID.', 400);
    }

    sw_generation_request_abort($request_id);
    json_success(['message' => 'Abort requested.']);
}

/**
 * Test an API key with a minimal prompt.
 *
 * Expects POST JSON: { key_id, _csrf_token }
 * Requires contributor+.
 */
function handle_test_api_key(): void
{
    $user = current_user();
    if ($user === null || role_level($user['role']) < role_level('contributor')) {
        json_error('Contributor access required.', 403);
    }

    $input = get_json_input();
    $csrf  = $input['_csrf_token'] ?? '';
    if (!hash_equals(csrf_token(), $csrf)) {
        json_error('Invalid CSRF token.', 403);
    }

    $key_id = $input['key_id'] ?? '';
    if (!validate_id($key_id, 'key_')) {
        json_error('Invalid key ID.', 400);
    }

    $key_record = api_key_find_by_id($key_id);
    if ($key_record === null) {
        json_error('Key not found.', 404);
    }

    // Only owner or admin can test
    if ($key_record['owner_user_id'] !== $user['id']
        && role_level($user['role']) < role_level('admin')) {
        json_error('You can only test your own keys.', 403);
    }

    $key_error = api_key_access_error($key_record, $user);
    if ($key_error !== null) {
        json_error($key_error, 403);
    }

    try {
        $key_record = api_key_prepare_for_use($key_record);
    } catch (RuntimeException $e) {
        json_error($e->getMessage(), 500);
    }

    sw_close_session();

    $provider = api_ai_provider($key_record, $user);
    $result = $provider->testConnection();

    json_success($result);
}

/**
 * Save (create) a new API key.
 *
 * Expects POST JSON: { label, provider, base_url, api_key, model_text, model_image, scope, fallback_key_id?, _csrf_token }
 * Requires contributor+.
 */
function handle_save_api_key(): void
{
    $user = current_user();
    if ($user === null || role_level($user['role']) < role_level('contributor')) {
        json_error('Contributor access required.', 403);
    }

    $input = get_json_input();
    $csrf  = $input['_csrf_token'] ?? '';
    if (!hash_equals(csrf_token(), $csrf)) {
        json_error('Invalid CSRF token.', 403);
    }

    $label           = trim($input['label'] ?? '');
    $provider        = trim($input['provider'] ?? '');
    $base_url        = trim($input['base_url'] ?? '');
    $api_key         = trim($input['api_key'] ?? '');
    $model_text      = trim($input['model_text'] ?? '');
    $model_image     = trim($input['model_image'] ?? '');
    $scope           = trim($input['scope'] ?? 'self');
    $fallback_key_id = trim($input['fallback_key_id'] ?? '');

    if ($label === '') {
        json_error('Label is required.', 400);
    }
    if (!api_key_valid_provider($provider)) {
        json_error('Invalid provider.', 400);
    }
    if ($model_text === '') {
        json_error('Text model name is required.', 400);
    }

    // Auto-fill base URL if empty and provider is known
    if ($base_url === '') {
        $base_url = api_key_default_base_url($provider);
    }
    if ($base_url === '') {
        json_error('Base URL is required for this provider.', 400);
    }

    // Ollama's OpenAI-compatible endpoint lives under /v1; normalize if the user
    // entered just the host:port (e.g. http://192.168.1.60:11434).
    if ($provider === 'ollama' && !str_ends_with(rtrim($base_url, '/'), '/v1')) {
        $base_url = rtrim($base_url, '/') . '/v1';
    }

    $url_policy = api_key_url_policy($base_url);
    if (!$url_policy['ok']) {
        json_error($url_policy['reason'], 400);
    }
    if ($url_policy['restricted'] && $user['role'] !== 'admin') {
        json_error($url_policy['reason'], 403);
    }

    if (!in_array($scope, ['self', 'all'], true)) {
        $scope = 'self';
    }

    // Validate fallback key if provided
    if ($fallback_key_id !== '') {
        if (!validate_id($fallback_key_id, 'key_')) {
            json_error('Invalid fallback key ID.', 400);
        }
        $fb_record = api_key_find_by_id($fallback_key_id);
        if ($fb_record === null) {
            json_error('Fallback key not found.', 400);
        }
        // Prevent pointing at a key owned by someone else unless it's scope=all
        if ($fb_record['owner_user_id'] !== $user['id'] && ($fb_record['scope'] ?? '') !== 'all') {
            json_error('Fallback key must be yours or shared with all users.', 403);
        }
    } else {
        $fallback_key_id = null;
    }

    $record = api_key_create([
        'owner_user_id'  => $user['id'],
        'label'          => $label,
        'provider'       => $provider,
        'base_url'       => $base_url,
        'api_key'        => $api_key,
        'model_text'     => $model_text,
        'model_image'    => $model_image,
        'scope'          => $scope,
        'fallback_key_id' => $fallback_key_id,
    ]);

    json_success(['key' => array_diff_key($record, ['api_key' => 1]), 'message' => 'API key saved.']);
}

/**
 * Update an existing API key without exposing or changing the stored secret.
 *
 * Expects POST JSON: { key_id, label, provider, base_url, model_text, model_image, scope, fallback_key_id?, _csrf_token }
 * Requires key owner or admin.
 */
function handle_update_api_key(): void
{
    $user = current_user();
    if ($user === null || role_level($user['role']) < role_level('contributor')) {
        json_error('Contributor access required.', 403);
    }

    $input = get_json_input();
    $csrf  = $input['_csrf_token'] ?? '';
    if (!hash_equals(csrf_token(), $csrf)) {
        json_error('Invalid CSRF token.', 403);
    }

    $key_id          = trim((string) ($input['key_id'] ?? ''));
    $label           = trim((string) ($input['label'] ?? ''));
    $provider        = trim((string) ($input['provider'] ?? ''));
    $base_url        = trim((string) ($input['base_url'] ?? ''));
    $model_text      = trim((string) ($input['model_text'] ?? ''));
    $model_image     = trim((string) ($input['model_image'] ?? ''));
    $scope           = trim((string) ($input['scope'] ?? 'self'));
    $fallback_key_id = trim((string) ($input['fallback_key_id'] ?? ''));

    if (!validate_id($key_id, 'key_')) {
        json_error('Invalid key ID.', 400);
    }

    $existing = api_key_find_by_id($key_id);
    if ($existing === null) {
        json_error('Key not found.', 404);
    }

    if (($existing['owner_user_id'] ?? '') !== $user['id'] && role_level($user['role']) < role_level('admin')) {
        json_error('Permission denied.', 403);
    }

    if ($label === '') {
        json_error('Label is required.', 400);
    }
    if (!api_key_valid_provider($provider)) {
        json_error('Invalid provider.', 400);
    }
    if ($model_text === '') {
        json_error('Text model name is required.', 400);
    }

    if ($base_url === '') {
        $base_url = api_key_default_base_url($provider);
    }
    if ($base_url === '') {
        json_error('Base URL is required for this provider.', 400);
    }

    if ($provider === 'ollama' && !str_ends_with(rtrim($base_url, '/'), '/v1')) {
        $base_url = rtrim($base_url, '/') . '/v1';
    }

    $url_policy = api_key_url_policy($base_url);
    if (!$url_policy['ok']) {
        json_error($url_policy['reason'], 400);
    }
    if ($url_policy['restricted'] && $user['role'] !== 'admin') {
        json_error($url_policy['reason'], 403);
    }

    if (!in_array($scope, ['self', 'all'], true)) {
        $scope = 'self';
    }

    if ($fallback_key_id !== '') {
        if (!validate_id($fallback_key_id, 'key_')) {
            json_error('Invalid fallback key ID.', 400);
        }
        if ($fallback_key_id === $key_id) {
            json_error('A key cannot fall back to itself.', 400);
        }
        $fb_record = api_key_find_by_id($fallback_key_id);
        if ($fb_record === null) {
            json_error('Fallback key not found.', 400);
        }
        if (($fb_record['owner_user_id'] ?? '') !== $user['id'] && ($fb_record['scope'] ?? '') !== 'all') {
            json_error('Fallback key must be yours or shared with all users.', 403);
        }
    } else {
        $fallback_key_id = null;
    }

    $ok = api_key_update($key_id, [
        'label'           => $label,
        'provider'        => $provider,
        'base_url'        => rtrim($base_url, '/'),
        'model_text'      => $model_text,
        'model_image'     => $model_image,
        'scope'           => $scope,
        'fallback_key_id' => $fallback_key_id,
    ]);

    if (!$ok) {
        json_error('Failed to update API key.', 500);
    }

    $updated = api_key_find_by_id($key_id);
    if ($updated === null) {
        json_error('Updated key could not be reloaded.', 500);
    }

    json_success(['key' => array_diff_key($updated, ['api_key' => 1]), 'message' => 'API key updated.']);
}

/**
 * List available models for an existing or in-progress API-key configuration.
 *
 * Expects POST JSON: { key_id? , provider?, base_url?, api_key?, _csrf_token }
 * Requires contributor+.
 */
function handle_list_api_models(): void
{
    $user = current_user();
    if ($user === null || role_level($user['role']) < role_level('contributor')) {
        json_error('Contributor access required.', 403);
    }

    $input = get_json_input();
    $csrf  = $input['_csrf_token'] ?? '';
    if (!hash_equals(csrf_token(), $csrf)) {
        json_error('Invalid CSRF token.', 403);
    }

    $key_id = trim((string) ($input['key_id'] ?? ''));

    if ($key_id !== '') {
        if (!validate_id($key_id, 'key_')) {
            json_error('Invalid key ID.', 400);
        }

        $record = api_key_find_by_id($key_id);
        if ($record === null) {
            json_error('Key not found.', 404);
        }

        $key_error = api_key_access_error($record, $user);
        if ($key_error !== null) {
            json_error($key_error, 403);
        }

        try {
            $record = api_key_prepare_for_use($record);
        } catch (RuntimeException $e) {
            json_error($e->getMessage(), 500);
        }
    } else {
        $provider = trim((string) ($input['provider'] ?? ''));
        $base_url = trim((string) ($input['base_url'] ?? ''));
        $api_key  = trim((string) ($input['api_key'] ?? ''));

        if (!api_key_valid_provider($provider)) {
            json_error('Invalid provider.', 400);
        }

        if ($base_url === '') {
            $base_url = api_key_default_base_url($provider);
        }
        if ($base_url === '') {
            json_error('Base URL is required for this provider.', 400);
        }

        if ($provider === 'ollama' && !str_ends_with(rtrim($base_url, '/'), '/v1')) {
            $base_url = rtrim($base_url, '/') . '/v1';
        }

        $url_policy = api_key_url_policy($base_url);
        if (!$url_policy['ok']) {
            json_error($url_policy['reason'], 400);
        }
        if ($url_policy['restricted'] && $user['role'] !== 'admin') {
            json_error($url_policy['reason'], 403);
        }

        $record = [
            'provider' => $provider,
            'base_url' => rtrim($base_url, '/'),
            'api_key' => $api_key,
            'model_text' => '',
            'model_image' => '',
        ];
    }

    $provider_client = api_ai_provider($record, current_user());

    try {
        $catalog = $provider_client->listModelCatalog();
    } catch (RuntimeException $e) {
        json_error('Failed to list models: ' . $e->getMessage(), 400);
    }

    json_success([
        'models' => array_values(array_unique(array_merge($catalog['text'] ?? [], $catalog['image'] ?? []))),
        'text_models' => $catalog['text'] ?? [],
        'image_models' => $catalog['image'] ?? [],
    ]);
}

/**
 * Deactivate an API key (set status to unavailable).
 *
 * Expects POST JSON: { key_id, _csrf_token }
 * Requires key owner or admin.
 */
function handle_deactivate_api_key(): void
{
    $user = current_user();
    if ($user === null) {
        json_error('Authentication required.', 401);
    }

    $input = get_json_input();
    $csrf  = $input['_csrf_token'] ?? '';
    if (!hash_equals(csrf_token(), $csrf)) {
        json_error('Invalid CSRF token.', 403);
    }

    $key_id = $input['key_id'] ?? '';
    if (!validate_id($key_id, 'key_')) {
        json_error('Invalid key ID.', 400);
    }

    $key_record = api_key_find_by_id($key_id);
    if ($key_record === null) {
        json_error('Key not found.', 404);
    }

    if ($key_record['owner_user_id'] !== $user['id']
        && role_level($user['role']) < role_level('admin')) {
        json_error('Permission denied.', 403);
    }

    api_key_mark_unavailable($key_id, 'Deactivated by user.');
    json_success(['message' => 'Key deactivated.']);
}

/**
 * Reactivate an unavailable API key.
 *
 * Expects POST JSON: { key_id, _csrf_token }
 * Requires key owner only.
 */
function handle_reactivate_api_key(): void
{
    $user = current_user();
    if ($user === null) {
        json_error('Authentication required.', 401);
    }

    $input = get_json_input();
    $csrf  = $input['_csrf_token'] ?? '';
    if (!hash_equals(csrf_token(), $csrf)) {
        json_error('Invalid CSRF token.', 403);
    }

    $key_id = $input['key_id'] ?? '';
    if (!validate_id($key_id, 'key_')) {
        json_error('Invalid key ID.', 400);
    }

    $key_record = api_key_find_by_id($key_id);
    if ($key_record === null) {
        json_error('Key not found.', 404);
    }

    // Only the owner can reactivate (§3.5)
    if ($key_record['owner_user_id'] !== $user['id']) {
        json_error('Only the key owner can reactivate a key.', 403);
    }

    api_key_update($key_id, [
        'status'       => 'active',
        'last_failure' => null,
    ]);

    json_success(['message' => 'Key reactivated.']);
}

/**
 * Delete an API key.
 *
 * Expects POST JSON: { key_id, _csrf_token }
 * Requires key owner or admin.
 */
function handle_delete_api_key(): void
{
    $user = current_user();
    if ($user === null) {
        json_error('Authentication required.', 401);
    }

    $input = get_json_input();
    $csrf  = $input['_csrf_token'] ?? '';
    if (!hash_equals(csrf_token(), $csrf)) {
        json_error('Invalid CSRF token.', 403);
    }

    $key_id = $input['key_id'] ?? '';
    if (!validate_id($key_id, 'key_')) {
        json_error('Invalid key ID.', 400);
    }

    $key_record = api_key_find_by_id($key_id);
    if ($key_record === null) {
        json_error('Key not found.', 404);
    }

    if ($key_record['owner_user_id'] !== $user['id']
        && role_level($user['role']) < role_level('admin')) {
        json_error('Permission denied.', 403);
    }

    api_key_delete($key_id);
    json_success(['message' => 'Key deleted.']);
}

/**
 * Set the default shared/public API key (admin only).
 *
 * Expects POST JSON: { key_id?, _csrf_token }
 */
function handle_set_default_public_api_key(): void
{
    $user = current_user();
    if ($user === null || ($user['role'] ?? '') !== 'admin') {
        json_error('Admin access required.', 403);
    }

    $input = get_json_input();
    $csrf  = $input['_csrf_token'] ?? '';
    if (!hash_equals(csrf_token(), $csrf)) {
        json_error('Invalid CSRF token.', 403);
    }

    $key_id = trim((string) ($input['key_id'] ?? ''));
    if ($key_id !== '' && !validate_id($key_id, 'key_')) {
        json_error('Invalid key ID.', 400);
    }

    if ($key_id !== '') {
        $key_record = api_key_find_by_id($key_id);
        if ($key_record === null) {
            json_error('Key not found.', 404);
        }
        if (($key_record['scope'] ?? '') !== 'all' || ($key_record['status'] ?? '') !== 'active') {
            json_error('Only active shared keys can be set as the default public key.', 400);
        }
    }

    if (!api_key_set_default_public($key_id)) {
        json_error('Failed to update the default public key.', 500);
    }

    json_success([
        'message' => $key_id === ''
            ? 'Default public key cleared. Automatic selection will use the newest active shared key.'
            : 'Default public key updated.',
        'key_id' => $key_id,
    ]);
}

/* ======================================================================
 * RESPONSE HELPERS
 * ====================================================================*/

/**
 * Send a JSON success response and exit.
 *
 * @param array $data Additional data to include in the response.
 * @return never
 */
function json_success(array $data = []): never
{
    echo json_encode(array_merge(['ok' => true], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Send a JSON error response and exit.
 *
 * @param string $message Error message.
 * @param int    $status  HTTP status code.
 * @return never
 */
function json_error(string $message, int $status = 400): never
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Parse JSON input from the request body.
 *
 * @return array Decoded JSON data.
 */
function get_json_input(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return $_POST; // Fall back to form data
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $_POST;
}

/**
 * Normalize manually entered or AI-generated choice text.
 */
function normalize_choice_text(string $choice_text): string
{
    $choice_text = html_entity_decode(strip_tags($choice_text), ENT_QUOTES, 'UTF-8');
    $choice_text = preg_replace('/\s+/u', ' ', $choice_text) ?? $choice_text;
    return trim(mb_substr($choice_text, 0, 160));
}

/**
 * Append stronger steering guidance to a story-generation prompt.
 */
function append_story_regeneration_guidance(string $story_context, string $steer_prompt): string
{
    $steer_prompt = trim($steer_prompt);
    if ($steer_prompt === '') {
        return $story_context;
    }

    return $story_context
        . "\n\n[IMPORTANT REGENERATION GUIDANCE]\n"
        . "The user wants the new version to clearly incorporate this direction while staying coherent with the established story. "
        . "Treat it as a concrete requirement for the regenerated text and choices:\n"
        . $steer_prompt;
}

/**
 * Append stronger steering guidance to an image-generation prompt.
 */
function append_image_regeneration_guidance(string $prompt, string $steer_prompt): string
{
    $steer_prompt = trim($steer_prompt);
    if ($steer_prompt === '') {
        return $prompt;
    }

    return $prompt
        . "\n\nImportant regeneration guidance: Clearly incorporate the following direction into the new image while keeping the established scene, characters, and mood consistent: "
        . $steer_prompt;
}

/**
 * Use the text AI to produce a brief visual-context summary for image generation.
 *
 * Walks the full story ancestor chain (excluding the current node) and asks the
 * text model to describe, in 2–3 sentences, the protagonist's appearance, the
 * setting, and any key recurring visual elements. Returns an empty string if no
 * suitable text key is available or the call fails.
 *
 * @param string $story_id          Story ID.
 * @param string $node_id           The node being illustrated (current node).
 * @param array  $image_key         The image-generation key record (reused if it has model_text).
 * @param bool   $check_quarantine  Also read quarantined nodes when needed.
 * @return string The summary, or '' on failure / no key.
 */
function build_image_context_summary(string $story_id, string $node_id, array $image_key, ?array $user = null, bool $check_quarantine = false): string
{
    // Prefer the image key's own text model; fall back to any active text key.
    $text_key = null;
    if (!empty($image_key['model_text']) && api_key_access_error($image_key, $user) === null) {
        $text_key = $image_key;
    } else {
        $text_key = api_key_select_text_for_user($user['id'] ?? null);
    }

    if ($text_key === null) {
        return '';
    }

    // Build context from all ancestor nodes (not the current one — its text
    // is already used verbatim in the main image prompt).
    $node = node_read($story_id, $node_id, $check_quarantine);
    $parent_id = $node['parent_id'] ?? '';
    if ($parent_id === '') {
        return ''; // Opening node — no prior context to summarise.
    }

    $entries = reconstruct_context($story_id, $parent_id, $check_quarantine);
    $entries = truncate_context($entries, 10000);

    $context_parts = [];
    foreach ($entries as $entry) {
        if ($entry['choice_taken'] !== '') {
            $context_parts[] = '> Player chose: ' . $entry['choice_taken'];
        }
        if ($entry['paragraphs'] !== '') {
            $context_parts[] = $entry['paragraphs'];
        }
    }

    if (empty($context_parts)) {
        return '';
    }

    $context_text = implode("\n\n", $context_parts);
    $system = 'You are a visual-context assistant for an interactive fiction story.';
    $prompt = "Here is the story so far:\n\n{$context_text}\n\n"
            . "In 2–3 sentences describe ONLY visually observable details from this story: "
            . "the protagonist's appearance (clothing, features), the setting/environment, "
            . "and any recurring visual props or atmosphere. "
            . "Do NOT describe plot events. Output plain prose only — no bullet points, no labels.";

    try {
        $provider = api_ai_provider(api_key_prepare_for_use($text_key), $user);
        $summary  = trim($provider->generateText($system, $prompt));
        return $summary;
    } catch (RuntimeException $e) {
        return ''; // Silently degrade if AI call fails.
    }
}

/**
 * Generate an image for a node and save it.
 *
 * Expects POST JSON: { story_id, node_id, key_id? }
 * Returns the image URL on success.
 */
function handle_generate_image(): void
{
    $input = get_json_input();
    csrf_check($input['_csrf_token'] ?? '');

    $story_id = $input['story_id'] ?? '';
    $node_id  = $input['node_id'] ?? '';
    $key_id   = trim($input['key_id'] ?? '');

    if (!validate_id($story_id, 'story_') || !validate_id($node_id, 'node_')) {
        json_error('Invalid story or page ID.');
    }

    $user = current_user();
    $user_id = $user ? $user['id'] : null;
    $node = node_read_for_user($story_id, $node_id, $user);
    if ($node === null) {
        json_error('Page not found.', 404);
    }
    if (!story_user_can_continue_story($story_id, $user)) {
        json_error('Only admins can generate images for the announcements archive.', 403);
    }
    $check_quarantine = (($node['location'] ?? 'stories') === 'quarantine');

    // Select API key
    if ($key_id !== '' && validate_id($key_id, 'key_')) {
        $key_record = api_key_find_by_id($key_id);
        if ($key_record === null || $key_record['status'] !== 'active') {
            $key_record = null;
        } elseif (($key_error = api_key_access_error($key_record, $user)) !== null) {
            json_error($key_error, 403);
        }
    } else {
        $key_record = api_key_select_for_user($user_id);
    }

    if ($key_record === null || empty($key_record['model_image'])) {
        $fallback_image_key = api_key_select_image_for_user($user_id);
        if ($fallback_image_key !== null) {
            $key_record = $fallback_image_key;
        }
    }

    if ($key_record === null) {
        json_error('No AI key available.');
    }

    if (empty($key_record['model_image'])) {
        json_error('No image-generation model is available.');
    }

    $steer_prompt = trim((string) ($input['steer_prompt'] ?? ''));
    sw_close_session();
    $context_summary = build_image_context_summary($story_id, $node_id, $key_record, $user, $check_quarantine);
    $prompt     = build_image_prompt($story_id, $node_id, $context_summary, $check_quarantine);
    $prompt = append_image_regeneration_guidance($prompt, $steer_prompt);
    try {
        $active_key = api_key_prepare_for_use($key_record);
    } catch (RuntimeException $e) {
        json_error($e->getMessage(), 500);
    }
    $provider   = api_ai_provider($active_key, $user);

    try {
        $image_data = $provider->generateImage($prompt);
        $image_path = node_save_image($node_id, $image_data);
        json_success(['image_url' => image_url($story_id, $node_id, basename($image_path))]);
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();

        // Auth errors: mark unavailable immediately
        if (str_contains($msg, 'HTTP 401') || str_contains($msg, 'HTTP 403')) {
            api_key_mark_unavailable($active_key['id']);
            json_error('Image generation failed: ' . $msg);
        }

        // Connection error: try fallback key if configured (primary stays active)
        if (api_key_is_connection_error($msg)) {
            $fallback = api_key_get_fallback($key_record);
            if ($fallback !== null && !empty($fallback['model_image'])) {
                try {
                    $active_key = api_key_prepare_for_use($fallback);
                } catch (RuntimeException $e3) {
                    json_error($e3->getMessage(), 500);
                }
                $provider   = api_ai_provider($active_key, $user);

                try {
                    $image_data = $provider->generateImage($prompt);
                    $image_path = node_save_image($node_id, $image_data);
                    json_success(['image_url' => image_url($story_id, $node_id, basename($image_path))]);
                } catch (RuntimeException $e2) {
                    $msg = $e2->getMessage();
                    if (str_contains($msg, 'HTTP 401') || str_contains($msg, 'HTTP 403')) {
                        api_key_mark_unavailable($active_key['id']);
                    }
                    json_error('Image generation failed: ' . $msg);
                }
            }
        }

        json_error('Image generation failed: ' . $msg);
    }
}

/* ======================================================================
 * MODERATION ACTION HANDLERS (§7, §8)
 * ====================================================================*/

/**
 * Flag a node for concern (§8).
 *
 * Any user (including anonymous) can flag. Expects: { node_id, story_id, reason? }
 */
function handle_flag_concern(): void
{
    $input = get_json_input();
    csrf_check($input['_csrf_token'] ?? '');

    $node_id  = $input['node_id'] ?? '';
    $story_id = $input['story_id'] ?? '';
    $reason   = trim($input['reason'] ?? '');

    if (!validate_id($node_id, 'node_') || !validate_id($story_id, 'story_')) {
        json_error('Invalid page or story ID.');
    }

    $user = current_user();
    $flagged_by = $user ? $user['id'] : 'anonymous';

    concern_add($node_id, $story_id, $reason, $flagged_by);
    json_success(['message' => 'Thank you for flagging this page.']);
}

/**
 * Flag a node for review / quarantine (§7).
 *
 * Requires editor+ role. Expects: { story_id, node_id, concern_id? }
 * If concern_id is provided, also dismisses that concern.
 */
function handle_flag_review(): void
{
    $input = get_json_input();
    csrf_check($input['_csrf_token'] ?? '');

    $user = current_user();
    if (!$user || !in_array($user['role'], ['editor', 'admin'])) {
        json_error('Editor or admin access required.', 403);
    }

    $story_id   = $input['story_id'] ?? '';
    $node_id    = $input['node_id'] ?? '';
    $concern_id = $input['concern_id'] ?? '';

    if (!validate_id($story_id, 'story_') || !validate_id($node_id, 'node_')) {
        json_error('Invalid story or page ID.');
    }

    $ok = quarantine_move($story_id, $node_id, $user['id']);
    if (!$ok) {
        json_error('Failed to quarantine page.');
    }

    // Dismiss the associated concern if provided
    if ($concern_id !== '' && validate_id($concern_id, 'flag_')) {
        concern_dismiss($concern_id);
    }

    json_success(['message' => 'Page and subtree moved to quarantine.']);
}

/**
 * Approve and restore a quarantined node (§7).
 *
 * Requires editor+ role. Expects: { story_id, node_id }
 */
function handle_approve_node(): void
{
    $input = get_json_input();
    csrf_check($input['_csrf_token'] ?? '');

    $user = current_user();
    if (!$user || !in_array($user['role'], ['editor', 'admin'])) {
        json_error('Editor or admin access required.', 403);
    }

    $story_id = $input['story_id'] ?? '';
    $node_id  = $input['node_id'] ?? '';

    if (!validate_id($story_id, 'story_') || !validate_id($node_id, 'node_')) {
        json_error('Invalid story or page ID.');
    }

    $ok = quarantine_restore($story_id, $node_id);
    if (!$ok) {
        json_error('Failed to restore page.');
    }

    json_success(['message' => 'Page restored from quarantine.']);
}

/**
 * Delete a quarantined node permanently (§7).
 *
 * Requires editor+ role. Expects: { story_id, node_id }
 */
function handle_delete_node(): void
{
    $input = get_json_input();
    csrf_check($input['_csrf_token'] ?? '');

    $user = current_user();
    if (!$user || !in_array($user['role'], ['editor', 'admin'])) {
        json_error('Editor or admin access required.', 403);
    }

    $story_id = $input['story_id'] ?? '';
    $node_id  = $input['node_id'] ?? '';

    if (!validate_id($story_id, 'story_') || !validate_id($node_id, 'node_')) {
        json_error('Invalid story or page ID.');
    }

    $ok = quarantine_delete($story_id, $node_id);
    if (!$ok) {
        json_error('Failed to delete page.');
    }

    json_success([
        'message' => 'Page permanently deleted.',
        'redirect' => app_url('index'),
    ]);
}

/**
 * Delete a final/leaf page that has no active child pages.
 *
 * Requires the page owner or editor+ role. Expects: { story_id, node_id }
 */
function handle_delete_final_page(): void
{
    $input = get_json_input();
    csrf_check($input['_csrf_token'] ?? '');

    $user = current_user();
    if ($user === null) {
        json_error('Authentication required.', 401);
    }

    $story_id = trim((string) ($input['story_id'] ?? ''));
    $node_id  = trim((string) ($input['node_id'] ?? ''));
    if (!validate_id($story_id, 'story_') || !validate_id($node_id, 'node_')) {
        json_error('Invalid story or page ID.', 400);
    }

    $node = node_read_for_user($story_id, $node_id, $user);
    if ($node === null) {
        json_error('Story page not found.', 404);
    }

    if (!story_user_can_edit_node($story_id, $node, $user)) {
        json_error('You do not have permission to delete this page.', 403);
    }

    if (!node_can_regenerate($node)) {
        json_error('Only final pages without active child pages can be deleted.', 400);
    }

    $redirect = ($node['parent_id'] ?? '') !== ''
        ? node_url($story_id, (string) $node['parent_id'])
        : app_url('index');

    if (!node_delete_leaf($story_id, $node_id)) {
        json_error('Failed to delete page.', 500);
    }

    json_success([
        'message' => 'Page deleted.',
        'redirect' => $redirect,
    ]);
}

/**
 * Delete a full story, including all pages and generated images.
 */
function handle_delete_story(): void
{
    $input = get_json_input();
    csrf_check($input['_csrf_token'] ?? '');

    $user = current_user();
    if ($user === null) {
        json_error('Authentication required.', 401);
    }

    $story_id = trim((string) ($input['story_id'] ?? ''));
    if (!validate_id($story_id, 'story_')) {
        json_error('Invalid story ID.', 400);
    }

    $root_id = story_find_root($story_id);
    if ($root_id === null) {
        json_error('Story not found.', 404);
    }

    $root = node_read_for_user($story_id, $root_id, $user);
    if ($root === null) {
        json_error('Story not found.', 404);
    }

    if (!story_user_can_manage_access($story_id, $user)) {
        json_error('Only the story owner or admin can delete this story.', 403);
    }

    if (!story_delete($story_id)) {
        json_error('Failed to delete story.', 500);
    }

    json_success([
        'message' => 'Story deleted.',
        'redirect' => app_url('index'),
    ]);
}

/**
 * Dismiss a concern from the queue.
 *
 * Requires editor+ role. Expects: { id }
 */
function handle_dismiss_concern(): void
{
    $input = get_json_input();
    csrf_check($input['_csrf_token'] ?? '');

    $user = current_user();
    if (!$user || !in_array($user['role'], ['editor', 'admin'])) {
        json_error('Editor or admin access required.', 403);
    }

    $concern_id = $input['id'] ?? '';
    if (!validate_id($concern_id, 'flag_')) {
        json_error('Invalid concern ID.');
    }

    $ok = concern_dismiss($concern_id);
    if (!$ok) {
        json_error('Concern not found or already dismissed.');
    }

    json_success(['message' => 'Concern dismissed.']);
}

/**
 * Change a user's role (admin only).
 *
 * Expects: { user_id, role }
 */
function handle_change_role(): void
{
    $input = get_json_input();
    csrf_check($input['_csrf_token'] ?? '');

    $user = current_user();
    if (!$user || $user['role'] !== 'admin') {
        json_error('Admin access required.', 403);
    }

    $target_id = $input['user_id'] ?? '';
    $new_role  = $input['role'] ?? '';

    $valid_roles = ['viewer', 'contributor', 'editor', 'admin'];
    if (!in_array($new_role, $valid_roles, true)) {
        json_error('Invalid role.');
    }

    if ($target_id === $user['id']) {
        json_error('You cannot change your own role.');
    }

    $target = user_find_by_id($target_id);
    if ($target === null) {
        json_error('User not found.');
    }

    user_update($target_id, ['role' => $new_role]);
    json_success(['message' => 'Role updated to ' . $new_role . '.']);
}

/**
 * Delete a user account (admin only).
 *
 * Expects: { id }
 */
function handle_delete_user(): void
{
    $input = get_json_input();
    csrf_check($input['_csrf_token'] ?? '');

    $user = current_user();
    if (!$user || $user['role'] !== 'admin') {
        json_error('Admin access required.', 403);
    }

    $target_id = $input['id'] ?? '';
    if ($target_id === $user['id']) {
        json_error('You cannot delete your own account.');
    }

    $target = user_find_by_id($target_id);
    if ($target === null) {
        json_error('User not found.');
    }

    user_delete($target_id);
    json_success(['message' => 'User deleted.']);
}

/**
 * Apply a theme site-wide (admin only).
 *
 * Expects: { theme }
 */
function handle_apply_theme(): void
{
    $input = get_json_input();
    csrf_check($input['_csrf_token'] ?? '');

    $user = current_user();
    if (!$user || $user['role'] !== 'admin') {
        json_error('Admin access required.', 403);
    }

    $theme = trim($input['theme'] ?? '');
    if ($theme === '') {
        json_error('Theme filename is required.');
    }

    $count = theme_apply($theme);
    if ($count === -1) {
        json_error('Theme file not found.');
    }

    json_success(['message' => "Theme applied. {$count} file(s) updated.", 'count' => $count]);
}

/**
 * Save theme CSS — admin only, via form POST.
 */
function handle_save_theme_css(): void
{
    // This is a form POST, not JSON
    csrf_check($_POST['_csrf_token'] ?? '');

    $user = current_user();
    if (!$user || $user['role'] !== 'admin') {
        flash('error', 'Admin access required.');
        redirect(app_url('admin', ['tab' => 'themes']));
    }

    $theme_file = basename(trim($_POST['theme_file'] ?? ''));
    $css = $_POST['css'] ?? '';

    if ($theme_file === '' || !preg_match('/^[a-zA-Z0-9_-]+\.css$/', $theme_file)) {
        flash('error', 'Invalid theme filename.');
        redirect(app_url('admin', ['tab' => 'themes']));
    }

    $path = sw_root() . '/_themes/' . $theme_file;
    if (!file_exists($path)) {
        flash('error', 'Theme file not found.');
        redirect(app_url('admin', ['tab' => 'themes']));
    }

    atomic_write($path, $css);
    flash('success', 'Theme CSS saved.');
    redirect(app_url('admin', ['tab' => 'themes']));
}

/**
 * Rename a story — editor+ only.
 */
function handle_rename_story(): void
{
    $input = get_json_input();
    csrf_check($input['_csrf_token'] ?? '');

    $user = current_user();
    if (!$user || role_level($user['role']) < role_level('editor')) {
        json_error('Editor or admin access required.', 403);
    }

    $story_id = trim((string) ($input['story_id'] ?? ''));
    $title = normalize_story_title((string) ($input['title'] ?? ''));

    if (!validate_id($story_id, 'story_')) {
        json_error('Invalid story ID.');
    }
    if ($title === '') {
        json_error('Story title is required.');
    }

    if (story_find_root($story_id) === null) {
        json_error('Story not found.', 404);
    }
    if (story_is_announcements_archive($story_id) && ($user['role'] ?? '') !== 'admin') {
        json_error('Only admins can rename the announcements archive.', 403);
    }

    if (!story_update_title($story_id, $title, (string) ($user['id'] ?? ''))) {
        json_error('Failed to rename story.', 500);
    }

    json_success([
        'message' => 'Story title updated.',
        'title' => $title,
    ]);
}

/**
 * Set per-story theme — story initiator or admin only.
 */
function handle_set_story_theme(): void
{
    $input = get_json_input();
    csrf_check($input['_csrf_token'] ?? '');

    $user = current_user();
    if (!$user) {
        json_error('Login required.', 401);
    }

    $story_id = trim($input['story_id'] ?? '');
    $theme = trim($input['theme'] ?? '');

    if (!validate_id($story_id, 'story_')) {
        json_error('Invalid story ID.');
    }
    if ($theme !== '' && !preg_match('/^[a-zA-Z0-9_-]+\.css$/', $theme)) {
        json_error('Invalid theme filename.');
    }

    // Read the root node
    $root_id = story_find_root($story_id);
    if (!$root_id) {
        json_error('Story not found.');
    }
    $root = node_read_for_user($story_id, $root_id, $user);
    if (!$root) {
        json_error('Story not found.');
    }

    // Check permissions: must be story creator or admin
    $created_by = $root['sw_meta']['created_by'] ?? '';
    if ($created_by !== $user['id'] && $user['role'] !== 'admin') {
        json_error('Only the story creator or admin can change the theme.', 403);
    }

    // Update sw-meta in root node with story_theme
    $meta = $root['sw_meta'] ?? [];
    $meta['story_theme'] = $theme;
    node_update_meta($story_id, $root_id, $meta);

    $label = $theme === '' ? 'default (site theme)' : $theme;
    json_success(['message' => "Story theme set to {$label}."]);
}

/**
 * Update the root node's Story Guidelines text.
 *
 * Expects: { story_id, scenario_essentials, _csrf_token }
  */
function handle_update_story_scenario(): void
{
    $input = get_json_input();
    csrf_check($input['_csrf_token'] ?? '');

    $user = current_user();
    if (!$user) {
        json_error('Login required.', 401);
    }

    $story_id = trim((string) ($input['story_id'] ?? ''));
    $scenario = normalize_scenario_essentials((string) ($input['scenario_essentials'] ?? ''));

    if (!validate_id($story_id, 'story_')) {
        json_error('Invalid story ID.');
    }

    $root_id = story_find_root($story_id);
    if (!$root_id) {
        json_error('Story not found.', 404);
    }

    $root = node_read_for_user($story_id, $root_id, $user);
    if (!$root) {
        json_error('Story not found.', 404);
    }

    if (!story_user_can_edit_node($story_id, $root, $user)) {
        json_error('You do not have permission to update Story Guidelines.', 403);
    }

    $meta = $root['sw_meta'] ?? [];
    $meta['scenario_essentials'] = $scenario;
    $meta = node_meta_append_history($meta, $user['id'], 'scenario_updated');
    node_update_meta($story_id, $root_id, $meta);

    json_success([
        'message' => 'Story Guidelines updated.',
        'scenario_essentials' => $scenario,
    ]);
}

/**
 * Set story visibility.
 */
function handle_set_story_visibility(): void
{
    $input = get_json_input();
    csrf_check($input['_csrf_token'] ?? '');

    $user = current_user();
    if (!$user) {
        json_error('Login required.', 401);
    }

    $story_id = trim((string) ($input['story_id'] ?? ''));
    $visibility = trim((string) ($input['visibility'] ?? ''));
    if (!validate_id($story_id, 'story_') || !in_array($visibility, ['public', 'private'], true)) {
        json_error('Invalid story access settings.', 400);
    }

    if (!story_user_can_manage_access($story_id, $user)) {
        json_error('Only the story owner or admin can manage access.', 403);
    }

    $privacy = story_privacy_info($story_id);
    if ($privacy['root_node_id'] === null) {
        json_error('Story not found.', 404);
    }

    if (!story_update_privacy($story_id, $visibility, $privacy['shared_user_ids'], (string) $user['id'])) {
        json_error('Failed to update story access.', 500);
    }

    json_success([
        'message' => $visibility === 'private' ? 'Story is now private.' : 'Story is now public.',
        'visibility' => $visibility,
        'shared_users' => story_shared_users($privacy['shared_user_ids']),
    ]);
}

/**
 * Grant a user access to a private story by exact username.
 */
function handle_grant_story_access(): void
{
    $input = get_json_input();
    csrf_check($input['_csrf_token'] ?? '');

    $user = current_user();
    if (!$user) {
        json_error('Login required.', 401);
    }

    $story_id = trim((string) ($input['story_id'] ?? ''));
    $username = trim((string) ($input['username'] ?? ''));
    if (!validate_id($story_id, 'story_') || $username === '') {
        json_error('Story and username are required.', 400);
    }

    if (!story_user_can_manage_access($story_id, $user)) {
        json_error('Only the story owner or admin can manage access.', 403);
    }

    $target = user_find_by_username($username);
    if ($target === null) {
        json_error('No matching user was found.', 404);
    }

    $privacy = story_privacy_info($story_id);
    if ($privacy['root_node_id'] === null) {
        json_error('Story not found.', 404);
    }

    $target_id = (string) ($target['id'] ?? '');
    if ($target_id === '' || $target_id === $privacy['owner_user_id']) {
        json_error('That user already has access.', 400);
    }

    $shared = $privacy['shared_user_ids'];
    if (!in_array($target_id, $shared, true)) {
        $shared[] = $target_id;
    }

    if (!story_update_privacy($story_id, $privacy['visibility'], $shared, (string) $user['id'])) {
        json_error('Failed to grant access.', 500);
    }

    json_success([
        'message' => 'Story access granted.',
        'shared_users' => story_shared_users($shared),
    ]);
}

/**
 * Revoke a user's explicit story access.
 */
function handle_revoke_story_access(): void
{
    $input = get_json_input();
    csrf_check($input['_csrf_token'] ?? '');

    $user = current_user();
    if (!$user) {
        json_error('Login required.', 401);
    }

    $story_id = trim((string) ($input['story_id'] ?? ''));
    $target_id = trim((string) ($input['user_id'] ?? ''));
    if (!validate_id($story_id, 'story_') || !validate_id($target_id, 'usr_')) {
        json_error('Invalid story or user ID.', 400);
    }

    if (!story_user_can_manage_access($story_id, $user)) {
        json_error('Only the story owner or admin can manage access.', 403);
    }

    $privacy = story_privacy_info($story_id);
    if ($privacy['root_node_id'] === null) {
        json_error('Story not found.', 404);
    }
    if ($target_id === $privacy['owner_user_id']) {
        json_error('The story owner cannot be removed.', 400);
    }

    $shared = array_values(array_filter($privacy['shared_user_ids'], function ($id) use ($target_id) {
        return $id !== $target_id;
    }));

    if (!story_update_privacy($story_id, $privacy['visibility'], $shared, (string) $user['id'])) {
        json_error('Failed to revoke access.', 500);
    }

    json_success([
        'message' => 'Story access revoked.',
        'shared_users' => story_shared_users($shared),
    ]);
}

/**
 * Set story-level image generation preferences.
 */
function handle_set_story_image_settings(): void
{
    $input = get_json_input();
    csrf_check($input['_csrf_token'] ?? '');

    $user = current_user();
    if (!$user) {
        json_error('Login required.', 401);
    }

    $story_id = trim((string) ($input['story_id'] ?? ''));
    if (!validate_id($story_id, 'story_')) {
        json_error('Invalid story ID.', 400);
    }

    $root_id = story_find_root($story_id);
    if ($root_id === null) {
        json_error('Story not found.', 404);
    }

    $root = node_read_for_user($story_id, $root_id, $user);
    if ($root === null) {
        json_error('Story not found.', 404);
    }

    if (!story_user_can_edit_node($story_id, $root, $user)) {
        json_error('You do not have permission to update image settings for this story.', 403);
    }

    $auto_generate_images = !empty($input['auto_generate_images']);
    $image_key_id = trim((string) ($input['image_key_id'] ?? ''));
    $image_key_id = validate_id($image_key_id, 'key_') ? $image_key_id : '';
    if ($image_key_id !== '') {
        $key = api_key_find_by_id($image_key_id);
        if ($key === null || ($key['status'] ?? '') !== 'active' || empty($key['model_image'])) {
            json_error('The selected image model is not available.', 400);
        }
        if (($key_error = api_key_access_error($key, $user)) !== null) {
            json_error($key_error, 403);
        }
    }

    $guidance = trim((string) ($input['image_guidance'] ?? ''));
    $guidance_enabled = !empty($input['image_guidance_enabled']) && $guidance !== '';

    if (!story_update_image_settings($story_id, $auto_generate_images, $image_key_id, $guidance_enabled, $guidance, (string) $user['id'])) {
        json_error('Failed to update image settings.', 500);
    }

    json_success([
        'message' => 'Image settings updated.',
        'auto_generate_images' => $auto_generate_images,
        'image_guidance_enabled' => $guidance_enabled,
        'image_guidance' => $guidance,
    ]);
}

/**
 * Delete a specific image file.
 *
 * Expects: { image_url, _csrf_token }
 */
function handle_delete_image(): void
{
    $input = get_json_input();
    csrf_check($input['_csrf_token'] ?? '');

    $user = current_user();
    if (!$user) {
        json_error('Login required.', 403);
    }

    $image_url_value = trim((string) ($input['image_url'] ?? ''));
    $story_id = trim((string) ($input['story_id'] ?? ''));
    $node_id = trim((string) ($input['node_id'] ?? ''));

    if ($image_url_value === '') {
        json_error('Image URL is required.');
    }

    $query = parse_url($image_url_value, PHP_URL_QUERY);
    if (is_string($query) && $query !== '') {
        $params = [];
        parse_str($query, $params);
        $story_id = $story_id !== '' ? $story_id : (string) ($params['story'] ?? '');
        $node_id = $node_id !== '' ? $node_id : (string) ($params['node'] ?? '');
        $filename = basename((string) ($params['file'] ?? ''));
    } else {
        $filename = basename($image_url_value);
    }

    if (!validate_id($story_id, 'story_') || !validate_id($node_id, 'node_')) {
        json_error('Invalid story or page ID.', 400);
    }

    if (!preg_match('/^' . preg_quote($node_id, '/') . '-\d+-[a-f0-9]{8}\.(png|jpg|jpeg|gif|webp)$/', $filename)) {
        json_error('Invalid image filename.');
    }

    $node = node_read_for_user($story_id, $node_id, $user);
    if ($node === null) {
        json_error('Page not found.', 404);
    }

    if (!story_user_can_edit_node($story_id, $node, $user)) {
        json_error('You do not have permission to delete this image.', 403);
    }

    $path = sw_root() . '/_assets/images/' . $filename;
    $real = realpath($path);
    $images_dir = realpath(sw_root() . '/_assets/images');
    if ($real === false || $images_dir === false || !str_starts_with($real, $images_dir . DIRECTORY_SEPARATOR) || !is_file($real)) {
        json_error('Image not found.');
    }

    unlink($real);
    json_success(['message' => 'Image deleted.']);
}

/**
 * Upload an image file to a story node.
 *
 * Expects multipart/form-data with: file (image), story_id, node_id, _csrf_token.
 * Requires a logged-in user who is the node author or has editor+ role.
 */
function handle_upload_image(): void
{
    csrf_check($_POST['_csrf_token'] ?? '');

    $user = current_user();
    if (!$user) {
        json_error('You must be logged in to upload images.', 403);
    }

    $story_id = $_POST['story_id'] ?? '';
    $node_id  = $_POST['node_id'] ?? '';

    if (!validate_id($story_id, 'story_') || !validate_id($node_id, 'node_')) {
        json_error('Invalid story or page ID.', 400);
    }

    // Permission check: author of the node or editor+
    $node = node_read_for_user($story_id, $node_id, $user);
    if ($node === null) {
        json_error('Page not found.', 404);
    }
    if (!story_user_can_edit_node($story_id, $node, $user)) {
        json_error('You can only upload images to your own pages.', 403);
    }

    // Validate the uploaded file
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $code = $_FILES['file']['error'] ?? -1;
        $msg = match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File is too large.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            default => 'Upload failed (error ' . $code . ').',
        };
        json_error($msg, 400);
    }

    $tmp = $_FILES['file']['tmp_name'];
    $size = $_FILES['file']['size'];

    // Max 5 MB
    if ($size > 5 * 1024 * 1024) {
        json_error('Image must be under 5 MB.', 400);
    }

    // Verify MIME type via actual file content
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp);
    $allowed = [
        'image/png'  => 'png',
        'image/jpeg' => 'jpg',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];
    if (!isset($allowed[$mime])) {
        json_error('Only PNG, JPEG, GIF, and WebP images are allowed.', 400);
    }

    $image_data = file_get_contents($tmp);
    if ($image_data === false) {
        json_error('Failed to read uploaded file.', 500);
    }

    $extension = $allowed[$mime];
    $image_path = node_save_image($node_id, $image_data, $extension);
    json_success(['image_url' => image_url($story_id, $node_id, basename($image_path))]);
}

/**
 * Preview the prompts that would be sent to the AI for a given node.
 *
 * Admin-only. Does not call AI — just builds and returns prompts.
 *
 * Expects POST JSON: { story_id?, parent_node_id?, choice_text?, title?,
 *                      scenario_essentials?, _csrf_token }
 */
function handle_preview_prompt(): void
{
    $input = get_json_input();
    csrf_check($input['_csrf_token'] ?? '');

    $user = current_user();
    if (!$user || $user['role'] !== 'admin') {
        json_error('Admin access required.', 403);
    }

    $story_id       = trim($input['story_id'] ?? '');
    $parent_node_id = trim($input['parent_node_id'] ?? '');
    $choice_text    = trim($input['choice_text'] ?? '(example choice)');
    $key_id         = trim((string) ($input['key_id'] ?? ''));
    $title          = normalize_story_title((string) ($input['title'] ?? ''));
    $scenario       = normalize_scenario_essentials((string) ($input['scenario_essentials'] ?? ''));

    $is_opening = ($parent_node_id === '' && $title !== '');

    if (!$is_opening && ($story_id !== '' || $parent_node_id !== '')) {
        if (!validate_id($story_id, 'story_') || !validate_id($parent_node_id, 'node_')) {
            json_error('Invalid story or page ID.', 400);
        }
    }

    if ($story_id !== "" && validate_id($story_id, "story_") && !story_user_can_access($story_id, $user)) {
        json_error("Story not found.", 404);
    }

    $system_prompt = '';
    $story_context = '';
    $image_prompt  = '';
    $preview_key = null;
    $prepared_preview_key = null;

    if ($key_id !== '' && validate_id($key_id, 'key_')) {
        $preview_key = api_key_find_by_id($key_id);
        if ($preview_key === null || $preview_key['status'] !== 'active') {
            $preview_key = null;
        } elseif (($key_error = api_key_access_error($preview_key, $user)) !== null) {
            json_error($key_error, 403);
        }
    } else {
        $preview_key = api_key_select_for_user($user['id']);
    }

    if ($preview_key !== null) {
        try {
            $prepared_preview_key = api_key_prepare_for_use($preview_key);
        } catch (RuntimeException $e) {
            json_error($e->getMessage(), 500);
        }
    }

    sw_close_session();

    if ($is_opening) {
        $prompt_bundle = build_opening_prompt_bundle($title, $scenario, $user);
        $system_prompt = $prompt_bundle['system_prompt'];
        $story_context = $prompt_bundle['story_context'];
    } elseif ($story_id !== '' && $parent_node_id !== '') {
        $prompt_bundle = build_continuation_prompt_bundle($story_id, $parent_node_id, $choice_text, true, $user, $prepared_preview_key);
        $system_prompt = $prompt_bundle['system_prompt'];
        $story_context = $prompt_bundle['story_context'];

        // Use any available key to build the enriched image prompt (same logic as generation).
        $context_summary = ($preview_key !== null)
            ? build_image_context_summary($story_id, $parent_node_id, $preview_key, $user, true)
            : '';
        $image_prompt = build_image_prompt($story_id, $parent_node_id, $context_summary, true);
    }

    json_success([
        'system_prompt' => $system_prompt,
        'story_context' => $story_context,
        'image_prompt'  => $image_prompt,
    ]);
}
