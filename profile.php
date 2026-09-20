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
            $strength_errors = app_validate_password_strength($new_password);
            if (!empty($strength_errors)) {
                throw new Exception(implode(' ', $strength_errors));
            }

            if ($new_password !== $confirm_password) {
                throw new Exception('New passwords do not match.');
            }

            $fields[] = 'password_hash = ?';
            $params[] = password_hash($new_password, PASSWORD_DEFAULT);
            $fields[] = 'password_changed_at = NOW()';
            $fields[] = 'must_change_password = 0';
        }

        $params[] = $user_id;
        $update_stmt = $pdo->prepare("UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?");
        $update_stmt->execute($params);

        $_SESSION['user']['name'] = $name;
        $_SESSION['user']['email'] = $login_id;
        if ($password_requested) {
            $_SESSION['user']['must_change_password'] = 0;
        }

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
                        <div class="profile-pwd-input-wrap">
                            <input type="password" id="profile_current_password" name="current_password" autocomplete="current-password">
                            <button type="button" class="btn-password-toggle" data-target="profile_current_password" aria-label="Show password">
                                <i class="far fa-eye fa-fw" aria-hidden="true"></i>
                            </button>
                        </div>
                    </label>
                    <label>
                        <span>New Password</span>
                        <div class="profile-pwd-input-wrap">
                            <input type="password" id="profile_new_password" name="new_password" autocomplete="new-password">
                            <button type="button" class="btn-password-toggle" data-target="profile_new_password" aria-label="Show password">
                                <i class="far fa-eye fa-fw" aria-hidden="true"></i>
                            </button>
                        </div>
                    </label>
                    <label>
                        <span>Confirm New Password</span>
                        <div class="profile-pwd-input-wrap">
                            <input type="password" id="profile_confirm_password" name="confirm_password" autocomplete="new-password">
                            <button type="button" class="btn-password-toggle" data-target="profile_confirm_password" aria-label="Show password">
                                <i class="far fa-eye fa-fw" aria-hidden="true"></i>
                            </button>
                        </div>
                    </label>
                </div>

                <div class="profile-pwd-rules" id="profilePwdRulesBox">
                    <strong><i class="fas fa-shield-halved me-1"></i> Password Requirements:</strong>
                    <ul class="profile-pwd-checklist">
                        <li id="prof-rule-length"><i class="far fa-circle rule-icon"></i> At least 8 characters</li>
                        <li id="prof-rule-upper"><i class="far fa-circle rule-icon"></i> One uppercase letter (A-Z)</li>
                        <li id="prof-rule-lower"><i class="far fa-circle rule-icon"></i> One lowercase letter (a-z)</li>
                        <li id="prof-rule-number"><i class="far fa-circle rule-icon"></i> One number (0-9)</li>
                        <li id="prof-rule-special"><i class="far fa-circle rule-icon"></i> One special character</li>
                    </ul>
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

<style>
.profile-pwd-input-wrap {
    position: relative;
    width: 100%;
    display: flex;
    align-items: center;
}
.profile-pwd-input-wrap input {
    width: 100%;
    display: block;
    padding-right: 44px !important;
}
.profile-pwd-input-wrap input::-ms-reveal,
.profile-pwd-input-wrap input::-ms-clear {
    display: none !important;
    width: 0 !important;
    height: 0 !important;
}
/* Strict geometry lock for password toggle button in ALL states */
.profile-pwd-input-wrap .btn-password-toggle,
.profile-pwd-input-wrap .btn-password-toggle:hover,
.profile-pwd-input-wrap .btn-password-toggle:focus,
.profile-pwd-input-wrap .btn-password-toggle:focus-visible,
.profile-pwd-input-wrap .btn-password-toggle:active,
.main-content .content .profile-pwd-input-wrap .btn-password-toggle,
.main-content .content .profile-pwd-input-wrap .btn-password-toggle:hover,
.main-content .content .profile-pwd-input-wrap .btn-password-toggle:focus,
.main-content .content .profile-pwd-input-wrap .btn-password-toggle:focus-visible,
.main-content .content .profile-pwd-input-wrap .btn-password-toggle:active {
    position: absolute !important;
    right: 6px !important;
    top: 50% !important;
    transform: translateY(-50%) !important;
    width: 36px !important;
    height: 36px !important;
    padding: 0 !important;
    margin: 0 !important;
    border: 0 !important;
    box-shadow: none !important;
    outline: none !important;
    background: transparent !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    box-sizing: border-box !important;
    line-height: 1 !important;
    cursor: pointer;
    z-index: 5;
    transition: color 0.2s ease;
}

