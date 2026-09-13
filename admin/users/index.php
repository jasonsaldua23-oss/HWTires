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

// Helper: URL builder preserving active filter parameters
if (!function_exists('users_filter_url')) {
    function users_filter_url($params = []) {
        $current = [
            'branch_id' => $_GET['branch_id'] ?? '',
            'role'      => $_GET['role'] ?? '',
            'status'    => $_GET['status'] ?? '',
            'search'    => $_GET['search'] ?? '',
            'per_page'  => $_GET['per_page'] ?? 10,
            'page'      => $_GET['page'] ?? 1,
        ];

        $merged = array_merge($current, $params);
        $clean = [];
        foreach ($merged as $k => $v) {
            $v_str = trim((string) $v);
            if ($v_str !== '' && $v_str !== 'all' && !($k === 'page' && (int) $v <= 1) && !($k === 'per_page' && (int) $v === 10)) {
                $clean[$k] = $v_str;
            }
        }

        return '/hwtires/admin/users/' . (!empty($clean) ? '?' . http_build_query($clean) : '');
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
        $return_url = trim((string) ($_POST['return_url'] ?? ''));
        if ($return_url === '' || strpos($return_url, '/hwtires/admin/users/') !== 0) {
            $return_url = '/hwtires/admin/users/';
        }

        if ($action === 'create') {
            $name = trim($_POST['name'] ?? '');
            $login_id = users_normalize_login_id($_POST['login_id'] ?? '');
            $role = $_POST['role'] ?? 'front-desk';
            $status = $_POST['status'] ?? 'active';
            $branch_id = $role === 'admin' ? null : (int) ($_POST['branch_id'] ?? 0);

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

            if (users_login_id_exists($pdo, $login_id)) {
                throw new Exception('That login ID is already in use.');
            }

            $temp_password = app_generate_temporary_password(12);
            $password_hash = password_hash($temp_password, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare("
                INSERT INTO users (name, email, password_hash, role, branch_id, status, must_change_password)
                VALUES (?, ?, ?, ?, ?, ?, 1)
            ");
            $stmt->execute([
                $name,
                $login_id,
                $password_hash,
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
                'temporary_password' => true,
            ]);

            $branch_name_str = 'All Branches';
            if ($role === 'front-desk') {
                foreach ($branches as $b) {
                    if ((int) $b['id'] === (int) $branch_id) {
                        $branch_name_str = users_branch_label($b['name']);
                        break;
                    }
                }
            }

            $_SESSION['temp_credentials_notice'] = [
                'title' => 'New User Account Created',
                'subtitle' => 'Temporary credentials generated successfully',
                'name' => $name,
                'login_id' => $login_id,
                'temp_password' => $temp_password,
                'role' => users_role_label($role),
                'branch' => $branch_name_str,
                'action' => 'create',
            ];

            set_flash_message('User added successfully. Temporary password generated.', 'success');
            session_write_close();
            redirect($return_url);
        }

        if ($action === 'reset_password') {
            $target_id = (int) ($_POST['user_id'] ?? 0);
            if ($target_id <= 0) {
                throw new Exception('Invalid user selected.');
            }

            $stmt = $pdo->prepare("SELECT u.*, b.name AS branch_name FROM users u LEFT JOIN branches b ON b.id = u.branch_id WHERE u.id = ?");
            $stmt->execute([$target_id]);
            $old_user = $stmt->fetch();

            if (!$old_user) {
                throw new Exception('User not found.');
            }

            $temp_password = app_generate_temporary_password(12);
            $new_hash = password_hash($temp_password, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare("UPDATE users SET password_hash = ?, must_change_password = 1, password_changed_at = NULL WHERE id = ?");
            $stmt->execute([$new_hash, $target_id]);

            log_audit('users', 'password_reset_admin', $target_id, $old_user, [
                'login_id' => $old_user['email'],
                'must_change_password' => 1,
            ]);

            $branch_name_str = ($old_user['role'] ?? '') === 'admin' ? 'All Branches' : users_branch_label($old_user['branch_name'] ?? '');

            $_SESSION['temp_credentials_notice'] = [
                'title' => 'Password Reset Successfully',
                'subtitle' => 'New temporary credentials generated',
                'name' => $old_user['name'],
                'login_id' => $old_user['email'],
                'temp_password' => $temp_password,
                'role' => users_role_label($old_user['role']),
                'branch' => $branch_name_str,
                'action' => 'reset',
            ];

            set_flash_message('Password reset successfully. New temporary password generated.', 'success');
            session_write_close();
            redirect($return_url);
        }

        if ($action === 'update') {
            $name = trim($_POST['name'] ?? '');
            $login_id = users_normalize_login_id($_POST['login_id'] ?? '');
            $role = $_POST['role'] ?? 'front-desk';
            $status = $_POST['status'] ?? 'active';
            $branch_id = $role === 'admin' ? null : (int) ($_POST['branch_id'] ?? 0);

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

            $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, role = ?, branch_id = ?, status = ? WHERE id = ?");
            $stmt->execute([$name, $login_id, $role, $branch_id, $status, $target_id]);

            log_audit('users', 'update', $target_id, $old_user, [
                'name' => $name,
                'login_id' => $login_id,
                'role' => $role,
                'branch_id' => $branch_id,
                'status' => $status,
            ]);
            set_flash_message('User updated successfully.', 'success');
            redirect($return_url);
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
            redirect($return_url);
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
            redirect($return_url);
        }

        throw new Exception('Invalid action.');
    } catch (Exception $e) {
        set_flash_message($e->getMessage(), 'danger');
        redirect('/hwtires/admin/users/');
    }
}

// Global System-Wide Stats (Stat cards never fluctuate with filters)
$stats = [
    'total' => (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
    'active' => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'active'")->fetchColumn(),
    'admins' => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn(),
];

// Filters & Pagination Capture & Normalization
$branch_filter = trim((string) ($_GET['branch_id'] ?? ''));
$role_filter = trim((string) ($_GET['role'] ?? ''));
$status_filter = trim((string) ($_GET['status'] ?? ''));
$search_query = trim((string) ($_GET['search'] ?? ''));

$per_page = (int) ($_GET['per_page'] ?? 10);
if (!in_array($per_page, [10, 20, 50], true)) {
    $per_page = 10;
}

$page = max(1, (int) ($_GET['page'] ?? 1));

// Build WHERE conditions
$where_clauses = [];
$params = [];

if ($branch_filter !== '' && $branch_filter !== 'all') {
    $where_clauses[] = "u.branch_id = ?";
    $params[] = (int) $branch_filter;
}

if ($role_filter !== '' && $role_filter !== 'all' && in_array($role_filter, ['admin', 'front-desk'], true)) {
    $where_clauses[] = "u.role = ?";
    $params[] = $role_filter;
}

if ($status_filter !== '' && $status_filter !== 'all' && in_array($status_filter, ['active', 'inactive'], true)) {
    $where_clauses[] = "u.status = ?";
    $params[] = $status_filter;
}

if ($search_query !== '') {
    $where_clauses[] = "(u.name LIKE ? OR u.email LIKE ?)";
    $params[] = '%' . $search_query . '%';
    $params[] = '%' . $search_query . '%';
}

$where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

// Count filtered records
$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM users u $where_sql");
$count_stmt->execute($params);
$total_filtered = (int) $count_stmt->fetchColumn();

// Calculate pagination
$total_pages = max(1, (int) ceil($total_filtered / $per_page));
if ($page > $total_pages) {
    $page = $total_pages;
}
$offset = ($page - 1) * $per_page;

// Fetch paginated records
$stmt = $pdo->prepare("
    SELECT u.*, b.name AS branch_name
    FROM users u
    LEFT JOIN branches b ON b.id = u.branch_id
    $where_sql
    ORDER BY FIELD(u.role, 'admin', 'front-desk'), COALESCE(u.branch_id, 0), u.name, u.id ASC
    LIMIT $per_page OFFSET $offset
");
$stmt->execute($params);
$users = $stmt->fetchAll();

$has_active_filters = ($branch_filter !== '' && $branch_filter !== 'all') || 
                      ($role_filter !== '' && $role_filter !== 'all') || 
                      ($status_filter !== '' && $status_filter !== 'all') || 
                      ($search_query !== '');

$start_entry = $total_filtered > 0 ? $offset + 1 : 0;
$end_entry = min($offset + $per_page, $total_filtered);
$current_view_url = $_SERVER['REQUEST_URI'] ?? '/hwtires/admin/users/';
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

    <!-- System-Wide Summary Cards -->
    <section class="users-summary-grid" aria-label="System-wide user summary">
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
            <span class="users-summary-icon icon-green"><i class="fas fa-user-check"></i></span>
        </article>
        <article class="users-summary-card">
            <div>
                <span>Admin / Owner</span>
                <strong><?php echo (int) $stats['admins']; ?></strong>
            </div>
            <span class="users-summary-icon icon-amber"><i class="fas fa-user-shield"></i></span>
        </article>
    </section>

    <!-- Filters & Search Toolbar -->
    <section class="users-toolbar-card" aria-label="User filters and search">
        <form method="GET" action="/hwtires/admin/users/" class="users-filter-form">
            <input type="hidden" name="per_page" value="<?php echo (int) $per_page; ?>">
            <div class="users-filter-grid">
                <div class="users-filter-group-wrap">
                    <div class="users-filter-item">
                        <label for="branchFilter">Branch</label>
                        <select id="branchFilter" name="branch_id" onchange="this.form.submit()">
                            <option value="all">All Branches</option>
                            <?php foreach ($branches as $branch): ?>
                                <option value="<?php echo (int) $branch['id']; ?>" <?php echo $branch_filter !== '' && (int) $branch_filter === (int) $branch['id'] ? 'selected' : ''; ?>>
                                    <?php echo esc_html(users_branch_label($branch['name'])); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="users-filter-item">
                        <label for="roleFilter">Role</label>
                        <select id="roleFilter" name="role" onchange="this.form.submit()">
                            <option value="all">All Roles</option>
                            <option value="admin" <?php echo $role_filter === 'admin' ? 'selected' : ''; ?>>Admin/Owner</option>
                            <option value="front-desk" <?php echo $role_filter === 'front-desk' ? 'selected' : ''; ?>>Front Desk</option>
                        </select>
                    </div>

                    <div class="users-filter-item">
                        <label for="statusFilter">Status</label>
                        <select id="statusFilter" name="status" onchange="this.form.submit()">
                            <option value="all">All Statuses</option>
                            <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>

                    <div class="users-filter-actions">
                        <button type="submit" class="users-filter-btn" title="Apply filters">
                            <i class="fas fa-filter me-1"></i> Filter
                        </button>
                    </div>
                </div>

                <div class="users-search-group">
                    <label for="userSearch">Search</label>
                    <div class="users-search-input-wrap">
                        <div class="search-input-wrap position-relative flex-grow-1">
                            <i class="fas fa-search position-absolute top-50 translate-middle-y text-muted" style="left: 14px;"></i>
                            <input
                                type="text"
                                id="userSearch"
                                name="search"
                                maxlength="100"
                                data-text-format="first-letter"
                                autocomplete="off"
                                data-no-autocomplete="true"
                                value="<?php echo esc_attr($search_query); ?>"
                                placeholder="Search by name or login ID..."
                            >
                        </div>
                        <button type="submit" class="btn btn-secondary users-search-btn" title="Search users">
                            <i class="fas fa-search me-1"></i> Search
                        </button>
                        <?php if ($has_active_filters): ?>
                            <a href="/hwtires/admin/users/" class="users-reset-btn" title="Reset all filters">
                                <i class="fas fa-rotate-left me-1"></i> Reset
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </form>
    </section>

    <!-- Results Count & Per-Page Controls -->
    <div class="users-results-bar">
        <span class="users-results-count">
            <?php if ($total_filtered === 0): ?>
                No users found
            <?php elseif ($has_active_filters): ?>
                Showing <strong><?php echo $start_entry; ?>–<?php echo $end_entry; ?></strong> of <strong><?php echo $total_filtered; ?></strong> filtered users
            <?php else: ?>
                Showing <strong><?php echo $start_entry; ?>–<?php echo $end_entry; ?></strong> of <strong><?php echo $total_filtered; ?></strong> users
            <?php endif; ?>
        </span>

        <div class="users-per-page-wrap">
            <label for="perPageSelect">Show:</label>
            <select id="perPageSelect" onchange="location.href=this.value;">
                <?php foreach ([10, 20, 50] as $opt): ?>
                    <option value="<?php echo esc_attr(users_filter_url(['per_page' => $opt, 'page' => 1])); ?>" <?php echo $per_page === $opt ? 'selected' : ''; ?>>
                        <?php echo $opt; ?> per page
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <!-- Users Table -->
    <section class="users-table-card" aria-label="Users list">
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
                            <td colspan="6" class="users-empty-cell">
                                <div class="users-empty-box">
                                    <i class="fas fa-user-slash"></i>
                                    <p>No users match the selected criteria.</p>
                                    <?php if ($has_active_filters): ?>
                                        <a href="/hwtires/admin/users/" class="users-reset-link">Reset all filters</a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($users as $account): ?>
                            <?php
                            $account_id = (int) $account['id'];
                            $is_self = $account_id === (int) ($user['id'] ?? 0);
                            $edit_modal_id = 'editUserModal' . $account_id;
                            $reset_modal_id = 'resetUserModal' . $account_id;
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
                                <td><code class="users-login-id"><?php echo esc_html($account['email']); ?></code></td>
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
                                        <button type="button" class="users-icon-btn reset-pwd" data-bs-toggle="modal" data-bs-target="#<?php echo esc_attr($reset_modal_id); ?>" title="Reset password">
                                            <i class="fas fa-key"></i>
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

        <!-- Pagination Controls -->
        <?php if ($total_pages > 1): ?>
            <nav class="users-pagination-nav" aria-label="User pagination">
                <ul class="users-pagination">
                    <?php if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="<?php echo esc_attr(users_filter_url(['page' => $page - 1])); ?>" aria-label="Previous">
                                <i class="fas fa-chevron-left"></i> Previous
                            </a>
                        </li>
                    <?php else: ?>
                        <li class="page-item disabled">
                            <span class="page-link"><i class="fas fa-chevron-left"></i> Previous</span>
                        </li>
                    <?php endif; ?>

                    <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                        <?php if ($p === $page): ?>
                            <li class="page-item active">
                                <span class="page-link"><?php echo $p; ?></span>
                            </li>
                        <?php elseif ($p === 1 || $p === $total_pages || ($p >= $page - 2 && $p <= $page + 2)): ?>
                            <li class="page-item">
                                <a class="page-link" href="<?php echo esc_attr(users_filter_url(['page' => $p])); ?>"><?php echo $p; ?></a>
                            </li>
                        <?php elseif ($p === $page - 3 || $p === $page + 3): ?>
                            <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($page < $total_pages): ?>
                        <li class="page-item">
                            <a class="page-link" href="<?php echo esc_attr(users_filter_url(['page' => $page + 1])); ?>" aria-label="Next">
                                Next <i class="fas fa-chevron-right"></i>
                            </a>
                        </li>
                    <?php else: ?>
                        <li class="page-item disabled">
                            <span class="page-link">Next <i class="fas fa-chevron-right"></i></span>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>
        <?php endif; ?>
    </section>

    <!-- Modals for Edit, Reset Password, and Status (Archive/Reactivate) -->
    <?php foreach ($users as $account): ?>
        <?php
        $account_id = (int) $account['id'];
        $edit_modal_id = 'editUserModal' . $account_id;
        $reset_modal_id = 'resetUserModal' . $account_id;
        $status_modal_id = 'userStatusModal' . $account_id;
        ?>
        <!-- Edit User Modal (Profile Attributes Only - No Password Fields) -->
        <div class="modal fade users-modal" id="<?php echo esc_attr($edit_modal_id); ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form method="POST" class="users-form">
                        <input type="hidden" name="csrf_token" value="<?php echo esc_attr($csrf_token); ?>">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="user_id" value="<?php echo $account_id; ?>">
                        <input type="hidden" name="return_url" value="<?php echo esc_attr($current_view_url); ?>">

                        <div class="users-modal-header">
                            <h2>Edit User</h2>
                            <button type="button" class="users-modal-close" data-bs-dismiss="modal" aria-label="Close">
                                <i class="fas fa-xmark"></i>
                            </button>
                        </div>

                        <div class="users-modal-body">
                            <label>
                                <span>Name</span>
                                <input type="text" name="name" value="<?php echo esc_attr($account['name']); ?>" maxlength="100" data-text-format="person-name" required>
                            </label>
                            <label>
                                <span>Login ID</span>
                                <input type="text" name="login_id" value="<?php echo esc_attr($account['email']); ?>" maxlength="100" required>
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
                        </div>

                        <div class="users-modal-footer">
                            <button type="button" class="users-cancel-btn" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="users-submit-btn">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Reset Password Modal -->
        <div class="modal fade users-modal users-confirm-modal" id="<?php echo esc_attr($reset_modal_id); ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="users-modal-header">
                        <h2>Reset User Password</h2>
                        <button type="button" class="users-modal-close" data-bs-dismiss="modal" aria-label="Close">
                            <i class="fas fa-xmark"></i>
                        </button>
                    </div>

                    <div class="users-modal-body">
                        <div class="users-confirm-box">
                            <strong><?php echo esc_html($account['name']); ?></strong>
                            <p><?php echo esc_html($account['email']); ?> &bull; <?php echo esc_html(users_role_label($account['role'])); ?></p>
                        </div>
                        <p class="users-confirm-copy">
                            Are you sure you want to reset this user's password? The system will generate a secure temporary password. The employee will be required to create a new personal password upon their next login.
                        </p>
                    </div>

                    <div class="users-modal-footer">
                        <button type="button" class="users-cancel-btn" data-bs-dismiss="modal">Cancel</button>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo esc_attr($csrf_token); ?>">
                            <input type="hidden" name="action" value="reset_password">
                            <input type="hidden" name="user_id" value="<?php echo $account_id; ?>">
                            <input type="hidden" name="return_url" value="<?php echo esc_attr($current_view_url); ?>">
                            <button type="submit" class="users-submit-btn" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);">
                                <i class="fas fa-key me-1"></i> Reset Password
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- User Status / Archive Modal -->
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
                                Archive this user to prevent future logins while keeping the account record and historical audit trail intact.
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
                            <input type="hidden" name="return_url" value="<?php echo esc_attr($current_view_url); ?>">
                            <button type="submit" class="users-submit-btn <?php echo ($account['status'] ?? '') === 'active' ? 'warning' : ''; ?>">
                                <?php echo ($account['status'] ?? '') === 'active' ? 'Archive' : 'Reactivate'; ?>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <!-- Add User Modal -->
    <div class="modal fade users-modal" id="addUserModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" class="users-form">
                    <input type="hidden" name="csrf_token" value="<?php echo esc_attr($csrf_token); ?>">
                    <input type="hidden" name="action" value="create">
                    <input type="hidden" name="return_url" value="<?php echo esc_attr($current_view_url); ?>">

                    <div class="users-modal-header">
                        <h2>Add New User</h2>
                        <button type="button" class="users-modal-close" data-bs-dismiss="modal" aria-label="Close">
                            <i class="fas fa-xmark"></i>
                        </button>
                    </div>

                    <div class="users-modal-body">
                        <div class="users-temp-pwd-info">
                            <i class="fas fa-shield-halved"></i>
                            <div>
                                <strong>Automatic Temporary Password</strong>
                                <p>A cryptographically secure temporary password will be automatically generated and displayed once upon account creation. The employee must change it on their first login.</p>
                            </div>
                        </div>

                        <label>
                            <span>Name</span>
                            <input type="text" name="name" placeholder="Enter user name" maxlength="100" data-text-format="person-name" required>
                        </label>
                        <label>
                            <span>Login ID</span>
                            <input type="text" name="login_id" placeholder="e.g. branch1.frontdesk" maxlength="100" required>
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
                        <button type="submit" class="users-submit-btn">
                            <i class="fas fa-user-plus me-1"></i> Add User
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Temporary Credentials Modal (Enhanced High-Contrast Front-End Presentation) -->
    <?php
    $temp_notice = $_SESSION['temp_credentials_notice'] ?? null;
    unset($_SESSION['temp_credentials_notice']);
    ?>
    <?php if ($temp_notice): ?>
    <div class="modal fade users-modal" id="tempCredentialsModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content temp-cred-card">
                <div class="users-modal-header temp-cred-header">
                    <div class="temp-cred-title-wrap">
                        <span class="temp-cred-badge-icon"><i class="fas fa-shield-halved"></i></span>
                        <div>
                            <h2><?php echo esc_html($temp_notice['title'] ?? 'Temporary Credentials'); ?></h2>
                            <p class="temp-cred-subtitle"><?php echo esc_html($temp_notice['subtitle'] ?? 'Credentials generated successfully'); ?></p>
                        </div>
                    </div>
                    <button type="button" class="users-modal-close" data-bs-dismiss="modal" aria-label="Close">
                        <i class="fas fa-xmark"></i>
                    </button>
                </div>

                <div class="users-modal-body temp-cred-body">
                    <!-- Employee Summary Box with Strong Contrast -->
                    <div class="temp-cred-employee-box">
                        <div class="temp-cred-row">
                            <span class="temp-cred-meta-label">Employee Name</span>
                            <strong class="temp-cred-emp-name"><?php echo esc_html($temp_notice['name'] ?? ''); ?></strong>
                        </div>
                        <div class="temp-cred-badges">
                            <span class="temp-cred-pill role-pill"><i class="fas fa-user-tag me-1"></i> <?php echo esc_html($temp_notice['role'] ?? ''); ?></span>
                            <span class="temp-cred-pill branch-pill"><i class="fas fa-building me-1"></i> <?php echo esc_html($temp_notice['branch'] ?? ''); ?></span>
                        </div>
                    </div>

                    <!-- Login ID Card -->
                    <div class="temp-cred-field-group">
                        <label class="temp-cred-field-label">Login ID / Username</label>
                        <div class="temp-cred-code-box">
                            <code id="tempLoginId" class="temp-cred-code-val"><?php echo esc_html($temp_notice['login_id'] ?? ''); ?></code>
                        </div>
                    </div>

                    <!-- Temporary Password Card -->
                    <div class="temp-cred-field-group">
                        <label class="temp-cred-field-label">Generated Temporary Password</label>
                        <div class="temp-cred-code-box highlight-pwd">
                            <code id="tempPasswordVal" class="temp-cred-pwd-val"><?php echo esc_html($temp_notice['temp_password'] ?? ''); ?></code>
                            <button type="button" class="temp-cred-copy-btn" id="copyCredentialsBtn" title="Copy Login ID and Temporary Password">
                                <i class="far fa-copy me-1"></i> <span>Copy</span>
                            </button>
                        </div>
                    </div>

                    <!-- One-Time Security Notice -->
                    <div class="temp-cred-warning-box">
                        <i class="fas fa-triangle-exclamation"></i>
                        <div>
                            <strong>One-Time Display Warning</strong>
                            <p>Please copy or securely share these credentials with the employee. This temporary password will <strong>NOT</strong> be displayed again. The employee will be forced to create their own personal password upon first login.</p>
                        </div>
                    </div>
                </div>

                <div class="users-modal-footer temp-cred-footer">
                    <button type="button" class="users-submit-btn temp-cred-done-btn" data-bs-dismiss="modal">
                        <i class="fas fa-check me-1"></i> Done / Close
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</main>

<style>
/* Filter & Search Toolbar */
.users-toolbar-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 16px;
    margin-bottom: 16px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
}
.users-filter-grid {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr 1fr auto;
    gap: 12px;
    align-items: flex-end;
}
.users-filter-item {
    display: flex;
    flex-direction: column;
    gap: 5px;
}
.users-filter-item label {
    font-size: 11.5px;
    font-weight: 700;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.users-search-input-wrap {
    position: relative;
    display: flex;
    align-items: center;
}
.users-search-input-wrap i {
    position: absolute;
    left: 12px;
    color: #94a3b8;
    font-size: 14px;
}
.users-search-input-wrap input {
    width: 100%;
    padding: 9px 12px 9px 36px;
    background: #f8fafc;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    color: #0f172a;
    font-size: 13.5px;
    transition: all 0.2s ease;
}
.users-search-input-wrap input:focus {
    outline: none;
    background: #ffffff;
    border-color: #06b6d4;
    box-shadow: 0 0 0 3px rgba(6, 182, 212, 0.15);
}
.users-filter-item select {
    padding: 9px 12px;
    background: #f8fafc;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    color: #0f172a;
    font-size: 13.5px;
    cursor: pointer;
    transition: all 0.2s ease;
}
.users-filter-item select:focus {
    outline: none;
    background: #ffffff;
    border-color: #06b6d4;
    box-shadow: 0 0 0 3px rgba(6, 182, 212, 0.15);
}
.users-filter-actions {
    display: flex;
    gap: 8px;
}
.users-filter-btn {
    padding: 9px 16px;
    background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
    border: none;
    border-radius: 8px;
    color: #ffffff;
    font-size: 13.5px;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    transition: all 0.2s ease;
    white-space: nowrap;
}
.users-filter-btn:hover {
    filter: brightness(1.1);
    transform: translateY(-1px);
}
.users-reset-btn {
    padding: 9px 14px;
    background: #f1f5f9;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    color: #475569;
    font-size: 13.5px;
    font-weight: 600;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    transition: all 0.2s ease;
    white-space: nowrap;
}
.users-reset-btn:hover {
    background: #e2e8f0;
    color: #0f172a;
}

/* Results Count & Per Page Bar */
.users-results-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
    padding: 0 4px;
    font-size: 13px;
    color: #64748b;
}
.users-results-count strong {
    color: #0f172a;
}
.users-per-page-wrap {
    display: flex;
    align-items: center;
    gap: 8px;
}
.users-per-page-wrap label {
    font-size: 12.5px;
    color: #64748b;
}
.users-per-page-wrap select {
    padding: 4px 8px;
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    color: #0f172a;
    font-size: 12.5px;
    cursor: pointer;
}

/* Login ID Column Formatting */
.users-table td code.users-login-id,
.users-login-id {
    font-family: 'JetBrains Mono', 'Fira Code', Consolas, monospace;
    font-size: 12.5px;
    font-weight: 600;
    color: #334155;
    background: #f1f5f9;
    padding: 3px 8px;
    border-radius: 6px;
    border: 1px solid #e2e8f0;
    display: inline-block;
}
.users-table tr.is-inactive .users-login-id {
    color: #64748b;
    background: #f8fafc;
    border-color: #e2e8f0;
}

/* Empty State */
.users-empty-box {
    padding: 32px 16px;
    text-align: center;
    color: #64748b;
}
.users-empty-box i {
    font-size: 36px;
    margin-bottom: 10px;
    opacity: 0.6;
}
.users-empty-box p {
    font-size: 14px;
    margin-bottom: 8px;
    color: #64748b;
}
.users-reset-link {
    color: #06b6d4;
    text-decoration: none;
    font-size: 13px;
    font-weight: 600;
}
.users-reset-link:hover {
    text-decoration: underline;
}

/* Pagination Navigation */
.users-pagination-nav {
    display: flex;
    justify-content: center;
    padding: 16px;
    border-top: 1px solid #e2e8f0;
}
.users-pagination {
    display: flex;
    list-style: none;
    padding: 0;
    margin: 0;
    gap: 6px;
    align-items: center;
}
.users-pagination .page-item .page-link {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 7px 13px;
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    color: #475569;
    font-size: 13px;
    font-weight: 500;
    text-decoration: none;
    transition: all 0.2s ease;
}
.users-pagination .page-item:not(.disabled):not(.active) .page-link:hover {
    background: #f8fafc;
    border-color: #06b6d4;
    color: #0891b2;
}
.users-pagination .page-item.active .page-link {
    background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
    border-color: #06b6d4;
    color: #ffffff;
    font-weight: 700;
}
.users-pagination .page-item.disabled .page-link {
    opacity: 0.5;
    cursor: not-allowed;
    background: #f8fafc;
    border-color: #e2e8f0;
    color: #94a3b8;
}

/* Action buttons */
.users-icon-btn.reset-pwd {
    background: rgba(245, 158, 11, 0.15);
    color: #f59e0b;
    border: 1px solid rgba(245, 158, 11, 0.3);
}
.users-icon-btn.reset-pwd:hover {
    background: #f59e0b;
    color: #ffffff;
}
.users-temp-pwd-info {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    background: rgba(6, 182, 212, 0.1);
    border: 1px solid rgba(6, 182, 212, 0.25);
    border-radius: 12px;
    padding: 12px 14px;
    margin-bottom: 16px;
    color: #cbd5e1;
    font-size: 13px;
}
.users-temp-pwd-info i {
    color: #06b6d4;
    font-size: 20px;
    margin-top: 2px;
}
.users-temp-pwd-info strong {
    color: #ffffff;
    display: block;
    margin-bottom: 2px;
}
.users-temp-pwd-info p {
    margin: 0;
    color: #94a3b8;
    font-size: 12.5px;
    line-height: 1.4;
}

/* Enhanced Temporary Credentials Modal Styling */
.temp-cred-card {
    background: #0b1329 !important;
    border: 1px solid rgba(6, 182, 212, 0.45) !important;
    box-shadow: 0 20px 40px -10px rgba(0, 0, 0, 0.7), 0 0 25px rgba(6, 182, 212, 0.15) !important;
    border-radius: 16px !important;
    overflow: hidden;
}
.temp-cred-header {
    background: #0f172a;
    border-bottom: 1px solid rgba(6, 182, 212, 0.25);
    padding: 18px 22px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.temp-cred-title-wrap {
    display: flex;
    align-items: center;
    gap: 14px;
}
.temp-cred-badge-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    background: rgba(6, 182, 212, 0.15);
    border: 1px solid rgba(6, 182, 212, 0.35);
    color: #06b6d4;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
}
.temp-cred-title-wrap h2 {
    font-size: 17px;
    font-weight: 700;
    color: #f8fafc;
    margin: 0 0 2px 0;
}
.temp-cred-subtitle {
    font-size: 12.5px;
    color: #94a3b8;
    margin: 0;
}
.temp-cred-body {
    padding: 22px;
    background: #0b1329;
}
.temp-cred-employee-box {
    background: #131d36;
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 12px;
    padding: 14px 16px;
    margin-bottom: 18px;
}
.temp-cred-row {
    margin-bottom: 8px;
}
.temp-cred-meta-label {
    display: block;
    font-size: 11.5px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #94a3b8;
    margin-bottom: 3px;
}
.temp-cred-emp-name {
    font-size: 16px;
    font-weight: 700;
    color: #ffffff;
}
.temp-cred-badges {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 6px;
}
.temp-cred-pill {
    font-size: 12px;
    font-weight: 600;
    padding: 4px 10px;
    border-radius: 6px;
    display: inline-flex;
    align-items: center;
}
.temp-cred-pill.role-pill {
    background: rgba(56, 189, 248, 0.15);
    color: #38bdf8;
    border: 1px solid rgba(56, 189, 248, 0.3);
}
.temp-cred-pill.branch-pill {
    background: rgba(165, 243, 252, 0.12);
    color: #67e8f9;
    border: 1px solid rgba(165, 243, 252, 0.25);
}
.temp-cred-field-group {
    margin-bottom: 16px;
}
.temp-cred-field-label {
    font-size: 12px;
    font-weight: 600;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    display: block;
    margin-bottom: 6px;
}
.temp-cred-code-box {
    background: #020617;
    border: 1px solid rgba(255, 255, 255, 0.15);
    border-radius: 10px;
    padding: 10px 14px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.temp-cred-code-box.highlight-pwd {
    border: 1px solid rgba(6, 182, 212, 0.5);
    background: #050d24;
}
.temp-cred-code-val {
    font-family: 'JetBrains Mono', 'Fira Code', Consolas, monospace;
    color: #38bdf8;
    font-size: 14.5px;
    font-weight: 600;
}
.temp-cred-pwd-val {
    font-family: 'JetBrains Mono', 'Fira Code', Consolas, monospace;
    color: #22d3ee;
    font-size: 17px;
    font-weight: 700;
    letter-spacing: 1px;
}
.temp-cred-copy-btn {
    padding: 6px 14px;
    background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
    border: none;
    border-radius: 6px;
    color: #ffffff;
    font-size: 12.5px;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    transition: all 0.2s ease;
    white-space: nowrap;
}
.temp-cred-copy-btn:hover {
    filter: brightness(1.15);
    transform: translateY(-1px);
}
.temp-cred-copy-btn.btn-copied {
    background: #10b981 !important;
}
.temp-cred-warning-box {
    background: rgba(245, 158, 11, 0.12);
    border: 1px solid rgba(245, 158, 11, 0.35);
    border-radius: 10px;
    padding: 12px 14px;
    display: flex;
    gap: 12px;
    align-items: flex-start;
    color: #fef08a;
    font-size: 12.5px;
    line-height: 1.45;
}
.temp-cred-warning-box i {
    color: #f59e0b;
    font-size: 17px;
    margin-top: 2px;
    flex-shrink: 0;
}
.temp-cred-warning-box strong {
    color: #fbbf24;
    display: block;
    margin-bottom: 2px;
}
.temp-cred-warning-box p {
    margin: 0;
    color: #fef3c7;
}
.temp-cred-footer {
    background: #0f172a;
    border-top: 1px solid rgba(255, 255, 255, 0.08);
    padding: 14px 22px;
    display: flex;
    justify-content: flex-end;
}
.temp-cred-done-btn {
    padding: 9px 22px;
    font-size: 14px;
}

@media (max-width: 992px) {
    .users-filter-grid {
        grid-template-columns: 1fr 1fr;
    }
    .users-filter-item.search-item {
        grid-column: span 2;
    }
    .users-filter-actions {
        grid-column: span 2;
        justify-content: flex-end;
    }
}
@media (max-width: 600px) {
    .users-filter-grid {
        grid-template-columns: 1fr;
    }
    .users-filter-item.search-item,
    .users-filter-actions {
        grid-column: span 1;
    }
}
</style>

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

    // Auto-show temp credentials modal if present
    const tempCredModalEl = document.getElementById('tempCredentialsModal');
    if (tempCredModalEl && typeof bootstrap !== 'undefined') {
        const tempCredModal = new bootstrap.Modal(tempCredModalEl);
        tempCredModal.show();

        const copyBtn = document.getElementById('copyCredentialsBtn');
        if (copyBtn) {
            copyBtn.addEventListener('click', function () {
                const loginId = document.getElementById('tempLoginId')?.innerText || '';
                const tempPwd = document.getElementById('tempPasswordVal')?.innerText || '';
                const textToCopy = `Login ID: ${loginId}\nTemporary Password: ${tempPwd}`;

                navigator.clipboard.writeText(textToCopy).then(function () {
                    copyBtn.innerHTML = '<i class="fas fa-check me-1"></i> Copied!';
                    copyBtn.classList.add('btn-copied');
                    setTimeout(function () {
                        copyBtn.innerHTML = '<i class="far fa-copy me-1"></i> Copy';
                        copyBtn.classList.remove('btn-copied');
                    }, 2500);
                });
            });
        }
    }
});
</script>

<?php require_once '../../includes/footer.php'; ?>
