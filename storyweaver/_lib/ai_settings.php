<?php
/**
 * StoryWeaver — Per-user AI prompt/settings and shared schema storage.
 */

require_once __DIR__ . '/helpers.php';

define('AI_SETTINGS_FILE', data_path('ai_settings.json'));

/**
 * Default editable story system-prompt body.
 */
function ai_default_system_prompt_body(): string
{
    return <<<'PROMPT'
You are a collaborative storytelling engine for a choose-your-own-adventure game.
Always respond with ONLY valid JSON matching the schema inserted below — no markdown fences, no extra keys, no preamble.

Rules:
- Exactly 2 paragraphs unless the user has explicitly requested more or fewer.
- Exactly 3 choices unless the last node is a story ending (then 0 choices and add "ending": true).
- Each paragraph: 3–6 sentences of vivid, present-tense narrative.
- Each choice: 4–12 words, active voice, no punctuation at end.
- Never include the player's previous choice as an available choice again.
- Never break the JSON schema.
PROMPT;
}

/**
 * Default story-generation JSON schema block.
 */
function ai_default_story_json_schema(): string
{
    return <<<'SCHEMA'
{
  "paragraphs": ["<paragraph 1>", "<paragraph 2>"],
  "choices": [
    {"id": 1, "text": "<short action phrase>"},
    {"id": 2, "text": "<short action phrase>"},
    {"id": 3, "text": "<short action phrase>"}
  ]
}
SCHEMA;
}

/**
 * Default text-generation controls.
 *
 * @return array{temperature: float, top_p: float, max_tokens: int}
 */
function ai_default_generation_settings(): array
{
    return [
        'temperature' => 0.8,
        'top_p'       => 1.0,
        'max_tokens'  => 2048,
    ];
}

/**
 * Default site-level AI settings.
 *
 * @return array{story_json_schema: string, schema_edit_level: string}
 */
function ai_default_site_settings(): array
{
    return [
        'story_json_schema' => ai_default_story_json_schema(),
        'schema_edit_level' => 'admin',
    ];
}

/**
 * Full default data structure for ai_settings.json.
 *
 * @return array{site: array{story_json_schema: string, schema_edit_level: string}, users: array<string, array<string, mixed>>}
 */
function ai_settings_defaults(): array
{
    return [
        'site'  => ai_default_site_settings(),
        'users' => [],
    ];
}

/**
 * Read and normalize AI settings storage.
 */
function ai_settings_read(): array
{
    $data = json_read(AI_SETTINGS_FILE, ai_settings_defaults());
    return ai_settings_normalize($data);
}

/**
 * Write AI settings storage after normalization.
 */
function ai_settings_write(array $data): void
{
    json_write(AI_SETTINGS_FILE, ai_settings_normalize($data));
}

/**
 * Normalize the full AI settings payload.
 */
function ai_settings_normalize(array $data): array
{
    $normalized = ai_settings_defaults();

    $site = is_array($data['site'] ?? null) ? $data['site'] : [];
    $normalized['site']['story_json_schema'] = ai_normalize_schema_text((string) ($site['story_json_schema'] ?? ''));
    $normalized['site']['schema_edit_level'] = ai_normalize_schema_edit_level((string) ($site['schema_edit_level'] ?? 'admin'));

    $users = is_array($data['users'] ?? null) ? $data['users'] : [];
    foreach ($users as $user_id => $settings) {
        if (!is_string($user_id) || !validate_id($user_id, 'usr_') || !is_array($settings)) {
            continue;
        }

        $normalized['users'][$user_id] = ai_normalize_user_settings($settings);
    }

    return $normalized;
}

/**
 * Normalize a single user's stored AI settings.
 *
 * @return array{system_prompt_body: string, temperature: float, top_p: float, max_tokens: int}
 */
function ai_normalize_user_settings(array $settings): array
{
    $defaults = ai_default_generation_settings();

    return [
        'system_prompt_body' => ai_normalize_prompt_body((string) ($settings['system_prompt_body'] ?? '')),
        'temperature'        => ai_normalize_float($settings['temperature'] ?? $defaults['temperature'], 0.0, 2.0, $defaults['temperature']),
        'top_p'              => ai_normalize_float($settings['top_p'] ?? $defaults['top_p'], 0.0, 1.0, $defaults['top_p']),
        'max_tokens'         => ai_normalize_int($settings['max_tokens'] ?? $defaults['max_tokens'], 128, 8192, $defaults['max_tokens']),
    ];
}

/**
 * Get merged AI settings for a user, or app defaults for guests.
 *
 * @return array{system_prompt_body: string, temperature: float, top_p: float, max_tokens: int}
 */
function ai_user_settings(?string $user_id): array
{
    $defaults = ai_default_generation_settings();
    $base = [
        'system_prompt_body' => ai_default_system_prompt_body(),
        'temperature'        => $defaults['temperature'],
        'top_p'              => $defaults['top_p'],
        'max_tokens'         => $defaults['max_tokens'],
    ];

    if ($user_id === null || $user_id === '') {
        return $base;
    }

    $data = ai_settings_read();
    if (!isset($data['users'][$user_id]) || !is_array($data['users'][$user_id])) {
        return $base;
    }

    return array_merge($base, $data['users'][$user_id]);
}

/**
 * Persist one user's AI prompt/settings overrides.
 */
function ai_settings_update_user(string $user_id, array $settings): void
{
    if (!validate_id($user_id, 'usr_')) {
        throw new InvalidArgumentException('Invalid user ID.');
    }

    $data = ai_settings_read();
    $normalized = ai_normalize_user_settings($settings);
    $defaults = ai_user_settings(null);

    if ($normalized === $defaults) {
        unset($data['users'][$user_id]);
    } else {
        $data['users'][$user_id] = $normalized;
    }

    ai_settings_write($data);
}

