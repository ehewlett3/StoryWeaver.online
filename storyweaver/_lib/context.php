<?php
/**
 * StoryWeaver — Context reconstruction (§3.3) and prompt assembly (§3.2).
 *
 * Walks the parent chain of a story node to build a narrative context
 * string for AI text generation. Applies model-aware compression when
 * the full history would exceed the selected model's usable prompt budget.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/nodes.php';
require_once __DIR__ . '/ai_settings.php';
require_once __DIR__ . '/AIProvider.php';

/** Maximum total characters for the assembled context string. */
define('MAX_CONTEXT_CHARS', 12000);
define('SCENARIO_ESSENTIALS_MAX_CHARS', 8000);
define('STORY_OPENING_MAX_CHARS', 6000);
define('PROMPT_CHARS_PER_TOKEN', 4);
define('DEFAULT_MODEL_CONTEXT_TOKENS', 32000);
define('DEFAULT_CONTEXT_OUTPUT_RESERVE_TOKENS', 2048);
define('DEFAULT_CONTEXT_MARGIN_TOKENS', 1024);
define('MIN_SUMMARIZED_CONTEXT_TOKENS', 384);
define('MAX_SUMMARY_OUTPUT_TOKENS', 1024);
define('MAX_SUMMARY_INPUT_TOKENS', 24000);

/**
 * Get the effective story-generation system prompt for the active user.
 */
function get_system_prompt(?array $user = null): string
{
    return ai_story_system_prompt($user);
}

/**
 * Read the story-wide story guidelines from the root node metadata.
 */
function story_get_scenario_essentials(string $story_id): string
{
    $root_id = story_find_root($story_id);
    if ($root_id === null) {
        return '';
    }

    $root = node_read($story_id, $root_id, true);
    if ($root === null) {
        return '';
    }

    return trim((string) (($root['sw_meta']['scenario_essentials'] ?? '')));
}

/**
 * Normalize Story Guidelines text to a safe maximum length.
 */
function normalize_scenario_essentials(string $scenario_essentials): string
{
    return trim(mb_substr($scenario_essentials, 0, SCENARIO_ESSENTIALS_MAX_CHARS));
}

/**
 * Convert optional user-provided opening text into sanitized root-node paragraphs.
 *
 * Kept as plain text input: blank lines split paragraphs, single line breaks
 * remain line breaks inside the paragraph.
 *
 * @return array<int, string>
 */
function normalize_story_opening_paragraphs(string $story_opening): array
{
    $story_opening = trim(mb_substr($story_opening, 0, STORY_OPENING_MAX_CHARS));
    if ($story_opening === '') {
        return [];
    }

    $story_opening = str_replace(["\r\n", "\r"], "\n", $story_opening);
    $blocks = preg_split("/\n\s*\n/u", $story_opening) ?: [];
    $paragraphs = [];

    foreach ($blocks as $block) {
        $block = trim($block);
        if ($block === '') {
            continue;
        }
        $paragraphs[] = sanitize_paragraph_html(nl2br(h($block), false));
    }

    return array_values(array_filter($paragraphs, static fn ($p) => trim((string) $p) !== ''));
}

/**
 * Approximate token count for budgeting prompt text without a model tokenizer.
 */
function estimate_prompt_tokens(string $text): int
{
    $length = mb_strlen($text, 'UTF-8');
    if ($length <= 0) {
        return 0;
    }

    return (int) ceil($length / PROMPT_CHARS_PER_TOKEN);
}

/**
 * Convert an approximate token budget to a character budget.
 */
function prompt_tokens_to_chars(int $tokens): int
{
    return max(0, $tokens * PROMPT_CHARS_PER_TOKEN);
}

/**
 * Trim text to an approximate token budget.
 */
function trim_text_to_token_budget(string $text, int $token_budget): string
{
    if ($token_budget <= 0) {
        return '';
    }

    return trim(mb_strimwidth($text, 0, prompt_tokens_to_chars($token_budget), '…', 'UTF-8'));
}

/**
 * Estimate the selected model's total context window in tokens.
 */
