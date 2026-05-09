<?php
/**
 * StoryWeaver — Authentication controller.
 *
 * Handles login, logout, first-run setup, and password reset.
 * Routes via ?action= query parameter.
 */

require_once __DIR__ . '/_lib/auth_check.php';

$action = $_GET['action'] ?? 'login';

// ─── Setup guard: redirect to setup if no users exist ───
if ($action !== 'setup' && !users_exists()) {
    redirect(base_url() . '/auth.php?action=setup');
}

// ─── Setup page must be inaccessible once users.json exists ───
if ($action === 'setup' && users_exists()) {
    redirect(base_url() . '/index.php');
}

// ─── Route to the appropriate action ───
switch ($action) {
    case 'setup':
        handle_setup();
        break;
    case 'login':
        handle_login();
        break;
    case 'logout':
        handle_logout();
        break;
    case 'reset_request':
        handle_reset_request();
        break;
    case 'reset':
        handle_reset();
        break;
    case 'register':
        handle_register();
        break;
    default:
        redirect(base_url() . '/auth.php?action=login');
}

/* ======================================================================
 * ACTION HANDLERS
 * ====================================================================*/

/**
 * First-run setup — create the initial admin account.
 *
 * GET:  Show the setup form.
 * POST: Validate input, create admin user, log in, redirect.
 */
function handle_setup(): void
{
    $errors = [];

    if (is_post()) {
        csrf_check();

        $username = trim($_POST['username'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['password_confirm'] ?? '';

        if ($username === '') {
            $errors[] = 'Username is required.';
        } elseif (strlen($username) < 3 || strlen($username) > 30) {
            $errors[] = 'Username must be 3–30 characters.';
        } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
            $errors[] = 'Username may only contain letters, numbers, and underscores.';
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid email address is required.';
        }

        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }

        if ($password !== $confirm) {
            $errors[] = 'Passwords do not match.';
        }

        if (empty($errors)) {
            $user = user_create($username, $email, $password, 'admin');
            auth_login($user['id']);
            flash('success', 'Welcome to StoryWeaver! Your admin account has been created.');
            redirect(base_url() . '/index.php');
        }
    }

    render_page('Create Admin Account', function () use ($errors) {
        ?>
        <div class="sw-auth-page">
            <div class="sw-auth-card">
                <h1>🧶 StoryWeaver</h1>
                <p class="sw-auth-subtitle">Set up your first admin account to get started.</p>

                <?php render_errors($errors); ?>

                <form method="POST" action="<?= h(base_url()) ?>/auth.php?action=setup">
                    <?= csrf_field() ?>

                    <div class="sw-form-group">
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" class="sw-input"
                               value="<?= h($_POST['username'] ?? '') ?>" required autofocus>
                    </div>

                    <div class="sw-form-group">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" class="sw-input"
                               value="<?= h($_POST['email'] ?? '') ?>" required>
                    </div>

                    <div class="sw-form-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" class="sw-input"
                               minlength="8" required>
                    </div>

                    <div class="sw-form-group">
                        <label for="password_confirm">Confirm Password</label>
                        <input type="password" id="password_confirm" name="password_confirm" class="sw-input"
                               minlength="8" required>
                    </div>

                    <button type="submit" class="sw-btn sw-btn-primary" style="width:100%">
                        Create Account & Get Started →
                    </button>
                </form>
            </div>
        </div>
        <?php
    }, true, 'setup');
}

/**
 * Login — authenticate a user.
 *
 * GET:  Show login form.
 * POST: Validate credentials, start session, redirect.
 */
