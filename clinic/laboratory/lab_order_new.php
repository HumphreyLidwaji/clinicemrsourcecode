<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once "includes/inc_all.php";
enforceUserPermission('module_lab');

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = sanitizeInput($_POST['csrf_token']);
    $lab_order_patient_id = intval($_POST['lab_order_patient_id']);
    $ordering_doctor_id = intval($_POST['ordering_doctor_id']);
    $priority = sanitizeInput($_POST['priority']);
    $order_notes = sanitizeInput($_POST['order_notes']);
    $test_ids = isset($_POST['test_ids']) ? $_POST['test_ids'] : [];
    $visit_id = isset($_POST['visit_id']) ? intval($_POST['visit_id']) : null;

    // Validate CSRF token
    if (!validateCsrfToken($csrf_token)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Invalid CSRF token. Please try again.";
        header("Location: lab_order_new.php");
        exit;
    }

    // Validate required fields
    if (empty($lab_order_patient_id) || empty($ordering_doctor_id) || empty($test_ids)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Please fill in all required fields and select at least one test.";
        header("Location: lab_order_new.php");
        exit;
    }

    // Generate order number
    $order_number = 'LAB-' . date('Ymd') . '-' . strtoupper(uniqid());

    // Start transaction
    $mysqli->begin_transaction();

    try {
        // Insert lab order
        $order_sql = "INSERT INTO lab_orders SET 
                     order_number = ?,
                     lab_order_patient_id = ?,
                     ordering_doctor_id = ?,
                     order_priority = ?,
                     clinical_notes = ?,
                     visit_id = ?,
                     lab_order_status = 'Ordered',
                     created_by = ?,
                     created_at = NOW(),
                     updated_at = NOW()";
        
        $order_stmt = $mysqli->prepare($order_sql);
        $order_stmt->bind_param(
            "siissii",
            $order_number,
            $lab_order_patient_id,
            $ordering_doctor_id,
            $priority,
            $order_notes,
            $visit_id,
            $session_user_id
        );

        if (!$order_stmt->execute()) {
            throw new Exception("Error creating lab order: " . $mysqli->error);
        }

        $lab_order_id = $order_stmt->insert_id;

        // Insert order tests
        $test_sql = "INSERT INTO lab_order_tests (lab_order_id, test_id, status, created_at, updated_at) VALUES (?, ?, 'pending', NOW(), NOW())";
        $test_stmt = $mysqli->prepare($test_sql);

        foreach ($test_ids as $test_id) {
            $test_id = intval($test_id);
            $test_stmt->bind_param("ii", $lab_order_id, $test_id);
            if (!$test_stmt->execute()) {
                throw new Exception("Error adding test to order: " . $mysqli->error);
            }
        }

        // Log activity
        $activity_sql = "INSERT INTO lab_activities SET 
                        lab_order_id = ?,
                        activity_type = 'order_created',
                        activity_description = ?,
                        performed_by = ?,
                        activity_date = NOW()";
        
        $visit_info = $visit_id ? " (Linked to Visit #$visit_id)" : "";
        $activity_desc = "New lab order created: " . $order_number . " with " . count($test_ids) . " test(s)" . $visit_info;
        $activity_stmt = $mysqli->prepare($activity_sql);
        $activity_stmt->bind_param("isi", $lab_order_id, $activity_desc, $session_user_id);
        $activity_stmt->execute();

        // Commit transaction
        $mysqli->commit();

        $_SESSION['alert_type'] = "success";
        $_SESSION['alert_message'] = "Lab order created successfully! Order Number: " . $order_number;
        header("Location: lab_order_details.php?id=" . $lab_order_id);
        exit;

    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error creating lab order: " . $e->getMessage();
        header("Location: lab_order_new.php");
        exit;
    }
}

