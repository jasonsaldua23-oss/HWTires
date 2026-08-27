<?php
/**
 * Job Order Create Form
 */

require_once '../../includes/config.php';
session_name(SESSION_NAME);
session_start();

if (!is_logged_in()) {
    redirect('/hwtires/index.php');
}

$user = app_get_session_user();
$page_title = 'Create Job Order';
$quotation_id = intval($_GET['quotation_id'] ?? 0);

$quotation = null;
$quotation_items = [];
$customers = [];
$vehicles = [];
$branches = [];
$manual_mode = $quotation_id <= 0;
$default_notes = '';

if ($user['role'] === 'admin') {
    $branches = $pdo->query("SELECT id, name FROM branches WHERE status = 'active' ORDER BY name")->fetchAll();
}

$customers = $pdo->query("SELECT id, name FROM customers WHERE status = 'active' ORDER BY name")->fetchAll();
$vehicles_stmt = $pdo->query("SELECT v.id, v.customer_id, v.plate_number, v.make, v.model, v.year, c.name as customer_name
                             FROM vehicles v
                             LEFT JOIN customers c ON v.customer_id = c.id
                             WHERE v.status = 'active'
                             ORDER BY c.name, v.make, v.model, v.year");
$vehicles = $vehicles_stmt->fetchAll();

if (!$manual_mode) {
    try {
        $stmt = $pdo->prepare("SELECT q.*, c.name as customer_name, c.phone_mobile, c.address, c.email,
                                      v.make, v.model, v.year, v.plate_number,
                                      b.name as branch_name
                               FROM quotations q
                               LEFT JOIN customers c ON q.customer_id = c.id
                               LEFT JOIN vehicles v ON q.vehicle_id = v.id
                               LEFT JOIN branches b ON q.branch_id = b.id
                               WHERE q.id = ?");
        $stmt->execute([$quotation_id]);
        $quotation = $stmt->fetch();

        if (!$quotation) {
            set_flash_message('Quotation not found.', 'error');
            redirect('/hwtires/' . $user['role'] . '/quotations/');
        }

        if ($quotation['status'] !== 'approved') {
            set_flash_message('Only approved quotations can be converted to job orders.', 'warning');
            redirect('/hwtires/' . $user['role'] . '/quotations/view.php?id=' . $quotation_id);
        }

        if (!has_branch_access($quotation['branch_id'])) {
            set_flash_message('Unauthorized access to this quotation.', 'error');
            redirect('/hwtires/' . $user['role'] . '/quotations/');
        }

        $default_notes = $quotation['notes'] ?? '';

        $items_stmt = $pdo->prepare("SELECT * FROM quotation_items WHERE quotation_id = ? ORDER BY id");
        $items_stmt->execute([$quotation_id]);
        $quotation_items = $items_stmt->fetchAll();
    } catch (Exception $e) {
        error_log('Job order create error: ' . $e->getMessage());
        set_flash_message('Unable to load quotation details.', 'error');
        redirect('/hwtires/' . $user['role'] . '/quotations/');
    }
}
?>

<?php require_once '../../includes/header.php'; ?>
<?php require_once '../../includes/sidebar.php'; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-0"><i class="fas fa-hammer"></i> Create Job Order</h2>
        <small class="text-muted">
            <?php if ($manual_mode): ?>
                Create a manual job order
            <?php else: ?>
                Convert approved quotation <?php echo esc_html($quotation['quotation_number']); ?> into a job order
            <?php endif; ?>
        </small>
    </div>
    <?php if ($manual_mode): ?>
    <a href="/hwtires/<?php echo $user['role']; ?>/job-orders/" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to Job Orders
    </a>
    <?php else: ?>
    <a href="/hwtires/<?php echo $user['role']; ?>/quotations/view.php?id=<?php echo $quotation_id; ?>" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to Quotation
    </a>
    <?php endif; ?>
</div>

<?php
$flash_message = get_flash_message();
if ($flash_message):
?>
    <div class="alert alert-<?php echo esc_attr($flash_message['type']); ?> alert-dismissible fade show" role="alert">
        <?php echo esc_html($flash_message['message']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-5 mb-4">
        <div class="card h-100">
            <div class="card-header">
                <strong><?php echo $manual_mode ? 'Manual Job Summary' : 'Quotation Summary'; ?></strong>
            </div>
            <div class="card-body">
                <?php if ($manual_mode): ?>
                <div class="mb-3 text-muted">
                    Use this form to create a standalone job order without an approved quotation.
                </div>
                <div class="alert alert-light border mb-0">
                    <div class="small text-muted">Customer, vehicle, branch, date, and assigned technician are entered directly in the form.</div>
                </div>
                <?php else: ?>
                <div class="mb-3">
                    <div class="text-muted small">Customer</div>
                    <div class="fw-semibold"><?php echo esc_html($quotation['customer_name']); ?></div>
                    <div class="small text-muted"><?php echo esc_html($quotation['phone_mobile'] ?? ''); ?></div>
                </div>
                <div class="mb-3">
                    <div class="text-muted small">Vehicle</div>
                    <div class="fw-semibold">
                        <?php echo esc_html(trim(($quotation['make'] ?? '') . ' ' . ($quotation['model'] ?? '')) ?: 'No vehicle specified'); ?>
                    </div>
                    <div class="small text-muted">Plate: <?php echo esc_html($quotation['plate_number'] ?? 'N/A'); ?></div>
                </div>
                <div class="mb-3">
                    <div class="text-muted small">Branch</div>
                    <div class="fw-semibold"><?php echo esc_html($quotation['branch_name']); ?></div>
                </div>
                <div class="mb-3">
                    <div class="text-muted small">Quotation Total</div>
                    <div class="fw-semibold"><?php echo format_currency($quotation['total_amount']); ?></div>
                </div>

                <hr>

                <div class="mb-2 text-muted small fw-semibold">Items</div>
                <div class="table-responsive" style="max-height: 360px; overflow: auto;">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Description</th>
                                <th class="text-end">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($quotation_items)): ?>
                            <tr>
                                <td colspan="2" class="text-center text-muted py-3">No line items</td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($quotation_items as $item): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?php echo esc_html($item['item_name']); ?></div>
                                        <small class="text-muted"><?php echo esc_html(ucfirst($item['item_type'])); ?> x <?php echo intval($item['quantity']); ?></small>
                                    </td>
                                    <td class="text-end"><?php echo format_currency($item['quantity'] * $item['unit_price']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-7 mb-4">
        <div class="card">
            <div class="card-header">
                <strong>Job Order Details</strong>
            </div>
            <div class="card-body">
                <form method="POST" action="/hwtires/api/job-orders-api.php">
                    <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="create">
                    <input type="hidden" name="quotation_id" value="<?php echo $quotation_id; ?>">
                    <?php if ($manual_mode): ?>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label required">Customer</label>
                            <select class="form-select" name="customer_id" id="customer_id" required>
                                <option value="">Select customer...</option>
                                <?php foreach ($customers as $customer): ?>
                                <option value="<?php echo $customer['id']; ?>"><?php echo esc_html($customer['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Vehicle</label>
                            <select class="form-select" name="vehicle_id" id="vehicle_id">
                                <option value="">Select customer first...</option>
                            </select>
                        </div>
                        <?php if ($user['role'] === 'admin'): ?>
                        <div class="col-md-6">
                            <label class="form-label required">Branch</label>
                            <select class="form-select" name="branch_id" required>
                                <option value="">Select branch...</option>
                                <?php foreach ($branches as $branch): ?>
                                <option value="<?php echo $branch['id']; ?>"><?php echo esc_html($branch['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php else: ?>
                        <input type="hidden" name="branch_id" value="<?php echo intval($user['branch_id']); ?>">
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <input type="hidden" name="customer_id" value="<?php echo intval($quotation['customer_id']); ?>">
                    <input type="hidden" name="vehicle_id" value="<?php echo intval($quotation['vehicle_id'] ?? 0); ?>">
                    <input type="hidden" name="branch_id" value="<?php echo intval($quotation['branch_id']); ?>">
                    <?php endif; ?>
                    <input type="hidden" name="redirect" value="/hwtires/<?php echo $user['role']; ?>/job-orders/">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label required">Job Date</label>
                            <input type="date" class="form-control" name="job_date" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Assigned Technician</label>
                            <input type="text" class="form-control" name="assigned_technician_name" placeholder="Type technician name">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Start Time</label>
                            <input type="time" class="form-control" name="scheduled_start_time">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">End Time</label>
                            <input type="time" class="form-control" name="scheduled_end_time">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Notes</label>
                            <textarea class="form-control" name="notes" rows="5" placeholder="Job order notes..."><?php echo esc_html($quotation['notes'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-2 mt-4">
                        <?php if ($manual_mode): ?>
                        <a href="/hwtires/<?php echo $user['role']; ?>/job-orders/" class="btn btn-outline-secondary">Cancel</a>
                        <?php else: ?>
                        <a href="/hwtires/<?php echo $user['role']; ?>/quotations/view.php?id=<?php echo $quotation_id; ?>" class="btn btn-outline-secondary">Cancel</a>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Create Job Order
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>

<?php if ($manual_mode): ?>
<script>
const allVehicles = <?php echo json_encode($vehicles, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

function renderVehicleOptions(customerId) {
    const vehicleSelect = document.getElementById('vehicle_id');
    vehicleSelect.innerHTML = '<option value="">Select vehicle...</option>';

    allVehicles.filter(vehicle => String(vehicle.customer_id) === String(customerId)).forEach(vehicle => {
        const option = document.createElement('option');
        option.value = vehicle.id;
        option.textContent = `${vehicle.plate_number || 'No plate'} - ${vehicle.make || ''} ${vehicle.model || ''} ${vehicle.year || ''}`.trim();
        vehicleSelect.appendChild(option);
    });
}

document.getElementById('customer_id').addEventListener('change', function() {
    renderVehicleOptions(this.value);
});
</script>
<?php endif; ?>
