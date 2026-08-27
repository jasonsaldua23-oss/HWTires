<?php
/**
 * Admin Branch Management
 */

require_once '../../includes/config.php';
session_name(SESSION_NAME);
session_start();

if (!is_logged_in()) {
    redirect('/hwtires/index.php');
}

$user = app_get_session_user();

if (($user['role'] ?? '') !== 'admin') {
    redirect('/hwtires/' . ($user['role'] ?? '') . '/index.php');
}

$page_title = 'Branch Management';

if (!function_exists('branches_ensure_supervisor_column')) {
    function branches_ensure_supervisor_column(PDO $pdo) {
        try {
            $stmt = $pdo->prepare("SHOW COLUMNS FROM branches LIKE ?");
            $stmt->execute(['branch_supervisor']);

            if (!$stmt->fetch()) {
                $pdo->exec("ALTER TABLE branches ADD COLUMN branch_supervisor VARCHAR(150) NULL AFTER location");
            }
        } catch (Exception $e) {
            error_log('Unable to ensure branches.branch_supervisor column: ' . $e->getMessage());
        }
    }
}

if (!function_exists('branches_label')) {
    function branches_label($branch_name) {
        if (preg_match('/Branch\s+\d+/i', (string) $branch_name, $matches)) {
            return $matches[0];
        }

        return $branch_name ?: 'Branch';
    }
}

if (!function_exists('branches_next_name')) {
    function branches_next_name(PDO $pdo) {
        $names = $pdo->query("SELECT name FROM branches")->fetchAll(PDO::FETCH_COLUMN);
        $max = 0;

        foreach ($names as $name) {
            if (preg_match('/Branch\s+(\d+)/i', (string) $name, $matches)) {
                $max = max($max, (int) $matches[1]);
            }
        }

        return 'Branch ' . ($max + 1);
    }
}

if (!function_exists('branches_normalize_phone')) {
    function branches_normalize_phone($phone) {
        return preg_replace('/\D+/', '', trim((string) $phone));
    }
}

if (!function_exists('branches_is_valid_ph_mobile')) {
    function branches_is_valid_ph_mobile($phone) {
        return preg_match('/^09\d{9}$/', (string) $phone) === 1;
    }
}

if (!function_exists('branches_status_class')) {
    function branches_status_class($status) {
        return $status === 'active' ? 'status-active' : 'status-inactive';
    }
}

if (!function_exists('branches_capability_label')) {
    function branches_capability_label($has_inventory) {
        return 'Inventory Enabled';
    }
}

if (!function_exists('branches_capability_class')) {
    function branches_capability_class($has_inventory) {
        return 'has-inventory';
    }
}

if (!function_exists('branches_parse_technician_names')) {
    function branches_parse_technician_names($value) {
        $parts = preg_split('/[,;\r\n]+/', (string) $value);
        $parts = array_map('trim', $parts ?: []);
        $parts = array_values(array_unique(array_filter($parts, static function ($part) {
            return $part !== '';
        })));

        return $parts;
    }
}

if (!function_exists('branches_count_related_rows')) {
    function branches_count_related_rows(PDO $pdo, $sql, array $params) {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return (int) $stmt->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }
}