function estimate_model_context_window_tokens(?array $key_record = null): int
{
    $provider = strtolower((string) ($key_record['provider'] ?? ''));
    $model = strtolower((string) ($key_record['model_text'] ?? ''));

    if ($provider === 'anthropic' || str_contains($model, 'claude')) {
        return 200000;
    }

    if ($provider === 'gemini' || str_contains($model, 'gemini')) {
        return 128000;
    }

    if (
        str_contains($model, 'gpt-5')
        || str_contains($model, 'gpt-4.1')
        || str_contains($model, 'gpt-4o')
        || preg_match('/\bo[134]\b/', $model) === 1
    ) {
        return 128000;
    }

    if (str_contains($model, 'gpt-4')) {
        return 32000;
    }

    if (str_contains($model, 'gpt-3.5')) {
        return 16000;
    }

    if (
        $provider === 'ollama'
        || str_contains($model, 'llama')
        || str_contains($model, 'mistral')
        || str_contains($model, 'mixtral')
        || str_contains($model, 'qwen')
        || str_contains($model, 'gemma')
        || str_contains($model, 'phi')
    ) {
        return 32000;
    }

    return DEFAULT_MODEL_CONTEXT_TOKENS;
}

/**
 * Reserve output tokens so the prompt budget leaves room for the model's reply.
 */
function estimate_prompt_output_reserve_tokens(?array $user = null): int
{
    $generation_options = ai_generation_options_for_user($user);
    $reserve = (int) ($generation_options['max_tokens'] ?? 0);
    if ($reserve <= 0) {
        $reserve = DEFAULT_CONTEXT_OUTPUT_RESERVE_TOKENS;
    }

    return max(512, $reserve);
}

/**
 * Resolve the available token budget for the story-context user message.
 */
function resolve_story_context_budget_tokens(string $system_prompt, ?array $key_record = null, ?array $user = null): int
{
    $context_window = estimate_model_context_window_tokens($key_record);
    $system_tokens = estimate_prompt_tokens($system_prompt);
    $output_reserve = min(
        estimate_prompt_output_reserve_tokens($user),
        max(1024, (int) floor($context_window * 0.25))
    );

    $budget = $context_window - $system_tokens - $output_reserve - DEFAULT_CONTEXT_MARGIN_TOKENS;
    return max(2048, $budget);
}

/**
 * Format one reconstructed context entry.
 */
function format_context_entry(array $entry): string
{
    $parts = [];
    if (($entry['choice_taken'] ?? '') !== '') {
        $parts[] = '> Player chose: ' . $entry['choice_taken'];
    }
    if (($entry['paragraphs'] ?? '') !== '') {
        $parts[] = $entry['paragraphs'];
    }

    return trim(implode("\n", $parts));
}

/**
 * Render context entries to chronological plain text blocks.
 *
 * @param array<int, array{paragraphs:string, choice_taken:string}> $entries
 */
function render_context_entries_text(array $entries): string
{
    $blocks = [];
    foreach ($entries as $entry) {
        $block = format_context_entry($entry);
        if ($block !== '') {
            $blocks[] = $block;
        }
    }

    return implode("\n\n", $blocks);
}

/**
 * Split context entries into chronological chunks that fit an approximate input budget.
 *
 * @param array<int, array{paragraphs:string, choice_taken:string}> $entries
 * @return array<int, array<int, array{paragraphs:string, choice_taken:string}>>
 */
function split_context_entries_into_chunks(array $entries, int $max_tokens): array
{
    $chunks = [];
    $current_chunk = [];
    $current_tokens = 0;

    foreach ($entries as $entry) {
        $entry_tokens = estimate_prompt_tokens(format_context_entry($entry)) + 12;
        if (!empty($current_chunk) && ($current_tokens + $entry_tokens) > $max_tokens) {
            $chunks[] = $current_chunk;
            $current_chunk = [];
            $current_tokens = 0;
        }

        $current_chunk[] = $entry;
        $current_tokens += $entry_tokens;
    }

    if (!empty($current_chunk)) {
        $chunks[] = $current_chunk;
    }

    return $chunks;
}

/**
 * Deterministically compress older history when AI summarization is unavailable.
 *
 * @param array<int, array{paragraphs:string, choice_taken:string}> $entries
 */
