<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Handle form submissions via POST
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_test'])) {
        $test_code = sanitizeInput($_POST['test_code']);
        $test_name = sanitizeInput($_POST['test_name']);
        $method = sanitizeInput($_POST['method']);
        $test_description = sanitizeInput($_POST['test_description']);
        $category_id = intval($_POST['category_id']);
        $price = floatval($_POST['price']);
        $turnaround_time = intval($_POST['turnaround_time']);
        $specimen_type = sanitizeInput($_POST['specimen_type']);
        $container_type = sanitizeInput($_POST['container_type']);
        $required_volume = sanitizeInput($_POST['required_volume']);
        $reference_range = sanitizeInput($_POST['reference_range']);
        $result_unit = sanitizeInput($_POST['result_unit']);
        $instructions = sanitizeInput($_POST['instructions']);
        
        $stmt = $mysqli->prepare("INSERT INTO lab_tests SET test_code=?, test_name=?, method=?, test_description=?, category_id=?, price=?, turnaround_time=?, specimen_type=?, container_type=?, required_volume=?, reference_range=?, result_unit=?, instructions=?, is_active=1");
        $stmt->bind_param("ssssidisssssss", $test_code, $test_name, $method, $test_description, $category_id, $price, $turnaround_time, $specimen_type, $container_type, $required_volume, $reference_range, $result_unit, $instructions);
        
        if ($stmt->execute()) {
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Test added successfully!";
        } else {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error adding test: " . $mysqli->error;
        }
        $stmt->close();
    }
    
    elseif (isset($_POST['update_test'])) {
        $test_id = intval($_POST['test_id']);
        $test_code = sanitizeInput($_POST['test_code']);
        $test_name = sanitizeInput($_POST['test_name']);
        $method = sanitizeInput($_POST['method']);
        $test_description = sanitizeInput($_POST['test_description']);
        $category_id = intval($_POST['category_id']);
        $price = floatval($_POST['price']);
        $turnaround_time = intval($_POST['turnaround_time']);
        $specimen_type = sanitizeInput($_POST['specimen_type']);
        $container_type = sanitizeInput($_POST['container_type']);
        $required_volume = sanitizeInput($_POST['required_volume']);
        $reference_range = sanitizeInput($_POST['reference_range']);
        $result_unit = sanitizeInput($_POST['result_unit']);
        $instructions = sanitizeInput($_POST['instructions']);
        
        $stmt = $mysqli->prepare("UPDATE lab_tests SET test_code=?, test_name=?, method=?, test_description=?, category_id=?, price=?, turnaround_time=?, specimen_type=?, container_type=?, required_volume=?, reference_range=?, result_unit=?, instructions=? WHERE test_id=?");
        $stmt->bind_param("ssssidisssssssi", $test_code, $test_name, $method, $test_description, $category_id, $price, $turnaround_time, $specimen_type, $container_type, $required_volume, $reference_range, $result_unit, $instructions, $test_id);
        
        if ($stmt->execute()) {
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Test updated successfully!";
        } else {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error updating test: " . $mysqli->error;
        }
        $stmt->close();
    }
    
    // Redirect to prevent form resubmission
    header("Location: lab_tests.php");
    exit;
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ajax_request'])) {
    header('Content-Type: application/json');
    
    if (isset($_POST['get_test_details'])) {
        $test_id = intval($_POST['test_id']);
        $test = $mysqli->query("SELECT * FROM lab_tests WHERE test_id = $test_id")->fetch_assoc();
        echo json_encode($test);
        exit;
    }
    
    if (isset($_POST['delete_test'])) {
        $test_id = intval($_POST['test_id']);
        $stmt = $mysqli->prepare("UPDATE lab_tests SET is_active = 0 WHERE test_id = ?");
        $stmt->bind_param("i", $test_id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Test deleted successfully!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error deleting test: ' . $mysqli->error]);
        }
        $stmt->close();
        exit;
    }
}

// Default Column Sortby/Order Filter
$sort = "lt.test_name";
$order = "ASC";

// Filter parameters
$category_filter = $_GET['category'] ?? '';
$specimen_filter = $_GET['specimen'] ?? '';
$active_filter = $_GET['active'] ?? '';

// Date Range Filter
$dtf = sanitizeInput($_GET['dtf'] ?? '');
$dtt = sanitizeInput($_GET['dtt'] ?? '');

// Search Query
$q = sanitizeInput($_GET['q'] ?? '');
if (!empty($q)) {
    $search_query = "AND (
        lt.test_code LIKE '%$q%' 
        OR lt.test_name LIKE '%$q%'
        OR lt.method LIKE '%$q%'
        OR lt.test_description LIKE '%$q%'
        OR ltc.category_name LIKE '%$q%'
    )";
} else {
    $search_query = '';
}

