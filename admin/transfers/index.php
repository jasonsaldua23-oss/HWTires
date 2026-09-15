<?php
/**
 * Inter-Branch Transfer Management Page
 * Admin can view, approve, and manage transfer requests between branches
 */

require_once __DIR__ . '/../../includes/config.php';
global $pdo;
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_name(SESSION_NAME);
    session_start();
}

// Strict Authentication & Admin Authorization
if (!is_logged_in()) {
    redirect('/hwtires/index.php');
}

$user = app_get_session_user();
if (($user['role'] ?? '') !== 'admin') {
    redirect('/hwtires/' . ($user['role'] ?? 'front-desk') . '/');
}

$page_title = 'Branch Transfers';

// Get list of branches for filtering
$branches = $pdo->query("
    SELECT id, name FROM branches WHERE status = 'active' ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

// Get filter parameters
$filter_status = $_GET['status'] ?? '';
$filter_branch = (int) ($_GET['branch'] ?? 0);
$filter_priority = $_GET['priority'] ?? '';
$filter_request_id = (int) ($_GET['request'] ?? 0);
$search = trim($_GET['search'] ?? '');

// Build query
$where = ['1=1'];
$params = [];

if ($filter_request_id > 0) {
    $where[] = "t.id = ?";
    $params[] = $filter_request_id;
}

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

// Get transfers with current donor stock availability
$stmt = $pdo->prepare("
    SELECT
        t.id, t.request_number, t.status, t.priority,
        t.item_id, t.item_name, t.requested_quantity, t.approved_quantity,
        t.reason, t.notes, t.created_at,
        t.requesting_branch_id, t.donor_branch_id,
        rb.name as requesting_branch,
        db.name as donor_branch,
        u.name as requested_by,
        COALESCE(donor_item.quantity, 0) as donor_available_stock
    FROM inter_branch_transfer_requests t
    LEFT JOIN branches rb ON t.requesting_branch_id = rb.id
    LEFT JOIN branches db ON t.donor_branch_id = db.id
    LEFT JOIN users u ON t.requested_by = u.id
    LEFT JOIN inventory_items donor_item ON donor_item.id = t.item_id AND donor_item.branch_id = t.donor_branch_id
    WHERE $where_sql
    ORDER BY t.created_at DESC
    LIMIT $per_page OFFSET $offset
");
$stmt->execute($params);
$transfers = $stmt->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>

<div class="container-fluid px-3 py-4">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h1 class="h3 mb-1 fw-bold text-dark"><i class="fas fa-arrow-right-arrow-left text-primary me-2"></i>Branch Transfers</h1>
            <p class="text-muted mb-0">Manage and monitor stock transfer requests across all branches.</p>
        </div>
        <div>
            <span class="badge bg-light text-dark border px-3 py-2 fs-6">
                <i class="fas fa-boxes-stacked me-1 text-secondary"></i> Total Requests: <strong><?= number_format($total) ?></strong>
            </span>
        </div>
    </div>

        <!-- Filters -->
        <div class="card border-0 shadow-sm rounded-3 mb-4 transfers-filter-card hw-filter-card">
            <div class="card-body p-3">
                <form method="GET" action="/hwtires/admin/transfers/" class="transfers-unified-filter-form hw-filter-toolbar">
                    <div class="hw-filter-group hw-group-status">
                        <label for="transferStatus" class="hw-filter-label">Status</label>
                        <select id="transferStatus" name="status" class="hw-filter-select">
                            <option value="">All Statuses</option>
                            <option value="pending" <?= $filter_status === 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="approved" <?= $filter_status === 'approved' ? 'selected' : '' ?>>Approved</option>
                            <option value="shipped" <?= $filter_status === 'shipped' ? 'selected' : '' ?>>Shipped</option>
                            <option value="received" <?= $filter_status === 'received' ? 'selected' : '' ?>>Received</option>
                            <option value="cancelled" <?= $filter_status === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                        </select>
                    </div>
                    <div class="hw-filter-group hw-group-priority">
                        <label for="transferPriority" class="hw-filter-label">Priority</label>
                        <select id="transferPriority" name="priority" class="hw-filter-select">
                            <option value="">All Priorities</option>
                            <option value="high" <?= $filter_priority === 'high' ? 'selected' : '' ?>>High</option>
                            <option value="medium" <?= $filter_priority === 'medium' ? 'selected' : '' ?>>Medium</option>
                            <option value="low" <?= $filter_priority === 'low' ? 'selected' : '' ?>>Low</option>
                        </select>
                    </div>
                    <div class="hw-filter-group hw-group-branch">
                        <label for="transferBranch" class="hw-filter-label">Branch</label>
                        <select id="transferBranch" name="branch" class="hw-filter-select">
                            <option value="">All Branches</option>
                            <?php foreach ($branches as $b): ?>
                                <option value="<?= (int)$b['id'] ?>" <?= $filter_branch === (int) $b['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($b['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="hw-filter-actions">
                        <button type="submit" class="btn btn-primary hw-filter-icon-btn" title="Apply filters" aria-label="Apply filters">
                            <i class="fas fa-filter"></i>
                        </button>
                        <a href="/hwtires/admin/transfers/" class="btn btn-outline-secondary hw-filter-icon-btn hw-btn-reset" title="Reset filters" aria-label="Reset filters">
                            <i class="fas fa-rotate-left"></i>
                        </a>
                    </div>
                    <div class="hw-search-cluster">
                        <div class="hw-filter-group hw-group-search flex-grow-1">
                            <label for="transferSearch" class="hw-filter-label">Search</label>
                            <div class="hw-search-wrapper">
                                <input id="transferSearch" type="text" name="search" maxlength="100" data-text-format="first-letter" class="hw-search-input" value="<?= htmlspecialchars($search) ?>" placeholder="Request #, item, reason, user...">
                            </div>
                        </div>
                        <div class="hw-filter-actions">
                            <button type="submit" class="btn btn-primary hw-filter-icon-btn hw-btn-search" title="Search" aria-label="Search">
                                <i class="fas fa-search"></i>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Table -->
        <div class="card border-0 shadow-sm rounded-3">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead>
                        <tr>
                            <th>Request #</th>
                            <th>Item</th>
                            <th>From → To</th>
                            <th>Quantity</th>
                            <th>Donor Stock</th>
                            <th>Priority</th>
                            <th>Status</th>
                            <th>Requested By</th>
                            <th>Date</th>
                            <th class="text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($transfers)): ?>
                            <tr>
                                <td colspan="10" class="text-center py-4 text-muted">
                                    No transfer requests found
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($transfers as $t): ?>
                                <?php
                                $status = strtolower((string)($t['status'] ?? 'pending'));
                                $is_pending_or_approved = in_array($status, ['pending', 'approved'], true);
                                $effective_qty = (int)($t['approved_quantity'] > 0 ? $t['approved_quantity'] : $t['requested_quantity']);
                                if ($effective_qty <= 0) $effective_qty = 1;
                                $donor_stock = (int) ($t['donor_available_stock'] ?? 0);
                                $is_insufficient = $is_pending_or_approved && ($donor_stock < $effective_qty);
                                ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($t['request_number']) ?></strong></td>
                                    <td><?= htmlspecialchars(app_display_item_name($t['item_name'])) ?></td>
                                    <td>
                                        <small>
                                            <?= htmlspecialchars($t['donor_branch']) ?> →
                                            <?= htmlspecialchars($t['requesting_branch']) ?>
                                        </small>
                                    </td>
                                    <td>
                                        <?= (int)$t['requested_quantity'] ?>
                                        <?php if ($t['approved_quantity'] > 0 && $t['approved_quantity'] != $t['requested_quantity']): ?>
                                            <br><small class="text-muted">(✓ <?= (int)$t['approved_quantity'] ?>)</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($is_pending_or_approved): ?>
                                            <?php if ($is_insufficient): ?>
                                                <span class="badge bg-danger" title="Available stock (<?= $donor_stock ?>) is less than requested (<?= $effective_qty ?>)">
                                                    <?= $donor_stock ?> avail (Low)
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-success" title="Available stock (<?= $donor_stock ?>) is sufficient">
                                                    <?= $donor_stock ?> avail
                                                </span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted small">—</span>
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
                                            'pending' => 'bg-warning text-dark',
                                            'approved' => 'bg-info text-dark',
                                            'shipped' => 'bg-primary',
                                            'received' => 'bg-success',
                                            'cancelled' => 'bg-danger'
                                        ][$status] ?? 'bg-secondary';
                                        ?>
                                        <span class="badge <?= $status_class ?>">
                                            <?= ucfirst($status) ?>
                                        </span>
                                    </td>
                                    <td><small><?= htmlspecialchars($t['requested_by'] ?? 'N/A') ?></small></td>
                                    <td><small><?= date('M d, Y', strtotime($t['created_at'])) ?></small></td>
                                    <td class="text-center">
                                        <?php if ($is_insufficient): ?>
                                            <button type="button"
                                                    class="btn btn-sm btn-outline-danger js-admin-reject-btn"
                                                    data-transfer-id="<?= (int)$t['id'] ?>"
                                                    data-request-number="<?= htmlspecialchars($t['request_number'] ?? '') ?>"
                                                    data-item-name="<?= htmlspecialchars(app_display_item_name($t['item_name'] ?? '')) ?>"
                                                    data-donor-branch="<?= htmlspecialchars($t['donor_branch'] ?? '') ?>"
                                                    data-requesting-branch="<?= htmlspecialchars($t['requesting_branch'] ?? '') ?>"
                                                    data-requested-qty="<?= $effective_qty ?>"
                                                    data-available-qty="<?= $donor_stock ?>"
                                                    title="Reject transfer due to insufficient stock">
                                                <i class="fas fa-times-circle"></i> Reject (Insufficient Stock)
                                            </button>
                                        <?php elseif ($is_pending_or_approved): ?>
                                            <span class="text-success small fw-semibold"><i class="fas fa-check-circle"></i> Stock Ready</span>
                                        <?php elseif ($status === 'cancelled'): ?>
                                            <?php
                                            $notes_str = (string)($t['notes'] ?? '');
                                            if (strpos($notes_str, '[REJECTED_PRE_SHIPMENT]') !== false):
                                            ?>
                                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle" title="<?= htmlspecialchars($notes_str) ?>">Pre-Shipment Rejected</span>
                                            <?php elseif (strpos($notes_str, '[RETURN_PENDING]') !== false): ?>
                                                <span class="badge bg-warning-subtle text-warning border border-warning-subtle" title="<?= htmlspecialchars($notes_str) ?>">Return Pending</span>
                                            <?php elseif (strpos($notes_str, '[RETURNED]') !== false): ?>
                                                <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle" title="<?= htmlspecialchars($notes_str) ?>">Stock Returned</span>
                                            <?php else: ?>
                                                <span class="text-muted small">Cancelled</span>
                                            <?php endif; ?>
                                        <?php elseif ($status === 'shipped'): ?>
                                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle">In Transit</span>
                                        <?php elseif ($status === 'received'): ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle">Completed</span>
                                        <?php endif; ?>
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

    <!-- Admin Reject Transfer Modal -->
    <div class="modal fade" id="rejectTransferModal" tabindex="-1" aria-labelledby="rejectTransferModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="rejectTransferModalLabel"><i class="fas fa-exclamation-triangle me-2"></i>Reject Transfer — Insufficient Stock</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="rejectTransferForm">
                    <div class="modal-body">
                        <input type="hidden" name="transfer_id" id="modalTransferId">
                        <div class="mb-3">
                            <label class="form-label text-muted small mb-1">Transfer Request</label>
                            <div class="fw-bold fs-6" id="modalRequestNumber"></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-muted small mb-1">Item</label>
                            <div class="fw-bold" id="modalItemName"></div>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label text-muted small mb-1">Donor Branch</label>
                                <div class="fw-semibold" id="modalDonorBranch"></div>
                            </div>
                            <div class="col-6">
                                <label class="form-label text-muted small mb-1">Requesting Branch</label>
                                <div class="fw-semibold" id="modalRequestingBranch"></div>
                            </div>
                        </div>
                        <div class="alert alert-warning py-2 mb-3">
                            <div class="d-flex justify-content-between mb-1">
                                <span>Requested Quantity:</span>
                                <strong id="modalRequestedQty"></strong>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span>Donor Available Stock:</span>
                                <strong class="text-danger" id="modalAvailableQty"></strong>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="modalRejectionReason" class="form-label fw-semibold">Rejection Reason <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="modalRejectionReason" name="reason" rows="3" maxlength="1000" data-text-format="first-letter" required placeholder="Enter rejection reason...">Insufficient stock at donor branch</textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger" id="modalSubmitRejectBtn">
                            <i class="fas fa-times-circle me-1"></i> Confirm Rejection
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const modalEl = document.getElementById('rejectTransferModal');
        const rejectModal = modalEl ? new bootstrap.Modal(modalEl) : null;
        const rejectForm = document.getElementById('rejectTransferForm');
        const submitBtn = document.getElementById('modalSubmitRejectBtn');

        document.querySelectorAll('.js-admin-reject-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                document.getElementById('modalTransferId').value = this.dataset.transferId || '';
                document.getElementById('modalRequestNumber').textContent = this.dataset.requestNumber || '';
                document.getElementById('modalItemName').textContent = this.dataset.itemName || '';
                document.getElementById('modalDonorBranch').textContent = this.dataset.donorBranch || '';
                document.getElementById('modalRequestingBranch').textContent = this.dataset.requestingBranch || '';
                document.getElementById('modalRequestedQty').textContent = (this.dataset.requestedQty || '0') + ' unit(s)';
                document.getElementById('modalAvailableQty').textContent = (this.dataset.availableQty || '0') + ' unit(s)';
                document.getElementById('modalRejectionReason').value = 'Insufficient stock at donor branch (Available: ' + (this.dataset.availableQty || '0') + ', Requested: ' + (this.dataset.requestedQty || '0') + ')';

                if (rejectModal) {
                    rejectModal.show();
                }
            });
        });

        if (rejectForm) {
            rejectForm.addEventListener('submit', async function(e) {
                e.preventDefault();
                const transferId = document.getElementById('modalTransferId').value;
                const reason = document.getElementById('modalRejectionReason').value.trim();

                if (!transferId) {
                    alert('Invalid transfer selection.');
                    return;
                }

                if (!reason) {
                    alert('A rejection reason is required.');
                    return;
                }

                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Rejecting...';

                try {
                    const body = new URLSearchParams();
                    body.append('action', 'reject_before_shipment');
                    body.append('transfer_id', transferId);
                    body.append('reason', reason);

                    const response = await fetch('/hwtires/api/transfers-api.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: body.toString()
                    });

                    const result = await response.json();

                    if (!response.ok || !result.success) {
                        throw new Error(result.message || 'Unable to reject transfer request.');
                    }

                    if (rejectModal) {
                        rejectModal.hide();
                    }
                    window.location.reload();
                } catch (err) {
                    alert(err.message || 'An error occurred while rejecting the transfer.');
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="fas fa-times-circle me-1"></i> Confirm Rejection';
                }
            });
        }
    });
    </script>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
