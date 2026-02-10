<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Default Column Sortby/Order Filter
$sort = "invoice_date";
$order = "DESC";

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Status Filter - Show all invoice statuses by default
$status_filter = $_GET['status'] ?? 'all';
if (isset($_GET['status']) && !empty($_GET['status']) && $_GET['status'] != 'all') {
    $status_query = "AND (i.invoice_status = '" . sanitizeInput($_GET['status']) . "')";
    $status_filter = sanitizeInput($_GET['status']);
} else {
    $status_query = '';
    $status_filter = 'all';
}

// Invoice Type Filter - Based on price_list_type in new schema
if (isset($_GET['type']) && !empty($_GET['type']) && $_GET['type'] != 'all') {
    $type_query = "AND (i.price_list_type = '" . sanitizeInput($_GET['type']) . "')";
    $type_filter = nullable_htmlentities($_GET['type']);
} else {
    $type_query = '';
    $type_filter = 'all';
}

// Date Range Filter
$dtf = sanitizeInput($_GET['dtf'] ?? '');
$dtt = sanitizeInput($_GET['dtt'] ?? '');

if (!empty($dtf) && !empty($dtt)) {
    $date_query = "AND DATE(i.invoice_date) BETWEEN '$dtf' AND '$dtt'";
} else {
    $date_query = '';
}

// Search query
$search_query = '';
if (isset($_GET['q']) && !empty($_GET['q'])) {
    $q = sanitizeInput($_GET['q']);
    $search_query = "AND (
        i.patient_name LIKE '%$q%' 
        OR i.patient_identifier LIKE '%$q%' 
        OR i.invoice_number LIKE '%$q%'
        OR i.price_list_name LIKE '%$q%'
    )";
}

