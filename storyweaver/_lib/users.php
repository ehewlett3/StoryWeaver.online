<?php
/**
 * StoryWeaver — User data read/write helpers.
 *
 * Manages _data/users.json. All functions operate on the JSON file directly
 * (no caching across requests). Writes are atomic.
 */

require_once __DIR__ . '/helpers.php';

/** Path to the users data file. */
define('USERS_FILE', data_path('users.json'));

/**
 * Check whether the users data file exists.
 *
 * Used for first-run detection — if false, the app should redirect to setup.
 *
 * @return bool
 */
function users_exists(): bool
{
    return file_exists(USERS_FILE);
}

/**
 * Read the full users data structure.
 *
 * @return array { "users": [...], "concern_queue": [...] }
 */
function users_read(): array
{
    return json_read(USERS_FILE, ['users' => [], 'concern_queue' => []]);
}

/**
 * Write the full users data structure atomically.
 *
 * @param array $data The complete users data.
 * @return void
 */
function users_write(array $data): void
{
    json_write(USERS_FILE, $data);
}

/**
 * Find a user by their unique ID.
 *
 * @param string $id User ID (e.g. "usr_3f9a1b2c").
 * @return array|null The user record, or null if not found.
 */
function user_find_by_id(string $id): ?array
{
    $data = users_read();
    foreach ($data['users'] as $user) {
        if ($user['id'] === $id) {
            return $user;
        }
    }
    return null;
}

/**
 * Find a user by username (case-insensitive).
 *
 * @param string $username
 * @return array|null The user record, or null if not found.
 */
function user_find_by_username(string $username): ?array
{
    $data = users_read();
    $lower = strtolower($username);
    foreach ($data['users'] as $user) {
        if (strtolower($user['username']) === $lower) {
            return $user;
        }
    }
    return null;
}

/**
 * Find a user by email address (case-insensitive).
 *
 * @param string $email
 * @return array|null The user record, or null if not found.
 */
function user_find_by_email(string $email): ?array
{
    $data = users_read();
    $lower = strtolower($email);
    foreach ($data['users'] as $user) {
        if (strtolower($user['email']) === $lower) {
            return $user;
        }
    }
    return null;
}

/**
 * Create a new user and persist to users.json.
 *
 * @param string $username Unique username.
 * @param string $email    Email address.
 * @param string $password Plain-text password (will be hashed).
 * @param string $role     One of: contributor, editor, admin.
 * @return array The created user record (without plain password).
 */
function user_create(string $username, string $email, string $password, string $role = 'contributor'): array
{
    $data = users_read();

    $user = [
        'id'             => generate_id('usr_'),
        'username'       => $username,
        'email'          => $email,
        'password_hash'  => password_hash($password, PASSWORD_BCRYPT),
        'role'           => $role,
        'created_at'     => gmdate('Y-m-d\TH:i:s\Z'),
        'reset_token'    => null,
        'reset_expires'  => null,
    ];

    $data['users'][] = $user;
    users_write($data);

    return $user;
}

/**
 * Update fields on an existing user record.
 *
 * Merges the given fields into the user array. Does not allow changing 'id'.
 *
 * @param string $id     User ID.
 * @param array  $fields Key-value pairs to update.
 * @return bool True if user was found and updated.
 */
function user_update(string $id, array $fields): bool
{
    $data = users_read();
    unset($fields['id']); // Never allow ID change

    foreach ($data['users'] as &$user) {
        if ($user['id'] === $id) {
            $user = array_merge($user, $fields);
            users_write($data);
            return true;
        }
    }
    return false;
}

/**
 * Verify a plain-text password against a user's stored hash.
 *
 * @param array  $user     The user record (must contain 'password_hash').
 * @param string $password The plain-text password to check.
 * @return bool
 */
function user_verify_password(array $user, string $password): bool
{
    return password_verify($password, $user['password_hash']);
}

/**
 * List all users (without password hashes).
 *
 * @return array Array of user records with password_hash removed.
 */
function users_list(): array
{
    $data = users_read();
    $users = $data['users'] ?? [];
    return array_map(function ($u) {
        unset($u['password_hash']);
        return $u;
    }, $users);
}

/**
 * Delete a user by ID.
 *
 * @param string $id User ID.
 * @return bool True if user was found and deleted.
 */
function user_delete(string $id): bool
{
    $data = users_read();
    $found = false;

    $data['users'] = array_values(array_filter($data['users'] ?? [], function ($u) use ($id, &$found) {
        if ($u['id'] === $id) {
            $found = true;
            return false;
        }
        return true;
    }));

    if (!$found) {
        return false;
    }

    users_write($data);
    return true;
}
