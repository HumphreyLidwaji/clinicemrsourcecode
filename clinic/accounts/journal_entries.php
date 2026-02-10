<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Check permissions
if (!isset($_SESSION['user_id'])) {
    header("Location: /login.php");
    exit;
}

// Check if journal system is set up
$table_check = $mysqli->query("SHOW TABLES LIKE 'journal_entries'")->num_rows;
if ($table_check == 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Journal entry system is not set up. Please run accounting setup first.";
    header("Location: accounts.php");
    exit;
}

// Default Column Sortby/Order Filter
$sort = "je.transaction_date";
$order = "DESC";

// Filter parameters
$status_filter = $_GET['status'] ?? '';
$type_filter = $_GET['type'] ?? '';
$date_filter = $_GET['date'] ?? '';
$dtf = sanitizeInput($_GET['dtf'] ?? '');
$dtt = sanitizeInput($_GET['dtt'] ?? '');

// Set default date range if not provided
if (empty($dtf)) {
    $dtf = date('Y-m-01'); // First day of current month
}
if (empty($dtt)) {
    $dtt = date('Y-m-t'); // Last day of current month
}

// Status Filter
if ($status_filter) {
    $status_query = "AND je.status = '" . sanitizeInput($status_filter) . "'";
} else {
    $status_query = '';
}

// Type Filter
if ($type_filter) {
    $type_query = "AND je.transaction_type = '" . sanitizeInput($type_filter) . "'";
} else {
    $type_query = '';
}

// Date Range Filter
if (!empty($dtf) && !empty($dtt)) {
    $date_query = "AND je.transaction_date BETWEEN '$dtf' AND '$dtt'";
} else {
    $date_query = '';
}

// Search Query
$q = sanitizeInput($_GET['q'] ?? '');
if (!empty($q)) {
    $search_query = "AND (
        je.journal_entry_number LIKE '%$q%' 
        OR je.description LIKE '%$q%'
        OR je.reference_number LIKE '%$q%'
        OR u.user_name LIKE '%$q%'
    )";
} else {
    $search_query = '';
}

// Get statistics
$stats_sql = "SELECT 
    COUNT(*) as total_entries,
    COUNT(CASE WHEN je.status = 'posted' THEN 1 END) as posted_entries,
    COUNT(CASE WHEN je.status = 'draft' THEN 1 END) as draft_entries,
    COUNT(CASE WHEN je.status = 'void' THEN 1 END) as void_entries,
    COUNT(CASE WHEN je.status = 'reversed' THEN 1 END) as reversed_entries,
    COALESCE(SUM(je.total_debit), 0) as total_debit,
    COALESCE(SUM(je.total_credit), 0) as total_credit,
    COUNT(DISTINCT DATE(je.transaction_date)) as active_days
FROM journal_entries je
WHERE 1=1
$status_query
$type_query
$date_query
$search_query";

$stats_result = $mysqli->query($stats_sql);
$stats = $stats_result->fetch_assoc();

