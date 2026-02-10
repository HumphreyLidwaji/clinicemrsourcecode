<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Handle form submissions via POST
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_imaging'])) {
        $imaging_code = sanitizeInput($_POST['imaging_code']);
        $imaging_name = sanitizeInput($_POST['imaging_name']);
        $imaging_description = sanitizeInput($_POST['imaging_description']);
        $modality = sanitizeInput($_POST['modality']);
        $body_part = sanitizeInput($_POST['body_part']);
        $preparation_instructions = sanitizeInput($_POST['preparation_instructions']);
        $contrast_required = sanitizeInput($_POST['contrast_required']);
        $fee_amount = floatval($_POST['fee_amount']);
        $duration_minutes = intval($_POST['duration_minutes']);
        $radiation_dose = sanitizeInput($_POST['radiation_dose']);
        $report_template = sanitizeInput($_POST['report_template']);
        
        $stmt = $mysqli->prepare("INSERT INTO radiology_imagings SET imaging_code=?, imaging_name=?, imaging_description=?, modality=?, body_part=?, preparation_instructions=?, contrast_required=?, fee_amount=?, duration_minutes=?, radiation_dose=?, report_template=?, is_active=1");
        $stmt->bind_param("sssssssdiss", $imaging_code, $imaging_name, $imaging_description, $modality, $body_part, $preparation_instructions, $contrast_required, $fee_amount, $duration_minutes, $radiation_dose, $report_template);
        
        if ($stmt->execute()) {
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Radiology imaging added successfully!";
        } else {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error adding radiology imaging: " . $mysqli->error;
        }
        $stmt->close();
    }
    
    elseif (isset($_POST['update_imaging'])) {
        $imaging_id = intval($_POST['imaging_id']);
        $imaging_code = sanitizeInput($_POST['imaging_code']);
        $imaging_name = sanitizeInput($_POST['imaging_name']);
        $imaging_description = sanitizeInput($_POST['imaging_description']);
        $modality = sanitizeInput($_POST['modality']);
        $body_part = sanitizeInput($_POST['body_part']);
        $preparation_instructions = sanitizeInput($_POST['preparation_instructions']);
        $contrast_required = sanitizeInput($_POST['contrast_required']);
        $fee_amount = floatval($_POST['fee_amount']);
        $duration_minutes = intval($_POST['duration_minutes']);
        $radiation_dose = sanitizeInput($_POST['radiation_dose']);
        $report_template = sanitizeInput($_POST['report_template']);
        
        $stmt = $mysqli->prepare("UPDATE radiology_imagings SET imaging_code=?, imaging_name=?, imaging_description=?, modality=?, body_part=?, preparation_instructions=?, contrast_required=?, fee_amount=?, duration_minutes=?, radiation_dose=?, report_template=? WHERE imaging_id=?");
        $stmt->bind_param("sssssssdisssi", $imaging_code, $imaging_name, $imaging_description, $modality, $body_part, $preparation_instructions, $contrast_required, $fee_amount, $duration_minutes, $radiation_dose, $report_template, $imaging_id);
        
        if ($stmt->execute()) {
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Radiology imaging updated successfully!";
        } else {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error updating radiology imaging: " . $mysqli->error;
        }
        $stmt->close();
    }
    
    // Redirect to prevent form resubmission
    header("Location: radiology_imaging.php");
    exit;
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ajax_request'])) {
    header('Content-Type: application/json');
    
    if (isset($_POST['get_imaging_details'])) {
        $imaging_id = intval($_POST['imaging_id']);
        $imaging = $mysqli->query("SELECT * FROM radiology_imagings WHERE imaging_id = $imaging_id")->fetch_assoc();
        echo json_encode($imaging);
        exit;
    }
    
    if (isset($_POST['delete_imaging'])) {
        $imaging_id = intval($_POST['imaging_id']);
        $stmt = $mysqli->prepare("UPDATE radiology_imagings SET is_active = 0 WHERE imaging_id = ?");
        $stmt->bind_param("i", $imaging_id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Radiology imaging deleted successfully!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error deleting radiology imaging: ' . $mysqli->error]);
        }
        $stmt->close();
        exit;
    }
}

