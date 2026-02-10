<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Employee ID is required!";
    header("Location: manage_employees.php");
    exit;
}

$employee_id = intval($_GET['id']);

// Get employee details
$sql = "SELECT * FROM employees WHERE employee_id = ?";
$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $employee_id);
$stmt->execute();
$employee = $stmt->get_result()->fetch_assoc();

if (!$employee) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Employee not found!";
    header("Location: manage_employees.php");
    exit;
}

// Get departments and job titles
$departments_sql = "SELECT department_id, department_name FROM departments WHERE department_is_active = 1 ORDER BY department_name";
$departments_result = $mysqli->query($departments_sql);

$job_titles_sql = "SELECT job_title_id, title FROM job_titles ORDER BY title";
$job_titles_result = $mysqli->query($job_titles_sql);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $sql = "UPDATE employees SET 
                first_name = ?, last_name = ?, gender = ?, national_id = ?, kra_pin = ?,
                nssf_number = ?, nhif_number = ?, date_of_birth = ?, phone = ?, email = ?, address = ?,
                department_id = ?, job_title_id = ?, hire_date = ?, contract_type = ?, employment_status = ?,
                basic_salary = ?, bank_name = ?, bank_branch = ?, bank_account = ?
                WHERE employee_id = ?";
        
        $stmt = $mysqli->prepare($sql);
        
        $stmt->bind_param("sssssssssssiisssdsssi", 
            $_POST['first_name'], $_POST['last_name'], $_POST['gender'], $_POST['national_id'], $_POST['kra_pin'],
            $_POST['nssf_number'], $_POST['nhif_number'], $_POST['date_of_birth'], $_POST['phone'], $_POST['email'], $_POST['address'],
            $_POST['department_id'], $_POST['job_title_id'], $_POST['hire_date'], $_POST['contract_type'], $_POST['employment_status'],
            $_POST['basic_salary'], $_POST['bank_name'], $_POST['bank_branch'], $_POST['bank_account'],
            $employee_id
        );
        
        if ($stmt->execute()) {
            // Log the action
            $audit_sql = "INSERT INTO hr_audit_log (user_id, action, description, table_name, record_id) VALUES (?, 'employee_updated', ?, 'employees', ?)";
            $audit_stmt = $mysqli->prepare($audit_sql);
            $description = "Updated employee: " . $_POST['first_name'] . " " . $_POST['last_name'] . " (" . $employee['employee_number'] . ")";
            $audit_stmt->bind_param("isi", $_SESSION['user_id'], $description, $employee_id);
            $audit_stmt->execute();
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Employee updated successfully!";
            header("Location: view_employee.php?id=" . $employee_id);
            exit;
        } else {
            throw new Exception("Employee update failed: " . $stmt->error);
        }
    } catch (Exception $e) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error updating employee: " . $e->getMessage();
    }
}
?>

