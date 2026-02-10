<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Handle form submissions via POST
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_category'])) {
        $category_name = sanitizeInput($_POST['category_name']);
        $category_description = sanitizeInput($_POST['category_description']);
        
        $stmt = $mysqli->prepare("INSERT INTO lab_test_categories SET category_name=?, category_description=?, is_active=1");
        $stmt->bind_param("ss", $category_name, $category_description);
        
        if ($stmt->execute()) {
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Category added successfully!";
        } else {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error adding category: " . $mysqli->error;
        }
        $stmt->close();
    }
    
    elseif (isset($_POST['update_category'])) {
        $category_id = intval($_POST['category_id']);
        $category_name = sanitizeInput($_POST['category_name']);
        $category_description = sanitizeInput($_POST['category_description']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        $stmt = $mysqli->prepare("UPDATE lab_test_categories SET category_name=?, category_description=?, is_active=? WHERE category_id=?");
        $stmt->bind_param("ssii", $category_name, $category_description, $is_active, $category_id);
        
        if ($stmt->execute()) {
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Category updated successfully!";
        } else {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error updating category: " . $mysqli->error;
        }
        $stmt->close();
    }
    
    // Redirect to prevent form resubmission
    header("Location: lab_category.php");
    exit;
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ajax_request'])) {
    header('Content-Type: application/json');
    
    if (isset($_POST['get_category_details'])) {
        $category_id = intval($_POST['category_id']);
        $category = $mysqli->query("SELECT * FROM lab_test_categories WHERE category_id = $category_id")->fetch_assoc();
        echo json_encode($category);
        exit;
    }
    
    if (isset($_POST['delete_category'])) {
        $category_id = intval($_POST['category_id']);
        
        // Check if category has active tests
        $check_sql = "SELECT COUNT(*) as test_count FROM lab_tests WHERE category_id = ? AND is_active = 1";
        $check_stmt = $mysqli->prepare($check_sql);
        $check_stmt->bind_param("i", $category_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $test_count = $check_result->fetch_assoc()['test_count'];
        $check_stmt->close();
        
        if ($test_count > 0) {
            echo json_encode(['success' => false, 'message' => 'Cannot delete category. It has ' . $test_count . ' active test(s). Please reassign or delete the tests first.']);
            exit;
        }
        
        $stmt = $mysqli->prepare("UPDATE lab_test_categories SET is_active = 0 WHERE category_id = ?");
        $stmt->bind_param("i", $category_id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Category deleted successfully!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error deleting category: ' . $mysqli->error]);
        }
        $stmt->close();
        exit;
    }
}

// Default Column Sortby/Order Filter
$sort = "ltc.category_name";
$order = "ASC";

// Search Query
$q = sanitizeInput($_GET['q'] ?? '');
if (!empty($q)) {
    $search_query = "AND (
        ltc.category_name LIKE '%$q%' 
        OR ltc.category_description LIKE '%$q%'
    )";
} else {
    $search_query = '';
}

// Get all categories with test counts
$categories_sql = "
    SELECT SQL_CALC_FOUND_ROWS ltc.*, 
           COUNT(lt.test_id) as test_count,
           SUM(lt.price) as total_value,
           AVG(lt.turnaround_time) as avg_turnaround
    FROM lab_test_categories ltc 
    LEFT JOIN lab_tests lt ON ltc.category_id = lt.category_id AND lt.is_active = 1
    WHERE ltc.is_active = 1 
    $search_query
    GROUP BY ltc.category_id 
    ORDER BY $sort $order
    LIMIT $record_from, $record_to
";

