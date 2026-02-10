<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Get departments and job titles for dropdowns
$departments_sql = "SELECT department_id, department_name FROM departments WHERE department_is_active = 1 ORDER BY department_name";
$departments_result = $mysqli->query($departments_sql);

$job_titles_sql = "SELECT job_title_id, title FROM job_titles ORDER BY title";
$job_titles_result = $mysqli->query($job_titles_sql);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        // Start transaction
        $mysqli->begin_transaction();

        // Generate employee number
        $employee_number = 'EMP' . date('Ymd') . str_pad(mt_rand(1, 999), 3, '0', STR_PAD_LEFT);

        // Validate and sanitize all fields with strict checks
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $gender = $_POST['gender'] ?? '';
        $national_id = trim($_POST['national_id'] ?? '');
        $kra_pin = trim($_POST['kra_pin'] ?? '');
        $nssf_number = !empty(trim($_POST['nssf_number'] ?? '')) ? trim($_POST['nssf_number']) : null;
        $nhif_number = !empty(trim($_POST['nhif_number'] ?? '')) ? trim($_POST['nhif_number']) : null;
        $date_of_birth = $_POST['date_of_birth'] ?? '';
        $phone = !empty(trim($_POST['phone'] ?? '')) ? trim($_POST['phone']) : null;
        $email = !empty(trim($_POST['email'] ?? '')) ? trim($_POST['email']) : null;
        $address = !empty(trim($_POST['address'] ?? '')) ? trim($_POST['address']) : null;
        $department_id = !empty($_POST['department_id']) ? intval($_POST['department_id']) : null;
        $job_title_id = !empty($_POST['job_title_id']) ? intval($_POST['job_title_id']) : null;
        $hire_date = $_POST['hire_date'] ?? '';
        $contract_type = $_POST['contract_type'] ?? '';
        $employment_status = 'Active'; // Default value as per table structure
        $basic_salary = !empty($_POST['basic_salary']) ? floatval($_POST['basic_salary']) : 0.00;
        $bank_name = !empty(trim($_POST['bank_name'] ?? '')) ? trim($_POST['bank_name']) : null;
        $bank_branch = !empty(trim($_POST['bank_branch'] ?? '')) ? trim($_POST['bank_branch']) : null;
        $bank_account = !empty(trim($_POST['bank_account'] ?? '')) ? trim($_POST['bank_account']) : null;
        $created_by = $_SESSION['user_id'];

        // VALIDATE REQUIRED FIELDS (NOT NULL columns)
        $required_fields = [
            'First Name' => $first_name,
            'Last Name' => $last_name,
            'Gender' => $gender,
            'National ID' => $national_id,
            'KRA PIN' => $kra_pin,
            'Date of Birth' => $date_of_birth,
            'Hire Date' => $hire_date,
            'Contract Type' => $contract_type
        ];

        $missing_fields = [];
        foreach ($required_fields as $field_name => $value) {
            if (empty($value)) {
                $missing_fields[] = $field_name;
            }
        }

        if (!empty($missing_fields)) {
            throw new Exception("Required fields missing: " . implode(', ', $missing_fields));
        }

        // Validate specific field formats
        if (!in_array($gender, ['Male', 'Female', 'Other'])) {
            throw new Exception("Invalid gender selected");
        }

        if (!in_array($contract_type, ['Permanent', 'Contract', 'Locum', 'Casual'])) {
            throw new Exception("Invalid contract type selected");
        }

        // Validate dates
        if (strtotime($date_of_birth) === false) {
            throw new Exception("Invalid date of birth");
        }

        if (strtotime($hire_date) === false) {
            throw new Exception("Invalid hire date");
        }

        // Validate age (at least 18 years old)
        $min_age_date = date('Y-m-d', strtotime('-18 years'));
        if ($date_of_birth > $min_age_date) {
            throw new Exception("Employee must be at least 18 years old");
        }

        // Validate hire date is not in future
        if ($hire_date > date('Y-m-d')) {
            throw new Exception("Hire date cannot be in the future");
        }

        // Validate email format if provided
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Invalid email format");
        }

        // Check for duplicate national_id, kra_pin, employee_number
        $check_duplicates_sql = "SELECT COUNT(*) as count FROM employees WHERE national_id = ? OR kra_pin = ? OR employee_number = ?";
        $check_stmt = $mysqli->prepare($check_duplicates_sql);
        $check_stmt->bind_param("sss", $national_id, $kra_pin, $employee_number);
        $check_stmt->execute();
        $duplicate_count = $check_stmt->get_result()->fetch_assoc()['count'];
        
        if ($duplicate_count > 0) {
            throw new Exception("Employee with same National ID, KRA PIN, or Employee Number already exists");
        }

        // Insert into employees table