<div class="card">
    <div class="card-header bg-warning py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0 text-white">
                <i class="fas fa-fw fa-edit mr-2"></i>Edit Employee
            </h3>
            <div class="card-tools">
                <a href="view_employee.php?id=<?php echo $employee_id; ?>" class="btn btn-info btn-sm">
                    <i class="fas fa-eye mr-2"></i>View Employee
                </a>
                <a href="manage_employees.php" class="btn btn-light btn-sm ml-2">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Employees
                </a>
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

        <form method="POST" id="employeeForm">
            <div class="row">
                <div class="col-md-6">
                    <!-- Personal Information -->
                    <div class="card card-primary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-user mr-2"></i>Personal Information</h3>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>First Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="first_name" required value="<?php echo htmlspecialchars($employee['first_name']); ?>">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Last Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="last_name" required value="<?php echo htmlspecialchars($employee['last_name']); ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>Gender <span class="text-danger">*</span></label>
                                        <select class="form-control select2" name="gender" required>
                                            <option value="">Select Gender</option>
                                            <option value="Male" <?php echo $employee['gender'] == 'Male' ? 'selected' : ''; ?>>Male</option>
                                            <option value="Female" <?php echo $employee['gender'] == 'Female' ? 'selected' : ''; ?>>Female</option>
                                            <option value="Other" <?php echo $employee['gender'] == 'Other' ? 'selected' : ''; ?>>Other</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>Date of Birth <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" name="date_of_birth" required value="<?php echo $employee['date_of_birth']; ?>">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>Phone</label>
                                        <input type="text" class="form-control" name="phone" value="<?php echo htmlspecialchars($employee['phone'] ?? ''); ?>" placeholder="+254...">
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Email</label>
                                <input type="email" class="form-control" name="email" value="<?php echo htmlspecialchars($employee['email'] ?? ''); ?>" placeholder="employee@company.com">
                            </div>

                            <div class="form-group">
                                <label>Address</label>
                                <textarea class="form-control" name="address" rows="2" placeholder="Full physical address"><?php echo htmlspecialchars($employee['address'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Employment Information -->
                    <div class="card card-warning">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-briefcase mr-2"></i>Employment Information</h3>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Department</label>
                                        <select class="form-control select2" name="department_id">
                                            <option value="">Select Department</option>
                                            <?php while($dept = $departments_result->fetch_assoc()): ?>
                                                <option value="<?php echo $dept['department_id']; ?>" <?php echo $employee['department_id'] == $dept['department_id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($dept['department_name']); ?>
                                                </option>
                                            <?php endwhile; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Job Title</label>
                                        <select class="form-control select2" name="job_title_id">
                                            <option value="">Select Job Title</option>
                                            <?php while($job = $job_titles_result->fetch_assoc()): ?>
                                                <option value="<?php echo $job['job_title_id']; ?>" <?php echo $employee['job_title_id'] == $job['job_title_id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($job['title']); ?>
                                                </option>
                                            <?php endwhile; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Hire Date <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" name="hire_date" required value="<?php echo $employee['hire_date']; ?>">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Contract Type <span class="text-danger">*</span></label>
                                        <select class="form-control select2" name="contract_type" required>
                                            <option value="">Select Type</option>
                                            <option value="Permanent" <?php echo $employee['contract_type'] == 'Permanent' ? 'selected' : ''; ?>>Permanent</option>
                                            <option value="Contract" <?php echo $employee['contract_type'] == 'Contract' ? 'selected' : ''; ?>>Contract</option>
                                            <option value="Locum" <?php echo $employee['contract_type'] == 'Locum' ? 'selected' : ''; ?>>Locum</option>
                                            <option value="Casual" <?php echo $employee['contract_type'] == 'Casual' ? 'selected' : ''; ?>>Casual</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Employment Status</label>
                                <select class="form-control select2" name="employment_status">
                                    <option value="Active" <?php echo $employee['employment_status'] == 'Active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="Inactive" <?php echo $employee['employment_status'] == 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    <option value="Suspended" <?php echo $employee['employment_status'] == 'Suspended' ? 'selected' : ''; ?>>Suspended</option>
                                    <option value="Terminated" <?php echo $employee['employment_status'] == 'Terminated' ? 'selected' : ''; ?>>Terminated</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <!-- Quick Actions -->
                    <div class="card card-success">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-bolt mr-2"></i>Quick Actions</h3>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-info">
                                <small><i class="fas fa-info-circle mr-1"></i> Employee ID: <strong><?php echo htmlspecialchars($employee['employee_number']); ?></strong></small>
                            </div>
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-success btn-lg">
                                    <i class="fas fa-save mr-2"></i>Update Employee
                                </button>
                                <button type="reset" class="btn btn-outline-secondary">
                                    <i class="fas fa-undo mr-2"></i>Reset Changes
                                </button>
                                <a href="view_employee.php?id=<?php echo $employee_id; ?>" class="btn btn-outline-danger">
                                    <i class="fas fa-times mr-2"></i>Cancel
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Kenyan Compliance -->
                    <div class="card card-info">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-id-card mr-2"></i>Kenyan Compliance</h3>
                        </div>
                        <div class="card-body">
                            <div class="form-group">
                                <label>National ID <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="national_id" required value="<?php echo htmlspecialchars($employee['national_id']); ?>" placeholder="12345678">
                            </div>
                            <div class="form-group">
                                <label>KRA PIN <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="kra_pin" required value="<?php echo htmlspecialchars($employee['kra_pin']); ?>" placeholder="A123456789X">
                            </div>
                            <div class="form-group">
                                <label>NSSF Number</label>
                                <input type="text" class="form-control" name="nssf_number" value="<?php echo htmlspecialchars($employee['nssf_number'] ?? ''); ?>" placeholder="NSSF number">
                            </div>
                            <div class="form-group">
                                <label>NHIF Number</label>
                                <input type="text" class="form-control" name="nhif_number" value="<?php echo htmlspecialchars($employee['nhif_number'] ?? ''); ?>" placeholder="NHIF number">
                            </div>
                        </div>
                    </div>

                    <!-- Compensation & Banking -->
                    <div class="card card-secondary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-money-bill-wave mr-2"></i>Compensation & Banking</h3>
                        </div>
                        <div class="card-body">
                            <div class="form-group">
                                <label>Basic Salary (KES)</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text">KES</span>
                                    </div>
                                    <input type="number" class="form-control" name="basic_salary" step="0.01" min="0" value="<?php echo $employee['basic_salary']; ?>" placeholder="0.00">
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label>Bank Name</label>
                                <input type="text" class="form-control" name="bank_name" value="<?php echo htmlspecialchars($employee['bank_name'] ?? ''); ?>" placeholder="Bank Name">
                            </div>
                            <div class="form-group">
                                <label>Bank Branch</label>
                                <input type="text" class="form-control" name="bank_branch" value="<?php echo htmlspecialchars($employee['bank_branch'] ?? ''); ?>" placeholder="Branch Name">
                            </div>
                            <div class="form-group">
                                <label>Bank Account</label>
                                <input type="text" class="form-control" name="bank_account" value="<?php echo htmlspecialchars($employee['bank_account'] ?? ''); ?>" placeholder="Account Number">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
$(document).ready(function() {
    // Initialize Select2
    $('.select2').select2();

    // Form validation
    $('#employeeForm').on('submit', function(e) {
        var requiredFields = ['first_name', 'last_name', 'gender', 'national_id', 'kra_pin', 'date_of_birth', 'hire_date', 'contract_type'];
        var isValid = true;
        
        requiredFields.forEach(function(field) {
            var element = $('[name="' + field + '"]');
            if (!element.val()) {
                isValid = false;
                element.addClass('is-invalid');
            } else {
                element.removeClass('is-invalid');
            }
        });

        if (!isValid) {
            e.preventDefault();
            alert('Please fill in all required fields (marked with *).');
            return false;
        }

        // Show loading state
        $('button[type="submit"]').html('<i class="fas fa-spinner fa-spin mr-2"></i>Updating Employee...').prop('disabled', true);
    });
});
</script>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>