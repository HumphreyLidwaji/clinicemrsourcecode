<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

$department_id = intval($_GET['department_id'] ?? 0);

if (!$department_id) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Department ID is required!";
    header("Location: departments.php");
    exit;
}

// Get department details
$department = $mysqli->query("SELECT * FROM departments WHERE department_id = $department_id")->fetch_assoc();
if (!$department) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Department not found!";
    header("Location: departments.php");
    exit;
}

// Get employees in this department (if you have an employees table)
$employees = $mysqli->query("
    SELECT * FROM employees 
    WHERE department_id = $department_id 
    ORDER BY first_name, last_name
");

$employee_count = $employees->num_rows;

// Get department statistics
$total_employees = $mysqli->query("SELECT COUNT(*) FROM employees")->fetch_row()[0];
$active_departments = $mysqli->query("SELECT COUNT(*) FROM departments WHERE department_is_active = 1")->fetch_row()[0];
?>

<div class="card">
    <div class="card-header bg-secondary py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0">
                <i class="fas fa-fw fa-sitemap mr-2"></i><?php echo htmlspecialchars($department['department_name']); ?> - Department Details
            </h3>
            <div class="card-tools">
                <a href="departments.php" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Departments
                </a>
            </div>
        </div>
    </div>

    <div class="card-body">
        <!-- Department Information -->
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">Department Information</h5>
                    </div>
                    <div class="card-body">
                        <table class="table table-sm">
                            <tr>
                                <th width="30%">Department Name:</th>
                                <td><?php echo htmlspecialchars($department['department_name']); ?></td>
                            </tr>
                            <tr>
                                <th>Description:</th>
                                <td><?php echo $department['department_description'] ? htmlspecialchars($department['department_description']) : '<em class="text-muted">No description</em>'; ?></td>
                            </tr>
                            <tr>
                                <th>Status:</th>
                                <td>
                                    <?php if ($department['department_is_active']): ?>
                                        <span class="badge badge-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary">Inactive</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th>Created:</th>
                                <td><?php echo date('M j, Y g:i A', strtotime($department['department_created_at'])); ?></td>
                            </tr>
                            <?php if (!empty($department['department_updated_at'])): ?>
                            <tr>
                                <th>Last Updated:</th>
                                <td><?php echo date('M j, Y g:i A', strtotime($department['department_updated_at'])); ?></td>
                            </tr>
                            <?php endif; ?>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">Department Statistics</h5>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-6">
                                <div class="border rounded p-3">
                                    <div class="h2 mb-0 text-primary"><?php echo $employee_count; ?></div>
                                    <small class="text-muted">Employees</small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="border rounded p-3">
                                    <div class="h2 mb-0 text-success"><?php echo $department['department_is_active'] ? 'Active' : 'Inactive'; ?></div>
                                    <small class="text-muted">Status</small>
                                </div>
                            </div>
                        </div>
                        <div class="mt-3">
                            <div class="progress" style="height: 20px;">
                                <div class="progress-bar bg-primary" style="width: <?php echo $total_employees > 0 ? ($employee_count / $total_employees) * 100 : 0; ?>%">
                                    <?php echo $total_employees > 0 ? round(($employee_count / $total_employees) * 100) : 0; ?>%
                                </div>
                            </div>
                            <div class="text-center mt-1">
                                <small class="text-muted">
                                    <?php echo $employee_count; ?> of <?php echo $total_employees; ?> total employees
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Employees List -->
        <div class="card">
            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">
                    <i class="fas fa-users mr-2"></i>Employees in <?php echo htmlspecialchars($department['department_name']); ?>
                    <span class="badge badge-primary ml-2"><?php echo $employee_count; ?> employees</span>
                </h5>
            </div>
            <div class="card-body">
                <?php if ($employee_count > 0): ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Employee Name</th>
                                <th>Position</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>Status</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($employee = $employees->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($employee['employee_first_name'] . ' ' . $employee['employee_last_name']); ?></strong>
                                </td>
                                <td><?php echo htmlspecialchars($employee['employee_position'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($employee['employee_email'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($employee['employee_phone'] ?? 'N/A'); ?></td>
                                <td>
                                    <span class="badge badge-<?php echo $employee['employee_status'] == 'active' ? 'success' : 'secondary'; ?>">
                                        <?php echo ucfirst($employee['employee_status']); ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <a href="employee_details.php?employee_id=<?php echo $employee['employee_id']; ?>" class="btn btn-sm btn-info">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="text-center py-4">
                    <i class="fas fa-users fa-3x text-muted mb-3"></i>
                    <h5 class="text-muted">No Employees Found</h5>
                    <p class="text-muted">This department doesn't have any employees assigned yet.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
.card {
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    margin-bottom: 1rem;
}
</style>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>