$employee_sql = "INSERT INTO employees (
    employee_number, first_name, last_name, gender, national_id, kra_pin, 
    nssf_number, nhif_number, date_of_birth, phone, email, address,
    department_id, job_title_id, hire_date, contract_type, employment_status,
    basic_salary, bank_name, bank_branch, bank_account, created_by
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        
        $employee_stmt = $mysqli->prepare($employee_sql);
        
        if (!$employee_stmt) {
            throw new Exception("Prepare failed: " . $mysqli->error);
        }

        // Bind parameters
        $bind_result = $employee_stmt->bind_param("ssssssssssssiisssdsssi", 
            $employee_number, $first_name, $last_name, $gender, $national_id, $kra_pin,
            $nssf_number, $nhif_number, $date_of_birth, $phone, $email, $address,
            $department_id, $job_title_id, $hire_date, $contract_type, $employment_status,
            $basic_salary, $bank_name, $bank_branch, $bank_account, $created_by
        );

        if (!$bind_result) {
            throw new Exception("Bind failed: " . $employee_stmt->error);
        }
        
        $execute_result = $employee_stmt->execute();
        
        if ($execute_result) {
            $employee_id = $mysqli->insert_id;

            // Add contract record if contract dates provided
            if (!empty($_POST['contract_start_date'])) {
                $contract_start_date = $_POST['contract_start_date'];
                $contract_end_date = !empty($_POST['contract_end_date']) ? $_POST['contract_end_date'] : null;
                $contract_terms = !empty($_POST['contract_terms']) ? trim($_POST['contract_terms']) : null;
                
                $contract_sql = "INSERT INTO employee_contracts (employee_id, start_date, end_date, terms) VALUES (?, ?, ?, ?)";
                $contract_stmt = $mysqli->prepare($contract_sql);
                if ($contract_stmt) {
                    $contract_stmt->bind_param("isss", $employee_id, $contract_start_date, $contract_end_date, $contract_terms);
                    $contract_stmt->execute();
                }
            }

            // Log the action
            $audit_sql = "INSERT INTO hr_audit_log (user_id, action, description, table_name, record_id) VALUES (?, 'employee_added', ?, 'employees', ?)";
            $audit_stmt = $mysqli->prepare($audit_sql);
            $description = "Added new employee: $first_name $last_name ($employee_number)";
            $audit_stmt->bind_param("isi", $_SESSION['user_id'], $description, $employee_id);
            $audit_stmt->execute();

            // Commit transaction
            $mysqli->commit();

            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Employee added successfully! Employee ID: " . $employee_number;
            header("Location: hr_dashboard.php");
            exit;
        } else {
            throw new Exception("Employee insertion failed: " . $employee_stmt->error);
        }

    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error adding employee: " . $e->getMessage();
        error_log("Employee Add Error: " . $e->getMessage());
    }
}
?>