$categories = $mysqli->query($categories_sql);
$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Get statistics
$total_categories = $num_rows[0];
$total_tests = $mysqli->query("SELECT COUNT(*) FROM lab_tests WHERE is_active = 1")->fetch_row()[0];
$active_categories = $total_categories;
$inactive_categories = $mysqli->query("SELECT COUNT(*) FROM lab_test_categories WHERE is_active = 0")->fetch_row()[0];
$popular_category = $mysqli->query("
    SELECT ltc.category_name, COUNT(lt.test_id) as test_count 
    FROM lab_test_categories ltc 
    JOIN lab_tests lt ON ltc.category_id = lt.category_id AND lt.is_active = 1 
    WHERE ltc.is_active = 1 
    GROUP BY ltc.category_id 
    ORDER BY test_count DESC 
    LIMIT 1
")->fetch_assoc();

// Reset pointer for main query
$categories->data_seek(0);
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0"><i class="fas fa-fw fa-folder mr-2"></i>Laboratory Test Categories</h3>
        <div class="card-tools">
            <a href="lab_category_add.php" class="btn btn-success">
                <i class="fas fa-plus mr-2"></i>New Category
            </a>
        </div>
    </div>

   

    <div class="card-header pb-2 pt-3">
        <form autocomplete="off">
            <div class="row">
                <div class="col-md-5">
                    <div class="form-group">
                        <div class="input-group">
                            <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search categories..." autofocus>
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
                                <i class="fas fa-folder text-primary mr-1"></i>
                                Categories: <strong><?php echo $total_categories; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-vial text-success mr-1"></i>
                                Tests: <strong><?php echo $total_tests; ?></strong>
                            </span>
                            <a href="lab_tests.php" class="btn btn-info ml-2">
                                <i class="fas fa-fw fa-vial mr-2"></i>View Tests
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse <?php if (isset($_GET['q'])) { echo "show"; } ?>" id="advancedFilter">
                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Sort By</label>
                            <select class="form-control select2" name="sort" onchange="this.form.submit()">
                                <option value="ltc.category_name ASC" <?php if ($sort == 'ltc.category_name' && $order == 'ASC') { echo "selected"; } ?>>Name (A-Z)</option>
                                <option value="ltc.category_name DESC" <?php if ($sort == 'ltc.category_name' && $order == 'DESC') { echo "selected"; } ?>>Name (Z-A)</option>
                                <option value="test_count ASC" <?php if ($sort == 'test_count' && $order == 'ASC') { echo "selected"; } ?>>Test Count (Low to High)</option>
                                <option value="test_count DESC" <?php if ($sort == 'test_count' && $order == 'DESC') { echo "selected"; } ?>>Test Count (High to Low)</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Quick Actions</label>
                            <div class="btn-group btn-block">
                                <a href="lab_category.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-times mr-2"></i>Clear Filters
                                </a>
                                <a href="lab_category_add.php" class="btn btn-success">
                                    <i class="fas fa-plus mr-2"></i>New Category
                                </a>
                            </div>
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

        <!-- Categories Table -->
        <div class="table-responsive-sm">
            <table class="table table-hover mb-0">
                <thead class="<?php if ($num_rows[0] == 0) { echo "d-none"; } ?> bg-light">
                <tr>
                    <th>
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=ltc.category_name&order=<?php echo $disp; ?>">
                            Category Name <?php if ($sort == 'ltc.category_name') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>Description</th>
                    <th class="text-center">
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=test_count&order=<?php echo $disp; ?>">
                            Tests <?php if ($sort == 'test_count') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th class="text-center">Total Value</th>
                    <th class="text-center">Avg Turnaround</th>
                    <th class="text-center">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php while($category = $categories->fetch_assoc()): 
                    $category_id = intval($category['category_id']);
                    $category_name = nullable_htmlentities($category['category_name']);
                    $category_description = nullable_htmlentities($category['category_description']);
                    $test_count = intval($category['test_count']);
                    $total_value = floatval($category['total_value'] ?? 0);
                    $avg_turnaround = floatval($category['avg_turnaround'] ?? 0);
                    ?>
                    <tr>
                        <td>
                            <div class="font-weight-bold text-primary">
                                <i class="fas fa-folder mr-2"></i><?php echo $category_name; ?>
                            </div>
                        </td>
                        <td>
                            <?php if ($category_description): ?>
                                <small class="text-muted"><?php echo strlen($category_description) > 80 ? substr($category_description, 0, 80) . '...' : $category_description; ?></small>
                            <?php else: ?>
                                <span class="text-muted"><em>No description</em></span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <span class="badge badge-primary badge-pill"><?php echo $test_count; ?></span>
                        </td>
                        <td class="text-center">
                            <span class="font-weight-bold text-success">$<?php echo number_format($total_value, 2); ?></span>
                        </td>
                        <td class="text-center">
                            <span class="badge badge-secondary"><?php echo round($avg_turnaround); ?>h</span>
                        </td>
                        <td>
                            <div class="dropdown dropleft text-center">
                                <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                    <i class="fas fa-ellipsis-h"></i>
                                </button>
                                <div class="dropdown-menu">
                                    <a class="dropdown-item" href="lab_tests.php?category=<?php echo $category_id; ?>">
                                        <i class="fas fa-fw fa-eye mr-2"></i>View Tests
                                    </a>
                                    <a class="dropdown-item" href="lab_category_edit.php?category_id=<?php echo $category_id; ?>">
                                        <i class="fas fa-fw fa-edit mr-2"></i>Edit Category
                                    </a>
                                    <div class="dropdown-divider"></div>
                                    <a class="dropdown-item text-danger" href="#" onclick="deleteCategory(<?php echo $category_id; ?>)">
                                        <i class="fas fa-fw fa-trash mr-2"></i>Delete Category
                                    </a>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; ?>

                <?php if ($num_rows[0] === 0): ?>
                    <tr>
                        <td colspan="6" class="text-center py-4">
                            <i class="fas fa-folder-open fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted">No Categories Found</h5>
                            <p class="text-muted">
                                <?php echo ($search_query) ? 
                                    'Try adjusting your search criteria.' : 
                                    'Get started by creating your first test category.'; 
                                ?>
                            </p>
                            <a href="lab_category_add.php" class="btn btn-primary mt-2">
                                <i class="fas fa-plus mr-2"></i>Create First Category
                            </a>
                            <?php if ($search_query): ?>
                                <a href="lab_category.php" class="btn btn-outline-secondary mt-2 ml-2">
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

    // Auto-close alerts after 5 seconds
    setTimeout(() => {
        $('.alert').alert('close');
    }, 5000);
});

function deleteCategory(categoryId) {
    if (confirm('Are you sure you want to delete this category? This action cannot be undone.')) {
        $.ajax({
            url: 'lab_category.php',
            type: 'POST',
            data: {
                ajax_request: 1,
                delete_category: 1,
                category_id: categoryId
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
                showAlert('Error deleting category. Please try again.', 'error');
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
    // Ctrl + N for new category
    if (e.ctrlKey && e.keyCode === 78) {
        e.preventDefault();
        window.location.href = 'lab_category_add.php';
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