function handle_login(): void
{
    // Already logged in? Go home.
    if (is_logged_in()) {
        redirect(base_url() . '/index.php');
    }

    $errors = [];

    if (is_post()) {
        csrf_check();

        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        $user = user_find_by_username($username);

        if ($user === null || !user_verify_password($user, $password)) {
            $errors[] = 'Invalid username or password.';
        } else {
            auth_login($user['id']);
            flash('success', 'Welcome back, ' . $user['username'] . '!');
            redirect(base_url() . '/index.php');
        }
    }

    render_page('Log In', function () use ($errors) {
        ?>
        <div class="sw-auth-page">
            <div class="sw-auth-card">
                <h1>🧶 StoryWeaver</h1>
                <p class="sw-auth-subtitle">Log in to your account.</p>

                <?php render_errors($errors); ?>
                <?php render_flashes(); ?>

                <form method="POST" action="<?= h(base_url()) ?>/auth.php?action=login">
                    <?= csrf_field() ?>

                    <div class="sw-form-group">
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" class="sw-input"
                               value="<?= h($_POST['username'] ?? '') ?>" required autofocus>
                    </div>

                    <div class="sw-form-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" class="sw-input" required>
                    </div>

                    <button type="submit" class="sw-btn sw-btn-primary" style="width:100%">
                        Log In
                    </button>
                </form>

                <div class="sw-auth-footer">
                    <a href="<?= h(base_url()) ?>/auth.php?action=register">Create an account</a>
                    &nbsp;·&nbsp;
                    <a href="<?= h(base_url()) ?>/auth.php?action=reset_request">Forgot password?</a>
                </div>
            </div>
        </div>
        <?php
    }, true, 'login');
}

/**
 * Logout — destroy session, redirect home.
 */
function handle_logout(): void
{
    if (!is_post()) {
        redirect(base_url() . '/index.php');
    }

    csrf_check();
    auth_logout();
    // Start a new session for the flash message
    sw_start_session();
    flash('info', 'You have been logged out.');
    redirect(base_url() . '/auth.php?action=login');
}

/**
 * Password reset request — enter email to receive a reset link.
 *
 * GET:  Show the form.
 * POST: Generate token, store in _mail/, attempt to send via mail().
 */
function handle_reset_request(): void
{
    $errors = [];
    $mail_sent = false;

    if (is_post()) {
        csrf_check();

        $email = trim($_POST['email'] ?? '');

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }

        if (empty($errors)) {
            $user = user_find_by_email($email);

            // Always show success to prevent email enumeration.
            // But only actually generate a token if the user exists.
            if ($user !== null) {
                $token = bin2hex(random_bytes(32));
                $expires = gmdate('Y-m-d\TH:i:s\Z', time() + 3600); // 1 hour

                // Store token in _mail/ directory
                $token_data = [
                    'user_id'    => $user['id'],
                    'email'      => $user['email'],
                    'token'      => $token,
                    'expires_at' => $expires,
                    'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
                ];

                $mail_dir = sw_root() . '/_mail';
                if (!is_dir($mail_dir)) {
                    mkdir($mail_dir, 0755, true);
                }
                json_write($mail_dir . '/' . $token . '.json', $token_data);

                // Build the reset link
                $reset_link = request_origin() . base_url()
                            . '/auth.php?action=reset&token=' . urlencode($token);

                // Attempt to send email
                $subject = 'StoryWeaver — Password Reset';
                $body = "Hello {$user['username']},\n\n"
                      . "You requested a password reset. Click the link below (valid for 1 hour):\n\n"
                      . $reset_link . "\n\n"
                      . "If you did not request this, you can safely ignore this email.\n";
                $headers = 'From: ' . mail_from_address() . "\r\n"
                         . 'Content-Type: text/plain; charset=UTF-8' . "\r\n";

                if (function_exists('mail')) {
                    $mail_sent = @mail($user['email'], $subject, $body, $headers);
                }

                if (!$mail_sent) {
                    error_log('StoryWeaver password reset email could not be delivered for user ' . $user['id'] . '.');
                }
            }

            // Show success regardless (prevent enumeration)
            flash('success', 'If an account with that email exists, a reset link has been sent.');
        }
    }

    render_page('Reset Password', function () use ($errors) {
        ?>
        <div class="sw-auth-page">
            <div class="sw-auth-card">
                <h1>Reset Password</h1>
                <p class="sw-auth-subtitle">Enter your email to receive a reset link.</p>

                <?php render_errors($errors); ?>
                <?php render_flashes(); ?>

                <form method="POST" action="<?= h(base_url()) ?>/auth.php?action=reset_request">
                    <?= csrf_field() ?>

                    <div class="sw-form-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" class="sw-input"
                               value="<?= h($_POST['email'] ?? '') ?>" required autofocus>
                    </div>

                    <button type="submit" class="sw-btn sw-btn-primary" style="width:100%">
                        Send Reset Link
                    </button>
                </form>

                <div class="sw-auth-footer">
                    <a href="<?= h(base_url()) ?>/auth.php?action=login">← Back to login</a>
                </div>
            </div>
        </div>
        <?php
    }, true, 'login');
}