if (!function_exists('branches_permanent_delete_blockers')) {
    function branches_permanent_delete_blockers(PDO $pdo, $branch_id) {
        $branch_id = (int) $branch_id;
        $checks = [
            'assigned users' => ['SELECT COUNT(*) FROM users WHERE branch_id = ?', [$branch_id]],
            'technicians' => ['SELECT COUNT(*) FROM technicians WHERE branch_id = ?', [$branch_id]],
            'customer branch records' => ['SELECT COUNT(*) FROM customer_branch_records WHERE branch_id = ?', [$branch_id]],
            'customers' => ['SELECT COUNT(*) FROM customers WHERE branch_id = ?', [$branch_id]],
            'vehicles' => ['SELECT COUNT(*) FROM vehicles WHERE branch_id = ?', [$branch_id]],
            'customer visits' => ['SELECT COUNT(*) FROM customer_visits WHERE branch_id = ?', [$branch_id]],
            'quotations' => ['SELECT COUNT(*) FROM quotations WHERE branch_id = ?', [$branch_id]],
            'job orders' => ['SELECT COUNT(*) FROM job_orders WHERE branch_id = ?', [$branch_id]],
            'service history' => ['SELECT COUNT(*) FROM service_history WHERE branch_id = ?', [$branch_id]],
            'inventory items' => ['SELECT COUNT(*) FROM inventory_items WHERE branch_id = ?', [$branch_id]],
            'transfer requests' => ['SELECT COUNT(*) FROM inter_branch_transfer_requests WHERE requesting_branch_id = ? OR donor_branch_id = ?', [$branch_id, $branch_id]],
            'transfer notifications' => ['SELECT COUNT(*) FROM transfer_notifications WHERE branch_id = ?', [$branch_id]],
            'SMS records' => ['SELECT COUNT(*) FROM sms_outbox WHERE branch_id = ?', [$branch_id]],
        ];
        $blockers = [];

        foreach ($checks as $label => $check) {
            $count = branches_count_related_rows($pdo, $check[0], $check[1]);
            if ($count > 0) {
                $blockers[] = $label . ' (' . $count . ')';
            }
        }

        return $blockers;
    }
}

