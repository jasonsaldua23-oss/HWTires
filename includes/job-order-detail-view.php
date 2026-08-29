<?php
/**
 * Shared Job Order detail view for admin and front-desk users.
 */

$user = app_get_session_user();
if (!is_array($user)) {
    redirect('/hwtires/index.php');
}

require_once __DIR__ . '/line-item-display.php';

$job_order_id = intval($_GET['id'] ?? 0);
$page_title = 'Job Order Details';
$job_detail_context = $job_detail_context ?? (($user['role'] ?? '') === 'admin' ? 'admin' : 'front-desk');
$job_detail_role = ($user['role'] ?? '') === 'admin' ? 'admin' : 'front-desk';

if ($job_order_id <= 0) {
    redirect('../');
}

if (!function_exists('job_detail_status_label')) {
    function job_detail_status_label($status) {
        $status = trim((string) $status);
        return $status === '' ? '-' : ucwords(str_replace(['-', '_'], ' ', $status));
    }
}

if (!function_exists('job_detail_status_class')) {
    function job_detail_status_class($status) {
        $status = strtolower(trim((string) $status));

        if (in_array($status, ['completed', 'approved', 'active'], true)) {
            return 'success';
        }

        if (in_array($status, ['waiting', 'pending'], true)) {
            return 'warning';
        }

        if ($status === 'in-progress') {
            return 'info';
        }

        if (in_array($status, ['cancelled', 'rejected', 'archived'], true)) {
            return 'danger';
        }

        return 'neutral';
    }
}

if (!function_exists('job_detail_date')) {
    function job_detail_date($date, $format = 'M d, Y') {
        return !empty($date) ? date($format, strtotime($date)) : '-';
    }
}

if (!function_exists('job_detail_time')) {
    function job_detail_time($time) {
        return !empty($time) ? date('H:i', strtotime($time)) : '-';
    }
}

if (!function_exists('job_detail_money')) {
    function job_detail_money($amount) {
        return format_currency((float) $amount);
    }
}

if (!function_exists('job_detail_branch_label')) {
    function job_detail_branch_label($branch_name) {
        return app_branch_label($branch_name, 'Branch');
    }
}

if (!function_exists('job_detail_vehicle_name')) {
    function job_detail_vehicle_name($job_order) {
        $vehicle = trim((string) (($job_order['vehicle_make'] ?? '') . ' ' . ($job_order['vehicle_model'] ?? '')));
        return $vehicle !== '' ? $vehicle : 'Vehicle';
    }
}