.profile-pwd-input-wrap .btn-password-toggle:hover {
    color: #00183a;
}

.profile-pwd-input-wrap .btn-password-toggle:focus,
.profile-pwd-input-wrap .btn-password-toggle:focus-visible {
    color: #06b6d4;
}

.profile-pwd-input-wrap .btn-password-toggle:active {
    color: #00183a;
}

/* Strict geometry lock for the icon in ALL states */
.profile-pwd-input-wrap .btn-password-toggle i,
.profile-pwd-input-wrap .btn-password-toggle:hover i,
.profile-pwd-input-wrap .btn-password-toggle:focus i,
.profile-pwd-input-wrap .btn-password-toggle:focus-visible i,
.profile-pwd-input-wrap .btn-password-toggle:active i {
    width: 20px !important;
    height: 20px !important;
    line-height: 20px !important;
    font-size: 15px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    text-align: center !important;
    margin: 0 !important;
    padding: 0 !important;
    border: 0 !important;
    transform: none !important;
    transition: none !important;
}

/* Strict geometry lock for the slash overlay */
.profile-pwd-input-wrap .btn-password-toggle.is-password-visible::after {
    content: "";
    position: absolute;
    width: 18px;
    height: 1.5px;
    background: currentColor;
    left: 50%;
    top: 50%;
    transform: translate(-50%, -50%) rotate(-45deg) !important;
    transform-origin: center;
    pointer-events: none;
    transition: none !important;
}
.profile-pwd-rules {
    margin-top: 18px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 14px 16px;
    max-width: 520px;
}
.profile-pwd-rules strong {
    font-size: 13px;
    color: #00183a;
    display: block;
    margin-bottom: 8px;
}
.profile-pwd-checklist {
    list-style: none;
    padding-left: 0;
    margin: 0;
}
.profile-pwd-checklist li {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 5px;
    color: #64748b;
    font-size: 12.5px;
    transition: color 0.2s ease;
}
.profile-pwd-checklist li.valid {
    color: #059669;
    font-weight: 600;
}
.profile-pwd-checklist li .rule-icon {
    font-size: 12px;
    color: #94a3b8;
    transition: color 0.2s ease;
}
.profile-pwd-checklist li.valid .rule-icon {
    color: #059669;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.btn-password-toggle').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const targetId = this.getAttribute('data-target');
            const input = document.getElementById(targetId);
            if (input) {
                const isPwd = input.getAttribute('type') === 'password';
                input.setAttribute('type', isPwd ? 'text' : 'password');
                if (isPwd) {
                    this.classList.add('is-password-visible');
                    this.setAttribute('aria-label', 'Hide password');
                } else {
                    this.classList.remove('is-password-visible');
                    this.setAttribute('aria-label', 'Show password');
                }
            }
        });
    });

    const newPwdInput = document.getElementById('profile_new_password');
    if (newPwdInput) {
        const rules = {
            length: { el: document.getElementById('prof-rule-length'), test: function(p) { return p.length >= 8; } },
            upper: { el: document.getElementById('prof-rule-upper'), test: function(p) { return /[A-Z]/.test(p); } },
            lower: { el: document.getElementById('prof-rule-lower'), test: function(p) { return /[a-z]/.test(p); } },
            number: { el: document.getElementById('prof-rule-number'), test: function(p) { return /[0-9]/.test(p); } },
            special: { el: document.getElementById('prof-rule-special'), test: function(p) { return /[^A-Za-z0-9\s]/.test(p); } }
        };

        function updateChecklist() {
            const val = newPwdInput.value || '';
            for (const key in rules) {
                const rule = rules[key];
                if (!rule.el) continue;
                const passed = rule.test(val);
                rule.el.classList.toggle('valid', passed);
                const icon = rule.el.querySelector('.rule-icon');
                if (icon) {
                    icon.className = passed ? 'fas fa-check-circle rule-icon' : 'far fa-circle rule-icon';
                }
            }
        }

        newPwdInput.addEventListener('input', updateChecklist);
        updateChecklist();
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