/**
 * Remove a user's stored AI overrides.
 */
function ai_settings_reset_user(string $user_id): void
{
    if (!validate_id($user_id, 'usr_')) {
        throw new InvalidArgumentException('Invalid user ID.');
    }

    $data = ai_settings_read();
    unset($data['users'][$user_id]);
    ai_settings_write($data);
}

/**
 * Update the shared schema settings.
 */
function ai_settings_update_site(array $settings): void
{
    $data = ai_settings_read();

    if (array_key_exists('story_json_schema', $settings)) {
        $data['site']['story_json_schema'] = ai_normalize_schema_text((string) $settings['story_json_schema']);
    }

    if (array_key_exists('schema_edit_level', $settings)) {
        $data['site']['schema_edit_level'] = ai_normalize_schema_edit_level((string) $settings['schema_edit_level']);
    }

    ai_settings_write($data);
}

/**
 * Reset the shared schema and optionally its permission level.
 */
function ai_settings_reset_site(bool $include_permissions = true): void
{
    $data = ai_settings_read();
    $defaults = ai_default_site_settings();

    $data['site']['story_json_schema'] = $defaults['story_json_schema'];
    if ($include_permissions) {
        $data['site']['schema_edit_level'] = $defaults['schema_edit_level'];
    }

    ai_settings_write($data);
}

/**
 * Get the shared story schema text.
 */
function ai_story_json_schema(): string
{
    $data = ai_settings_read();
    return (string) ($data['site']['story_json_schema'] ?? ai_default_story_json_schema());
}

/**
 * Get the minimum role allowed to edit the shared schema.
 */
function ai_story_schema_edit_level(): string
{
    $data = ai_settings_read();
    return ai_normalize_schema_edit_level((string) ($data['site']['schema_edit_level'] ?? 'admin'));
}

/**
 * Determine whether the current user may edit the shared schema text.
 */
function ai_user_can_edit_story_schema(?array $user): bool
{
    if ($user === null) {
        return false;
    }

    return ai_role_level((string) ($user['role'] ?? 'viewer')) >= ai_role_level(ai_story_schema_edit_level());
}

/**
 * Determine whether the current user may change schema-edit permissions.
 */
function ai_user_can_manage_schema_permissions(?array $user): bool
{
    return $user !== null && (($user['role'] ?? '') === 'admin');
}

/**
 * Build the effective story-generation system prompt for a user.
 */
function ai_story_system_prompt(?array $user = null): string
{
    $settings = ai_user_settings($user['id'] ?? null);
    return ai_compose_story_system_prompt($settings['system_prompt_body'], ai_story_json_schema());
}

/**
 * Get the effective model parameters for a user's text-generation requests.
 *
 * @return array{temperature: float, top_p: float, max_tokens: int}
 */
function ai_generation_options_for_user(?array $user = null): array
{
    $settings = ai_user_settings($user['id'] ?? null);

    return [
        'temperature' => $settings['temperature'],
        'top_p'       => $settings['top_p'],
        'max_tokens'  => $settings['max_tokens'],
    ];
}

/**
 * Compose a story system prompt body with the shared JSON schema block.
 */
function ai_compose_story_system_prompt(string $body, string $schema): string
{
    $body = ai_normalize_prompt_body($body);
    $schema = ai_normalize_schema_text($schema);

    if (preg_match('/^\s*Rules:\s*$/m', $body, $match, PREG_OFFSET_CAPTURE) === 1) {
        $offset = (int) $match[0][1];
        return rtrim(substr($body, 0, $offset)) . "\n\n" . $schema . "\n\n" . ltrim(substr($body, $offset));
    }

    return rtrim($body) . "\n\n" . $schema;
}

/**
 * Normalize an editable prompt body.
 */
function ai_normalize_prompt_body(string $body): string
{
    $body = str_replace(["\r\n", "\r"], "\n", $body);
    $body = trim(mb_substr($body, 0, 12000));
    return $body !== '' ? $body : ai_default_system_prompt_body();
}

/**
 * Normalize shared schema text.
 */
function ai_normalize_schema_text(string $schema): string
{
    $schema = str_replace(["\r\n", "\r"], "\n", $schema);
    $schema = trim(mb_substr($schema, 0, 12000));
    return $schema !== '' ? $schema : ai_default_story_json_schema();
}

/**
 * Normalize a schema-edit permission level.
 */
function ai_normalize_schema_edit_level(string $level): string
{
    $level = strtolower(trim($level));
    return in_array($level, ['admin', 'editor', 'contributor'], true) ? $level : 'admin';
}

/**
 * Clamp and normalize a float setting.
 */
function ai_normalize_float(mixed $value, float $min, float $max, float $default): float
{
    if (!is_numeric($value)) {
        return $default;
    }

    $value = (float) $value;
    if ($value < $min) {
        $value = $min;
    } elseif ($value > $max) {
        $value = $max;
    }

    return round($value, 2);
}

/**
 * Clamp and normalize an integer setting.
 */
function ai_normalize_int(mixed $value, int $min, int $max, int $default): int
{
    if (!is_numeric($value)) {
        return $default;
    }

    $value = (int) round((float) $value);
    if ($value < $min) {
        $value = $min;
    } elseif ($value > $max) {
        $value = $max;
    }

    return $value;
}

/**
 * Local role ladder fallback for files that do not include auth_check.php.
 */
function ai_role_level(string $role): int
{
    if (function_exists('role_level')) {
        return role_level($role);
    }

    return [
        'viewer'      => 0,
        'contributor' => 1,
        'editor'      => 2,
        'admin'       => 3,
    ][$role] ?? 0;
}
