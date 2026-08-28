<?php
/**
 * Shared User Profile
 */

require_once __DIR__ . '/includes/config.php';
session_name(SESSION_NAME);
session_start();

if (!is_logged_in()) {
    redirect('/hwtires/index.php');
}

$page_title = 'Profile';
$user = app_get_session_user();
$user_id = (int) ($user['id'] ?? 0);

if (!function_exists('profile_role_label')) {
    function profile_role_label($role) {
        return $role === 'admin' ? 'Admin/Owner' : 'Front Desk';
    }
}

if (!function_exists('profile_branch_label')) {
    function profile_branch_label($branch_name) {
        if (preg_match('/Branch\s+\d+/i', (string) $branch_name, $matches)) {
            return $matches[0];
        }

        return $branch_name ?: 'All Branches';
    }
}

if (!function_exists('profile_validate_login_id')) {
    function profile_validate_login_id($login_id) {
        return (bool) preg_match('/^[a-z0-9._@-]{3,100}$/i', $login_id);
    }
}

if (!function_exists('profile_login_id_exists')) {
    function profile_login_id_exists(PDO $pdo, $login_id, $exclude_id) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1");
        $stmt->execute([$login_id, (int) $exclude_id]);
        return (bool) $stmt->fetch();
    }
}

$stmt = $pdo->prepare("
    SELECT u.*, b.name AS branch_name
    FROM users u
    LEFT JOIN branches b ON b.id = u.branch_id
    WHERE u.id = ?
    LIMIT 1
");
$stmt->execute([$user_id]);
$account = $stmt->fetch();

if (!$account) {
    session_destroy();
    redirect('/hwtires/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            throw new Exception('Security check failed. Please try again.');
        }

        $name = trim($_POST['name'] ?? '');
        $login_id = strtolower(trim($_POST['login_id'] ?? ''));
        $current_password = (string) ($_POST['current_password'] ?? '');
        $new_password = (string) ($_POST['new_password'] ?? '');
        $confirm_password = (string) ($_POST['confirm_password'] ?? '');

        if ($name === '') {
            throw new Exception('Name is required.');
        }

        if (!profile_validate_login_id($login_id)) {
            throw new Exception('Login ID must be 3-100 characters and may use letters, numbers, dots, dashes, underscores, or @.');
        }

        if (profile_login_id_exists($pdo, $login_id, $user_id)) {
            throw new Exception('That login ID is already in use.');
        }

        $login_changed = $login_id !== strtolower((string) ($account['email'] ?? ''));
        $password_requested = $current_password !== '' || $new_password !== '' || $confirm_password !== '';

        if ($login_changed || $password_requested) {
            if ($current_password === '' || !password_verify($current_password, $account['password_hash'])) {
                throw new Exception('Enter your current password to save sensitive profile changes.');
            }
        }

        $fields = ['name = ?', 'email = ?'];
        $params = [$name, $login_id];

        if ($password_requested) {
            if (strlen($new_password) < 8) {
                throw new Exception('New password must be at least 8 characters.');
            }

            if ($new_password !== $confirm_password) {
                throw new Exception('New passwords do not match.');
            }

            $fields[] = 'password_hash = ?';
            $params[] = password_hash($new_password, PASSWORD_DEFAULT);
        }

        $params[] = $user_id;
        $update_stmt = $pdo->prepare("UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?");
        $update_stmt->execute($params);

        $_SESSION['user']['name'] = $name;
        $_SESSION['user']['email'] = $login_id;

        log_audit('users', 'profile_update', $user_id, [
            'name' => $account['name'] ?? null,
            'login_id' => $account['email'] ?? null,
        ], [
            'name' => $name,
            'login_id' => $login_id,
            'password_changed' => $password_requested,
        ]);

        set_flash_message('Profile updated successfully.', 'success');
        redirect('/hwtires/profile.php');
    } catch (Exception $e) {
        set_flash_message($e->getMessage(), 'danger');
        redirect('/hwtires/profile.php');
    }
}

