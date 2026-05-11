<?php
/**
 * StoryWeaver — API key management helpers.
 *
 * CRUD for _data/api_keys.json. Each key record describes a provider
 * configuration (OpenAI, Anthropic, Ollama, custom) with model names,
 * scope, and status. Keys are selected at call time per §3.1 priority.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/users.php';

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
 * Path to the master secret used for API-key encryption at rest.
 */
function api_keys_master_secret_path(): string
{
    return data_path('api_key_master_secret');
}

/**
 * Return the app-local master secret, creating it on first use.
 */
function api_keys_master_secret(): string
{
    $path = api_keys_master_secret_path();
    if (file_exists($path)) {
        $stored = trim((string) file_get_contents($path));
        if ($stored !== '') {
            $decoded = base64_decode($stored, true);
            if ($decoded !== false && strlen($decoded) >= 32) {
                return $decoded;
            }
        }
    }

    $secret = random_bytes(32);
    atomic_write($path, base64_encode($secret) . PHP_EOL);
    @chmod($path, 0600);
    return $secret;
}

/**
 * Raw read of API key records from disk without migrations or locking.
 *
 * @return array Array of key records.
 */
function api_keys_load_unlocked(): array
{
    $data = json_read(api_keys_path(), ['keys' => []]);
    return $data['keys'] ?? [];
}

/**
 * Return true when an API key value uses encrypted storage.
 */
function api_key_is_encrypted_value(string $value): bool
{
    return str_starts_with($value, 'enc:v1:');
}

/**
 * Resolve the username-derived salt string for a key record.
 */
function api_key_owner_salt(array $record, ?string $override_username = null): string
{
    if ($override_username !== null && trim($override_username) !== '') {
        return trim($override_username);
    }

    $stored = trim((string) ($record['owner_username'] ?? ''));
    if ($stored !== '') {
        return $stored;
    }

    $owner = user_find_by_id((string) ($record['owner_user_id'] ?? ''));
    $username = trim((string) ($owner['username'] ?? ''));
    if ($username !== '') {
        return $username;
    }

    $fallback = trim((string) ($record['owner_user_id'] ?? ''));
    if ($fallback !== '') {
        return $fallback;
    }

    throw new RuntimeException('Unable to resolve a username salt for the selected API key.');
}

/**
 * Derive a symmetric encryption key from the master secret and username salt.
 */
function api_key_derive_encryption_key(string $username_salt): string
{
    return hash_hkdf('sha256', api_keys_master_secret(), 32, 'storyweaver/api-key', $username_salt);
}

/**
 * Encrypt an API key for storage.
 */
