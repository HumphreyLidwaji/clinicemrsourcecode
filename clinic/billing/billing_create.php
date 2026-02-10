<?php
// billing_create.php - Create New Bill
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/audit_functions.php';

// Get visit_id from URL or form
$visit_id = intval($_GET['visit_id'] ?? $_POST['visit_id'] ?? 0);

if ($visit_id <= 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Please select a visit first";
    header("Location: billing.php");
    exit;
}

// Get visit data with patient information
$visit_sql = "SELECT v.*, 
                     p.first_name, p.last_name, p.patient_mrn, p.patient_id,
                     p.date_of_birth, p.sex, p.phone_primary, p.email,
                     d.department_name,
                     u.user_name as doctor_name,
                     vi.insurance_company_id, ic.company_name as insurance_company,
                     vi.member_number, vi.coverage_percentage,
                     isc.scheme_name
              FROM visits v 
              JOIN patients p ON v.patient_id = p.patient_id
              LEFT JOIN departments d ON v.department_id = d.department_id
              LEFT JOIN users u ON v.attending_provider_id = u.user_id
              LEFT JOIN visit_insurance vi ON v.visit_id = vi.visit_id
              LEFT JOIN insurance_companies ic ON vi.insurance_company_id = ic.insurance_company_id
              LEFT JOIN insurance_schemes isc ON vi.insurance_scheme_id = isc.scheme_id
              WHERE v.visit_id = ? AND p.archived_at IS NULL";
$visit_stmt = $mysqli->prepare($visit_sql);
$visit_stmt->bind_param("i", $visit_id);
$visit_stmt->execute();
$visit_result = $visit_stmt->get_result();
$visit = $visit_result->fetch_assoc();

if (!$visit) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Visit not found";
    header("Location: billing.php");
    exit;
}

// Check if visit can be billed
if ($visit['visit_status'] == 'CANCELLED') {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Cancelled visits cannot be billed";
    header("Location: billing.php");
    exit;
}

// Check if visit already has pending bill
$existing_bill_sql = "SELECT pb.*, i.invoice_number 
                      FROM pending_bills pb
                      LEFT JOIN invoices i ON pb.invoice_id = i.invoice_id
                      WHERE pb.visit_id = ? AND pb.bill_status != 'cancelled'";
$existing_bill_stmt = $mysqli->prepare($existing_bill_sql);
$existing_bill_stmt->bind_param("i", $visit_id);
$existing_bill_stmt->execute();
$existing_bill_result = $existing_bill_stmt->get_result();
$existing_bills = $existing_bill_result->fetch_all(MYSQLI_ASSOC);

// Function to generate bill number
function generateBillNumber($mysqli, $prefix = 'BILL') {
    $year = date('Y');
    $full_prefix = $prefix . '-' . $year . '-';
    
    $sql = "SELECT MAX(bill_number) AS last_number
            FROM pending_bills
            WHERE bill_number LIKE ?
            AND YEAR(created_at) = ?";
    
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $mysqli->error);
    }
    
    $like_prefix = $full_prefix . '%';
    $stmt->bind_param('si', $like_prefix, $year);
    $stmt->execute();
    
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    
    $last_number = 0;
    if (!empty($row['last_number'])) {
        $parts = explode('-', $row['last_number']);
        $last_number = intval(end($parts));
    }
    
    $next_number = $last_number + 1;
    
    return $full_prefix . str_pad($next_number, 4, '0', STR_PAD_LEFT);
}

// Function to generate invoice number
function generateInvoiceNumber($mysqli) {
    $year = date('Y');
    $month = date('m');
    $prefix = 'INV-' . $year . '-' . $month . '-';
    
    $sql = "SELECT MAX(invoice_number) AS last_number
            FROM invoices
            WHERE invoice_number LIKE ?
            AND YEAR(created_at) = ?
            AND MONTH(created_at) = ?";
    
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $mysqli->error);
    }
    
    $like_prefix = $prefix . '%';
    $stmt->bind_param('sii', $like_prefix, $year, $month);
    $stmt->execute();
    
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    
    $last_number = 0;
    if (!empty($row['last_number'])) {
        $parts = explode('-', $row['last_number']);
        $last_number = intval(end($parts));
    }
    
    $next_number = $last_number + 1;
    
    return $prefix . str_pad($next_number, 4, '0', STR_PAD_LEFT);
}

