<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Get imaging ID from URL
$imaging_id = intval($_GET['imaging_id'] ?? 0);

if ($imaging_id <= 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Invalid imaging ID.";
    header("Location: radiology_imaging.php");
    exit;
}

// Fetch imaging details
$imaging_sql = "SELECT ri.*, u1.user_name as created_by_name, u2.user_name as updated_by_name
                FROM radiology_imagings ri
                LEFT JOIN users u1 ON ri.created_by = u1.user_id
                LEFT JOIN users u2 ON ri.updated_by = u2.user_id
                WHERE ri.imaging_id = ?";
$imaging_stmt = $mysqli->prepare($imaging_sql);
$imaging_stmt->bind_param("i", $imaging_id);
$imaging_stmt->execute();
$imaging_result = $imaging_stmt->get_result();

if ($imaging_result->num_rows === 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Radiology imaging not found.";
    header("Location: radiology_imaging.php");
    exit;
}

$imaging = $imaging_result->fetch_assoc();

// Get activity logs
$activity_sql = "
    SELECT al.*, u.user_name 
    FROM activity_logs al
    LEFT JOIN users u ON al.user_id = u.user_id
    WHERE al.activity_type = 'radiology'
      AND al.activity_description LIKE ?
    ORDER BY al.created_at DESC
    LIMIT 20";

$search = "%" . $imaging['imaging_code'] . "%";
$stmt = $mysqli->prepare($activity_sql);
$stmt->bind_param("s", $search);
$stmt->execute();
$activities = $stmt->get_result();
?>

