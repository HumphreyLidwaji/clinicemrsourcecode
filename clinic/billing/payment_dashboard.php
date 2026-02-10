<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Default Column Sortby/Order Filter
$sort = "invoice_date";
$order = "DESC";

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Status Filter - Only show draft invoices (in your schema, 'issued' is the initial status)
$status_query = "AND (i.invoice_status = 'issued')";
$status_filter = 'issued';

// Invoice Type Filter
if (isset($_GET['type']) && !empty($_GET['type']) && $_GET['type'] != 'all') {
    $type_query = "AND (i.invoice_type = '" . sanitizeInput($_GET['type']) . "')";
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
    $payment_date_query = "AND DATE(p.payment_date) BETWEEN '$dtf' AND '$dtt'";
} else {
    $date_query = '';
    $payment_date_query = '';
}

// Search query
$search_query = '';
if (isset($_GET['q']) && !empty($_GET['q'])) {
    $q = sanitizeInput($_GET['q']);
    $search_query = "AND (p.patient_first_name LIKE '%$q%' OR p.patient_last_name LIKE '%$q%' OR i.invoice_number LIKE '%$q%' OR p.patient_identifier LIKE '%$q%')";
    $payment_search_query = "AND (pat.patient_first_name LIKE '%$q%' OR pat.patient_last_name LIKE '%$q%' OR i.invoice_number LIKE '%$q%' OR p.payment_number LIKE '%$q%')";
} else {
    $payment_search_query = '';
}