branches_ensure_supervisor_column($pdo);
$csrf_token = generate_csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            throw new Exception('Security check failed. Please try again.');
        }

        $action = $_POST['action'] ?? '';

        if ($action === 'create' || $action === 'update') {
            $name = trim($_POST['name'] ?? '');
            $branch_supervisor = trim($_POST['branch_supervisor'] ?? '');
            $location = trim($_POST['location'] ?? '');
            $contact_number = branches_normalize_phone($_POST['contact_number'] ?? '');
            $status = $_POST['status'] ?? 'active';
            $has_inventory = 1;

            if ($name === '') {
                throw new Exception('Branch name is required.');
            }

            if (!in_array($status, ['active', 'inactive'], true)) {
                throw new Exception('Invalid status selected.');
            }

            if ($contact_number !== '' && !branches_is_valid_ph_mobile($contact_number)) {
                throw new Exception('Contact number must be an 11-digit Philippine mobile number starting with 09, e.g. 09171234567.');
            }

            if ($action === 'create') {
                $stmt = $pdo->prepare("
                    INSERT INTO branches (name, branch_supervisor, location, contact_number, has_inventory, status)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $name,
                    $branch_supervisor ?: null,
                    $location ?: null,
                    $contact_number ?: null,
                    $has_inventory,
                    $status,
                ]);

                $branch_id = (int) $pdo->lastInsertId();
                log_audit('branches', 'create', $branch_id, null, [
                    'name' => $name,
                    'branch_supervisor' => $branch_supervisor,
                    'location' => $location,
                    'contact_number' => $contact_number,
                    'has_inventory' => $has_inventory,
                    'status' => $status,
                ]);
                set_flash_message('Branch added successfully.', 'success');
            } else {
                $branch_id = (int) ($_POST['branch_id'] ?? 0);
                if ($branch_id <= 0) {
                    throw new Exception('Invalid branch selected.');
                }

                $stmt = $pdo->prepare("SELECT * FROM branches WHERE id = ?");
                $stmt->execute([$branch_id]);
                $old_branch = $stmt->fetch();

                if (!$old_branch) {
                    throw new Exception('Branch not found.');
                }

                $stmt = $pdo->prepare("
                    UPDATE branches
                    SET name = ?, branch_supervisor = ?, location = ?, contact_number = ?, has_inventory = ?, status = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $name,
                    $branch_supervisor ?: null,
                    $location ?: null,
                    $contact_number ?: null,
                    $has_inventory,
                    $status,
                    $branch_id,
                ]);

                log_audit('branches', 'update', $branch_id, $old_branch, [
                    'name' => $name,
                    'branch_supervisor' => $branch_supervisor,
                    'location' => $location,
                    'contact_number' => $contact_number,
                    'has_inventory' => $has_inventory,
                    'status' => $status,
                ]);
                set_flash_message('Branch updated successfully.', 'success');
            }

            redirect('/hwtires/admin/branch-management/');
        }

        if ($action === 'save_technicians') {
            $branch_id = (int) ($_POST['branch_id'] ?? 0);
            if ($branch_id <= 0) {
                throw new Exception('Invalid branch selected.');
            }

            $stmt = $pdo->prepare("SELECT * FROM branches WHERE id = ?");
            $stmt->execute([$branch_id]);
            $branch = $stmt->fetch();

            if (!$branch) {
                throw new Exception('Branch not found.');
            }

            $technician_names = branches_parse_technician_names($_POST['technician_names'] ?? '');

            $pdo->beginTransaction();

            $old_stmt = $pdo->prepare("SELECT id, name FROM technicians WHERE branch_id = ? ORDER BY name ASC");
            $old_stmt->execute([$branch_id]);
            $old_technicians = $old_stmt->fetchAll();

            $existing_stmt = $pdo->prepare("SELECT id, name FROM technicians WHERE branch_id = ?");
            $existing_stmt->execute([$branch_id]);
            $existing_technicians = $existing_stmt->fetchAll();

            $existing_by_name = [];
            foreach ($existing_technicians as $existing_technician) {
                $existing_by_name[strtolower(trim((string) ($existing_technician['name'] ?? '')))] = $existing_technician;
            }

            $saved_ids = [];
            foreach ($technician_names as $technician_name) {
                $lookup_key = strtolower(trim($technician_name));
                $existing_row = $existing_by_name[$lookup_key] ?? null;

                if ($existing_row) {
                    $existing_id = (int) ($existing_row['id'] ?? 0);
                    $update_stmt = $pdo->prepare("
                        UPDATE technicians
                        SET name = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    $update_stmt->execute([$technician_name, $existing_id]);
                    $saved_ids[] = $existing_id;
                } else {
                    $insert_stmt = $pdo->prepare("
                        INSERT INTO technicians (name, branch_id, join_date, created_at, updated_at)
                        VALUES (?, ?, CURDATE(), NOW(), NOW())
                    ");
                    $insert_stmt->execute([$technician_name, $branch_id]);
                    $saved_ids[] = (int) $pdo->lastInsertId();
                }
            }

            $saved_ids = array_values(array_unique(array_filter($saved_ids, static function ($id) {
                return (int) $id > 0;
            })));

            if (!empty($saved_ids)) {
                $placeholders = implode(', ', array_fill(0, count($saved_ids), '?'));
                $delete_stmt = $pdo->prepare("DELETE FROM technicians WHERE branch_id = ? AND id NOT IN ($placeholders)");
                $delete_stmt->execute(array_merge([$branch_id], $saved_ids));
            } else {
                $delete_stmt = $pdo->prepare("DELETE FROM technicians WHERE branch_id = ?");
                $delete_stmt->execute([$branch_id]);
            }

            $pdo->commit();

            log_audit('technicians', 'update_roster', $branch_id, $old_technicians, [
                'branch_id' => $branch_id,
                'technicians' => $technician_names,
            ]);
            set_flash_message('Technician roster updated successfully.', 'success');
            redirect('/hwtires/admin/branch-management/');
        }

        if (in_array($action, ['deactivate', 'reactivate', 'archive'], true)) {
            $branch_id = (int) ($_POST['branch_id'] ?? 0);
            if ($branch_id <= 0) {
                throw new Exception('Invalid branch selected.');
            }

            $stmt = $pdo->prepare("SELECT * FROM branches WHERE id = ?");
            $stmt->execute([$branch_id]);
            $old_branch = $stmt->fetch();

            if (!$old_branch) {
                throw new Exception('Branch not found.');
            }

            if (in_array($action, ['deactivate', 'archive'], true)) {
                $active_count_stmt = $pdo->prepare("SELECT COUNT(*) FROM branches WHERE status = 'active' AND id <> ?");
                $active_count_stmt->execute([$branch_id]);
                if ((int) $active_count_stmt->fetchColumn() <= 0) {
                    throw new Exception('At least one active branch is required.');
                }
            }

            $new_status = $action === 'reactivate' ? 'active' : 'inactive';
            $stmt = $pdo->prepare("UPDATE branches SET status = ? WHERE id = ?");
            $stmt->execute([$new_status, $branch_id]);

            log_audit('branches', $new_status === 'active' ? 'reactivate' : 'archive', $branch_id, $old_branch, [
                'status' => $new_status,
                'records_preserved' => true,
            ]);
            set_flash_message($new_status === 'active' ? 'Branch reactivated successfully.' : 'Branch archived successfully.', 'success');
            redirect('/hwtires/admin/branch-management/');
        }

        if ($action === 'delete_permanent') {
            $branch_id = (int) ($_POST['branch_id'] ?? 0);
            if ($branch_id <= 0) {
                throw new Exception('Invalid branch selected.');
            }

            $stmt = $pdo->prepare("SELECT * FROM branches WHERE id = ?");
            $stmt->execute([$branch_id]);
            $old_branch = $stmt->fetch();

            if (!$old_branch) {
                throw new Exception('Branch not found.');
            }

            if (($old_branch['status'] ?? '') === 'active') {
                $active_count_stmt = $pdo->prepare("SELECT COUNT(*) FROM branches WHERE status = 'active' AND id <> ?");
                $active_count_stmt->execute([$branch_id]);
                if ((int) $active_count_stmt->fetchColumn() <= 0) {
                    throw new Exception('At least one active branch is required.');
                }
            }

            $stmt = $pdo->prepare("UPDATE branches SET status = 'inactive' WHERE id = ?");
            $stmt->execute([$branch_id]);

            log_audit('branches', 'archive', $branch_id, $old_branch, [
                'status' => 'inactive',
                'records_preserved' => true,
            ]);
            set_flash_message('Branch archived successfully.', 'success');
            redirect('/hwtires/admin/branch-management/');
        }

        throw new Exception('Invalid action.');
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        set_flash_message($e->getMessage(), 'danger');
        redirect('/hwtires/admin/branch-management/');
    }
}

