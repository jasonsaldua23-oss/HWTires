<?php
/**
 * Admin Service Catalog Management
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

$page_title = 'Service Catalog';
$csrf_token = generate_csrf_token();

if (!function_exists('services_money')) {
    function services_money($amount) {
        return '&#8369;' . number_format((float) $amount, 0);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            throw new Exception('Security check failed. Please try again.');
        }

        $action = $_POST['action'] ?? '';

        if ($action === 'create' || $action === 'update') {
            $name = trim($_POST['name'] ?? '');
            $category = trim($_POST['category'] ?? 'Service');
            $price = max(0, (float) ($_POST['price'] ?? 0));
            $labor_cost = max(0, (float) ($_POST['labor_cost'] ?? $price));
            $estimated_duration = trim($_POST['estimated_duration'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $is_variable_price = !empty($_POST['is_variable_price']) ? 1 : 0;
            $status = $_POST['status'] ?? 'active';

            if ($name === '') {
                throw new Exception('Service name is required.');
            }

            if ($category === '') {
                $category = 'Service';
            }

            if (!in_array($status, ['active', 'inactive'], true)) {
                throw new Exception('Invalid service status.');
            }

            if ($action === 'create') {
                $stmt = $pdo->prepare("
                    INSERT INTO service_catalog (
                        name, category, price, labor_cost, estimated_duration,
                        description, is_variable_price, status
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $name,
                    $category,
                    $price,
                    $labor_cost,
                    $estimated_duration !== '' ? $estimated_duration : null,
                    $description !== '' ? $description : null,
                    $is_variable_price,
                    $status,
                ]);
                $service_id = (int) $pdo->lastInsertId();
                log_audit('service_catalog', 'create', $service_id, null, [
                    'name' => $name,
                    'category' => $category,
                    'price' => $price,
                    'labor_cost' => $labor_cost,
                    'estimated_duration' => $estimated_duration,
                    'description' => $description,
                    'is_variable_price' => $is_variable_price,
                    'status' => $status,
                ]);
                set_flash_message('Service added successfully.', 'success');
            } else {
                $service_id = (int) ($_POST['service_id'] ?? 0);
                if ($service_id <= 0) {
                    throw new Exception('Invalid service selected.');
                }

                $old_stmt = $pdo->prepare("SELECT * FROM service_catalog WHERE id = ?");
                $old_stmt->execute([$service_id]);
                $old_service = $old_stmt->fetch();
                if (!$old_service) {
                    throw new Exception('Service not found.');
                }

                $stmt = $pdo->prepare("
                    UPDATE service_catalog
                    SET name = ?,
                        category = ?,
                        price = ?,
                        labor_cost = ?,
                        estimated_duration = ?,
                        description = ?,
                        is_variable_price = ?,
                        status = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $name,
                    $category,
                    $price,
                    $labor_cost,
                    $estimated_duration !== '' ? $estimated_duration : null,
                    $description !== '' ? $description : null,
                    $is_variable_price,
                    $status,
                    $service_id,
                ]);
                log_audit('service_catalog', 'update', $service_id, $old_service, [
                    'name' => $name,
                    'category' => $category,
                    'price' => $price,
                    'labor_cost' => $labor_cost,
                    'estimated_duration' => $estimated_duration,
                    'description' => $description,
                    'is_variable_price' => $is_variable_price,
                    'status' => $status,
                ]);
                set_flash_message('Service updated successfully.', 'success');
            }
        } elseif ($action === 'delete' || $action === 'archive') {
            $service_id = (int) ($_POST['service_id'] ?? 0);
            if ($service_id <= 0) {
                throw new Exception('Invalid service selected.');
            }

            $old_stmt = $pdo->prepare("SELECT * FROM service_catalog WHERE id = ?");
            $old_stmt->execute([$service_id]);
            $old_service = $old_stmt->fetch();
            if (!$old_service) {
                throw new Exception('Service not found.');
            }

            $stmt = $pdo->prepare("UPDATE service_catalog SET status = 'inactive', updated_at = NOW() WHERE id = ?");
            $stmt->execute([$service_id]);
            log_audit('service_catalog', 'archive', $service_id, $old_service, [
                'status' => 'inactive',
                'records_preserved' => true,
            ]);
            set_flash_message('Service archived successfully.', 'success');
        }
    } catch (Exception $e) {
        set_flash_message($e->getMessage(), 'danger');
    }

    redirect('/hwtires/admin/services/');
}

$services = $pdo->query("
    SELECT *
    FROM service_catalog
    ORDER BY status ASC, category ASC, name ASC
")->fetchAll();

$total_services = count($services);
$active_services = count(array_filter($services, static fn($service) => ($service['status'] ?? '') === 'active'));
$categories = array_values(array_unique(array_filter(array_map(static fn($service) => $service['category'] ?? '', $services))));
sort($categories);
?>

<?php require_once '../../includes/header.php'; ?>
<?php require_once '../../includes/sidebar.php'; ?>

<main class="user-management-page service-catalog-page">
    <header class="users-hero">
        <div>
            <h1>Service Catalog</h1>
            <p>Manage services available during front desk service operations</p>
        </div>
        <button class="users-add-btn" type="button" data-bs-toggle="modal" data-bs-target="#serviceModal" data-service-action="create">
            <i class="fas fa-plus"></i>
            <span>Add Service</span>
        </button>
    </header>

    <?php $flash_message = get_flash_message(); ?>
    <?php if ($flash_message): ?>
        <div class="alert alert-<?php echo esc_attr($flash_message['type']); ?> alert-dismissible fade show" role="alert">
            <?php echo esc_html($flash_message['message']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <section class="users-summary-grid">
        <article class="users-summary-card">
            <div>
                <span>Total Services</span>
                <strong><?php echo (int) $total_services; ?></strong>
            </div>
            <span class="users-summary-icon icon-cyan"><i class="far fa-file-lines"></i></span>
        </article>
        <article class="users-summary-card">
            <div>
                <span>Active Services</span>
                <strong><?php echo (int) $active_services; ?></strong>
            </div>
            <span class="users-summary-icon icon-green"><i class="fas fa-check"></i></span>
        </article>
        <article class="users-summary-card">
            <div>
                <span>Categories</span>
                <strong><?php echo count($categories); ?></strong>
            </div>
            <span class="users-summary-icon icon-purple"><i class="fas fa-tags"></i></span>
        </article>
    </section>

    <section class="users-table-card">
        <div class="table-responsive">
            <table class="users-table">
                <thead>
                    <tr>
                        <th>Service Name</th>
                        <th>Category</th>
                        <th>Base Price</th>
                        <th>Labor</th>
                        <th>Estimated Duration</th>
                        <th>Pricing</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($services)): ?>
                        <tr>
                            <td colspan="8" class="users-empty-cell">No services found.</td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($services as $service): ?>
                        <tr class="<?php echo ($service['status'] ?? '') === 'inactive' ? 'is-inactive' : ''; ?>">
                            <td>
                                <strong><?php echo esc_html($service['name']); ?></strong>
                                <?php if (!empty($service['description'])): ?>
                                    <small class="service-catalog-description"><?php echo esc_html($service['description']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td><span class="users-branch-pill branch-1"><?php echo esc_html($service['category']); ?></span></td>
                            <td><strong><?php echo services_money($service['price']); ?></strong></td>
                            <td><strong><?php echo services_money($service['labor_cost'] ?? $service['price']); ?></strong></td>
                            <td><?php echo esc_html($service['estimated_duration'] ?: '-'); ?></td>
                            <td>
                                <span class="service-variable-pill <?php echo !empty($service['is_variable_price']) ? 'is-variable' : ''; ?>">
                                    <?php echo !empty($service['is_variable_price']) ? 'Adjustable' : 'Fixed'; ?>
                                </span>
                            </td>
                            <td><span class="users-status-pill <?php echo ($service['status'] ?? '') === 'active' ? 'status-active' : 'status-inactive'; ?>"><?php echo esc_html(ucfirst($service['status'])); ?></span></td>
                            <td>
                                <div class="users-actions">
                                    <button class="users-icon-btn edit" type="button"
                                            data-bs-toggle="modal"
                                            data-bs-target="#serviceModal"
                                            data-service-action="update"
                                            data-service-id="<?php echo (int) $service['id']; ?>"
                                            data-service-name="<?php echo esc_attr($service['name']); ?>"
                                            data-service-category="<?php echo esc_attr($service['category']); ?>"
                                            data-service-price="<?php echo esc_attr($service['price']); ?>"
                                            data-service-labor-cost="<?php echo esc_attr($service['labor_cost'] ?? $service['price']); ?>"
                                            data-service-estimated-duration="<?php echo esc_attr($service['estimated_duration'] ?? ''); ?>"
                                            data-service-description="<?php echo esc_attr($service['description'] ?? ''); ?>"
                                            data-service-variable="<?php echo !empty($service['is_variable_price']) ? '1' : '0'; ?>"
                                            data-service-status="<?php echo esc_attr($service['status']); ?>">
                                        <i class="far fa-pen-to-square"></i>
                                    </button>
                                    <form class="m-0" method="POST" action="/hwtires/admin/services/" onsubmit="return confirm('Archive this service? It will be hidden from new service operations but kept in the database.');">
                                        <input type="hidden" name="csrf_token" value="<?php echo esc_attr($csrf_token); ?>">
                                        <input type="hidden" name="action" value="archive">
                                        <input type="hidden" name="service_id" value="<?php echo (int) $service['id']; ?>">
                                        <button class="users-icon-btn deactivate" type="submit" title="Archive service">
                                            <i class="fas fa-box-archive"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

<div class="modal fade users-modal" id="serviceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" action="/hwtires/admin/services/">
            <div class="users-modal-header">
                <h2 data-service-modal-title>Add New Service</h2>
                <button type="button" class="users-modal-close" data-bs-dismiss="modal" aria-label="Close">
                    <i class="fas fa-xmark"></i>
                </button>
            </div>
            <div class="users-modal-body">
                <input type="hidden" name="csrf_token" value="<?php echo esc_attr($csrf_token); ?>">
                <input type="hidden" name="action" value="create" data-service-action-input>
                <input type="hidden" name="service_id" value="" data-service-id-input>

                <label>
                    <span>Service Name</span>
                    <input type="text" name="name" placeholder="e.g., Wheel Alignment" required data-service-name-input>
                </label>

                <label>
                    <span>Category</span>
                    <input type="text" name="category" placeholder="e.g., Tires" value="Service" required data-service-category-input>
                </label>

                <label>
                    <span>Default Price</span>
                    <input type="number" name="price" min="0" step="0.01" placeholder="0.00" required data-service-price-input>
                </label>

                <label>
                    <span>Labor Cost</span>
                    <input type="number" name="labor_cost" min="0" step="0.01" placeholder="0.00" data-service-labor-input>
                </label>

                <label>
                    <span>Estimated Duration</span>
                    <input type="text" name="estimated_duration" placeholder="e.g., 1 hour, 2 days" data-service-duration-input>
                </label>

                <label class="users-modal-wide">
                    <span>Description</span>
                    <textarea name="description" rows="3" placeholder="Short note shown to the front desk" data-service-description-input></textarea>
                </label>

                <label class="users-modal-check">
                    <input type="checkbox" name="is_variable_price" value="1" data-service-variable-input>
                    <span>Allow price adjustment for this service</span>
                </label>

                <label>
                    <span>Status</span>
                    <select name="status" data-service-status-input>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </label>
            </div>
            <div class="users-modal-footer">
                <button type="button" class="users-cancel-btn" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="users-submit-btn" data-service-submit>Add Service</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('serviceModal');
    if (!modal) return;

    modal.addEventListener('show.bs.modal', function(event) {
        const button = event.relatedTarget;
        const action = button ? button.dataset.serviceAction || 'create' : 'create';
        const isUpdate = action === 'update';

        modal.querySelector('[data-service-modal-title]').textContent = isUpdate ? 'Edit Service' : 'Add New Service';
        modal.querySelector('[data-service-action-input]').value = action;
        modal.querySelector('[data-service-id-input]').value = isUpdate ? button.dataset.serviceId || '' : '';
        modal.querySelector('[data-service-name-input]').value = isUpdate ? button.dataset.serviceName || '' : '';
        modal.querySelector('[data-service-category-input]').value = isUpdate ? button.dataset.serviceCategory || 'Service' : 'Service';
        modal.querySelector('[data-service-price-input]').value = isUpdate ? button.dataset.servicePrice || '0' : '';
        modal.querySelector('[data-service-labor-input]').value = isUpdate ? button.dataset.serviceLaborCost || button.dataset.servicePrice || '0' : '';
        modal.querySelector('[data-service-duration-input]').value = isUpdate ? button.dataset.serviceEstimatedDuration || '' : '';
        modal.querySelector('[data-service-description-input]').value = isUpdate ? button.dataset.serviceDescription || '' : '';
        modal.querySelector('[data-service-variable-input]').checked = isUpdate ? button.dataset.serviceVariable === '1' : false;
        modal.querySelector('[data-service-status-input]').value = isUpdate ? button.dataset.serviceStatus || 'active' : 'active';
        modal.querySelector('[data-service-submit]').textContent = isUpdate ? 'Save Service' : 'Add Service';
    });
});
</script>

<?php require_once '../../includes/footer.php'; ?>