// Main query for issued invoices (draft equivalent)
$sql = mysqli_query(
    $mysqli,
    "
    SELECT SQL_CALC_FOUND_ROWS 
        i.*, 
        p.first_name, 
        p.last_name,
        
        CONCAT(p.first_name, ' ', p.last_name) as patient_name,
        DATEDIFF(CURDATE(), i.due_date) AS days_overdue,
        (i.total_amount - i.amount_paid) as balance_due
    FROM invoices i 
    LEFT JOIN patients p ON i.patient_id = p.patient_id
    WHERE 1=1
      $status_query
      $type_query
      $date_query
      $search_query
    ORDER BY $sort $order
    LIMIT $record_from, $record_to
");

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Statistics query for issued invoices
$stats_sql = mysqli_query(
    $mysqli,
    "
    SELECT 
        COUNT(*) as total_invoices,
        SUM(i.total_amount) as total_amount,
        SUM(i.total_amount - i.amount_paid) as total_balance,
        SUM(CASE WHEN i.invoice_type = 'insurance' THEN 1 ELSE 0 END) as insurance_count,
        SUM(CASE WHEN i.invoice_type = 'patient_self_pay' THEN 1 ELSE 0 END) as patient_self_pay_count,
        SUM(CASE WHEN i.invoice_type = 'patient_co_pay' THEN 1 ELSE 0 END) as patient_co_pay_count
    FROM invoices i
    WHERE i.invoice_status = 'issued'
      $type_query
      $date_query
      $search_query
"
);

$stats = mysqli_fetch_assoc($stats_sql);

// Payments Statistics Query
$payment_stats_sql = mysqli_query(
    $mysqli,
    "
    SELECT 
        COUNT(*) as total_payments,
        SUM(p.payment_amount) as total_payment_amount,
        AVG(p.payment_amount) as average_payment,
        SUM(CASE WHEN p.status = 'posted' THEN p.payment_amount ELSE 0 END) as posted_amount,
        SUM(CASE WHEN p.status = 'pending' THEN p.payment_amount ELSE 0 END) as pending_amount,
        COUNT(CASE WHEN p.status = 'posted' THEN 1 END) as posted_count,
        COUNT(CASE WHEN p.status = 'pending' THEN 1 END) as pending_count,
        COUNT(CASE WHEN p.status = 'void' THEN 1 END) as void_count,
        COUNT(CASE WHEN p.payment_method = 'mobile_money' THEN 1 END) as mobile_money_count,
        COUNT(CASE WHEN p.payment_method = 'cash' THEN 1 END) as cash_count,
        COUNT(CASE WHEN p.payment_method = 'bank_transfer' THEN 1 END) as bank_transfer_count,
        COUNT(CASE WHEN p.payment_method = 'credit_card' THEN 1 END) as credit_card_count,
        COUNT(CASE WHEN p.payment_method = 'insurance' THEN 1 END) as insurance_count
    FROM payments p
    WHERE 1=1
      $payment_date_query
      $payment_search_query
"
);

$payment_stats = mysqli_fetch_assoc($payment_stats_sql);

// Recent Payments Query
$recent_payments_sql = mysqli_query(
    $mysqli,
    "
    SELECT 
        p.*,
        pat.first_name,
        pat.last_name,
        CONCAT(pat.first_name, ' ', pat.last_name) as patient_name,
        i.invoice_number,
        pm.payment_method_name
    FROM payments p
    LEFT JOIN invoices i ON p.invoice_id = i.invoice_id
    LEFT JOIN patients pat ON i.patient_id = pat.patient_id
    LEFT JOIN payment_methods pm ON p.payment_method = pm.payment_method_name
    WHERE 1=1
      $payment_date_query
      $payment_search_query
    ORDER BY p.payment_date DESC, p.created_at DESC
    LIMIT 10
"
);

?>

<div class="card">
    <div class="card-header bg-dark py-2">
        <h3 class="card-title mt-2"><i class="fa fa-fw fa-file-invoice mr-2"></i>Issued Invoices & Payments</h3>
        <div class="card-tools">
            <a href="billing_invoice_create.php" class="btn btn-primary">
                <i class="fas fa-plus mr-2"></i>New Invoice
            </a>
            <a href="billing_payment_create.php" class="btn btn-success ml-2">
                <i class="fas fa-money-bill-wave mr-2"></i>Record Payment
            </a>
        </div>
    </div>
    
    <!-- Statistics Row -->
    <div class="card-body border-bottom">
        <h5 class="text-muted mb-3"><i class="fas fa-chart-bar mr-2"></i>Invoice Statistics</h5>
        <div class="row text-center">
            <div class="col-md-3">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-secondary"><i class="fas fa-file-invoice"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Issued Invoices</span>
                        <span class="info-box-number"><?php echo $stats['total_invoices']; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-warning"><i class="fas fa-dollar-sign"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Amount</span>
                        <span class="info-box-number">KSH <?php echo number_format($stats['total_amount'] ?? 0, 2); ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-primary"><i class="fas fa-hospital"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Insurance</span>
                        <span class="info-box-number"><?php echo $stats['insurance_count']; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-success"><i class="fas fa-user"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Patient Pay</span>
                        <span class="info-box-number"><?php echo ($stats['patient_self_pay_count'] ?? 0) + ($stats['patient_co_pay_count'] ?? 0); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Payment Statistics -->
        <h5 class="text-muted mb-3 mt-4"><i class="fas fa-money-bill-wave mr-2"></i>Payment Statistics</h5>
        <div class="row text-center">
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-success"><i class="fas fa-money-bill"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Payments</span>
                        <span class="info-box-number"><?php echo $payment_stats['total_payments']; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-success"><i class="fas fa-dollar-sign"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Received</span>
                        <span class="info-box-number">KSH <?php echo number_format($payment_stats['total_payment_amount'] ?? 0, 2); ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-info"><i class="fas fa-check-circle"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Posted</span>
                        <span class="info-box-number"><?php echo $payment_stats['posted_count']; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-warning"><i class="fas fa-clock"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Pending</span>
                        <span class="info-box-number"><?php echo $payment_stats['pending_count']; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-primary"><i class="fas fa-mobile-alt"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Mobile Money</span>
                        <span class="info-box-number"><?php echo $payment_stats['mobile_money_count']; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-secondary"><i class="fas fa-university"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Bank/Card</span>
                        <span class="info-box-number"><?php echo ($payment_stats['bank_transfer_count'] ?? 0) + ($payment_stats['credit_card_count'] ?? 0); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card-header pb-2 pt-3">
        <form autocomplete="off">
            <input type="hidden" name="status" value="issued">
            <div class="row">
                <div class="col-md-5">
                    <div class="form-group">
                        <div class="input-group">
                            <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search invoices, patients, payments..." autofocus>
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
                            <a href="billing_invoices.php" class="btn btn-outline-primary">
                                <i class="fa fa-fw fa-list mr-2"></i>All Invoices
                            </a>
                            <a href="billing_payments.php" class="btn btn-outline-info">
                                <i class="fa fa-fw fa-money-bill-wave mr-2"></i>All Payments
                            </a>
                            <a href="billing_dashboard.php" class="btn btn-outline-secondary">
                                <i class="fa fa-fw fa-tachometer-alt mr-2"></i>Billing Dashboard
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse <?php if (isset($_GET['dtf']) || $type_filter != 'all' || isset($_GET['q'])) { echo "show"; } ?>" id="advancedFilter">
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
                                <option <?php if (($_GET['canned_date'] ?? '') == "thismonth") { echo "selected"; } ?> value="thismonth">This Month</option>
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
                                <option value="patient_self_pay" <?php if ($type_filter == "patient_self_pay") { echo "selected"; } ?>>Patient Self-Pay</option>
                                <option value="patient_co_pay" <?php if ($type_filter == "patient_co_pay") { echo "selected"; } ?>>Patient Co-Pay</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Actions</label>
                            <div>
                                <a href="billing_draft_invoices.php" class="btn btn-secondary btn-block">Clear Filters</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- Recent Payments Section -->
    <div class="card-body border-bottom">
        <h5 class="text-muted mb-3">
            <i class="fas fa-clock mr-2"></i>Recent Payments
            <a href="billing_payments.php" class="btn btn-sm btn-outline-primary float-right">
                <i class="fas fa-list mr-1"></i>View All
            </a>
        </h5>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="bg-light">
                <tr>
                    <th>Payment #</th>
                    <th>Patient</th>
                    <th>Invoice</th>
                    <th>Amount</th>
                    <th>Method</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th class="text-center">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php
                if ($recent_payments_sql && mysqli_num_rows($recent_payments_sql) > 0) {
                    while ($payment = mysqli_fetch_assoc($recent_payments_sql)) {
                        $payment_id = intval($payment['payment_id']);
                        $payment_number = nullable_htmlentities($payment['payment_number']);
                        $patient_name = nullable_htmlentities($payment['patient_name']) ?: 'N/A';
                        $invoice_number = nullable_htmlentities($payment['invoice_number']) ?: 'N/A';
                        $payment_amount = floatval($payment['payment_amount']);
                        $payment_method = nullable_htmlentities($payment['payment_method']);
                        $payment_date = nullable_htmlentities($payment['payment_date']);
                        $payment_status = nullable_htmlentities($payment['status']);
                        
                        // Status badge styling
                        $status_badge = "badge-secondary";
                        switch($payment_status) {
                            case 'posted': $status_badge = "badge-success"; break;
                            case 'pending': $status_badge = "badge-warning"; break;
                            case 'void': $status_badge = "badge-danger"; break;
                            case 'reversed': $status_badge = "badge-secondary"; break;
                        }
                        
                        // Method badge styling
                        $method_badge = "badge-info";
                        switch($payment_method) {
                            case 'mobile_money': $method_badge = "badge-primary"; break;
                            case 'cash': $method_badge = "badge-success"; break;
                            case 'bank_transfer': $method_badge = "badge-info"; break;
                            case 'credit_card': $method_badge = "badge-warning"; break;
                            case 'insurance': $method_badge = "badge-secondary"; break;
                        }
                        ?>
                        <tr>
                            <td>
                                <div class="font-weight-bold"><?php echo $payment_number; ?></div>
                            </td>
                            <td><?php echo $patient_name; ?></td>
                            <td><?php echo $invoice_number; ?></td>
                            <td class="font-weight-bold text-success">KSH <?php echo number_format($payment_amount, 2); ?></td>
                            <td>
                                <span class="badge <?php echo $method_badge; ?>">
                                    <?php echo ucwords(str_replace('_', ' ', $payment_method)); ?>
                                </span>
                            </td>
                            <td><?php echo date('M j, Y', strtotime($payment_date)); ?></td>
                            <td>
                                <span class="badge <?php echo $status_badge; ?>">
                                    <?php echo ucfirst($payment_status); ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <div class="dropdown dropleft">
                                    <button class="btn btn-sm btn-secondary" type="button" data-toggle="dropdown">
                                        <i class="fas fa-ellipsis-h"></i>
                                    </button>
                                    <div class="dropdown-menu">
                                        <a class="dropdown-item" href="billing_payment_view.php?payment_id=<?php echo $payment_id; ?>">
                                            <i class="fas fa-fw fa-eye mr-2"></i>View Details
                                        </a>
                                        <a class="dropdown-item" href="billing_payment_edit.php?payment_id=<?php echo $payment_id; ?>">
                                            <i class="fas fa-fw fa-edit mr-2"></i>Edit Payment
                                        </a>
                                        <?php if ($payment_status == 'pending'): ?>
                                            <a class="dropdown-item text-success" href="post.php?mark_payment_posted=<?php echo $payment_id; ?>">
                                                <i class="fas fa-fw fa-check mr-2"></i>Mark as Posted
                                            </a>
                                        <?php endif; ?>
                                        <div class="dropdown-divider"></div>
                                        <a class="dropdown-item text-danger" href="post.php?delete_payment=<?php echo $payment_id; ?>" onclick="return confirm('Are you sure you want to delete this payment?')">
                                            <i class="fas fa-fw fa-trash mr-2"></i>Delete
                                        </a>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php
                    }
                } else {
                    ?>
                    <tr>
                        <td colspan="8" class="text-center py-3">
                            <i class="fas fa-money-bill-wave fa-2x text-muted mb-2"></i>
                            <p class="text-muted mb-0">No recent payments found</p>
                            <a href="billing_payment_create.php" class="btn btn-sm btn-success mt-2">
                                <i class="fas fa-plus mr-1"></i>Record Payment
                            </a>
                        </td>
                    </tr>
                    <?php
                }
                ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Issued Invoices Section -->
    <div class="card-body">
        <h5 class="text-muted mb-3">
            <i class="fas fa-file-invoice mr-2"></i>Issued Invoices
        </h5>
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
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=i.invoice_type&order=<?php echo $disp; ?>">
                            Type <?php if ($sort == 'i.invoice_type') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=p.patient_first_name&order=<?php echo $disp; ?>">
                            Patient <?php if ($sort == 'p.patient_first_name') { echo $order_icon; } ?>
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
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=balance_due&order=<?php echo $disp; ?>">
                            Balance <?php if ($sort == 'balance_due') { echo $order_icon; } ?>
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
                        $invoice_type = nullable_htmlentities($row['invoice_type']);
                        $patient_name = nullable_htmlentities($row['patient_name']);
                        $patient_identifier = nullable_htmlentities($row['patient_identifier']);
                        $invoice_date = nullable_htmlentities($row['invoice_date']);
                        $invoice_due_date = nullable_htmlentities($row['due_date']);
                        $invoice_amount = floatval($row['total_amount']);
                        $paid_amount = floatval($row['amount_paid'] ?? 0);
                        $balance_due = floatval($row['balance_due'] ?? 0);
                        $invoice_status = nullable_htmlentities($row['invoice_status']);
                        $days_overdue = intval($row['days_overdue']);

                        // Type badge styling
                        $type_badge = "";
                        switch($invoice_type) {
                            case 'insurance':
                                $type_badge = "badge-primary";
                                break;
                            case 'patient_self_pay':
                                $type_badge = "badge-success";
                                break;
                            case 'patient_co_pay':
                                $type_badge = "badge-warning";
                                break;
                            default:
                                $type_badge = "badge-secondary";
                        }

                        // Status badge styling
                        $status_badge = "";
                        switch($invoice_status) {
                            case 'issued':
                                $status_badge = "badge-warning";
                                break;
                            case 'partially_paid':
                                $status_badge = "badge-info";
                                break;
                            case 'paid':
                                $status_badge = "badge-success";
                                break;
                            case 'cancelled':
                                $status_badge = "badge-danger";
                                break;
                            case 'refunded':
                                $status_badge = "badge-secondary";
                                break;
                            default:
                                $status_badge = "badge-secondary";
                        }

                        ?>
                        <tr>
                            <td>
                                <div class="font-weight-bold"><?php echo $invoice_number; ?></div>
                                <?php if ($patient_identifier): ?>
                                    <small class="text-muted">ID: <?php echo $patient_identifier; ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?php echo $type_badge; ?>">
                                    <?php 
                                    switch($invoice_type) {
                                        case 'insurance': echo 'Insurance'; break;
                                        case 'patient_self_pay': echo 'Self-Pay'; break;
                                        case 'patient_co_pay': echo 'Co-Pay'; break;
                                        default: echo ucfirst($invoice_type);
                                    }
                                    ?>
                                </span>
                            </td>
                            <td>
                                <div class="font-weight-bold"><?php echo $patient_name ?: 'N/A'; ?></div>
                            </td>
                            <td>
                                <div class="font-weight-bold"><?php echo date('M j, Y', strtotime($invoice_date)); ?></div>
                            </td>
                            <td>
                                <div class="font-weight-bold">
                                    <?php echo date('M j, Y', strtotime($invoice_due_date)); ?>
                                    <?php if ($days_overdue > 0): ?>
                                        <div class="small text-danger">Overdue <?php echo $days_overdue; ?> days</div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="text-right">
                                <div class="font-weight-bold">KSH <?php echo number_format($invoice_amount, 2); ?></div>
                            </td>
                            <td class="text-right">
                                <div class="font-weight-bold <?php echo $balance_due > 0 ? 'text-warning' : 'text-success'; ?>">
                                    KSH <?php echo number_format($balance_due, 2); ?>
                                </div>
                            </td>
                            <td>
                                <span class="badge <?php echo $status_badge; ?>">
                                    <?php echo ucwords(str_replace('_', ' ', $invoice_status)); ?>
                                </span>
                            </td>
                            <td>
                                <div class="dropdown dropleft text-center">
                                    <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                        <i class="fas fa-ellipsis-h"></i>
                                    </button>
                                    <div class="dropdown-menu">
                                        <a class="dropdown-item" href="billing_invoice_view.php?invoice_id=<?php echo $invoice_id; ?>">
                                            <i class="fas fa-fw fa-eye mr-2"></i>View Details
                                        </a>
                                        <a class="dropdown-item" href="billing_payments.php?invoice_id=<?php echo $invoice_id; ?>">
                                            <i class="fas fa-fw fa-money-bill-wave mr-2"></i>View Payments
                                        </a>
                                        <a class="dropdown-item" href="billing_invoice_edit.php?invoice_id=<?php echo $invoice_id; ?>">
                                            <i class="fas fa-fw fa-edit mr-2"></i>Edit Invoice
                                        </a>
                                        <a class="dropdown-item" href="billing_invoice_print.php?invoice_id=<?php echo $invoice_id; ?>" target="_blank">
                                            <i class="fas fa-fw fa-print mr-2"></i>Print
                                        </a>
                                        <a class="dropdown-item" href="billing_payment_create.php?invoice_id=<?php echo $invoice_id; ?>">
                                            <i class="fas fa-fw fa-plus-circle mr-2"></i>Record Payment
                                        </a>
                                        
                                        <?php if ($invoice_status == 'issued' || $invoice_status == 'partially_paid'): ?>
                                            <div class="dropdown-divider"></div>
                                            <a class="dropdown-item text-success" href="post.php?mark_invoice_paid=<?php echo $invoice_id; ?>" onclick="return confirm('Mark this invoice as fully paid?')">
                                                <i class="fas fa-fw fa-check-circle mr-2"></i>Mark as Paid
                                            </a>
                                        <?php endif; ?>
                                        
                                        <?php if ($invoice_type == 'insurance' && $invoice_status == 'issued'): ?>
                                            <a class="dropdown-item text-info" href="post.php?submit_insurance_claim=<?php echo $invoice_id; ?>">
                                                <i class="fas fa-fw fa-paper-plane mr-2"></i>Submit Claim
                                            </a>
                                        <?php endif; ?>
                                        
                                        <div class="dropdown-divider"></div>
                                        
                                        <?php if ($invoice_status == 'issued'): ?>
                                            <a class="dropdown-item text-danger" href="post.php?cancel_invoice=<?php echo $invoice_id; ?>" onclick="return confirm('Cancel this invoice?')">
                                                <i class="fas fa-fw fa-times-circle mr-2"></i>Cancel Invoice
                                            </a>
                                        <?php endif; ?>
                                        
                                        <a class="dropdown-item text-danger" href="post.php?delete_invoice=<?php echo $invoice_id; ?>" onclick="return confirm('Are you sure you want to delete this invoice?')">
                                            <i class="fas fa-fw fa-trash mr-2"></i>Delete
                                        </a>
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
                            <h5 class="text-muted">No issued invoices found</h5>
                            <p class="text-muted">All invoices have been paid or no invoices match your search criteria.</p>
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

function markAsPaid(invoiceId) {
    if (confirm('Mark this invoice as fully paid?')) {
        $.post('post.php', {
            mark_invoice_paid: invoiceId,
            csrf_token: '<?php echo $_SESSION['csrf_token']; ?>'
        }, function(response) {
            alert('Invoice marked as paid');
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

// Payment functions
function markPaymentPosted(paymentId) {
    if (confirm('Mark this payment as posted?')) {
        $.post('post.php', {
            mark_payment_posted: paymentId,
            csrf_token: '<?php echo $_SESSION['csrf_token']; ?>'
        }, function(response) {
            alert('Payment marked as posted');
            location.reload();
        });
    }
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