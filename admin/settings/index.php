<?php
/**
 * System Settings
 */

require_once '../../includes/config.php';
session_name(SESSION_NAME);
session_start();

if (!is_logged_in()) {
    redirect('/hwtires/index.php');
}

$page_title = 'Settings';
$user = app_get_session_user();

if ($user['role'] !== 'admin') {
    set_flash_message('You do not have permission to access settings.', 'danger');
    redirect('/hwtires/' . $user['role'] . '/index.php');
}

function load_system_settings(PDO $pdo): array
{
    $settings = [];
    $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings");
    $stmt->execute();

    foreach ($stmt->fetchAll() as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }

    return $settings;
}

function settings_normalize_phone($phone): string
{
    return preg_replace('/\D+/', '', trim((string) $phone));
}

function settings_is_valid_ph_mobile($phone): bool
{
    return preg_match('/^09\d{9}$/', (string) $phone) === 1;
}

$settings = load_system_settings($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $company_name = trim($_POST['company_name'] ?? '');
    $system_title = trim($_POST['system_title'] ?? '');
    $contact_email = trim($_POST['contact_email'] ?? '');
    $contact_phone = settings_normalize_phone($_POST['contact_phone'] ?? '');
    $primary_color = trim($_POST['primary_color'] ?? '#06B6D4');

    if ($primary_color !== '' && $primary_color[0] !== '#') {
        $primary_color = '#' . $primary_color;
    }

    $errors = [];

    if ($company_name === '') {
        $errors[] = 'Company name is required.';
    }

    if ($system_title === '') {
        $errors[] = 'System title is required.';
    }

    if ($contact_email !== '' && !filter_var($contact_email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid contact email.';
    }

    if ($contact_phone !== '' && !settings_is_valid_ph_mobile($contact_phone)) {
        $errors[] = 'Contact phone must be an 11-digit Philippine mobile number starting with 09, e.g. 09171234567.';
    }

    if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $primary_color)) {
        $errors[] = 'Primary color must be a valid hex color.';
    }

    $logo_path = $settings['company_logo'] ?? 'assets/images/logo.png';

    if (isset($_FILES['company_logo']) && $_FILES['company_logo']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['company_logo']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Logo upload failed. Please try again.';
        } elseif ($_FILES['company_logo']['size'] > 5 * 1024 * 1024) {
            $errors[] = 'Company logo must not exceed 5MB.';
        } else {
            $extension = strtolower(pathinfo($_FILES['company_logo']['name'], PATHINFO_EXTENSION));
            $allowed_extensions = ['png', 'jpg', 'jpeg'];

            if (!in_array($extension, $allowed_extensions, true)) {
                $errors[] = 'Company logo must be a PNG or JPG image.';
            } else {
                $upload_dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'uploads';

                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0775, true);
                }

                $safe_extension = $extension === 'jpeg' ? 'jpg' : $extension;
                $file_name = 'company-logo-' . date('YmdHis') . '.' . $safe_extension;
                $target_path = $upload_dir . DIRECTORY_SEPARATOR . $file_name;

                if (move_uploaded_file($_FILES['company_logo']['tmp_name'], $target_path)) {
                    $logo_path = 'assets/images/uploads/' . $file_name;
                } else {
                    $errors[] = 'Unable to save the uploaded logo.';
                }
            }
        }
    }

    if ($errors) {
        set_flash_message(implode(' ', $errors), 'danger');
    } else {
        try {
            $setting_values = [
                'company_name' => $company_name,
                'system_title' => $system_title,
                'contact_email' => $contact_email,
                'contact_phone' => $contact_phone,
                'company_logo' => $logo_path,
                'primary_color' => strtoupper($primary_color),
            ];

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO system_settings (setting_key, setting_value, updated_at)
                VALUES (?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    setting_value = VALUES(setting_value),
                    updated_at = NOW()
            ");

            foreach ($setting_values as $key => $value) {
                $stmt->execute([$key, $value]);
            }

            $pdo->commit();
            set_flash_message('Settings saved successfully.', 'success');
            redirect($_SERVER['REQUEST_URI']);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            set_flash_message('Error saving settings: ' . $e->getMessage(), 'danger');
        }
    }

    $settings = load_system_settings($pdo);
}

$company_name_value = $settings['company_name'] ?? 'Highway Tires';
$system_title_value = $settings['system_title'] ?? 'Branch Data Management System';
$contact_email_value = $settings['contact_email'] ?? 'info@highwaytires.com';
$contact_phone_value = $settings['contact_phone'] ?? '';
$primary_color_value = $settings['primary_color'] ?? '#06B6D4';
$logo_path_value = $settings['company_logo'] ?? 'assets/images/logo.png';
$logo_src = APP_URL . '/' . ltrim($logo_path_value, '/');
?>

<?php require_once '../../includes/header.php'; ?>
<?php require_once '../../includes/sidebar.php'; ?>