function api_key_encrypt_secret(string $plaintext, string $username_salt): string
{
    if ($plaintext === '') {
        return '';
    }
    if (!function_exists('openssl_encrypt') || !function_exists('openssl_decrypt')) {
        throw new RuntimeException('The OpenSSL extension is required for encrypted API key storage.');
    }

    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt(
        $plaintext,
        'aes-256-gcm',
        api_key_derive_encryption_key($username_salt),
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if (!is_string($ciphertext) || $ciphertext === '' || $tag === '') {
        throw new RuntimeException('Failed to encrypt the API key.');
    }

    return 'enc:v1:' . base64_encode($iv) . ':' . base64_encode($tag) . ':' . base64_encode($ciphertext);
}

/**
 * Decrypt an API key from storage, or return plaintext for legacy records.
 */
function api_key_decrypt_secret(string $stored, string $username_salt): string
{
    if ($stored === '' || !api_key_is_encrypted_value($stored)) {
        return $stored;
    }
    if (!function_exists('openssl_encrypt') || !function_exists('openssl_decrypt')) {
        throw new RuntimeException('The OpenSSL extension is required for encrypted API key storage.');
    }

    $parts = explode(':', $stored, 5);
    if (count($parts) !== 5) {
        throw new RuntimeException('Stored API key data is malformed.');
    }

    [, , $iv_b64, $tag_b64, $cipher_b64] = $parts;
    $iv = base64_decode($iv_b64, true);
    $tag = base64_decode($tag_b64, true);
    $ciphertext = base64_decode($cipher_b64, true);
    if ($iv === false || $tag === false || $ciphertext === false) {
        throw new RuntimeException('Stored API key data is malformed.');
    }

    $plaintext = openssl_decrypt(
        $ciphertext,
        'aes-256-gcm',
        api_key_derive_encryption_key($username_salt),
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if (!is_string($plaintext)) {
        throw new RuntimeException('Failed to decrypt the stored API key.');
    }

    return $plaintext;
}

/**
 * Ensure stored key records have username metadata and encrypted secrets.
 *
 * @param array $keys Raw key records.
 * @return array{keys: array, changed: bool}
 */
function api_keys_normalize_storage(array $keys): array
{
    $changed = false;
    $default_public_ids = [];

    foreach ($keys as &$key) {
        $owner_salt = api_key_owner_salt($key);
        if (($key['owner_username'] ?? '') !== $owner_salt) {
            $key['owner_username'] = $owner_salt;
            $changed = true;
        }

        $stored_secret = (string) ($key['api_key'] ?? '');
        if ($stored_secret !== '' && !api_key_is_encrypted_value($stored_secret)) {
            $key['api_key'] = api_key_encrypt_secret($stored_secret, $owner_salt);
            $changed = true;
        }

        $is_default_public = !empty($key['is_default_public']);
        $is_valid_default_public = (($key['scope'] ?? '') === 'all') && (($key['status'] ?? '') === 'active');
        if ($is_default_public && !$is_valid_default_public) {
            $key['is_default_public'] = false;
            $changed = true;
            $is_default_public = false;
        }

        if ($is_default_public) {
            $default_public_ids[] = (string) ($key['id'] ?? '');
        }
    }
    unset($key);

    if (count($default_public_ids) > 1) {
        $keep_id = end($default_public_ids);
        foreach ($keys as &$key) {
            if (!empty($key['is_default_public']) && ($key['id'] ?? '') !== $keep_id) {
                $key['is_default_public'] = false;
                $changed = true;
            }
        }
        unset($key);
    }

    return ['keys' => $keys, 'changed' => $changed];
}

/**
 * Upgrade plaintext API key storage to encrypted-at-rest format.
 */
function api_keys_upgrade_storage(): void
{
    $path = api_keys_path();
    $lock_path = $path . '.lock';

    $lock = fopen($lock_path, 'c');
    if (!$lock || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('Could not acquire lock for api_keys');
    }

    try {
        $normalized = api_keys_normalize_storage(api_keys_load_unlocked());
        if ($normalized['changed']) {
            api_keys_write($normalized['keys']);
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * Read all API key records from disk.
 *
 * @return array Array of key records.
 */
function api_keys_read(): array
{
    api_keys_upgrade_storage();
    return api_keys_load_unlocked();
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
    $path = api_keys_path();
    $lock_path = $path . '.lock';

    $lock = fopen($lock_path, 'c');
    if (!$lock || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('Could not acquire lock for api_keys');
    }

    try {
        $normalized = api_keys_normalize_storage(api_keys_load_unlocked());
        $keys = $normalized['keys'];
        $owner_salt = api_key_owner_salt([
            'owner_user_id' => $params['owner_user_id'] ?? '',
            'owner_username' => $params['owner_username'] ?? '',
        ]);

        $record = [
            'id'              => generate_id('key_'),
            'owner_user_id'   => $params['owner_user_id'] ?? '',
            'owner_username'  => $owner_salt,
            'label'           => $params['label'] ?? 'Untitled Key',
            'provider'        => $params['provider'] ?? 'openai',
            'base_url'        => rtrim($params['base_url'] ?? '', '/'),
            'api_key'         => api_key_encrypt_secret((string) ($params['api_key'] ?? ''), $owner_salt),
            'model_text'      => $params['model_text'] ?? '',
            'model_image'     => $params['model_image'] ?? '',
            'scope'           => $params['scope'] ?? 'self',
            'fallback_key_id' => $params['fallback_key_id'] ?? null,
            'is_default_public' => !empty($params['is_default_public']),
            'status'          => 'active',
            'last_failure'    => null,
            'shared_by'       => $params['owner_user_id'] ?? '',
        ];

        $keys[] = $record;
        api_keys_write($keys);

        return $record;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
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
        $normalized = api_keys_normalize_storage(api_keys_load_unlocked());
        $keys = $normalized['keys'];
        $found = false;

        foreach ($keys as &$key) {
            if (($key['id'] ?? '') === $id) {
                $updated = array_merge($key, $fields);
                if (array_key_exists('api_key', $fields)) {
                    $updated['api_key'] = api_key_encrypt_secret(
                        (string) $fields['api_key'],
                        api_key_owner_salt($updated)
                    );
                }
                $key = $updated;
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
    $path = api_keys_path();
    $lock_path = $path . '.lock';

    $lock = fopen($lock_path, 'c');
    if (!$lock || !flock($lock, LOCK_EX)) {
        return false;
    }

    try {
        $normalized = api_keys_normalize_storage(api_keys_load_unlocked());
        $keys = $normalized['keys'];
        $initial = count($keys);

        $keys = array_values(array_filter($keys, function ($k) use ($id) {
            return ($k['id'] ?? '') !== $id;
        }));

        if (count($keys) < $initial) {
            api_keys_write($keys);
            return true;
        }
        return false;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * Return a key record with a decrypted API secret ready for provider use.
 */
function api_key_prepare_for_use(array $record): array
{
    $owner_salt = api_key_owner_salt($record);
    $record['owner_username'] = $owner_salt;
    $record['api_key'] = api_key_decrypt_secret((string) ($record['api_key'] ?? ''), $owner_salt);
    return $record;
}

/**
 * Re-encrypt all API keys owned by a user after a username change.
 */
function api_keys_reencrypt_for_username(string $user_id, string $old_username, string $new_username): void
{
    if ($old_username === $new_username) {
        return;
    }

    $path = api_keys_path();
    $lock_path = $path . '.lock';

    $lock = fopen($lock_path, 'c');
    if (!$lock || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('Could not acquire lock for api_keys');
    }

    try {
        $normalized = api_keys_normalize_storage(api_keys_load_unlocked());
        $keys = $normalized['keys'];
        $changed = false;

        foreach ($keys as &$key) {
            if (($key['owner_user_id'] ?? '') !== $user_id) {
                continue;
            }

            $current_salt = trim((string) ($key['owner_username'] ?? '')) ?: $old_username;
            $plaintext = api_key_decrypt_secret((string) ($key['api_key'] ?? ''), $current_salt);
            $key['owner_username'] = $new_username;
            $key['api_key'] = api_key_encrypt_secret($plaintext, $new_username);
            $changed = true;
        }
        unset($key);

        if ($changed) {
            api_keys_write($keys);
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
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
    $user = $user_id !== null ? user_find_by_id($user_id) : null;
    $keys = api_keys_read();

    // Priority 1: user's own "self" scoped active key
    if ($user_id !== null) {
        foreach ($keys as $key) {
            if (($key['owner_user_id'] ?? '') === $user_id
                && ($key['scope'] ?? '') === 'self'
                && ($key['status'] ?? '') === 'active'
                && api_key_access_error($key, $user) === null) {
                return $key;
            }
        }
    }

    // Priority 2: default shared key, else newest active shared key
    $shared_key = api_key_select_shared_for_user($keys, $user, false);
    if ($shared_key !== null) {
        return $shared_key;
    }

    if ($user !== null && ($user['role'] ?? '') === 'admin') {
        $admin_keys = [];
        foreach ($keys as $key) {
            if (($key['status'] ?? '') === 'active' && api_key_access_error($key, $user) === null) {
                $admin_keys[] = $key;
            }
        }
        if (!empty($admin_keys)) {
            return end($admin_keys);
        }
    }

    // No key available
    return null;
}

/**
 * Select a text-capable AI key that the current user may use.
 */
function api_key_select_text_for_user(?string $user_id): ?array
{
    $user = $user_id !== null ? user_find_by_id($user_id) : null;
    $keys = api_keys_read();

    if ($user_id !== null) {
        foreach ($keys as $key) {
            if (($key['owner_user_id'] ?? '') === $user_id
                && ($key['scope'] ?? '') === 'self'
                && ($key['status'] ?? '') === 'active'
                && !empty($key['model_text'])
                && api_key_access_error($key, $user) === null) {
                return $key;
            }
        }
    }

    $shared_key = api_key_select_shared_for_user($keys, $user, true);
    if ($shared_key !== null) {
        return $shared_key;
    }

    if ($user !== null && ($user['role'] ?? '') === 'admin') {
        $admin_keys = [];
        foreach ($keys as $key) {
            if (($key['status'] ?? '') === 'active'
                && !empty($key['model_text'])
                && api_key_access_error($key, $user) === null) {
                $admin_keys[] = $key;
            }
        }
        if (!empty($admin_keys)) {
            return end($admin_keys);
        }
    }

    return null;
}

/**
 * Return the explicitly configured default shared/public key ID, if any.
 */
function api_key_default_public_id(): ?string
{
    foreach (api_keys_read() as $key) {
        if (!empty($key['is_default_public'])) {
            return (string) ($key['id'] ?? '');
        }
    }

    return null;
}

/**
 * Set or clear the default shared/public API key.
 *
 * @param string $id Shared key ID, or '' to clear the explicit default.
 * @return bool True if the default was updated.
 */
function api_key_set_default_public(string $id): bool
{
    $path = api_keys_path();
    $lock_path = $path . '.lock';

    $lock = fopen($lock_path, 'c');
    if (!$lock || !flock($lock, LOCK_EX)) {
        return false;
    }

    try {
        $normalized = api_keys_normalize_storage(api_keys_load_unlocked());
        $keys = $normalized['keys'];
        $target_found = ($id === '');
        $target_valid = ($id === '');

        foreach ($keys as &$key) {
            $is_target = ($key['id'] ?? '') === $id;
            if ($is_target) {
                $target_found = true;
                $target_valid = (($key['scope'] ?? '') === 'all') && (($key['status'] ?? '') === 'active');
            }

            $key['is_default_public'] = ($id !== '' && $is_target && $target_valid);
        }
        unset($key);

        if (!$target_found || !$target_valid) {
            return false;
        }

        api_keys_write($keys);
        return true;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * Select a shared/public key that the current user may use.
 *
 * Prefers the explicitly configured default shared key when valid.
 *
 * @param array $keys
 */
function api_key_select_shared_for_user(array $keys, ?array $user, bool $require_text_model): ?array
{
    $shared_keys = [];
    $default_key = null;

    foreach ($keys as $key) {
        if (($key['scope'] ?? '') !== 'all'
            || ($key['status'] ?? '') !== 'active'
            || api_key_access_error($key, $user) !== null) {
            continue;
        }

        if ($require_text_model && empty($key['model_text'])) {
            continue;
        }

        $shared_keys[] = $key;
        if (!empty($key['is_default_public'])) {
            $default_key = $key;
        }
    }

    if ($default_key !== null) {
        return $default_key;
    }

    if (!empty($shared_keys)) {
        return end($shared_keys);
    }

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
    return in_array($provider, ['openai', 'anthropic', 'gemini', 'ollama', 'custom'], true);
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
        'gemini'    => 'https://generativelanguage.googleapis.com/v1beta',
        'ollama'    => 'http://localhost:11434/v1',
        default     => '',
    };
}

/**
 * Determine whether a key is visible to the current user.
 */
function api_key_is_visible_to_user(array $key, ?array $user): bool
{
    if ($user !== null && ($user['role'] ?? '') === 'admin') {
        return true;
    }

    if (($key['scope'] ?? '') === 'all') {
        return true;
    }

    return $user !== null && ($key['owner_user_id'] ?? '') === ($user['id'] ?? '');
}

/**
 * Validate an AI-related URL and classify whether it targets a restricted endpoint.
 *
 * @return array{ok: bool, restricted: bool, reason: string}
 */
function api_key_url_policy(string $url): array
{
    $url = trim($url);
    if ($url === '') {
        return ['ok' => false, 'restricted' => false, 'reason' => 'Base URL is required.'];
    }

    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        return ['ok' => false, 'restricted' => false, 'reason' => 'AI endpoint URL must include http:// or https:// and a host name.'];
    }

    if (!empty($parts['user']) || !empty($parts['pass'])) {
        return ['ok' => false, 'restricted' => false, 'reason' => 'AI endpoint URLs may not include embedded credentials.'];
    }

    $scheme = strtolower((string) $parts['scheme']);
    if (!in_array($scheme, ['http', 'https'], true)) {
        return ['ok' => false, 'restricted' => false, 'reason' => 'AI endpoint URLs must use http:// or https://.'];
    }

    $host = trim((string) $parts['host'], '[]');
    if ($host === '') {
        return ['ok' => false, 'restricted' => false, 'reason' => 'AI endpoint URL is missing a host name.'];
    }

    $restricted = $scheme !== 'https' || api_key_host_is_restricted($host);

    return [
        'ok' => true,
        'restricted' => $restricted,
        'reason' => $restricted
            ? 'Only admin-managed keys may use local, private, or non-HTTPS AI endpoints.'
            : '',
    ];
}

/**
 * Return true when a host resolves to a local, private, or reserved address.
 */
function api_key_host_is_restricted(string $host): bool
{
    $host = strtolower(rtrim($host, '.'));
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return !api_key_ip_is_public($host);
    }

    if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local') || !str_contains($host, '.')) {
        return true;
    }

    $ipv4 = gethostbynamel($host);
    if (is_array($ipv4)) {
        foreach ($ipv4 as $ip) {
            if (!api_key_ip_is_public($ip)) {
                return true;
            }
        }
    }

    if (function_exists('dns_get_record')) {
        $ipv6 = @dns_get_record($host, DNS_AAAA);
        if (is_array($ipv6)) {
            foreach ($ipv6 as $record) {
                $ip = $record['ipv6'] ?? '';
                if ($ip !== '' && !api_key_ip_is_public($ip)) {
                    return true;
                }
            }
        }
    }

    return false;
}

/**
 * Return true when an IP address is public and routable.
 */
function api_key_ip_is_public(string $ip): bool
{
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

/**
 * Return a human-readable access error for a key, or null if it may be used.
 */
function api_key_access_error(array $key, ?array $user): ?string
{
    if (!api_key_is_visible_to_user($key, $user)) {
        return 'You do not have access to that AI key.';
    }

    $policy = api_key_url_policy($key['base_url'] ?? '');
    if (!$policy['ok']) {
        return $policy['reason'];
    }

    if (!$policy['restricted']) {
        return null;
    }

    if ($user !== null && ($user['role'] ?? '') === 'admin') {
        return null;
    }

    $owner = user_find_by_id($key['owner_user_id'] ?? '');
    if (($owner['role'] ?? '') === 'admin') {
        return null;
    }

    return $policy['reason'];
}

/**
 * Detect whether an error message represents a transient connection failure
 * (as opposed to an auth/rate-limit/model error that warrants marking a key unavailable).
 *
 * Connection errors come from curl or the stream fallback when the remote host
 * is unreachable — typical for a LAN Ollama instance that is temporarily down.
 *
 * @param string $msg Exception message from AIProvider.
 * @return bool True if the error looks like a transient network failure.
 */
function api_key_is_connection_error(string $msg): bool
{
    return str_contains($msg, 'HTTP request failed:')       // curl: connection refused/timed out
        || str_contains($msg, 'HTTP request failed (stream)') // stream context fallback
        || str_contains($msg, 'Streaming request failed:');   // SSE streaming
}

/**
 * Resolve the fallback key for a primary key record.
 *
 * Returns the fallback key record if the primary has a fallback_key_id configured
 * and that key exists and is active. Returns null otherwise.
 * Fallback chains are deliberately limited to one level to prevent cycles.
 *
 * @param array $primary The primary key record.
 * @return array|null The fallback key record, or null if none available.
 */
function api_key_get_fallback(array $primary): ?array
{
    $fid = $primary['fallback_key_id'] ?? null;
    if (empty($fid)) {
        return null;
    }

    $fb = api_key_find_by_id($fid);
    if ($fb === null || ($fb['status'] ?? '') !== 'active') {
        return null;
    }

    return $fb;
}