// Build WHERE clause for filters
$where_conditions = ["1=1"];
$params = [];
$types = '';

if (!empty($category_filter)) {
    $where_conditions[] = "lt.category_id = ?";
    $params[] = $category_filter;
    $types .= 'i';
}

if (!empty($specimen_filter)) {
    $where_conditions[] = "lt.specimen_type = ?";
    $params[] = $specimen_filter;
    $types .= 's';
}

if ($active_filter === 'inactive') {
    $where_conditions[] = "lt.is_active = 0";
} else {
    $where_conditions[] = "lt.is_active = 1";
}

$where_clause = implode(' AND ', $where_conditions);

// Get all tests with categories
$tests_sql = "
    SELECT SQL_CALC_FOUND_ROWS lt.*, ltc.category_name 
    FROM lab_tests lt 
    LEFT JOIN lab_test_categories ltc ON lt.category_id = ltc.category_id 
    WHERE $where_clause
    $search_query
    ORDER BY $sort $order
    LIMIT $record_from, $record_to
";

$tests_stmt = $mysqli->prepare($tests_sql);
if (!empty($params)) {
    $tests_stmt->bind_param($types, ...$params);
}
$tests_stmt->execute();
$tests = $tests_stmt->get_result();

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Get categories for filter dropdown
$categories = $mysqli->query("SELECT * FROM lab_test_categories WHERE is_active = 1 ORDER BY category_name");

// Get unique specimen types for filter
$specimen_types = $mysqli->query("SELECT DISTINCT specimen_type FROM lab_tests WHERE specimen_type IS NOT NULL ORDER BY specimen_type");

// Get statistics
$total_tests = $mysqli->query("SELECT COUNT(*) FROM lab_tests WHERE is_active = 1")->fetch_row()[0];
$inactive_tests = $mysqli->query("SELECT COUNT(*) FROM lab_tests WHERE is_active = 0")->fetch_row()[0];
$total_value = $mysqli->query("SELECT SUM(price) as total FROM lab_tests WHERE is_active = 1")->fetch_assoc()['total'] ?? 0;
$avg_turnaround = $mysqli->query("SELECT AVG(turnaround_time) as avg_time FROM lab_tests WHERE is_active = 1")->fetch_assoc()['avg_time'] ?? 0;
$blood_tests_count = $mysqli->query("SELECT COUNT(*) FROM lab_tests WHERE specimen_type LIKE '%blood%' AND is_active = 1")->fetch_row()[0];