/**
 * Password reset — validate token, set new password.
 *
 * GET:  Show the new-password form (if token is valid).
 * POST: Update the password and delete the token file.
 */
function handle_reset(): void
{
    $token_str = $_GET['token'] ?? '';
    $errors = [];

    // Validate the token
    $token_file = sw_root() . '/_mail/' . basename($token_str) . '.json';
    $token_data = null;

    if ($token_str !== '' && file_exists($token_file)) {
        $token_data = json_read($token_file);

        // Check expiry
        if (isset($token_data['expires_at'])) {
            $expires = strtotime($token_data['expires_at']);
            if ($expires !== false && $expires < time()) {
                @unlink($token_file);
                $token_data = null;
            }
        }

        // Check token matches filename
        if ($token_data !== null && ($token_data['token'] ?? '') !== $token_str) {
            $token_data = null;
        }
    }

    if ($token_data === null) {
        render_page('Invalid Reset Link', function () {
            ?>
            <div class="sw-auth-page">
                <div class="sw-auth-card">
                    <h1>Invalid or Expired Link</h1>
                    <p>This password reset link is invalid or has expired.</p>
                    <div class="sw-auth-footer">
                        <a href="<?= h(base_url()) ?>/auth.php?action=reset_request">Request a new reset link</a>
                    </div>
                </div>
            </div>
            <?php
        }, true, 'login');
        return;
    }

    if (is_post()) {
        csrf_check();

        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['password_confirm'] ?? '';

        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }

        if ($password !== $confirm) {
            $errors[] = 'Passwords do not match.';
        }

        if (empty($errors)) {
            $new_hash = password_hash($password, PASSWORD_BCRYPT);
            user_update($token_data['user_id'], [
                'password_hash' => $new_hash,
                'reset_token'   => null,
                'reset_expires' => null,
            ]);

            // Delete the token file
            @unlink($token_file);

            flash('success', 'Your password has been reset. Please log in.');
            redirect(base_url() . '/auth.php?action=login');
        }
    }

    render_page('Set New Password', function () use ($errors, $token_str) {
        ?>
        <div class="sw-auth-page">
            <div class="sw-auth-card">
                <h1>Set New Password</h1>
                <p class="sw-auth-subtitle">Choose a new password for your account.</p>

                <?php render_errors($errors); ?>

                <form method="POST" action="<?= h(base_url()) ?>/auth.php?action=reset&token=<?= h(urlencode($token_str)) ?>">
                    <?= csrf_field() ?>

                    <div class="sw-form-group">
                        <label for="password">New Password</label>
                        <input type="password" id="password" name="password" class="sw-input"
                               minlength="8" required autofocus>
                    </div>

                    <div class="sw-form-group">
                        <label for="password_confirm">Confirm New Password</label>
                        <input type="password" id="password_confirm" name="password_confirm" class="sw-input"
                               minlength="8" required>
                    </div>

                    <button type="submit" class="sw-btn sw-btn-primary" style="width:100%">
                        Reset Password
                    </button>
                </form>
            </div>
        </div>
        <?php
    }, true, 'login');
}

/**
 * Registration — create a new contributor account.
 *
 * GET:  Show registration form.
 * POST: Validate input, create user, log in, redirect.
 */
