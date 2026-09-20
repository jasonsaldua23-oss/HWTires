<?php
/**
 * Highway Tires Management System - Forced Password Change Page
 * Strictly enforced for users created or reset with a temporary password.
 */

require_once __DIR__ . '/includes/config.php';
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_name(SESSION_NAME);
    session_start();
}
app_send_no_cache_headers();

// Access Control: Must be logged in
if (!isset($_SESSION['user']) || empty($_SESSION['user']['id'])) {
    redirect(APP_URL . '/index.php');
}

$user = app_get_session_user();
$user_id = (int) ($user['id'] ?? 0);
$dashboard_url = ($user['role'] === 'admin') ? (APP_URL . '/admin/') : (APP_URL . '/front-desk/');

// If user does NOT need to change password, redirect to dashboard
if (empty($user['must_change_password'])) {
    redirect($dashboard_url);
}

$error = '';
$success = '';

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            throw new Exception('Security check failed. Please refresh and try again.');
        }

        $current_password = (string) ($_POST['current_password'] ?? '');
        $new_password = (string) ($_POST['new_password'] ?? '');
        $confirm_password = (string) ($_POST['confirm_password'] ?? '');

        if ($current_password === '') {
            throw new Exception('Please enter your current temporary password.');
        }

        $strength_errors = app_validate_password_strength($new_password);
        if (!empty($strength_errors)) {
            throw new Exception(implode(' ', $strength_errors));
        }

        if ($new_password !== $confirm_password) {
            throw new Exception('New password and confirmation do not match.');
        }

        if ($new_password === $current_password) {
            throw new Exception('New password must be different from your temporary password.');
        }

        // Fetch fresh record from DB
        $stmt = $pdo->prepare("SELECT id, email, password_hash FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$user_id]);
        $db_user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$db_user) {
            throw new Exception('User record not found.');
        }

        // Verify current temporary password
        if (!password_verify($current_password, $db_user['password_hash'])) {
            throw new Exception('The temporary password you entered is incorrect.');
        }

        // Update password hash, clear must_change_password flag, set timestamp
        $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
        $update_stmt = $pdo->prepare("
            UPDATE users 
            SET password_hash = ?, 
                must_change_password = 0, 
                password_changed_at = NOW() 
            WHERE id = ?
        ");
        $update_stmt->execute([$new_hash, $user_id]);

        // Update session flag
        $_SESSION['user']['must_change_password'] = 0;
        $_SESSION['last_activity'] = time();

        // Log audit event
        log_audit('users', 'password_change_first_login', $user_id, null, [
            'login_id' => $user['email'] ?? null,
            'must_change_password' => 0,
        ]);

        set_flash_message('Your password has been successfully created. Welcome!', 'success');
        redirect($dashboard_url);
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$csrf_token = generate_csrf_token();

$login_brand = [
    'company_name' => 'Highway Tires',
    'system_title' => 'Branch Data Management System',
    'company_logo' => 'assets/images/logo.png',
    'primary_color' => '#06B6D4',
];

try {
    $settings_stmt = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('company_name', 'system_title', 'company_logo', 'primary_color')");
    $settings_stmt->execute();
    foreach ($settings_stmt->fetchAll() as $setting) {
        if (array_key_exists($setting['setting_key'], $login_brand) && trim((string) $setting['setting_value']) !== '') {
            $login_brand[$setting['setting_key']] = trim((string) $setting['setting_value']);
        }
    }
} catch (Exception $e) {
}

if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $login_brand['primary_color'])) {
    $login_brand['primary_color'] = '#06B6D4';
}