// Get price lists for dropdown
$price_lists = [];
$price_list_sql = "SELECT price_list_id, price_list_name, price_list_type, currency, is_active 
                   FROM price_lists 
                   WHERE is_active = 1 
                   ORDER BY price_list_name";
$price_list_result = $mysqli->query($price_list_sql);
while ($row = $price_list_result->fetch_assoc()) {
    $price_lists[] = $row;
}

// Get default price list based on insurance
if ($visit['insurance_company_id']) {
    // Look for insurance price list
    $insurance_price_list = null;
    foreach ($price_lists as $pl) {
        if ($pl['price_list_type'] == 'insurance') {
            $insurance_price_list = $pl;
            break;
        }
    }
    $default_price_list_id = $insurance_price_list ? $insurance_price_list['price_list_id'] : ($price_lists[0]['price_list_id'] ?? 0);
} else {
    // Look for self-pay price list
    $self_pay_price_list = null;
    foreach ($price_lists as $pl) {
        if ($pl['price_list_type'] == 'self_pay') {
            $self_pay_price_list = $pl;
            break;
        }
    }
    $default_price_list_id = $self_pay_price_list ? $self_pay_price_list['price_list_id'] : ($price_lists[0]['price_list_id'] ?? 0);
}

// Get today's billing stats
$today_bills_sql = "SELECT 
    COUNT(*) as total_bills,
    SUM(CASE WHEN bill_status = 'draft' THEN 1 ELSE 0 END) as draft_bills,
    SUM(CASE WHEN bill_status = 'pending' THEN 1 ELSE 0 END) as pending_bills,
    SUM(CASE WHEN bill_status = 'approved' THEN 1 ELSE 0 END) as approved_bills
    FROM pending_bills 
    WHERE DATE(created_at) = CURDATE()";
