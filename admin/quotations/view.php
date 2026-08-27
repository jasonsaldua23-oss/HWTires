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
               u.name as created_by_name, b.name as branch_name
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

} catch (Exception $e) {
    error_log('Quotation view error: ' . $e->getMessage());
    redirect("../");
}

$quote_items_total = 0;
foreach ($items as $item) {
    if (app_line_item_is_service($item)) {
        continue;
    }

    $quantity = max(1, (int) ($item['quantity'] ?? 1));
    $unit_price = (float) ($item['unit_price'] ?? 0);
    $quote_items_total += (float) (($item['subtotal'] ?? 0) ?: ($quantity * $unit_price));
}
$quote_subtotal = (float) ($quotation['labor_cost'] ?? 0) + $quote_items_total;
$quote_total_amount = $quote_subtotal;
$issued_inventory_total = app_inventory_transaction_rows_total($issued_inventory_items ?? []);
$display_total_amount = $quote_total_amount + $issued_inventory_total;

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
        <a href="/hwtires/admin/quotations/" class="btn btn-secondary">
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
                        License: <?php echo esc_html($quotation['plate_number'] ?? 'N/A'); ?><br>
                        VIN: <?php echo esc_html($quotation['vin'] ?? 'N/A'); ?>
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
                        <td><?php echo app_line_item_description_html($item, $inventory_items_by_id); ?></td>
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

        <?php echo app_inventory_transaction_table_html($issued_inventory_items ?? [], [
            'title' => 'Products / Inventory Issued',
            'subtitle' => 'Stock-out products linked to this service operation.',
        ]); ?>

        <!-- Totals Section -->
        <div class="row mt-4">
            <div class="col-md-6 offset-md-6">
                <table class="table table-sm">
                    <tbody>
                        <tr>
                            <td class="text-end"><strong>Service Operation Total:</strong></td>
                            <td class="text-end" style="width: 120px;">&#8369;<?php echo number_format($quote_total_amount, 2); ?></td>
                        </tr>
                        <?php if ($issued_inventory_total > 0): ?>
                        <tr>
                            <td class="text-end"><strong>Inventory Issued:</strong></td>
                            <td class="text-end">&#8369;<?php echo number_format($issued_inventory_total, 2); ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr class="table-light">
                            <td class="text-end"><h6 class="mb-0"><strong><?php echo $issued_inventory_total > 0 ? 'GRAND TOTAL:' : 'TOTAL AMOUNT:'; ?></strong></h6></td>
                            <td class="text-end"><h6 class="mb-0"><strong>&#8369;<?php echo number_format($issued_inventory_total > 0 ? $display_total_amount : $quote_total_amount, 2); ?></strong></h6></td>
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

<div class="alert alert-info no-print" role="alert">
    <i class="fas fa-info-circle"></i>
    Admin service operation records are view-only. Branch front desk users handle approvals and job order creation.
</div>

<?php if (!$is_print): ?>
<?php require_once '../../includes/footer.php'; ?>
<?php endif; ?>

<?php if (($user['role'] ?? '') === 'admin'): ?>
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
                                <?php if (($item['item_type'] ?? '') === 'service'): ?>
                                    <label class="list-group-item">
                                        <input type="checkbox" name="keep_item_ids[]" value="<?php echo (int) $item['id']; ?>" checked>
                                        <?php echo esc_html($item['item_name']); ?>
                                    </label>
                                <?php endif; ?>
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
<?php endif; ?>
