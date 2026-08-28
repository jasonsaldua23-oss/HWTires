<?php
/**
 * Highway Tires Management System - Login Page
 */

require_once 'includes/config.php';
session_name(SESSION_NAME);
session_start();

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
    <title>Login - <?php echo htmlspecialchars($login_brand['company_name']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: <?php echo htmlspecialchars($login_brand['primary_color']); ?>;
            --login-accent: #0f766e;
            --login-accent-hover: #115e59;
            --secondary: #263238;
            --text-dark: #202936;
            --text-muted: #667085;
            --border: #d7dde5;
            --page-bg: #f4f6f8;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background:
                linear-gradient(90deg, rgba(244, 246, 248, 0.96) 0%, rgba(244, 246, 248, 0.88) 48%, rgba(244, 246, 248, 0.76) 100%),
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
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body::before {
            content: '';
            position: fixed;
            inset: 0;
            pointer-events: none;
            background:
                linear-gradient(135deg, rgba(15, 118, 110, 0.08), transparent 42%),
                linear-gradient(315deg, rgba(183, 121, 31, 0.08), transparent 38%);
        }

        .login-card {
            position: relative;
            z-index: 1;
            background: white;
            border: 1px solid var(--border);
            border-radius: 8px;
            box-shadow: 0 1px 2px rgba(16, 24, 40, 0.06), 0 18px 46px rgba(16, 24, 40, 0.16);
            max-width: 430px;
            width: 100%;
            overflow: hidden;
        }

        .login-header {
            background: #ffffff;
            min-height: auto;
            padding: 34px 38px 26px;
            text-align: left;
            color: var(--text-dark);
            border-bottom: 1px solid var(--border);
        }

        .login-logo {
            width: 54px;
            height: 54px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 0 18px;
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: 8px;
        }

        .login-logo img {
            max-width: 42px;
            max-height: 42px;
            object-fit: contain;
            display: block;
        }

        .login-header h1 {
            font-size: 25px;
            font-weight: 750;
            line-height: 1.15;
            margin: 0;
            letter-spacing: 0;
            color: var(--text-dark);
        }

        .login-header p {
            font-size: 13px;
            margin: 8px 0 0;
            color: var(--text-muted);
            opacity: 1;
            font-weight: 600;
        }

        .login-body {
            padding: 30px 38px 36px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            font-weight: 750;
            color: var(--text-dark);
            margin-bottom: 8px;
            font-size: 13px;
        }

        .form-control {
            height: 44px;
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 10px 15px;
            font-size: 14px;
            color: var(--text-dark);
        }

        .form-control:focus {
            border-color: var(--login-accent);
            box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.12);
        }

        .btn-login {
            background: var(--login-accent);
            color: white;
            border: none;
            border-radius: 6px;
            min-height: 48px;
            padding: 12px;
            font-weight: 750;
            width: 100%;
            margin-top: 6px;
        }

        .btn-login:hover {
            background: var(--login-accent-hover);
            color: white;
        }

        .alert {
            border-radius: 8px;
            margin-bottom: 20px;
            border: none;
        }

        @media (max-width: 520px) {
            .login-header {
                padding: 34px 28px;
            }

            .login-body {
                padding: 34px 28px;
            }

            body {
                background-position: center;
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
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger" role="alert">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <div class="form-group">
                    <label class="form-label">Login ID</label>
                    <input type="text"
                           name="email"
                           class="form-control"
                           value="<?php echo htmlspecialchars($login_id); ?>"
                           placeholder="Enter your login ID"
                           required>
                </div>

                <div class="form-group">
                    <label class="form-label">Password</label>
                    <input type="password"
                           name="password"
                           class="form-control"
                           placeholder="Enter your password"
                           required>
                </div>

                <input type="submit" class="btn btn-login" value="Sign In">
            </form>

        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