// Main query for invoices - UPDATED for new schema
$sql = mysqli_query(
    $mysqli,
    "
    SELECT SQL_CALC_FOUND_ROWS 
        i.*,
        p.patient_id as patient_db_id,
        p.first_name,
        p.last_name,
        p.phone_primary,
        p.email,
        u.user_name AS created_by_name,
        DATEDIFF(CURDATE(), i.due_date) AS days_overdue,
        i.amount_due as balance_due,
        CASE 
            WHEN i.invoice_status = 'paid' THEN 'Paid'
            WHEN i.invoice_status = 'partially_paid' THEN 'Partially Paid'
            WHEN i.invoice_status = 'issued' AND i.due_date < CURDATE() THEN 'Overdue'
            WHEN i.invoice_status = 'issued' THEN 'Issued'
            WHEN i.invoice_status = 'cancelled' THEN 'Cancelled'
            WHEN i.invoice_status = 'refunded' THEN 'Refunded'
            ELSE i.invoice_status
        END as display_status
    FROM invoices i 
    LEFT JOIN patients p ON i.patient_id = p.patient_id
    LEFT JOIN users u ON i.created_by = u.user_id
    WHERE 1=1
      $status_query
      $type_query
      $date_query
      $search_query
    ORDER BY $sort $order
    LIMIT $record_from, $record_to
");

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Statistics query for all invoices - UPDATED for new schema
$stats_sql = mysqli_query(
    $mysqli,
    "
    SELECT 
        COUNT(*) as total_invoices,
        SUM(i.total_amount) as total_amount,
        SUM(i.amount_paid) as total_paid,
        SUM(i.amount_due) as total_balance,
        SUM(CASE WHEN i.price_list_type = 'insurance' THEN 1 ELSE 0 END) as insurance_count,
        SUM(CASE WHEN i.price_list_type = 'self_pay' THEN 1 ELSE 0 END) as self_pay_count,
        SUM(CASE WHEN i.price_list_type = 'cash' THEN 1 ELSE 0 END) as cash_count,
        SUM(CASE WHEN i.price_list_type = 'corporate' THEN 1 ELSE 0 END) as corporate_count,
        SUM(CASE WHEN i.invoice_status = 'issued' AND i.due_date >= CURDATE() THEN 1 ELSE 0 END) as issued_count,
        SUM(CASE WHEN i.invoice_status = 'issued' AND i.due_date < CURDATE() THEN 1 ELSE 0 END) as overdue_count,
        SUM(CASE WHEN i.invoice_status = 'partially_paid' THEN 1 ELSE 0 END) as partially_paid_count,
        SUM(CASE WHEN i.invoice_status = 'paid' THEN 1 ELSE 0 END) as paid_count,
        SUM(CASE WHEN i.invoice_status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count,
        SUM(CASE WHEN i.invoice_status = 'refunded' THEN 1 ELSE 0 END) as refunded_count
    FROM invoices i
    WHERE 1=1
      $status_query
      $type_query
      $date_query
      $search_query
"
);

$stats = mysqli_fetch_assoc($stats_sql);

?>

<div class="card">
    <div class="card-header bg-dark py-2">
        <h3 class="card-title mt-2"><i class="fa fa-fw fa-file-invoice mr-2"></i>All Invoices</h3>
        <div class="card-tools">
            <a href="billing_invoice_create.php" class="btn btn-primary">
                <i class="fas fa-plus mr-2"></i>New Invoice
            </a>
        </div>
    </div>
    
    <!-- Statistics Row -->
    <div class="card-body border-bottom">
        <div class="row text-center">
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-secondary"><i class="fas fa-file-invoice"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Invoices</span>
                        <span class="info-box-number"><?php echo $stats['total_invoices']; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-warning"><i class="fas fa-dollar-sign"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Amount</span>
                        <span class="info-box-number">KSH <?php echo number_format($stats['total_amount'] ?? 0, 2); ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-success"><i class="fas fa-money-bill-wave"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Paid</span>
                        <span class="info-box-number">KSH <?php echo number_format($stats['total_paid'] ?? 0, 2); ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-danger"><i class="fas fa-balance-scale"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Balance Due</span>
                        <span class="info-box-number">KSH <?php echo number_format($stats['total_balance'] ?? 0, 2); ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-primary"><i class="fas fa-hospital"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Insurance</span>
                        <span class="info-box-number"><?php echo $stats['insurance_count']; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-info"><i class="fas fa-user"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Patient Pay</span>
                        <span class="info-box-number"><?php echo $stats['self_pay_count'] + $stats['cash_count']; ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Status Summary - UPDATED for new status values -->
        <div class="row text-center mt-3">
            <div class="col-md-12">
                <div class="btn-group btn-group-toggle" data-toggle="buttons">
                    <a href="?status=all<?php echo isset($q) ? '&q=' . urlencode($q) : ''; ?><?php echo isset($type_filter) && $type_filter != 'all' ? '&type=' . $type_filter : ''; ?>" class="btn btn-outline-secondary <?php echo $status_filter == 'all' ? 'active' : ''; ?>">
                        All <span class="badge badge-light"><?php echo $stats['total_invoices']; ?></span>
                    </a>
                    <a href="?status=issued<?php echo isset($q) ? '&q=' . urlencode($q) : ''; ?><?php echo isset($type_filter) && $type_filter != 'all' ? '&type=' . $type_filter : ''; ?>" class="btn btn-outline-warning <?php echo $status_filter == 'issued' ? 'active' : ''; ?>">
                        Issued <span class="badge badge-light"><?php echo $stats['issued_count']; ?></span>
                    </a>
                    <a href="?status=overdue<?php echo isset($q) ? '&q=' . urlencode($q) : ''; ?><?php echo isset($type_filter) && $type_filter != 'all' ? '&type=' . $type_filter : ''; ?>" class="btn btn-outline-danger <?php echo $status_filter == 'overdue' ? 'active' : ''; ?>">
                        Overdue <span class="badge badge-light"><?php echo $stats['overdue_count']; ?></span>
                    </a>
                    <a href="?status=partially_paid<?php echo isset($q) ? '&q=' . urlencode($q) : ''; ?><?php echo isset($type_filter) && $type_filter != 'all' ? '&type=' . $type_filter : ''; ?>" class="btn btn-outline-info <?php echo $status_filter == 'partially_paid' ? 'active' : ''; ?>">
                        Partial <span class="badge badge-light"><?php echo $stats['partially_paid_count']; ?></span>
                    </a>
                    <a href="?status=paid<?php echo isset($q) ? '&q=' . urlencode($q) : ''; ?><?php echo isset($type_filter) && $type_filter != 'all' ? '&type=' . $type_filter : ''; ?>" class="btn btn-outline-success <?php echo $status_filter == 'paid' ? 'active' : ''; ?>">
                        Paid <span class="badge badge-light"><?php echo $stats['paid_count']; ?></span>
                    </a>
                    <a href="?status=cancelled<?php echo isset($q) ? '&q=' . urlencode($q) : ''; ?><?php echo isset($type_filter) && $type_filter != 'all' ? '&type=' . $type_filter : ''; ?>" class="btn btn-outline-dark <?php echo $status_filter == 'cancelled' ? 'active' : ''; ?>">
                        Cancelled <span class="badge badge-light"><?php echo $stats['cancelled_count']; ?></span>
                    </a>
                    <a href="?status=refunded<?php echo isset($q) ? '&q=' . urlencode($q) : ''; ?><?php echo isset($type_filter) && $type_filter != 'all' ? '&type=' . $type_filter : ''; ?>" class="btn btn-outline-secondary <?php echo $status_filter == 'refunded' ? 'active' : ''; ?>">
                        Refunded <span class="badge badge-light"><?php echo $stats['refunded_count']; ?></span>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="card-header pb-2 pt-3">
        <form autocomplete="off">
            <input type="hidden" name="status" value="<?php echo $status_filter; ?>">
            <div class="row">
                <div class="col-md-5">
                    <div class="form-group">
                        <div class="input-group">
                            <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search invoices, patients, identifier..." autofocus>
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
                            <a href="billing_draft_invoices.php" class="btn btn-outline-primary">
                                <i class="fa fa-fw fa-file-alt mr-2"></i>Draft Invoices
                            </a>
                            <a href="billing_payments.php" class="btn btn-outline-info">
                                <i class="fa fa-fw fa-money-bill-wave mr-2"></i>Payments
                            </a>
                            <a href="billing_dashboard.php" class="btn btn-outline-secondary">
                                <i class="fa fa-fw fa-tachometer-alt mr-2"></i>Billing Dashboard
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse <?php if (isset($_GET['dtf']) || $type_filter != 'all' || isset($_GET['q']) || $status_filter != 'all') { echo "show"; } ?>" id="advancedFilter">
                <div class="row">
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Date Range</label>
                            <select onchange="this.form.submit()" class="form-control select2" name="canned_date">
                                <option <?php if (($_GET['canned_date'] ?? '') == "custom") { echo "selected"; } ?> value="custom">Custom</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "today") { echo "selected"; } ?> value="today">Today</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "yesterday") { echo "selected"; } ?> value="yesterday">Yesterday</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "thisweek") { echo "selected"; } ?> value="thisweek">This Week</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "lastweek") { echo "selected"; } ?> value="lastweek">Last Week</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "thismonth") { echo "selected"; } ?>value="thismonth">This Month</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "lastmonth") { echo "selected"; } ?> value="lastmonth">Last Month</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "thisyear") { echo "selected"; } ?> value="thisyear">This Year</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "lastyear") { echo "selected"; } ?> value="lastyear">Last Year</option>
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
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Invoice Type</label>
                            <select class="form-control select2" name="type" onchange="this.form.submit()">
                                <option value="all" <?php if ($type_filter == "all") { echo "selected"; } ?>>- All Types -</option>
                                <option value="insurance" <?php if ($type_filter == "insurance") { echo "selected"; } ?>>Insurance</option>
                                <option value="self_pay" <?php if ($type_filter == "self_pay") { echo "selected"; } ?>>Self Pay</option>
                                <option value="cash" <?php if ($type_filter == "cash") { echo "selected"; } ?>>Cash</option>
                                <option value="corporate" <?php if ($type_filter == "corporate") { echo "selected"; } ?>>Corporate</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Actions</label>
                            <div>
                                <a href="billing_invoices.php" class="btn btn-secondary btn-block">Clear Filters</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
    
    <div class="table-responsive-sm">
        <table class="table table-hover mb-0 text-nowrap">
            <thead class="<?php if ($num_rows[0] == 0) { echo "d-none"; } ?> bg-light">
            <tr>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=i.invoice_number&order=<?php echo $disp; ?>">
                        Invoice # <?php if ($sort == 'i.invoice_number') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=i.price_list_type&order=<?php echo $disp; ?>">
                        Type <?php if ($sort == 'i.price_list_type') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=i.patient_name&order=<?php echo $disp; ?>">
                        Patient <?php if ($sort == 'i.patient_name') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=i.invoice_date&order=<?php echo $disp; ?>">
                        Date <?php if ($sort == 'i.invoice_date') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=i.due_date&order=<?php echo $disp; ?>">
                        Due Date <?php if ($sort == 'i.due_date') { echo $order_icon; } ?>
                    </a>
                </th>
                <th class="text-right">
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=i.total_amount&order=<?php echo $disp; ?>">
                        Total <?php if ($sort == 'i.total_amount') { echo $order_icon; } ?>
                    </a>
                </th>
                <th class="text-right">
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=i.amount_due&order=<?php echo $disp; ?>">
                        Balance <?php if ($sort == 'i.amount_due') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=i.invoice_status&order=<?php echo $disp; ?>">
                        Status <?php if ($sort == 'i.invoice_status') { echo $order_icon; } ?>
                    </a>
                </th>
                <th class="text-center">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php

            if ($num_rows[0] > 0) {
                while ($row = mysqli_fetch_array($sql)) {
                    $invoice_id = intval($row['invoice_id']);
                    $invoice_number = nullable_htmlentities($row['invoice_number']);
                    $price_list_type = nullable_htmlentities($row['price_list_type']);
                    $price_list_name = nullable_htmlentities($row['price_list_name']);
                    $patient_name = nullable_htmlentities($row['patient_name']);
                    $patient_identifier = nullable_htmlentities($row['patient_identifier']);
                    $invoice_date = nullable_htmlentities($row['invoice_date']);
                    $due_date = nullable_htmlentities($row['due_date']);
                    $total_amount = floatval($row['total_amount']);
                    $amount_paid = floatval($row['amount_paid'] ?? 0);
                    $balance_due = floatval($row['balance_due'] ?? 0);
                    $invoice_status = nullable_htmlentities($row['invoice_status']);
                    $display_status = nullable_htmlentities($row['display_status']);
                    $days_overdue = intval($row['days_overdue']);
                    $subtotal_amount = floatval($row['subtotal_amount']);
                    $discount_amount = floatval($row['discount_amount']);
                    $tax_amount = floatval($row['tax_amount']);
                    $payment_method = nullable_htmlentities($row['payment_method'] ?? '');
                    $transaction_reference = nullable_htmlentities($row['transaction_reference'] ?? '');
                    $notes = nullable_htmlentities($row['notes'] ?? '');

                    // Type badge styling - UPDATED for new types
                    $type_badge = "";
                    switch($price_list_type) {
                        case 'insurance':
                            $type_badge = "badge-primary";
                            break;
                        case 'self_pay':
                            $type_badge = "badge-success";
                            break;
                        case 'cash':
                            $type_badge = "badge-warning";
                            break;
                        case 'corporate':
                            $type_badge = "badge-info";
                            break;
                        default:
                            $type_badge = "badge-secondary";
                    }

                    // Status badge styling - UPDATED for new statuses
                    $status_badge = "badge-secondary";
                    switch($invoice_status) {
                        case 'issued':
                            $status_badge = $days_overdue > 0 ? "badge-danger" : "badge-warning";
                            break;
                        case 'partially_paid':
                            $status_badge = "badge-info";
                            break;
                        case 'paid':
                            $status_badge = "badge-success";
                            break;
                        case 'cancelled':
                            $status_badge = "badge-dark";
                            break;
                        case 'refunded':
                            $status_badge = "badge-secondary";
                            break;
                    }

                    ?>
                    <tr>
                        <td>
                            <div class="font-weight-bold"><?php echo $invoice_number; ?></div>
                            <?php if (!empty($patient_identifier)): ?>
                                <small class="text-muted">ID: <?php echo $patient_identifier; ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?php echo $type_badge; ?>">
                                <?php 
                                switch($price_list_type) {
                                    case 'insurance': echo 'Insurance'; break;
                                    case 'self_pay': echo 'Self-Pay'; break;
                                    case 'cash': echo 'Cash'; break;
                                    case 'corporate': echo 'Corporate'; break;
                                    default: echo ucfirst($price_list_type);
                                }
                                ?>
                            </span>
                            <?php if (!empty($price_list_name)): ?>
                                <small class="text-muted d-block"><?php echo $price_list_name; ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="font-weight-bold"><?php echo $patient_name ?: 'N/A'; ?></div>
                            <?php if (!empty($patient_identifier)): ?>
                                <small class="text-muted"><?php echo $patient_identifier; ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="font-weight-bold"><?php echo date('M j, Y', strtotime($invoice_date)); ?></div>
                            <?php if (!empty($row['created_by_name'])): ?>
                                <small class="text-muted">By: <?php echo $row['created_by_name']; ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="font-weight-bold <?php echo $days_overdue > 0 && $invoice_status == 'issued' ? 'text-danger' : ''; ?>">
                                <?php echo $due_date ? date('M j, Y', strtotime($due_date)) : 'N/A'; ?>
                                <?php if ($days_overdue > 0 && $invoice_status == 'issued'): ?>
                                    <small class="text-danger d-block"><?php echo $days_overdue; ?> days overdue</small>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="text-right">
                            <div class="font-weight-bold">KSH <?php echo number_format($total_amount, 2); ?></div>
                            <?php if ($discount_amount > 0): ?>
                                <small class="text-info">Discount: KSH <?php echo number_format($discount_amount, 2); ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="text-right">
                            <div class="font-weight-bold <?php echo $balance_due > 0 ? 'text-warning' : 'text-success'; ?>">
                                KSH <?php echo number_format($balance_due, 2); ?>
                            </div>
                            <?php if ($amount_paid > 0): ?>
                                <small class="text-success">Paid: KSH <?php echo number_format($amount_paid, 2); ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?php echo $status_badge; ?>">
                                <?php echo $display_status; ?>
                            </span>
                            <?php if (!empty($payment_method) && $invoice_status == 'paid'): ?>
                                <small class="text-muted d-block"><?php echo ucfirst($payment_method); ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="dropdown dropleft text-center">
                                <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                    <i class="fas fa-ellipsis-h"></i>
                                </button>
                                <div class="dropdown-menu">
                                    <a class="dropdown-item" href="billing_invoice_view.php?invoice_id=<?php echo $invoice_id; ?>">
                                        <i class="fas fa-fw fa-eye mr-2"></i>View Invoice
                                    </a>
                                    <a class="dropdown-item" href="billing_invoice_print.php?invoice_id=<?php echo $invoice_id; ?>" target="_blank">
                                        <i class="fas fa-fw fa-print mr-2"></i>Print Preview
                                    </a>
                                     <a class="dropdown-item" href="export_invoice.php?invoice_id=<?php echo $invoice_id; ?>" target="_blank">
                                        <i class="fas fa-fw fa-print mr-2"></i>Export
                                    </a>
                                    
                                    <?php if ($invoice_status == 'issued' || $invoice_status == 'partially_paid'): ?>
                                        <a class="dropdown-item text-success" href="process_payment.php?invoice_id=<?php echo $invoice_id; ?>">
                                            <i class="fas fa-fw fa-money-bill-wave mr-2"></i>Record Payment
                                        </a>
                                    <?php endif; ?>
                                    
                                    <?php if ($price_list_type == 'insurance' && $invoice_status == 'issued'): ?>
                                        <a class="dropdown-item text-info" href="post.php?submit_insurance_claim=<?php echo $invoice_id; ?>">
                                            <i class="fas fa-fw fa-paper-plane mr-2"></i>Submit Insurance Claim
                                        </a>
                                    <?php endif; ?>
                                    
                                    <div class="dropdown-divider"></div>
                                    
                                    <?php if ($invoice_status == 'issued' || $invoice_status == 'partially_paid'): ?>
                                        <a class="dropdown-item" href="billing_invoice_edit.php?invoice_id=<?php echo $invoice_id; ?>">
                                            <i class="fas fa-fw fa-edit mr-2"></i>Edit Invoice
                                        </a>
                                        
                                        <a class="dropdown-item text-danger" href="post.php?cancel_invoice=<?php echo $invoice_id; ?>" onclick="return confirm('Are you sure you want to cancel this invoice?')">
                                            <i class="fas fa-fw fa-times mr-2"></i>Cancel Invoice
                                        </a>
                                    <?php endif; ?>
                                    
                                    <?php if ($invoice_status == 'paid'): ?>
                                        <a class="dropdown-item text-warning" href="post.php?refund_invoice=<?php echo $invoice_id; ?>" onclick="return confirm('Are you sure you want to refund this invoice?')">
                                            <i class="fas fa-fw fa-undo mr-2"></i>Refund Invoice
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php
                }
            } else {
                ?>
                <tr>
                    <td colspan="9" class="text-center py-5">
                        <i class="fas fa-file-invoice fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No invoices found</h5>
                        <p class="text-muted">No invoices match your search criteria.</p>
                        <a href="billing_invoice_create.php" class="btn btn-primary">
                            <i class="fas fa-plus mr-2"></i>Create New Invoice
                        </a>
                    </td>
                </tr>
                <?php
            }
            ?>

            </tbody>
        </table>
    </div>
    
    <!-- Pagination Footer -->
    <?php
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/filter_footer.php';
    ?>
    