function handle_register(): void
{
    // Already logged in? Go home.
    if (is_logged_in()) {
        redirect(base_url() . '/index.php');
    }

    $errors = [];

    if (is_post()) {
        csrf_check();

        $username = trim($_POST['username'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['password_confirm'] ?? '';

        if ($username === '') {
            $errors[] = 'Username is required.';
        } elseif (strlen($username) < 3 || strlen($username) > 30) {
            $errors[] = 'Username must be 3–30 characters.';
        } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
            $errors[] = 'Username may only contain letters, numbers, and underscores.';
        } elseif (user_find_by_username($username) !== null) {
            $errors[] = 'That username is already taken.';
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid email address is required.';
        } elseif (user_find_by_email($email) !== null) {
            $errors[] = 'An account with that email already exists.';
        }

        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }

        if ($password !== $confirm) {
            $errors[] = 'Passwords do not match.';
        }

        if (empty($errors)) {
            $user = user_create($username, $email, $password, 'contributor');
            auth_login($user['id']);
            flash('success', 'Welcome to StoryWeaver, ' . $user['username'] . '! Your account has been created.');
            redirect(base_url() . '/index.php');
        }
    }

    render_page('Create Account', function () use ($errors) {
        ?>
        <div class="sw-auth-page">
            <div class="sw-auth-card">
                <h1>🧶 StoryWeaver</h1>
                <p class="sw-auth-subtitle">Create a new account.</p>

                <?php render_errors($errors); ?>

                <form method="POST" action="<?= h(base_url()) ?>/auth.php?action=register">
                    <?= csrf_field() ?>

                    <div class="sw-form-group">
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" class="sw-input"
                               value="<?= h($_POST['username'] ?? '') ?>" required autofocus>
                    </div>

                    <div class="sw-form-group">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" class="sw-input"
                               value="<?= h($_POST['email'] ?? '') ?>" required>
                    </div>

                    <div class="sw-form-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" class="sw-input"
                               minlength="8" required>
                    </div>

                    <div class="sw-form-group">
                        <label for="password_confirm">Confirm Password</label>
                        <input type="password" id="password_confirm" name="password_confirm" class="sw-input"
                               minlength="8" required>
                    </div>

                    <button type="submit" class="sw-btn sw-btn-primary" style="width:100%">
                        Create Account
                    </button>
                </form>

                <div class="sw-auth-footer">
                    Already have an account? <a href="<?= h(base_url()) ?>/auth.php?action=login">Log in</a>
                </div>
            </div>
        </div>
        <?php
    }, true, 'register');
}

/* ======================================================================
 * RENDERING HELPERS
 * ====================================================================*/

/**
 * Render a full HTML page with optional navigation.
 *
 * @param string   $title    Page title.
 * @param callable $body     Callback that outputs the page body content.
 * @param bool     $show_nav Whether to show the navigation bar.
 * @param string   $nav_active Active top-nav item ID.
 * @return void
 */
function render_page(string $title, callable $body, bool $show_nav = true, string $nav_active = ''): void
{
    $base = base_url();
    $user = $show_nav ? current_user() : null;
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($title) ?> — StoryWeaver</title>
    <?php render_brand_favicon_links(); ?>
    <link rel="stylesheet" href="<?= h($base) ?>/_themes/<?= h(theme_css()) ?>">
</head>
    <body>
    <?php if ($show_nav): ?>
    <?php render_main_nav($user, $nav_active); ?>
    <?php endif; ?>

    <?php $body(); ?>

    <script src="<?= h($base) ?>/_assets/sw.js?v=<?= filemtime(sw_root() . '/_assets/sw.js') ?>"></script>
</body>
</html>
    <?php
}

/**
 * Render an array of error messages as flash-style alerts.
 *
 * @param array $errors List of error strings.
 * @return void
 */
function render_errors(array $errors): void
{
    foreach ($errors as $err) {
        echo '<div class="sw-flash sw-flash-error">' . h($err) . '</div>';
    }
}

/**
 * Render any pending flash messages from the session.
 *
 * @return void
 */
function render_flashes(): void
{
    $flashes = get_flashes();
    foreach ($flashes as $type => $messages) {
        foreach ($messages as $msg) {
            echo '<div class="sw-flash sw-flash-' . h($type) . '">'
               . h($msg)
               . '<button class="sw-flash-dismiss" aria-label="Dismiss">&times;</button>'
               . '</div>';
        }
    }
}
