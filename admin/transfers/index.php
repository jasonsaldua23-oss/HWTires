<?php
/**
 * Inter-Branch Transfer Management Page
 * Admin can view, approve, and manage transfer requests between branches
 */

require_once __DIR__ . '/../../includes/config.php';

$user = app_get_session_user();
if (!$user) {
    redirect('/hwtires/');
}

if ($user['role'] !== 'admin') {
    redirect('/hwtires/front-desk/');
}

// Get list of branches for filtering
$branches = $pdo->query("
    SELECT id, name FROM branches WHERE status = 'active' ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

// Get filter parameters
$filter_status = $_GET['status'] ?? '';
$filter_branch = (int) ($_GET['branch'] ?? 0);
$filter_priority = $_GET['priority'] ?? '';
$search = trim($_GET['search'] ?? '');

// Build query
$where = ['1=1'];
$params = [];

if ($filter_status) {
    $where[] = "t.status = ?";
    $params[] = $filter_status;
}

if ($filter_branch) {
    $where[] = "(t.requesting_branch_id = ? OR t.donor_branch_id = ?)";
    $params[] = $filter_branch;
    $params[] = $filter_branch;
}

if ($filter_priority) {
    $where[] = "t.priority = ?";
    $params[] = $filter_priority;
}

if ($search) {
    foreach (app_search_terms($search) as $term) {
        $where[] = "(
            t.request_number LIKE ?
            OR t.item_name LIKE ?
            OR t.reason LIKE ?
            OR rb.name LIKE ?
            OR db.name LIKE ?
            OR u.name LIKE ?
        )";
        $params = array_merge($params, array_fill(0, 6, '%' . $term . '%'));
    }
}

$where_sql = implode(' AND ', $where);

// Pagination
$page = (int) ($_GET['page'] ?? 1);
$per_page = (int) ($_GET['per_page'] ?? 20);
$offset = ($page - 1) * $per_page;

// Get total count
$stmt = $pdo->prepare("
    SELECT COUNT(*) as total FROM inter_branch_transfer_requests t
    LEFT JOIN branches rb ON t.requesting_branch_id = rb.id
    LEFT JOIN branches db ON t.donor_branch_id = db.id
    LEFT JOIN users u ON t.requested_by = u.id
    WHERE $where_sql
");
$stmt->execute($params);
$total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total / $per_page);

// Get transfers
$stmt = $pdo->prepare("
    SELECT
        t.id, t.request_number, t.status, t.priority,
        t.item_name, t.requested_quantity, t.approved_quantity,
        t.reason, t.created_at,
        rb.name as requesting_branch,
        db.name as donor_branch,
        u.name as requested_by
    FROM inter_branch_transfer_requests t
    LEFT JOIN branches rb ON t.requesting_branch_id = rb.id
    LEFT JOIN branches db ON t.donor_branch_id = db.id
    LEFT JOIN users u ON t.requested_by = u.id
    WHERE $where_sql
    ORDER BY t.created_at DESC
    LIMIT ? OFFSET ?
");
$stmt->execute(array_merge($params, [$per_page, $offset]));
$transfers = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inter-Branch Transfers - Highway Tires</title>
    <link href="/hwtires/assets/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="/hwtires/assets/css/custom.css" rel="stylesheet">
</head>
<body class="bg-light">
    <?php include __DIR__ . '/../../includes/header.php'; ?>

    <div class="container-fluid py-4">
        <div class="row mb-4">
            <div class="col-12">
                <h1 class="h3 mb-0">Inter-Branch Transfer Requests</h1>
            </div>
        </div>

        <!-- Filters -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="">All Statuses</option>
                            <option value="pending" <?= $filter_status === 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="approved" <?= $filter_status === 'approved' ? 'selected' : '' ?>>Approved</option>
                            <option value="shipped" <?= $filter_status === 'shipped' ? 'selected' : '' ?>>Shipped</option>
                            <option value="received" <?= $filter_status === 'received' ? 'selected' : '' ?>>Received</option>
                            <option value="cancelled" <?= $filter_status === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Priority</label>
                        <select name="priority" class="form-select">
                            <option value="">All Priorities</option>
                            <option value="high" <?= $filter_priority === 'high' ? 'selected' : '' ?>>High</option>
                            <option value="medium" <?= $filter_priority === 'medium' ? 'selected' : '' ?>>Medium</option>
                            <option value="low" <?= $filter_priority === 'low' ? 'selected' : '' ?>>Low</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Branch</label>
                        <select name="branch" class="form-select">
                            <option value="">All Branches</option>
                            <?php foreach ($branches as $b): ?>
                                <option value="<?= $b['id'] ?>" <?= $filter_branch === (int) $b['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($b['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Search</label>
                        <input type="text" name="search" class="form-control" value="<?= htmlspecialchars($search) ?>" placeholder="Request # or item...">
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary">Filter</button>
                        <a href="?page=1" class="btn btn-secondary">Reset</a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Table -->
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Request #</th>
                            <th>Item</th>
                            <th>From → To</th>
                            <th>Quantity</th>
                            <th>Priority</th>
                            <th>Status</th>
                            <th>Requested By</th>
                            <th>Date</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($transfers)): ?>
                            <tr>
                                <td colspan="9" class="text-center py-4 text-muted">
                                    No transfer requests found
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($transfers as $t): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($t['request_number']) ?></strong></td>
                                    <td><?= htmlspecialchars($t['item_name']) ?></td>
                                    <td>
                                        <small>
                                            <?= htmlspecialchars($t['donor_branch']) ?> →
                                            <?= htmlspecialchars($t['requesting_branch']) ?>
                                        </small>
                                    </td>
                                    <td>
                                        <?= $t['requested_quantity'] ?>
                                        <?php if ($t['approved_quantity'] > 0 && $t['approved_quantity'] != $t['requested_quantity']): ?>
                                            <br><small class="text-muted">(✓ <?= $t['approved_quantity'] ?>)</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge priority-badge priority-<?= htmlspecialchars($t['priority']) ?>">
                                            <?= ucfirst($t['priority']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php
                                        $status_class = [
                                            'pending' => 'bg-warning',
                                            'approved' => 'bg-info',
                                            'shipped' => 'bg-primary',
                                            'received' => 'bg-success',
                                            'cancelled' => 'bg-danger'
                                        ][$t['status']] ?? 'bg-secondary';
                                        ?>
                                        <span class="badge <?= $status_class ?>">
                                            <?= ucfirst($t['status']) ?>
                                        </span>
                                    </td>
                                    <td><small><?= htmlspecialchars($t['requested_by'] ?? 'N/A') ?></small></td>
                                    <td><small><?= date('M d, Y', strtotime($t['created_at'])) ?></small></td>
                                    <td>
                                        <a href="?request=<?= $t['id'] ?>" class="btn btn-sm btn-outline-primary">View</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <nav aria-label="Page navigation" class="mt-4">
                <ul class="pagination">
                    <?php if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>">First</a>
                        </li>
                        <li class="page-item">
                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">Previous</a>
                        </li>
                    <?php endif; ?>

                    <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>">
                                <?= $i ?>
                            </a>
                        </li>
                    <?php endfor; ?>

                    <?php if ($page < $total_pages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">Next</a>
                        </li>
                        <li class="page-item">
                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $total_pages])) ?>">Last</a>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>
        <?php endif; ?>
    </div>

    <?php include __DIR__ . '/../../includes/footer.php'; ?>

    <script src="/hwtires/assets/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>