function fallback_story_history_summary(array $entries, int $summary_budget_tokens): string
{
    $segments = [];
    foreach ($entries as $entry) {
        if (($entry['choice_taken'] ?? '') !== '') {
            $segments[] = 'Player chose: ' . $entry['choice_taken'] . '.';
        }

        if (($entry['paragraphs'] ?? '') !== '') {
            $segments[] = trim(mb_strimwidth($entry['paragraphs'], 0, 260, '…', 'UTF-8'));
        }
    }

    return trim_text_to_token_budget(implode("\n", $segments), $summary_budget_tokens);
}

/**
 * Summarize one chronological history chunk using the selected text model.
 *
 * @param array<int, array{paragraphs:string, choice_taken:string}> $entries
 */
function summarize_story_history_chunk(array $entries, array $key_record, int $summary_budget_tokens): string
{
    $context_text = render_context_entries_text($entries);
    if ($context_text === '') {
        return '';
    }

    $system = 'You are a continuity assistant for a branching interactive fiction story.';
    $prompt = "Here is the earlier part of a story in chronological order:\n\n{$context_text}\n\n"
        . "Write a concise chronological summary for future continuation. Preserve key names, relationships, locations, discoveries, promises, injuries, items, ongoing goals, unresolved threats, and lasting consequences. "
        . "Keep the sequence of events clear. Output plain prose only.";

    $provider = new AIProvider($key_record, [
        'temperature' => 0.0,
        'top_p' => 1.0,
        'max_tokens' => min(MAX_SUMMARY_OUTPUT_TOKENS, max(256, $summary_budget_tokens)),
    ]);

    $summary = trim($provider->generateText($system, $prompt));
    return trim_text_to_token_budget($summary, $summary_budget_tokens);
}

/**
 * Build a chronological summary of older story history, using AI first and a deterministic fallback if needed.
 *
 * @param array<int, array{paragraphs:string, choice_taken:string}> $entries
 */
function build_story_history_summary(array $entries, int $summary_budget_tokens, ?array $key_record = null): string
{
    if (empty($entries) || $summary_budget_tokens <= 0) {
        return '';
    }

    if ($key_record === null || empty($key_record['model_text'])) {
        return fallback_story_history_summary($entries, $summary_budget_tokens);
    }

    $input_chunk_tokens = min(
        MAX_SUMMARY_INPUT_TOKENS,
        max(4000, (int) floor(estimate_model_context_window_tokens($key_record) * 0.35))
    );
    $chunks = split_context_entries_into_chunks($entries, $input_chunk_tokens);
    $chunk_summaries = [];

    foreach ($chunks as $chunk) {
        try {
            $chunk_summaries[] = summarize_story_history_chunk(
                $chunk,
                $key_record,
                min(MAX_SUMMARY_OUTPUT_TOKENS, max(256, (int) floor($summary_budget_tokens / max(1, count($chunks)))))
            );
        } catch (RuntimeException $e) {
            $chunk_summaries[] = fallback_story_history_summary($chunk, max(192, (int) floor($summary_budget_tokens / max(1, count($chunks)))));
        }
    }

    $chunk_summaries = array_values(array_filter(array_map('trim', $chunk_summaries), static function ($summary) {
        return $summary !== '';
    }));

    if (empty($chunk_summaries)) {
        return fallback_story_history_summary($entries, $summary_budget_tokens);
    }

    if (count($chunk_summaries) === 1) {
        return trim_text_to_token_budget($chunk_summaries[0], $summary_budget_tokens);
    }

    $combined_entries = [];
    foreach ($chunk_summaries as $summary) {
        $combined_entries[] = [
            'choice_taken' => '',
            'paragraphs' => $summary,
        ];
    }

    try {
        return summarize_story_history_chunk($combined_entries, $key_record, $summary_budget_tokens);
    } catch (RuntimeException $e) {
        return fallback_story_history_summary($combined_entries, $summary_budget_tokens);
    }
}

