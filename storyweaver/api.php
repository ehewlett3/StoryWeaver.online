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
if (!in_array($action, ['save_theme_css', 'stream_generate_node'], true)) {
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
    case 'generate_node':
        handle_generate_node();
        break;
    case 'stream_generate_node':
        handle_stream_generate_node();
        break;
    case 'test_api_key':
        handle_test_api_key();
        break;
    case 'save_api_key':
        handle_save_api_key();
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
    case 'generate_image':
        handle_generate_image();
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
    case 'delete_image':
        handle_delete_image();
        break;
    case 'upload_image':
        handle_upload_image();
        break;
    default:
        json_error('Unknown action.', 400);
}

/* ======================================================================
 * ACTION HANDLERS
 * ====================================================================*/

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

    // Read the existing node to check permissions (including quarantine for editors)
    $check_quarantine = role_level($user['role']) >= role_level('editor');
    $node = node_read($story_id, $node_id, $check_quarantine);
    if ($node === null) {
        json_error('Node not found.', 404);
    }

    // Permission check: author can edit own, editor+ can edit any
    $is_author = ($node['author_id'] === $user['id']);
    $is_editor = (role_level($user['role']) >= role_level('editor'));

    if (!$is_author && !$is_editor) {
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

    // Log the edit in metadata
    node_meta_log($story_id, $node_id, $user['id'], 'edited');

    json_success(['message' => 'Node text saved.']);
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
    $scenario       = trim($input['scenario_essentials'] ?? '');
    $title          = trim($input['title'] ?? '');
    $key_id         = trim($input['key_id'] ?? '');

    // Determine the user for key selection
    $user = current_user();
    $user_id = $user ? $user['id'] : null;
    $author_id = $user ? $user['id'] : 'anonymous';

    // Select API key (optionally by specific key_id)
    if ($key_id !== '' && validate_id($key_id, 'key_')) {
        $key_record = api_key_find_by_id($key_id);
        if ($key_record === null || $key_record['status'] !== 'active') {
            $key_record = null;
        }
    } else {
        $key_record = api_key_select_for_user($user_id);
    }
    if ($key_record === null) {
        json_error('No AI key available. Add one in Settings or write manually.', 400);
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
    }

    // Build prompts
    $system_prompt = get_system_prompt($scenario);

    if ($is_opening) {
        $user_message = build_opening_prompt($title, $scenario);
    } else {
        // Reconstruct context from parent chain
        $entries = reconstruct_context($story_id, $parent_node_id);
        $entries = truncate_context($entries);
        $user_message = build_story_prompt($entries, $choice_text);
    }

    // Call AI with retry on parse failure
    $provider = new AIProvider($key_record);
    $parsed = null;
    $last_error = '';

    for ($attempt = 0; $attempt < 2; $attempt++) {
        try {
            if ($attempt === 0) {
                $raw_response = $provider->generateText($system_prompt, $user_message);
            } else {
                // Retry with repair prompt
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

            // On auth failure, mark key unavailable immediately
            if (str_contains($last_error, 'Authentication failed')) {
                api_key_mark_unavailable($key_record['id'], $last_error);
                json_error('API key authentication failed and has been deactivated. ' . $last_error, 401);
            }
            break;
        }
    }

    if ($parsed === null) {
        // Mark key unavailable after repeated failure
        api_key_mark_unavailable($key_record['id'], $last_error);
        json_error('AI generation failed: ' . $last_error, 502);
    }

    // Sanitize paragraphs
    $paragraphs = [];
    foreach ($parsed['paragraphs'] as $p) {
        $paragraphs[] = sanitize_paragraph_html($p);
    }
    if (empty($paragraphs)) {
        $paragraphs = ['The story continues…'];
    }

    // Build choices (all pending — node is null)
    $choices = $parsed['choices'] ?? [];

    if ($is_opening) {
        // Create new story + root node
        $result = story_create(h($title), $author_id, $paragraphs);
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
        ]);

        // Link parent's choice to the new child
        node_link_choice($story_id, $parent_node_id, $choice_text, $node_id);
    }

    $base = base_url();
    json_success([
        'story_id' => $story_id,
        'node_id'  => $node_id,
        'url'      => $base . '/node.php?story=' . urlencode($story_id) . '&id=' . urlencode($node_id),
        'ending'   => $parsed['ending'] ?? false,
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
    $scenario       = trim($input['scenario_essentials'] ?? '');
    $title          = trim($input['title'] ?? '');
    $key_id         = trim($input['key_id'] ?? '');

    $user = current_user();
    $user_id = $user ? $user['id'] : null;
    $author_id = $user ? $user['id'] : 'anonymous';

    // Select API key (optionally by specific key_id)
    if ($key_id !== '' && validate_id($key_id, 'key_')) {
        $key_record = api_key_find_by_id($key_id);
        if ($key_record === null || $key_record['status'] !== 'active') {
            $key_record = null;
        }
    } else {
        $key_record = api_key_select_for_user($user_id);
    }

    if ($key_record === null) {
        sse_event('error', ['error' => 'No AI key available.']);
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
    }

    $system_prompt = get_system_prompt($scenario);

    if ($is_opening) {
        $user_message = build_opening_prompt($title, $scenario);
    } else {
        $entries = reconstruct_context($story_id, $parent_node_id);
        $entries = truncate_context($entries);
        $user_message = build_story_prompt($entries, $choice_text);
    }

    $provider = new AIProvider($key_record);

    try {
        $raw_response = $provider->generateTextStream($system_prompt, $user_message, function (string $chunk) {
            // Send raw text (no JSON wrapper) for efficient streaming display
            echo "event: token\ndata: " . str_replace("\n", "\ndata: ", $chunk) . "\n\n";
            flush();
        });
    } catch (RuntimeException $e) {
        if (str_contains($e->getMessage(), 'Authentication failed')) {
            api_key_mark_unavailable($key_record['id'], $e->getMessage());
        }
        sse_event('error', ['error' => $e->getMessage()]);
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
            // Ignore retry failure
        }
    }

    if ($parsed === null) {
        api_key_mark_unavailable($key_record['id'], 'JSON parse failure after retry');
        sse_event('error', ['error' => 'AI response could not be parsed.']);
        exit;
    }

    // Sanitize paragraphs
    $paragraphs = [];
    foreach ($parsed['paragraphs'] as $p) {
        $paragraphs[] = sanitize_paragraph_html($p);
    }
    if (empty($paragraphs)) {
        $paragraphs = ['The story continues…'];
    }

    $choices = $parsed['choices'] ?? [];

    // AI metadata from the key used
    $ai_meta = [
        'ai_model'     => $key_record['model_text'] ?? '',
        'ai_provider'  => $key_record['provider'] ?? '',
        'ai_key_label' => $key_record['label'] ?? '',
    ];

    if ($is_opening) {
        $result = story_create(h($title), $author_id, $paragraphs, $ai_meta);
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
        ], $ai_meta));
        node_link_choice($story_id, $parent_node_id, $choice_text, $node_id);
    }

    $base = base_url();
    sse_event('done', [
        'ok'         => true,
        'story_id'   => $story_id,
        'node_id'    => $node_id,
        'url'        => $base . '/node.php?story=' . urlencode($story_id) . '&id=' . urlencode($node_id),
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

    $provider = new AIProvider($key_record);
    $result = $provider->testConnection();

    json_success($result);
}

/**
 * Save (create) a new API key.
 *
 * Expects POST JSON: { label, provider, base_url, api_key, model_text, model_image, scope, _csrf_token }
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

    $label      = trim($input['label'] ?? '');
    $provider   = trim($input['provider'] ?? '');
    $base_url   = trim($input['base_url'] ?? '');
    $api_key    = trim($input['api_key'] ?? '');
    $model_text = trim($input['model_text'] ?? '');
    $model_image = trim($input['model_image'] ?? '');
    $scope      = trim($input['scope'] ?? 'self');

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

    if (!in_array($scope, ['self', 'all'], true)) {
        $scope = 'self';
    }

    $record = api_key_create([
        'owner_user_id' => $user['id'],
        'label'         => $label,
        'provider'      => $provider,
        'base_url'      => $base_url,
        'api_key'       => $api_key,
        'model_text'    => $model_text,
        'model_image'   => $model_image,
        'scope'         => $scope,
    ]);

    json_success(['key' => array_diff_key($record, ['api_key' => 1]), 'message' => 'API key saved.']);
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

    // Select API key
    if ($key_id !== '' && validate_id($key_id, 'key_')) {
        $key_record = api_key_find_by_id($key_id);
        if ($key_record === null || $key_record['status'] !== 'active') {
            $key_record = null;
        }
    } else {
        $key_record = api_key_select_for_user($user_id);
    }

    if ($key_record === null) {
        json_error('No AI key available.');
    }

    if (empty($key_record['model_image'])) {
        json_error('Selected key has no image model configured.');
    }

    $prompt = build_image_prompt($story_id, $node_id);
    $provider = new AIProvider($key_record);

    try {
        $image_data = $provider->generateImage($prompt);
        $image_url = node_save_image($node_id, $image_data);
        $base = base_url();
        json_success(['image_url' => $base . $image_url]);
    } catch (RuntimeException $e) {
        // Mark key unavailable on auth errors
        $msg = $e->getMessage();
        if (str_contains($msg, 'HTTP 401') || str_contains($msg, 'HTTP 403')) {
            api_key_mark_unavailable($key_record['id']);
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
        'redirect' => base_url() . '/index.php',
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
        flash('Admin access required.', 'error');
        redirect(base_url() . '/admin.php?tab=themes');
    }

    $theme_file = basename(trim($_POST['theme_file'] ?? ''));
    $css = $_POST['css'] ?? '';

    if ($theme_file === '' || !preg_match('/^[a-zA-Z0-9_-]+\.css$/', $theme_file)) {
        flash('Invalid theme filename.', 'error');
        redirect(base_url() . '/admin.php?tab=themes');
    }

    $path = sw_root() . '/_themes/' . $theme_file;
    if (!file_exists($path)) {
        flash('Theme file not found.', 'error');
        redirect(base_url() . '/admin.php?tab=themes');
    }

    atomic_write($path, $css);
    flash('Theme CSS saved.', 'success');
    redirect(base_url() . '/admin.php?tab=themes');
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
    $root = node_read($story_id, $root_id);
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
 * Delete a specific image file.
 *
 * Expects: { image_url, _csrf_token }
 */
function handle_delete_image(): void
{
    $input = get_json_input();
    csrf_check($input['_csrf_token'] ?? '');

    $user = current_user();
    if (!$user || role_level($user['role']) < role_level('editor')) {
        json_error('Editor or admin access required.', 403);
    }

    $image_url = trim($input['image_url'] ?? '');
    if ($image_url === '') {
        json_error('Image URL is required.');
    }

    // Extract just the filename and validate it
    $filename = basename($image_url);
    if (!preg_match('/^node_[a-f0-9]{8}-\d+-[a-f0-9]{8}\.(png|jpg|jpeg|gif|webp)$/', $filename)) {
        json_error('Invalid image filename.');
    }

    $path = sw_root() . '/_assets/images/' . $filename;
    if (!file_exists($path)) {
        json_error('Image not found.');
    }

    unlink($path);
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
    $node = node_read($story_id, $node_id);
    if ($node === null) {
        json_error('Page not found.', 404);
    }
    $is_author = ($node['author_id'] === $user['id']);
    $is_editor = (role_level($user['role']) >= role_level('editor'));
    if (!$is_author && !$is_editor) {
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
    $image_url = node_save_image($node_id, $image_data, $extension);
    $base = base_url();
    json_success(['image_url' => $base . $image_url]);
}
