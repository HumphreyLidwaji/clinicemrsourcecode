<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Handle form submissions via POST
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_service'])) {
        $service_code = sanitizeInput($_POST['service_code']);
        $service_name = sanitizeInput($_POST['service_name']);
        $service_description = sanitizeInput($_POST['service_description']);
        $service_category_id = intval($_POST['service_category_id']);
        $service_type = sanitizeInput($_POST['service_type']);
        $fee_amount = floatval($_POST['fee_amount']);
        $duration_minutes = intval($_POST['duration_minutes']);
        $tax_rate = floatval($_POST['tax_rate']);
        $requires_doctor = isset($_POST['requires_doctor']) ? 1 : 0;
        $insurance_billable = isset($_POST['insurance_billable']) ? 1 : 0;
        $medical_code = sanitizeInput($_POST['medical_code']);
        
        $stmt = $mysqli->prepare("INSERT INTO medical_services SET service_code=?, service_name=?, service_description=?, service_category_id=?, service_type=?, fee_amount=?, duration_minutes=?, tax_rate=?, requires_doctor=?, insurance_billable=?, medical_code=?, is_active=1");
        $stmt->bind_param("sssisdiisiis", $service_code, $service_name, $service_description, $service_category_id, $service_type, $fee_amount, $duration_minutes, $tax_rate, $requires_doctor, $insurance_billable, $medical_code);
        
        if ($stmt->execute()) {
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Medical service added successfully!";
        } else {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error adding medical service: " . $mysqli->error;
        }
        $stmt->close();
    }
    
    elseif (isset($_POST['update_service'])) {
        $medical_service_id = intval($_POST['medical_service_id']);
        $service_code = sanitizeInput($_POST['service_code']);
        $service_name = sanitizeInput($_POST['service_name']);
        $service_description = sanitizeInput($_POST['service_description']);
        $service_category_id = intval($_POST['service_category_id']);
        $service_type = sanitizeInput($_POST['service_type']);
        $fee_amount = floatval($_POST['fee_amount']);
        $duration_minutes = intval($_POST['duration_minutes']);
        $tax_rate = floatval($_POST['tax_rate']);
        $requires_doctor = isset($_POST['requires_doctor']) ? 1 : 0;
        $insurance_billable = isset($_POST['insurance_billable']) ? 1 : 0;
        $medical_code = sanitizeInput($_POST['medical_code']);
        
        $stmt = $mysqli->prepare("UPDATE medical_services SET service_code=?, service_name=?, service_description=?, service_category_id=?, service_type=?, fee_amount=?, duration_minutes=?, tax_rate=?, requires_doctor=?, insurance_billable=?, medical_code=? WHERE medical_service_id=?");
        $stmt->bind_param("sssisdiisisi", $service_code, $service_name, $service_description, $service_category_id, $service_type, $fee_amount, $duration_minutes, $tax_rate, $requires_doctor, $insurance_billable, $medical_code, $medical_service_id);
        
        if ($stmt->execute()) {
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Medical service updated successfully!";
        } else {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error updating medical service: " . $mysqli->error;
        }
        $stmt->close();
    }
    
    // Handle AJAX deletions/restorations
    if (isset($_POST['ajax_request'])) {
        header('Content-Type: application/json');
        
        if (isset($_POST['delete_service'])) {
            $medical_service_id = intval($_POST['medical_service_id']);
            $stmt = $mysqli->prepare("UPDATE medical_services SET is_active = 0 WHERE medical_service_id = ?");
            $stmt->bind_param("i", $medical_service_id);
            
            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'message' => 'Medical service deactivated successfully!']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error deactivating medical service: ' . $mysqli->error]);
            }
            $stmt->close();
            exit;
        }
        
        if (isset($_POST['restore_service'])) {
            $medical_service_id = intval($_POST['medical_service_id']);
            $stmt = $mysqli->prepare("UPDATE medical_services SET is_active = 1 WHERE medical_service_id = ?");
            $stmt->bind_param("i", $medical_service_id);
            
            if ($stmt->execute()) {
                echo json_encode(['success' => true, 'message' => 'Medical service restored successfully!']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error restoring medical service: ' . $mysqli->error]);
            }
            $stmt->close();
            exit;
        }
        
        if (isset($_POST['get_service_details'])) {
            $medical_service_id = intval($_POST['medical_service_id']);
            $service = $mysqli->query("SELECT * FROM medical_services WHERE medical_service_id = $medical_service_id")->fetch_assoc();
            echo json_encode($service);
            exit;
        }
    }
    
    // Redirect to prevent form resubmission
    header("Location: medical_services.php");
    exit;
}

