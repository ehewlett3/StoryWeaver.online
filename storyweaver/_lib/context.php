<?php
/**
 * StoryWeaver — Context reconstruction (§3.3) and prompt assembly (§3.2).
 *
 * Walks the parent chain of a story node to build a narrative context
 * string for AI text generation. Handles truncation from the oldest
 * end when context exceeds MAX_CONTEXT_CHARS.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/nodes.php';

/** Maximum total characters for the assembled context string. */
define('MAX_CONTEXT_CHARS', 12000);
define('SCENARIO_ESSENTIALS_MAX_CHARS', 4000);

/**
 * The verbatim system prompt from §3.2.
 *
 * @param string $scenario_essentials Optional scenario description to prepend.
 * @return string The full system prompt.
 */
function get_system_prompt(string $scenario_essentials = ''): string
{
    $prompt = <<<'PROMPT'
You are a collaborative storytelling engine for a choose-your-own-adventure game.
Always respond with ONLY valid JSON matching this exact schema — no markdown fences, no extra keys, no preamble:

{
  "paragraphs": ["<paragraph 1>", "<paragraph 2>"],
  "choices": [
    {"id": 1, "text": "<short action phrase>"},
    {"id": 2, "text": "<short action phrase>"},
    {"id": 3, "text": "<short action phrase>"}
  ]
}

Rules:
- Exactly 2 paragraphs unless the user has explicitly requested more or fewer.
- Exactly 3 choices unless the last node is a story ending (then 0 choices and add "ending": true).
- Each paragraph: 3–6 sentences of vivid, present-tense narrative.
- Each choice: 4–12 words, active voice, no punctuation at end
- Never include the player's previous choice as an available choice again.
- Never break the JSON schema.
PROMPT;

    if ($scenario_essentials !== '') {
        $prompt = "Scenario: " . $scenario_essentials . "\n\n" . $prompt;
    }

    return $prompt;
}

/**
 * Read the story-wide scenario essentials from the root node metadata.
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
 * Normalize Scenario Essentials text to a safe maximum length.
 */
function normalize_scenario_essentials(string $scenario_essentials): string
{
    return trim(mb_substr($scenario_essentials, 0, SCENARIO_ESSENTIALS_MAX_CHARS));
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
            // Strip HTML tags for the context string
            $clean = strip_tags($p);
            $clean = html_entity_decode($clean, ENT_QUOTES, 'UTF-8');
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
 * @param string $scenario_essentials Optional scenario essentials carried forward from the root node.
 * @return string The assembled user message.
 */
function build_story_prompt(array $entries, string $choice_text, string $scenario_essentials = ''): string
{
    $parts = [];

    if ($scenario_essentials !== '') {
        $parts[] = "[SCENARIO ESSENTIALS]";
        $parts[] = $scenario_essentials;
        $parts[] = "";
    }

    $parts[] = "[STORY CONTEXT — oldest to newest]";

    foreach ($entries as $entry) {
        // The choice_taken is what the player chose to arrive at this node,
        // so it must appear before this node's paragraphs.
        if ($entry['choice_taken'] !== '') {
            $parts[] = "> Player chose: " . $entry['choice_taken'];
        }
        if ($entry['paragraphs'] !== '') {
            $parts[] = $entry['paragraphs'];
        }
    }

    $parts[] = "";
    $parts[] = "> Player chose: " . $choice_text;

    return implode("\n", $parts);
}

/**
 * Build the exact prompt payload for generating an opening node.
 *
 * @return array{scenario_essentials: string, system_prompt: string, story_context: string}
 */
function build_opening_prompt_bundle(string $title, string $scenario_essentials = ''): array
{
    return [
        'scenario_essentials' => $scenario_essentials,
        'system_prompt'       => get_system_prompt(),
        'story_context'       => build_opening_prompt($title, $scenario_essentials),
    ];
}

/**
 * Build the exact prompt payload for generating a continuation node.
 *
 * @param bool $check_quarantine Also read quarantined nodes when building context.
 * @return array{scenario_essentials: string, system_prompt: string, story_context: string}
 */
function build_continuation_prompt_bundle(string $story_id, string $parent_node_id, string $choice_text, bool $check_quarantine = false): array
{
    $scenario_essentials = story_get_scenario_essentials($story_id);
    $entries = reconstruct_context($story_id, $parent_node_id, $check_quarantine);
    $entries = truncate_context($entries);

    return [
        'scenario_essentials' => $scenario_essentials,
        'system_prompt'       => get_system_prompt(),
        'story_context'       => build_story_prompt($entries, $choice_text, $scenario_essentials),
    ];
}

/**
 * Build the initial prompt for a brand-new story's first node.
 *
 * @param string $title              Story title.
 * @param string $scenario_essentials Optional scenario description.
 * @return string The user message for the opening node.
 */
function build_opening_prompt(string $title, string $scenario_essentials = ''): string
{
    $prompt = "Begin a new choose-your-own-adventure story titled \"" . $title . "\".";

    if ($scenario_essentials !== '') {
        $prompt .= "\n\nScenario details: " . $scenario_essentials;
    }

    $prompt .= "\n\nWrite the opening scene. Set the atmosphere, introduce the protagonist's situation, and present the first set of choices.";

    return $prompt;
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