</div> <!-- End Card -->

<script>
$(document).ready(function() {
    $('.select2').select2();
    $('[data-toggle="tooltip"]').tooltip();

    // Auto-submit date range when canned date is selected
    $('select[name="canned_date"]').change(function() {
        if ($(this).val() !== 'custom') {
            $(this).closest('form').submit();
        }
    });
});

function sendInvoice(invoiceId) {
    if (confirm('Send this invoice to the patient?')) {
        $.post('post.php', {
            send_invoice: invoiceId,
            csrf_token: '<?php echo $_SESSION['csrf_token']; ?>'
        }, function(response) {
            alert('Invoice sent successfully');
            location.reload();
        });
    }
}

function submitInsuranceClaim(invoiceId) {
    if (confirm('Submit this invoice as an insurance claim?')) {
        $.post('post.php', {
            submit_insurance_claim: invoiceId,
            csrf_token: '<?php echo $_SESSION['csrf_token']; ?>'
        }, function(response) {
            alert('Insurance claim submitted successfully');
            location.reload();
        });
    }
}

function recordPayment(invoiceId) {
    window.location.href = 'billing_payment_create.php?invoice_id=' + invoiceId;
}

// Quick actions for multiple invoices
function bulkAction(action) {
    const selectedInvoices = getSelectedInvoices();
    if (selectedInvoices.length === 0) {
        alert('Please select at least one invoice.');
        return;
    }

    if (confirm(`Perform ${action} on ${selectedInvoices.length} invoice(s)?`)) {
        // Implement bulk actions here
        console.log(`Bulk ${action} for:`, selectedInvoices);
    }
}

function getSelectedInvoices() {
    // This would need checkboxes in the table for bulk operations
    return []; // Placeholder
}
</script>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>