/**
 * Reconstruct the narrative context by walking the parent chain (§3.3).
 *
 * Reads each ancestor node's paragraphs and the choice that was taken
 * to reach the next node. Returns entries ordered oldest-to-newest.
 *
 * @param string $story_id          The story ID.
 * @param string $node_id           The current node ID (starting point).
 * @param bool   $check_quarantine  Also read nodes from quarantine when needed.
 * @return array Array of context entries, each: ['paragraphs' => string, 'choice_taken' => string]
 */
function reconstruct_context(string $story_id, string $node_id, bool $check_quarantine = false): array
{
    $entries = [];
    $current_id = $node_id;
    $visited = []; // cycle protection

    while ($current_id !== '') {
        if (isset($visited[$current_id])) {
            break; // prevent infinite loops
        }
        $visited[$current_id] = true;

        $node = node_read($story_id, $current_id, $check_quarantine);
        if ($node === null) {
            break;
        }

        // Build paragraph text from the node's paragraphs
        $para_text = '';
        foreach ($node['paragraphs'] as $p) {
            $clean = rich_html_to_text((string) $p);
            $para_text .= trim($clean) . "\n";
        }

        $entries[] = [
            'paragraphs'   => trim($para_text),
            'choice_taken' => $node['choice_taken'] ?? '',
        ];

        $current_id = $node['parent_id'] ?? '';
    }

    // Reverse so oldest is first
    return array_reverse($entries);
}

/**
 * Truncate context entries from the oldest end to fit within a character limit.
 *
 * Never truncates the most recent 2 entries (§3.3).
 *
 * @param array $entries    Context entries from reconstruct_context().
 * @param int   $max_chars  Maximum total characters (default: MAX_CONTEXT_CHARS).
 * @return array Truncated entries array.
 */
function truncate_context(array $entries, int $max_chars = MAX_CONTEXT_CHARS): array
{
    $total = 0;
    foreach ($entries as $e) {
        $total += strlen($e['paragraphs']) + strlen($e['choice_taken']) + 20;
    }

    if ($total <= $max_chars) {
        return $entries;
    }

    // Protect the most recent 2 entries
    $protected = min(2, count($entries));

    while (count($entries) > $protected && $total > $max_chars) {
        $removed = array_shift($entries);
        $total -= strlen($removed['paragraphs']) + strlen($removed['choice_taken']) + 20;
    }

    return $entries;
}

/**
 * Format context entries into the user message string for the AI (§3.2).
 *
 * @param array  $entries     Context entries (oldest to newest).
 * @param string $choice_text The choice the player just made.
 * @param string $scenario_essentials Optional story guidelines carried forward from the root node.
 * @return string The assembled user message.
 */
function build_story_prompt(
    array $entries,
    string $choice_text,
    string $scenario_essentials = '',
    string $history_summary = ''
): string
{
    $parts = [];

    if ($scenario_essentials !== '') {
        $parts[] = "[SCENARIO ESSENTIALS]";
        $parts[] = $scenario_essentials;
        $parts[] = "";
    }

    if ($history_summary !== '') {
        $parts[] = "[EARLIER STORY SUMMARY]";
        $parts[] = $history_summary;
        $parts[] = "";
    }

    $parts[] = "[STORY CONTEXT — oldest to newest]";

    foreach ($entries as $entry) {
        $entry_text = format_context_entry($entry);
        if ($entry_text !== '') {
            $parts[] = $entry_text;
        }
    }

    $parts[] = "";
    $parts[] = "> Player chose: " . $choice_text;

    return implode("\n", $parts);
}

/**
 * Build the current-story prompt for pending-choice regeneration.
 *
 * @param array<int, array{paragraphs:string, choice_taken:string}> $entries
 * @param array<int, string> $locked_choices
 */
