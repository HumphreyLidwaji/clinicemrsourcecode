<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Default Column Sortby/Order Filter
$sort = "jh.entry_date";
$order = "DESC";
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Filter parameters
$status_filter = $_GET['status'] ?? '';
$date_filter = $_GET['date'] ?? date('Y-m-d');

// Date Range Filter
$dtf = sanitizeInput($_GET['dtf'] ?? '');
$dtt = sanitizeInput($_GET['dtt'] ?? '');

// Set default date range if not provided
if (empty($dtf)) {
    $dtf = date('Y-m-01'); // First day of current month
}
if (empty($dtt)) {
    $dtt = date('Y-m-t'); // Last day of current month
}

if (!empty($dtf) && !empty($dtt)) {
    $date_query = "AND DATE(jh.entry_date) BETWEEN '$dtf' AND '$dtt'";
} else {
    $date_query = "AND DATE(jh.entry_date) = '$date_filter'";
}

// Status Filter
if ($status_filter == 'balanced') {
    $status_query = "HAVING ABS(total_debits - total_credits) < 0.01";
} elseif ($status_filter == 'unbalanced') {
    $status_query = "HAVING ABS(total_debits - total_credits) >= 0.01";
} else {
    $status_query = "";
}

// Search Query
$q = sanitizeInput($_GET['q'] ?? '');
if (!empty($q)) {
    $search_query = "AND (
        jh.reference_number LIKE '%$q%' 
        OR jh.description LIKE '%$q%'
        OR jh.journal_header_id LIKE '%$q%'
        OR u.user_name LIKE '%$q%'
    )";
} else {
    $search_query = '';
}

