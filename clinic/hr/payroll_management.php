<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Handle payroll actions
if (isset($_POST['action'])) {
    if ($_POST['action'] == 'create_period') {
        $start_date = $_POST['start_date'];
        $end_date = $_POST['end_date'];
        $pay_date = $_POST['pay_date'];
        $period_name = $_POST['period_name'];
        
        // Check if period already exists
        $check_sql = "SELECT period_id FROM payroll_periods WHERE start_date = ? AND end_date = ?";
        $check_stmt = $mysqli->prepare($check_sql);
        $check_stmt->bind_param("ss", $start_date, $end_date);
        $check_stmt->execute();
        
        if ($check_stmt->get_result()->num_rows > 0) {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Payroll period for these dates already exists!";
        } else {
            $sql = "INSERT INTO payroll_periods (period_name, start_date, end_date, pay_date, status) VALUES (?, ?, ?, ?, 'open')";
            $stmt = $mysqli->prepare($sql);
            $stmt->bind_param("ssss", $period_name, $start_date, $end_date, $pay_date);
            
            if ($stmt->execute()) {
                // Log the action
                $audit_sql = "INSERT INTO hr_audit_log (user_id, action, description, table_name, record_id) VALUES (?, 'payroll_period_created', ?, 'payroll_periods', ?)";
                $audit_stmt = $mysqli->prepare($audit_sql);
                $description = "Created payroll period: $period_name ($start_date to $end_date)";
                $audit_stmt->bind_param("isi", $_SESSION['user_id'], $description, $mysqli->insert_id);
                $audit_stmt->execute();
                
                $_SESSION['alert_type'] = "success";
                $_SESSION['alert_message'] = "Payroll period created successfully!";
            } else {
                $_SESSION['alert_type'] = "error";
                $_SESSION['alert_message'] = "Error creating payroll period: " . $stmt->error;
            }
        }
        header("Location: payroll_management.php");
        exit;
    }
}

// Handle quick actions
if (isset($_GET['action']) && isset($_GET['id'])) {
    $period_id = intval($_GET['id']);
    
    if ($_GET['action'] == 'process_payroll') {
        // Redirect to payroll process page
        header("Location: payroll_process.php?period_id=$period_id");
        exit;
    }
    
    if ($_GET['action'] == 'generate_payslips') {
        // Generate all payslips for the period
        $transactions_sql = "SELECT transaction_id FROM payroll_transactions WHERE period_id = ? AND status IN ('calculated', 'approved', 'paid')";
        $transactions_stmt = $mysqli->prepare($transactions_sql);
        $transactions_stmt->bind_param("i", $period_id);
        $transactions_stmt->execute();
        $transactions_result = $transactions_stmt->get_result();
        
        if ($transactions_result->num_rows > 0) {
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Payslips are ready for download. Use the individual download links for each employee.";
        } else {
            $_SESSION['alert_type'] = "warning";
            $_SESSION['alert_message'] = "No payroll data found for this period. Please process payroll first.";
        }
        header("Location: payroll_management.php");
        exit;
    }
    
    if ($_GET['action'] == 'process_payments') {
        // Redirect to payment processing page
        header("Location: payroll_payment.php?period_id=$period_id");
        exit;
    }
    
    if ($_GET['action'] == 'close_period') {
        $sql = "UPDATE payroll_periods SET status = 'closed' WHERE period_id = ?";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("i", $period_id);
        
        if ($stmt->execute()) {
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Payroll period closed successfully!";
        } else {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error closing payroll period: " . $stmt->error;
        }
        header("Location: payroll_management.php");
        exit;
    }
}

// Default Column Sortby/Order Filter
$sort = "start_date";
$order = "DESC";

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$search = $_GET['search'] ?? '';

// Date Range for Payroll Periods
$dtf = sanitizeInput($_GET['dtf'] ?? date('Y-01-01'));
$dtt = sanitizeInput($_GET['dtt'] ?? date('Y-m-d'));