$csrf_token = generate_csrf_token();
$dashboard_url = ($account['role'] ?? '') === 'admin' ? (APP_URL . '/admin/') : (APP_URL . '/front-desk/');
?>

<?php require_once __DIR__ . '/includes/header.php'; ?>
<?php require_once __DIR__ . '/includes/sidebar.php'; ?>

<main class="account-profile-page">
    <header class="account-profile-hero">
        <div>
            <h1>Profile</h1>
            <p>Manage your account details and sign-in password</p>
        </div>
        <a href="<?php echo esc_attr($dashboard_url); ?>" class="account-profile-back">
            <i class="fas fa-arrow-left"></i>
            <span>Back to Dashboard</span>
        </a>
    </header>

    <?php
    $flash_message = get_flash_message();
    if ($flash_message):
        $flash_type = $flash_message['type'] === 'error' ? 'danger' : $flash_message['type'];
    ?>
        <div class="alert alert-<?php echo esc_attr($flash_type); ?> alert-dismissible fade show account-profile-flash" role="alert">
            <?php echo esc_html($flash_message['message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <section class="account-profile-grid">
        <article class="account-profile-card account-profile-summary">
            <div class="account-profile-avatar">
                <?php echo esc_html(strtoupper(substr((string) ($account['name'] ?? 'U'), 0, 1))); ?>
            </div>
            <h2><?php echo esc_html($account['name']); ?></h2>
            <p><?php echo esc_html($account['email']); ?></p>
            <div class="account-profile-pills">
                <span><?php echo esc_html(profile_role_label($account['role'] ?? 'front-desk')); ?></span>
                <span><?php echo esc_html(profile_branch_label($account['branch_name'] ?? '')); ?></span>
                <span><?php echo esc_html(ucfirst($account['status'] ?? 'active')); ?></span>
            </div>
        </article>

        <form method="POST" class="account-profile-card account-profile-form">
            <input type="hidden" name="csrf_token" value="<?php echo esc_attr($csrf_token); ?>">

            <div class="account-profile-section-title">
                <span><i class="fas fa-user-gear"></i></span>
                <div>
                    <h2>Account Information</h2>
                    <p>Use your Login ID and password on the sign-in page</p>
                </div>
            </div>

            <div class="account-profile-form-grid">
                <label>
                    <span>Name</span>
                    <input type="text" name="name" value="<?php echo esc_attr($account['name']); ?>" required>
                </label>
                <label>
                    <span>Login ID</span>
                    <input type="text" name="login_id" value="<?php echo esc_attr($account['email']); ?>" required>
                </label>
                <label>
                    <span>Role</span>
                    <input type="text" value="<?php echo esc_attr(profile_role_label($account['role'] ?? 'front-desk')); ?>" readonly>
                </label>
                <label>
                    <span>Branch</span>
                    <input type="text" value="<?php echo esc_attr(profile_branch_label($account['branch_name'] ?? '')); ?>" readonly>
                </label>
            </div>

            <div class="account-profile-password">
                <h3>Change Password</h3>
                <p>Leave these fields blank to keep your current password. Current password is also required when changing Login ID.</p>
                <div class="account-profile-form-grid">
                    <label>
                        <span>Current Password</span>
                        <input type="password" name="current_password" autocomplete="current-password">
                    </label>
                    <label>
                        <span>New Password</span>
                        <input type="password" name="new_password" autocomplete="new-password">
                    </label>
                    <label>
                        <span>Confirm New Password</span>
                        <input type="password" name="confirm_password" autocomplete="new-password">
                    </label>
                </div>
            </div>

            <div class="account-profile-actions">
                <a href="<?php echo esc_attr($dashboard_url); ?>" class="account-profile-cancel">Cancel</a>
                <button type="submit" class="account-profile-save">
                    <i class="far fa-save"></i>
                    <span>Save Profile</span>
                </button>
            </div>
        </form>
    </section>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
