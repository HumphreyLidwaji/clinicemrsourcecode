<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// SURGICAL CASE ENTRY - Updated with correct billing logic
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    
    // Patient info
    $patient_id = intval($_POST['patient_id']);
    $referral_source = sanitizeInput($_POST['referral_source'] ?? 'opd');
    
    // Surgical classification
    $surgical_urgency = sanitizeInput($_POST['surgical_urgency']);
    $asa_score = intval($_POST['asa_score']);
    $surgical_specialty = sanitizeInput($_POST['surgical_specialty']);
    $pre_op_diagnosis = sanitizeInput($_POST['pre_op_diagnosis']);
    $planned_procedure = sanitizeInput($_POST['planned_procedure']);
    $medical_service_id = intval($_POST['medical_service_id']);
    
    // Team
    $primary_surgeon_id = intval($_POST['primary_surgeon_id']);
    $anesthetist_id = intval($_POST['anesthetist_id']) ?: null;
    $referring_doctor_id = intval($_POST['referring_doctor_id']) ?: null;
    
    // Timing
    $presentation_date = sanitizeInput($_POST['presentation_date']);
    $decision_date = sanitizeInput($_POST['decision_date']);
    $target_or_date = sanitizeInput($_POST['target_or_date']) ?: null;
    
    // Additional fields
    $consent_signed = isset($_POST['consent_signed']) ? 1 : 0;
    $labs_completed = isset($_POST['labs_completed']) ? 1 : 0;
    $imaging_completed = isset($_POST['imaging_completed']) ? 1 : 0;
    $anes_clearance = isset($_POST['anes_clearance']) ? 1 : 0;
    $npo_confirmed = isset($_POST['npo_confirmed']) ? 1 : 0;
    
    // Billing option
    $bill_immediately = isset($_POST['bill_immediately']) ? 1 : 0;
    
    // Validate
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token.";
        header("Location: surgical_case_new.php");
        exit;
    }
    
    // Check required fields
    if (empty($patient_id) || empty($surgical_urgency) || empty($asa_score) || 
        empty($surgical_specialty) || empty($pre_op_diagnosis) || empty($planned_procedure) ||
        empty($primary_surgeon_id) || empty($presentation_date) || empty($decision_date)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please fill in all required fields.";
        header("Location: surgical_case_new.php");
        exit;
    }
    
    // Validate surgical urgency
    $valid_urgencies = ['emergency', 'urgent', 'elective'];
    if (!in_array($surgical_urgency, $valid_urgencies)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid surgical urgency.";
        header("Location: surgical_case_new.php");
        exit;
    }
    
    // Check if patient already has active surgical case
    $check_surgical_sql = "
        SELECT sc.case_id, sc.case_status, sc.case_number
        FROM surgical_cases sc
        WHERE sc.patient_id = ? 
          AND sc.case_status NOT IN ('completed', 'cancelled')
        LIMIT 1
    ";
    
    $check_stmt = $mysqli->prepare($check_surgical_sql);
    $check_stmt->bind_param("i", $patient_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows > 0) {
        $existing_case = $check_result->fetch_assoc();
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Patient has active surgical case #{$existing_case['case_number']}.";
        header("Location: surgical_case_new.php");
        exit;
    }

    $mysqli->begin_transaction();

    try {
        // 1. FIND ACTIVE VISIT WITHOUT EXISTING SURGICAL CASE
        $find_visit_sql = "
            SELECT v.visit_id, v.visit_number 
            FROM visits v
            LEFT JOIN surgical_cases sc ON v.visit_id = sc.visit_id
            WHERE v.patient_id = ? 
              AND v.visit_status = 'ACTIVE'
              AND sc.case_id IS NULL
            LIMIT 1
        ";
        
        $find_visit_stmt = $mysqli->prepare($find_visit_sql);
        $find_visit_stmt->bind_param("i", $patient_id);
        $find_visit_stmt->execute();
        $visit_result = $find_visit_stmt->get_result();
        
        $visit_id = null;
        $visit_number = null;
        
        if ($visit_result->num_rows > 0) {
            // Found an active visit without a surgical case
            $visit = $visit_result->fetch_assoc();
            $visit_id = $visit['visit_id'];
            $visit_number = $visit['visit_number'];
        } else {
            // No suitable active visit found - show error
            throw new Exception("No active visit found for this patient without existing surgical case. Please create or use an active visit first.");
        }
        
        // 2. CREATE SURGICAL CASE
        $case_number = 'SC-' . date('Ymd') . '-' . str_pad($visit_id, 4, '0', STR_PAD_LEFT);
        
        $case_sql = "
            INSERT INTO surgical_cases (
                case_number, visit_id, patient_id, referral_source,
                surgical_urgency, asa_score, surgical_specialty,
                pre_op_diagnosis, planned_procedure,
                primary_surgeon_id, anesthetist_id, referring_doctor_id,
                presentation_date, decision_date, target_or_date,
                case_status, created_by,
                consent_signed, labs_completed, imaging_completed,
                anes_clearance, npo_confirmed
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'referred', ?, ?, ?, ?, ?, ?)
        ";
        
        $case_stmt = $mysqli->prepare($case_sql);
        $case_stmt->bind_param(
            "siississsiiisssiiiiii",
            $case_number,
            $visit_id,
            $patient_id,
            $referral_source,
            $surgical_urgency,
            $asa_score,
            $surgical_specialty,
            $pre_op_diagnosis,
            $planned_procedure,
            $primary_surgeon_id,
            $anesthetist_id,
            $referring_doctor_id,
            $presentation_date,
            $decision_date,
            $target_or_date,
            $session_user_id,
            $consent_signed,
            $labs_completed,
            $imaging_completed,
            $anes_clearance,
            $npo_confirmed
        );
        
        if (!$case_stmt->execute()) {
            throw new Exception("Error creating surgical case: " . $mysqli->error);
        }
        
        $case_id = $mysqli->insert_id;
        
        // 3. CHECK FOR MEDICAL SERVICE AND CREATE BILLABLE ITEM IF NEEDED
        if ($medical_service_id > 0) {
            // Get medical service details
            $service_sql = "SELECT service_code, service_name, fee_amount, insurance_billable FROM medical_services WHERE medical_service_id = ?";
            $service_stmt = $mysqli->prepare($service_sql);
            $service_stmt->bind_param("i", $medical_service_id);
            $service_stmt->execute();
            $service_result = $service_stmt->get_result();
            
            if ($service_result->num_rows > 0) {
                $service = $service_result->fetch_assoc();
                
                // 4. CHECK OR CREATE BILLABLE ITEM
                $billable_item_sql = "
                    SELECT billable_item_id FROM billable_items 
                    WHERE source_table = 'medical_services' AND source_id = ? 
                    AND item_type = 'procedure'
                ";
                $billable_stmt = $mysqli->prepare($billable_item_sql);
                $billable_stmt->bind_param("i", $medical_service_id);
                $billable_stmt->execute();
                $billable_result = $billable_stmt->get_result();
                
                $billable_item_id = null;
                
                if ($billable_result->num_rows > 0) {
                    // Billable item already exists
                    $billable_item = $billable_result->fetch_assoc();
                    $billable_item_id = $billable_item['billable_item_id'];
                } else {
                    // Create new billable item for the procedure
                    $create_billable_sql = "
                        INSERT INTO billable_items (
                            item_type, source_table, source_id, item_code,
                            item_name, unit_price, is_active, created_by
                        ) VALUES ('procedure', 'medical_services', ?, ?, ?, ?, 1, ?)
                    ";
                    
                    $create_stmt = $mysqli->prepare($create_billable_sql);
                    $create_stmt->bind_param(
                        "issdi",
                        $medical_service_id,
                        $service['service_code'],
                        $service['service_name'],
                        $service['fee_amount'],
                        $session_user_id
                    );
                    
                    if (!$create_stmt->execute()) {
                        throw new Exception("Error creating billable item: " . $mysqli->error);
                    }
                    
                    $billable_item_id = $mysqli->insert_id;
                }
                
                // 5. CHECK FOR EXISTING DRAFT OR PENDING BILL FOR THIS VISIT
                $pending_bill_id = null;
                $pending_bill_sql = "
                    SELECT pending_bill_id, bill_status, bill_number 
                    FROM pending_bills 
                    WHERE visit_id = ? AND bill_status IN ('draft', 'pending')
                    LIMIT 1
                ";
                
                $pending_stmt = $mysqli->prepare($pending_bill_sql);
                $pending_stmt->bind_param("i", $visit_id);
                $pending_stmt->execute();
                $pending_result = $pending_stmt->get_result();
                
                if ($pending_result->num_rows > 0) {
                    // Use existing draft/pending bill
                    $pending_bill = $pending_result->fetch_assoc();
                    $pending_bill_id = $pending_bill['pending_bill_id'];
                    $existing_bill_number = $pending_bill['bill_number'];
                } else {
                    // Only create new pending bill if medical service requires billing
                    // Check if the service is billable (has a fee amount)
                    if ($service['fee_amount'] > 0) {
                        // Create new pending bill
                        $bill_number = 'PB-' . date('Ymd') . '-' . str_pad($visit_id, 4, '0', STR_PAD_LEFT);
                        
                        // Get default price list
                        $price_list_sql = "SELECT price_list_id FROM price_lists WHERE is_default = 1 LIMIT 1";
                        $price_list_result = $mysqli->query($price_list_sql);
                        $price_list_id = 1;
                        if ($price_list_result->num_rows > 0) {
                            $price_list = $price_list_result->fetch_assoc();
                            $price_list_id = $price_list['price_list_id'];
                        }
                        
                        $create_bill_sql = "
                            INSERT INTO pending_bills (
                                bill_number, visit_id, patient_id, price_list_id,
                                bill_status, created_by, bill_date
                            ) VALUES (?, ?, ?, ?, 'draft', ?, NOW())
                        ";
                        
                        $create_bill_stmt = $mysqli->prepare($create_bill_sql);
                        $create_bill_stmt->bind_param(
                            "siiii",
                            $bill_number,
                            $visit_id,
                            $patient_id,
                            $price_list_id,
                            $session_user_id
                        );
                        
                        if (!$create_bill_stmt->execute()) {
                            throw new Exception("Error creating pending bill: " . $mysqli->error);
                        }
                        
                        $pending_bill_id = $mysqli->insert_id;
                        $existing_bill_number = $bill_number;
                    }
                }
                
                // 6. ADD SURGERY TO PENDING BILL ITEMS (only if billable)
                if ($pending_bill_id && $billable_item_id && $service['fee_amount'] > 0) {
                    // Calculate amounts
                    $unit_price = $service['fee_amount'];
                    $item_quantity = 1.000;
                    $discount_percentage = 0.00;
                    $tax_percentage = 0.00;
                    
                    $subtotal = $unit_price * $item_quantity;
                    $discount_amount = ($subtotal * $discount_percentage) / 100;
                    $taxable_amount = $subtotal - $discount_amount;
                    $tax_amount = ($taxable_amount * $tax_percentage) / 100;
                    $total_amount = $taxable_amount + $tax_amount;
                    
                    // Check if this surgery is already added to the bill
                    $check_existing_item_sql = "
                        SELECT pending_bill_item_id FROM pending_bill_items 
                        WHERE pending_bill_id = ? AND source_type = 'surgical_case' AND source_id = ?
                    ";
                    $check_item_stmt = $mysqli->prepare($check_existing_item_sql);
                    $check_item_stmt->bind_param("ii", $pending_bill_id, $case_id);
                    $check_item_stmt->execute();
                    $check_item_result = $check_item_stmt->get_result();
                    
                    if ($check_item_result->num_rows == 0) {
                        // Only add if not already added
                        $bill_item_sql = "
                            INSERT INTO pending_bill_items (
                                pending_bill_id, billable_item_id, item_quantity,
                                unit_price, discount_percentage, discount_amount,
                                tax_percentage, subtotal, tax_amount, total_amount,
                                source_type, source_id, created_by
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'surgical_case', ?, ?)
                        ";
                        
                        $bill_item_stmt = $mysqli->prepare($bill_item_sql);
                        $bill_item_stmt->bind_param(
                            "iiddddddddis",
                            $pending_bill_id,
                            $billable_item_id,
                            $item_quantity,
                            $unit_price,
                            $discount_percentage,
                            $discount_amount,
                            $tax_percentage,
                            $subtotal,
                            $tax_amount,
                            $total_amount,
                            $case_id,
                            $session_user_id
                        );
                        
                        if (!$bill_item_stmt->execute()) {
                            throw new Exception("Error adding surgery to pending bill: " . $mysqli->error);
                        }
                        
                        // 7. UPDATE PENDING BILL TOTALS
                        $update_bill_sql = "
                            UPDATE pending_bills SET
                                subtotal_amount = COALESCE((SELECT SUM(subtotal) FROM pending_bill_items WHERE pending_bill_id = ?), 0),
                                discount_amount = COALESCE((SELECT SUM(discount_amount) FROM pending_bill_items WHERE pending_bill_id = ?), 0),
                                tax_amount = COALESCE((SELECT SUM(tax_amount) FROM pending_bill_items WHERE pending_bill_id = ?), 0),
                                total_amount = COALESCE((SELECT SUM(total_amount) FROM pending_bill_items WHERE pending_bill_id = ?), 0),
                                updated_at = NOW()
                            WHERE pending_bill_id = ?
                        ";
                        
                        $update_bill_stmt = $mysqli->prepare($update_bill_sql);
                        $update_bill_stmt->bind_param("iiiii", 
                            $pending_bill_id, $pending_bill_id, $pending_bill_id, $pending_bill_id, $pending_bill_id
                        );
                        $update_bill_stmt->execute();
                        
                        // 8. MARK AS BILLED IMMEDIATELY IF OPTION SELECTED
                        if ($bill_immediately) {
                            $finalize_sql = "
                                UPDATE pending_bills SET 
                                    bill_status = 'approved',
                                    is_finalized = 1,
                                    finalized_at = NOW(),
                                    finalized_by = ?,
                                    updated_at = NOW()
                                WHERE pending_bill_id = ?
                            ";
                            
                            $finalize_stmt = $mysqli->prepare($finalize_sql);
                            $finalize_stmt->bind_param("ii", $session_user_id, $pending_bill_id);
                            $finalize_stmt->execute();
                        }
                    } else {
                        // Surgery already added to this bill
                        $billing_info = " (Procedure already exists in pending bill #{$existing_bill_number})";
                    }
                }
            }
        }
        
        // 9. Create surgical_activities entry if table exists
        $activity_table_exists = mysqli_query($mysqli, "SHOW TABLES LIKE 'surgical_activities'");
        if (mysqli_num_rows($activity_table_exists) > 0) {
            $activity_sql = "
                INSERT INTO surgical_activities 
                (case_id, activity_type, activity_description, created_by, created_at)
                VALUES (?, 'case_created', 'Surgical case created via referral', ?, NOW())
            ";
            $activity_stmt = $mysqli->prepare($activity_sql);
            $activity_stmt->bind_param("ii", $case_id, $session_user_id);
            $activity_stmt->execute();
        }
        
        $mysqli->commit();
        
        // Build success message
        $success_message = "Surgical case #{$case_number} created successfully for visit #{$visit_number}!";
        if ($medical_service_id > 0 && isset($service)) {
            if (isset($existing_bill_number)) {
                if (isset($billing_info)) {
                    $success_message .= $billing_info;
                } else {
                    $success_message .= " Procedure added to pending bill #{$existing_bill_number}.";
                }
            }
        }
        
        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = $success_message;
        header("Location: surgical_case_view.php?case_id=" . $case_id);
        exit;
        
    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = $e->getMessage();
        header("Location: surgical_case_new.php");
        exit;
    }
}

