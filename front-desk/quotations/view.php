<?php
/**
 * Quotation Detail & Print View
 */

require_once '../../includes/config.php';
session_name(SESSION_NAME);
session_start();

// Check authentication
if (!is_logged_in()) {
    redirect('/hwtires/index.php');
}

$user = app_get_session_user();
$quotation_id = intval($_GET['id'] ?? 0);
$page_title = 'Service Operation Details';

require_once '../../includes/line-item-display.php';

if ($quotation_id <= 0) {
    redirect("../");
}

try {
    // Get quotation details
    $stmt = $pdo->prepare("
        SELECT q.*, c.name as customer_name, c.email as customer_email, c.phone_mobile, c.contact, c.address,
               v.make, v.model, v.year, v.vin, v.plate_number,
               u.name as created_by_name, b.name AS branch_name
        FROM quotations q
        LEFT JOIN customers c ON q.customer_id = c.id
        LEFT JOIN vehicles v ON q.vehicle_id = v.id
        LEFT JOIN users u ON q.created_by = u.id
        LEFT JOIN branches b ON q.branch_id = b.id
        WHERE q.id = ?
    ");
    $stmt->execute([$quotation_id]);
    $quotation = $stmt->fetch();

    if (!$quotation) {
        redirect("../");
    }

    // Get quotation items
    $items_stmt = $pdo->prepare("SELECT * FROM quotation_items WHERE quotation_id = ? ORDER BY id");
    $items_stmt->execute([$quotation_id]);
    $items = $items_stmt->fetchAll();
    $inventory_items_by_id = app_line_item_load_inventory_items($pdo, $items);
    $issued_inventory_items = app_line_item_load_linked_inventory_transactions($pdo, [
        'quotation_id' => $quotation_id,
    ]);

    // Load inter-branch transfer requests for this quotation
    $quotation_transfers = [];
    $item_transfers = [];
    $eligible_donors_by_item = [];
    $requesting_branch_id = (int) ($quotation['branch_id'] ?? 0);

    if (function_exists('app_table_exists') && app_table_exists('inter_branch_transfer_requests')) {
        $transfers_stmt = $pdo->prepare("
            SELECT tr.*, db.name AS donor_branch_name
            FROM inter_branch_transfer_requests tr
            LEFT JOIN branches db ON db.id = tr.donor_branch_id
            WHERE tr.quotation_id = ?
            ORDER BY FIELD(tr.status, 'received', 'shipped', 'approved', 'pending', 'cancelled') ASC, tr.id DESC
        ");
        $transfers_stmt->execute([$quotation_id]);
        $quotation_transfers = $transfers_stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($items as $item) {
            $item_id = (int) $item['id'];
            $is_other_branch = ($item['source'] ?? '') === 'other_branch';
            $is_service = strtolower((string) ($item['item_type'] ?? '')) === 'service';

            if (!$is_other_branch || $is_service) {
                continue;
            }

            $meta = [];
            $notes_str = trim((string) ($item['notes'] ?? ''));
            if ($notes_str !== '' && $notes_str[0] === '{') {
                $meta = json_decode($notes_str, true) ?: [];
            }
            $meta_item_id = (int) ($meta['inventory_item_id'] ?? 0);
            $meta_branch_id = (int) ($meta['inventory_branch_id'] ?? 0);

            $matched_tr = null;
            foreach ($quotation_transfers as $tr) {
                if (($meta_item_id > 0 && (int) $tr['item_id'] === $meta_item_id && (int) $tr['donor_branch_id'] === $meta_branch_id)
                    || trim($tr['item_name']) === trim($item['item_name'])) {
                    $matched_tr = $tr;
                    break;
                }
            }

            if ($matched_tr) {
                $item_transfers[$item_id] = $matched_tr;

                if ($matched_tr['status'] === 'cancelled') {
                    $donor_stmt = $pdo->prepare("
                        SELECT i.id AS inventory_item_id, i.branch_id, i.quantity, b.name AS branch_name
                        FROM inventory_items i
                        INNER JOIN branches b ON b.id = i.branch_id
                        WHERE i.item_name = ?
                          AND i.branch_id != ?
                          AND i.status = 'active'
                          AND b.status = 'active'
                          AND b.has_inventory = 1
                          AND i.quantity >= ?
                        ORDER BY b.name ASC
                    ");
                    $donor_stmt->execute([
                        $item['item_name'],
                        $requesting_branch_id,
                        max(1, (int) ($item['quantity'] ?? 1))
                    ]);
                    $eligible_donors_by_item[$item_id] = $donor_stmt->fetchAll(PDO::FETCH_ASSOC);
                }
            }
        }
    }

} catch (Exception $e) {
    error_log('Quotation view error: ' . $e->getMessage());
    redirect("../");
}

$labor_cost = (float) ($quotation['labor_cost'] ?? 0);
$quote_totals = app_quotation_calculate_totals($items, $issued_inventory_items ?? [], $labor_cost);
$quote_items_total = $quote_totals['parts_total'];
$quote_total_amount = $quote_totals['grand_total'];
$issued_inventory_total = app_inventory_transaction_rows_total($issued_inventory_items ?? []);

$can_manage_quotation = ($user['role'] ?? '') === 'front-desk'
    && (int) ($quotation['branch_id'] ?? 0) === (int) ($user['branch_id'] ?? 0);
$is_cross_branch_quotation = (int) ($quotation['branch_id'] ?? 0) !== (int) ($user['branch_id'] ?? 0);
$quotation_branch_label = app_branch_label($quotation['branch_name'] ?? '', 'Branch');

// Check if print mode
$is_print = isset($_GET['print']);

?>

<?php if (!$is_print): ?>
<?php require_once '../../includes/header.php'; ?>
<?php require_once '../../includes/sidebar.php'; ?>
<?php endif; ?>

<style>
    @media print {
        .no-print { display: none !important; }
        body { background: white; }
        .card { border: none; box-shadow: none; }
    }
</style>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4 no-print">
    <div>
        <h2 class="mb-0"><i class="fas fa-receipt"></i> Service Operation <?php echo esc_html($quotation['quotation_number']); ?></h2>
        <small class="text-muted">Created on <?php echo date('M d, Y', strtotime($quotation['quotation_date'])); ?></small>
    </div>
    <div class="gap-2">
        <a class="btn btn-primary" href="/hwtires/api/quotation-print.php?id=<?php echo (int) $quotation['id']; ?>" target="_blank" rel="noopener">
            <i class="fas fa-print"></i> Print
        </a>
        <a href="/hwtires/front-desk/quotations/" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back
        </a>
    </div>
</div>

<!-- Flash Messages -->
<?php
$flash_message = get_flash_message();
if ($flash_message && !$is_print):
?>
    <div class="alert alert-<?php echo esc_attr($flash_message['type']); ?> alert-dismissible fade show" role="alert">
        <?php echo esc_html($flash_message['message']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (!$is_print && $is_cross_branch_quotation): ?>
    <div class="alert alert-info no-print" role="alert">
        <i class="fas fa-info-circle"></i>
        You are viewing <?php echo esc_html($quotation_branch_label); ?> records. Updates and approvals remain with the owning branch.
    </div>
<?php endif; ?>

<!-- Service Operation Content -->
<div class="card mb-4 service-operation-detail-card">
    <div class="card-body">
        <!-- Header Info -->
        <div class="row mb-4">
            <div class="col-md-6">
                <h6 class="text-muted mb-2">CUSTOMER</h6>
                <p class="mb-1"><strong><?php echo esc_html($quotation['customer_name']); ?></strong></p>
                <p class="text-muted mb-0">
                    <?php if ($quotation['phone_mobile']): ?>
                        <i class="fas fa-phone"></i> <?php echo esc_html($quotation['phone_mobile']); ?><br>
                    <?php endif; ?>
                    <?php if ($quotation['customer_email']): ?>
                        <i class="fas fa-envelope"></i> <?php echo esc_html($quotation['customer_email']); ?><br>
                    <?php endif; ?>
                    <?php if ($quotation['address']): ?>
                        <i class="fas fa-map-marker-alt"></i> <?php echo esc_html($quotation['address']); ?>
                    <?php endif; ?>
                </p>
            </div>
            <div class="col-md-6">
                <h6 class="text-muted mb-2">VEHICLE</h6>
                <?php if ($quotation['vehicle_id']): ?>
                    <p class="mb-1"><strong><?php echo esc_html($quotation['make'] . ' ' . $quotation['model']); ?></strong></p>
                    <p class="text-muted mb-0 service-operation-detail-lines">
                        Year: <?php echo esc_html($quotation['year'] ?? 'N/A'); ?><br>
                        License: <?php echo esc_html($quotation['plate_number'] ?? 'N/A'); ?>
                    </p>
                <?php else: ?>
                    <p class="text-muted">No vehicle specified</p>
                <?php endif; ?>
            </div>
        </div>

        <hr>

        <!-- Service Operation Details -->
        <div class="row mb-4">
            <div class="col-md-6">
                <p class="mb-1"><small class="text-muted">Service Operation #</small><br><strong><?php echo esc_html($quotation['quotation_number']); ?></strong></p>
            </div>
            <div class="col-md-3">
                <p class="mb-1"><small class="text-muted">Status</small><br>
                    <span class="badge <?php echo ($quotation['status'] === 'approved' ? 'success' : ($quotation['status'] === 'pending' ? 'warning' : 'danger')); ?>">
                        <?php echo ucfirst($quotation['status']); ?>
                    </span>
                </p>
            </div>
            <div class="col-md-3">
                <p class="mb-1"><small class="text-muted">Branch</small><br><strong><?php echo esc_html($quotation['branch_name']); ?></strong></p>
            </div>
        </div>

        <?php
        $has_inspection = trim((string) ($quotation['inspection_complaint'] ?? '')) !== ''
            || trim((string) ($quotation['inspection_findings'] ?? '')) !== ''
            || trim((string) ($quotation['inspection_recommendations'] ?? '')) !== ''
            || !empty($quotation['inspection_mileage']);
        ?>
        <?php if ($has_inspection): ?>
        <div class="mt-4 pt-4 border-top">
            <h6 class="text-muted mb-3">SERVICE INSPECTION</h6>
            <div class="row g-3">
                <?php if (!empty($quotation['inspection_mileage'])): ?>
                <div class="col-md-6">
                    <small class="text-muted">Current Mileage</small>
                    <p class="mb-0"><strong><?php echo number_format((int) $quotation['inspection_mileage']); ?> km</strong></p>
                </div>
                <?php endif; ?>
                <?php if (trim((string) ($quotation['inspection_complaint'] ?? '')) !== ''): ?>
                <div class="col-md-6">
                    <small class="text-muted">Customer Concern</small>
                    <p class="mb-0"><?php echo nl2br(esc_html($quotation['inspection_complaint'])); ?></p>
                </div>
                <?php endif; ?>
                <?php if (trim((string) ($quotation['inspection_findings'] ?? '')) !== ''): ?>
                <div class="col-md-6">
                    <small class="text-muted">Inspection Findings</small>
                    <p class="mb-0"><?php echo nl2br(esc_html($quotation['inspection_findings'])); ?></p>
                </div>
                <?php endif; ?>
                <?php if (trim((string) ($quotation['inspection_recommendations'] ?? '')) !== ''): ?>
                <div class="col-md-6">
                    <small class="text-muted">Recommended Action</small>
                    <p class="mb-0"><?php echo nl2br(esc_html($quotation['inspection_recommendations'])); ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Items Table -->
        <table class="table table-bordered mt-4 service-operation-items-table">
            <thead class="table-light">
                <tr>
                    <th style="width: 40%;">Description</th>
                    <th style="width: 15%;">Type</th>
                    <th style="width: 10%; text-align: right;">Quantity</th>
                    <th style="width: 15%; text-align: right;">Unit Price</th>
                    <th style="width: 20%; text-align: right;">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)): ?>
                <tr>
                    <td colspan="5" class="text-center text-muted py-3">No items</td>
                </tr>
                <?php else: ?>
                    <?php foreach ($items as $item): ?>
                    <?php
                    $is_service_line = app_line_item_is_service($item);
                    $item_type_key = strtolower(trim((string) ($item['item_type'] ?? 'service')));
                    $item_type_key = preg_replace('/[^a-z0-9_-]/', '-', $item_type_key !== '' ? $item_type_key : 'service');
                    ?>
                    <tr>
                        <td>
                            <?php echo app_line_item_description_html($item, $inventory_items_by_id); ?>
                            <?php if (!$is_print && isset($item_transfers[(int) $item['id']])): ?>
                                <?php
                                $tr = $item_transfers[(int) $item['id']];
                                $tr_status = strtolower((string) ($tr['status'] ?? ''));
                                $donor_name = trim((string) ($tr['donor_branch_name'] ?? ''));
                                ?>
                                <?php if ($tr_status === 'cancelled'): ?>
                                    <div class="mt-2">
                                        <span class="badge bg-danger">
                                            <i class="fas fa-times-circle"></i> Transfer Cancelled (<?php echo esc_html($donor_name ?: 'Donor'); ?>)
                                        </span>
                                        <?php if ($can_manage_quotation && in_array($quotation['status'], ['pending', 'approved'], true)): ?>
                                            <button type="button" class="btn btn-sm btn-outline-primary ms-1" data-bs-toggle="modal" data-bs-target="#resourceModal_<?php echo (int) $item['id']; ?>">
                                                <i class="fas fa-exchange-alt"></i> Re-source Item
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                <?php elseif (in_array($tr_status, ['pending', 'approved', 'shipped', 'received'], true)): ?>
                                    <div class="mt-2">
                                        <span class="badge bg-<?php echo in_array($tr_status, ['received', 'shipped', 'approved'], true) ? 'success' : 'info'; ?>">
                                            <i class="fas fa-truck"></i> Transfer: <?php echo esc_html(ucfirst($tr_status)); ?><?php if ($donor_name): ?> (<?php echo esc_html($donor_name); ?>)<?php endif; ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td><span class="record-line-type type-<?php echo esc_attr($item_type_key); ?>"><?php echo esc_html(app_line_item_type_label($item)); ?></span></td>
                        <td style="text-align: right;"><?php echo $item['quantity']; ?></td>
                        <td style="text-align: right;">&#8369;<?php echo number_format($item['unit_price'], 2); ?></td>
                        <td style="text-align: right;"><?php echo $is_service_line ? 'Included in labor' : '&#8369;' . number_format($item['quantity'] * $item['unit_price'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                <!-- Labor Cost Row -->
                <?php if ($quotation['labor_cost'] > 0): ?>
                <tr>
                    <td><strong>Labor Cost</strong></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td style="text-align: right;">&#8369;<?php echo number_format($quotation['labor_cost'], 2); ?></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Totals Section -->
        <div class="row mt-4">
            <div class="col-md-6 offset-md-6">
                <table class="table table-sm">
                    <tbody>
                        <tr>
                            <td class="text-end"><strong>Parts &amp; Items Subtotal:</strong></td>
                            <td class="text-end" style="width: 140px;">&#8369;<?php echo number_format($quote_items_total, 2); ?></td>
                        </tr>
                        <?php if ((float) ($quotation['labor_cost'] ?? 0) > 0): ?>
                        <tr>
                            <td class="text-end"><strong>Labor Cost:</strong></td>
                            <td class="text-end">&#8369;<?php echo number_format((float) $quotation['labor_cost'], 2); ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr class="table-light">
                            <td class="text-end"><h5 class="mb-0"><strong>TOTAL AMOUNT:</strong></h5></td>
                            <td class="text-end"><h5 class="mb-0 text-primary"><strong>&#8369;<?php echo number_format($quote_total_amount, 2); ?></strong></h5></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Notes -->
        <?php if (!empty($quotation['notes'])): ?>
        <div class="mt-4 pt-4 border-top">
            <h6 class="text-muted mb-2">NOTES</h6>
            <p><?php echo nl2br(esc_html(app_format_record_notes($quotation['notes']))); ?></p>
        </div>
        <?php endif; ?>

        <!-- Footer Info -->
        <div class="mt-4 pt-4 border-top text-muted" style="font-size: 0.9rem;">
            <p class="mb-1">Created by: <strong><?php echo esc_html($quotation['created_by_name']); ?></strong></p>
            <p class="mb-0">Date: <strong><?php echo date('M d, Y H:i A', strtotime($quotation['created_at'])); ?></strong></p>
            <?php if (!empty($quotation['updated_at']) && strtotime($quotation['updated_at']) > strtotime($quotation['created_at'])): ?>
                <p class="mb-0">Last edited: <strong><?php echo date('M d, Y H:i A', strtotime($quotation['updated_at'])); ?></strong></p>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Action Buttons -->
<?php if ($can_manage_quotation && in_array($quotation['status'], ['pending', 'approved'], true)): ?>
<div class="card no-print">
    <div class="card-body">
        <div class="gap-2">
            <button class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#editServicesModal">
                <i class="fas fa-edit"></i> Edit Services
            </button>
            <?php if ($quotation['status'] === 'pending'): ?>
            <form method="POST" action="/hwtires/api/quotations-api.php" style="display: inline;">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="id" value="<?php echo $quotation['id']; ?>">
                <input type="hidden" name="status" value="approved">
                <input type="hidden" name="redirect" value="<?php echo esc_attr($_SERVER['REQUEST_URI'] ?? '/hwtires/front-desk/quotations/'); ?>">
                <button type="submit" class="btn btn-success">
                    <i class="fas fa-check"></i> Approve Service Operation
                </button>
            </form>

            <form method="POST" action="/hwtires/api/quotations-api.php" style="display: inline;">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="id" value="<?php echo $quotation['id']; ?>">
                <input type="hidden" name="status" value="rejected">
                <input type="hidden" name="redirect" value="<?php echo esc_attr($_SERVER['REQUEST_URI'] ?? '/hwtires/front-desk/quotations/'); ?>">
                <button type="submit" class="btn btn-danger" onclick="return confirm('Are you sure?')">
                    <i class="fas fa-times"></i> Reject Service Operation
                </button>
            </form>
            <?php endif; ?>

            <?php if ($quotation['status'] === 'approved'): ?>
            <a href="/hwtires/<?php echo $user['role']; ?>/job-orders/create.php?quotation_id=<?php echo $quotation['id']; ?>" class="btn btn-primary">
                <i class="fas fa-hammer"></i> Create Job Order
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!$is_print): ?>
<?php require_once '../../includes/footer.php'; ?>
<?php endif; ?>

<?php if ($can_manage_quotation && in_array($quotation['status'], ['pending', 'approved'], true)): ?>
<!-- Edit Services Modal -->
<div class="modal fade" id="editServicesModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <form method="POST" action="/hwtires/admin/quotations/edit.php">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <input type="hidden" name="quotation_id" value="<?php echo (int) $quotation['id']; ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Services for <?php echo esc_html($quotation['quotation_number']); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Select the service lines to keep for this service operation. Unchecked service lines will be removed.</p>
                    <?php if (empty($items)): ?>
                        <p class="text-muted">No items to edit.</p>
                    <?php else: ?>
                        <div class="list-group">
                            <?php foreach ($items as $item): ?>
                                <?php
                                    $iid = (int) $item['id'];
                                    $itype = ($item['item_type'] ?? 'item');
                                ?>
                                <div class="list-group-item">
                                    <div class="row align-items-center">
                                        <div class="col-5">
                                            <label class="form-check-label">
                                                <input type="checkbox" class="form-check-input me-2" name="keep_item_ids[]" value="<?php echo $iid; ?>" checked>
                                                <strong><?php echo esc_html(app_display_item_name($item['item_name'], $itype)); ?></strong>
                                                <small class="text-muted"> &mdash; <?php echo esc_html(ucfirst($itype)); ?></small>
                                            </label>
                                        </div>
                                        <div class="col-2">
                                            <label class="form-label small mb-1">Qty</label>
                                            <input type="number" min="1" class="form-control form-control-sm" name="quantity[<?php echo $iid; ?>]" value="<?php echo (int) $item['quantity']; ?>">
                                        </div>
                                        <div class="col-3">
                                            <label class="form-label small mb-1">Notes</label>
                                            <input type="text" class="form-control form-control-sm" name="notes[<?php echo $iid; ?>]" value="<?php echo esc_attr($item['notes'] ?? ''); ?>" maxlength="255" data-text-format="first-letter" placeholder="optional">
                                        </div>
                                        <div class="col-2 text-end">
                                            <small class="text-muted">Unit: <?php echo '&#8369;' . number_format((float) ($item['unit_price'] ?? 0), 2); ?></small>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

    <?php foreach ($items as $item): ?>
        <?php
        $iid = (int) $item['id'];
        if (!isset($item_transfers[$iid]) || ($item_transfers[$iid]['status'] ?? '') !== 'cancelled') {
            continue;
        }
        $donors = $eligible_donors_by_item[$iid] ?? [];
        $cancelled_tr = $item_transfers[$iid];
        $cancelled_donor = trim((string) ($cancelled_tr['donor_branch_name'] ?? 'Donor branch'));
        ?>
        <!-- Re-source Modal for Item #<?php echo $iid; ?> -->
        <div class="modal fade" id="resourceModal_<?php echo $iid; ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form method="POST" action="/hwtires/api/quotations-api.php" class="resource-transfer-form">
                        <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                        <input type="hidden" name="action" value="resource_transfer_item">
                        <input type="hidden" name="quotation_id" value="<?php echo (int) $quotation['id']; ?>">
                        <input type="hidden" name="quotation_item_id" value="<?php echo $iid; ?>">
                        <input type="hidden" name="redirect" value="<?php echo esc_attr($_SERVER['REQUEST_URI'] ?? ''); ?>">
                        <input type="hidden" name="new_donor_branch_id" id="donor_branch_id_<?php echo $iid; ?>" value="">
                        <input type="hidden" name="new_donor_item_id" id="donor_item_id_<?php echo $iid; ?>" value="">

                        <div class="modal-header">
                            <h5 class="modal-title">
                                <i class="fas fa-exchange-alt text-primary me-2"></i>Re-source Item
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label text-muted small mb-1">Item to Re-source</label>
                                <p class="fw-bold mb-1"><?php echo esc_html($item['item_name']); ?></p>
                                <small class="text-muted">Quantity Required: <strong><?php echo (int) $item['quantity']; ?></strong></small>
                            </div>
                            <div class="alert alert-warning py-2 mb-3">
                                <small>
                                    <i class="fas fa-info-circle me-1"></i>
                                    The previous transfer request (<strong><?php echo esc_html($cancelled_tr['request_number']); ?></strong>) from <strong><?php echo esc_html($cancelled_donor); ?></strong> was cancelled.
                                </small>
                            </div>

                            <?php if (empty($donors)): ?>
                                <div class="alert alert-danger py-2 mb-0">
                                    <i class="fas fa-exclamation-triangle me-1"></i>
                                    No other branch currently has sufficient stock (&ge; <?php echo (int) $item['quantity']; ?>) for this item.
                                </div>
                            <?php else: ?>
                                <div class="mb-3">
                                    <label for="donor_select_<?php echo $iid; ?>" class="form-label fw-bold">Select Alternative Donor Branch <span class="text-danger">*</span></label>
                                    <select class="form-select resource-donor-select" id="donor_select_<?php echo $iid; ?>" data-line-id="<?php echo $iid; ?>" required>
                                        <option value="">-- Select Donor Branch --</option>
                                        <?php foreach ($donors as $donor): ?>
                                            <option value="<?php echo (int) $donor['branch_id']; ?>" data-inventory-item-id="<?php echo (int) $donor['inventory_item_id']; ?>">
                                                <?php echo esc_html($donor['branch_name']); ?> (<?php echo (int) $donor['quantity']; ?> in stock)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text">
                                        Selecting an alternative donor branch will submit a new transfer request while preserving the cancelled request in the audit history.
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <?php if (!empty($donors)): ?>
                                <button type="submit" class="btn btn-primary" id="btn_submit_resource_<?php echo $iid; ?>">
                                    <i class="fas fa-paper-plane me-1"></i> Submit Re-source Request
                                </button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <script>
    (function() {
        function initResourceSelectors() {
            document.querySelectorAll('.resource-donor-select').forEach(function(selectEl) {
                selectEl.addEventListener('change', function() {
                    var lineId = this.dataset.lineId;
                    var selectedOption = this.options[this.selectedIndex];
                    var branchIdInput = document.getElementById('donor_branch_id_' + lineId);
                    var itemIdInput = document.getElementById('donor_item_id_' + lineId);

                    if (branchIdInput && itemIdInput) {
                        branchIdInput.value = selectedOption ? (selectedOption.value || '') : '';
                        itemIdInput.value = selectedOption ? (selectedOption.dataset.inventoryItemId || '') : '';
                    }
                });
            });

            document.querySelectorAll('.resource-transfer-form').forEach(function(form) {
                form.addEventListener('submit', function(e) {
                    var lineIdInput = this.querySelector('input[name="quotation_item_id"]');
                    var lineId = lineIdInput ? lineIdInput.value : '';
                    var branchIdInput = document.getElementById('donor_branch_id_' + lineId);
                    var itemIdInput = document.getElementById('donor_item_id_' + lineId);

                    if (!branchIdInput || !branchIdInput.value || !itemIdInput || !itemIdInput.value) {
                        e.preventDefault();
                        alert('Please select an alternative donor branch.');
                    }
                });
            });
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initResourceSelectors);
        } else {
            initResourceSelectors();
        }
    })();
    </script>
<?php endif; ?>