$login_logo = APP_URL . '/' . ltrim($login_brand['company_logo'], '/');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>Create Your Password - <?php echo htmlspecialchars($login_brand['company_name']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: <?php echo htmlspecialchars($login_brand['primary_color']); ?>;
            --page-bg: #0b1120;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: linear-gradient(135deg, rgba(15, 23, 42, 0.95) 0%, rgba(15, 23, 42, 0.98) 100%), var(--page-bg);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 32px 16px;
            color: #ffffff;
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        .pwd-card {
            background: rgba(15, 23, 42, 0.85);
            backdrop-filter: blur(24px);
            border: 1px solid rgba(255, 255, 255, 0.16);
            border-radius: 24px;
            box-shadow: 0 30px 60px -12px rgba(0, 0, 0, 0.75);
            max-width: 480px;
            width: 100%;
            padding: 38px 34px;
        }

        .pwd-header {
            text-align: center;
            margin-bottom: 24px;
        }

        .pwd-icon-circle {
            width: 64px;
            height: 64px;
            margin: 0 auto 16px;
            background: rgba(6, 182, 212, 0.15);
            border: 1.5px solid rgba(6, 182, 212, 0.4);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #06b6d4;
            font-size: 26px;
        }

        .pwd-header h1 {
            font-size: 22px;
            font-weight: 700;
            margin: 0 0 8px;
        }

        .pwd-header p {
            color: #94a3b8;
            font-size: 13.5px;
            margin: 0;
            line-height: 1.5;
        }

        .user-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.14);
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 13px;
            color: #cbd5e1;
            margin-top: 12px;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #cbd5e1;
            margin-bottom: 7px;
        }

        .input-icon-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #64748b;
            font-size: 15px;
            pointer-events: none;
            z-index: 3;
        }

        .form-control {
            width: 100%;
            display: block;
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.16);
            border-radius: 12px;
            padding: 11px 44px 11px 40px;
            color: #ffffff;
            font-size: 14px;
            transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
        }

        .form-control:focus {
            background: rgba(255, 255, 255, 0.1);
            border-color: #06b6d4;
            box-shadow: 0 0 0 3px rgba(6, 182, 212, 0.25);
            color: #ffffff;
            outline: none;
        }

        .form-control::-ms-reveal,
        .form-control::-ms-clear {
            display: none !important;
            width: 0 !important;
            height: 0 !important;
        }

        /* Strict geometry lock for password toggle button in ALL states */
        .btn-password-toggle,
        .btn-password-toggle:hover,
        .btn-password-toggle:focus,
        .btn-password-toggle:focus-visible,
        .btn-password-toggle:active {
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

        .btn-password-toggle:hover {
            color: #ffffff;
        }

        .btn-password-toggle:focus,
        .btn-password-toggle:focus-visible {
            color: #06b6d4;
        }

        .btn-password-toggle:active {
            color: #ffffff;
        }

        /* Strict geometry lock for the icon in ALL states */
        .btn-password-toggle i,
        .btn-password-toggle:hover i,
        .btn-password-toggle:focus i,
        .btn-password-toggle:focus-visible i,
        .btn-password-toggle:active i {
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
        .btn-password-toggle.is-password-visible::after {
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

        .pwd-rules {
            background: rgba(6, 182, 212, 0.08);
            border: 1px solid rgba(6, 182, 212, 0.2);
            border-radius: 12px;
            padding: 12px 14px;
            margin-bottom: 22px;
            font-size: 12.5px;
            color: #cbd5e1;
        }

        .pwd-checklist {
            list-style: none;
            padding-left: 0;
            margin: 8px 0 0;
        }

        .pwd-checklist li {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 5px;
            color: #94a3b8;
            font-size: 12.5px;
            transition: color 0.2s ease;
        }

        .pwd-checklist li.valid {
            color: #34d399;
            font-weight: 600;
        }

        .pwd-checklist li .rule-icon {
            font-size: 12px;
            color: #94a3b8;
            transition: color 0.2s ease;
        }

        .pwd-checklist li.valid .rule-icon {
            color: #34d399;
        }

        .btn-submit {
            width: 100%;
            background: linear-gradient(135deg, #06b6d4 0%, #0284c7 100%);
            color: #ffffff;
            border: none;
            border-radius: 12px;
            padding: 13px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: opacity 0.2s, transform 0.1s;
        }

        .btn-submit:hover {
            opacity: 0.95;
            transform: translateY(-1px);
        }

        .pwd-footer {
            text-align: center;
            margin-top: 20px;
        }

        .pwd-footer a {
            color: #94a3b8;
            text-decoration: none;
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .pwd-footer a:hover {
            color: #ffffff;
        }
    </style>
</head>
<body>
    <div class="pwd-card">
        <div class="pwd-header">
            <div class="pwd-icon-circle">
                <i class="fas fa-shield-halved"></i>
            </div>
            <h1>Create Your New Password</h1>
            <p>Your account was set with a temporary password. Please create your personal password to continue.</p>
            <div class="user-pill">
                <i class="fas fa-user-check"></i>
                <span><?php echo esc_html($user['name'] ?? ''); ?> (<?php echo esc_html($user['email'] ?? ''); ?>)</span>
            </div>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger" role="alert" style="background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.4); color: #fca5a5; font-size: 13.5px; border-radius: 12px;">
                <i class="fas fa-exclamation-circle me-1"></i> <?php echo esc_html($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo esc_attr($csrf_token); ?>">

            <div class="form-group">
                <label class="form-label" for="current_password">Current / Temporary Password</label>
                <div class="input-icon-wrapper">
                    <i class="fas fa-key input-icon"></i>
                    <input type="password" id="current_password" name="current_password" class="form-control" placeholder="Enter the temporary password" required>
                    <button type="button" class="btn-password-toggle" data-target="current_password" aria-label="Show password">
                        <i class="far fa-eye fa-fw" aria-hidden="true"></i>
                    </button>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="new_password">New Personal Password</label>
                <div class="input-icon-wrapper">
                    <i class="fas fa-lock input-icon"></i>
                    <input type="password" id="new_password" name="new_password" class="form-control" placeholder="Minimum 8 characters" minlength="8" required>
                    <button type="button" class="btn-password-toggle" data-target="new_password" aria-label="Show password">
                        <i class="far fa-eye fa-fw" aria-hidden="true"></i>
                    </button>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="confirm_password">Confirm New Password</label>
                <div class="input-icon-wrapper">
                    <i class="fas fa-lock-open input-icon"></i>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" placeholder="Re-type new password" minlength="8" required>
                    <button type="button" class="btn-password-toggle" data-target="confirm_password" aria-label="Show password">
                        <i class="far fa-eye fa-fw" aria-hidden="true"></i>
                    </button>
                </div>
            </div>

            <div class="pwd-rules" id="pwdRulesBox">
                <strong><i class="fas fa-shield-halved me-1"></i> Password Requirements:</strong>
                <ul class="pwd-checklist">
                    <li id="rule-length"><i class="far fa-circle rule-icon"></i> At least 8 characters</li>
                    <li id="rule-upper"><i class="far fa-circle rule-icon"></i> One uppercase letter (A-Z)</li>
                    <li id="rule-lower"><i class="far fa-circle rule-icon"></i> One lowercase letter (a-z)</li>
                    <li id="rule-number"><i class="far fa-circle rule-icon"></i> One number (0-9)</li>
                    <li id="rule-special"><i class="far fa-circle rule-icon"></i> One special character</li>
                </ul>
            </div>

            <button type="submit" class="btn-submit">
                <span>Save Password & Continue</span>
                <i class="fas fa-arrow-right"></i>
            </button>
        </form>

        <div class="pwd-footer">
            <a href="<?php echo APP_URL; ?>/logout.php" onclick="return confirm('Do you want to log out and complete password setup later?')">
                <i class="fas fa-arrow-right-from-bracket"></i> Log out and finish later
            </a>
        </div>
    </div>

    <script>
        document.querySelectorAll('.btn-password-toggle').forEach(function(btn) {
            btn.addEventListener('click', function() {
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

        const newPwdInput = document.getElementById('new_password');
        if (newPwdInput) {
            const rules = {
                length: { el: document.getElementById('rule-length'), test: function(p) { return p.length >= 8; } },
                upper: { el: document.getElementById('rule-upper'), test: function(p) { return /[A-Z]/.test(p); } },
                lower: { el: document.getElementById('rule-lower'), test: function(p) { return /[a-z]/.test(p); } },
                number: { el: document.getElementById('rule-number'), test: function(p) { return /[0-9]/.test(p); } },
                special: { el: document.getElementById('rule-special'), test: function(p) { return /[^A-Za-z0-9\s]/.test(p); } }
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
    </script>
</body>
</html>