// Main query for journal headers
$sql = mysqli_query(
    $mysqli,
    "
    SELECT SQL_CALC_FOUND_ROWS 
        jh.*,
        u.user_name AS created_by,
        COUNT(je.entry_id) AS entry_count,
        COALESCE(SUM(CASE WHEN jel.entry_type = 'debit' THEN jel.amount ELSE 0 END), 0) AS total_debits,
        COALESCE(SUM(CASE WHEN jel.entry_type = 'credit' THEN jel.amount ELSE 0 END), 0) AS total_credits,
        DATEDIFF(CURDATE(), jh.created_at) AS days_ago
    FROM journal_headers jh
    LEFT JOIN journal_entries je ON jh.journal_header_id = je.journal_header_id
    LEFT JOIN journal_entry_lines jel ON je.entry_id = jel.entry_id
    LEFT JOIN users u ON jh.created_by = u.user_id
    WHERE jh.archived_at IS NULL
      $date_query
      $search_query
    GROUP BY jh.journal_header_id
    $status_query
    ORDER BY $sort $order
    LIMIT $record_from, $record_to
");

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Get statistics
$total_headers = $num_rows[0];
$balanced_headers = 0;
$unbalanced_headers = 0;
$today_headers = 0;
$total_debits_all = 0;
$total_credits_all = 0;

// Reset pointer and calculate
mysqli_data_seek($sql, 0);
while ($header = mysqli_fetch_assoc($sql)) {
    $is_balanced = abs($header['total_debits'] - $header['total_credits']) < 0.01;
    
    if ($is_balanced) {
        $balanced_headers++;
    } else {
        $unbalanced_headers++;
    }
    
    if ($header['days_ago'] == 0) {
        $today_headers++;
    }
    
    $total_debits_all += $header['total_debits'];
    $total_credits_all += $header['total_credits'];
}
mysqli_data_seek($sql, $record_from); // Reset pointer back to current page

// Get recent journal entries for quick view
$recent_entries_sql = "SELECT je.*, jel.amount, jel.entry_type, 
                               a.account_name, a.account_number,
                               jh.journal_header_id, jh.reference_number
                       FROM journal_entries je
                       JOIN journal_entry_lines jel ON je.entry_id = jel.entry_id
                       JOIN accounts a ON jel.account_id = a.account_id
                       JOIN journal_headers jh ON je.journal_header_id = jh.journal_header_id
                       WHERE jh.archived_at IS NULL
                       ORDER BY je.entry_date DESC, je.entry_id DESC
                       LIMIT 10";
$recent_entries = $mysqli->query($recent_entries_sql);

?>

<div class="card">
    <div class="card-header bg-info py-2">
        <h3 class="card-title mt-2 mb-0"><i class="fas fa-fw fa-file-alt mr-2"></i>Journal Headers</h3>
        <div class="card-tools">
            <a href="journal_entry_new.php" class="btn btn-success">
                <i class="fas fa-plus mr-2"></i>New Journal Entry
            </a>
        </div>
    </div>
    
    <!-- Statistics Row -->
    <div class="card-body border-bottom">
        <div class="row text-center">
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-info"><i class="fas fa-file-alt"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Headers</span>
                        <span class="info-box-number"><?php echo $total_headers; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-success"><i class="fas fa-check-circle"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Balanced</span>
                        <span class="info-box-number"><?php echo $balanced_headers; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-warning"><i class="fas fa-exclamation-triangle"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Unbalanced</span>
                        <span class="info-box-number"><?php echo $unbalanced_headers; ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-primary"><i class="fas fa-dollar-sign"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Debits</span>
                        <span class="info-box-number"><?php echo numfmt_format_currency($currency_format, $total_debits_all, $session_company_currency); ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-secondary"><i class="fas fa-dollar-sign"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Credits</span>
                        <span class="info-box-number"><?php echo numfmt_format_currency($currency_format, $total_credits_all, $session_company_currency); ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-warning"><i class="fas fa-calendar-day"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Today's Headers</span>
                        <span class="info-box-number"><?php echo $today_headers; ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card-header pb-2 pt-3">
        <form autocomplete="off">
            <div class="row">
                <div class="col-md-5">
                    <div class="form-group">
                        <div class="input-group">
                            <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search references, descriptions, users..." autofocus>
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
                            <a href="?<?php echo $url_query_strings_sort ?>&export=pdf" class="btn btn-default">
                                <i class="fa fa-fw fa-file-pdf mr-2"></i>Export Report
                            </a>
                            <a href="journal_entry_new.php" class="btn btn-success">
                                <i class="fas fa-fw fa-plus mr-2"></i>New Entry
                            </a>
                            <a href="reports_general_ledger.php" class="btn btn-warning">
                                <i class="fas fa-fw fa-book mr-2"></i>General Ledger
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <div 
                class="collapse 
                    <?php 
                    if (
                    isset($_GET['dtf'])
                    || $status_filter
                    || ($_GET['canned_date'] ?? '') !== "custom" ) 
                    { 
                        echo "show"; 
                    } 
                    ?>
                "
                id="advancedFilter"
            >
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
                                <option <?php if (($_GET['canned_date'] ?? '') == "thisquarter") { echo "selected"; } ?> value="thisquarter">This Quarter</option>
                                <option <?php if (($_GET['canned_date'] ?? '') == "thisyear") { echo "selected"; } ?> value="thisyear">This Year</option>
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
                            <label>Balance Status</label>
                            <select class="form-control select2" name="status" onchange="this.form.submit()">
                                <option value="">- All Statuses -</option>
                                <option value="balanced" <?php if ($status_filter == "balanced") { echo "selected"; } ?>>Balanced</option>
                                <option value="unbalanced" <?php if ($status_filter == "unbalanced") { echo "selected"; } ?>>Unbalanced</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Quick Actions</label>
                            <div class="btn-group-vertical btn-block">
                                <a href="journal_entry_new.php" class="btn btn-success btn-sm">
                                    <i class="fas fa-plus mr-1"></i> New Journal Entry
                                </a>
                                <a href="reports_general_ledger.php" class="btn btn-info btn-sm">
                                    <i class="fas fa-book mr-1"></i> General Ledger
                                </a>
                                <a href="journal_entries.php" class="btn btn-warning btn-sm">
                                    <i class="fas fa-list mr-1"></i> View All Entries
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
    
    <div class="table-responsive-sm">
        <table class="table table-hover mb-0">
            <thead class="<?php if ($num_rows[0] == 0) { echo "d-none"; } ?> bg-light">
            <tr>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=jh.entry_date&order=<?php echo $disp; ?>">
                        Date <?php if ($sort == 'jh.entry_date') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=jh.journal_header_id&order=<?php echo $disp; ?>">
                        Header ID <?php if ($sort == 'jh.journal_header_id') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=jh.reference_number&order=<?php echo $disp; ?>">
                        Reference <?php if ($sort == 'jh.reference_number') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>Description</th>
                <th class="text-center">
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=entry_count&order=<?php echo $disp; ?>">
                        Entries <?php if ($sort == 'entry_count') { echo $order_icon; } ?>
                    </a>
                </th>
                <th class="text-right">Total Debits</th>
                <th class="text-right">Total Credits</th>
                <th class="text-center">Balance Status</th>
                <th class="text-center">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php

            if ($num_rows[0] == 0): ?>
                <tr>
                    <td colspan="9" class="text-center text-muted py-4">
                        <i class="fas fa-file-alt fa-3x mb-3"></i>
                        <h5>No Journal Headers Found</h5>
                        <p class="mb-0">No journal headers match your current filters.</p>
                    </td>
                </tr>
            <?php else:
                while ($row = mysqli_fetch_array($sql)) {
                    $journal_header_id = intval($row['journal_header_id']);
                    $entry_date = nullable_htmlentities($row['entry_date']);
                    $reference_number = nullable_htmlentities($row['reference_number']);
                    $description = nullable_htmlentities($row['description']);
                    $entry_count = intval($row['entry_count']);
                    $total_debits = floatval($row['total_debits']);
                    $total_credits = floatval($row['total_credits']);
                    $created_by = nullable_htmlentities($row['created_by']);
                    $days_ago = intval($row['days_ago']);

                    // Balance status
                    $is_balanced = abs($total_debits - $total_credits) < 0.01;
                    $balance_status = $is_balanced ? "Balanced" : "Unbalanced";
                    $balance_badge = $is_balanced ? "badge-success" : "badge-warning";
                    $balance_diff = $total_debits - $total_credits;

                    // Check if today's header
                    $is_today = $days_ago == 0;

                    ?>
                    <tr class="<?php echo $is_today ? 'table-info' : ''; ?>">
                        <td>
                            <div class="font-weight-bold"><?php echo date('M j, Y', strtotime($entry_date)); ?></div>
                            <small class="text-muted"><?php echo date('H:i', strtotime($entry_date)); ?></small>
                        </td>
                        <td>
                            <div class="font-weight-bold text-primary">#<?php echo $journal_header_id; ?></div>
                        </td>
                        <td>
                            <div class="font-weight-bold"><?php echo $reference_number; ?></div>
                        </td>
                        <td>
                            <div class="font-weight-bold"><?php echo $description; ?></div>
                            <?php if($created_by): ?>
                                <small class="text-muted">By: <?php echo $created_by; ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <span class="badge badge-info badge-pill"><?php echo $entry_count; ?></span>
                        </td>
                        <td class="text-right">
                            <div class="font-weight-bold text-success">
                                <?php echo numfmt_format_currency($currency_format, $total_debits, $session_company_currency); ?>
                            </div>
                        </td>
                        <td class="text-right">
                            <div class="font-weight-bold text-danger">
                                <?php echo numfmt_format_currency($currency_format, $total_credits, $session_company_currency); ?>
                            </div>
                        </td>
                        <td class="text-center">
                            <span class="badge <?php echo $balance_badge; ?>" data-toggle="tooltip" title="Difference: <?php echo numfmt_format_currency($currency_format, abs($balance_diff), $session_company_currency); ?>">
                                <?php echo $balance_status; ?>
                            </span>
                            <?php if(!$is_balanced): ?>
                                <small class="text-danger d-block">
                                    <?php echo numfmt_format_currency($currency_format, abs($balance_diff), $session_company_currency); ?>
                                </small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="dropdown dropleft text-center">
                                <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                    <i class="fas fa-ellipsis-h"></i>
                                </button>
                                <div class="dropdown-menu">
                                    <a class="dropdown-item" href="journal_entry_view.php?id=<?php echo $journal_header_id; ?>">
                                        <i class="fas fa-fw fa-eye mr-2"></i>View Journal
                                    </a>
                                    <a class="dropdown-item" href="journal_entry_print.php?id=<?php echo $journal_header_id; ?>">
                                        <i class="fas fa-fw fa-print mr-2"></i>Print Journal
                                    </a>
                                    <div class="dropdown-divider"></div>
                                    <?php if($is_today): ?>
                                        <a class="dropdown-item text-warning" href="journal_entry_edit.php?id=<?php echo $journal_header_id; ?>">
                                            <i class="fas fa-fw fa-edit mr-2"></i>Edit Journal
                                        </a>
                                    <?php endif; ?>
                                    <?php if($is_today): ?>
                                        <a class="dropdown-item text-danger confirm-link" href="post.php?void_journal=<?php echo $journal_header_id; ?>">
                                            <i class="fas fa-fw fa-times mr-2"></i>Void Journal
                                        </a>
                                    <?php endif; ?>
                                    <?php if(!$is_today): ?>
                                        <a class="dropdown-item text-info" href="journal_entry_copy.php?id=<?php echo $journal_header_id; ?>">
                                            <i class="fas fa-fw fa-copy mr-2"></i>Copy Journal
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php
                } 
            endif; ?>

            </tbody>
        </table>
    </div>
    
    <!-- Recent Journal Entries Section -->
    <div class="card-body border-top">
        <h5 class="mb-3"><i class="fas fa-receipt text-primary mr-2"></i>Recent Journal Entries</h5>
        <div class="table-responsive">
            <table class="table table-sm table-hover">
                <thead class="bg-light">
                    <tr>
                        <th>Date</th>
                        <th>Account</th>
                        <th>Description</th>
                        <th>Reference</th>
                        <th>Type</th>
                        <th class="text-right">Amount</th>
                        <th class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    if ($recent_entries->num_rows > 0):
                        while($entry = $recent_entries->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo date('M j, Y', strtotime($entry['entry_date'])); ?></td>
                                <td>
                                    <small class="font-weight-bold"><?php echo htmlspecialchars($entry['account_name']); ?></small>
                                    <br><small class="text-muted"><?php echo htmlspecialchars($entry['account_number']); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($entry['entry_description']); ?></td>
                                <td>
                                    <?php if($entry['reference_number']): ?>
                                        <span class="badge badge-light"><?php echo htmlspecialchars($entry['reference_number']); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge-<?php echo $entry['entry_type'] == 'debit' ? 'success' : 'danger'; ?>">
                                        <?php echo $entry['entry_type'] == 'debit' ? 'Dr' : 'Cr'; ?>
                                    </span>
                                </td>
                                <td class="text-right font-weight-bold <?php echo $entry['entry_type'] == 'debit' ? 'text-success' : 'text-danger'; ?>">
                                    <?php echo numfmt_format_currency($currency_format, $entry['amount'], $session_company_currency); ?>
                                </td>
                                <td class="text-center">
                                    <a href="journal_entry_view.php?id=<?php echo $entry['journal_header_id']; ?>" class="btn btn-info btn-sm">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endwhile; 
                    else: ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-3">
                                <i class="fas fa-receipt fa-2x mb-2"></i><br>
                                No recent journal entries found
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="text-center mt-2">
            <a href="journal_entries.php" class="btn btn-outline-primary btn-sm">
                View All Journal Entries
            </a>
        </div>
    </div>
    
    <!-- Ends Card Body -->
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

    // Confirm destructive actions
    $('.confirm-link').click(function(e) {
        if (!confirm('Are you sure you want to void this journal entry? This action cannot be undone.')) {
            e.preventDefault();
        }
    });

    // Auto-refresh every 30 seconds for real-time updates
    setInterval(function() {
        $.get('ajax/journal_headers_stats.php', function(data) {
            // Update statistics cards
            $('.info-box-number').each(function(index) {
                if (data.stats[index]) {
                    $(this).text(data.stats[index]);
                }
            });
        });
    }, 30000);
});

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + N for new journal entry
    if (e.ctrlKey && e.keyCode === 78) {
        e.preventDefault();
        window.location.href = 'journal_entry_new.php';
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