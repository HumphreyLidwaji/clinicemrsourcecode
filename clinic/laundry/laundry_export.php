<?php
// laundry_export.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

$page_title = "Export Laundry Data";

// Handle export request
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $export_type = sanitizeInput($_POST['export_type']);
    $format = sanitizeInput($_POST['format']);
    $start_date = sanitizeInput($_POST['start_date']);
    $end_date = sanitizeInput($_POST['end_date']);
    
    // Set headers for download
    if ($format == 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=laundry_export_' . date('Y-m-d') . '.csv');
        
        $output = fopen('php://output', 'w');
        
        switch ($export_type) {
            case 'inventory':
                // Export inventory
                $sql = "
                    SELECT 
                        a.asset_tag as 'Asset Tag',
                        a.asset_name as 'Item Name',
                        lc.category_name as 'Category',
                        li.quantity as 'Quantity',
                        li.current_location as 'Location',
                        li.status as 'Status',
                        li.condition as 'Condition',
                        li.wash_count as 'Wash Count',
                        li.last_washed_date as 'Last Washed',
                        li.next_wash_date as 'Next Wash Due',
                        li.is_critical as 'Critical',
                        li.notes as 'Notes'
                    FROM laundry_items li
                    LEFT JOIN assets a ON li.asset_id = a.asset_id
                    LEFT JOIN laundry_categories lc ON li.category_id = lc.category_id
                    ORDER BY a.asset_name
                ";
                break;
                
            case 'transactions':
                // Export transactions
                $sql = "
                    SELECT 
                        DATE(lt.transaction_date) as 'Date',
                        TIME(lt.transaction_date) as 'Time',
                        a.asset_tag as 'Asset Tag',
                        a.asset_name as 'Item Name',
                        lt.transaction_type as 'Transaction Type',
                        lt.from_location as 'From Location',
                        lt.to_location as 'To Location',
                        u.user_name as 'Performed By',
                        c.client_name as 'For Patient',
                        lt.department as 'Department',
                        lt.notes as 'Notes'
                    FROM laundry_transactions lt
                    LEFT JOIN laundry_items li ON lt.laundry_id = li.laundry_id
                    LEFT JOIN assets a ON li.asset_id = a.asset_id
                    LEFT JOIN users u ON lt.performed_by = u.user_id
                    LEFT JOIN clients c ON lt.performed_for = c.client_id
                    WHERE DATE(lt.transaction_date) BETWEEN ? AND ?
                    ORDER BY lt.transaction_date DESC
                ";
                break;
                
            case 'wash_cycles':
                // Export wash cycles
                $sql = "
                    SELECT 
                        wc.wash_date as 'Wash Date',
                        wc.wash_time as 'Wash Time',
                        u.user_name as 'Completed By',
                        wc.temperature as 'Temperature',
                        wc.detergent_type as 'Detergent',
                        wc.bleach_used as 'Bleach Used',
                        wc.fabric_softener_used as 'Fabric Softener Used',
                        wc.items_washed as 'Items Washed',
                        wc.total_weight as 'Total Weight (kg)',
                        wc.notes as 'Notes'
                    FROM laundry_wash_cycles wc
                    LEFT JOIN users u ON wc.completed_by = u.user_id
                    WHERE wc.wash_date BETWEEN ? AND ?
                    ORDER BY wc.wash_date DESC, wc.wash_time DESC
                ";
                break;
                
            case 'categories':
                // Export categories
                $sql = "
                    SELECT 
                        category_name as 'Category Name',
                        description as 'Description',
                        min_quantity as 'Minimum Quantity',
                        reorder_point as 'Reorder Point',
                        is_active as 'Active',
                        created_at as 'Created Date'
                    FROM laundry_categories
                    ORDER BY category_name
                ";
                break;
        }
        
        $stmt = $mysqli->prepare($sql);
        
        if ($export_type == 'transactions' || $export_type == 'wash_cycles') {
            $stmt->bind_param("ss", $start_date, $end_date);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        // Output CSV headers
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            fputcsv($output, array_keys($row));
            fputcsv($output, array_values($row));
            
            while ($row = $result->fetch_assoc()) {
                fputcsv($output, $row);
            }
        }
        
        fclose($output);
        exit();
        
    } elseif ($format == 'pdf') {
        // PDF export would require a PDF library like TCPDF or Dompdf
        $_SESSION['alert_message'] = "PDF export requires additional setup. Please use CSV export for now.";
        $_SESSION['alert_type'] = "warning";
        header("Location: laundry_export.php");
        exit();
    }
}
?>