try {
    $stmt = $pdo->prepare("
        SELECT jo.*,
               c.name AS customer_name,
               c.phone_mobile AS customer_phone,
               c.contact AS customer_contact,
               v.make AS vehicle_make,
               v.model AS vehicle_model,
               v.year AS vehicle_year,
               v.plate_number,
               v.vin,
               q.quotation_number,
               q.total_amount AS quotation_total,
               q.inspection_complaint,
               q.inspection_findings,
               q.inspection_recommendations,
               q.inspection_mileage,
               u.name AS created_by_name,
               b.name AS branch_name
        FROM job_orders jo
        LEFT JOIN customers c ON jo.customer_id = c.id
        LEFT JOIN vehicles v ON jo.vehicle_id = v.id
        LEFT JOIN quotations q ON jo.quotation_id = q.id
        LEFT JOIN users u ON jo.created_by = u.id
        LEFT JOIN branches b ON jo.branch_id = b.id
        WHERE jo.id = ?
          AND jo.status <> 'cancelled'
    ");
    $stmt->execute([$job_order_id]);
    $job_order = $stmt->fetch();

    if (!$job_order) {
        set_flash_message('Job order not found', 'error');
        redirect('../');
    }

    if ($job_detail_context === 'admin' && !has_branch_access($job_order['branch_id'])) {
        set_flash_message('Unauthorized access', 'error');
        redirect('../');
    }

    $quotation_items = [];
    $inventory_items_by_id = [];
    $issued_inventory_items = [];
    if (!empty($job_order['quotation_id'])) {
        $items_stmt = $pdo->prepare("SELECT * FROM quotation_items WHERE quotation_id = ? ORDER BY id");
        $items_stmt->execute([$job_order['quotation_id']]);
        $quotation_items = $items_stmt->fetchAll();
        $inventory_items_by_id = app_line_item_load_inventory_items($pdo, $quotation_items);
    }
    $issued_inventory_items = app_line_item_load_linked_inventory_transactions($pdo, [
        'job_order_id' => $job_order_id,
        'quotation_id' => (int) ($job_order['quotation_id'] ?? 0),
    ]);
} catch (Exception $e) {
    error_log('Job order view error: ' . $e->getMessage());
    set_flash_message('Error loading job order', 'error');
    redirect('../');
}

$status_class = job_detail_status_class($job_order['status'] ?? '');
$job_branch_label = job_detail_branch_label($job_order['branch_name'] ?? '');
$customer_phone = trim((string) (($job_order['customer_phone'] ?? '') ?: ($job_order['customer_contact'] ?? '')));
$customer_phone = $customer_phone !== '' ? $customer_phone : '-';
$vehicle_name = job_detail_vehicle_name($job_order);
$is_cross_branch_job = $job_detail_role === 'front-desk'
    && (int) ($job_order['branch_id'] ?? 0) !== (int) ($user['branch_id'] ?? 0);
$has_inspection = trim((string) ($job_order['inspection_complaint'] ?? '')) !== ''
    || trim((string) ($job_order['inspection_findings'] ?? '')) !== ''
    || trim((string) ($job_order['inspection_recommendations'] ?? '')) !== ''
    || !empty($job_order['inspection_mileage']);
$notes = trim((string) ($job_order['notes'] ?? ''));
?>

<?php require_once __DIR__ . '/header.php'; ?>
<?php require_once __DIR__ . '/sidebar.php'; ?>

<div class="job-detail-page">
    <section class="job-detail-hero">
        <div>
            <h1><i class="fas fa-clipboard-check"></i> Job Order Details</h1>
            <p>
                <span>Job #<?php echo esc_html($job_order['job_number'] ?? '-'); ?></span>
                <span><?php echo esc_html($vehicle_name); ?></span>
                <span><?php echo esc_html($job_branch_label); ?></span>
            </p>
        </div>
        <div class="job-detail-actions">
            <a href="/hwtires/<?php echo esc_attr($job_detail_role); ?>/job-orders/" class="job-detail-btn secondary">
                <i class="fas fa-arrow-left"></i>
                <span>Back to List</span>
            </a>
            <?php if (!empty($job_order['vehicle_id'])): ?>
                <a href="/hwtires/<?php echo esc_attr($job_detail_role); ?>/vehicles/profile.php?id=<?php echo (int) $job_order['vehicle_id']; ?>" class="job-detail-btn secondary">
                    <i class="fas fa-car"></i>
                    <span>Vehicle Profile</span>
                </a>
            <?php endif; ?>
            <a href="/hwtires/<?php echo esc_attr($job_detail_role); ?>/customers/profile.php?id=<?php echo (int) $job_order['customer_id']; ?>" class="job-detail-btn secondary">
                <i class="fas fa-user"></i>
                <span>Customer Profile</span>
            </a>
        </div>
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

    <?php if ($is_cross_branch_job): ?>
        <div class="job-detail-notice" role="status">
            <i class="fas fa-circle-info"></i>
            <span>You are viewing <?php echo esc_html($job_branch_label); ?> records from the centralized database. Updates remain with the owning branch.</span>
        </div>
    <?php endif; ?>

    <section class="job-detail-summary" aria-label="Job order summary">
        <article>
            <span>Status</span>
            <strong><span class="job-detail-status status-<?php echo esc_attr($status_class); ?>"><?php echo esc_html(job_detail_status_label($job_order['status'] ?? '')); ?></span></strong>
        </article>
        <article>
            <span>Job Date</span>
            <strong><?php echo esc_html(job_detail_date($job_order['job_date'] ?? '')); ?></strong>
        </article>
        <article>
            <span>Branch</span>
            <strong><?php echo esc_html($job_branch_label); ?></strong>
        </article>
        <article>
            <span>Created</span>
            <strong><?php echo esc_html(job_detail_date($job_order['created_at'] ?? '', 'M d, Y H:i')); ?></strong>
        </article>
    </section>

    <section class="job-detail-grid">
        <article class="job-detail-panel">
            <div class="job-detail-panel-head">
                <h2><i class="fas fa-user"></i> Customer & Vehicle</h2>
            </div>
            <div class="job-detail-info-grid">
                <div>
                    <span>Customer Name</span>
                    <strong><?php echo esc_html($job_order['customer_name'] ?? '-'); ?></strong>
                </div>
                <div>
                    <span>Phone</span>
                    <?php if ($customer_phone !== '-'): ?>
                        <strong><a href="tel:<?php echo esc_attr($customer_phone); ?>"><?php echo esc_html($customer_phone); ?></a></strong>
                    <?php else: ?>
                        <strong>-</strong>
                    <?php endif; ?>
                </div>
                <div>
                    <span>Make / Model</span>
                    <strong>
                        <?php echo esc_html($vehicle_name); ?>
                        <?php if (!empty($job_order['vehicle_year'])): ?>
                            <em><?php echo esc_html($job_order['vehicle_year']); ?></em>
                        <?php endif; ?>
                    </strong>
                </div>
                <div>
                    <span>Plate Number</span>
                    <strong><?php echo esc_html($job_order['plate_number'] ?? '-'); ?></strong>
                </div>
            </div>
        </article>

        <article class="job-detail-panel">
            <div class="job-detail-panel-head">
                <h2><i class="fas fa-clock"></i> Scheduling & Source</h2>
            </div>
            <div class="job-detail-info-grid">
                <div>
                    <span>Assigned Technician</span>
                    <strong>
                        <?php if (!empty($job_order['assigned_technician_name'])): ?>
                            <span class="job-detail-status status-success"><?php echo esc_html($job_order['assigned_technician_name']); ?></span>
                        <?php else: ?>
                            <span class="job-detail-status status-warning">Unassigned</span>
                        <?php endif; ?>
                    </strong>
                </div>
                <div>
                    <span>Time</span>
                    <strong><?php echo esc_html(job_detail_time($job_order['scheduled_start_time'] ?? '')); ?> - <?php echo esc_html(job_detail_time($job_order['scheduled_end_time'] ?? '')); ?></strong>
                </div>
                <div>
                    <span>Expected Completion</span>
                    <strong><?php echo esc_html(job_detail_date($job_order['scheduled_end_date'] ?? '')); ?></strong>
                </div>
                <div>
                    <span>Estimated Duration</span>
                    <strong><?php echo esc_html($job_order['estimated_duration'] ?? '-'); ?></strong>
                </div>
                <?php if (!empty($job_order['quotation_id'])): ?>
                    <div>
                        <span>Source Service Operation</span>
                        <strong>
                            <a href="/hwtires/<?php echo esc_attr($job_detail_role); ?>/quotations/view.php?id=<?php echo (int) $job_order['quotation_id']; ?>">
                                <?php echo esc_html($job_order['quotation_number'] ?? '-'); ?>
                                <i class="fas fa-up-right-from-square"></i>
                            </a>
                        </strong>
                    </div>
                    <div>
                        <span>Service Operation Total</span>
                        <strong><?php echo job_detail_money($job_order['quotation_total'] ?? 0); ?></strong>
                    </div>
                <?php endif; ?>
            </div>
        </article>
    </section>

    <?php if ($has_inspection): ?>
        <section class="job-detail-panel">
            <div class="job-detail-panel-head">
                <h2><i class="fas fa-clipboard-list"></i> Service Inspection</h2>
            </div>
            <div class="job-detail-inspection-grid">
                <?php if (!empty($job_order['inspection_mileage'])): ?>
                    <div>
                        <span>Current Mileage</span>
                        <strong><?php echo number_format((int) $job_order['inspection_mileage']); ?> km</strong>
                    </div>
                <?php endif; ?>
                <?php if (trim((string) ($job_order['inspection_complaint'] ?? '')) !== ''): ?>
                    <div>
                        <span>Customer Concern</span>
                        <strong><?php echo nl2br(esc_html($job_order['inspection_complaint'])); ?></strong>
                    </div>
                <?php endif; ?>
                <?php if (trim((string) ($job_order['inspection_findings'] ?? '')) !== ''): ?>
                    <div>
                        <span>Inspection Findings</span>
                        <strong><?php echo nl2br(esc_html($job_order['inspection_findings'])); ?></strong>
                    </div>
                <?php endif; ?>
                <?php if (trim((string) ($job_order['inspection_recommendations'] ?? '')) !== ''): ?>
                    <div>
                        <span>Recommended Action</span>
                        <strong><?php echo nl2br(esc_html($job_order['inspection_recommendations'])); ?></strong>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if (!empty($quotation_items)): ?>
        <section class="job-detail-panel">
            <div class="job-detail-panel-head">
                <h2><i class="fas fa-list"></i> Service Items</h2>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 job-detail-items-table">
                    <thead>
                        <tr>
                            <th>Item Description</th>
                            <th>Category</th>
                            <th class="text-end">Qty</th>
                            <th class="text-end">Unit Price</th>
                            <th class="text-end">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($quotation_items as $item): ?>
                            <?php
                            $quantity = max(1, (int) ($item['quantity'] ?? 1));
                            $unit_price = (float) ($item['unit_price'] ?? 0);
                            $line_total = $quantity * $unit_price;
                            $inv_item_meta = app_line_item_meta($item);
                            $inv_item_record = !empty($inv_item_meta['inventory_item_id']) ? ($inventory_items_by_id[$inv_item_meta['inventory_item_id']] ?? []) : [];
                            $category_badge_text = app_line_item_type_label($item, $inv_item_record, $item['category'] ?? 'General');
                            ?>
                            <tr>
                                <td><?php echo app_line_item_description_html($item, $inventory_items_by_id, ['include_type' => true]); ?></td>
                                <td><span class="job-detail-chip"><?php echo esc_html($category_badge_text); ?></span></td>
                                <td class="text-end"><?php echo $quantity; ?></td>
                                <td class="text-end"><?php echo job_detail_money($unit_price); ?></td>
                                <td class="text-end"><strong><?php echo job_detail_money($line_total); ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <?php echo app_inventory_transaction_table_html($issued_inventory_items ?? [], [
        'title' => 'Products / Inventory Used',
        'subtitle' => 'Stock-out products issued for this job order.',
        'container' => 'section',
    ]); ?>

    <?php if ($notes !== ''): ?>
        <section class="job-detail-panel">
            <div class="job-detail-panel-head">
                <h2><i class="fas fa-note-sticky"></i> Notes</h2>
            </div>
            <div class="job-detail-notes">
                <?php echo esc_html(app_format_record_notes($notes)); ?>
            </div>
        </section>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
