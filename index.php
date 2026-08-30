<?php
/**
 * Highway Tires Management System - Login Page
 */

require_once 'includes/config.php';
session_name(SESSION_NAME);
session_start();
app_send_no_cache_headers();

// If already logged in, redirect
if (is_logged_in()) {
    $user = app_get_session_user();
    $redirect = ($user['role'] === 'admin') ? (APP_URL . '/admin/') : (APP_URL . '/front-desk/');
    redirect($redirect);
}

$error = '';
$login_id = '';
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
$login_background = '';
$login_background_candidates = [
    'assets/images/login-background.jpg',
    'assets/images/login-background.jpeg',
    'assets/images/login-background.png',
    'assets/images/login-background.webp',
];

foreach ($login_background_candidates as $login_background_path) {
    if (is_file(__DIR__ . '/' . $login_background_path)) {
        $login_background = APP_URL . '/' . $login_background_path;
        break;
    }
}

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['email']) && !empty($_POST['password'])) {
    $login_id = strtolower(trim($_POST['email']));
    $password = $_POST['password'];

    try {
        $sql = "SELECT id, name, email, password_hash, role, branch_id, status FROM users WHERE email = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$login_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $pwd_check = password_verify($password, $user['password_hash']);

            if ($pwd_check && $user['status'] === 'active') {
                $_SESSION['user'] = [
                    'id' => (int)$user['id'],
                    'name' => (string)$user['name'],
                    'email' => (string)$user['email'],
                    'role' => (string)$user['role'],
                    'branch_id' => (int)$user['branch_id'],
                    'status' => (string)$user['status']
                ];

                // Force session save before redirect
                session_write_close();

                $redirect = ($user['role'] === 'admin') ? (APP_URL . '/admin/') : (APP_URL . '/front-desk/');
                redirect($redirect);
            } else {
                $error = 'Invalid login ID or password.';
            }
        } else {
            $error = 'Invalid login ID or password.';
        }
    } catch (Exception $e) {
        $error = 'Database error: ' . $e->getMessage();
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>Sign In - <?php echo htmlspecialchars($login_brand['company_name']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script>
        window.addEventListener('pageshow', function(event) {
            if (event.persisted || (window.performance && window.performance.navigation && window.performance.navigation.type === 2)) {
                window.location.reload();
            }
        });
    </script>
    <style>
        :root {
            --primary: <?php echo htmlspecialchars($login_brand['primary_color']); ?>;
            --login-accent: #06b6d4;
            --login-accent-hover: #0284c7;
            --secondary: #334155;
            --text-dark: #ffffff;
            --text-muted: #94a3b8;
            --border: rgba(255, 255, 255, 0.16);
            --page-bg: #0b1120;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background:
                linear-gradient(135deg, rgba(15, 23, 42, 0.35) 0%, rgba(15, 23, 42, 0.50) 100%),
                <?php if ($login_background !== ''): ?>
                    url('<?php echo htmlspecialchars($login_background); ?>') center / cover no-repeat fixed,
                <?php endif; ?>
                var(--page-bg);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 32px 16px;
            color: var(--text-dark);
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        body::before {
            content: '';
            position: fixed;
            inset: 0;
            pointer-events: none;
            background: radial-gradient(circle at center, transparent 35%, rgba(0, 0, 0, 0.40) 100%);
        }

        .login-card {
            position: relative;
            z-index: 1;
            background: rgba(15, 23, 42, 0.72);
            backdrop-filter: blur(24px) saturate(180%);
            -webkit-backdrop-filter: blur(24px) saturate(180%);
            border: 1px solid rgba(255, 255, 255, 0.18);
            border-radius: 24px;
            box-shadow:
                0 30px 60px -12px rgba(0, 0, 0, 0.65),
                0 4px 20px rgba(0, 0, 0, 0.35),
                inset 0 1px 0 rgba(255, 255, 255, 0.25);
            max-width: 440px;
            width: 100%;
            padding: 42px 38px 34px;
            overflow: hidden;
            transition: transform 0.25s ease;
        }

        .login-header {
            text-align: center;
            margin-bottom: 28px;
        }

        .login-logo {
            width: 70px;
            height: 70px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 16px;
            background: #ffffff;
            border: 1.5px solid rgba(255, 255, 255, 0.9);
            border-radius: 18px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3);
            padding: 11px;
        }

        .login-logo img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
            display: block;
        }

        .login-header h1 {
            font-size: 24px;
            font-weight: 800;
            line-height: 1.2;
            margin: 0 0 6px;
            letter-spacing: -0.4px;
            color: #ffffff;
        }

        .login-header p {
            font-size: 13px;
            margin: 0;
            color: #94a3b8;
            font-weight: 500;
            line-height: 1.45;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-label {
            display: block;
            font-weight: 600;
            color: #cbd5e1;
            margin-bottom: 7px;
            font-size: 13px;
        }

        .input-icon-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-icon-wrapper .input-icon {
            position: absolute;
            left: 15px;
            color: rgba(255, 255, 255, 0.6);
            font-size: 15px;
            pointer-events: none;
            transition: color 0.2s ease;
            z-index: 5;
        }

        .form-control {
            height: 48px;
            border: 1.5px solid rgba(255, 255, 255, 0.16);
            border-radius: 10px;
            padding: 10px 14px 10px 44px;
            font-size: 14px;
            color: #ffffff;
            background: rgba(255, 255, 255, 0.07);
            transition: all 0.2s ease;
            font-weight: 500;
        }

        .form-control::placeholder {
            color: rgba(255, 255, 255, 0.35);
            font-weight: 400;
        }

        .form-control:focus {
            border-color: rgba(6, 182, 212, 0.8);
            background: rgba(255, 255, 255, 0.12);
            color: #ffffff;
            box-shadow: 0 0 0 4px rgba(6, 182, 212, 0.22);
        }

        .form-control:focus + .input-icon,
        .input-icon-wrapper:focus-within .input-icon {
            color: #06b6d4;
        }

        /* Prevent browser autofill from washing out background and icons */
        input:-webkit-autofill,
        input:-webkit-autofill:hover,
        input:-webkit-autofill:focus,
        input:-webkit-autofill:active {
            -webkit-box-shadow: 0 0 0 1000px #182234 inset !important;
            box-shadow: 0 0 0 1000px #182234 inset !important;
            -webkit-text-fill-color: #ffffff !important;
            caret-color: #ffffff !important;
            border-color: rgba(6, 182, 212, 0.6) !important;
            transition: background-color 5000s ease-in-out 0s;
        }

        .btn-password-toggle {
            position: absolute;
            right: 12px;
            background: none;
            border: none;
            color: rgba(255, 255, 255, 0.6);
            padding: 6px;
            cursor: pointer;
            font-size: 15px;
            transition: color 0.2s ease;
            z-index: 5;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .btn-password-toggle:hover {
            color: #ffffff;
        }

        .btn-login {
            background: linear-gradient(135deg, #06b6d4 0%, #0284c7 100%);
            color: #ffffff;
            border: none;
            border-radius: 10px;
            height: 50px;
            padding: 12px 24px;
            font-weight: 700;
            font-size: 15px;
            width: 100%;
            margin-top: 8px;
            box-shadow: 0 4px 18px rgba(6, 182, 212, 0.38);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s ease;
            cursor: pointer;
        }

        .btn-login:hover {
            background: linear-gradient(135deg, #0891b2 0%, #0369a1 100%);
            color: #ffffff;
            transform: translateY(-1px);
            box-shadow: 0 6px 24px rgba(6, 182, 212, 0.48);
        }

        .btn-login:active {
            transform: translateY(0);
            box-shadow: 0 2px 10px rgba(6, 182, 212, 0.3);
        }

        .login-footer-note {
            text-align: center;
            margin-top: 24px;
            padding-top: 18px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            font-size: 12px;
            color: #64748b;
            font-weight: 500;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        .alert {
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 13.5px;
            padding: 12px 16px;
            border: none;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        @media (max-width: 480px) {
            .login-card {
                padding: 32px 24px 24px;
                border-radius: 16px;
            }

            .login-header h1 {
                font-size: 22px;
            }

            body {
                background-attachment: scroll;
            }
        }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="login-header">
            <div class="login-logo">
                <img src="<?php echo htmlspecialchars($login_logo); ?>" alt="<?php echo htmlspecialchars($login_brand['company_name']); ?>" onerror="this.style.display='none';">
            </div>
            <h1><?php echo htmlspecialchars($login_brand['company_name']); ?></h1>
            <p><?php echo htmlspecialchars($login_brand['system_title']); ?></p>
        </div>

        <div class="login-body">
            <?php if (isset($_GET['timeout'])): ?>
                <div class="alert alert-warning" style="background:#fef3c7; color:#92400e; border:1px solid #fde68a;" role="alert">
                    <i class="fas fa-clock"></i> <span>Your session has expired due to inactivity. Please log in again.</span>
                </div>
            <?php endif; ?>
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger" role="alert">
                    <i class="fas fa-exclamation-circle"></i> <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" autocomplete="on">
                <div class="form-group">
                    <label class="form-label" for="loginEmail">Login ID / Email</label>
                    <div class="input-icon-wrapper">
                        <i class="fa-regular fa-user input-icon"></i>
                        <input type="text"
                               id="loginEmail"
                               name="email"
                               class="form-control"
                               value="<?php echo htmlspecialchars($login_id); ?>"
                               placeholder="Enter your login ID"
                               autocomplete="username"
                               required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="loginPassword">Password</label>
                    <div class="input-icon-wrapper">
                        <i class="fa-solid fa-lock input-icon"></i>
                        <input type="password"
                               id="loginPassword"
                               name="password"
                               class="form-control"
                               placeholder="Enter your password"
                               autocomplete="current-password"
                               required>
                        <button type="button" class="btn-password-toggle" id="togglePasswordBtn" title="Show/Hide Password" aria-label="Show/Hide Password">
                            <i class="fa-regular fa-eye" id="togglePasswordIcon"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn btn-login">
                    <span>Sign In</span>
                    <i class="fa-solid fa-arrow-right-to-bracket"></i>
                </button>
            </form>

            <div class="login-footer-note">
                <i class="fa-solid fa-shield-halved"></i>
                <span>Authorized Personnel Access Only</span>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const toggleBtn = document.getElementById('togglePasswordBtn');
        const passwordInput = document.getElementById('loginPassword');
        const toggleIcon = document.getElementById('togglePasswordIcon');

        if (toggleBtn && passwordInput && toggleIcon) {
            toggleBtn.addEventListener('click', function () {
                const isPassword = passwordInput.getAttribute('type') === 'password';
                passwordInput.setAttribute('type', isPassword ? 'text' : 'password');
                toggleIcon.classList.toggle('fa-eye', !isPassword);
                toggleIcon.classList.toggle('fa-eye-slash', isPassword);
            });
        }
    </script>
</body>
</html>
