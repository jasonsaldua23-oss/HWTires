<?php
/**
 * Service Status Dashboard
 */

require_once '../../includes/config.php';
session_name(SESSION_NAME);
session_start();

if (!is_logged_in()) {
    redirect('/hwtires/index.php');
}

$page_title = 'Service Status';
$user = app_get_session_user();

$branch_id = intval($_GET['branch'] ?? $user['branch_id']);

if (!has_branch_access($branch_id)) {
    redirect('/hwtires/' . $user['role'] . '/index.php');
}

$stmt = $pdo->prepare("SELECT name FROM branches WHERE id = ?");
$stmt->execute([$branch_id]);
$branch = $stmt->fetch();

$statuses = ['waiting', 'in-progress', 'completed', 'cancelled'];
$job_data = [];

foreach ($statuses as $status) {
    $stmt = $pdo->prepare("
        SELECT jo.*, c.name as customer_name
        FROM job_orders jo
        LEFT JOIN customers c ON jo.customer_id = c.id
        WHERE jo.branch_id = ? AND jo.status = ?
        ORDER BY jo.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$branch_id, $status]);
    $job_data[$status] = $stmt->fetchAll();
}

$summary_query = "SELECT status, COUNT(*) as count FROM job_orders WHERE branch_id = ? GROUP BY status";
$stmt = $pdo->prepare($summary_query);
$stmt->execute([$branch_id]);
$summary = [];
$total_jobs = 0;

foreach ($stmt->fetchAll() as $row) {
    $summary[$row['status']] = $row['count'];
    $total_jobs += $row['count'];
}
?>

<?php require_once '../../includes/header.php'; ?>
<?php require_once '../../includes/sidebar.php'; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-0"><i class="fas fa-tasks"></i> Service Status Dashboard</h2>
        <small class="text-muted"><?php echo esc_html($branch['name'] ?? 'Branch'); ?></small>
    </div>
    <?php if ($user['role'] === 'admin'): ?>
    <a href="/hwtires/admin/job-orders/" class="btn btn-primary">
        <i class="fas fa-list"></i> All Job Orders
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

<div class="row mb-4">
    <div class="col-md-3">
        <div class="card text-center bg-light">
            <div class="card-body">
                <h5 class="card-title">Waiting</h5>
                <h2 class="card-text text-warning"><?php echo $summary['waiting'] ?? 0; ?></h2>
                <small class="text-muted">Ready to start</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center bg-light">
            <div class="card-body">
                <h5 class="card-title">In Progress</h5>
                <h2 class="card-text text-info"><?php echo $summary['in-progress'] ?? 0; ?></h2>
                <small class="text-muted">Currently working</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center bg-light">
            <div class="card-body">
                <h5 class="card-title">Completed</h5>
                <h2 class="card-text text-success"><?php echo $summary['completed'] ?? 0; ?></h2>
                <small class="text-muted">Finished</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card text-center bg-light">
            <div class="card-body">
                <h5 class="card-title">Cancelled</h5>
                <h2 class="card-text text-danger"><?php echo $summary['cancelled'] ?? 0; ?></h2>
                <small class="text-muted">Cancelled</small>
            </div>
        </div>
    </div>
</div>

<ul class="nav nav-tabs mb-4" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" id="waiting-tab" data-bs-toggle="tab" data-bs-target="#waiting" type="button" role="tab">
            <i class="fas fa-hourglass-start"></i> Waiting
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="in-progress-tab" data-bs-toggle="tab" data-bs-target="#in-progress" type="button" role="tab">
            <i class="fas fa-spinner"></i> In Progress
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="completed-tab" data-bs-toggle="tab" data-bs-target="#completed" type="button" role="tab">
            <i class="fas fa-check-circle"></i> Completed
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="cancelled-tab" data-bs-toggle="tab" data-bs-target="#cancelled" type="button" role="tab">
            <i class="fas fa-ban"></i> Cancelled
        </button>
    </li>
</ul>

<div class="tab-content">
    <?php foreach ($statuses as $status): ?>
    <div class="tab-pane fade <?php echo $status === 'waiting' ? 'show active' : ''; ?>" id="<?php echo esc_attr($status); ?>" role="tabpanel">
        <div class="card">
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead>
                        <tr>
                            <th>Job #</th>
                            <th>Customer</th>
                            <th>Assigned Technician</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($job_data[$status])): ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">No jobs in this status</td>
                        </tr>
                        <?php else: ?>
                            <?php foreach ($job_data[$status] as $job): ?>
                            <tr>
                                <td><strong><?php echo esc_html($job['job_number']); ?></strong></td>
                                <td><?php echo esc_html($job['customer_name'] ?? '-'); ?></td>
                                <td><?php echo esc_html($job['assigned_technician_name'] ?? '-'); ?></td>
                                <td><small><?php echo format_date($job['created_at']); ?></small></td>
                                <td>
                                    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#statusModal"
                                        onclick="loadJobForStatus(<?php echo $job['id']; ?>, '<?php echo esc_attr($job['job_number']); ?>', '<?php echo esc_attr($job['status']); ?>')">
                                        Update
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="modal fade" id="statusModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-edit"></i> Update Job Status</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="/hwtires/api/service-status-api.php">
                <input type="hidden" name="csrf_token" value="<?php echo generate_csrf_token(); ?>">
                <input type="hidden" name="action" value="update_job_status">
                <input type="hidden" name="job_order_id" id="status_job_id">
                <input type="hidden" name="redirect" value="<?php echo esc_attr($_SERVER['REQUEST_URI']); ?>">

                <div class="modal-body">
                    <div class="alert alert-info">
                        Job: <strong id="status_job_number"></strong>
                    </div>

                    <div class="form-group">
                        <label for="status_select" class="form-label required">New Status</label>
                        <select class="form-select" id="status_select" name="status" required>
                            <option value="">Select status...</option>
                            <option value="waiting">Waiting</option>
                            <option value="in-progress">In Progress</option>
                            <option value="completed">Completed</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Status</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>

<script>
function loadJobForStatus(jobId, jobNumber, currentStatus) {
    document.getElementById('status_job_id').value = jobId;
    document.getElementById('status_job_number').textContent = jobNumber;
    document.getElementById('status_select').value = currentStatus;
}
</script>
