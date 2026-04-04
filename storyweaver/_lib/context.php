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
 * Reconstruct the narrative context by walking the parent chain (§3.3).
 *
 * Reads each ancestor node's paragraphs and the choice that was taken
 * to reach the next node. Returns entries ordered oldest-to-newest.
 *
 * @param string $story_id The story ID.
 * @param string $node_id  The current node ID (starting point).
 * @return array Array of context entries, each: ['paragraphs' => string, 'choice_taken' => string]
 */
function reconstruct_context(string $story_id, string $node_id): array
{
    $entries = [];
    $current_id = $node_id;
    $visited = []; // cycle protection

    while ($current_id !== '') {
        if (isset($visited[$current_id])) {
            break; // prevent infinite loops
        }
        $visited[$current_id] = true;

        $node = node_read($story_id, $current_id);
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
 * @return string The assembled user message.
 */
function build_story_prompt(array $entries, string $choice_text): string
{
    $parts = [];

    $parts[] = "[STORY CONTEXT — oldest to newest]";

    foreach ($entries as $entry) {
        if ($entry['paragraphs'] !== '') {
            $parts[] = $entry['paragraphs'];
        }
        if ($entry['choice_taken'] !== '') {
            $parts[] = "> Player chose: " . $entry['choice_taken'];
        }
    }

    $parts[] = "";
    $parts[] = "[PLAYER CHOICE]";
    $parts[] = $choice_text;
    $parts[] = "";
    $parts[] = "Continue the story.";

    return implode("\n", $parts);
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
 * Uses the most recent paragraphs to create a vivid scene description
 * suitable for image generation models.
 *
 * @param string $story_id The story ID.
 * @param string $node_id  The node ID to illustrate.
 * @return string The image prompt.
 */
function build_image_prompt(string $story_id, string $node_id): string
{
    $node = node_read($story_id, $node_id);
    if ($node === null || empty($node['paragraphs'])) {
        return 'A scene from an interactive story.';
    }

    // Combine the last 2 paragraphs (most recent context)
    $paras = array_slice($node['paragraphs'], -2);
    $text = implode(' ', array_map('strip_tags', $paras));
    $text = mb_substr($text, 0, 500); // keep prompt reasonable

    // Get story title for context
    $title = story_get_title($story_id);

    return "Create an illustration for a scene in a story called \"{$title}\". "
         . "The scene: {$text}\n\n"
         . "Style: Rich, atmospheric digital illustration suitable for an interactive fiction story. "
         . "No text or UI elements in the image.";
}