$today_bills_result = $mysqli->query($today_bills_sql);
$today_bills_stats = $today_bills_result->fetch_assoc();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    
    // Validate CSRF token
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token. Please try again.";
        header("Location: billing_create.php?visit_id=" . $visit_id);
        exit;
    }
    
    $action = sanitizeInput($_POST['action'] ?? '');
    
    if ($action === 'create_bill') {
        // Create new draft bill and invoice
        
        // Start transaction
        $mysqli->begin_transaction();
        
        try {
            // Generate bill number
            $bill_number = generateBillNumber($mysqli);
            $pending_bill_number = generateBillNumber($mysqli, 'PBL');
            
            // Generate invoice number
            $invoice_number = generateInvoiceNumber($mysqli);
            
            // Get price list
            $price_list_id = intval($_POST['price_list_id'] ?? $default_price_list_id);
            
            // Get price list details
            $price_list_details = null;
            foreach ($price_lists as $pl) {
                if ($pl['price_list_id'] == $price_list_id) {
                    $price_list_details = $pl;
                    break;
                }
            }
            
            if (!$price_list_details) {
                throw new Exception("Selected price list not found");
            }
            
            // Create draft bill
            $bill_sql = "INSERT INTO pending_bills (
                bill_number,
                pending_bill_number,
                visit_id,
                patient_id,
                price_list_id,
                subtotal_amount,
                discount_amount,
                tax_amount,
                total_amount,
                bill_status,
                is_finalized,
                notes,
                created_by,
                bill_date
            ) VALUES (?, ?, ?, ?, ?, 0, 0, 0, 0, 'draft', 0, ?, ?, NOW())";
            
            $bill_stmt = $mysqli->prepare($bill_sql);
            if (!$bill_stmt) {
                throw new Exception("Prepare failed for bill: " . $mysqli->error);
            }
            
            $notes = sanitizeInput($_POST['notes'] ?? "Draft bill created for visit " . $visit['visit_number']);
            $bill_stmt->bind_param(
                "ssiiisi",
                $bill_number,
                $pending_bill_number,
                $visit_id,
                $visit['patient_id'],
                $price_list_id,
                $notes,
                $session_user_id
            );
            
            if (!$bill_stmt->execute()) {
                throw new Exception("Error creating bill: " . $bill_stmt->error);
            }
            
            $pending_bill_id = $bill_stmt->insert_id;
            
            // Create empty invoice
            $patient_name = $visit['first_name'] . ' ' . $visit['last_name'];
            $invoice_sql = "INSERT INTO invoices (
                invoice_number,
                pending_bill_id,
                visit_id,
                patient_id,
                patient_name,
                patient_mrn,
                price_list_id,
                price_list_name,
                price_list_type,
                subtotal_amount,
                discount_amount,
                tax_amount,
                total_amount,
                amount_paid,
                amount_due,
                invoice_status,
                invoice_date,
                notes,
                created_by,
                finalized_at,
                finalized_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0, 0, 0, 0, 'issued', CURDATE(), ?, ?, NOW(), ?)";
            
            $invoice_stmt = $mysqli->prepare($invoice_sql);
            if (!$invoice_stmt) {
                throw new Exception("Prepare failed for invoice: " . $mysqli->error);
            }
            
            $invoice_notes = "Invoice created for visit " . $visit['visit_number'];
            $invoice_stmt->bind_param(
                "siiisssiisssii",
                $invoice_number,
                $pending_bill_id,
                $visit_id,
                $visit['patient_id'],
                $patient_name,
                $visit['patient_mrn'],
                $price_list_id,
                $price_list_details['price_list_name'],
                $price_list_details['price_list_type'],
                $invoice_notes,
                $session_user_id,
                $session_user_id
            );
            
            if (!$invoice_stmt->execute()) {
                throw new Exception("Error creating invoice: " . $invoice_stmt->error);
            }
            
            $invoice_id = $invoice_stmt->insert_id;
            
            // Update pending bill with invoice_id
            $update_bill_sql = "UPDATE pending_bills SET invoice_id = ? WHERE pending_bill_id = ?";
            $update_bill_stmt = $mysqli->prepare($update_bill_sql);
            $update_bill_stmt->bind_param("ii", $invoice_id, $pending_bill_id);
            
            if (!$update_bill_stmt->execute()) {
                throw new Exception("Error updating bill with invoice ID: " . $update_bill_stmt->error);
            }
            
            // Commit transaction
            $mysqli->commit();
            
            // AUDIT LOG: Log bill creation
            audit_log($mysqli, [
                'user_id'     => $session_user_id,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'CREATE',
                'module'      => 'Billing',
                'table_name'  => 'pending_bills',
                'entity_type' => 'pending_bill',
                'record_id'   => $pending_bill_id,
                'patient_id'  => $visit['patient_id'],
                'visit_id'    => $visit_id,
                'description' => "Created draft bill: " . $bill_number . " for visit: " . $visit['visit_number'],
                'status'      => 'SUCCESS',
                'old_values'  => null,
                'new_values'  => [
                    'bill_number' => $bill_number,
                    'pending_bill_number' => $pending_bill_number,
                    'visit_id' => $visit_id,
                    'patient_id' => $visit['patient_id'],
                    'price_list_id' => $price_list_id,
                    'bill_status' => 'draft'
                ]
            ]);
            
            // AUDIT LOG: Log invoice creation
            audit_log($mysqli, [
                'user_id'     => $session_user_id,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'CREATE',
                'module'      => 'Billing',
                'table_name'  => 'invoices',
                'entity_type' => 'invoice',
                'record_id'   => $invoice_id,
                'patient_id'  => $visit['patient_id'],
                'visit_id'    => $visit_id,
                'description' => "Created invoice: " . $invoice_number . " for bill: " . $bill_number,
                'status'      => 'SUCCESS',
                'old_values'  => null,
                'new_values'  => [
                    'invoice_number' => $invoice_number,
                    'pending_bill_id' => $pending_bill_id,
                    'visit_id' => $visit_id,
                    'patient_id' => $visit['patient_id'],
                    'invoice_status' => 'issued'
                ]
            ]);
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Draft bill created successfully!<br>Bill #: " . $bill_number . "<br>Invoice #: " . $invoice_number;
            
            // Redirect to bill edit page
            header("Location: billing_edit.php?pending_bill_id=" . $pending_bill_id);
            exit;
            
        } catch (Exception $e) {
            $mysqli->rollback();
            
            // AUDIT LOG: Log failed bill creation
            audit_log($mysqli, [
                'user_id'     => $session_user_id,
                'user_role'   => $_SESSION['user_role_name'] ?? 'UNKNOWN',
                'action'      => 'CREATE',
                'module'      => 'Billing',
                'table_name'  => 'pending_bills',
                'entity_type' => 'pending_bill',
                'record_id'   => 0,
                'patient_id'  => $visit['patient_id'],
                'visit_id'    => $visit_id,
                'description' => "Failed to create draft bill for visit: " . $visit['visit_number'],
                'status'      => 'FAILED',
                'old_values'  => null,
                'new_values'  => null,
                'error'       => $e->getMessage()
            ]);
            
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error creating bill: " . $e->getMessage();
        }
    }
}