// Get data for surgical form
$patients_result = $mysqli->query("SELECT * FROM patients ");
$surgeons_result = $mysqli->query("SELECT * FROM users ");
$anesthetists_result = $mysqli->query("SELECT * FROM users ");
$doctors_result = $mysqli->query("SELECT * FROM users ");

// Get medical services (procedures)
$medical_services_result = $mysqli->query("
    SELECT medical_service_id, service_code, service_name, fee_amount, insurance_billable 
    FROM medical_services 
    WHERE service_type = 'Procedure' AND is_active = 1 
    ORDER BY service_name
");

// Get today's OR stats
$today_or = $mysqli->query("SELECT COUNT(*) as count FROM surgical_cases WHERE DATE(target_or_date) = CURDATE()")->fetch_assoc()['count'];
$pending_cases = $mysqli->query("SELECT COUNT(*) as count FROM surgical_cases WHERE case_status = 'scheduled'")->fetch_assoc()['count'];
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-fw fa-procedures mr-2"></i>New Surgical Case
        </h3>
        <div class="card-tools">
            <div class="btn-group">
                <a href="theatre_dashboard.php" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Theatre
                </a>
                <a href="trauma_add.php" class="btn btn-warning">
                    <i class="fas fa-ambulance mr-2"></i>Emergency/Trauma
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

        <!-- Case Information Header -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="btn-toolbar justify-content-between">
                    <div class="btn-group">
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Status:</strong> 
                            <span class="badge badge-info ml-2">New Case</span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Today:</strong> 
                            <span class="badge badge-success ml-2"><?php echo date('M j, Y'); ?></span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>OR Cases Today:</strong> 
                            <span class="badge badge-primary ml-2"><?php echo $today_or; ?></span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Scheduled Cases:</strong> 
                            <span class="badge badge-warning ml-2"><?php echo $pending_cases; ?></span>
                        </span>
                    </div>
                    <div class="btn-group">
                        <a href="theatre_dashboard.php" class="btn btn-secondary">
                            <i class="fas fa-times mr-2"></i>Cancel
                        </a>
                        <button type="submit" form="newCaseForm" class="btn btn-primary">
                            <i class="fas fa-save mr-2"></i>Create Case
                        </button>
                        <button type="submit" form="newCaseForm" class="btn btn-success" onclick="return confirm('Create and schedule this case?')" name="create_and_schedule">
                            <i class="fas fa-calendar-check mr-2"></i>Create & Schedule
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <form method="post" id="newCaseForm" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            
            <div class="row">
                <!-- Left Column: Patient & Surgical Details -->
                <div class="col-md-6">
                    <!-- Patient Information Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-user-injured mr-2"></i>Patient Information</h4>
                        </div>
                        <div class="card-body">
                            <div class="form-group">
                                <label class="required">Patient</label>
                                <select class="form-control select2" name="patient_id" required data-placeholder="Search and select patient">
                                    <option value=""></option>
                                    <?php while ($patient = $patients_result->fetch_assoc()): 
                                        $full_name = htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']);
                                        $mrn = htmlspecialchars($patient['patient_mrn']);
                                    ?>
                                        <option value="<?php echo $patient['patient_id']; ?>">
                                            <?php echo $full_name; ?> (MRN: <?php echo $mrn; ?>)
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                                <small class="form-text text-muted">Search by name or MRN</small>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Referral Source</label>
                                        <select class="form-control" name="referral_source">
                                            <option value="opd">OPD</option>
                                            <option value="er">Emergency</option>
                                            <option value="internal">Internal Referral</option>
                                            <option value="external">External Referral</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="required">Date of Presentation</label>
                                        <input type="date" class="form-control" name="presentation_date" 
                                               value="<?php echo date('Y-m-d'); ?>" required>
                                        <small class="form-text text-muted">Date patient presented for consultation</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Surgical Details Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-stethoscope mr-2"></i>Surgical Details</h4>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="required">Surgical Urgency</label>
                                        <select class="form-control" name="surgical_urgency" required>
                                            <option value="">Select Urgency</option>
                                            <option value="elective">Elective (Planned)</option>
                                            <option value="urgent">Urgent (Within 24h)</option>
                                            <option value="emergency">Emergency (Immediate)</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="required">ASA Score</label>
                                        <select class="form-control" name="asa_score" required>
                                            <option value="">Select ASA Score</option>
                                            <option value="1">ASA I - Healthy</option>
                                            <option value="2">ASA II - Mild systemic disease</option>
                                            <option value="3">ASA III - Severe systemic disease</option>
                                            <option value="4">ASA IV - Severe disease, constant threat</option>
                                            <option value="5">ASA V - Moribund</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="required">Surgical Specialty</label>
                                        <select class="form-control" name="surgical_specialty" required>
                                            <option value="">Select Specialty</option>
                                            <option value="general">General Surgery</option>
                                            <option value="ortho">Orthopedics</option>
                                            <option value="neuro">Neurosurgery</option>
                                            <option value="cardio">Cardiothoracic</option>
                                            <option value="vascular">Vascular</option>
                                            <option value="urology">Urology</option>
                                            <option value="plastics">Plastic Surgery</option>
                                            <option value="ent">ENT</option>
                                            <option value="ophthal">Ophthalmology</option>
                                            <option value="gynae">Obstetrics & Gynecology</option>
                                            <option value="pediatric">Pediatric Surgery</option>
                                            <option value="trauma">Trauma Surgery</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="required">Decision to Treat Date</label>
                                        <input type="date" class="form-control" name="decision_date" 
                                               value="<?php echo date('Y-m-d'); ?>" required>
                                        <small class="form-text text-muted">Date decision for surgery was made</small>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="required">Pre-operative Diagnosis</label>
                                <input type="text" class="form-control" name="pre_op_diagnosis" 
                                       placeholder="e.g., Acute appendicitis, Fractured femur" required>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="required">Planned Procedure</label>
                                        <div class="input-group">
                                            <select class="form-control select2" name="medical_service_id" id="medical_service_id" data-placeholder="Select a procedure">
                                                <option value=""></option>
                                                <?php 
                                                $medical_services_result->data_seek(0);
                                                while ($service = $medical_services_result->fetch_assoc()): 
                                                    $fee = number_format($service['fee_amount'], 2);
                                                    $insurance = $service['insurance_billable'] ? 'Insurance' : 'Cash';
                                                ?>
                                                    <option value="<?php echo $service['medical_service_id']; ?>" 
                                                            data-fee="<?php echo $service['fee_amount']; ?>"
                                                            data-code="<?php echo htmlspecialchars($service['service_code']); ?>"
                                                            data-insurance="<?php echo $service['insurance_billable']; ?>">
                                                        <?php echo htmlspecialchars($service['service_name']); ?> 
                                                        (<?php echo $service['service_code']; ?> - $<?php echo $fee; ?> - <?php echo $insurance; ?>)
                                                    </option>
                                                <?php endwhile; ?>
                                            </select>
                                            <div class="input-group-append">
                                                <button type="button" class="btn btn-outline-info" data-toggle="modal" data-target="#procedureModal" title="Search Procedures">
                                                    <i class="fas fa-search"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Target OR Date</label>
                                        <input type="date" class="form-control" name="target_or_date" id="target_or_date">
                                        <small class="form-text text-muted">Will auto-fill based on urgency</small>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label>Custom Procedure Description (if not in list above)</label>
                                <input type="text" class="form-control" name="planned_procedure" 
                                       placeholder="Enter custom procedure description" required>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Medical Team & Pre-op Checklist -->
                <div class="col-md-6">
                    <!-- Medical Team Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-user-md mr-2"></i>Surgical Team</h4>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label class="required">Primary Surgeon</label>
                                        <select class="form-control select2" name="primary_surgeon_id" required data-placeholder="Select primary surgeon">
                                            <option value=""></option>
                                            <?php 
                                            $surgeons_result->data_seek(0);
                                            while ($surgeon = $surgeons_result->fetch_assoc()): 
                                                $title = ($surgeon['user_type'] == 'Doctor' || $surgeon['user_type'] == 'Surgeon') ? 'Dr. ' : '';
                                            ?>
                                                <option value="<?php echo $surgeon['user_id']; ?>">
                                                    <?php echo $title . htmlspecialchars($surgeon['user_name']); ?>
                                                </option>
                                            <?php endwhile; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Anesthetist</label>
                                        <select class="form-control select2" name="anesthetist_id" data-placeholder="Select anesthetist">
                                            <option value=""></option>
                                            <?php 
                                            $anesthetists_result->data_seek(0);
                                            while ($anes = $anesthetists_result->fetch_assoc()): ?>
                                                <option value="<?php echo $anes['user_id']; ?>">
                                                    <?php echo htmlspecialchars($anes['user_name']); ?>
                                                </option>
                                            <?php endwhile; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label>Referring Doctor</label>
                                <select class="form-control select2" name="referring_doctor_id" data-placeholder="Select referring doctor">
                                    <option value=""></option>
                                    <?php 
                                    $doctors_result->data_seek(0);
                                    while ($doctor = $doctors_result->fetch_assoc()): ?>
                                        <option value="<?php echo $doctor['user_id']; ?>">
                                            <?php echo htmlspecialchars($doctor['user_name']); ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Pre-op Checklist Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-clipboard-check mr-2"></i>Pre-op Checklist</h4>
                        </div>
                        <div class="card-body">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="consent_signed" id="consent_signed" value="1">
                                <label class="form-check-label" for="consent_signed">
                                    <i class="fas fa-file-signature text-success mr-2"></i> Consent Signed
                                </label>
                            </div>
                            
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="labs_completed" id="labs_completed" value="1">
                                <label class="form-check-label" for="labs_completed">
                                    <i class="fas fa-vial text-success mr-2"></i> Labs Completed
                                </label>
                            </div>
                            
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="imaging_completed" id="imaging_completed" value="1">
                                <label class="form-check-label" for="imaging_completed">
                                    <i class="fas fa-x-ray text-success mr-2"></i> Imaging Available
                                </label>
                            </div>
                            
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="anes_clearance" id="anes_clearance" value="1">
                                <label class="form-check-label" for="anes_clearance">
                                    <i class="fas fa-user-nurse text-success mr-2"></i> Anesthesia Clearance
                                </label>
                            </div>
                            
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="npo_confirmed" id="npo_confirmed" value="1">
                                <label class="form-check-label" for="npo_confirmed">
                                    <i class="fas fa-utensils text-success mr-2"></i> NPO Confirmed
                                </label>
                            </div>
                            
                            <div class="alert alert-info mt-3 p-2 small">
                                <i class="fas fa-info-circle mr-2"></i>
                                These can be completed later if not available now. Checking them will mark the case as ready for scheduling.
                            </div>
                        </div>
                    </div>

                    <!-- Billing & Options Card -->
                    <div class="card mb-4">
                        <div class="card-header bg-light py-2">
                            <h4 class="card-title mb-0"><i class="fas fa-file-invoice-dollar mr-2"></i>Billing & Options</h4>
                        </div>
                        <div class="card-body">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="bill_immediately" id="bill_immediately" value="1">
                                <label class="form-check-label" for="bill_immediately">
                                    <i class="fas fa-money-bill-wave mr-2"></i> Bill Immediately (Cash patients)
                                </label>
                                <small class="form-text text-muted d-block mt-1">
                                    Check this for cash-paying patients to create billed service order. System will check for draft/pending bill and add surgery to it.
                                </small>
                            </div>
                            
                            <div class="alert alert-warning mt-3 p-2 small">
                                <i class="fas fa-exclamation-triangle mr-2"></i>
                                <strong>Note:</strong> System will use existing draft or pending bills for this visit if available. New bill will only be created if no existing draft/pending bill exists.
                            </div>
                            
                            <div class="mt-3" id="procedureDetails" style="display: none;">
                                <div class="alert alert-info p-3">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <strong>Service Code:</strong><br>
                                            <span id="selected_code" class="font-weight-bold">-</span>
                                        </div>
                                        <div class="col-md-6">
                                            <strong>Procedure Fee:</strong><br>
                                            $<span id="selected_fee" class="font-weight-bold">0.00</span>
                                        </div>
                                    </div>
                                    <div class="row mt-2">
                                        <div class="col-md-6">
                                            <strong>Billing Type:</strong><br>
                                            <span id="selected_insurance" class="font-weight-bold">-</span>
                                        </div>
                                        <div class="col-md-6">
                                            <strong>Insurance Billable:</strong><br>
                                            <span id="selected_billable" class="font-weight-bold">-</span>
                                        </div>
                                    </div>
                                    <div class="row mt-2">
                                        <div class="col-md-12">
                                            <small class="text-muted">
                                                <i class="fas fa-info-circle"></i> This procedure will be added to existing draft/pending bill for the visit. New bill created only if none exists.
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Form Actions -->
            <div class="row">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <a href="theatre_dashboard.php" class="btn btn-secondary">
                                        <i class="fas fa-times mr-2"></i>Cancel
                                    </a>
                                    <button type="reset" class="btn btn-outline-secondary">
                                        <i class="fas fa-redo mr-2"></i>Reset Form
                                    </button>
                                </div>
                                <div>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save mr-2"></i>Create Surgical Case
                                    </button>
                                    <button type="submit" class="btn btn-success" onclick="return confirm('Create and schedule this case?')" name="create_and_schedule">
                                        <i class="fas fa-calendar-check mr-2"></i>Create & Schedule
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Procedure Search Modal -->
<div class="modal fade" id="procedureModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-info">
                <h5 class="modal-title text-white"><i class="fas fa-search mr-2"></i>Search Procedures</h5>
                <button type="button" class="close text-white" data-dismiss="modal">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <input type="text" class="form-control" id="procedureSearch" placeholder="Search by procedure name or code...">
                </div>
                <div class="table-responsive">
                    <table class="table table-hover table-sm">
                        <thead class="bg-light">
                            <tr>
                                <th>Code</th>
                                <th>Procedure Name</th>
                                <th>Fee</th>
                                <th>Type</th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody id="procedureResults">
                            <?php 
                            // Re-fetch medical services for modal
                            $services_modal = $mysqli->query("
                                SELECT medical_service_id, service_code, service_name, fee_amount, insurance_billable 
                                FROM medical_services 
                                WHERE service_type = 'Procedure' AND is_active = 1 
                                ORDER BY service_name
                            ");
                            while ($service = $services_modal->fetch_assoc()): 
                                $fee = number_format($service['fee_amount'], 2);
                                $insurance = $service['insurance_billable'] ? 'Insurance' : 'Cash';
                            ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($service['service_code']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($service['service_name']); ?></td>
                                    <td><span class="badge badge-secondary">$<?php echo $fee; ?></span></td>
                                    <td><span class="badge badge-<?php echo $insurance == 'Insurance' ? 'success' : 'warning'; ?>"><?php echo $insurance; ?></span></td>
                                    <td class="text-center">
                                        <button type="button" class="btn btn-sm btn-outline-primary select-procedure" 
                                                data-id="<?php echo $service['medical_service_id']; ?>"
                                                data-name="<?php echo htmlspecialchars($service['service_name']); ?>"
                                                data-code="<?php echo htmlspecialchars($service['service_code']); ?>"
                                                data-fee="<?php echo $service['fee_amount']; ?>"
                                                data-insurance="<?php echo $service['insurance_billable']; ?>">
                                            <i class="fas fa-check mr-1"></i> Select
                                        </button>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
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
        theme: 'bootstrap'
    });
    
    // Handle medical service selection
    $('#medical_service_id').change(function() {
        var selected = $(this).find('option:selected');
        if (selected.val()) {
            var fee = parseFloat(selected.data('fee')).toFixed(2);
            var code = selected.data('code');
            var insurance = selected.data('insurance') == 1 ? 'Insurance Billable' : 'Cash Payment';
            var billable = selected.data('insurance') == 1 ? 'Yes' : 'No';
            var procedureName = selected.text().split(' (')[0];
            
            // Update procedure details display
            $('#selected_code').text(code);
            $('#selected_fee').text(fee);
            $('#selected_insurance').text(insurance);
            $('#selected_billable').text(billable);
            $('#procedureDetails').show();
            
            // Update planned procedure input with selected service name
            $('input[name="planned_procedure"]').val(procedureName);
        } else {
            $('#procedureDetails').hide();
        }
    });
    
    // Procedure search in modal
    $('#procedureSearch').on('keyup', function() {
        var value = $(this).val().toLowerCase();
        $('#procedureResults tr').filter(function() {
            $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1);
        });
    });
    
    // Select procedure from modal
    $(document).on('click', '.select-procedure', function() {
        var id = $(this).data('id');
        var name = $(this).data('name');
        var code = $(this).data('code');
        var fee = $(this).data('fee');
        var insurance = $(this).data('insurance');
        
        // Set the select2 value
        $('#medical_service_id').val(id).trigger('change');
        
        // Update procedure details
        $('#selected_code').text(code);
        $('#selected_fee').text(parseFloat(fee).toFixed(2));
        $('#selected_insurance').text(insurance == 1 ? 'Insurance Billable' : 'Cash Payment');
        $('#selected_billable').text(insurance == 1 ? 'Yes' : 'No');
        $('#procedureDetails').show();
        
        // Update planned procedure input
        $('input[name="planned_procedure"]').val(name);
        
        // Close modal
        $('#procedureModal').modal('hide');
    });
    
    // Auto-set target OR date based on urgency
    $('select[name="surgical_urgency"]').change(function() {
        var urgency = $(this).val();
        var targetDate = new Date();
        
        switch(urgency) {
            case 'emergency':
                targetDate.setDate(targetDate.getDate() + 0); // Today
                break;
            case 'urgent':
                targetDate.setDate(targetDate.getDate() + 1); // Tomorrow
                break;
            case 'elective':
                targetDate.setDate(targetDate.getDate() + 7); // 1 week
                break;
        }
        
        if (urgency) {
            $('#target_or_date').val(targetDate.toISOString().split('T')[0]);
        }
    });
    
    // Form validation
    $('#newCaseForm').submit(function(e) {
        var isValid = true;
        var requiredFields = [
            'patient_id', 'surgical_urgency', 'asa_score', 
            'surgical_specialty', 'pre_op_diagnosis', 'planned_procedure',
            'primary_surgeon_id', 'presentation_date', 'decision_date'
        ];
        
        $('.is-invalid').removeClass('is-invalid');
        $('.select2-selection').removeClass('is-invalid');
        
        requiredFields.forEach(function(field) {
            var element = $('[name="' + field + '"]');
            if (!element.val()) {
                isValid = false;
                element.addClass('is-invalid');
                if (element.is('select')) {
                    element.next('.select2-container').find('.select2-selection').addClass('is-invalid');
                }
            }
        });
        
        if (!isValid) {
            e.preventDefault();
            // Show error message
            if (!$('#formErrorAlert').length) {
                $('#newCaseForm').prepend(
                    '<div class="alert alert-danger alert-dismissible" id="formErrorAlert">' +
                    '<button type="button" class="close" data-dismiss="alert">&times;</button>' +
                    '<i class="fas fa-exclamation-triangle mr-2"></i>' +
                    'Please fill in all required fields marked with *' +
                    '</div>'
                );
            }
            
            // Scroll to first error
            $('html, body').animate({
                scrollTop: $('.is-invalid').first().offset().top - 100
            }, 500);
            
            return false;
        }
        
        // Show loading
        $('button[type="submit"]').html('<i class="fas fa-spinner fa-spin mr-2"></i> Creating...').prop('disabled', true);
    });
    
    // Keyboard shortcuts
    $(document).keydown(function(e) {
        // Ctrl + P to focus on patient field
        if (e.ctrlKey && e.keyCode === 80) {
            e.preventDefault();
            $('select[name="patient_id"]').select2('open');
        }
        // Ctrl + U to focus on urgency field
        if (e.ctrlKey && e.keyCode === 85) {
            e.preventDefault();
            $('select[name="surgical_urgency"]').focus();
        }
        // Ctrl + S to submit form
        if (e.ctrlKey && e.keyCode === 83) {
            e.preventDefault();
            $('#newCaseForm').submit();
        }
        // Escape to reset form
        if (e.keyCode === 27) {
            if (confirm('Are you sure you want to reset the form?')) {
                $('#newCaseForm').trigger('reset');
                $('#procedureDetails').hide();
                $('.select2').val(null).trigger('change');
            }
        }
    });
    
    // Auto-close alerts after 5 seconds
    setTimeout(function() {
        $('.alert').fadeOut('slow');
    }, 5000);
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
#procedureResults tr:hover {
    background-color: #f8f9fa;
    cursor: pointer;
}
.card-title {
    font-size: 1.1rem;
    font-weight: 600;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>