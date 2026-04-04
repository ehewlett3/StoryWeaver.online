<?php
/**
 * StoryWeaver — API key management helpers.
 *
 * CRUD for _data/api_keys.json. Each key record describes a provider
 * configuration (OpenAI, Anthropic, Ollama, custom) with model names,
 * scope, and status. Keys are selected at call time per §3.1 priority.
 */

require_once __DIR__ . '/helpers.php';

/**
 * Path to the api_keys.json data file.
 *
 * @return string Absolute path.
 */
function api_keys_path(): string
{
    return data_path('api_keys.json');
}

/**
 * Read all API key records from disk.
 *
 * @return array Array of key records.
 */
function api_keys_read(): array
{
    $data = json_read(api_keys_path(), ['keys' => []]);
    return $data['keys'] ?? [];
}

/**
 * Write the full list of API key records to disk atomically.
 *
 * @param array $keys Array of key records.
 * @return void
 */
function api_keys_write(array $keys): void
{
    json_write(api_keys_path(), ['keys' => array_values($keys)]);
}

/**
 * Find an API key record by its ID.
 *
 * @param string $id Key ID (e.g. "key_3f9a1b2c").
 * @return array|null The key record, or null if not found.
 */
function api_key_find_by_id(string $id): ?array
{
    $keys = api_keys_read();
    foreach ($keys as $key) {
        if (($key['id'] ?? '') === $id) {
            return $key;
        }
    }
    return null;
}

/**
 * Find all API keys owned by a specific user.
 *
 * @param string $user_id Owner user ID.
 * @return array Array of key records.
 */
function api_keys_for_user(string $user_id): array
{
    $keys = api_keys_read();
    return array_values(array_filter($keys, function ($k) use ($user_id) {
        return ($k['owner_user_id'] ?? '') === $user_id;
    }));
}

/**
 * Create a new API key record.
 *
 * @param array $params Key parameters:
 *   - owner_user_id (string) User ID of the key owner.
 *   - label         (string) Human-friendly label.
 *   - provider      (string) "openai", "anthropic", "ollama", or "custom".
 *   - base_url      (string) Provider API base URL.
 *   - api_key       (string) The raw API key/token.
 *   - model_text    (string) Model name for text generation.
 *   - model_image   (string) Model name for image generation (or "").
 *   - scope         (string) "self" or "all".
 * @return array The created key record.
 */
function api_key_create(array $params): array
{
    $keys = api_keys_read();

    $record = [
        'id'             => generate_id('key_'),
        'owner_user_id'  => $params['owner_user_id'] ?? '',
        'label'          => $params['label'] ?? 'Untitled Key',
        'provider'       => $params['provider'] ?? 'openai',
        'base_url'       => rtrim($params['base_url'] ?? '', '/'),
        'api_key'        => $params['api_key'] ?? '',
        'model_text'     => $params['model_text'] ?? '',
        'model_image'    => $params['model_image'] ?? '',
        'scope'          => $params['scope'] ?? 'self',
        'status'         => 'active',
        'last_failure'   => null,
        'shared_by'      => $params['owner_user_id'] ?? '',
    ];

    $keys[] = $record;
    api_keys_write($keys);

    return $record;
}

/**
 * Update fields on an existing API key record.
 *
 * Uses file locking to prevent race conditions during read-modify-write.
 *
 * @param string $id     Key ID to update.
 * @param array  $fields Associative array of fields to merge.
 * @return bool True if the key was found and updated.
 */
function api_key_update(string $id, array $fields): bool
{
    $path = api_keys_path();
    $lock_path = $path . '.lock';

    // Acquire exclusive lock for the read-modify-write cycle
    $lock = fopen($lock_path, 'c');
    if (!$lock || !flock($lock, LOCK_EX)) {
        return false;
    }

    try {
        $keys = api_keys_read();
        $found = false;

        foreach ($keys as &$key) {
            if (($key['id'] ?? '') === $id) {
                foreach ($fields as $k => $v) {
                    $key[$k] = $v;
                }
                $found = true;
                break;
            }
        }
        unset($key);

        if ($found) {
            api_keys_write($keys);
        }
        return $found;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * Delete an API key record.
 *
 * @param string $id Key ID to delete.
 * @return bool True if the key was found and removed.
 */
function api_key_delete(string $id): bool
{
    $keys = api_keys_read();
    $initial = count($keys);

    $keys = array_values(array_filter($keys, function ($k) use ($id) {
        return ($k['id'] ?? '') !== $id;
    }));

    if (count($keys) < $initial) {
        api_keys_write($keys);
        return true;
    }
    return false;
}

/**
 * Select the best available API key for a given user (§3.1 priority).
 *
 * Priority:
 * 1. User's own key scoped to "self", status "active"
 * 2. Any key scoped to "all", status "active" (most recently added)
 * 3. null — AI features disabled for this request
 *
 * @param string|null $user_id Current user ID, or null for anonymous.
 * @return array|null The selected key record, or null if none available.
 */
function api_key_select_for_user(?string $user_id): ?array
{
    $keys = api_keys_read();

    // Priority 1: user's own "self" scoped active key
    if ($user_id !== null) {
        foreach ($keys as $key) {
            if (($key['owner_user_id'] ?? '') === $user_id
                && ($key['scope'] ?? '') === 'self'
                && ($key['status'] ?? '') === 'active') {
                return $key;
            }
        }
    }

    // Priority 2: any "all" scoped active key (last one in array = most recently added)
    $all_keys = [];
    foreach ($keys as $key) {
        if (($key['scope'] ?? '') === 'all'
            && ($key['status'] ?? '') === 'active') {
            $all_keys[] = $key;
        }
    }
    if (!empty($all_keys)) {
        return end($all_keys);
    }

    // No key available
    return null;
}

/**
 * Mark an API key as unavailable after a failure.
 *
 * @param string $id     Key ID.
 * @param string $reason Human-readable failure reason.
 * @return bool True if the key was found and updated.
 */
function api_key_mark_unavailable(string $id, string $reason = ''): bool
{
    return api_key_update($id, [
        'status'       => 'unavailable',
        'last_failure' => $reason ?: gmdate('Y-m-d\TH:i:s\Z') . ' — request failed',
    ]);
}

/**
 * Validate the list of allowed provider names.
 *
 * @param string $provider Provider name to check.
 * @return bool True if valid.
 */
function api_key_valid_provider(string $provider): bool
{
    return in_array($provider, ['openai', 'anthropic', 'ollama', 'custom'], true);
}

/**
 * Get the default base URL for a known provider.
 *
 * @param string $provider Provider name.
 * @return string Default base URL (empty for custom/ollama).
 */
function api_key_default_base_url(string $provider): string
{
    return match ($provider) {
        'openai'    => 'https://api.openai.com/v1',
        'anthropic' => 'https://api.anthropic.com',
        default     => '',
    };
}