// Build query for payroll periods
$sql = "SELECT SQL_CALC_FOUND_ROWS pp.* 
        FROM payroll_periods pp 
        WHERE DATE(pp.start_date) BETWEEN '$dtf' AND '$dtt'";

$params = [];
$types = '';

if (!empty($status_filter) && $status_filter != 'all') {
    $sql .= " AND pp.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

if (!empty($search)) {
    $sql .= " AND (pp.period_name LIKE ? OR pp.period_id LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= 'ss';
}

$sql .= " ORDER BY $sort $order LIMIT $record_from, $record_to";

$stmt = $mysqli->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$periods_result = $stmt->get_result();

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Get payroll statistics
$total_periods = $num_rows[0];
$open_periods = 0;
$processing_periods = 0;
$closed_periods = 0;
$paid_periods = 0;
$total_paid_amount = 0;
$today_periods = 0;

// Reset pointer and calculate
mysqli_data_seek($periods_result, 0);
while ($period = mysqli_fetch_assoc($periods_result)) {
    switch($period['status']) {
        case 'open':
            $open_periods++;
            break;
        case 'processing':
            $processing_periods++;
            break;
        case 'closed':
            $closed_periods++;
            break;
        case 'paid':
            $paid_periods++;
            break;
    }
    
    if (date('Y-m-d', strtotime($period['start_date'])) == date('Y-m-d')) {
        $today_periods++;
    }
    
    // Get total paid amount for closed/paid periods
    if (in_array($period['status'], ['closed', 'paid'])) {
        $txn_sql = "SELECT SUM(net_pay) as total_net_pay FROM payroll_transactions WHERE period_id = ?";
        $txn_stmt = $mysqli->prepare($txn_sql);
        $txn_stmt->bind_param("i", $period['period_id']);
        $txn_stmt->execute();
        $txn_result = $txn_stmt->get_result()->fetch_assoc();
        $total_paid_amount += $txn_result['total_net_pay'] ?? 0;
    }
}
mysqli_data_seek($periods_result, $record_from);
?>

<div class="card">
    <div class="card-header bg-info py-2">
        <h3 class="card-title mt-2 mb-0"><i class="fas fa-fw fa-money-bill-wave mr-2"></i>Payroll Management</h3>
        <div class="card-tools">
            <a href="hr_dashboard.php" class="btn btn-light">
                <i class="fas fa-arrow-left mr-2"></i>Back to Dashboard
            </a>
        </div>
    </div>

    <div class="card-header pb-2 pt-3">
        <form autocomplete="off">
            <div class="row">
                <div class="col-md-5">
                    <div class="form-group">
                        <div class="input-group">
                            <input type="search" class="form-control" name="search" value="<?php if (isset($search)) { echo stripslashes(nullable_htmlentities($search)); } ?>" placeholder="Search period names, IDs..." autofocus>
                            <div class="input-group-append">
                                <button class="btn btn-secondary" type="button" data-toggle="collapse" data-target="#advancedFilter"><i class="fas fa-filter"></i></button>
                                <button class="btn btn-primary"><i class="fa fa-search"></i></button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-7">
                    <div class="btn-toolbar form-group float-right">
                        <div class="btn-group mr-2">
                            <button type="button" class="btn btn-success" data-toggle="modal" data-target="#createPeriodModal">
                                <i class="fas fa-plus mr-2"></i>Create Period
                            </button>
                        </div>
                        <div class="btn-group mr-2">
                            <span class="btn btn-light border">
                                <i class="fas fa-calendar-alt text-primary mr-1"></i>
                                Total: <strong><?php echo $total_periods; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-folder-open text-primary mr-1"></i>
                                Open: <strong><?php echo $open_periods; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-cog text-warning mr-1"></i>
                                Processing: <strong><?php echo $processing_periods; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-check-circle text-success mr-1"></i>
                                Closed: <strong><?php echo $closed_periods; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-money-bill-wave text-success mr-1"></i>
                                Total Paid: <strong>KES <?php echo number_format($total_paid_amount, 2); ?></strong>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse <?php if (isset($_GET['dtf']) || $status_filter != 'all') { echo "show"; } ?>" id="advancedFilter">
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
                            <label>Period Status</label>
                            <select class="form-control select2" name="status" onchange="this.form.submit()">
                                <option value="all" <?php if ($status_filter == "all") { echo "selected"; } ?>>- All Statuses -</option>
                                <option value="open" <?php if ($status_filter == "open") { echo "selected"; } ?>>Open</option>
                                <option value="processing" <?php if ($status_filter == "processing") { echo "selected"; } ?>>Processing</option>
                                <option value="closed" <?php if ($status_filter == "closed") { echo "selected"; } ?>>Closed</option>
                                <option value="paid" <?php if ($status_filter == "paid") { echo "selected"; } ?>>Paid</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
    
    <?php if (isset($_SESSION['alert_message'])): ?>
        <div class="alert alert-<?php echo $_SESSION['alert_type']; ?> alert-dismissible m-3">
            <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
            <i class="icon fas fa-<?php echo $_SESSION['alert_type'] == 'success' ? 'check' : 'exclamation-triangle'; ?>"></i>
            <?php echo $_SESSION['alert_message']; ?>
        </div>
        <?php 
        unset($_SESSION['alert_type']);
        unset($_SESSION['alert_message']);
        ?>
    <?php endif; ?>
    
    <div class="table-responsive-sm">
        <table class="table table-hover mb-0">
            <thead class="<?php if ($num_rows[0] == 0) { echo "d-none"; } ?> bg-light">
            <tr>
                <th>Period Name</th>
                <th>Dates</th>
                <th>Pay Date</th>
                <th>Status</th>
                <th class="text-center">Employees</th>
                <th class="text-right">Total Net Pay</th>
                <th class="text-center">Quick Actions</th>
                <th class="text-center">More Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php
            while ($row = mysqli_fetch_array($periods_result)) {
                $period_id = intval($row['period_id']);
                $period_name = nullable_htmlentities($row['period_name']);
                $start_date = nullable_htmlentities($row['start_date']);
                $end_date = nullable_htmlentities($row['end_date']);
                $pay_date = nullable_htmlentities($row['pay_date']);
                $status = nullable_htmlentities($row['status']);
                $created_at = nullable_htmlentities($row['created_at']);

                // Get payroll transaction stats for this period
                $txn_sql = "SELECT 
                            COUNT(*) as employee_count,
                            SUM(net_pay) as total_net_pay,
                            SUM(paye) as total_paye,
                            SUM(nhif) as total_nhif,
                            SUM(nssf_tier1 + nssf_tier2) as total_nssf
                          FROM payroll_transactions 
                          WHERE period_id = ?";
                $txn_stmt = $mysqli->prepare($txn_sql);
                $txn_stmt->bind_param("i", $period_id);
                $txn_stmt->execute();
                $txn_stats = $txn_stmt->get_result()->fetch_assoc();

                $employee_count = $txn_stats['employee_count'] ?? 0;
                $total_net_pay = $txn_stats['total_net_pay'] ?? 0;
                $total_paye = $txn_stats['total_paye'] ?? 0;
                $total_nhif = $txn_stats['total_nhif'] ?? 0;
                $total_nssf = $txn_stats['total_nssf'] ?? 0;

                // Status badge styling
                $status_badge = "";
                switch($status) {
                    case 'open':
                        $status_badge = "badge-primary";
                        break;
                    case 'processing':
                        $status_badge = "badge-warning";
                        break;
                    case 'closed':
                        $status_badge = "badge-success";
                        break;
                    case 'paid':
                        $status_badge = "badge-info";
                        break;
                    default:
                        $status_badge = "badge-secondary";
                }
                ?>
                <tr>
                    <td>
                        <div class="font-weight-bold text-primary"><?php echo $period_name; ?></div>
                        <small class="text-muted">ID: <?php echo $period_id; ?></small>
                    </td>
                    <td>
                        <div class="font-weight-bold"><?php echo date('M j', strtotime($start_date)); ?> - <?php echo date('M j, Y', strtotime($end_date)); ?></div>
                        <small class="text-muted"><?php echo date('D', strtotime($start_date)); ?> to <?php echo date('D', strtotime($end_date)); ?></small>
                    </td>
                    <td>
                        <div class="font-weight-bold text-success"><?php echo date('M j, Y', strtotime($pay_date)); ?></div>
                        <small class="text-muted">Payday</small>
                    </td>
                    <td>
                        <span class="badge <?php echo $status_badge; ?>"><?php echo ucfirst($status); ?></span>
                    </td>
                    <td class="text-center">
                        <span class="badge badge-info"><?php echo $employee_count; ?></span>
                    </td>
                    <td class="text-right">
                        <div class="font-weight-bold">KES <?php echo number_format($total_net_pay, 2); ?></div>
                        <?php if ($total_net_pay > 0): ?>
                        <small class="text-muted">
                            PAYE: KES <?php echo number_format($total_paye, 2); ?><br>
                            NHIF: KES <?php echo number_format($total_nhif, 2); ?>
                        </small>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <div class="btn-group-vertical btn-group-sm">
                            <?php if ($status == 'open'): ?>
                                <a href="payroll_management.php?action=process_payroll&id=<?php echo $period_id; ?>" class="btn btn-primary btn-sm mb-1">
                                    <i class="fas fa-calculator mr-1"></i>Process
                                </a>
                            <?php elseif ($status == 'processing'): ?>
                                <a href="payroll_management.php?action=generate_payslips&id=<?php echo $period_id; ?>" class="btn btn-info btn-sm mb-1">
                                    <i class="fas fa-file-invoice mr-1"></i>Payslips
                                </a>
                                <a href="payroll_management.php?action=process_payments&id=<?php echo $period_id; ?>" class="btn btn-warning btn-sm">
                                    <i class="fas fa-money-bill-wave mr-1"></i>Pay
                                </a>
                            <?php elseif ($status == 'closed'): ?>
                                <a href="payroll_management.php?action=generate_payslips&id=<?php echo $period_id; ?>" class="btn btn-info btn-sm mb-1">
                                    <i class="fas fa-file-invoice mr-1"></i>Payslips
                                </a>
                                <span class="btn btn-success btn-sm disabled">
                                    <i class="fas fa-check mr-1"></i>Closed
                                </span>
                            <?php elseif ($status == 'paid'): ?>
                                <a href="payroll_management.php?action=generate_payslips&id=<?php echo $period_id; ?>" class="btn btn-info btn-sm mb-1">
                                    <i class="fas fa-file-invoice mr-1"></i>Payslips
                                </a>
                                <span class="btn btn-success btn-sm disabled">
                                    <i class="fas fa-check-double mr-1"></i>Paid
                                </span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="text-center">
                        <div class="dropdown">
                            <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                <i class="fas fa-ellipsis-h"></i>
                            </button>
                            <div class="dropdown-menu dropdown-menu-right">
                                <a class="dropdown-item" href="payroll_process.php?period_id=<?php echo $period_id; ?>">
                                    <i class="fas fa-fw fa-calculator mr-2"></i>Detailed Processing
                                </a>
                                <a class="dropdown-item" href="payroll_view.php?period_id=<?php echo $period_id; ?>">
                                    <i class="fas fa-fw fa-eye mr-2"></i>View Details
                                </a>
                                <a class="dropdown-item" href="payroll_payment.php?period_id=<?php echo $period_id; ?>">
                                    <i class="fas fa-fw fa-money-bill-wave mr-2"></i>Payment Processing
                                </a>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item" href="payroll_report.php?period_id=<?php echo $period_id; ?>" target="_blank">
                                    <i class="fas fa-fw fa-file-pdf mr-2"></i>Generate Report
                                </a>
                                <a class="dropdown-item" href="payroll_export.php?period_id=<?php echo $period_id; ?>">
                                    <i class="fas fa-fw fa-download mr-2"></i>Export Data
                                </a>
                                <?php if ($status == 'open'): ?>
                                    <div class="dropdown-divider"></div>
                                    <a class="dropdown-item text-warning confirm-link" href="payroll_management.php?action=close_period&id=<?php echo $period_id; ?>&csrf_token=<?php echo $_SESSION['csrf_token'] ?>" data-confirm-message="Close this payroll period? This action cannot be undone.">
                                        <i class="fas fa-fw fa-lock mr-2"></i>Close Period
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php
            } 
            
            if ($num_rows[0] == 0) {
                ?>
                <tr>
                    <td colspan="8" class="text-center py-4">
                        <i class="fas fa-calendar-times fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No payroll periods found</h5>
                        <p class="text-muted">No payroll periods match your current filters.</p>
                        <button type="button" class="btn btn-primary mt-2" data-toggle="modal" data-target="#createPeriodModal">
                            <i class="fas fa-plus mr-2"></i>Create Payroll Period
                        </button>
                        <a href="?status=all" class="btn btn-outline-secondary mt-2">
                            <i class="fas fa-redo mr-2"></i>Reset Filters
                        </a>
                    </td>
                </tr>
                <?php
            }
            ?>
            </tbody>
        </table>
    </div>
    
    <!-- Ends Card Body -->
    <?php 
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/filter_footer.php';
    ?>
</div>

<!-- Create Period Modal -->
<div class="modal fade" id="createPeriodModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Create New Payroll Period</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="create_period">
                    <div class="form-group">
                        <label>Period Name *</label>
                        <input type="text" class="form-control" name="period_name" required 
                               placeholder="e.g., January 2024 Payroll" value="<?php echo date('F Y'); ?> Payroll">
                    </div>
                    <div class="form-group">
                        <label>Start Date *</label>
                        <input type="date" class="form-control" name="start_date" required 
                               value="<?php echo date('Y-m-01'); ?>">
                    </div>
                    <div class="form-group">
                        <label>End Date *</label>
                        <input type="date" class="form-control" name="end_date" required 
                               value="<?php echo date('Y-m-t'); ?>">
                    </div>
                    <div class="form-group">
                        <label>Pay Date *</label>
                        <input type="date" class="form-control" name="pay_date" required 
                               value="<?php echo date('Y-m-d', strtotime('+5 days')); ?>">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Period</button>
                </div>
            </form>
        </div>
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

    // Keyboard shortcuts
    $(document).keydown(function(e) {
        // Ctrl + F for focus search
        if (e.ctrlKey && e.keyCode === 70) {
            e.preventDefault();
            $('input[name="search"]').focus();
        }
    });

    // Confirm links
    $('.confirm-link').click(function(e) {
        e.preventDefault();
        var message = $(this).data('confirm-message') || 'Are you sure?';
        var href = $(this).attr('href');
        
        if (confirm(message)) {
            window.location.href = href;
        }
    });

    // Set default dates for next period
    const today = new Date();
    const firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
    const lastDay = new Date(today.getFullYear(), today.getMonth() + 1, 0);
    const payDay = new Date(today.getFullYear(), today.getMonth() + 1, 5);
    
    // Format dates as YYYY-MM-DD
    function formatDate(date) {
        return date.toISOString().split('T')[0];
    }
    
    // Set values if fields are empty in modal
    $('#createPeriodModal').on('show.bs.modal', function() {
        $('input[name="start_date"]').val(formatDate(firstDay));
        $('input[name="end_date"]').val(formatDate(lastDay));
        $('input[name="pay_date"]').val(formatDate(payDay));
    });
});
</script>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>