function build_pending_choices_prompt(
    array $entries,
    string $scenario_essentials,
    int $pending_choice_count,
    array $locked_choices = [],
    string $history_summary = ''
): string {
    $parts = [];
    if ($scenario_essentials !== '') {
        $parts[] = "[SCENARIO ESSENTIALS]";
        $parts[] = $scenario_essentials;
        $parts[] = '';
    }

    if ($history_summary !== '') {
        $parts[] = "[EARLIER STORY SUMMARY]";
        $parts[] = $history_summary;
        $parts[] = '';
    }

    $parts[] = "[CURRENT STORY CONTEXT — oldest to newest]";
    foreach ($entries as $entry) {
        $entry_text = format_context_entry($entry);
        if ($entry_text !== '') {
            $parts[] = $entry_text;
        }
    }

    $parts[] = '';
    $parts[] = "[TASK]";
    $parts[] = "Generate exactly {$pending_choice_count} new pending choices for what could happen next from the current page.";
    if (!empty($locked_choices)) {
        $parts[] = "These existing choices are already linked to child pages and must remain distinct:";
        foreach ($locked_choices as $choice_text) {
            $parts[] = "- " . $choice_text;
        }
    }
    $parts[] = "Do not repeat or closely paraphrase any locked choice.";

    return implode("\n", $parts);
}

/**
 * Compress reconstructed entries so the final prompt fits the selected model budget.
 *
 * @param array<int, array{paragraphs:string, choice_taken:string}> $entries
 * @param callable(array<int, array{paragraphs:string, choice_taken:string}>, string): string $prompt_builder
 * @return array{entries: array<int, array{paragraphs:string, choice_taken:string}>, history_summary: string}
 */
function compress_story_history_for_prompt(
    array $entries,
    int $story_budget_tokens,
    callable $prompt_builder,
    ?array $key_record = null
): array {
    $full_prompt = $prompt_builder($entries, '');
    if (estimate_prompt_tokens($full_prompt) <= $story_budget_tokens) {
        return [
            'entries' => $entries,
            'history_summary' => '',
        ];
    }

    $summary_heading_tokens = estimate_prompt_tokens("[EARLIER STORY SUMMARY]\n");
    $split_index = 0;

    while ($split_index <= count($entries)) {
        $older_entries = array_slice($entries, 0, $split_index);
        $recent_entries = array_slice($entries, $split_index);

        if (empty($older_entries)) {
            if (estimate_prompt_tokens($prompt_builder($recent_entries, '')) <= $story_budget_tokens) {
                return [
                    'entries' => $recent_entries,
                    'history_summary' => '',
                ];
            }
        } else {
            $base_tokens = estimate_prompt_tokens($prompt_builder($recent_entries, ''));
            $summary_budget_tokens = $story_budget_tokens - $base_tokens - $summary_heading_tokens;
            if ($summary_budget_tokens >= MIN_SUMMARIZED_CONTEXT_TOKENS) {
                $history_summary = build_story_history_summary($older_entries, $summary_budget_tokens, $key_record);
                $history_summary = trim_text_to_token_budget($history_summary, $summary_budget_tokens);
                return [
                    'entries' => $recent_entries,
                    'history_summary' => $history_summary,
                ];
            }
        }

        $split_index++;
    }

    return [
        'entries' => [],
        'history_summary' => build_story_history_summary($entries, max(MIN_SUMMARIZED_CONTEXT_TOKENS, $story_budget_tokens - $summary_heading_tokens), $key_record),
    ];
}

/**
 * Build the exact prompt payload for generating an opening node.
 *
 * @return array{scenario_essentials: string, system_prompt: string, story_context: string}
 */
function build_opening_prompt_bundle(string $title, string $scenario_essentials = '', ?array $user = null, string $story_opening = ''): array
{
    return [
        'scenario_essentials' => $scenario_essentials,
        'system_prompt'       => get_system_prompt($user),
        'story_context'       => build_opening_prompt($title, $scenario_essentials, $story_opening),
    ];
}

/**
 * Build the exact prompt payload for generating a continuation node.
 *
 * @param bool $check_quarantine Also read quarantined nodes when building context.
 * @return array{scenario_essentials: string, system_prompt: string, story_context: string}
 */