// Calculate patient age
$age = '';
if (!empty($visit['date_of_birth'])) {
    $birthDate = new DateTime($visit['date_of_birth']);
    $today = new DateTime();
    $age = $today->diff($birthDate)->y . ' years';
}

$patient_name = $visit['first_name'] . ' ' . $visit['last_name'];
?>

<div class="card">
    <div class="card-header bg-success py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-fw fa-plus-circle mr-2"></i>Create New Bill for Visit: <?php echo htmlspecialchars($visit['visit_number']); ?>
        </h3>
        <div class="card-tools">
            <div class="btn-group">
                <a href="billing.php?visit_id=<?php echo $visit_id; ?>" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Billing
                </a>
                <a href="visit_details.php?visit_id=<?php echo $visit_id; ?>" class="btn btn-light ml-2">
                    <i class="fas fa-eye mr-2"></i>View Visit Details
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

        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-xl-3 col-md-6 mb-4">
                <div class="card border-left-primary shadow h-100 py-2">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                    Today's Bills
                                </div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800">
                                    <?php echo $today_bills_stats['total_bills'] ?? 0; ?>
                                </div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-clipboard-list fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-md-6 mb-4">
                <div class="card border-left-info shadow h-100 py-2">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                    Draft Bills
                                </div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800">
                                    <?php echo $today_bills_stats['draft_bills'] ?? 0; ?>
                                </div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-edit fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-md-6 mb-4">
                <div class="card border-left-warning shadow h-100 py-2">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                    Pending Bills
                                </div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800">
                                    <?php echo $today_bills_stats['pending_bills'] ?? 0; ?>
                                </div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-clock fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-3 col-md-6 mb-4">
                <div class="card border-left-success shadow h-100 py-2">
                    <div class="card-body">
                        <div class="row no-gutters align-items-center">
                            <div class="col mr-2">
                                <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                    Approved Bills
                                </div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800">
                                    <?php echo $today_bills_stats['approved_bills'] ?? 0; ?>
                                </div>
                            </div>
                            <div class="col-auto">
                                <i class="fas fa-check-circle fa-2x text-gray-300"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Main Content -->
            <div class="col-md-8">
                
                <!-- Patient Information Card -->
                <div class="card mb-4">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-user-injured mr-2"></i>Patient Information</h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="table-responsive">
                                    <table class="table table-sm table-borderless">
                                        <tr>
                                            <th width="40%" class="text-muted">Patient Name:</th>
                                            <td><strong><?php echo htmlspecialchars($patient_name); ?></strong></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Medical Record No:</th>
                                            <td><strong class="text-primary"><?php echo htmlspecialchars($visit['patient_mrn']); ?></strong></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Date of Birth:</th>
                                            <td><?php echo !empty($visit['date_of_birth']) ? date('M j, Y', strtotime($visit['date_of_birth'])) : 'Not specified'; ?></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Age:</th>
                                            <td><?php echo $age ?: 'N/A'; ?></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Gender:</th>
                                            <td><?php echo htmlspecialchars($visit['sex'] ?: 'Not specified'); ?></td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="table-responsive">
                                    <table class="table table-sm table-borderless">
                                        <tr>
                                            <th class="text-muted">Primary Phone:</th>
                                            <td><?php echo htmlspecialchars($visit['phone_primary'] ?: 'Not specified'); ?></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Email:</th>
                                            <td><?php echo htmlspecialchars($visit['email'] ?: 'Not specified'); ?></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Visit Type:</th>
                                            <td><?php echo htmlspecialchars($visit['visit_type']); ?></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Department:</th>
                                            <td><?php echo htmlspecialchars($visit['department_name'] ?: 'Not specified'); ?></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Visit Date:</th>
                                            <td><?php echo date('M j, Y g:i A', strtotime($visit['visit_datetime'])); ?></td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Insurance Information Card -->
                <?php if (!empty($visit['insurance_company'])): ?>
                <div class="card mb-4">
                    <div class="card-header bg-info py-2">
                        <h4 class="card-title mb-0 text-white"><i class="fas fa-shield-alt mr-2"></i>Insurance Information</h4>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="table-responsive">
                                    <table class="table table-sm table-borderless">
                                        <tr>
                                            <th width="40%" class="text-muted">Insurance Company:</th>
                                            <td><strong class="text-info"><?php echo htmlspecialchars($visit['insurance_company']); ?></strong></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Insurance Scheme:</th>
                                            <td><?php echo htmlspecialchars($visit['scheme_name'] ?: 'Not specified'); ?></td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="table-responsive">
                                    <table class="table table-sm table-borderless">
                                        <tr>
                                            <th class="text-muted">Member Number:</th>
                                            <td><?php echo htmlspecialchars($visit['member_number'] ?: 'Not specified'); ?></td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted">Coverage:</th>
                                            <td><?php echo htmlspecialchars($visit['coverage_percentage'] ? $visit['coverage_percentage'] . '%' : 'Not specified'); ?></td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="alert alert-info mt-2">
                            <i class="fas fa-info-circle mr-2"></i>
                            Insurance bills will use the insurance price list. Patients will be billed for the uncovered portion.
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Existing Bills Card -->
                <?php if (!empty($existing_bills)): ?>
                <div class="card mb-4">
                    <div class="card-header bg-warning py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-exclamation-triangle mr-2"></i>Existing Bills for this Visit</h4>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-circle mr-2"></i>
                            This visit already has existing bills. You can create a new bill or work with existing ones.
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Bill Number</th>
                                        <th>Status</th>
                                        <th>Invoice #</th>
                                        <th>Total Amount</th>
                                        <th>Created</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($existing_bills as $bill): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($bill['bill_number']); ?></strong>
                                            </td>
                                            <td>
                                                <?php 
                                                $status_badges = [
                                                    'draft' => 'badge-secondary',
                                                    'pending' => 'badge-warning',
                                                    'approved' => 'badge-success',
                                                    'cancelled' => 'badge-danger'
                                                ];
                                                $badge_class = $status_badges[$bill['bill_status']] ?? 'badge-secondary';
                                                ?>
                                                <span class="badge <?php echo $badge_class; ?>">
                                                    <?php echo ucfirst($bill['bill_status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($bill['invoice_number']): ?>
                                                    <span class="text-success"><?php echo htmlspecialchars($bill['invoice_number']); ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">Not generated</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="font-weight-bold text-success">
                                                KSH <?php echo number_format($bill['total_amount'] ?? 0, 2); ?>
                                            </td>
                                            <td>
                                                <?php echo date('M j, Y', strtotime($bill['created_at'])); ?>
                                            </td>
                                            <td>
                                                <a href="billing_edit.php?pending_bill_id=<?php echo $bill['pending_bill_id']; ?>" class="btn btn-primary btn-sm">
                                                    <i class="fas fa-edit"></i> Edit
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="alert alert-secondary mt-3">
                            <i class="fas fa-lightbulb mr-2"></i>
                            You can either create a new bill or use one of the existing bills above.
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Create Bill Card -->
                <div class="card mb-4">
                    <div class="card-header bg-success py-2">
                        <h4 class="card-title mb-0 text-white"><i class="fas fa-plus-circle mr-2"></i>Create New Bill</h4>
                    </div>
                    <div class="card-body">
                        <form method="post" id="createBillForm">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="action" value="create_bill">
                            <input type="hidden" name="visit_id" value="<?php echo $visit_id; ?>">
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="required">Price List</label>
                                        <select class="form-control select2" name="price_list_id" id="price_list_id" required>
                                            <option value="">- Select Price List -</option>
                                            <?php foreach ($price_lists as $price_list): ?>
                                                <option value="<?php echo $price_list['price_list_id']; ?>" 
                                                    <?php echo $price_list['price_list_id'] == $default_price_list_id ? 'selected' : ''; ?>
                                                    data-type="<?php echo htmlspecialchars($price_list['price_list_type']); ?>">
                                                    <?php echo htmlspecialchars($price_list['price_list_name']); ?> 
                                                    (<?php echo htmlspecialchars($price_list['price_list_type']); ?>)
                                                    <?php if (!empty($price_list['currency'])): ?>
                                                        - <?php echo htmlspecialchars($price_list['currency']); ?>
                                                    <?php endif; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="form-text text-muted">
                                            <?php if ($visit['insurance_company']): ?>
                                                Recommended: Insurance price list for <?php echo htmlspecialchars($visit['insurance_company']); ?>
                                            <?php else: ?>
                                                Recommended: Self-pay price list
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Payment Mode</label>
                                        <input type="text" class="form-control" 
                                               value="<?php echo $visit['insurance_company'] ? 'INSURANCE' : 'CASH'; ?>" readonly>
                                        <small class="form-text text-muted">
                                            <?php if ($visit['insurance_company']): ?>
                                                Insurance coverage: <?php echo $visit['coverage_percentage'] ? $visit['coverage_percentage'] . '%' : 'Not specified'; ?>
                                            <?php else: ?>
                                                Self-pay (Cash/MPesa/Card)
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label>Notes</label>
                                <textarea class="form-control" name="notes" rows="3" 
                                          placeholder="Optional notes about this bill (e.g., special billing instructions, patient requests, etc.)"></textarea>
                            </div>
                            
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle mr-2"></i>
                                Creating a bill will also generate an invoice. You can add billable items to the bill after creation.
                            </div>
                            
                            <div class="text-center">
                                <button type="submit" class="btn btn-success btn-lg">
                                    <i class="fas fa-plus-circle mr-2"></i>Create New Bill & Invoice
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

            </div>

            <!-- Sidebar Information -->
            <div class="col-md-4">
                <!-- Visit Summary Card -->
                <div class="card mb-4">
                    <div class="card-header bg-primary py-2">
                        <h4 class="card-title mb-0 text-white"><i class="fas fa-clipboard-list mr-2"></i>Visit Summary</h4>
                    </div>
                    <div class="card-body">
                        <div class="text-center mb-3">
                            <div class="h4 text-primary"><?php echo htmlspecialchars($visit['visit_number']); ?></div>
                            <small class="text-muted">Visit ID: <?php echo $visit_id; ?></small>
                        </div>
                        <hr>
                        <div class="small">
                            <div class="d-flex justify-content-between mb-2">
                                <span>Visit Type:</span>
                                <span class="font-weight-bold"><?php echo htmlspecialchars($visit['visit_type']); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Visit Status:</span>
                                <span class="badge badge-<?php echo $visit['visit_status'] == 'ACTIVE' ? 'success' : ($visit['visit_status'] == 'CLOSED' ? 'warning' : 'secondary'); ?>">
                                    <?php echo htmlspecialchars($visit['visit_status']); ?>
                                </span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Department:</span>
                                <span class="font-weight-bold"><?php echo htmlspecialchars($visit['department_name'] ?: 'N/A'); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Attending Provider:</span>
                                <span><?php echo htmlspecialchars($visit['doctor_name'] ?: 'Not assigned'); ?></span>
                            </div>
                            <div class="d-flex justify-content-between mb-3">
                                <span>Visit Date:</span>
                                <span><?php echo date('M j, Y g:i A', strtotime($visit['visit_datetime'])); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Price List Information Card -->
                <div class="card mb-4">
                    <div class="card-header bg-info py-2">
                        <h4 class="card-title mb-0 text-white"><i class="fas fa-money-bill-wave mr-2"></i>Price List Information</h4>
                    </div>
                    <div class="card-body">
                        <div class="small">
                            <div class="d-flex align-items-start mb-3">
                                <i class="fas fa-info-circle text-info mr-2 mt-1"></i>
                                <div>
                                    <strong>Insurance Price Lists:</strong> Used for patients with insurance coverage. Items are billed at insurance-negotiated rates.
                                </div>
                            </div>
                            <div class="d-flex align-items-start mb-3">
                                <i class="fas fa-info-circle text-info mr-2 mt-1"></i>
                                <div>
                                    <strong>Self-Pay Price Lists:</strong> Used for cash-paying patients. Items are billed at standard rates.
                                </div>
                            </div>
                            <div class="d-flex align-items-start">
                                <i class="fas fa-info-circle text-info mr-2 mt-1"></i>
                                <div>
                                    <strong>Special Price Lists:</strong> Can be created for specific patient groups or promotional rates.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions Card -->
                <div class="card">
                    <div class="card-header bg-light py-2">
                        <h4 class="card-title mb-0"><i class="fas fa-bolt mr-2"></i>Quick Actions</h4>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="billing.php?visit_id=<?php echo $visit_id; ?>" class="btn btn-outline-primary btn-block">
                                <i class="fas fa-arrow-left mr-2"></i>Back to Billing
                            </a>
                            <?php if (!empty($existing_bills)): ?>
                                <a href="billing_edit.php?pending_bill_id=<?php echo $existing_bills[0]['pending_bill_id']; ?>" class="btn btn-outline-warning btn-block">
                                    <i class="fas fa-edit mr-2"></i>Edit Latest Bill
                                </a>
                            <?php endif; ?>
                            <a href="visit_details.php?visit_id=<?php echo $visit_id; ?>" class="btn btn-outline-info btn-block">
                                <i class="fas fa-eye mr-2"></i>View Visit Details
                            </a>
                            <button type="button" class="btn btn-outline-secondary btn-block" onclick="window.print()">
                                <i class="fas fa-print mr-2"></i>Print This Page
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Initialize Select2
    $('.select2').select2({
        width: '100%',
        placeholder: "Select...",
        theme: 'bootstrap',
        minimumResultsForSearch: 10
    });

    // Auto-close alerts after 5 seconds
    setTimeout(function() {
        $('.alert').fadeOut('slow');
    }, 5000);

    // Highlight recommended price list
    $('#price_list_id').on('select2:open', function() {
        setTimeout(function() {
            $('.select2-results__option[data-type="insurance"]').addClass('text-info font-weight-bold');
            $('.select2-results__option[data-type="self_pay"]').addClass('text-success font-weight-bold');
        }, 100);
    });

    // Form validation
    $('#createBillForm').on('submit', function(e) {
        var isValid = true;
        
        // Clear previous validation errors
        $('.is-invalid').removeClass('is-invalid');
        $('.select2-selection').removeClass('is-invalid');
        
        // Validate required fields
        if (!$('#price_list_id').val()) {
            isValid = false;
            $('#price_list_id').addClass('is-invalid');
            $('#price_list_id').next('.select2-container').find('.select2-selection').addClass('is-invalid');
        }

        if (!isValid) {
            e.preventDefault();
            
            // Show error message
            if (!$('#formErrorAlert').length) {
                $('#createBillForm').prepend(
                    '<div class="alert alert-danger alert-dismissible" id="formErrorAlert">' +
                    '<button type="button" class="close" data-dismiss="alert">&times;</button>' +
                    '<i class="fas fa-exclamation-triangle mr-2"></i>' +
                    'Please select a price list' +
                    '</div>'
                );
            }
            
            return false;
        }
    });

    // Keyboard shortcuts
    $(document).keydown(function(e) {
        // Ctrl + P to focus on price list
        if (e.ctrlKey && e.keyCode === 80) {
            e.preventDefault();
            $('#price_list_id').select2('open');
        }
        // Ctrl + S to submit form
        if (e.ctrlKey && e.keyCode === 83) {
            e.preventDefault();
            $('#createBillForm').submit();
        }
        // Escape to go back
        if (e.keyCode === 27) {
            window.location.href = 'billing.php?visit_id=<?php echo $visit_id; ?>';
        }
    });
});
</script>

<style>
.required:after {
    content: " *";
    color: #dc3545;
}
.select2-container .select2-selection.is-invalid {
    border-color: #dc3545;
}
.card {
    border: none;
    box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
}
.card-header {
    border-bottom: 1px solid #e3e6f0;
}
.border-left-primary {
    border-left: 0.25rem solid #4e73df !important;
}
.border-left-success {
    border-left: 0.25rem solid #1cc88a !important;
}
.border-left-info {
    border-left: 0.25rem solid #36b9cc !important;
}
.border-left-warning {
    border-left: 0.25rem solid #f6c23e !important;
}
.select2-results__option.text-info {
    color: #17a2b8 !important;
}
.select2-results__option.text-success {
    color: #28a745 !important;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>