// Default Column Sortby/Order Filter
$sort = "ri.imaging_name";
$order = "ASC";

// Filter parameters
$modality_filter = $_GET['modality'] ?? '';
$body_part_filter = $_GET['body_part'] ?? '';
$active_filter = $_GET['active'] ?? '';

// Date Range Filter
$dtf = sanitizeInput($_GET['dtf'] ?? '');
$dtt = sanitizeInput($_GET['dtt'] ?? '');

// Search Query
$q = sanitizeInput($_GET['q'] ?? '');
if (!empty($q)) {
    $search_query = "AND (
        ri.imaging_code LIKE '%$q%' 
        OR ri.imaging_name LIKE '%$q%'
        OR ri.imaging_description LIKE '%$q%'
        OR ri.body_part LIKE '%$q%'
    )";
} else {
    $search_query = '';
}

// Build WHERE clause for filters
$where_conditions = ["1=1"];
$params = [];
$types = '';

if (!empty($modality_filter)) {
    $where_conditions[] = "ri.modality = ?";
    $params[] = $modality_filter;
    $types .= 's';
}

if (!empty($body_part_filter)) {
    $where_conditions[] = "ri.body_part = ?";
    $params[] = $body_part_filter;
    $types .= 's';
}

if ($active_filter === 'inactive') {
    $where_conditions[] = "ri.is_active = 0";
} else {
    $where_conditions[] = "ri.is_active = 1";
}

$where_clause = implode(' AND ', $where_conditions);

// Get all radiology imaging
$imaging_sql = "
    SELECT SQL_CALC_FOUND_ROWS ri.* 
    FROM radiology_imagings ri 
    WHERE $where_clause
    $search_query
    ORDER BY $sort $order
    LIMIT $record_from, $record_to
";

$imaging_stmt = $mysqli->prepare($imaging_sql);
if (!empty($params)) {
    $imaging_stmt->bind_param($types, ...$params);
}
$imaging_stmt->execute();
$imaging = $imaging_stmt->get_result();

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Get unique modalities for filter
$modalities = $mysqli->query("SELECT DISTINCT modality FROM radiology_imagings WHERE modality IS NOT NULL ORDER BY modality");

// Get unique body parts for filter
$body_parts = $mysqli->query("SELECT DISTINCT body_part FROM radiology_imagings WHERE body_part IS NOT NULL ORDER BY body_part");

// Get statistics
$total_imaging = $mysqli->query("SELECT COUNT(*) FROM radiology_imagings WHERE is_active = 1")->fetch_row()[0];
$inactive_imaging = $mysqli->query("SELECT COUNT(*) FROM radiology_imagings WHERE is_active = 0")->fetch_row()[0];
$total_value = $mysqli->query("SELECT SUM(fee_amount) as total FROM radiology_imagings WHERE is_active = 1")->fetch_assoc()['total'] ?? 0;
$avg_duration = $mysqli->query("SELECT AVG(duration_minutes) as avg_duration FROM radiology_imagings WHERE is_active = 1")->fetch_assoc()['avg_duration'] ?? 0;
$ct_count = $mysqli->query("SELECT COUNT(*) FROM radiology_imagings WHERE modality = 'CT' AND is_active = 1")->fetch_row()[0];

// Reset pointer for main query
$imaging->data_seek(0);
?>