// Default Column Sortby/Order Filter
$sort = "ms.service_name";
$order = "ASC";

// Filter parameters
$category_filter = $_GET['category'] ?? '';
$type_filter = $_GET['type'] ?? '';
$active_filter = $_GET['active'] ?? '';

// Date Range Filter
$dtf = sanitizeInput($_GET['dtf'] ?? '');
$dtt = sanitizeInput($_GET['dtt'] ?? '');

// Search Query
$q = sanitizeInput($_GET['q'] ?? '');
if (!empty($q)) {
    $search_query = "AND (
        ms.service_code LIKE '%$q%' 
        OR ms.service_name LIKE '%$q%'
        OR ms.service_description LIKE '%$q%'
        OR ms.medical_code LIKE '%$q%'
        OR msc.category_name LIKE '%$q%'
    )";
} else {
    $search_query = '';
}

// Build WHERE clause for filters
$where_conditions = ["1=1"];
$params = [];
$types = '';

if (!empty($category_filter)) {
    $where_conditions[] = "ms.service_category_id = ?";
    $params[] = $category_filter;
    $types .= 'i';
}

if (!empty($type_filter)) {
    $where_conditions[] = "ms.service_type = ?";
    $params[] = $type_filter;
    $types .= 's';
}

if ($active_filter === 'inactive') {
    $where_conditions[] = "ms.is_active = 0";
} else {
    $where_conditions[] = "ms.is_active = 1";
}

$where_clause = implode(' AND ', $where_conditions);

// Get all medical services with categories and linked components count
$services_sql = "
    SELECT SQL_CALC_FOUND_ROWS ms.*, msc.category_name,
    (SELECT COUNT(*) FROM service_components sc WHERE sc.medical_service_id = ms.medical_service_id) as linked_components_count,
    (SELECT COUNT(*) FROM service_inventory_items sii WHERE sii.medical_service_id = ms.medical_service_id) as linked_inventory_count,
    (SELECT COUNT(*) FROM service_accounts sa WHERE sa.medical_service_id = ms.medical_service_id) as linked_accounts_count
    FROM medical_services ms 
    LEFT JOIN medical_service_categories msc ON ms.service_category_id = msc.category_id 
    WHERE $where_clause
    $search_query
    ORDER BY $sort $order
    LIMIT $record_from, $record_to
";

$services_stmt = $mysqli->prepare($services_sql);
if (!empty($params)) {
    $services_stmt->bind_param($types, ...$params);
}
$services_stmt->execute();
$services = $services_stmt->get_result();

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Get categories for filter dropdown
$categories = $mysqli->query("SELECT * FROM medical_service_categories ");

// Get unique service types for filter
$service_types = $mysqli->query("SELECT DISTINCT service_type FROM medical_services WHERE service_type IS NOT NULL ORDER BY service_type");

// Get statistics
$total_services = $mysqli->query("SELECT COUNT(*) FROM medical_services WHERE is_active = 1")->fetch_row()[0];
$inactive_services = $mysqli->query("SELECT COUNT(*) FROM medical_services WHERE is_active = 0")->fetch_row()[0];
$total_value = $mysqli->query("SELECT SUM(fee_amount) as total FROM medical_services WHERE is_active = 1")->fetch_assoc()['total'] ?? 0;
$avg_duration = $mysqli->query("SELECT AVG(duration_minutes) as avg_duration FROM medical_services WHERE is_active = 1")->fetch_assoc()['avg_duration'] ?? 0;

// Get service type statistics
$consultation_count = $mysqli->query("SELECT COUNT(*) FROM medical_services WHERE service_type = 'Consultation' AND is_active = 1")->fetch_row()[0];
$procedure_count = $mysqli->query("SELECT COUNT(*) FROM medical_services WHERE service_type = 'Procedure' AND is_active = 1")->fetch_row()[0];
$labtest_count = $mysqli->query("SELECT COUNT(*) FROM medical_services WHERE service_type = 'LabTest' AND is_active = 1")->fetch_row()[0];
$imaging_count = $mysqli->query("SELECT COUNT(*) FROM medical_services WHERE service_type = 'Imaging' AND is_active = 1")->fetch_row()[0];
$vaccination_count = $mysqli->query("SELECT COUNT(*) FROM medical_services WHERE service_type = 'Vaccination' AND is_active = 1")->fetch_row()[0];
$package_count = $mysqli->query("SELECT COUNT(*) FROM medical_services WHERE service_type = 'Package' AND is_active = 1")->fetch_row()[0];
$bed_count = $mysqli->query("SELECT COUNT(*) FROM medical_services WHERE service_type = 'Bed' AND is_active = 1")->fetch_row()[0];