<div class="card">
    <div class="card-header bg-dark py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-fw fa-file-export mr-2"></i>Export Laundry Data
        </h3>
        <div class="card-tools">
            <a href="laundry_management.php" class="btn btn-secondary">
                <i class="fas fa-fw fa-arrow-left mr-2"></i>Back to Laundry
            </a>
        </div>
    </div>
    
    <div class="card-body">
        <?php if (isset($_SESSION['alert_message'])): ?>
            <div class="alert alert-<?php echo $_SESSION['alert_type']; ?>">
                <i class="fas fa-info-circle mr-2"></i><?php echo $_SESSION['alert_message']; ?>
            </div>
            <?php unset($_SESSION['alert_message'], $_SESSION['alert_type']); ?>
        <?php endif; ?>
        
        <div class="row">
            <div class="col-md-8 offset-md-2">
                <div class="card">
                    <div class="card-body">
                        <form action="laundry_export.php" method="POST" autocomplete="off">
                            <div class="form-group">
                                <label>Export Type *</label>
                                <select class="form-control" name="export_type" required id="exportType" onchange="toggleDateRange()">
                                    <option value="">- Select Data to Export -</option>
                                    <option value="inventory">Inventory List</option>
                                    <option value="transactions">Transaction History</option>
                                    <option value="wash_cycles">Wash Cycles</option>
                                    <option value="categories">Categories</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label>Export Format *</label>
                                <select class="form-control" name="format" required>
                                    <option value="csv" selected>CSV (Excel Compatible)</option>
                                    <option value="pdf">PDF (Requires Setup)</option>
                                </select>
                            </div>
                            
                            <div id="dateRangeSection" style="display: none;">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label>Start Date *</label>
                                            <input type="date" class="form-control" name="start_date" 
                                                   value="<?php echo date('Y-m-01'); ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <label>End Date *</label>
                                            <input type="date" class="form-control" name="end_date" 
                                                   value="<?php echo date('Y-m-d'); ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="alert alert-info mt-4">
                                <i class="fas fa-info-circle mr-2"></i>
                                <strong>Export Information:</strong>
                                <ul class="mb-0 mt-2">
                                    <li>CSV files can be opened in Excel, Google Sheets, or any spreadsheet software</li>
                                    <li>PDF export requires additional library setup</li>
                                    <li>Large exports may take a few moments to generate</li>
                                </ul>
                            </div>
                            
                            <hr>
                            
                            <div class="form-group text-center">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="fas fa-download mr-2"></i>Export Data
                                </button>
                                <a href="laundry_management.php" class="btn btn-secondary btn-lg ml-2">
                                    <i class="fas fa-times mr-2"></i>Cancel
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Quick Export Links -->
                <div class="card mt-4">
                    <div class="card-header bg-light">
                        <h5 class="mb-0"><i class="fas fa-bolt mr-2"></i>Quick Exports</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <a href="export_csv.php?type=inventory" class="btn btn-outline-primary btn-block mb-2">
                                    <i class="fas fa-tshirt mr-2"></i>Current Inventory
                                </a>
                            </div>
                            <div class="col-md-6">
                                <a href="export_csv.php?type=today_transactions" class="btn btn-outline-success btn-block mb-2">
                                    <i class="fas fa-history mr-2"></i>Today's Transactions
                                </a>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <a href="export_csv.php?type=low_stock" class="btn btn-outline-warning btn-block mb-2">
                                    <i class="fas fa-exclamation-triangle mr-2"></i>Low Stock Items
                                </a>
                            </div>
                            <div class="col-md-6">
                                <a href="export_csv.php?type=damaged_items" class="btn btn-outline-danger btn-block mb-2">
                                    <i class="fas fa-times-circle mr-2"></i>Damaged Items
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Initialize date pickers
    $('input[type="date"]').datepicker({
        format: 'yyyy-mm-dd',
        autoclose: true,
        todayHighlight: true
    });
});

function toggleDateRange() {
    var exportType = $('#exportType').val();
    var dateRangeSection = $('#dateRangeSection');
    
    if (exportType == 'transactions' || exportType == 'wash_cycles') {
        dateRangeSection.show();
        $('input[name="start_date"], input[name="end_date"]').prop('required', true);
    } else {
        dateRangeSection.hide();
        $('input[name="start_date"], input[name="end_date"]').prop('required', false);
    }
}
</script>

<style>
.btn-block {
    padding: 10px;
}

.alert ul {
    padding-left: 20px;
}

.card {
    border: 1px solid #e9ecef;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>