// Main query for journal entries
$sql = mysqli_query(
    $mysqli,
    "
    SELECT SQL_CALC_FOUND_ROWS je.*,
           u.user_name as created_by_name,
           u2.user_name as posted_by_name,
           COUNT(jel.journal_entry_line_id) as line_count,
           (SELECT GROUP_CONCAT(DISTINCT a.account_number SEPARATOR ', ') 
            FROM journal_entry_lines jel2
            LEFT JOIN accounts a ON jel2.account_id = a.account_id
            WHERE jel2.journal_entry_id = je.journal_entry_id) as account_numbers
    FROM journal_entries je
    LEFT JOIN users u ON je.created_by = u.user_id
    LEFT JOIN users u2 ON je.posted_by = u2.user_id
    LEFT JOIN journal_entry_lines jel ON je.journal_entry_id = jel.journal_entry_id
    WHERE 1=1
      $status_query
      $type_query
      $date_query
      $search_query
    GROUP BY je.journal_entry_id
    ORDER BY $sort $order
    LIMIT $record_from, $record_to
");

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Get recent activity
$recent_activity_sql = "SELECT 
    a.activity_id,
    a.activity_type,
    a.activity_description,
    a.activity_date,
    a.amount,
    u.user_name as performed_by_name
FROM activities a
LEFT JOIN users u ON a.performed_by = u.user_id
WHERE a.related_type = 'journal_entry'
ORDER BY a.activity_date DESC
LIMIT 10";
$recent_activity = $mysqli->query($recent_activity_sql);
?>

<div class="card">
    <div class="card-header bg-dark py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-fw fa-book mr-2"></i>Journal Entries
        </h3>
        <div class="card-tools">
            <div class="btn-group">
                <a href="journal_entry_new.php" class="btn btn-success">
                    <i class="fas fa-plus mr-2"></i>New Entry
                </a>
                <a href="reports_general_ledger.php" class="btn btn-info ml-2">
                    <i class="fas fa-file-invoice-dollar mr-2"></i>General Ledger
                </a>
                <div class="btn-group ml-2">
                    <button type="button" class="btn btn-secondary dropdown-toggle" data-toggle="dropdown">
                        <i class="fas fa-tasks mr-2"></i>Quick Actions
                    </button>
                    <div class="dropdown-menu">
                        <a class="dropdown-item" href="#" onclick="batchPostDrafts()">
                            <i class="fas fa-check-circle mr-2"></i>Post All Drafts
                        </a>
                        <a class="dropdown-item" href="#" onclick="reconcileJournal()">
                            <i class="fas fa-balance-scale mr-2"></i>Reconcile Entries
                        </a>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item" href="reports_trial_balance.php">
                            <i class="fas fa-balance-scale-left mr-2"></i>Trial Balance
                        </a>
                        <a class="dropdown-item" href="reports_audit_trail.php">
                            <i class="fas fa-clipboard-check mr-2"></i>Audit Trail
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Statistics Row -->
    <div class="card-body border-bottom">
        <div class="row text-center">
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-primary"><i class="fas fa-exchange-alt"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Entries</span>
                        <span class="info-box-number"><?php echo $stats['total_entries']; ?></span>
                        <small class="text-muted"><?php echo $stats['active_days']; ?> active days</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-success"><i class="fas fa-check-circle"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Posted</span>
                        <span class="info-box-number"><?php echo $stats['posted_entries']; ?></span>
                        <small class="text-muted">Ready for reporting</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-warning"><i class="fas fa-clock"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Draft</span>
                        <span class="info-box-number"><?php echo $stats['draft_entries']; ?></span>
                        <small class="text-muted">Awaiting posting</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-info"><i class="fas fa-arrow-up"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Debits</span>
                        <span class="info-box-number"><?php echo numfmt_format_currency($currency_format, $stats['total_debit'], $session_company_currency); ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-danger"><i class="fas fa-arrow-down"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Credits</span>
                        <span class="info-box-number"><?php echo numfmt_format_currency($currency_format, $stats['total_credit'], $session_company_currency); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Search and Filter -->
    <div class="card-header pb-2 pt-3">
        <form autocomplete="off">
            <div class="row">
                <div class="col-md-5">
                    <div class="form-group">
                        <div class="input-group">
                            <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search entry #, description, reference..." autofocus>
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
                            <span class="btn btn-light border" data-toggle="tooltip" title="Total Entries">
                                <i class="fas fa-book text-dark mr-1"></i>
                                <strong><?php echo $stats['total_entries']; ?></strong>
                            </span>
                            <span class="btn btn-light border" data-toggle="tooltip" title="Posted Entries">
                                <i class="fas fa-check-circle text-success mr-1"></i>
                                <strong><?php echo $stats['posted_entries']; ?></strong>
                            </span>
                            <span class="btn btn-light border" data-toggle="tooltip" title="Draft Entries">
                                <i class="fas fa-clock text-warning mr-1"></i>
                                <strong><?php echo $stats['draft_entries']; ?></strong>
                            </span>
                            <span class="btn btn-light border" data-toggle="tooltip" title="Balance Check">
                                <i class="fas fa-balance-scale text-info mr-1"></i>
                                <strong><?php echo numfmt_format_currency($currency_format, abs($stats['total_debit'] - $stats['total_credit']), $session_company_currency); ?></strong>
                            </span>
                            <a href="journal_entry_new.php" class="btn btn-success ml-2">
                                <i class="fas fa-plus mr-2"></i>New Entry
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse <?php if ($status_filter || $type_filter || !empty($dtf) || !empty($dtt)) { echo "show"; } ?>" id="advancedFilter">
                <div class="row">
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Date Range</label>
                            <select class="form-control select2" name="date" onchange="handleDateSelection(this)">
                                <option value="">Custom</option>
                                <option value="today" <?php echo $date_filter == "today" ? "selected" : ""; ?>>Today</option>
                                <option value="yesterday" <?php echo $date_filter == "yesterday" ? "selected" : ""; ?>>Yesterday</option>
                                <option value="thisweek" <?php echo $date_filter == "thisweek" ? "selected" : ""; ?>>This Week</option>
                                <option value="lastweek" <?php echo $date_filter == "lastweek" ? "selected" : ""; ?>>Last Week</option>
                                <option value="thismonth" <?php echo $date_filter == "thismonth" ? "selected" : ""; ?>>This Month</option>
                                <option value="lastmonth" <?php echo $date_filter == "lastmonth" ? "selected" : ""; ?>>Last Month</option>
                                <option value="thisquarter" <?php echo $date_filter == "thisquarter" ? "selected" : ""; ?>>This Quarter</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Date from</label>
                            <input type="date" class="form-control" name="dtf" value="<?php echo nullable_htmlentities($dtf); ?>">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Date to</label>
                            <input type="date" class="form-control" name="dtt" value="<?php echo nullable_htmlentities($dtt); ?>">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>Status</label>
                            <select class="form-control select2" name="status" onchange="this.form.submit()">
                                <option value="">All Status</option>
                                <option value="draft" <?php echo $status_filter == "draft" ? "selected" : ""; ?>>Draft</option>
                                <option value="posted" <?php echo $status_filter == "posted" ? "selected" : ""; ?>>Posted</option>
                                <option value="void" <?php echo $status_filter == "void" ? "selected" : ""; ?>>Void</option>
                                <option value="reversed" <?php echo $status_filter == "reversed" ? "selected" : ""; ?>>Reversed</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Quick Actions</label>
                            <div class="btn-group btn-block">
                                <a href="journal_entries.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-times mr-2"></i>Clear Filters
                                </a>
                                <a href="journal_entry_new.php" class="btn btn-success">
                                    <i class="fas fa-plus mr-2"></i>New Entry
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row mt-2">
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Transaction Type</label>
                            <select class="form-control select2" name="type" onchange="this.form.submit()">
                                <option value="">All Types</option>
                                <option value="invoice" <?php echo $type_filter == "invoice" ? "selected" : ""; ?>>Invoice</option>
                                <option value="payment" <?php echo $type_filter == "payment" ? "selected" : ""; ?>>Payment</option>
                                <option value="adjustment" <?php echo $type_filter == "adjustment" ? "selected" : ""; ?>>Adjustment</option>
                                <option value="refund" <?php echo $type_filter == "refund" ? "selected" : ""; ?>>Refund</option>
                                <option value="inventory_issue" <?php echo $type_filter == "inventory_issue" ? "selected" : ""; ?>>Inventory Issue</option>
                                <option value="inventory_receipt" <?php echo $type_filter == "inventory_receipt" ? "selected" : ""; ?>>Inventory Receipt</option>
                                <option value="other" <?php echo $type_filter == "other" ? "selected" : ""; ?>>Other</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-9">
                        <div class="form-group">
                            <label>Quick Filters</label>
                            <div class="btn-group btn-group-toggle" data-toggle="buttons">
                                <a href="?status=draft" class="btn btn-outline-warning btn-sm <?php echo $status_filter == 'draft' ? 'active' : ''; ?>">
                                    <i class="fas fa-clock mr-1"></i> Draft Entries
                                </a>
                                <a href="?status=posted" class="btn btn-outline-success btn-sm <?php echo $status_filter == 'posted' ? 'active' : ''; ?>">
                                    <i class="fas fa-check-circle mr-1"></i> Posted Entries
                                </a>
                                <a href="?dtf=<?php echo date('Y-m-01'); ?>&dtt=<?php echo date('Y-m-d'); ?>" class="btn btn-outline-info btn-sm <?php echo !empty($dtf) ? 'active' : ''; ?>">
                                    <i class="fas fa-calendar-alt mr-1"></i> This Month
                                </a>
                                <a href="?type=adjustment" class="btn btn-outline-secondary btn-sm <?php echo $type_filter == 'adjustment' ? 'active' : ''; ?>">
                                    <i class="fas fa-cog mr-1"></i> Adjustments
                                </a>
                                <a href="?type=invoice" class="btn btn-outline-primary btn-sm <?php echo $type_filter == 'invoice' ? 'active' : ''; ?>">
                                    <i class="fas fa-file-invoice mr-1"></i> Invoices
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
    
    <!-- Journal Entries Table -->
    <div class="table-responsive-sm">
        <table class="table table-hover mb-0">
            <thead class="<?php if ($num_rows[0] == 0) { echo "d-none"; } ?> bg-light">
            <tr>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=je.journal_entry_number&order=<?php echo $disp; ?>">
                        Entry # <?php if ($sort == 'je.journal_entry_number') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=je.transaction_date&order=<?php echo $disp; ?>">
                        Date <?php if ($sort == 'je.transaction_date') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>Description</th>
                <th>Type</th>
                <th class="text-right">Debit</th>
                <th class="text-right">Credit</th>
                <th>Status</th>
                <th>Created By</th>
                <th class="text-center">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php
            if ($num_rows[0] == 0) {
                ?>
                <tr>
                    <td colspan="9" class="text-center py-5">
                        <i class="fas fa-book fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No journal entries found</h5>
                        <p class="text-muted">
                            <?php 
                            if ($q || $status_filter || $type_filter || !empty($dtf) || !empty($dtt)) {
                                echo "Try adjusting your search or filter criteria.";
                            } else {
                                echo "Get started by creating your first journal entry.";
                            }
                            ?>
                        </p>
                        <a href="journal_entry_new.php" class="btn btn-primary">
                            <i class="fas fa-plus mr-2"></i>Create First Entry
                        </a>
                        <?php if ($q || $status_filter || $type_filter || !empty($dtf) || !empty($dtt)): ?>
                            <a href="journal_entries.php" class="btn btn-secondary ml-2">
                                <i class="fas fa-times mr-2"></i>Clear Filters
                            </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php
            } else {
                while ($row = mysqli_fetch_array($sql)) {
                    $journal_entry_id = intval($row['journal_entry_id']);
                    $journal_entry_number = nullable_htmlentities($row['journal_entry_number']);
                    $transaction_date = nullable_htmlentities($row['transaction_date']);
                    $description = nullable_htmlentities($row['description']);
                    $transaction_type = nullable_htmlentities($row['transaction_type']);
                    $reference_number = nullable_htmlentities($row['reference_number']);
                    $total_debit = floatval($row['total_debit']);
                    $total_credit = floatval($row['total_credit']);
                    $status = nullable_htmlentities($row['status']);
                    $created_by_name = nullable_htmlentities($row['created_by_name']);
                    $posted_by_name = nullable_htmlentities($row['posted_by_name']);
                    $line_count = intval($row['line_count']);
                    $account_numbers = nullable_htmlentities($row['account_numbers']);
                    $notes = nullable_htmlentities($row['notes']);
                    
                    // Status badge styling
                    $status_badge = "";
                    $status_icon = "";
                    switch($status) {
                        case 'posted':
                            $status_badge = "badge-success";
                            $status_icon = "fa-check-circle";
                            break;
                        case 'draft':
                            $status_badge = "badge-warning";
                            $status_icon = "fa-clock";
                            break;
                        case 'void':
                            $status_badge = "badge-danger";
                            $status_icon = "fa-ban";
                            break;
                        case 'reversed':
                            $status_badge = "badge-secondary";
                            $status_icon = "fa-undo";
                            break;
                        default:
                            $status_badge = "badge-light";
                            $status_icon = "fa-question-circle";
                    }
                    
                    // Type badge styling
                    $type_badge = "";
                    switch($transaction_type) {
                        case 'invoice':
                            $type_badge = "badge-primary";
                            break;
                        case 'payment':
                            $type_badge = "badge-success";
                            break;
                        case 'adjustment':
                            $type_badge = "badge-warning";
                            break;
                        case 'refund':
                            $type_badge = "badge-danger";
                            break;
                        case 'inventory_issue':
                            $type_badge = "badge-info";
                            break;
                        case 'inventory_receipt':
                            $type_badge = "badge-info";
                            break;
                        default:
                            $type_badge = "badge-secondary";
                    }
                    
                    // Balance check
                    $balanced = abs($total_debit - $total_credit) < 0.01;
                    ?>
                    <tr class="<?php echo $status == 'void' ? 'table-secondary' : ($status == 'draft' ? 'table-warning' : ''); ?>">
                        <td>
                            <div class="font-weight-bold text-primary"><?php echo $journal_entry_number; ?></div>
                            <?php if ($reference_number): ?>
                                <small class="text-muted">Ref: <?php echo $reference_number; ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="font-weight-bold"><?php echo date('M j, Y', strtotime($transaction_date)); ?></div>
                            <small class="text-muted"><?php echo date('D', strtotime($transaction_date)); ?></small>
                        </td>
                        <td>
                            <div class="font-weight-bold"><?php echo $description; ?></div>
                            <?php if ($account_numbers): ?>
                                <small class="text-muted">Accounts: <?php echo $account_numbers; ?></small>
                            <?php endif; ?>
                            <?php if ($line_count > 0): ?>
                                <small class="text-muted d-block">
                                    <i class="fas fa-list-ol"></i> <?php echo $line_count; ?> lines
                                </small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?php echo $type_badge; ?>">
                                <?php echo str_replace('_', ' ', $transaction_type); ?>
                            </span>
                        </td>
                        <td class="text-right">
                            <div class="font-weight-bold text-success"><?php echo numfmt_format_currency($currency_format, $total_debit, $session_company_currency); ?></div>
                            <?php if (!$balanced): ?>
                                <small class="text-danger">
                                    <i class="fas fa-exclamation-triangle"></i> Unbalanced
                                </small>
                            <?php endif; ?>
                        </td>
                        <td class="text-right">
                            <div class="font-weight-bold text-danger"><?php echo numfmt_format_currency($currency_format, $total_credit, $session_company_currency); ?></div>
                            <?php if ($balanced): ?>
                                <small class="text-success">
                                    <i class="fas fa-check"></i> Balanced
                                </small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?php echo $status_badge; ?> badge-pill">
                                <i class="fas <?php echo $status_icon; ?> mr-1"></i>
                                <?php echo ucfirst($status); ?>
                            </span>
                            <?php if ($posted_by_name): ?>
                                <small class="d-block text-muted">By: <?php echo $posted_by_name; ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="font-weight-bold"><?php echo $created_by_name; ?></div>
                            <small class="text-muted">Created</small>
                        </td>
                        <td>
                            <div class="dropdown dropleft text-center">
                                <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                    <i class="fas fa-ellipsis-h"></i>
                                </button>
                                <div class="dropdown-menu">
                                    <a class="dropdown-item" href="journal_entry_view.php?id=<?php echo $journal_entry_id; ?>">
                                        <i class="fas fa-fw fa-eye mr-2"></i>View Details
                                    </a>
                                    <?php if ($status == 'draft'): ?>
                                        <a class="dropdown-item text-success" href="journal_entry_edit.php?id=<?php echo $journal_entry_id; ?>">
                                            <i class="fas fa-fw fa-edit mr-2"></i>Edit Entry
                                        </a>
                                        <a class="dropdown-item text-primary confirm-post" href="post/journal.php?post_entry=<?php echo $journal_entry_id; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>">
                                            <i class="fas fa-fw fa-check-circle mr-2"></i>Post Entry
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($status == 'posted'): ?>
                                        <a class="dropdown-item text-warning confirm-reverse" href="post/journal.php?reverse_entry=<?php echo $journal_entry_id; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>">
                                            <i class="fas fa-fw fa-undo mr-2"></i>Reverse Entry
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($status != 'void'): ?>
                                        <a class="dropdown-item text-danger confirm-void" href="post/journal.php?void_entry=<?php echo $journal_entry_id; ?>&csrf_token=<?php echo $_SESSION['csrf_token']; ?>">
                                            <i class="fas fa-fw fa-ban mr-2"></i>Void Entry
                                        </a>
                                    <?php endif; ?>
                                    <div class="dropdown-divider"></div>
                                    <a class="dropdown-item" href="journal_entry_print.php?id=<?php echo $journal_entry_id; ?>" target="_blank">
                                        <i class="fas fa-fw fa-print mr-2"></i>Print
                                    </a>
                                    <a class="dropdown-item" href="journal_entry_export.php?id=<?php echo $journal_entry_id; ?>">
                                        <i class="fas fa-fw fa-file-export mr-2"></i>Export
                                    </a>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php
                }
            }
            ?>
            </tbody>
        </table>
    </div>
    
    <!-- Recent Activity -->
    <div class="card-body border-top">
        <h5 class="mb-3"><i class="fas fa-stream text-primary mr-2"></i>Recent Journal Activity</h5>
        <div class="table-responsive">
            <table class="table table-sm table-hover">
                <thead class="bg-light">
                    <tr>
                        <th>Date & Time</th>
                        <th>Activity Type</th>
                        <th>Description</th>
                        <th>Amount</th>
                        <th>Performed By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($recent_activity->num_rows > 0): ?>
                        <?php while($activity = $recent_activity->fetch_assoc()): ?>
                            <tr>
                                <td>
                                    <div class="font-weight-bold"><?php echo date('M j, Y', strtotime($activity['activity_date'])); ?></div>
                                    <small class="text-muted"><?php echo date('H:i', strtotime($activity['activity_date'])); ?></small>
                                </td>
                                <td>
                                    <span class="badge badge-info"><?php echo str_replace('_', ' ', $activity['activity_type']); ?></span>
                                </td>
                                <td><?php echo htmlspecialchars($activity['activity_description']); ?></td>
                                <td class="text-right">
                                    <?php if ($activity['amount'] > 0): ?>
                                        <span class="text-success font-weight-bold">
                                            <?php echo numfmt_format_currency($currency_format, $activity['amount'], $session_company_currency); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($activity['performed_by_name']); ?></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted py-3">
                                <i class="fas fa-stream fa-2x mb-2"></i><br>
                                No recent journal activity found
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Ends Card Body -->
    <?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/filter_footer.php'; ?>
</div>

<script>
$(document).ready(function() {
    $('.select2').select2();
    $('[data-toggle="tooltip"]').tooltip();
    
    // Auto-submit date range when canned date is selected
    $('select[name="date"]').change(function() {
        if ($(this).val() !== '') {
            $(this).closest('form').submit();
        }
    });
    
    // Confirm actions
    $('.confirm-post').click(function(e) {
        if (!confirm('Are you sure you want to post this journal entry? This action cannot be undone.')) {
            e.preventDefault();
        }
    });
    
    $('.confirm-reverse').click(function(e) {
        if (!confirm('Are you sure you want to reverse this journal entry? This will create a reversing entry.')) {
            e.preventDefault();
        }
    });
    
    $('.confirm-void').click(function(e) {
        if (!confirm('Are you sure you want to void this journal entry? This action cannot be undone.')) {
            e.preventDefault();
        }
    });
});

function handleDateSelection(select) {
    const form = select.closest('form');
    const dtfInput = form.querySelector('input[name="dtf"]');
    const dttInput = form.querySelector('input[name="dtt"]');
    
    const today = new Date();
    let startDate, endDate;
    
    switch(select.value) {
        case 'today':
            startDate = endDate = today.toISOString().split('T')[0];
            break;
        case 'yesterday':
            const yesterday = new Date(today);
            yesterday.setDate(today.getDate() - 1);
            startDate = endDate = yesterday.toISOString().split('T')[0];
            break;
        case 'thisweek':
            const firstDay = new Date(today.setDate(today.getDate() - today.getDay()));
            const lastDay = new Date(today.setDate(today.getDate() - today.getDay() + 6));
            startDate = firstDay.toISOString().split('T')[0];
            endDate = lastDay.toISOString().split('T')[0];
            break;
        case 'lastweek':
            const lastWeekStart = new Date(today.setDate(today.getDate() - today.getDay() - 7));
            const lastWeekEnd = new Date(today.setDate(today.getDate() - today.getDay() - 1));
            startDate = lastWeekStart.toISOString().split('T')[0];
            endDate = lastWeekEnd.toISOString().split('T')[0];
            break;
        case 'thismonth':
            startDate = new Date(today.getFullYear(), today.getMonth(), 1).toISOString().split('T')[0];
            endDate = new Date(today.getFullYear(), today.getMonth() + 1, 0).toISOString().split('T')[0];
            break;
        case 'lastmonth':
            const lastMonth = new Date(today.getFullYear(), today.getMonth() - 1, 1);
            startDate = new Date(lastMonth.getFullYear(), lastMonth.getMonth(), 1).toISOString().split('T')[0];
            endDate = new Date(lastMonth.getFullYear(), lastMonth.getMonth() + 1, 0).toISOString().split('T')[0];
            break;
        case 'thisquarter':
            const quarter = Math.floor((today.getMonth() + 3) / 3);
            startDate = new Date(today.getFullYear(), (quarter - 1) * 3, 1).toISOString().split('T')[0];
            endDate = new Date(today.getFullYear(), quarter * 3, 0).toISOString().split('T')[0];
            break;
        default:
            return; // Custom date, don't auto-submit
    }
    
    dtfInput.value = startDate;
    dttInput.value = endDate;
    form.submit();
}

function batchPostDrafts() {
    Swal.fire({
        title: 'Post All Draft Entries?',
        text: 'This will post all journal entries currently in draft status. Continue?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Post All',
        cancelButtonText: 'Cancel',
        showLoaderOnConfirm: true,
        preConfirm: () => {
            return fetch('post/journal.php?batch_post_drafts=1&csrf_token=<?php echo $_SESSION['csrf_token']; ?>')
                .then(response => {
                    if (!response.ok) {
                        throw new Error(response.statusText);
                    }
                    return response.json();
                })
                .catch(error => {
                    Swal.showValidationMessage(`Request failed: ${error}`);
                });
        },
        allowOutsideClick: () => !Swal.isLoading()
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire({
                title: 'Success!',
                text: 'All draft entries have been posted.',
                icon: 'success',
                timer: 2000,
                showConfirmButton: false
            }).then(() => {
                location.reload();
            });
        }
    });
}