<div class="card">
    <div class="card-header bg-info py-2">
        <h3 class="card-title mt-2 mb-0"><i class="fas fa-fw fa-x-ray mr-2"></i>Radiology Imaging Management</h3>
        <div class="card-tools">
            <?php if (SimplePermission::any("module_radiology" ,"radiology_create_image")){ ?>
            <a href="radiology_imaging_add.php" class="btn btn-success">
                <i class="fas fa-plus mr-2"></i>New Imaging
            </a>
             <?php } ?>
              <?php if (SimplePermission::any("radiology_view_order")) { ?>
             <a href="radiology_imaging_orders.php" class="btn btn-warning">
                <i class="fas fa-plus mr-2"></i>Orders
            </a>
            <?php } ?>
             <?php if (SimplePermission::any("radiology_view_report'")) {?>
             <a href="radiology_reports.php" class="btn btn-primary">
                <i class="fas fa-plus mr-2"></i>Reports
            </a>
            <?php } ?>
        </div>
    </div>

    <div class="card-header pb-2 pt-3">
        <form autocomplete="off">
            <div class="row">
                <div class="col-md-5">
                    <div class="form-group">
                        <div class="input-group">
                            <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search imaging, codes, body parts..." autofocus>
                            <div class="input-group-append">
                                <button class="btn btn-secondary" type="button" data-toggle="collapse" data-target="#advancedFilter"><i class="fas fa-filter"></i></button>
                                <button class="btn btn-primary"><i class="fa fa-search"></i></button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-7">
                    <div class="btn-toolbar form-group float-right">
                        <div class="btn-group">
                            <span class="btn btn-light border">
                                <i class="fas fa-x-ray text-info mr-1"></i>
                                Active: <strong><?php echo $total_imaging; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-clock text-warning mr-1"></i>
                                Avg Time: <strong><?php echo number_format($avg_duration, 0); ?>m</strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-dollar-sign text-success mr-1"></i>
                                Value: <strong>$<?php echo number_format($total_value, 2); ?></strong>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse <?php if ($modality_filter || $body_part_filter || isset($_GET['dtf'])) { echo "show"; } ?>" id="advancedFilter">
                <div class="row">
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Status</label>
                            <select class="form-control select2" name="active" onchange="this.form.submit()">
                                <option value="active" <?php if ($active_filter !== 'inactive') echo "selected"; ?>>Active Imaging</option>
                                <option value="inactive" <?php if ($active_filter === 'inactive') echo "selected"; ?>>Inactive Imaging</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Date Range</label>
                            <select onchange="this.form.submit()" class="form-control select2" name="canned_date">
                                <option <?php if (($_GET['canned_date'] ?? '') == "custom") { echo "selected"; } ?> value="custom">Custom</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "today") { echo "selected"; } ?> value="today">Today</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "yesterday") { echo "selected"; } ?> value="yesterday">Yesterday</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "thisweek") { echo "selected"; } ?> value="thisweek">This Week</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "lastweek") { echo "selected"; } ?> value="lastweek">Last Week</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "thismonth") { echo "selected"; } ?> value="thismonth">This Month</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "lastmonth") { echo "selected"; } ?> value="lastmonth">Last Month</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Date from</label>
                            <input onchange="this.form.submit()" type="date" class="form-control" name="dtf" max="2999-12-31" value="<?php echo nullable_htmlentities($dtf); ?>">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Date to</label>
                            <input onchange="this.form.submit()" type="date" class="form-control" name="dtt" max="2999-12-31" value="<?php echo nullable_htmlentities($dtt); ?>">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Modality</label>
                            <select class="form-control select2" name="modality" onchange="this.form.submit()">
                                <option value="">- All Modalities -</option>
                                <?php while($modality = $modalities->fetch_assoc()): ?>
                                    <option value="<?php echo htmlspecialchars($modality['modality']); ?>" <?php if ($modality_filter == $modality['modality']) { echo "selected"; } ?>>
                                        <?php echo htmlspecialchars($modality['modality']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Body Part</label>
                            <select class="form-control select2" name="body_part" onchange="this.form.submit()">
                                <option value="">- All Body Parts -</option>
                                <?php while($body_part = $body_parts->fetch_assoc()): ?>
                                    <option value="<?php echo htmlspecialchars($body_part['body_part']); ?>" <?php if ($body_part_filter == $body_part['body_part']) { echo "selected"; } ?>>
                                        <?php echo htmlspecialchars($body_part['body_part']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </form>
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

        <!-- Alert Container for AJAX Messages -->
        <div id="ajaxAlertContainer"></div>
    
        <div class="table-responsive-sm">
            <table class="table table-hover mb-0">
                <thead class="<?php if ($num_rows[0] == 0) { echo "d-none"; } ?> bg-light">
                <tr>
                    <th>
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=ri.imaging_code&order=<?php echo $disp; ?>">
                            Imaging Code <?php if ($sort == 'ri.imaging_code') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=ri.imaging_name&order=<?php echo $disp; ?>">
                            Imaging Name <?php if ($sort == 'ri.imaging_name') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=ri.modality&order=<?php echo $disp; ?>">
                            Modality <?php if ($sort == 'ri.modality') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>Body Part</th>
                    <th>
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=ri.fee_amount&order=<?php echo $disp; ?>">
                            Fee <?php if ($sort == 'ri.fee_amount') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=ri.duration_minutes&order=<?php echo $disp; ?>">
                            Duration <?php if ($sort == 'ri.duration_minutes') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>Contrast</th>
                    <th class="text-center">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php while($img = $imaging->fetch_assoc()): 
                    $imaging_id = intval($img['imaging_id']);
                    $imaging_code = nullable_htmlentities($img['imaging_code']);
                    $imaging_name = nullable_htmlentities($img['imaging_name']);
                    $imaging_description = nullable_htmlentities($img['imaging_description']);
                    $modality = nullable_htmlentities($img['modality']);
                    $body_part = nullable_htmlentities($img['body_part']);
                    $fee_amount = floatval($img['fee_amount']);
                    $duration_minutes = intval($img['duration_minutes']);
                    $contrast_required = nullable_htmlentities($img['contrast_required']);
                    $is_active = intval($img['is_active']);
                    ?>
                    <tr class="<?php echo $is_active ? '' : 'text-muted bg-light'; ?>">
                        <td class="font-weight-bold text-info"><?php echo $imaging_code; ?></td>
                        <td>
                            <div class="font-weight-bold"><?php echo $imaging_name; ?></div>
                            <?php if ($imaging_description): ?>
                                <small class="text-muted"><?php echo strlen($imaging_description) > 50 ? substr($imaging_description, 0, 50) . '...' : $imaging_description; ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge-primary"><?php echo $modality; ?></span>
                        </td>
                        <td>
                            <small class="text-muted"><?php echo $body_part ?: 'Not specified'; ?></small>
                        </td>
                        <td>
                            <span class="font-weight-bold text-success">$<?php echo number_format($fee_amount, 2); ?></span>
                        </td>
                        <td>
                            <span class="badge badge-secondary"><?php echo $duration_minutes; ?> min</span>
                        </td>
                        <td>
                            <?php if ($contrast_required && $contrast_required !== 'None'): ?>
                                <span class="badge badge-warning"><?php echo $contrast_required; ?></span>
                            <?php else: ?>
                                <span class="text-muted">None</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="dropdown dropleft text-center">
                                <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                    <i class="fas fa-ellipsis-h"></i>
                                </button>
                                <div class="dropdown-menu">
                                      <?php if (SimplePermission::any("radiology_view_image")){ ?>
                                    <a class="dropdown-item" href="radiology_imaging_details.php?imaging_id=<?php echo $imaging_id; ?>">
                                        <i class="fas fa-fw fa-eye mr-2"></i>View Details
                                    </a>
                                    <?php } ?>
                                    <?php if (SimplePermission::any("radiology_edit_image")){ ?>
                                    <a class="dropdown-item" href="radiology_imaging_edit.php?imaging_id=<?php echo $imaging_id; ?>">
                                        <i class="fas fa-fw fa-edit mr-2"></i>Edit Imaging
                                    </a>
                                         
                                    <?php } ?>
                                    <?php if ($is_active): ?>
                                        <div class="dropdown-divider"></div>
                                        <?php if (SimplePermission::any("radiology_delete_image")){ ?>
                                        <a class="dropdown-item text-danger" href="#" onclick="deleteImaging(<?php echo $imaging_id; ?>)">
                                            <i class="fas fa-fw fa-trash mr-2"></i>Delete Imaging
                                        </a>
                                                 
                                    <?php } ?>
                                    <?php else: ?>
                                        <?php if (SimplePermission::any("radiology_restore_image")){ ?>
                                        <a class="dropdown-item text-success" href="#" onclick="restoreImaging(<?php echo $imaging_id; ?>)">
                                            <i class="fas fa-fw fa-undo mr-2"></i>Restore Imaging
                                        </a>
                                             
                                    <?php } ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; ?>

                <?php if ($num_rows[0] === 0): ?>
                    <tr>
                        <td colspan="8" class="text-center py-4">
                            <i class="fas fa-x-ray fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted">No Radiology Imaging Found</h5>
                            <p class="text-muted">
                                <?php echo ($modality_filter || $body_part_filter || $search_query) ? 
                                    'Try adjusting your filters or search criteria.' : 
                                    'Get started by creating your first radiology imaging.'; 
                                ?>
                            </p>
                            <a href="radiology_imaging_add.php" class="btn btn-primary mt-2">
                                <i class="fas fa-plus mr-2"></i>Create First Imaging
                            </a>
                            <?php if ($modality_filter || $body_part_filter || $search_query): ?>
                                <a href="radiology_imaging.php" class="btn btn-outline-secondary mt-2 ml-2">
                                    <i class="fas fa-times mr-2"></i>Clear Filters
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Ends Card Body -->
        <?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/filter_footer.php'; ?>
    </div>
</div>

<script>
$(document).ready(function() {
    $('.select2').select2();

    // Auto-submit when date range changes
    $('input[type="date"]').change(function() {
        if ($(this).val()) {
            $(this).closest('form').submit();
        }
    });

    // Auto-submit date range when canned date is selected
    $('select[name="canned_date"]').change(function() {
        if ($(this).val() !== 'custom') {
            $(this).closest('form').submit();
        }
    });

    // Auto-close alerts after 5 seconds
    setTimeout(() => {
        $('.alert').alert('close');
    }, 5000);
});

function deleteImaging(imagingId) {
    if (confirm('Are you sure you want to delete this radiology imaging? This action cannot be undone.')) {
        $.ajax({
            url: 'radiology_imaging.php',
            type: 'POST',
            data: {
                ajax_request: 1,
                delete_imaging: 1,
                imaging_id: imagingId
            },
            success: function(response) {
                const result = JSON.parse(response);
                if (result.success) {
                    showAlert(result.message, 'success');
                    // Reload page after successful deletion
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else {
                    showAlert(result.message, 'error');
                }
            },
            error: function() {
                showAlert('Error deleting radiology imaging. Please try again.', 'error');
            }
        });
    }
}

function restoreImaging(imagingId) {
    if (confirm('Are you sure you want to restore this radiology imaging?')) {
        $.ajax({
            url: 'radiology_imaging.php',
            type: 'POST',
            data: {
                ajax_request: 1,
                restore_imaging: 1,
                imaging_id: imagingId
            },
            success: function(response) {
                const result = JSON.parse(response);
                if (result.success) {
                    showAlert(result.message, 'success');
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else {
                    showAlert(result.message, 'error');
                }
            },
            error: function() {
                showAlert('Error restoring radiology imaging. Please try again.', 'error');
            }
        });
    }
}

function showAlert(message, type) {
    const alertClass = type === 'success' ? 'alert-success' : 'alert-danger';
    const iconClass = type === 'success' ? 'fa-check' : 'fa-exclamation-triangle';
    
    const alertHtml = `
        <div class="alert ${alertClass} alert-dismissible">
            <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
            <i class="icon fas ${iconClass}"></i>
            ${message}
        </div>
    `;
    
    $('#ajaxAlertContainer').html(alertHtml);
    
    // Auto-dismiss success alerts after 5 seconds
    if (type === 'success') {
        setTimeout(() => {
            $('.alert').alert('close');
        }, 5000);
    }
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + N for new imaging
    if (e.ctrlKey && e.keyCode === 78) {
        e.preventDefault();
        window.location.href = 'radiology_imaging_add.php';
    }
    // Ctrl + F for focus search
    if (e.ctrlKey && e.keyCode === 70) {
        e.preventDefault();
        $('input[name="q"]').focus();
    }
});
</script>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>