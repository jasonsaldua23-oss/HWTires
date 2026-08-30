<?php
/**
 * Highway Tires - Custom Branded 404 Error Page
 */

require_once __DIR__ . '/includes/config.php';
session_name(SESSION_NAME);
session_start();

$user = app_get_session_user();
$dashboard_url = APP_URL . '/index.php';

if (is_array($user) && !empty($user['role'])) {
    $dashboard_url = ($user['role'] === 'admin') ? (APP_URL . '/admin/') : (APP_URL . '/front-desk/');
}

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
} catch (Exception $e) {}

$company_logo = APP_URL . '/' . ltrim($login_brand['company_logo'], '/');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Page Not Found - <?php echo htmlspecialchars($login_brand['company_name']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&amp;display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            color: #ffffff;
        }
        .error-card {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.12);
            backdrop-filter: blur(16px);
            border-radius: 20px;
            padding: 44px 36px;
            max-width: 520px;
            width: 100%;
            text-align: center;
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.35);
        }
        .brand-logo {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 24px;
        }
        .brand-logo img {
            max-height: 48px;
            max-width: 180px;
            object-fit: contain;
        }
        .error-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 16px;
            background: rgba(6, 182, 212, 0.15);
            border: 1px solid rgba(6, 182, 212, 0.4);
            border-radius: 999px;
            color: #38bdf8;
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 18px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .error-title {
            font-size: 26px;
            font-weight: 800;
            color: #ffffff;
            margin-bottom: 12px;
            line-height: 1.25;
        }
        .error-desc {
            font-size: 14.5px;
            color: #94a3b8;
            line-height: 1.6;
            margin-bottom: 30px;
        }
        .actions {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 14px;
            flex-wrap: wrap;
        }
        .btn-primary {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            background: #06b6d4;
            color: #0f172a;
            text-decoration: none;
            font-weight: 750;
            font-size: 14.5px;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .btn-primary:hover {
            background: #22d3ee;
            transform: translateY(-1px);
        }
        .btn-secondary {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 22px;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.18);
            color: #ffffff;
            text-decoration: none;
            font-weight: 650;
            font-size: 14.5px;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.15);
        }
    </style>
</head>
<body>
    <div class="error-card">
        <div class="brand-logo">
            <img src="<?php echo htmlspecialchars($company_logo); ?>" alt="<?php echo htmlspecialchars($login_brand['company_name']); ?>" onerror="this.style.display='none';">
        </div>
        <div>
            <span class="error-badge"><i class="fas fa-circle-exclamation"></i> 404 Notice</span>
        </div>
        <h1 class="error-title">Page or Resource Not Found</h1>
        <p class="error-desc">
            The page you are looking for might have been moved, renamed, or is temporarily unavailable.
        </p>
        <div class="actions">
            <a href="<?php echo htmlspecialchars($dashboard_url); ?>" class="btn-primary">
                <i class="fas fa-house"></i>
                <span>Return to Dashboard</span>
            </a>
            <button type="button" onclick="history.back()" class="btn-secondary">
                <i class="fas fa-arrow-left"></i>
                <span>Go Back</span>
            </button>
        </div>
    </div>
</body>
</html>