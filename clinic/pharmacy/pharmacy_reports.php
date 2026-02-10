<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

$report_type = $_GET['report'] ?? 'stock';
$date_from = $_GET['dtf'] ?? date('Y-m-01');
$date_to = $_GET['dtt'] ?? date('Y-m-d');
$location_filter = $_GET['location'] ?? '';
$status_filter = $_GET['status'] ?? '';

// Generate reports based on type
switch($report_type) {
    case 'stock':
        $title = "Inventory Stock Report";
        $sql = mysqli_query($mysqli, "
            SELECT i.*, 
                   c.category_name,
                   s.supplier_name,
                   d.drug_name, d.drug_generic_name, d.drug_form, d.drug_strength,
                   COALESCE(SUM(ili.quantity), 0) as total_stock,
                   GROUP_CONCAT(DISTINCT loc.location_name SEPARATOR ', ') as locations,
                   MAX(ili.expiry_date) as next_expiry
            FROM inventory_items i
            LEFT JOIN inventory_categories c ON i.item_category_id = c.category_id
            LEFT JOIN suppliers s ON i.item_supplier_id = s.supplier_id
            LEFT JOIN drugs d ON i.drug_id = d.drug_id
            LEFT JOIN inventory_location_items ili ON i.item_id = ili.item_id
            LEFT JOIN inventory_locations loc ON ili.location_id = loc.location_id
            WHERE i.item_status != 'Discontinued'
            " . ($location_filter ? " AND loc.location_id = '$location_filter'" : "") . "
            GROUP BY i.item_id
            ORDER BY total_stock ASC, i.item_name
        ");
        break;
        
    case 'expiry':
        $title = "Expiry Report";
        $sql = mysqli_query($mysqli, "
            SELECT ili.*, 
                   i.item_name, i.item_brand, i.item_code, i.item_form,
                   i.item_unit_measure,
                   d.drug_name, d.drug_generic_name,
                   loc.location_name,
                   DATEDIFF(ili.expiry_date, CURDATE()) as days_to_expiry
            FROM inventory_location_items ili
            JOIN inventory_items i ON ili.item_id = i.item_id
            LEFT JOIN drugs d ON i.drug_id = d.drug_id
            LEFT JOIN inventory_locations loc ON ili.location_id = loc.location_id
            WHERE ili.quantity > 0
            AND ili.expiry_date IS NOT NULL
            AND ili.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)
            " . ($location_filter ? " AND loc.location_id = '$location_filter'" : "") . "
            ORDER BY ili.expiry_date ASC
        ");
        break;
        
    case 'sales':
        $title = "Sales Report";
        $sql = mysqli_query($mysqli, "
            SELECT i.item_id, i.item_name, i.item_code, i.item_form,
                   d.drug_name, d.drug_generic_name,
                   SUM(ii.item_quantity) as total_quantity,
                   SUM(ii.item_total) as total_sales,
                   COUNT(DISTINCT ii.invoice_id) as invoice_count
            FROM invoice_items ii
            JOIN invoices inv ON ii.invoice_id = inv.invoice_id
            JOIN inventory_items i ON ii.item_id = i.item_id
            LEFT JOIN drugs d ON i.drug_id = d.drug_id
            WHERE inv.invoice_date BETWEEN '$date_from' AND '$date_to'
            AND inv.invoice_status != 'Draft'
            AND inv.invoice_status != 'Cancelled'
            AND ii.category_id IN (SELECT category_id FROM invoice_categories WHERE category_name LIKE '%pharmacy%' OR category_name LIKE '%drug%' OR category_name LIKE '%medication%')
            GROUP BY i.item_id
            ORDER BY total_sales DESC
        ");
        break;
        
    case 'low_stock':
        $title = "Low Stock Report";
        $sql = mysqli_query($mysqli, "
            SELECT i.*, 
                   c.category_name,
                   d.drug_name, d.drug_generic_name,
                   COALESCE(SUM(ili.quantity), 0) as total_stock,
                   GROUP_CONCAT(DISTINCT loc.location_name SEPARATOR ', ') as locations
            FROM inventory_items i
            LEFT JOIN inventory_categories c ON i.item_category_id = c.category_id
            LEFT JOIN drugs d ON i.drug_id = d.drug_id
            LEFT JOIN inventory_location_items ili ON i.item_id = ili.item_id
            LEFT JOIN inventory_locations loc ON ili.location_id = loc.location_id
            WHERE i.item_status != 'Discontinued'
            GROUP BY i.item_id
            HAVING total_stock <= i.item_low_stock_alert OR total_stock = 0
            ORDER BY total_stock ASC
        ");
        break;
        
    case 'prescriptions':
        $title = "Prescription Report";
        $sql = mysqli_query($mysqli, "
            SELECT p.*, 
                   pat.patient_first_name, pat.patient_last_name, pat.patient_mrn,
                   u.user_name as doctor_name,
                   COUNT(pi.pi_id) as item_count,
                   SUM(pi.pi_total_price) as total_value,
                   loc.location_name as dispensed_location
            FROM prescriptions p
            LEFT JOIN patients pat ON p.prescription_patient_id = pat.patient_id
            LEFT JOIN users u ON p.prescription_doctor_id = u.user_id
            LEFT JOIN prescription_items pi ON p.prescription_id = pi.pi_prescription_id
            LEFT JOIN inventory_locations loc ON p.dispensed_location_id = loc.location_id
            WHERE p.prescription_date BETWEEN '$date_from' AND '$date_to'
            " . ($status_filter ? " AND p.prescription_status = '$status_filter'" : "") . "
            GROUP BY p.prescription_id
            ORDER BY p.prescription_date DESC
        ");
        break;
        
    case 'dispensations':
        $title = "Dispensation Report";
        $sql = mysqli_query($mysqli, "
            SELECT pi.*,
                   p.prescription_date, p.prescription_status,
                   pat.patient_first_name, pat.patient_last_name, pat.patient_mrn,
                   d.drug_name, d.drug_generic_name,
                   i.item_name, i.item_code,
                   loc.location_name,
                   u.user_name as dispensed_by
            FROM prescription_items pi
            JOIN prescriptions p ON pi.pi_prescription_id = p.prescription_id
            JOIN patients pat ON p.prescription_patient_id = pat.patient_id
            LEFT JOIN drugs d ON pi.pi_drug_id = d.drug_id
            LEFT JOIN inventory_items i ON pi.pi_inventory_item_id = i.item_id
            LEFT JOIN inventory_locations loc ON p.dispensed_location_id = loc.location_id
            LEFT JOIN users u ON p.prescription_dispensed_by = u.user_id
            WHERE pi.pi_dispensed_at IS NOT NULL
            AND DATE(pi.pi_dispensed_at) BETWEEN '$date_from' AND '$date_to'
            ORDER BY pi.pi_dispensed_at DESC
        ");
        break;
}

// Get locations for filter
$locations = mysqli_query($mysqli, "SELECT location_id, location_name FROM inventory_locations ORDER BY location_name");
?>

<div class="card">
    <div class="card-header bg-dark py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0">
                <i class="fas fa-chart-bar mr-2"></i>Pharmacy Reports
            </h3>
            <div class="card-tools">
                <div class="btn-group">
                    <button type="button" class="btn btn-primary" onclick="window.print()">
                        <i class="fas fa-print mr-2"></i>Print Report
                    </button>
                    <button type="button" class="btn btn-primary dropdown-toggle dropdown-toggle-split" data-toggle="dropdown">
                        <span class="sr-only">Toggle Dropdown</span>
                    </button>
                    <div class="dropdown-menu dropdown-menu-right">
                        <a class="dropdown-item" href="pharmacy_reports.php?report=stock">Stock Report</a>
                        <a class="dropdown-item" href="pharmacy_reports.php?report=expiry">Expiry Report</a>
                        <a class="dropdown-item" href="pharmacy_reports.php?report=sales">Sales Report</a>
                        <a class="dropdown-item" href="pharmacy_reports.php?report=low_stock">Low Stock Report</a>
                        <a class="dropdown-item" href="pharmacy_reports.php?report=prescriptions">Prescription Report</a>
                        <a class="dropdown-item" href="pharmacy_reports.php?report=dispensations">Dispensation Report</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card-body">
        <!-- Report Header -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="btn-toolbar justify-content-between">
                    <div class="btn-group">
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Report:</strong> 
                            <span class="badge badge-info ml-2"><?php echo $title; ?></span>
                        </span>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Period:</strong> 
                            <span class="badge badge-success ml-2">
                                <?php echo date('M j, Y', strtotime($date_from)); ?> - <?php echo date('M j, Y', strtotime($date_to)); ?>
                            </span>
                        </span>
                        <?php if($report_type == 'stock' || $report_type == 'expiry'): ?>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Location:</strong> 
                            <span class="badge badge-primary ml-2">
                                <?php echo $location_filter ? "Filtered" : "All Locations"; ?>
                            </span>
                        </span>
                        <?php endif; ?>
                        <?php if($report_type == 'prescriptions'): ?>
                        <span class="btn btn-outline-secondary disabled">
                            <strong>Status:</strong> 
                            <span class="badge badge-<?php 
                                echo $status_filter ? 
                                    ($status_filter == 'dispensed' ? 'success' : 
                                     ($status_filter == 'pending' ? 'warning' : 
                                     ($status_filter == 'cancelled' ? 'danger' : 'secondary'))) : 
                                    'secondary'; 
                            ?> ml-2">
                                <?php echo $status_filter ? ucfirst($status_filter) : "All Statuses"; ?>
                            </span>
                        </span>
                        <?php endif; ?>
                    </div>
                    <div class="btn-group">
                        <button type="button" class="btn btn-secondary" onclick="exportToExcel()">
                            <i class="fas fa-file-excel mr-2"></i>Export Excel
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="exportToPDF()">
                            <i class="fas fa-file-pdf mr-2"></i>Export PDF
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Report Filters -->
        <div class="card mb-4">
            <div class="card-header bg-light py-2">
                <h4 class="card-title mb-0"><i class="fas fa-filter mr-2"></i>Report Filters</h4>
            </div>
            <div class="card-body">
                <form method="get" id="reportFilterForm">
                    <input type="hidden" name="report" value="<?php echo $report_type; ?>">
                    <div class="row">
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Report Type</label>
                                <select class="form-control select2" name="report" onchange="this.form.submit()">
                                    <option value="stock" <?php echo $report_type == 'stock' ? 'selected' : ''; ?>>Inventory Stock Report</option>
                                    <option value="expiry" <?php echo $report_type == 'expiry' ? 'selected' : ''; ?>>Expiry Report</option>
                                    <option value="sales" <?php echo $report_type == 'sales' ? 'selected' : ''; ?>>Sales Report</option>
                                    <option value="low_stock" <?php echo $report_type == 'low_stock' ? 'selected' : ''; ?>>Low Stock Report</option>
                                    <option value="prescriptions" <?php echo $report_type == 'prescriptions' ? 'selected' : ''; ?>>Prescription Report</option>
                                    <option value="dispensations" <?php echo $report_type == 'dispensations' ? 'selected' : ''; ?>>Dispensation Report</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="form-group">
                                <label>From Date</label>
                                <input type="date" class="form-control" name="dtf" value="<?php echo $date_from; ?>" onchange="this.form.submit()">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="form-group">
                                <label>To Date</label>
                                <input type="date" class="form-control" name="dtt" value="<?php echo $date_to; ?>" onchange="this.form.submit()">
                            </div>
                        </div>
                        <?php if($report_type == 'stock' || $report_type == 'expiry'): ?>
                        <div class="col-md-2">
                            <div class="form-group">
                                <label>Location</label>
                                <select class="form-control select2" name="location" onchange="this.form.submit()">
                                    <option value="">All Locations</option>
                                    <?php while($loc = mysqli_fetch_assoc($locations)): 
                                        $selected = $location_filter == $loc['location_id'] ? 'selected' : '';
                                    ?>
                                        <option value="<?php echo $loc['location_id']; ?>" <?php echo $selected; ?>>
                                            <?php echo htmlspecialchars($loc['location_name']); ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php if($report_type == 'prescriptions'): ?>
                        <div class="col-md-2">
                            <div class="form-group">
                                <label>Status</label>
                                <select class="form-control select2" name="status" onchange="this.form.submit()">
                                    <option value="">All Statuses</option>
                                    <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="active" <?php echo $status_filter == 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="dispensed" <?php echo $status_filter == 'dispensed' ? 'selected' : ''; ?>>Dispensed</option>
                                    <option value="partial" <?php echo $status_filter == 'partial' ? 'selected' : ''; ?>>Partial</option>
                                    <option value="completed" <?php echo $status_filter == 'completed' ? 'selected' : ''; ?>>Completed</option>
                                    <option value="cancelled" <?php echo $status_filter == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                </select>
                            </div>
                        </div>
                        <?php endif; ?>
                        <div class="col-md-2">
                            <div class="form-group">
                                <label>&nbsp;</label>
                                <div class="d-grid gap-2">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-sync-alt mr-2"></i>Update
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Report Content -->
        <div class="card">
            <div class="card-header bg-light py-2">
                <h4 class="card-title mb-0"><i class="fas fa-table mr-2"></i>Report Data</h4>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped table-bordered" id="reportTable">
                        <thead class="thead-dark">
                            <?php if($report_type == 'stock'): ?>
                            <tr>
                                <th>Item Name</th>
                                <th>Drug Info</th>
                                <th class="text-center">Current Stock</th>
                                <th class="text-center">Low Stock Alert</th>
                                <th class="text-center">Status</th>
                                <th class="text-right">Unit Price</th>
                                <th>Locations</th>
                                <th>Next Expiry</th>
                            </tr>
                            <?php elseif($report_type == 'expiry'): ?>
                            <tr>
                                <th>Item Name</th>
                                <th>Drug Info</th>
                                <th>Batch Number</th>
                                <th>Location</th>
                                <th class="text-center">Quantity</th>
                                <th>Expiry Date</th>
                                <th class="text-center">Days to Expiry</th>
                                <th>Status</th>
                            </tr>
                            <?php elseif($report_type == 'sales'): ?>
                            <tr>
                                <th>Item Name</th>
                                <th>Drug Info</th>
                                <th class="text-center">Quantity Sold</th>
                                <th class="text-center">Invoices</th>
                                <th class="text-right">Total Sales</th>
                                <th class="text-right">Avg. Price</th>
                            </tr>
                            <?php elseif($report_type == 'low_stock'): ?>
                            <tr>
                                <th>Item Name</th>
                                <th>Drug Info</th>
                                <th class="text-center">Current Stock</th>
                                <th class="text-center">Low Stock Alert</th>
                                <th class="text-center">Deficit</th>
                                <th class="text-center">Status</th>
                                <th>Locations</th>
                            </tr>
                            <?php elseif($report_type == 'prescriptions'): ?>
                            <tr>
                                <th>Date</th>
                                <th>Patient</th>
                                <th>MRN</th>
                                <th>Doctor</th>
                                <th class="text-center">Items</th>
                                <th class="text-right">Total Value</th>
                                <th>Status</th>
                                <th>Dispensed Location</th>
                            </tr>
                            <?php elseif($report_type == 'dispensations'): ?>
                            <tr>
                                <th>Dispensed Date</th>
                                <th>Patient</th>
                                <th>MRN</th>
                                <th>Drug/Item</th>
                                <th class="text-center">Quantity</th>
                                <th class="text-right">Unit Price</th>
                                <th class="text-right">Total</th>
                                <th>Location</th>
                                <th>Dispensed By</th>
                            </tr>
                            <?php endif; ?>
                        </thead>
                        <tbody>
                            <?php 
                            $total_sales = 0;
                            $total_quantity = 0;
                            $total_prescription_value = 0;
                            $total_dispensation_value = 0;
                            
                            if(mysqli_num_rows($sql) > 0):
                            while($row = mysqli_fetch_assoc($sql)): 
                                switch($report_type):
                                    case 'stock':
                                        $stock_status = '';
                                        if ($row['total_stock'] == 0) {
                                            $stock_status = '<span class="badge badge-danger">Out of Stock</span>';
                                        } elseif ($row['total_stock'] <= $row['item_low_stock_alert']) {
                                            $stock_status = '<span class="badge badge-warning">Low Stock</span>';
                                        } else {
                                            $stock_status = '<span class="badge badge-success">Adequate</span>';
                                        }
                                        $drug_info = '';
                                        if($row['drug_name']) {
                                            $drug_info = $row['drug_name'];
                                            if($row['drug_generic_name']) {
                                                $drug_info .= '<br><small class="text-muted">(' . $row['drug_generic_name'] . ')</small>';
                                            }
                                        }
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($row['item_name']); ?></strong>
                                    <?php if($row['item_brand']): ?>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($row['item_brand']); ?></small>
                                    <?php endif; ?>
                                    <?php if($row['item_code']): ?>
                                        <br><small class="text-muted">Code: <?php echo htmlspecialchars($row['item_code']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $drug_info; ?></td>
                                <td class="text-center"><?php echo $row['total_stock']; ?> <?php echo $row['item_unit_measure'] ?: 'pcs'; ?></td>
                                <td class="text-center"><?php echo $row['item_low_stock_alert']; ?></td>
                                <td class="text-center"><?php echo $stock_status; ?></td>
                                <td class="text-right"><?php echo numfmt_format_currency($currency_format, $row['item_unit_price'], $session_company_currency); ?></td>
                                <td><small><?php echo $row['locations'] ?: 'No location assigned'; ?></small></td>
                                <td>
                                    <?php if($row['next_expiry']): ?>
                                        <?php echo date('M j, Y', strtotime($row['next_expiry'])); ?>
                                    <?php else: ?>
                                        <span class="text-muted">N/A</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php
                                    break;
                                    
                                case 'expiry':
                                    $expiry_status = '';
                                    if ($row['days_to_expiry'] < 0) {
                                        $expiry_status = '<span class="badge badge-danger">EXPIRED</span>';
                                    } elseif ($row['days_to_expiry'] <= 30) {
                                        $expiry_status = '<span class="badge badge-warning">Expiring Soon</span>';
                                    } else {
                                        $expiry_status = '<span class="badge badge-success">Good</span>';
                                    }
                                    $drug_info = '';
                                    if($row['drug_name']) {
                                        $drug_info = $row['drug_name'];
                                        if($row['drug_generic_name']) {
                                            $drug_info .= '<br><small class="text-muted">(' . $row['drug_generic_name'] . ')</small>';
                                        }
                                    }
                            ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($row['item_name']); ?></strong></td>
                                <td><?php echo $drug_info; ?></td>
                                <td><?php echo $row['batch_number'] ?: 'N/A'; ?></td>
                                <td><?php echo htmlspecialchars($row['location_name']); ?></td>
                                <td class="text-center"><?php echo $row['quantity']; ?> <?php echo $row['item_unit_measure'] ?: 'pcs'; ?></td>
                                <td><?php echo date('M j, Y', strtotime($row['expiry_date'])); ?></td>
                                <td class="text-center <?php echo $row['days_to_expiry'] <= 30 ? 'text-warning font-weight-bold' : ''; ?>">
                                    <?php echo $row['days_to_expiry']; ?> days
                                </td>
                                <td class="text-center"><?php echo $expiry_status; ?></td>
                            </tr>
                            <?php
                                    break;
                                    
                                case 'sales':
                                    $total_sales += $row['total_sales'];
                                    $total_quantity += $row['total_quantity'];
                                    $avg_price = $row['total_quantity'] > 0 ? $row['total_sales'] / $row['total_quantity'] : 0;
                                    $drug_info = '';
                                    if($row['drug_name']) {
                                        $drug_info = $row['drug_name'];
                                        if($row['drug_generic_name']) {
                                            $drug_info .= '<br><small class="text-muted">(' . $row['drug_generic_name'] . ')</small>';
                                        }
                                    }
                            ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($row['item_name']); ?></strong></td>
                                <td><?php echo $drug_info; ?></td>
                                <td class="text-center"><?php echo $row['total_quantity']; ?></td>
                                <td class="text-center"><?php echo $row['invoice_count']; ?></td>
                                <td class="text-right"><?php echo numfmt_format_currency($currency_format, $row['total_sales'], $session_company_currency); ?></td>
                                <td class="text-right"><?php echo numfmt_format_currency($currency_format, $avg_price, $session_company_currency); ?></td>
                            </tr>
                            <?php
                                    break;
                                    
                                case 'low_stock':
                                    $deficit = $row['item_low_stock_alert'] - $row['total_stock'];
                                    $status_class = $row['total_stock'] == 0 ? 'danger' : 'warning';
                                    $drug_info = '';
                                    if($row['drug_name']) {
                                        $drug_info = $row['drug_name'];
                                        if($row['drug_generic_name']) {
                                            $drug_info .= '<br><small class="text-muted">(' . $row['drug_generic_name'] . ')</small>';
                                        }
                                    }
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($row['item_name']); ?></strong>
                                    <?php if($row['item_code']): ?>
                                        <br><small class="text-muted">Code: <?php echo htmlspecialchars($row['item_code']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $drug_info; ?></td>
                                <td class="text-center text-<?php echo $status_class; ?> font-weight-bold">
                                    <?php echo $row['total_stock']; ?> <?php echo $row['item_unit_measure'] ?: 'pcs'; ?>
                                </td>
                                <td class="text-center"><?php echo $row['item_low_stock_alert']; ?></td>
                                <td class="text-center text-<?php echo $status_class; ?> font-weight-bold">
                                    <?php echo $deficit > 0 ? $deficit : 0; ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge badge-<?php echo $status_class; ?>">
                                        <?php echo $row['total_stock'] == 0 ? 'OUT OF STOCK' : 'LOW STOCK'; ?>
                                    </span>
                                </td>
                                <td><small><?php echo $row['locations'] ?: 'No location assigned'; ?></small></td>
                            </tr>
                            <?php
                                    break;
                                    
                                case 'prescriptions':
                                    $total_prescription_value += $row['total_value'];
                                    $status_badge = [
                                        'pending' => 'warning',
                                        'active' => 'info',
                                        'dispensed' => 'success',
                                        'partial' => 'primary',
                                        'completed' => 'secondary',
                                        'cancelled' => 'danger'
                                    ][$row['prescription_status']] ?? 'secondary';
                            ?>
                            <tr>
                                <td><?php echo date('M j, Y', strtotime($row['prescription_date'])); ?></td>
                                <td><?php echo htmlspecialchars($row['patient_first_name'] . ' ' . $row['patient_last_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['patient_mrn']); ?></td>
                                <td><?php echo htmlspecialchars($row['doctor_name']); ?></td>
                                <td class="text-center"><?php echo $row['item_count']; ?></td>
                                <td class="text-right"><?php echo numfmt_format_currency($currency_format, $row['total_value'], $session_company_currency); ?></td>
                                <td><span class="badge badge-<?php echo $status_badge; ?>"><?php echo ucfirst($row['prescription_status']); ?></span></td>
                                <td><?php echo $row['dispensed_location'] ?: 'N/A'; ?></td>
                            </tr>
                            <?php
                                    break;
                                    
                                case 'dispensations':
                                    $total_dispensation_value += $row['pi_total_price'];
                                    $drug_info = '';
                                    if($row['drug_name']) {
                                        $drug_info = $row['drug_name'];
                                        if($row['drug_generic_name']) {
                                            $drug_info .= '<br><small class="text-muted">(' . $row['drug_generic_name'] . ')</small>';
                                        }
                                    } elseif($row['item_name']) {
                                        $drug_info = $row['item_name'];
                                        if($row['item_code']) {
                                            $drug_info .= '<br><small class="text-muted">Code: ' . $row['item_code'] . '</small>';
                                        }
                                    }
                            ?>
                            <tr>
                                <td><?php echo date('M j, Y g:i A', strtotime($row['pi_dispensed_at'])); ?></td>
                                <td><?php echo htmlspecialchars($row['patient_first_name'] . ' ' . $row['patient_last_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['patient_mrn']); ?></td>
                                <td><?php echo $drug_info; ?></td>
                                <td class="text-center"><?php echo $row['pi_quantity']; ?></td>
                                <td class="text-right"><?php echo numfmt_format_currency($currency_format, $row['pi_unit_price'], $session_company_currency); ?></td>
                                <td class="text-right"><?php echo numfmt_format_currency($currency_format, $row['pi_total_price'], $session_company_currency); ?></td>
                                <td><?php echo htmlspecialchars($row['location_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['dispensed_by']); ?></td>
                            </tr>
                            <?php
                                    break;
                                endswitch;
                            endwhile;
                            else: ?>
                            <tr>
                                <td colspan="<?php echo $report_type == 'stock' ? 8 : ($report_type == 'expiry' ? 8 : ($report_type == 'sales' ? 6 : ($report_type == 'low_stock' ? 7 : ($report_type == 'prescriptions' ? 8 : 9)))); ?>" class="text-center">
                                    <div class="py-5 text-muted">
                                        <i class="fas fa-database fa-3x mb-3"></i>
                                        <h4>No Data Found</h4>
                                        <p>No records match your filter criteria.</p>
                                    </div>
                                </td>
                            </tr>
                            <?php endif; ?>
                            
                            <!-- Show totals for relevant reports -->
                            <?php if($report_type == 'sales' && $total_sales > 0): ?>
                            <tr class="table-success">
                                <td colspan="2" class="text-right"><strong>Totals:</strong></td>
                                <td class="text-center"><strong><?php echo $total_quantity; ?></strong></td>
                                <td class="text-center">-</td>
                                <td class="text-right"><strong><?php echo numfmt_format_currency($currency_format, $total_sales, $session_company_currency); ?></strong></td>
                                <td class="text-right">-</td>
                            </tr>
                            <?php elseif($report_type == 'prescriptions' && $total_prescription_value > 0): ?>
                            <tr class="table-info">
                                <td colspan="5" class="text-right"><strong>Total Prescription Value:</strong></td>
                                <td class="text-right"><strong><?php echo numfmt_format_currency($currency_format, $total_prescription_value, $session_company_currency); ?></strong></td>
                                <td colspan="2"></td>
                            </tr>
                            <?php elseif($report_type == 'dispensations' && $total_dispensation_value > 0): ?>
                            <tr class="table-success">
                                <td colspan="6" class="text-right"><strong>Total Dispensed Value:</strong></td>
                                <td class="text-right"><strong><?php echo numfmt_format_currency($currency_format, $total_dispensation_value, $session_company_currency); ?></strong></td>
                                <td colspan="2"></td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Report Summary -->
        <?php 
        // Generate summary stats
        $summary = [];
        if($report_type == 'stock') {
            $stats_sql = mysqli_query($mysqli, "
                SELECT 
                    COUNT(DISTINCT i.item_id) as total_items,
                    SUM(CASE WHEN total_stock <= i.item_low_stock_alert THEN 1 ELSE 0 END) as low_stock_items,
                    SUM(CASE WHEN total_stock = 0 THEN 1 ELSE 0 END) as out_of_stock_items,
                    COALESCE(SUM(total_stock * i.item_unit_price), 0) as total_inventory_value
                FROM inventory_items i
                LEFT JOIN (
                    SELECT item_id, SUM(quantity) as total_stock 
                    FROM inventory_location_items 
                    GROUP BY item_id
                ) ili ON i.item_id = ili.item_id
                WHERE i.item_status != 'Discontinued'
            ");
            $summary = mysqli_fetch_assoc($stats_sql);
        } elseif($report_type == 'expiry') {
            $stats_sql = mysqli_query($mysqli, "
                SELECT 
                    COUNT(*) as expiring_items,
                    SUM(CASE WHEN days_to_expiry <= 30 THEN 1 ELSE 0 END) as expiring_soon,
                    SUM(ili.quantity * i.item_unit_price) as expiring_value
                FROM inventory_location_items ili
                JOIN inventory_items i ON ili.item_id = i.item_id
                WHERE ili.quantity > 0
                AND ili.expiry_date IS NOT NULL
                AND ili.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)
            ");
            $summary = mysqli_fetch_assoc($stats_sql);
        }
        ?>
        
        <?php if(!empty($summary)): ?>
        <div class="row mt-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-light py-2">
                        <h5 class="card-title mb-0"><i class="fas fa-chart-pie mr-2"></i>Report Summary</h5>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <?php if($report_type == 'stock'): ?>
                            <div class="col-md-3">
                                <h3 class="text-primary"><?php echo $summary['total_items']; ?></h3>
                                <p class="text-muted">Total Items</p>
                            </div>
                            <div class="col-md-3">
                                <h3 class="text-warning"><?php echo $summary['low_stock_items']; ?></h3>
                                <p class="text-muted">Low Stock Items</p>
                            </div>
                            <div class="col-md-3">
                                <h3 class="text-danger"><?php echo $summary['out_of_stock_items']; ?></h3>
                                <p class="text-muted">Out of Stock</p>
                            </div>
                            <div class="col-md-3">
                                <h3 class="text-success"><?php echo numfmt_format_currency($currency_format, $summary['total_inventory_value'], $session_company_currency); ?></h3>
                                <p class="text-muted">Total Inventory Value</p>
                            </div>
                            <?php elseif($report_type == 'expiry'): ?>
                            <div class="col-md-4">
                                <h3 class="text-warning"><?php echo $summary['expiring_items']; ?></h3>
                                <p class="text-muted">Expiring Items (90 days)</p>
                            </div>
                            <div class="col-md-4">
                                <h3 class="text-danger"><?php echo $summary['expiring_soon']; ?></h3>
                                <p class="text-muted">Expiring Soon (30 days)</p>
                            </div>
                            <div class="col-md-4">
                                <h3 class="text-info"><?php echo numfmt_format_currency($currency_format, $summary['expiring_value'], $session_company_currency); ?></h3>
                                <p class="text-muted">Expiring Inventory Value</p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
@media print {
    .card-header, .btn-group, form, .btn-toolbar, .report-summary-card {
        display: none !important;
    }
    .card {
        border: none !important;
    }
    .table th {
        background-color: #f8f9fa !important;
        color: #000 !important;
    }
    h3.card-title {
        margin-bottom: 20px !important;
    }
}
.report-header {
    background-color: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
}
</style>

<script>
$(document).ready(function() {
    // Initialize Select2
    $('.select2').select2({
        theme: 'bootstrap',
        width: '100%'
    });

    // Initialize DataTable for better table features
    $('#reportTable').DataTable({
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "All"]],
        dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>tip',
        language: {
            search: "_INPUT_",
            searchPlaceholder: "Search report...",
            lengthMenu: "_MENU_ records per page",
            info: "Showing _START_ to _END_ of _TOTAL_ entries",
            infoEmpty: "Showing 0 to 0 of 0 entries",
            infoFiltered: "(filtered from _MAX_ total entries)"
        }
    });
});

// Export functions
function exportToExcel() {
    const table = document.getElementById('reportTable');
    const html = table.outerHTML;
    const blob = new Blob([html], { type: 'application/vnd.ms-excel' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'pharmacy_report_<?php echo $report_type; ?>_<?php echo date('Y-m-d'); ?>.xls';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

function exportToPDF() {
    // This would require a PDF library like jsPDF or server-side PDF generation
    alert('PDF export feature requires additional setup. Please use the print function or contact your system administrator.');
}

// Auto-refresh report every 5 minutes
setTimeout(function() {
    location.reload();
}, 300000); // 5 minutes
</script>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>