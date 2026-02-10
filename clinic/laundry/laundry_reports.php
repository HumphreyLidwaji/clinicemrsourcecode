<?php
// laundry_reports.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

$page_title = "Laundry Reports";

// Report parameters
$report_type = $_GET['report'] ?? 'summary';
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$category_id = $_GET['category'] ?? '';
$location = $_GET['location'] ?? '';

// Generate report based on type
switch ($report_type) {
    case 'wash_history':
        $title = "Wash History Report";
        break;
    case 'inventory':
        $title = "Inventory Report";
        break;
    case 'transactions':
        $title = "Transaction History";
        break;
    case 'usage':
        $title = "Usage Statistics";
        break;
    case 'low_stock':
        $title = "Low Stock Alert";
        break;
    case 'damaged_items':
        $title = "Damaged Items Report";
        break;
    default:
        $title = "Summary Dashboard";
        $report_type = 'summary';
}

// Get laundry categories for filter
$categories_sql = "SELECT * FROM laundry_categories ";
$categories_result = $mysqli->query($categories_sql);
?>

<div class="card">
    <div class="card-header bg-dark py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-fw fa-chart-bar mr-2"></i><?php echo $title; ?>
        </h3>
        <div class="card-tools">
            <button class="btn btn-light" onclick="window.print()">
                <i class="fas fa-fw fa-print mr-2"></i>Print
            </button>
            <a href="laundry_management.php" class="btn btn-secondary ml-2">
                <i class="fas fa-fw fa-arrow-left mr-2"></i>Back to Laundry
            </a>
        </div>
    </div>
    
    <div class="card-body">
        <!-- Report Filter Form -->
        <div class="card mb-4">
            <div class="card-body bg-light">
                <form action="laundry_reports.php" method="GET" autocomplete="off">
                    <div class="row">
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Report Type</label>
                                <select class="form-control" name="report" onchange="this.form.submit()">
                                    <option value="summary" <?php echo $report_type == 'summary' ? 'selected' : ''; ?>>Summary Dashboard</option>
                                    <option value="wash_history" <?php echo $report_type == 'wash_history' ? 'selected' : ''; ?>>Wash History</option>
                                    <option value="inventory" <?php echo $report_type == 'inventory' ? 'selected' : ''; ?>>Inventory Report</option>
                                    <option value="transactions" <?php echo $report_type == 'transactions' ? 'selected' : ''; ?>>Transaction History</option>
                                    <option value="usage" <?php echo $report_type == 'usage' ? 'selected' : ''; ?>>Usage Statistics</option>
                                    <option value="low_stock" <?php echo $report_type == 'low_stock' ? 'selected' : ''; ?>>Low Stock Alert</option>
                                    <option value="damaged_items" <?php echo $report_type == 'damaged_items' ? 'selected' : ''; ?>>Damaged Items</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Start Date</label>
                                <input type="date" class="form-control" name="start_date" 
                                       value="<?php echo $start_date; ?>">
                            </div>
                        </div>
                        
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>End Date</label>
                                <input type="date" class="form-control" name="end_date" 
                                       value="<?php echo $end_date; ?>">
                            </div>
                        </div>
                        
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Category</label>
                                <select class="form-control select2" name="category">
                                    <option value="">All Categories</option>
                                    <?php while($category = $categories_result->fetch_assoc()): ?>
                                        <option value="<?php echo $category['category_id']; ?>" 
                                                <?php echo $category_id == $category['category_id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($category['category_name']); ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-12 text-right">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter mr-2"></i>Apply Filters
                            </button>
                            <a href="laundry_reports.php" class="btn btn-secondary ml-2">
                                <i class="fas fa-times mr-2"></i>Clear Filters
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
       
        
    </div>
</div>

<script>
$(document).ready(function() {
    $('.select2').select2();
    
    // Initialize date pickers
    $('input[type="date"]').datepicker({
        format: 'yyyy-mm-dd',
        autoclose: true,
        todayHighlight: true
    });
    
    // Load chart if exists
    if (typeof loadCharts === 'function') {
        loadCharts();
    }
});
</script>

<style>
@media print {
    .card-header, .btn, .form-group, .alert {
        display: none !important;
    }
    
    .card {
        border: none !important;
        box-shadow: none !important;
    }
    
    .table {
        font-size: 12px !important;
    }
}

.chart-container {
    height: 400px;
    position: relative;
}

.report-summary .card {
    transition: all 0.3s ease;
}

.report-summary .card:hover {
    transform: translateY(-5px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>