<div class="card">
    <div class="card-header bg-success py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0 text-white">
                <i class="fas fa-fw fa-user-plus mr-2"></i>Add New Employee
            </h3>
            <div class="card-tools">
                <a href="hr_dashboard.php" class="btn btn-light btn-sm">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Dashboard
                </a>
            </div>
        </div>
    </div>

    <div class="card-body">
        <?php if (isset($_SESSION['alert_message'])): ?>
            <div class="alert alert-<?php echo $_SESSION['alert_type']; ?> alert-dismissible fade show" role="alert">
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                <i class="fas fa-<?php echo $_SESSION['alert_type'] == 'success' ? 'check' : 'exclamation-triangle'; ?> mr-2"></i>
                <?php echo $_SESSION['alert_message']; ?>
            </div>
            <?php 
            unset($_SESSION['alert_type']);
            unset($_SESSION['alert_message']);
            ?>
        <?php endif; ?>

        <form method="POST" id="employeeForm" autocomplete="off" novalidate>
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
                                        <input type="text" class="form-control" name="first_name" required 
                                               maxlength="100" pattern="[A-Za-z\s]{2,}" 
                                               title="First name must be at least 2 letters">
                                        <div class="invalid-feedback">Please enter a valid first name (min 2 letters).</div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Last Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="last_name" required 
                                               maxlength="100" pattern="[A-Za-z\s]{2,}"
                                               title="Last name must be at least 2 letters">
                                        <div class="invalid-feedback">Please enter a valid last name (min 2 letters).</div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>Gender <span class="text-danger">*</span></label>
                                        <select class="form-control select2" name="gender" required>
                                            <option value="">Select Gender</option>
                                            <option value="Male">Male</option>
                                            <option value="Female">Female</option>
                                            <option value="Other">Other</option>
                                        </select>
                                        <div class="invalid-feedback">Please select gender.</div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>Date of Birth <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" name="date_of_birth" required 
                                               max="<?php echo date('Y-m-d', strtotime('-18 years')); ?>">
                                        <div class="invalid-feedback">Employee must be at least 18 years old.</div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>Phone</label>
                                        <input type="tel" class="form-control" name="phone" 
                                               maxlength="20" pattern="[\+\d\s\-\(\)]{10,}"
                                               placeholder="+254..." title="Enter valid phone number">
                                        <div class="invalid-feedback">Please enter a valid phone number.</div>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Email</label>
                                <input type="email" class="form-control" name="email" 
                                       maxlength="100" placeholder="employee@company.com">
                                <div class="invalid-feedback">Please enter a valid email address.</div>
                            </div>

                            <div class="form-group">
                                <label>Address</label>
                                <textarea class="form-control" name="address" rows="2" 
                                          maxlength="500" placeholder="Full physical address"></textarea>
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
                                            <?php 
                                            if ($departments_result && $departments_result->num_rows > 0):
                                                $departments_result->data_seek(0);
                                                while($dept = $departments_result->fetch_assoc()): ?>
                                                <option value="<?php echo $dept['department_id']; ?>">
                                                    <?php echo htmlspecialchars($dept['department_name']); ?>
                                                </option>
                                            <?php endwhile; endif; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Job Title</label>
                                        <select class="form-control select2" name="job_title_id">
                                            <option value="">Select Job Title</option>
                                            <?php 
                                            if ($job_titles_result && $job_titles_result->num_rows > 0):
                                                $job_titles_result->data_seek(0);
                                                while($job = $job_titles_result->fetch_assoc()): ?>
                                                <option value="<?php echo $job['job_title_id']; ?>">
                                                    <?php echo htmlspecialchars($job['title']); ?>
                                                </option>
                                            <?php endwhile; endif; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Hire Date <span class="text-danger">*</span></label>
                                        <input type="date" class="form-control" name="hire_date" required 
                                               max="<?php echo date('Y-m-d'); ?>">
                                        <div class="invalid-feedback">Hire date cannot be in the future.</div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Contract Type <span class="text-danger">*</span></label>
                                        <select class="form-control select2" name="contract_type" required>
                                            <option value="">Select Type</option>
                                            <option value="Permanent">Permanent</option>
                                            <option value="Contract">Contract</option>
                                            <option value="Locum">Locum</option>
                                            <option value="Casual">Casual</option>
                                        </select>
                                        <div class="invalid-feedback">Please select contract type.</div>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Contract Start Date</label>
                                        <input type="date" class="form-control" name="contract_start_date">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Contract End Date</label>
                                        <input type="date" class="form-control" name="contract_end_date">
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Contract Terms</label>
                                <textarea class="form-control" name="contract_terms" rows="2" 
                                          maxlength="1000" placeholder="Contract terms and conditions"></textarea>
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
                                <small><i class="fas fa-info-circle mr-1"></i> All fields marked with <span class="text-danger">*</span> are required.</small>
                            </div>
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-success btn-lg">
                                    <i class="fas fa-save mr-2"></i>Add Employee
                                </button>
                                <button type="reset" class="btn btn-outline-secondary">
                                    <i class="fas fa-undo mr-2"></i>Reset Form
                                </button>
                                <a href="hr_dashboard.php" class="btn btn-outline-danger">
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
                                <input type="text" class="form-control" name="national_id" required 
                                       maxlength="20" pattern="\d{6,12}" title="Enter valid National ID number">
                                <div class="invalid-feedback">Please enter a valid National ID number.</div>
                            </div>
                            <div class="form-group">
                                <label>KRA PIN <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="kra_pin" required 
                                       maxlength="20" pattern="[A-Z]\d{9}[A-Z]" 
                                       title="Enter valid KRA PIN (Format: A123456789X)" placeholder="A123456789X">
                                <div class="invalid-feedback">Please enter a valid KRA PIN (Format: A123456789X).</div>
                            </div>
                            <div class="form-group">
                                <label>NSSF Number</label>
                                <input type="text" class="form-control" name="nssf_number" 
                                       maxlength="30" placeholder="NSSF number">
                            </div>
                            <div class="form-group">
                                <label>NHIF Number</label>
                                <input type="text" class="form-control" name="nhif_number" 
                                       maxlength="30" placeholder="NHIF number">
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
                                    <input type="number" class="form-control" name="basic_salary" 
                                           step="0.01" min="0" max="999999999.99" 
                                           placeholder="0.00" value="0.00">
                                </div>
                                <div class="invalid-feedback">Please enter a valid salary amount.</div>
                            </div>
                            
                            <div class="form-group">
                                <label>Bank Name</label>
                                <input type="text" class="form-control" name="bank_name" 
                                       maxlength="100" placeholder="Bank Name">
                            </div>
                            <div class="form-group">
                                <label>Bank Branch</label>
                                <input type="text" class="form-control" name="bank_branch" 
                                       maxlength="100" placeholder="Branch Name">
                            </div>
                            <div class="form-group">
                                <label>Bank Account</label>
                                <input type="text" class="form-control" name="bank_account" 
                                       maxlength="50" placeholder="Account Number">
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

    // Set today's date as default for hire date
    $('input[name="hire_date"]').val('<?php echo date('Y-m-d'); ?>');

    // Real-time validation
    $('#employeeForm').on('input', 'input, select, textarea', function() {
        validateField($(this));
    });

    function validateField(field) {
        const value = field.val().trim();
        const isRequired = field.prop('required');
        
        if (isRequired && !value) {
            field.addClass('is-invalid');
            return false;
        }
        
        // Pattern validation
        const pattern = field.attr('pattern');
        if (pattern && value) {
            const regex = new RegExp(pattern);
            if (!regex.test(value)) {
                field.addClass('is-invalid');
                return false;
            }
        }
        
        field.removeClass('is-invalid');
        return true;
    }

    // Form submission
    $('#employeeForm').on('submit', function(e) {
        let isValid = true;
        
        // Validate all required fields
        $(this).find('[required]').each(function() {
            if (!validateField($(this))) {
                isValid = false;
            }
        });

        if (!isValid) {
            e.preventDefault();
            alert('Please fix the errors in the form before submitting.');
            return false;
        }

        // Show loading state
        $('button[type="submit"]').html('<i class="fas fa-spinner fa-spin mr-2"></i>Adding Employee...').prop('disabled', true);
    });

    // Auto-format KRA PIN
    $('input[name="kra_pin"]').on('input', function() {
        let value = $(this).val().toUpperCase().replace(/[^A-Z0-9]/g, '');
        $(this).val(value);
    });

    // Auto-format National ID
    $('input[name="national_id"]').on('input', function() {
        let value = $(this).val().replace(/\D/g, '');
        $(this).val(value);
    });
});
</script>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>