$stats = [
    'total' => (int) $pdo->query("SELECT COUNT(*) FROM branches")->fetchColumn(),
    'active' => (int) $pdo->query("SELECT COUNT(*) FROM branches WHERE status = 'active'")->fetchColumn(),
    'inactive' => (int) $pdo->query("SELECT COUNT(*) FROM branches WHERE status = 'inactive'")->fetchColumn(),
    'inventory' => (int) $pdo->query("SELECT COUNT(*) FROM branches WHERE has_inventory = 1")->fetchColumn(),
];

$stmt = $pdo->query("
    SELECT b.*,
           COUNT(DISTINCT t.id) AS technician_count,
           COUNT(DISTINCT CASE WHEN jo.status IN ('waiting', 'in-progress') THEN jo.id END) AS active_job_count
    FROM branches b
    LEFT JOIN technicians t ON t.branch_id = b.id
    LEFT JOIN job_orders jo ON jo.branch_id = b.id
    GROUP BY b.id
    ORDER BY b.id ASC
");
$branches = $stmt->fetchAll();

$technicians_by_branch = [];
try {
    $technician_rows = $pdo->query("
        SELECT id, branch_id, name
        FROM technicians
        ORDER BY branch_id ASC, name ASC
    ")->fetchAll();

    foreach ($technician_rows as $technician) {
        $technicians_by_branch[(int) $technician['branch_id']][] = $technician;
    }
} catch (Exception $e) {
    $technicians_by_branch = [];
}

$next_branch_name = branches_next_name($pdo);
?>

<?php require_once '../../includes/header.php'; ?>
<?php require_once '../../includes/sidebar.php'; ?>

<main class="branch-management-page">
    <header class="branches-hero">
        <div>
            <h1>Branch Management</h1>
            <p>Manage all Highway Tires branches and their configurations</p>
        </div>
        <button type="button" class="branches-add-btn" data-bs-toggle="modal" data-bs-target="#addBranchModal">
            <i class="fas fa-plus"></i>
            <span>Add Branch</span>
        </button>
    </header>

    <?php
    $flash_message = get_flash_message();
    if ($flash_message):
        $flash_type = $flash_message['type'] === 'error' ? 'danger' : $flash_message['type'];
    ?>
        <div class="alert alert-<?php echo esc_attr($flash_type); ?> alert-dismissible fade show branches-flash" role="alert">
            <?php echo esc_html($flash_message['message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <section class="branches-summary-grid" aria-label="Branch summary">
        <article class="branches-summary-card">
            <div>
                <span>Total Branches</span>
                <strong><?php echo (int) $stats['total']; ?></strong>
            </div>
            <span class="branches-summary-icon icon-cyan"><i class="fas fa-location-dot"></i></span>
        </article>
        <article class="branches-summary-card">
            <div>
                <span>Active Branches</span>
                <strong><?php echo (int) $stats['active']; ?></strong>
            </div>
            <span class="branches-summary-icon icon-green"><i class="fas fa-location-dot"></i></span>
        </article>
        <article class="branches-summary-card">
            <div>
                <span>Inactive Branches</span>
                <strong><?php echo (int) $stats['inactive']; ?></strong>
            </div>
            <span class="branches-summary-icon icon-gray"><i class="fas fa-location-dot"></i></span>
        </article>
        <article class="branches-summary-card">
            <div>
                <span>With Inventory</span>
                <strong><?php echo (int) $stats['inventory']; ?></strong>
            </div>
            <span class="branches-summary-icon icon-purple"><i class="fas fa-cube"></i></span>
        </article>
    </section>

    <section class="branches-card-grid">
        <?php if (empty($branches)): ?>
            <div class="branches-empty-state">No branches found.</div>
        <?php else: ?>
            <?php foreach ($branches as $branch): ?>
                <?php
                $branch_id = (int) $branch['id'];
                $edit_modal_id = 'editBranchModal' . $branch_id;
                $status_modal_id = 'branchStatusModal' . $branch_id;
                $technician_modal_id = 'branchTechniciansModal' . $branch_id;
                $has_inventory = (int) ($branch['has_inventory'] ?? 0);
                ?>
                <article id="branch-card-<?php echo $branch_id; ?>" class="branch-card <?php echo ($branch['status'] ?? '') === 'inactive' ? 'is-inactive' : ''; ?>">
                    <div class="branch-card-top">
                        <div class="branch-title-wrap">
                            <span class="branch-location-icon branch-<?php echo (($branch_id - 1) % 3) + 1; ?>">
                                <i class="fas fa-location-dot"></i>
                            </span>
                            <div>
                                <h2><?php echo esc_html(branches_label($branch['name'])); ?></h2>
                                <p><?php echo esc_html($branch['location'] ?: '-'); ?></p>
                            </div>
                        </div>
                        <span class="branches-status-pill <?php echo esc_attr(branches_status_class($branch['status'])); ?>">
                            <?php echo esc_html($branch['status']); ?>
                        </span>
                    </div>

                    <div class="branch-meta-list">
                        <div>
                            <i class="fas fa-users"></i>
                            <strong>Branch Supervisor:</strong>
                            <span><?php echo esc_html($branch['branch_supervisor'] ?: '-'); ?></span>
                        </div>
                        <div>
                            <i class="fas fa-location-dot"></i>
                            <strong>Contact:</strong>
                            <span><?php echo esc_html($branch['contact_number'] ?: '-'); ?></span>
                        </div>
                    </div>

                    <span class="branches-capability-pill <?php echo esc_attr(branches_capability_class($has_inventory)); ?>">
                        <?php echo esc_html(branches_capability_label($has_inventory)); ?>
                    </span>

                    <div class="branch-mini-stats">
                        <div>
                            <span><i class="fas fa-wrench"></i> Technicians</span>
                            <strong><?php echo (int) $branch['technician_count']; ?></strong>
                        </div>
                        <div>
                            <span><i class="fas fa-cube"></i> Active Jobs</span>
                            <strong><?php echo (int) $branch['active_job_count']; ?></strong>
                        </div>
                    </div>

                    <div class="branch-card-actions with-technicians">
                        <button type="button" class="branches-action-btn edit" data-bs-toggle="modal" data-bs-target="#<?php echo esc_attr($edit_modal_id); ?>">
                            <i class="far fa-pen-to-square"></i>
                            <span>Edit</span>
                        </button>
                        <button type="button" class="branches-action-btn technicians" data-bs-toggle="modal" data-bs-target="#<?php echo esc_attr($technician_modal_id); ?>">
                            <i class="fas fa-user-gear"></i>
                            <span>Technicians</span>
                        </button>
                        <?php if (($branch['status'] ?? '') === 'active'): ?>
                            <button type="button" class="branches-action-btn delete" data-bs-toggle="modal" data-bs-target="#<?php echo esc_attr($status_modal_id); ?>" title="Archive branch">
                                <i class="fas fa-box-archive"></i>
                                <span>Archive</span>
                            </button>
                        <?php else: ?>
                            <button type="button" class="branches-action-btn reactivate" data-bs-toggle="modal" data-bs-target="#<?php echo esc_attr($status_modal_id); ?>" title="Branch options">
                                <i class="fas fa-rotate-left"></i>
                                <span>Reactivate</span>
                            </button>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>

    <?php foreach ($branches as $branch): ?>
        <?php
        $branch_id = (int) $branch['id'];
        $edit_modal_id = 'editBranchModal' . $branch_id;
        $status_modal_id = 'branchStatusModal' . $branch_id;
        $technician_modal_id = 'branchTechniciansModal' . $branch_id;
        $has_inventory = (int) ($branch['has_inventory'] ?? 0);
        $branch_technicians = $technicians_by_branch[$branch_id] ?? [];
        $technician_names_text = implode("\n", array_map(static function ($technician) {
            return (string) ($technician['name'] ?? '');
        }, $branch_technicians));
        ?>
        <div class="modal fade branches-modal" id="<?php echo esc_attr($edit_modal_id); ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form method="POST" class="branches-form">
                        <input type="hidden" name="csrf_token" value="<?php echo esc_attr($csrf_token); ?>">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="branch_id" value="<?php echo $branch_id; ?>">

                        <div class="branches-modal-header">
                            <h2>Edit Branch</h2>
                            <button type="button" class="branches-modal-close" data-bs-dismiss="modal" aria-label="Close">
                                <i class="fas fa-xmark"></i>
                            </button>
                        </div>

                        <div class="branches-modal-body">
                            <label>
                                <span>Branch Name</span>
                                <input type="text" name="name" value="<?php echo esc_attr($branch['name']); ?>" required>
                            </label>
                            <label>
                                <span>Branch Supervisor</span>
                                <input type="text" name="branch_supervisor" value="<?php echo esc_attr($branch['branch_supervisor'] ?? ''); ?>" placeholder="Branch supervisor name">
                            </label>
                            <label class="branches-field-wide">
                                <span>Location</span>
                                <input type="text" name="location" value="<?php echo esc_attr($branch['location'] ?? ''); ?>" placeholder="Complete address">
                            </label>
                            <label>
                                <span>Contact Number</span>
                                <input
                                    type="tel"
                                    name="contact_number"
                                    value="<?php echo esc_attr(branches_normalize_phone($branch['contact_number'] ?? '')); ?>"
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
                            <label>
                                <span>Status</span>
                                <select name="status" required>
                                    <option value="active" <?php echo $branch['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo $branch['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </label>
                        </div>

                        <div class="branches-modal-footer">
                            <button type="button" class="branches-cancel-btn" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="branches-submit-btn">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal fade branches-modal" id="<?php echo esc_attr($technician_modal_id); ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form method="POST" class="branches-form branch-technician-form">
                        <input type="hidden" name="csrf_token" value="<?php echo esc_attr($csrf_token); ?>">
                        <input type="hidden" name="action" value="save_technicians">
                        <input type="hidden" name="branch_id" value="<?php echo $branch_id; ?>">

                        <div class="branches-modal-header">
                            <div>
                                <h2>Manage Technicians</h2>
                                <p><?php echo esc_html(branches_label($branch['name'])); ?></p>
                            </div>
                            <button type="button" class="branches-modal-close" data-bs-dismiss="modal" aria-label="Close">
                                <i class="fas fa-xmark"></i>
                            </button>
                        </div>

                        <div class="branches-modal-body">
                            <div class="branches-confirm-box">
                                <strong><?php echo esc_html($branch['name']); ?></strong>
                                <p><?php echo esc_html($branch['location'] ?: '-'); ?></p>
                            </div>
                            <label class="branches-field-wide">
                                <span>Technician Names</span>
                                <textarea name="technician_names" rows="8" placeholder="One technician name per line"><?php echo esc_html($technician_names_text); ?></textarea>
                            </label>
                            <p class="branch-technician-help">These names will appear in the front desk job order technician dropdown for this branch.</p>
                        </div>

                        <div class="branches-modal-footer">
                            <button type="button" class="branches-cancel-btn" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="branches-submit-btn">Save Technicians</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal fade branches-modal branches-confirm-modal" id="<?php echo esc_attr($status_modal_id); ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="branches-modal-header">
                        <h2><?php echo ($branch['status'] ?? '') === 'active' ? 'Archive Branch' : 'Branch Options'; ?></h2>
                        <button type="button" class="branches-modal-close" data-bs-dismiss="modal" aria-label="Close">
                            <i class="fas fa-xmark"></i>
                        </button>
                    </div>

                    <div class="branches-modal-body">
                        <div class="branches-confirm-box">
                            <strong><?php echo esc_html($branch['name']); ?></strong>
                            <p><?php echo esc_html($branch['location'] ?: '-'); ?></p>
                        </div>
                        <p class="branches-confirm-copy">
                            <?php if (($branch['status'] ?? '') === 'active'): ?>
                                Archive this branch to hide it from active workflows while keeping its records.
                            <?php else: ?>
                                Reactivate this branch to use it again.
                            <?php endif; ?>
                        </p>
                    </div>

                    <div class="branches-modal-footer removal-options">
                        <button type="button" class="branches-cancel-btn" data-bs-dismiss="modal">Cancel</button>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo esc_attr($csrf_token); ?>">
                            <input type="hidden" name="action" value="<?php echo ($branch['status'] ?? '') === 'active' ? 'archive' : 'reactivate'; ?>">
                            <input type="hidden" name="branch_id" value="<?php echo $branch_id; ?>">
                            <button type="submit" class="branches-submit-btn <?php echo ($branch['status'] ?? '') === 'active' ? 'warning' : ''; ?>">
                                <?php echo ($branch['status'] ?? '') === 'active' ? 'Archive' : 'Reactivate'; ?>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <div class="modal fade branches-modal" id="addBranchModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" class="branches-form">
                    <input type="hidden" name="csrf_token" value="<?php echo esc_attr($csrf_token); ?>">
                    <input type="hidden" name="action" value="create">

                    <div class="branches-modal-header">
                        <h2>Add New Branch</h2>
                        <button type="button" class="branches-modal-close" data-bs-dismiss="modal" aria-label="Close">
                            <i class="fas fa-xmark"></i>
                        </button>
                    </div>

                    <div class="branches-modal-body">
                        <label>
                            <span>Branch Name</span>
                            <input type="text" name="name" value="<?php echo esc_attr($next_branch_name); ?>" placeholder="e.g., Branch 4" required>
                        </label>
                        <label>
                            <span>Branch Supervisor</span>
                            <input type="text" name="branch_supervisor" placeholder="Branch supervisor name">
                        </label>
                        <label class="branches-field-wide">
                            <span>Location</span>
                            <input type="text" name="location" placeholder="Complete address">
                        </label>
                        <label>
                            <span>Contact Number</span>
                            <input
                                type="tel"
                                name="contact_number"
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
                        <label>
                            <span>Status</span>
                            <select name="status" required>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </label>
                    </div>

                    <div class="branches-modal-footer">
                        <button type="button" class="branches-cancel-btn" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="branches-submit-btn">Add Branch</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-phone-input]').forEach(function (input) {
        function normalizePhoneInput() {
            input.value = String(input.value || '').replace(/\D/g, '').slice(0, 11);
        }

        normalizePhoneInput();
        input.addEventListener('input', normalizePhoneInput);
    });

});
</script>

<?php require_once '../../includes/footer.php'; ?>