function build_continuation_prompt_bundle(
    string $story_id,
    string $parent_node_id,
    string $choice_text,
    bool $check_quarantine = false,
    ?array $user = null,
    ?array $key_record = null
): array
{
    $scenario_essentials = story_get_scenario_essentials($story_id);
    $system_prompt = get_system_prompt($user);
    $entries = reconstruct_context($story_id, $parent_node_id, $check_quarantine);
    $compressed = compress_story_history_for_prompt(
        $entries,
        resolve_story_context_budget_tokens($system_prompt, $key_record, $user),
        static function (array $prompt_entries, string $history_summary) use ($choice_text, $scenario_essentials): string {
            return build_story_prompt($prompt_entries, $choice_text, $scenario_essentials, $history_summary);
        },
        $key_record
    );

    return [
        'scenario_essentials' => $scenario_essentials,
        'system_prompt'       => $system_prompt,
        'story_context'       => build_story_prompt(
            $compressed['entries'],
            $choice_text,
            $scenario_essentials,
            $compressed['history_summary']
        ),
    ];
}

/**
 * Build the exact prompt payload for regenerating only a node's pending choices.
 *
 * @param array<int, string> $locked_choices Choices that already link to child pages and must stay untouched.
 * @return array{scenario_essentials: string, system_prompt: string, story_context: string}
 */
function build_pending_choices_prompt_bundle(
    string $story_id,
    string $node_id,
    int $pending_choice_count,
    array $locked_choices = [],
    bool $check_quarantine = false,
    ?array $user = null,
    ?array $key_record = null
): array
{
    $scenario_essentials = story_get_scenario_essentials($story_id);
    $system_prompt = get_pending_choices_system_prompt($pending_choice_count);
    $entries = reconstruct_context($story_id, $node_id, $check_quarantine);
    $compressed = compress_story_history_for_prompt(
        $entries,
        resolve_story_context_budget_tokens($system_prompt, $key_record, $user),
        static function (array $prompt_entries, string $history_summary) use ($scenario_essentials, $pending_choice_count, $locked_choices): string {
            return build_pending_choices_prompt(
                $prompt_entries,
                $scenario_essentials,
                $pending_choice_count,
                $locked_choices,
                $history_summary
            );
        },
        $key_record
    );

    return [
        'scenario_essentials' => $scenario_essentials,
        'system_prompt' => $system_prompt,
        'story_context' => build_pending_choices_prompt(
            $compressed['entries'],
            $scenario_essentials,
            $pending_choice_count,
            $locked_choices,
            $compressed['history_summary']
        ),
    ];
}

/**
 * Build the initial prompt for a brand-new story's first node.
 *
 * @param string $title              Story title.
 * @param string $scenario_essentials Optional scenario description.
 * @return string The user message for the opening node.
 */
function build_opening_prompt(string $title, string $scenario_essentials = '', string $story_opening = ''): string
{
    $prompt = "Begin a new choose-your-own-adventure story titled \"" . $title . "\".";

    if ($scenario_essentials !== '') {
        $prompt .= "\n\nStory guidelines: " . $scenario_essentials;
    }

    $story_opening = trim($story_opening);
    if ($story_opening !== '') {
        $prompt .= "\n\nThe user has already written this exact opening text for the first page. Continue naturally after it without repeating it:\n"
            . $story_opening;
    }

    $prompt .= "\n\nWrite the opening scene. Set the atmosphere, introduce the protagonist's situation, and present the first set of choices.";

    return $prompt;
}

/**
 * System prompt for regenerating pending choices only.
 */
function get_pending_choices_system_prompt(int $choice_count): string
{
    return <<<PROMPT
You are a collaborative storytelling engine for a choose-your-own-adventure game.
Always respond with ONLY valid JSON matching this exact schema — no markdown fences, no extra keys, no preamble:

{
  "choices": [
    {"id": 1, "text": "<short action phrase>"}
  ]
}

Rules:
- Return exactly {$choice_count} choices.
- Each choice: 4–12 words, active voice, no punctuation at end.
- Choices must fit the current scene and suggest meaningful next actions.
- Do not repeat or closely paraphrase any locked existing choice listed by the user.
- Never break the JSON schema.
PROMPT;
}

/**
 * Parse AI response text into structured data (paragraphs + choices).
 *
 * Handles markdown fences, preamble text, and common JSON issues.
 *
 * @param string $raw_response The raw AI response text.
 * @return array|null Parsed data ['paragraphs' => array, 'choices' => array], or null on failure.
 */