function reconcileJournal() {
    Swal.fire({
        title: 'Reconcile Journal Entries?',
        text: 'This will verify that all journal entries are properly balanced and identify any discrepancies.',
        icon: 'info',
        showCancelButton: true,
        confirmButtonText: 'Run Reconciliation',
        cancelButtonText: 'Cancel',
        showLoaderOnConfirm: true,
        preConfirm: () => {
            return fetch('ajax/reconcile_journal.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        let message = `Reconciliation complete!\n\n`;
                        message += `Total entries checked: ${data.total_entries}\n`;
                        message += `Balanced entries: ${data.balanced_entries}\n`;
                        message += `Unbalanced entries: ${data.unbalanced_entries}\n`;
                        
                        if (data.unbalanced_entries > 0) {
                            message += `\nUnbalanced entries found. Please review the journal entries.`;
                        }
                        
                        return message;
                    } else {
                        throw new Error(data.message || 'Reconciliation failed');
                    }
                })
                .catch(error => {
                    Swal.showValidationMessage(`Reconciliation failed: ${error}`);
                });
        },
        allowOutsideClick: () => !Swal.isLoading()
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire({
                title: 'Reconciliation Results',
                text: result.value,
                icon: 'info',
                confirmButtonText: 'OK'
            });
        }
    });
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + N for new entry
    if (e.ctrlKey && e.keyCode === 78) {
        e.preventDefault();
        window.location.href = 'journal_entry_new.php';
    }
    // Ctrl + F for focus search
    if (e.ctrlKey && e.keyCode === 70) {
        e.preventDefault();
        $('input[name="q"]').focus();
    }
    // Ctrl + R for reconcile
    if (e.ctrlKey && e.keyCode === 82) {
        e.preventDefault();
        reconcileJournal();
    }
    // Ctrl + P for print
    if (e.ctrlKey && e.keyCode === 80) {
        e.preventDefault();
        window.open('journal_entries_print.php', '_blank');
    }
});
</script>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>