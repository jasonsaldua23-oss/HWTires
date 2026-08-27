<?php
/**
 * Admin User Management
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

$page_title = 'User Management';

if (!function_exists('users_branch_label')) {
    function users_branch_label($branch_name) {
        return app_branch_label($branch_name, 'Branch');
    }
}

if (!function_exists('users_role_label')) {
    function users_role_label($role) {
        return $role === 'admin' ? 'Admin/Owner' : 'Front Desk';
    }
}

if (!function_exists('users_role_class')) {
    function users_role_class($role) {
        return $role === 'admin' ? 'role-admin' : 'role-front-desk';
    }
}

if (!function_exists('users_status_class')) {
    function users_status_class($status) {
        return $status === 'active' ? 'status-active' : 'status-inactive';
    }
}

if (!function_exists('users_normalize_login_id')) {
    function users_normalize_login_id($login_id) {
        return strtolower(trim((string) $login_id));
    }
}

if (!function_exists('users_validate_login_id')) {
    function users_validate_login_id($login_id) {
        return (bool) preg_match('/^[a-z0-9._@-]{3,100}$/i', $login_id);
    }
}

if (!function_exists('users_active_admin_count_excluding')) {
    function users_active_admin_count_excluding(PDO $pdo, $user_id) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active' AND id <> ?");
        $stmt->execute([(int) $user_id]);
        return (int) $stmt->fetchColumn();
    }
}

if (!function_exists('users_login_id_exists')) {
    function users_login_id_exists(PDO $pdo, $login_id, $exclude_id = 0) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1");
        $stmt->execute([$login_id, (int) $exclude_id]);
        return (bool) $stmt->fetch();
    }
}

if (!function_exists('users_branch_exists')) {
    function users_branch_exists(array $branches, $branch_id) {
        foreach ($branches as $branch) {
            if ((int) $branch['id'] === (int) $branch_id) {
                return true;
            }
        }

        return false;
    }
}

$branches = $pdo->query("SELECT id, name, has_inventory FROM branches WHERE status = 'active' ORDER BY id ASC")->fetchAll();
$csrf_token = generate_csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            throw new Exception('Security check failed. Please try again.');
        }

        $action = $_POST['action'] ?? '';
        $current_user_id = (int) ($user['id'] ?? 0);

        if ($action === 'create' || $action === 'update') {
            $name = trim($_POST['name'] ?? '');
            $login_id = users_normalize_login_id($_POST['login_id'] ?? '');
            $role = $_POST['role'] ?? 'front-desk';
            $status = $_POST['status'] ?? 'active';
            $branch_id = $role === 'admin' ? null : (int) ($_POST['branch_id'] ?? 0);
            $password = (string) ($_POST['password'] ?? '');
            $password_confirm = (string) ($_POST['password_confirm'] ?? '');

            if ($name === '') {
                throw new Exception('Name is required.');
            }

            if (!users_validate_login_id($login_id)) {
                throw new Exception('Login ID must be 3-100 characters and may use letters, numbers, dots, dashes, underscores, or @.');
            }

            if (!in_array($role, ['admin', 'front-desk'], true)) {
                throw new Exception('Invalid role selected.');
            }

            if (!in_array($status, ['active', 'inactive'], true)) {
                throw new Exception('Invalid status selected.');
            }

            if ($role === 'front-desk' && !users_branch_exists($branches, $branch_id)) {
                throw new Exception('Please choose a valid branch for front desk users.');
            }

            if ($action === 'create') {
                if ($password === '' || strlen($password) < 8) {
                    throw new Exception('Password must be at least 8 characters.');
                }

                if ($password !== $password_confirm) {
                    throw new Exception('Passwords do not match.');
                }

                if (users_login_id_exists($pdo, $login_id)) {
                    throw new Exception('That login ID is already in use.');
                }

                $stmt = $pdo->prepare("
                    INSERT INTO users (name, email, password_hash, role, branch_id, status)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $name,
                    $login_id,
                    password_hash($password, PASSWORD_DEFAULT),
                    $role,
                    $branch_id,
                    $status,
                ]);

                $new_user_id = (int) $pdo->lastInsertId();
                log_audit('users', 'create', $new_user_id, null, [
                    'name' => $name,
                    'login_id' => $login_id,
                    'role' => $role,
                    'branch_id' => $branch_id,
                    'status' => $status,
                ]);
                set_flash_message('User added successfully.', 'success');
            } else {
                $target_id = (int) ($_POST['user_id'] ?? 0);
                if ($target_id <= 0) {
                    throw new Exception('Invalid user selected.');
                }

                $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                $stmt->execute([$target_id]);
                $old_user = $stmt->fetch();

                if (!$old_user) {
                    throw new Exception('User not found.');
                }

                if ($target_id === $current_user_id && ($role !== 'admin' || $status !== 'active')) {
                    throw new Exception('You cannot remove your own active admin access.');
                }

                if (($old_user['role'] ?? '') === 'admin' && ($old_user['status'] ?? '') === 'active' && ($role !== 'admin' || $status !== 'active')) {
                    if (users_active_admin_count_excluding($pdo, $target_id) <= 0) {
                        throw new Exception('At least one active admin user is required.');
                    }
                }

                if (users_login_id_exists($pdo, $login_id, $target_id)) {
                    throw new Exception('That login ID is already in use.');
                }

                $fields = [
                    'name = ?',
                    'email = ?',
                    'role = ?',
                    'branch_id = ?',
                    'status = ?',
                ];
                $params = [$name, $login_id, $role, $branch_id, $status];

                if ($password !== '') {
                    if (strlen($password) < 8) {
                        throw new Exception('New password must be at least 8 characters.');
                    }

                    if ($password !== $password_confirm) {
                        throw new Exception('Passwords do not match.');
                    }

                    $fields[] = 'password_hash = ?';
                    $params[] = password_hash($password, PASSWORD_DEFAULT);
                }

                $params[] = $target_id;
                $stmt = $pdo->prepare("UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?");
                $stmt->execute($params);

                log_audit('users', 'update', $target_id, $old_user, [
                    'name' => $name,
                    'login_id' => $login_id,
                    'role' => $role,
                    'branch_id' => $branch_id,
                    'status' => $status,
                    'password_changed' => $password !== '',
                ]);
                set_flash_message('User updated successfully.', 'success');
            }

            redirect('/hwtires/admin/users/');
        }

        if (in_array($action, ['deactivate', 'reactivate', 'archive'], true)) {
            $target_id = (int) ($_POST['user_id'] ?? 0);
            if ($target_id <= 0) {
                throw new Exception('Invalid user selected.');
            }

            if ($target_id === $current_user_id && in_array($action, ['deactivate', 'archive'], true)) {
                throw new Exception('You cannot archive your own account.');
            }

            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$target_id]);
            $old_user = $stmt->fetch();

            if (!$old_user) {
                throw new Exception('User not found.');
            }

            if (in_array($action, ['deactivate', 'archive'], true) && ($old_user['role'] ?? '') === 'admin' && ($old_user['status'] ?? '') === 'active') {
                if (users_active_admin_count_excluding($pdo, $target_id) <= 0) {
                    throw new Exception('At least one active admin user is required.');
                }
            }

            $new_status = $action === 'reactivate' ? 'active' : 'inactive';
            $stmt = $pdo->prepare("UPDATE users SET status = ? WHERE id = ?");
            $stmt->execute([$new_status, $target_id]);

            log_audit('users', $new_status === 'active' ? 'reactivate' : 'archive', $target_id, $old_user, [
                'status' => $new_status,
                'records_preserved' => true,
            ]);
            set_flash_message($new_status === 'active' ? 'User reactivated successfully.' : 'User archived successfully.', 'success');
            redirect('/hwtires/admin/users/');
        }

        if ($action === 'delete_permanent') {
            $target_id = (int) ($_POST['user_id'] ?? 0);
            if ($target_id <= 0) {
                throw new Exception('Invalid user selected.');
            }

            if ($target_id === $current_user_id) {
                throw new Exception('You cannot archive your own account.');
            }

            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$target_id]);
            $old_user = $stmt->fetch();

            if (!$old_user) {
                throw new Exception('User not found.');
            }

            if (($old_user['role'] ?? '') === 'admin' && ($old_user['status'] ?? '') === 'active') {
                if (users_active_admin_count_excluding($pdo, $target_id) <= 0) {
                    throw new Exception('At least one active admin user is required.');
                }
            }

            $stmt = $pdo->prepare("UPDATE users SET status = 'inactive' WHERE id = ?");
            $stmt->execute([$target_id]);

            log_audit('users', 'archive', $target_id, $old_user, [
                'status' => 'inactive',
                'records_preserved' => true,
            ]);
            set_flash_message('User archived successfully.', 'success');
            redirect('/hwtires/admin/users/');
        }

        throw new Exception('Invalid action.');
    } catch (Exception $e) {
        set_flash_message($e->getMessage(), 'danger');
        redirect('/hwtires/admin/users/');
    }
}

$stats = [
    'total' => (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
    'active' => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'active'")->fetchColumn(),
    'admins' => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn(),
];

$stmt = $pdo->query("
    SELECT u.*, b.name AS branch_name
    FROM users u
    LEFT JOIN branches b ON b.id = u.branch_id
    ORDER BY FIELD(u.role, 'admin', 'front-desk'), COALESCE(u.branch_id, 0), u.name
");
$users = $stmt->fetchAll();
?>

<?php require_once '../../includes/header.php'; ?>
<?php require_once '../../includes/sidebar.php'; ?>

<main class="user-management-page">
    <header class="users-hero">
        <div>
            <h1>User Management</h1>
            <p>Manage system users and access control</p>
        </div>
        <button type="button" class="users-add-btn" data-bs-toggle="modal" data-bs-target="#addUserModal">
            <i class="fas fa-plus"></i>
            <span>Add User</span>
        </button>
    </header>

    <?php
    $flash_message = get_flash_message();
    if ($flash_message):
        $flash_type = $flash_message['type'] === 'error' ? 'danger' : $flash_message['type'];
    ?>
        <div class="alert alert-<?php echo esc_attr($flash_type); ?> alert-dismissible fade show users-flash" role="alert">
            <?php echo esc_html($flash_message['message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <section class="users-summary-grid" aria-label="User summary">
        <article class="users-summary-card">
            <div>
                <span>Total Users</span>
                <strong><?php echo (int) $stats['total']; ?></strong>
            </div>
            <span class="users-summary-icon icon-cyan"><i class="fas fa-user-gear"></i></span>
        </article>
        <article class="users-summary-card">
            <div>
                <span>Active Users</span>
                <strong><?php echo (int) $stats['active']; ?></strong>
            </div>
            <span class="users-summary-icon icon-green"><i class="fas fa-user-gear"></i></span>
        </article>
        <article class="users-summary-card">
            <div>
                <span>Admin Users</span>
                <strong><?php echo (int) $stats['admins']; ?></strong>
            </div>
            <span class="users-summary-icon icon-purple"><i class="fas fa-user-gear"></i></span>
        </article>
    </section>

    <section class="users-table-card">
        <div class="table-responsive">
            <table class="users-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Login ID</th>
                        <th>Role</th>
                        <th>Branch</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="6" class="users-empty-cell">No users found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($users as $account): ?>
                            <?php
                            $account_id = (int) $account['id'];
                            $is_self = $account_id === (int) ($user['id'] ?? 0);
                            $edit_modal_id = 'editUserModal' . $account_id;
                            $status_modal_id = 'userStatusModal' . $account_id;
                            $branch_label = $account['role'] === 'admin' ? 'All Branches' : users_branch_label($account['branch_name'] ?? '');
                            ?>
                            <tr class="<?php echo ($account['status'] ?? '') === 'inactive' ? 'is-inactive' : ''; ?>">
                                <td>
                                    <strong><?php echo esc_html($account['name']); ?></strong>
                                    <?php if ($is_self): ?>
                                        <span class="users-self-note">You</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($account['email']); ?></td>
                                <td>
                                    <span class="users-role-pill <?php echo esc_attr(users_role_class($account['role'])); ?>">
                                        <?php echo esc_html(users_role_label($account['role'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="users-branch-pill branch-<?php echo (int) ($account['branch_id'] ?? 0); ?>">
                                        <?php echo esc_html($branch_label); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="users-status-pill <?php echo esc_attr(users_status_class($account['status'])); ?>">
                                        <?php echo esc_html(ucfirst($account['status'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="users-actions">
                                        <button type="button" class="users-icon-btn edit" data-bs-toggle="modal" data-bs-target="#<?php echo esc_attr($edit_modal_id); ?>" title="Edit user">
                                            <i class="far fa-pen-to-square"></i>
                                        </button>
                                        <?php if (($account['status'] ?? '') === 'active'): ?>
                                            <button type="button" class="users-icon-btn deactivate" data-bs-toggle="modal" data-bs-target="#<?php echo esc_attr($status_modal_id); ?>" title="Archive user" <?php echo $is_self ? 'disabled' : ''; ?>>
                                                <i class="fas fa-box-archive"></i>
                                            </button>
                                        <?php else: ?>
                                            <button type="button" class="users-icon-btn reactivate" data-bs-toggle="modal" data-bs-target="#<?php echo esc_attr($status_modal_id); ?>" title="User options">
                                                <i class="fas fa-rotate-left"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <?php foreach ($users as $account): ?>
        <?php
        $account_id = (int) $account['id'];
        $edit_modal_id = 'editUserModal' . $account_id;
        $status_modal_id = 'userStatusModal' . $account_id;
        ?>
        <div class="modal fade users-modal" id="<?php echo esc_attr($edit_modal_id); ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form method="POST" class="users-form">
                        <input type="hidden" name="csrf_token" value="<?php echo esc_attr($csrf_token); ?>">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="user_id" value="<?php echo $account_id; ?>">

                        <div class="users-modal-header">
                            <h2>Edit User</h2>
                            <button type="button" class="users-modal-close" data-bs-dismiss="modal" aria-label="Close">
                                <i class="fas fa-xmark"></i>
                            </button>
                        </div>

                        <div class="users-modal-body">
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
                                <select name="role" class="users-role-select" required>
                                    <option value="front-desk" <?php echo $account['role'] === 'front-desk' ? 'selected' : ''; ?>>Front Desk</option>
                                    <option value="admin" <?php echo $account['role'] === 'admin' ? 'selected' : ''; ?>>Admin/Owner</option>
                                </select>
                            </label>
                            <label class="users-branch-field">
                                <span>Branch</span>
                                <select name="branch_id">
                                    <?php foreach ($branches as $branch): ?>
                                        <option value="<?php echo (int) $branch['id']; ?>" <?php echo (int) ($account['branch_id'] ?? 0) === (int) $branch['id'] ? 'selected' : ''; ?>>
                                            <?php echo esc_html(users_branch_label($branch['name'])); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>
                                <span>Status</span>
                                <select name="status" required>
                                    <option value="active" <?php echo $account['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo $account['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </label>
                            <label>
                                <span>New Password</span>
                                <input type="password" name="password" placeholder="Leave blank to keep current password" minlength="8">
                            </label>
                            <label>
                                <span>Confirm New Password</span>
                                <input type="password" name="password_confirm" placeholder="Confirm new password" minlength="8">
                            </label>
                        </div>

                        <div class="users-modal-footer">
                            <button type="button" class="users-cancel-btn" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="users-submit-btn">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal fade users-modal users-confirm-modal" id="<?php echo esc_attr($status_modal_id); ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="users-modal-header">
                        <h2><?php echo ($account['status'] ?? '') === 'active' ? 'Archive User' : 'User Options'; ?></h2>
                        <button type="button" class="users-modal-close" data-bs-dismiss="modal" aria-label="Close">
                            <i class="fas fa-xmark"></i>
                        </button>
                    </div>

                    <div class="users-modal-body">
                        <div class="users-confirm-box">
                            <strong><?php echo esc_html($account['name']); ?></strong>
                            <p><?php echo esc_html($account['email']); ?></p>
                        </div>
                        <p class="users-confirm-copy">
                            <?php if (($account['status'] ?? '') === 'active'): ?>
                                Archive this user to prevent future logins while keeping the account record.
                            <?php else: ?>
                                Reactivate this user to allow login again.
                            <?php endif; ?>
                        </p>
                    </div>

                    <div class="users-modal-footer removal-options">
                        <button type="button" class="users-cancel-btn" data-bs-dismiss="modal">Cancel</button>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo esc_attr($csrf_token); ?>">
                            <input type="hidden" name="action" value="<?php echo ($account['status'] ?? '') === 'active' ? 'archive' : 'reactivate'; ?>">
                            <input type="hidden" name="user_id" value="<?php echo $account_id; ?>">
                            <button type="submit" class="users-submit-btn <?php echo ($account['status'] ?? '') === 'active' ? 'warning' : ''; ?>">
                                <?php echo ($account['status'] ?? '') === 'active' ? 'Archive' : 'Reactivate'; ?>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <div class="modal fade users-modal" id="addUserModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" class="users-form">
                    <input type="hidden" name="csrf_token" value="<?php echo esc_attr($csrf_token); ?>">
                    <input type="hidden" name="action" value="create">

                    <div class="users-modal-header">
                        <h2>Add New User</h2>
                        <button type="button" class="users-modal-close" data-bs-dismiss="modal" aria-label="Close">
                            <i class="fas fa-xmark"></i>
                        </button>
                    </div>

                    <div class="users-modal-body">
                        <label>
                            <span>Name</span>
                            <input type="text" name="name" placeholder="Enter user name" required>
                        </label>
                        <label>
                            <span>Login ID</span>
                            <input type="text" name="login_id" placeholder="e.g. branch1.frontdesk" required>
                        </label>
                        <label>
                            <span>Password</span>
                            <input type="password" name="password" placeholder="Enter initial password" minlength="8" required>
                        </label>
                        <label>
                            <span>Confirm Password</span>
                            <input type="password" name="password_confirm" placeholder="Confirm initial password" minlength="8" required>
                        </label>
                        <label>
                            <span>Role</span>
                            <select name="role" class="users-role-select" required>
                                <option value="front-desk">Front Desk</option>
                                <option value="admin">Admin/Owner</option>
                            </select>
                        </label>
                        <label class="users-branch-field">
                            <span>Branch</span>
                            <select name="branch_id">
                                <?php foreach ($branches as $branch): ?>
                                    <option value="<?php echo (int) $branch['id']; ?>">
                                        <?php echo esc_html(users_branch_label($branch['name'])); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            <span>Status</span>
                            <select name="status" required>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </label>
                    </div>

                    <div class="users-modal-footer">
                        <button type="button" class="users-cancel-btn" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="users-submit-btn">Add User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.users-form').forEach(function (form) {
        const roleSelect = form.querySelector('.users-role-select');
        const branchField = form.querySelector('.users-branch-field');

        function syncBranchVisibility() {
            if (!roleSelect || !branchField) {
                return;
            }

            branchField.classList.toggle('is-hidden', roleSelect.value === 'admin');
        }

        if (roleSelect) {
            roleSelect.addEventListener('change', syncBranchVisibility);
            syncBranchVisibility();
        }
    });
});
</script>

<?php require_once '../../includes/footer.php'; ?>