// Get patients for dropdown
$patients = $mysqli->query("
    SELECT patient_id, patient_name, patient_mrn, patient_dob 
    FROM patients 
");

// Get doctors for dropdown
$doctors = $mysqli->query("
    SELECT user_id, user_name 
    FROM users 
");

// Get visits with "In Progress" status
$visits = $mysqli->query("
    SELECT v.visit_id, v.visit_date, 
           p.patient_id, p.patient_name, p.patient_mrn,
           u.user_name as doctor_name
    FROM visits v
    JOIN patients p ON v.visit_patient_id = p.patient_id
    LEFT JOIN users u ON v.visit_doctor_id = u.user_id
    WHERE v.visit_status = 'In Progress'
    ORDER BY v.visit_date DESC, p.patient_name
");

// Get available tests with category information
$tests = $mysqli->query("
    SELECT 
        lt.test_id, 
        lt.test_name, 
        lt.test_code, 
        lt.category_id,
        ltc.category_name,
        lt.price, 
        lt.turnaround_time, 
        lt.specimen_type,
        lt.reference_range,
        lt.result_unit
    FROM lab_tests lt
    LEFT JOIN lab_test_categories ltc ON lt.category_id = ltc.category_id
    WHERE lt.is_active = 1 AND (ltc.is_active = 1 OR lt.category_id IS NULL)
    ORDER BY ltc.category_name, lt.test_name
");

// Group tests by category
$tests_by_category = [];
while ($test = $tests->fetch_assoc()) {
    $category = $test['category_name'] ?: 'Uncategorized';
    $tests_by_category[$category][] = $test;
}
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0">
            <i class="fas fa-fw fa-plus mr-2"></i>Create New Lab Order
        </h3>
        <div class="card-tools">
            <a href="lab_orders.php" class="btn btn-light">
                <i class="fas fa-arrow-left mr-2"></i>Back to Orders
            </a>
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

        <form method="POST" id="createOrderForm" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            
            <!-- Patient & Doctor Information -->
            <div class="card card-primary mb-4">
                <div class="card-header bg-light py-2">
                    <h4 class="card-title mb-0"><i class="fas fa-user-injured mr-2"></i>Patient & Ordering Information</h4>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="lab_order_patient_id">Patient <span class="text-danger">*</span></label>
                                <select class="form-control select2" id="lab_order_patient_id" name="lab_order_patient_id" required onchange="updateVisitsForPatient()">
                                    <option value="">- Select Patient -</option>
                                    <?php while($patient = $patients->fetch_assoc()): ?>
                                        <option value="<?php echo $patient['patient_id']; ?>">
                                            <?php echo htmlspecialchars($patient['patient_name']); ?> 
                                            (MRN: <?php echo htmlspecialchars($patient['patient_mrn']); ?>)
                                            - DOB: <?php echo htmlspecialchars($patient['patient_dob']); ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="ordering_doctor_id">Ordering Doctor <span class="text-danger">*</span></label>
                                <select class="form-control select2" id="ordering_doctor_id" name="ordering_doctor_id" required>
                                    <option value="">- Select Doctor -</option>
                                    <?php while($doctor = $doctors->fetch_assoc()): ?>
                                        <option value="<?php echo $doctor['user_id']; ?>">
                                            <?php echo htmlspecialchars($doctor['user_name']); ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="priority">Priority <span class="text-danger">*</span></label>
                                <select class="form-control" id="priority" name="priority" required>
                                    <option value="routine">Routine</option>
                                    <option value="urgent">Urgent</option>
                                    <option value="stat">STAT</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="visit_id">Link to Visit (Optional)</label>
                                <select class="form-control select2" id="visit_id" name="visit_id">
                                    <option value="">- No Visit Link -</option>
                                    <?php while($visit = $visits->fetch_assoc()): ?>
                                        <option value="<?php echo $visit['visit_id']; ?>" data-patient-id="<?php echo $visit['patient_id']; ?>">
                                            Visit #<?php echo $visit['visit_id']; ?> - 
                                            <?php echo htmlspecialchars($visit['patient_name']); ?> 
                                            (<?php echo date('M j, Y', strtotime($visit['visit_date'])); ?>)
                                            - <?php echo htmlspecialchars($visit['visit_reason']); ?>
                                            <?php if ($visit['doctor_name']): ?>
                                                - Dr. <?php echo htmlspecialchars($visit['doctor_name']); ?>
                                            <?php endif; ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                                <small class="form-text text-muted">
                                    Only visits with "In Progress" status are shown. Visit will be automatically filtered when you select a patient.
                                </small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-group">
                                <label for="order_notes">Order Notes</label>
                                <textarea class="form-control" id="order_notes" name="order_notes" 
                                          rows="2" placeholder="Any special instructions or clinical information..."></textarea>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Test Selection -->
            <div class="card card-info mb-4">
                <div class="card-header bg-light py-2">
                    <h4 class="card-title mb-0"><i class="fas fa-flask mr-2"></i>Test Selection</h4>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-12">
                            <div class="input-group">
                                <input type="text" class="form-control" id="testSearch" placeholder="Search tests by name, code, or category...">
                                <div class="input-group-append">
                                    <button type="button" class="btn btn-outline-secondary" onclick="clearTestSearch()">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-group">
                                <label>Select Tests <span class="text-danger">*</span></label>
                                <div class="test-selection-container" style="max-height: 400px; overflow-y: auto; border: 1px solid #ddd; padding: 15px; border-radius: 4px;">
                                    <?php if (!empty($tests_by_category)): ?>
                                        <?php foreach ($tests_by_category as $category => $category_tests): ?>
                                            <div class="category-section mb-4">
                                                <h6 class="category-header bg-light p-2 mb-3">
                                                    <i class="fas fa-folder mr-2"></i><?php echo htmlspecialchars($category); ?>
                                                    <small class="text-muted ml-2">(<?php echo count($category_tests); ?> tests)</small>
                                                </h6>
                                                <div class="row">
                                                    <?php foreach ($category_tests as $test): ?>
                                                        <div class="col-md-6 col-lg-4 mb-3 test-item" 
                                                             data-test-name="<?php echo strtolower(htmlspecialchars($test['test_name'])); ?>"
                                                             data-test-code="<?php echo strtolower(htmlspecialchars($test['test_code'])); ?>"
                                                             data-category="<?php echo strtolower(htmlspecialchars($category)); ?>">
                                                            <div class="custom-control custom-checkbox">
                                                                <input type="checkbox" class="custom-control-input test-checkbox" 
                                                                       id="test_<?php echo $test['test_id']; ?>" 
                                                                       name="test_ids[]" 
                                                                       value="<?php echo $test['test_id']; ?>">
                                                                <label class="custom-control-label d-block" for="test_<?php echo $test['test_id']; ?>">
                                                                    <strong><?php echo htmlspecialchars($test['test_name']); ?></strong>
                                                                    <br>
                                                                    <small class="text-muted">
                                                                        Code: <?php echo htmlspecialchars($test['test_code']); ?> | 
                                                                        Specimen: <?php echo htmlspecialchars($test['specimen_type']); ?>
                                                                        <br>
                                                                        Turnaround: <?php echo htmlspecialchars($test['turnaround_time']); ?> hours | 
                                                                        $<?php echo number_format($test['price'], 2); ?>
                                                                        <?php if ($test['reference_range']): ?>
                                                                            <br>Ref Range: <?php echo htmlspecialchars($test['reference_range']); ?>
                                                                        <?php endif; ?>
                                                                        <?php if ($test['result_unit']): ?>
                                                                            (<?php echo htmlspecialchars($test['result_unit']); ?>)
                                                                        <?php endif; ?>
                                                                    </small>
                                                                </label>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <p class="text-muted text-center">No tests available.</p>
                                    <?php endif; ?>
                                </div>
                                <small class="form-text text-muted" id="selectedTestsCount">
                                    Selected tests: <span id="selectedCount">0</span>
                                </small>
                            </div>
                        </div>
                    </div>

                    <!-- Selected Tests Summary -->
                    <div class="row mt-3">
                        <div class="col-md-12">
                            <div class="card card-warning">
                                <div class="card-header bg-light py-2">
                                    <h5 class="card-title mb-0"><i class="fas fa-list mr-2"></i>Selected Tests Summary</h5>
                                </div>
                                <div class="card-body">
                                    <div id="selectedTestsList" class="small">
                                        <p class="text-muted mb-0">No tests selected yet.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Test Panels -->
            <div class="card card-success mb-4">
                <div class="card-header bg-light py-2">
                    <h4 class="card-title mb-0"><i class="fas fa-bolt mr-2"></i>Quick Test Panels</h4>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <button type="button" class="btn btn-outline-primary btn-block text-left" onclick="loadTestPanel('basic_metabolic')">
                                <i class="fas fa-heartbeat mr-2"></i>Basic Metabolic Panel
                            </button>
                        </div>
                        <div class="col-md-3 mb-3">
                            <button type="button" class="btn btn-outline-primary btn-block text-left" onclick="loadTestPanel('complete_blood_count')">
                                <i class="fas fa-tint mr-2"></i>Complete Blood Count
                            </button>
                        </div>
                        <div class="col-md-3 mb-3">
                            <button type="button" class="btn btn-outline-primary btn-block text-left" onclick="loadTestPanel('liver_function')">
                                <i class="fas fa-liver mr-2"></i>Liver Function Tests
                            </button>
                        </div>
                        <div class="col-md-3 mb-3">
                            <button type="button" class="btn btn-outline-primary btn-block text-left" onclick="loadTestPanel('thyroid')">
                                <i class="fas fa-burn mr-2"></i>Thyroid Panel
                            </button>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <button type="button" class="btn btn-outline-primary btn-block text-left" onclick="loadTestPanel('lipid')">
                                <i class="fas fa-chart-pie mr-2"></i>Lipid Panel
                            </button>
                        </div>
                        <div class="col-md-3 mb-3">
                            <button type="button" class="btn btn-outline-primary btn-block text-left" onclick="loadTestPanel('renal')">
                                <i class="fas fa-kidneys mr-2"></i>Renal Function Tests
                            </button>
                        </div>
                        <div class="col-md-3 mb-3">
                            <button type="button" class="btn btn-outline-primary btn-block text-left" onclick="loadTestPanel('diabetes')">
                                <i class="fas fa-syringe mr-2"></i>Diabetes Panel
                            </button>
                        </div>
                        <div class="col-md-3 mb-3">
                            <button type="button" class="btn btn-outline-danger btn-block text-left" onclick="clearAllTests()">
                                <i class="fas fa-times mr-2"></i>Clear All Tests
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Form Actions -->
            <div class="row mt-4">
                <div class="col-md-12">
                    <div class="btn-toolbar justify-content-between">
                        <a href="lab_orders.php" class="btn btn-secondary">
                            <i class="fas fa-times mr-2"></i>Cancel
                        </a>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-save mr-2"></i>Create Lab Order
                        </button>
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

    // Update selected tests count and summary
    function updateSelectedTests() {
        const selectedTests = $('.test-checkbox:checked');
        const selectedCount = selectedTests.length;
        
        $('#selectedCount').text(selectedCount);
        
        if (selectedCount > 0) {
            let summaryHtml = '<div class="row">';
            selectedTests.each(function() {
                const testId = $(this).val();
                const testName = $(this).closest('.test-item').find('strong').text();
                const testCode = $(this).closest('.test-item').find('small').text().split('Code: ')[1].split(' | ')[0];
                
                summaryHtml += `
                    <div class="col-md-6 mb-2">
                        <div class="d-flex justify-content-between align-items-center border-bottom pb-1">
                            <div>
                                <strong>${testName}</strong>
                                <br>
                                <small class="text-muted">Code: ${testCode}</small>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="unselectTest(${testId})">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                `;
            });
            summaryHtml += '</div>';
            $('#selectedTestsList').html(summaryHtml);
        } else {
            $('#selectedTestsList').html('<p class="text-muted mb-0">No tests selected yet.</p>');
        }
    }

    // Test search functionality
    $('#testSearch').on('input', function() {
        const searchTerm = $(this).val().toLowerCase();
        
        if (searchTerm.length === 0) {
            $('.test-item').show();
            $('.category-section').show();
            return;
        }
        
        $('.category-section').each(function() {
            const $category = $(this);
            let hasVisibleTests = false;
            
            $category.find('.test-item').each(function() {
                const $test = $(this);
                const testName = $test.data('test-name');
                const testCode = $test.data('test-code');
                const category = $test.data('category');
                
                if (testName.includes(searchTerm) || testCode.includes(searchTerm) || category.includes(searchTerm)) {
                    $test.show();
                    hasVisibleTests = true;
                } else {
                    $test.hide();
                }
            });
            
            $category.toggle(hasVisibleTests);
        });
    });

    // Test selection event
    $('.test-checkbox').change(function() {
        updateSelectedTests();
    });

    // Initialize selected tests count
    updateSelectedTests();
});

function updateVisitsForPatient() {
    const patientId = $('#lab_order_patient_id').val();
    const $visitSelect = $('#visit_id');
    
    if (patientId) {
        // Show only visits for the selected patient
        $visitSelect.find('option').each(function() {
            const $option = $(this);
            const optionPatientId = $option.data('patient-id');
            
            if (optionPatientId) {
                if (optionPatientId == patientId) {
                    $option.show();
                } else {
                    $option.hide();
                    // If this option was selected, clear it
                    if ($option.prop('selected')) {
                        $visitSelect.val('').trigger('change');
                    }
                }
            }
        });
        
        // If only one visit available for this patient, auto-select it
        const availableVisits = $visitSelect.find('option:visible').not('[value=""]');
        if (availableVisits.length === 1) {
            $visitSelect.val(availableVisits.val()).trigger('change');
            showAlert('Auto-linked to the available visit for this patient.', 'info');
        }
    } else {
        // Show all visits when no patient is selected
        $visitSelect.find('option').show();
    }
    
    // Refresh Select2 to update visible options
    $visitSelect.trigger('change.select2');
}

function clearTestSearch() {
    $('#testSearch').val('').trigger('input');
}

function unselectTest(testId) {
    $(`#test_${testId}`).prop('checked', false).trigger('change');
}

function clearAllTests() {
    if (confirm('Are you sure you want to clear all selected tests?')) {
        $('.test-checkbox').prop('checked', false).trigger('change');
    }
}

function loadTestPanel(panelType) {
    // Define common test panels with test codes or IDs
    const panels = {
        'basic_metabolic': ['GLU', 'BUN', 'CRE', 'NA', 'K', 'CL', 'CO2', 'CA'],
        'complete_blood_count': ['WBC', 'RBC', 'HGB', 'HCT', 'PLT', 'MCV'],
        'liver_function': ['ALT', 'AST', 'ALP', 'TBIL', 'DBIL', 'ALB', 'TP'],
        'thyroid': ['TSH', 'FT4', 'FT3', 'T3'],
        'lipid': ['CHOL', 'TRIG', 'HDL', 'LDL'],
        'renal': ['BUN', 'CRE', 'EGFR', 'UA'],
        'diabetes': ['GLU', 'HBA1C', 'INSULIN', 'CPEP']
    };

    const panelTests = panels[panelType] || [];
    
    if (panelTests.length === 0) {
        alert('Panel not found or no tests defined for this panel.');
        return;
    }

    // Clear current selection if confirmed
    if ($('.test-checkbox:checked').length > 0) {
        if (!confirm('This will replace your current test selection. Continue?')) {
            return;
        }
        clearAllTests();
    }

    // Select tests that match the panel codes
    let foundTests = 0;
    panelTests.forEach(testCode => {
        $('.test-item').each(function() {
            const $test = $(this);
            const actualTestCode = $test.data('test-code');
            if (actualTestCode.includes(testCode.toLowerCase())) {
                $test.find('.test-checkbox').prop('checked', true);
                foundTests++;
            }
        });
    });

    // Update the display
    $('.test-checkbox').trigger('change');
    
    if (foundTests > 0) {
        showAlert(`${panelType.replace('_', ' ').toUpperCase()} panel loaded with ${foundTests} tests!`, 'success');
    } else {
        showAlert('No matching tests found for this panel.', 'warning');
    }
}

function showAlert(message, type) {
    const alertClass = type === 'success' ? 'alert-success' : 
                      type === 'info' ? 'alert-info' : 'alert-warning';
    const icon = type === 'success' ? 'check' : 
                type === 'info' ? 'info-circle' : 'exclamation-triangle';
    
    const alertHtml = `
        <div class="alert ${alertClass} alert-dismissible fade show" role="alert">
            <i class="fas fa-${icon} mr-2"></i>
            ${message}
            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
    `;
    
    // Remove existing alerts and prepend new one
    $('.alert-dismissible').remove();
    $('#createOrderForm').prepend(alertHtml);
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + S to save
    if (e.ctrlKey && e.keyCode === 83) {
        e.preventDefault();
        $('#createOrderForm').submit();
    }
    // Escape to cancel
    if (e.keyCode === 27) {
        window.location.href = 'lab_orders.php';
    }
    // Ctrl + F to focus search
    if (e.ctrlKey && e.keyCode === 70) {
        e.preventDefault();
        $('#testSearch').focus();
    }
});

// Form validation
$('#createOrderForm').submit(function(e) {
    const patientId = $('#lab_order_patient_id').val();
    const doctorId = $('#ordering_doctor_id').val();
    const selectedTests = $('.test-checkbox:checked').length;
    
    if (!patientId || !doctorId || selectedTests === 0) {
        e.preventDefault();
        alert('Please fill in all required fields and select at least one test.');
        return false;
    }
    
    // Show loading state
    $('button[type="submit"]').html('<i class="fas fa-spinner fa-spin mr-2"></i>Creating...').prop('disabled', true);
});
</script>

<?php 
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
    ?>
    