<div class="settings-page">
    <form method="POST" action="" enctype="multipart/form-data" id="settingsForm">
        <input type="hidden" name="action" value="save">

        <section class="settings-hero">
            <div>
                <h1>System Settings</h1>
                <p>Configure your system preferences and branding</p>
            </div>

            <button type="submit" class="settings-save-btn">
                <i class="fas fa-save"></i>
                <span>Save Settings</span>
            </button>
        </section>

        <?php
        $flash_message = get_flash_message();
        if ($flash_message):
        ?>
            <div class="alert alert-<?php echo esc_attr($flash_message['type']); ?> alert-dismissible fade show" role="alert">
                <?php echo esc_html($flash_message['message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <section class="settings-panel">
            <div class="settings-section-heading">
                <span><i class="fas fa-building"></i></span>
                <h2>Company Information</h2>
            </div>

            <div class="settings-form-grid">
                <label for="company_name">
                    <span>Company Name</span>
                    <input
                        type="text"
                        id="company_name"
                        name="company_name"
                        value="<?php echo esc_attr($company_name_value); ?>"
                        required
                    >
                </label>

                <label for="system_title">
                    <span>System Title</span>
                    <input
                        type="text"
                        id="system_title"
                        name="system_title"
                        value="<?php echo esc_attr($system_title_value); ?>"
                        required
                    >
                </label>

                <label for="contact_email">
                    <span>Contact Email</span>
                    <input
                        type="email"
                        id="contact_email"
                        name="contact_email"
                        value="<?php echo esc_attr($contact_email_value); ?>"
                    >
                </label>

                <label for="contact_phone">
                    <span>Contact Phone</span>
                    <input
                        type="tel"
                        id="contact_phone"
                        name="contact_phone"
                        value="<?php echo esc_attr(settings_normalize_phone($contact_phone_value)); ?>"
                        inputmode="numeric"
                        minlength="11"
                        maxlength="11"
                        pattern="09[0-9]{9}"
                        placeholder="e.g., 09171234567"
                        autocomplete="tel"
                        title="Enter 11 digits starting with 09, e.g. 09171234567"
                        data-phone-input
                    >
                </label>
            </div>
        </section>

        <section class="settings-panel branding-panel">
            <h2>Logo & Branding</h2>

            <div class="settings-brand-grid">
                <label class="settings-logo-group" for="company_logo">
                    <span>Company Logo</span>
                    <div class="settings-logo-row">
                        <div class="settings-logo-preview">
                            <img src="<?php echo esc_attr($logo_src); ?>" alt="Company Logo" id="companyLogoPreview">
                        </div>

                        <div>
                            <span class="settings-upload-btn">
                                <i class="fas fa-upload"></i>
                                Upload Logo
                            </span>
                            <input type="file" id="company_logo" name="company_logo" accept="image/png,image/jpeg">
                            <p>PNG, JPG up to 5MB</p>
                        </div>
                    </div>
                </label>

                <label class="settings-color-group" for="primary_color_text">
                    <span>Primary Color</span>
                    <div class="settings-color-row">
                        <input
                            type="color"
                            id="primary_color_picker"
                            value="<?php echo esc_attr($primary_color_value); ?>"
                            aria-label="Primary color picker"
                        >
                        <input
                            type="text"
                            id="primary_color_text"
                            name="primary_color"
                            value="<?php echo esc_attr($primary_color_value); ?>"
                            maxlength="7"
                        >
                    </div>
                    <p>Used for buttons, links, and accents</p>
                </label>
            </div>
        </section>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const colorPicker = document.getElementById('primary_color_picker');
    const colorText = document.getElementById('primary_color_text');
    const logoInput = document.getElementById('company_logo');
    const logoPreview = document.getElementById('companyLogoPreview');

    document.querySelectorAll('[data-phone-input]').forEach(function (input) {
        function normalizePhoneInput() {
            input.value = String(input.value || '').replace(/\D/g, '').slice(0, 11);
        }

        normalizePhoneInput();
        input.addEventListener('input', normalizePhoneInput);
    });

    if (colorPicker && colorText) {
        colorPicker.addEventListener('input', function () {
            colorText.value = colorPicker.value.toUpperCase();
        });

        colorText.addEventListener('input', function () {
            const value = colorText.value.trim();

            if (/^#[0-9A-Fa-f]{6}$/.test(value)) {
                colorPicker.value = value;
            }
        });
    }

    if (logoInput && logoPreview) {
        logoInput.addEventListener('change', function () {
            const file = logoInput.files && logoInput.files[0];

            if (!file || !file.type.match(/^image\/(png|jpeg)$/)) {
                return;
            }

            const reader = new FileReader();
            reader.onload = function (event) {
                logoPreview.src = event.target.result;
            };
            reader.readAsDataURL(file);
        });
    }
});
</script>

<?php require_once '../../includes/footer.php'; ?>