// Reset pointer for main query
$services->data_seek(0);
?>

<div class="card">
    <div class="card-header bg-success py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0">
                <i class="fas fa-fw fa-stethoscope mr-2"></i>Medical Services Management
            </h3>
            <div class="card-tools">
                <a href="medical_service_add.php" class="btn btn-light">
                    <i class="fas fa-plus mr-2"></i>New Service
                </a>
            </div>
        </div>
    </div>

    <div class="card-header pb-2 pt-3">
        <form autocomplete="off">
            <div class="row">
                <div class="col-md-5">
                    <div class="form-group">
                        <div class="input-group">
                            <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search services, codes, descriptions..." autofocus>
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
                                <i class="fas fa-stethoscope text-success mr-1"></i>
                                Active: <strong><?php echo $total_services; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-clock text-warning mr-1"></i>
                                Avg Time: <strong><?php echo number_format($avg_duration, 0); ?>m</strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-dollar-sign text-primary mr-1"></i>
                                Value: <strong>$<?php echo number_format($total_value, 2); ?></strong>
                            </span>
                            <div class="btn-group">
                                <button type="button" class="btn btn-info dropdown-toggle" data-toggle="dropdown">
                                    <i class="fas fa-fw fa-chart-pie mr-1"></i>Analytics
                                </button>
                                <div class="dropdown-menu dropdown-menu-right p-3" style="width: 350px;">
                                    <h6 class="dropdown-header">Service Type Distribution</h6>
                                    <div class="row small">
                                        <div class="col-6">
                                            <div class="mb-2">
                                                <span class="badge badge-primary">Consultation:</span>
                                                <span class="float-right"><?php echo $consultation_count; ?></span>
                                            </div>
                                            <div class="mb-2">
                                                <span class="badge badge-warning">Procedure:</span>
                                                <span class="float-right"><?php echo $procedure_count; ?></span>
                                            </div>
                                            <div class="mb-2">
                                                <span class="badge badge-info">Lab Test:</span>
                                                <span class="float-right"><?php echo $labtest_count; ?></span>
                                            </div>
                                        </div>
                                        <div class="col-6">
                                            <div class="mb-2">
                                                <span class="badge badge-success">Imaging:</span>
                                                <span class="float-right"><?php echo $imaging_count; ?></span>
                                            </div>
                                            <div class="mb-2">
                                                <span class="badge badge-success">Vaccination:</span>
                                                <span class="float-right"><?php echo $vaccination_count; ?></span>
                                            </div>
                                            <div class="mb-2">
                                                <span class="badge badge-danger">Bed:</span>
                                                <span class="float-right"><?php echo $bed_count; ?></span>
                                            </div>
                                            <div class="mb-2">
                                                <span class="badge badge-info">Package:</span>
                                                <span class="float-right"><?php echo $package_count; ?></span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="dropdown-divider"></div>
                                    <div class="small text-center">
                                        <a href="medical_service_categories.php" class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-fw fa-folder mr-1"></i>Categories
                                        </a>
                                        <a href="medical_services.php?active=inactive" class="btn btn-sm btn-outline-secondary ml-1">
                                            <i class="fas fa-fw fa-archive mr-1"></i>Inactive (<?php echo $inactive_services; ?>)
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse <?php if ($category_filter || $type_filter || isset($_GET['dtf'])) { echo "show"; } ?>" id="advancedFilter">
                <div class="row">
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Status</label>
                            <select class="form-control select2" name="active" onchange="this.form.submit()">
                                <option value="active" <?php if ($active_filter !== 'inactive') echo "selected"; ?>>Active Services</option>
                                <option value="inactive" <?php if ($active_filter === 'inactive') echo "selected"; ?>>Inactive Services</option>
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
                            <label>Category</label>
                            <select class="form-control select2" name="category" onchange="this.form.submit()">
                                <option value="">- All Categories -</option>
                                <?php while($cat = $categories->fetch_assoc()): ?>
                                    <option value="<?php echo $cat['category_id']; ?>" <?php if ($category_filter == $cat['category_id']) { echo "selected"; } ?>>
                                        <?php echo htmlspecialchars($cat['category_name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Service Type</label>
                            <select class="form-control select2" name="type" onchange="this.form.submit()">
                                <option value="">- All Types -</option>
                                <?php while($type = $service_types->fetch_assoc()): ?>
                                    <option value="<?php echo htmlspecialchars($type['service_type']); ?>" <?php if ($type_filter == $type['service_type']) { echo "selected"; } ?>>
                                        <?php echo htmlspecialchars($type['service_type']); ?>
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
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=ms.service_code&order=<?php echo $disp; ?>">
                            Service Code <?php if ($sort == 'ms.service_code') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=ms.service_name&order=<?php echo $disp; ?>">
                            Service Name <?php if ($sort == 'ms.service_name') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=msc.category_name&order=<?php echo $disp; ?>">
                            Category <?php if ($sort == 'msc.category_name') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=ms.service_type&order=<?php echo $disp; ?>">
                            Type <?php if ($sort == 'ms.service_type') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=ms.fee_amount&order=<?php echo $disp; ?>">
                            Fee <?php if ($sort == 'ms.fee_amount') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=ms.duration_minutes&order=<?php echo $disp; ?>">
                            Duration <?php if ($sort == 'ms.duration_minutes') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>Linked Items</th>
                    <th class="text-center">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php while($service = $services->fetch_assoc()): 
                    $medical_service_id = intval($service['medical_service_id']);
                    $service_code = nullable_htmlentities($service['service_code']);
                    $service_name = nullable_htmlentities($service['service_name']);
                    $service_description = nullable_htmlentities($service['service_description']);
                    $category_name = nullable_htmlentities($service['category_name']);
                    $service_type = nullable_htmlentities($service['service_type']);
                    $fee_amount = floatval($service['fee_amount']);
                    $duration_minutes = intval($service['duration_minutes']);
                    $requires_doctor = intval($service['requires_doctor']);
                    $insurance_billable = intval($service['insurance_billable']);
                    $is_active = intval($service['is_active']);
                    $linked_components = intval($service['linked_components_count']);
                    $linked_inventory = intval($service['linked_inventory_count']);
                    $linked_accounts = intval($service['linked_accounts_count']);
                    ?>
                    <tr class="<?php echo $is_active ? '' : 'text-muted bg-light'; ?>">
                        <td class="font-weight-bold text-success"><?php echo $service_code; ?></td>
                        <td>
                            <div class="font-weight-bold"><?php echo $service_name; ?></div>
                            <?php if ($service_description): ?>
                                <small class="text-muted"><?php echo strlen($service_description) > 50 ? substr($service_description, 0, 50) . '...' : $service_description; ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge-info"><?php echo $category_name ?: 'Uncategorized'; ?></span>
                        </td>
                        <td>
                            <span class="badge badge-<?php echo getServiceTypeBadgeColor($service_type); ?>"><?php echo $service_type; ?></span>
                        </td>
                        <td>
                            <span class="font-weight-bold text-primary">$<?php echo number_format($fee_amount, 2); ?></span>
                            <?php if ($service['tax_rate'] > 0): ?>
                                <small class="text-muted d-block">+<?php echo $service['tax_rate']; ?>% tax</small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge-secondary"><?php echo $duration_minutes; ?> min</span>
                        </td>
                        <td>
                            <div class="d-flex flex-wrap">
                                <?php if ($linked_components > 0): ?>
                                    <span class="badge badge-info mr-1 mb-1" title="Linked Components">
                                        <i class="fas fa-link mr-1"></i><?php echo $linked_components; ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($linked_inventory > 0): ?>
                                    <span class="badge badge-warning mr-1 mb-1" title="Linked Inventory Items">
                                        <i class="fas fa-box mr-1"></i><?php echo $linked_inventory; ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($linked_accounts > 0): ?>
                                    <span class="badge badge-secondary mr-1 mb-1" title="Linked Accounts">
                                        <i class="fas fa-book mr-1"></i><?php echo $linked_accounts; ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($insurance_billable): ?>
                                    <span class="badge badge-success mb-1" title="Insurance Billable">
                                        <i class="fas fa-file-invoice-dollar mr-1"></i>Billable
                                    </span>
                                <?php else: ?>
                                    <span class="badge badge-light mb-1" title="Self-pay">
                                        <i class="fas fa-money-bill-wave mr-1"></i>Self-pay
                                    </span>
                                <?php endif; ?>
                                <?php if ($requires_doctor): ?>
                                    <span class="badge badge-primary mb-1" title="Requires Doctor">
                                        <i class="fas fa-user-md mr-1"></i>Doctor
                                    </span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <div class="dropdown dropleft text-center">
                                <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                    <i class="fas fa-ellipsis-h"></i>
                                </button>
                                <div class="dropdown-menu">
                                    <a class="dropdown-item" href="medical_service_details.php?medical_service_id=<?php echo $medical_service_id; ?>">
                                        <i class="fas fa-fw fa-eye mr-2"></i>View Details
                                    </a>
                                    <a class="dropdown-item" href="medical_service_edit.php?medical_service_id=<?php echo $medical_service_id; ?>">
                                        <i class="fas fa-fw fa-edit mr-2"></i>Edit Service
                                    </a>
                                    <div class="dropdown-divider"></div>
                                    <a class="dropdown-item" href="medical_service_copy.php?medical_service_id=<?php echo $medical_service_id; ?>">
                                        <i class="fas fa-fw fa-copy mr-2"></i>Duplicate Service
                                    </a>
                                    <?php if ($is_active): ?>
                                        <a class="dropdown-item text-danger" href="#" onclick="deactivateService(<?php echo $medical_service_id; ?>)">
                                            <i class="fas fa-fw fa-times mr-2"></i>Deactivate Service
                                        </a>
                                    <?php else: ?>
                                        <a class="dropdown-item text-success" href="#" onclick="restoreService(<?php echo $medical_service_id; ?>)">
                                            <i class="fas fa-fw fa-undo mr-2"></i>Restore Service
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; ?>

                <?php if ($num_rows[0] === 0): ?>
                    <tr>
                        <td colspan="8" class="text-center py-4">
                            <i class="fas fa-stethoscope fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted">No Medical Services Found</h5>
                            <p class="text-muted">
                                <?php echo ($category_filter || $type_filter || $search_query) ? 
                                    'Try adjusting your filters or search criteria.' : 
                                    'Get started by creating your first medical service.'; 
                                ?>
                            </p>
                            <a href="medical_service_add.php" class="btn btn-success mt-2">
                                <i class="fas fa-plus mr-2"></i>Create First Service
                            </a>
                            <?php if ($category_filter || $type_filter || $search_query): ?>
                                <a href="medical_services.php" class="btn btn-outline-secondary mt-2 ml-2">
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

<?php
// Helper function for service type badge colors
function getServiceTypeBadgeColor($service_type) {
    switch ($service_type) {
        case 'Consultation': return 'primary';
        case 'Procedure': return 'warning';
        case 'LabTest': return 'info';
        case 'Imaging': return 'success';
        case 'Vaccination': return 'success';
        case 'Package': return 'info';
        case 'Bed': return 'danger';
        case 'Other': return 'secondary';
        default: return 'secondary';
    }
}
?>

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

function deactivateService(serviceId) {
    if (confirm('Are you sure you want to deactivate this medical service? It will no longer be available for booking.')) {
        $.ajax({
            url: 'medical_services.php',
            type: 'POST',
            data: {
                ajax_request: 1,
                delete_service: 1,
                medical_service_id: serviceId
            },
            success: function(response) {
                const result = JSON.parse(response);
                if (result.success) {
                    showAlert(result.message, 'success');
                    // Reload page after successful deactivation
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else {
                    showAlert(result.message, 'error');
                }
            },
            error: function() {
                showAlert('Error deactivating medical service. Please try again.', 'error');
            }
        });
    }
}

function restoreService(serviceId) {
    if (confirm('Are you sure you want to restore this medical service?')) {
        $.ajax({
            url: 'medical_services.php',
            type: 'POST',
            data: {
                ajax_request: 1,
                restore_service: 1,
                medical_service_id: serviceId
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
                showAlert('Error restoring medical service. Please try again.', 'error');
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
    // Ctrl + N for new service
    if (e.ctrlKey && e.keyCode === 78) {
        e.preventDefault();
        window.location.href = 'medical_service_add.php';
    }
    // Ctrl + F for focus search
    if (e.ctrlKey && e.keyCode === 70) {
        e.preventDefault();
        $('input[name="q"]').focus();
    }
    // Ctrl + A for analytics dropdown
    if (e.ctrlKey && e.keyCode === 65) {
        e.preventDefault();
        $('.btn-group .dropdown-toggle').dropdown('toggle');
    }
});

// Tooltip initialization
$(function () {
    $('[title]').tooltip({
        placement: 'top',
        trigger: 'hover'
    });
});
</script>

<style>
.card {
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    margin-bottom: 1rem;
}
.dropdown-menu {
    min-width: 200px;
}
.badge {
    font-size: 0.8rem;
    padding: 0.25em 0.5em;
}
.table td {
    vertical-align: middle;
}
.text-muted.bg-light {
    opacity: 0.6;
}
</style>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>