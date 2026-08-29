<?php
app_send_no_cache_headers();

$app_primary_color = null;
$custom_css_file = __DIR__ . '/../assets/css/custom.css';
$custom_css_version = is_file($custom_css_file) ? filemtime($custom_css_file) : time();

try {
    if (isset($pdo)) {
        $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute(['primary_color']);
        $setting_color = strtoupper((string) $stmt->fetchColumn());

        if (preg_match('/^#[0-9A-F]{6}$/', $setting_color)) {
            $app_primary_color = $setting_color;
        }
    }
} catch (Exception $e) {
    $app_primary_color = null;
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
    <title><?php echo esc_html($page_title ?? 'HW Tires Management'); ?></title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="<?php echo APP_URL; ?>/assets/css/custom.css?v=<?php echo (int) $custom_css_version; ?>">
    <?php if ($app_primary_color): ?>
    <style>
        :root {
            --primary-color: <?php echo esc_html($app_primary_color); ?>;
            --primary-dark: <?php echo esc_html($app_primary_color); ?>;
        }
    </style>
    <?php endif; ?>
    <!-- Icon -->
    <link rel="icon" type="image/x-icon" href="<?php echo APP_URL; ?>/assets/images/favicon.ico">
    <script>
        // Force reload if page is restored from browser back-forward cache (bfcache) after logout
        window.addEventListener('pageshow', function(event) {
            if (event.persisted || (window.performance && window.performance.navigation && window.performance.navigation.type === 2)) {
                window.location.reload();
            }
        });
    </script>
</head>
<body>