<div class="card">
    <div class="card-header bg-info py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0">
                <i class="fas fa-fw fa-x-ray mr-2"></i>Radiology Imaging Details
            </h3>
            <div class="card-tools">
                <div class="btn-group">
                    <a href="radiology_imaging_edit.php?imaging_id=<?php echo $imaging_id; ?>" class="btn btn-warning">
                        <i class="fas fa-edit mr-2"></i>Edit
                    </a>
                    <a href="radiology_imaging.php" class="btn btn-light">
                        <i class="fas fa-arrow-left mr-2"></i>Back to List
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="card-body">
        <?php if (isset($_SESSION['alert_message'])): ?>
            <div class="alert alert-<?php echo $_SESSION['alert_type']; ?> alert-dismissible">
                <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
                <i class="icon fas fa-<?php echo $_SESSION['alert_type'] == 'success' ? 'check' : 'exclamation-triangle'; ?>"></i>
                <?php echo $_SESSION['alert_message']; ?>
            </div>
            <?php 
            unset($_SESSION['alert_type']);
            unset($_SESSION['alert_message']);
            ?>
        <?php endif; ?>

        <div class="row">
            <div class="col-md-8">
                <!-- Imaging Overview -->
                <div class="card card-primary">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-info-circle mr-2"></i>Imaging Overview</h3>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <table class="table table-bordered">
                                    <tr>
                                        <th class="bg-light" style="width: 40%">Imaging Code</th>
                                        <td>
                                            <span class="font-weight-bold text-primary"><?php echo htmlspecialchars($imaging['imaging_code']); ?></span>
                                            <?php if (!$imaging['is_active']): ?>
                                                <span class="badge badge-danger ml-2">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th class="bg-light">Imaging Name</th>
                                        <td class="font-weight-bold"><?php echo htmlspecialchars($imaging['imaging_name']); ?></td>
                                    </tr>
                                    <tr>
                                        <th class="bg-light">Modality</th>
                                        <td>
                                            <span class="badge badge-primary"><?php echo htmlspecialchars($imaging['modality']); ?></span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th class="bg-light">Body Part</th>
                                        <td><?php echo htmlspecialchars($imaging['body_part'] ?: 'Not specified'); ?></td>
                                    </tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <table class="table table-bordered">
                                    <tr>
                                        <th class="bg-light" style="width: 40%">Fee Amount</th>
                                        <td class="font-weight-bold text-success">$<?php echo number_format($imaging['fee_amount'], 2); ?></td>
                                    </tr>
                                    <tr>
                                        <th class="bg-light">Duration</th>
                                        <td>
                                            <span class="badge badge-info"><?php echo intval($imaging['duration_minutes']); ?> minutes</span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th class="bg-light">Contrast Required</th>
                                        <td>
                                            <?php if ($imaging['contrast_required'] && $imaging['contrast_required'] !== 'None'): ?>
                                                <span class="badge badge-warning"><?php echo htmlspecialchars($imaging['contrast_required']); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">None</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th class="bg-light">Radiation Dose</th>
                                        <td><?php echo htmlspecialchars($imaging['radiation_dose'] ?: 'Not specified'); ?></td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                        <?php if ($imaging['imaging_description']): ?>
                        <div class="form-group">
                            <label class="font-weight-bold">Description</label>
                            <div class="border rounded p-3 bg-light">
                                <?php echo nl2br(htmlspecialchars($imaging['imaging_description'])); ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Preparation Instructions -->
                <?php if ($imaging['preparation_instructions']): ?>
                <div class="card card-warning">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-clipboard-list mr-2"></i>Preparation Instructions</h3>
                    </div>
                    <div class="card-body">
                        <div class="border rounded p-3 bg-light">
                            <?php echo nl2br(htmlspecialchars($imaging['preparation_instructions'])); ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Report Template -->
                <?php if ($imaging['report_template']): ?>
                <div class="card card-success">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-file-medical mr-2"></i>Report Template</h3>
                    </div>
                    <div class="card-body">
                        <div class="border rounded p-3 bg-light font-monospace small">
                            <?php echo nl2br(htmlspecialchars($imaging['report_template'])); ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <div class="col-md-4">
                <!-- Quick Actions -->
                <div class="card card-success">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-bolt mr-2"></i>Quick Actions</h3>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="radiology_imaging_edit.php?imaging_id=<?php echo $imaging_id; ?>" class="btn btn-warning">
                                <i class="fas fa-edit mr-2"></i>Edit Imaging
                            </a>
                            <a href="radiology_imaging.php" class="btn btn-outline-primary">
                                <i class="fas fa-list mr-2"></i>View All Imaging
                            </a>
                            <a href="radiology_imaging_add.php" class="btn btn-outline-success">
                                <i class="fas fa-plus mr-2"></i>Add New Imaging
                            </a>
                            <?php if ($imaging['is_active']): ?>
                                <button type="button" class="btn btn-outline-danger" onclick="confirmDeactivate()">
                                    <i class="fas fa-times mr-2"></i>Deactivate
                                </button>
                            <?php else: ?>
                                <button type="button" class="btn btn-outline-success" onclick="confirmActivate()">
                                    <i class="fas fa-check mr-2"></i>Activate
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Status Information -->
                <div class="card card-info">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-chart-bar mr-2"></i>Status Information</h3>
                    </div>
                    <div class="card-body">
                        <div class="list-group list-group-flush">
                            <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                                Status
                                <span class="badge badge-<?php echo $imaging['is_active'] ? 'success' : 'danger'; ?>">
                                    <?php echo $imaging['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </div>
                            <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                                Created By
                                <span class="text-muted"><?php echo htmlspecialchars($imaging['created_by_name'] ?: 'System'); ?></span>
                            </div>
                            <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                                Created Date
                                <span class="text-muted"><?php echo date('M j, Y g:i A', strtotime($imaging['created_at'])); ?></span>
                            </div>
                            <?php if ($imaging['updated_at']): ?>
                            <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                                Last Updated
                                <span class="text-muted"><?php echo date('M j, Y g:i A', strtotime($imaging['updated_at'])); ?></span>
                            </div>
                            <?php if ($imaging['updated_by_name']): ?>
                            <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                                Updated By
                                <span class="text-muted"><?php echo htmlspecialchars($imaging['updated_by_name']); ?></span>
                            </div>
                            <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Recent Activity -->
                <div class="card card-secondary">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-history mr-2"></i>Recent Activity</h3>
                    </div>
                    <div class="card-body">
                        <?php if ($activity_result->num_rows > 0): ?>
                            <div class="list-group list-group-flush">
                                <?php while ($activity = $activity_result->fetch_assoc()): ?>
                                    <div class="list-group-item px-0 py-2">
                                        <div class="d-flex w-100 justify-content-between">
                                            <small class="text-primary"><?php echo htmlspecialchars($activity['user_name'] ?: 'System'); ?></small>
                                            <small class="text-muted"><?php echo date('M j, g:i A', strtotime($activity['activity_date'])); ?></small>
                                        </div>
                                        <p class="mb-1 small"><?php echo htmlspecialchars($activity['activity_description']); ?></p>
                                    </div>
                                <?php endwhile; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-muted mb-0 text-center">
                                <i class="fas fa-info-circle mr-1"></i>
                                No recent activity
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function confirmDeactivate() {
    if (confirm('Are you sure you want to deactivate this imaging? It will no longer be available for ordering.')) {
        window.location.href = 'radiology_imaging_deactivate.php?imaging_id=<?php echo $imaging_id; ?>';
    }
}

function confirmActivate() {
    if (confirm('Are you sure you want to activate this imaging? It will be available for ordering.')) {
        window.location.href = 'radiology_imaging_activate.php?imaging_id=<?php echo $imaging_id; ?>';
    }
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + E to edit
    if (e.ctrlKey && e.keyCode === 69) {
        e.preventDefault();
        window.location.href = 'radiology_imaging_edit.php?imaging_id=<?php echo $imaging_id; ?>';
    }
    // Ctrl + L to go back to list
    if (e.ctrlKey && e.keyCode === 76) {
        e.preventDefault();
        window.location.href = 'radiology_imaging.php';
    }
});
</script>

<style>
.card {
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    margin-bottom: 1rem;
}
.table th {
    font-weight: 600;
}
.font-monospace {
    font-family: 'Courier New', monospace;
}
</style>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>