// Reset pointer for main query
$tests->data_seek(0);
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0"><i class="fas fa-fw fa-vial mr-2"></i>Laboratory Tests Management</h3>
        <div class="card-tools">
            <a href="lab_test_add.php" class="btn btn-success">
                <i class="fas fa-plus mr-2"></i>New Test
            </a>
        </div>
    </div>

    <div class="card-header pb-2 pt-3">
        <form autocomplete="off">
            <div class="row">
                <div class="col-md-5">
                    <div class="form-group">
                        <div class="input-group">
                            <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search tests, codes, methods..." autofocus>
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
                                <i class="fas fa-vial text-primary mr-1"></i>
                                Active: <strong><?php echo $total_tests; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-clock text-warning mr-1"></i>
                                Avg Time: <strong><?php echo number_format($avg_turnaround, 1); ?>h</strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-dollar-sign text-success mr-1"></i>
                                Value: <strong>$<?php echo number_format($total_value, 2); ?></strong>
                            </span>
                            <a href="lab_category.php" class="btn btn-info ml-2">
                                <i class="fas fa-fw fa-folder mr-2"></i>Categories
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse <?php if ($category_filter || $specimen_filter || isset($_GET['dtf'])) { echo "show"; } ?>" id="advancedFilter">
                <div class="row">
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Status</label>
                            <select class="form-control select2" name="active" onchange="this.form.submit()">
                                <option value="active" <?php if ($active_filter !== 'inactive') echo "selected"; ?>>Active Tests</option>
                                <option value="inactive" <?php if ($active_filter === 'inactive') echo "selected"; ?>>Inactive Tests</option>
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
                            <label>Specimen Type</label>
                            <select class="form-control select2" name="specimen" onchange="this.form.submit()">
                                <option value="">- All Specimens -</option>
                                <?php while($specimen = $specimen_types->fetch_assoc()): ?>
                                    <option value="<?php echo htmlspecialchars($specimen['specimen_type']); ?>" <?php if ($specimen_filter == $specimen['specimen_type']) { echo "selected"; } ?>>
                                        <?php echo htmlspecialchars($specimen['specimen_type']); ?>
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
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=lt.test_code&order=<?php echo $disp; ?>">
                            Test Code <?php if ($sort == 'lt.test_code') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=lt.test_name&order=<?php echo $disp; ?>">
                            Test Name <?php if ($sort == 'lt.test_name') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>Method</th>
                    <th>
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=ltc.category_name&order=<?php echo $disp; ?>">
                            Category <?php if ($sort == 'ltc.category_name') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=lt.price&order=<?php echo $disp; ?>">
                            Price <?php if ($sort == 'lt.price') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=lt.turnaround_time&order=<?php echo $disp; ?>">
                            Turnaround <?php if ($sort == 'lt.turnaround_time') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>Specimen</th>
                    <th class="text-center">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php while($test = $tests->fetch_assoc()): 
                    $test_id = intval($test['test_id']);
                    $test_code = nullable_htmlentities($test['test_code']);
                    $test_name = nullable_htmlentities($test['test_name']);
                    $method = nullable_htmlentities($test['method']);
                    $test_description = nullable_htmlentities($test['test_description']);
                    $category_name = nullable_htmlentities($test['category_name']);
                    $price = floatval($test['price']);
                    $turnaround_time = intval($test['turnaround_time']);
                    $specimen_type = nullable_htmlentities($test['specimen_type']);
                    $is_active = intval($test['is_active']);
                    ?>
                    <tr class="<?php echo $is_active ? '' : 'text-muted bg-light'; ?>">
                        <td class="font-weight-bold text-primary"><?php echo $test_code; ?></td>
                        <td>
                            <div class="font-weight-bold"><?php echo $test_name; ?></div>
                            <?php if ($method): ?>
                                <small class="text-muted"><?php echo $method; ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($method): ?>
                                <small class="text-muted"><?php echo strlen($method) > 30 ? substr($method, 0, 30) . '...' : $method; ?></small>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge-info"><?php echo $category_name; ?></span>
                        </td>
                        <td>
                            <span class="font-weight-bold text-success">$<?php echo number_format($price, 2); ?></span>
                        </td>
                        <td>
                            <span class="badge badge-secondary"><?php echo $turnaround_time; ?> hrs</span>
                        </td>
                        <td>
                            <small class="text-muted"><?php echo $specimen_type ?: 'Not specified'; ?></small>
                        </td>
                        <td>
                            <div class="dropdown dropleft text-center">
                                <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                    <i class="fas fa-ellipsis-h"></i>
                                </button>
                                <div class="dropdown-menu">
                                    <a class="dropdown-item" href="lab_test_details.php?test_id=<?php echo $test_id; ?>">
                                        <i class="fas fa-fw fa-eye mr-2"></i>View Details
                                    </a>
                                    <a class="dropdown-item" href="lab_test_edit.php?test_id=<?php echo $test_id; ?>">
                                        <i class="fas fa-fw fa-edit mr-2"></i>Edit Test
                                    </a>
                                    <?php if ($is_active): ?>
                                        <div class="dropdown-divider"></div>
                                        <a class="dropdown-item text-danger" href="#" onclick="deleteTest(<?php echo $test_id; ?>)">
                                            <i class="fas fa-fw fa-trash mr-2"></i>Delete Test
                                        </a>
                                    <?php else: ?>
                                        <a class="dropdown-item text-success" href="#" onclick="restoreTest(<?php echo $test_id; ?>)">
                                            <i class="fas fa-fw fa-undo mr-2"></i>Restore Test
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
                            <i class="fas fa-vial fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted">No Tests Found</h5>
                            <p class="text-muted">
                                <?php echo ($category_filter || $specimen_filter || $search_query) ? 
                                    'Try adjusting your filters or search criteria.' : 
                                    'Get started by creating your first lab test.'; 
                                ?>
                            </p>
                            <a href="lab_test_add.php" class="btn btn-primary mt-2">
                                <i class="fas fa-plus mr-2"></i>Create First Test
                            </a>
                            <?php if ($category_filter || $specimen_filter || $search_query): ?>
                                <a href="lab_tests.php" class="btn btn-outline-secondary mt-2 ml-2">
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

function deleteTest(testId) {
    if (confirm('Are you sure you want to delete this test? This action cannot be undone.')) {
        $.ajax({
            url: 'lab_tests.php',
            type: 'POST',
            data: {
                ajax_request: 1,
                delete_test: 1,
                test_id: testId
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
                showAlert('Error deleting test. Please try again.', 'error');
            }
        });
    }
}

function restoreTest(testId) {
    if (confirm('Are you sure you want to restore this test?')) {
        $.ajax({
            url: 'lab_tests.php',
            type: 'POST',
            data: {
                ajax_request: 1,
                restore_test: 1,
                test_id: testId
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
                showAlert('Error restoring test. Please try again.', 'error');
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
    // Ctrl + N for new test
    if (e.ctrlKey && e.keyCode === 78) {
        e.preventDefault();
        window.location.href = 'lab_test_add.php';
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