function parse_ai_response(string $raw_response): ?array
{
    $text = trim($raw_response);

    // Strip markdown code fences if present (greedy: match outermost fences)
    if (preg_match('/```(?:json)?\s*([\s\S]*)\s*```/', $text, $m)) {
        $text = trim($m[1]);
    }

    // Try to find JSON object if there's preamble text
    if ($text !== '' && $text[0] !== '{') {
        $brace = strpos($text, '{');
        if ($brace !== false) {
            $text = substr($text, $brace);
        }
    }

    $data = json_decode($text, true);
    if (!is_array($data)) {
        return null;
    }

    // Validate required fields
    if (!isset($data['paragraphs']) || !is_array($data['paragraphs'])) {
        return null;
    }

    // Normalize choices
    $choices = [];
    if (isset($data['choices']) && is_array($data['choices'])) {
        foreach ($data['choices'] as $i => $c) {
            $choices[] = [
                'id'   => $c['id'] ?? ($i + 1),
                'text' => $c['text'] ?? '',
                'node' => null,
            ];
        }
    }

    return [
        'paragraphs' => array_values(array_filter($data['paragraphs'], 'is_string')),
        'choices'     => $choices,
        'ending'      => !empty($data['ending']),
    ];
}

/**
 * Parse an AI response that should contain only choices JSON.
 *
 * @return array<int, string>|null
 */
function parse_ai_choices_response(string $raw_response, int $expected_count = 0): ?array
{
    $text = trim($raw_response);

    if (preg_match('/```(?:json)?\s*([\s\S]*)\s*```/', $text, $m)) {
        $text = trim($m[1]);
    }

    if ($text !== '' && $text[0] !== '{') {
        $brace = strpos($text, '{');
        if ($brace !== false) {
            $text = substr($text, $brace);
        }
    }

    $data = json_decode($text, true);
    if (!is_array($data) || !isset($data['choices']) || !is_array($data['choices'])) {
        return null;
    }

    $choices = [];
    foreach ($data['choices'] as $choice) {
        $choice_text = $choice['text'] ?? null;
        if (is_string($choice_text)) {
            $choices[] = $choice_text;
        }
    }

    if ($expected_count > 0 && count($choices) !== $expected_count) {
        return null;
    }

    return $choices;
}

/**
 * Repair prompt: sent on first JSON parse failure to ask the AI to fix its output.
 *
 * @param string $broken_response The response that failed to parse.
 * @return string A user message asking for corrected JSON.
 */
function build_repair_prompt(string $broken_response): string
{
    return "Your previous response was not valid JSON. Here is what you sent:\n\n"
         . mb_substr($broken_response, 0, 2000) . "\n\n"
         . "Please respond with ONLY valid JSON matching the required schema. "
         . "No markdown fences, no extra text, no preamble.";
}

/**
 * Build an image generation prompt from a node's content.
 *
 * Uses all paragraphs of the node as the scene description. If a
 * $context_summary is provided (generated by the text AI from prior nodes),
 * it is prepended to give the image model visual continuity across scenes.
 *
 * @param string $story_id          The story ID.
 * @param string $node_id           The node ID to illustrate.
 * @param string $context_summary   Optional AI-generated visual summary of prior nodes.
 * @param bool   $check_quarantine  Also read quarantined nodes when needed.
 * @return string The image prompt.
 */
function build_image_prompt(string $story_id, string $node_id, string $context_summary = '', bool $check_quarantine = false): string
{
    $node = node_read($story_id, $node_id, $check_quarantine);
    if ($node === null || empty($node['paragraphs'])) {
        return 'A scene from an interactive story.';
    }

    // Use all paragraphs for the richest scene description
    $text = implode(' ', array_map('strip_tags', $node['paragraphs']));
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    $text = mb_substr($text, 0, 2000);

    // Get story title for context
    $title = story_get_title($story_id);

    $scene = '';
    if ($context_summary !== '') {
        $scene = "Story context: {$context_summary} Current scene: {$text}";
    } else {
        $scene = $text;
    }

    return "Create an illustration for a scene in a story called \"{$title}\". "
         . "The scene: {$scene}\n\n"
         . "Style: Rich, atmospheric digital illustration suitable for an interactive fiction story. "
         . "No text or UI elements in the image.";
}
