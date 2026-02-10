<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Get current page name for redirects
$current_page = basename($_SERVER['PHP_SELF']);

// Get invoice ID from URL
$invoice_id = intval($_GET['invoice_id'] ?? 0);

if (!$invoice_id) {
    flash_alert("No invoice specified", 'error');
    header("Location: billing_dashboard.php");
    exit;
}

/* Check if user has permission
$user_role = $_SESSION['user_role'] ?? '';
$allowed_roles = ['admin', 'doctor', 'accountant', 'receptionist'];
if (!in_array($user_role, $allowed_roles)) {
    flash_alert("You don't have permission to view M-Pesa logs", 'error');
    header("Location: billing_invoices.php");
    exit;
}*/ 

// Get invoice details
$invoice_sql = "SELECT i.*, 
                       CONCAT(p.patient_first_name, ' ', p.patient_last_name) as patient_name,
                       p.patient_phone,
                       i.invoice_amount - IFNULL(i.paid_amount, 0) as remaining_balance
                FROM invoices i
                LEFT JOIN patients p ON i.invoice_client_id = p.patient_id
                WHERE i.invoice_id = ?";
$invoice_stmt = mysqli_prepare($mysqli, $invoice_sql);
mysqli_stmt_bind_param($invoice_stmt, 'i', $invoice_id);
mysqli_stmt_execute($invoice_stmt);
$invoice_result = mysqli_stmt_get_result($invoice_stmt);
$invoice = mysqli_fetch_assoc($invoice_result);
mysqli_stmt_close($invoice_stmt);

if (!$invoice) {
    flash_alert("Invoice not found", 'error');
    header("Location: billing_invoices.php");
    exit;
}

// Handle filters
$filter_status = $_GET['status'] ?? 'all';
$filter_date_from = $_GET['date_from'] ?? '';
$filter_date_to = $_GET['date_to'] ?? '';
$filter_phone = $_GET['phone'] ?? '';

// Build filter conditions
$where_conditions = ["pt.invoice_id = ?"];
$params = [$invoice_id];
$param_types = "i";

if ($filter_status !== 'all') {
    $where_conditions[] = "pt.status = ?";
    $params[] = $filter_status;
    $param_types .= "s";
}

if (!empty($filter_date_from)) {
    $where_conditions[] = "DATE(pt.created_at) >= ?";
    $params[] = $filter_date_from;
    $param_types .= "s";
}

if (!empty($filter_date_to)) {
    $where_conditions[] = "DATE(pt.created_at) <= ?";
    $params[] = $filter_date_to;
    $param_types .= "s";
}

if (!empty($filter_phone)) {
    $where_conditions[] = "pt.phone_number LIKE ?";
    $params[] = '%' . $filter_phone . '%';
    $param_types .= "s";
}

$where_clause = implode(' AND ', $where_conditions);

// Get M-Pesa transactions with pagination
$page = intval($_GET['page'] ?? 1);
$limit = 20;
$offset = ($page - 1) * $limit;

// Count total transactions
$count_sql = "SELECT COUNT(*) as total 
              FROM mpesa_pending_transactions pt
              WHERE $where_clause";
$count_stmt = mysqli_prepare($mysqli, $count_sql);
mysqli_stmt_bind_param($count_stmt, $param_types, ...$params);
mysqli_stmt_execute($count_stmt);
$count_result = mysqli_stmt_get_result($count_stmt);
$total_count = mysqli_fetch_assoc($count_result)['total'];
mysqli_stmt_close($count_stmt);

$total_pages = ceil($total_count / $limit);

// Get transactions with details
$sql = "SELECT 
            pt.*,
            ml.log_id,
            ml.request_data,
            ml.response_data,
            ml.http_code as request_http_code,
            c.callback_id,
            c.result_code,
            c.result_desc,
            c.mpesa_receipt,
            c.amount as callback_amount,
            c.phone_number as callback_phone,
            c.transaction_date,
            c.created_at as callback_time,
            c.raw_data as callback_raw,
            p.payment_id,
            p.payment_number,
            p.payment_amount,
            p.payment_date,
            p.accounting_status,
            u.user_name as initiated_by
        FROM mpesa_pending_transactions pt
        LEFT JOIN mpesa_logs ml ON pt.log_id = ml.log_id
        LEFT JOIN mpesa_callbacks c ON pt.checkout_request_id = c.checkout_request_id
        LEFT JOIN payments p ON pt.payment_id = p.payment_id
        LEFT JOIN users u ON ml.created_by = u.user_id
        WHERE $where_clause
        ORDER BY pt.created_at DESC
        LIMIT ? OFFSET ?";

$params[] = $limit;
$params[] = $offset;
$param_types .= "ii";

$stmt = mysqli_prepare($mysqli, $sql);
mysqli_stmt_bind_param($stmt, $param_types, ...$params);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$transactions = [];
while ($row = mysqli_fetch_assoc($result)) {
    // Format phone numbers for display
    $row['formatted_phone'] = formatPhoneDisplay($row['phone_number']);
    if ($row['callback_phone']) {
        $row['callback_formatted_phone'] = formatPhoneDisplay($row['callback_phone']);
    }
    
    // Parse request/response data
    if ($row['request_data']) {
        $row['request_json'] = json_decode($row['request_data'], true);
    }
    if ($row['response_data']) {
        $row['response_json'] = json_decode($row['response_data'], true);
    }
    if ($row['callback_raw']) {
        $row['callback_json'] = json_decode($row['callback_raw'], true);
    }
    
    // Determine display status
    $row['display_status'] = determineDisplayStatus($row);
    
    $transactions[] = $row;
}
mysqli_stmt_close($stmt);

// Get statistics
$stats_sql = "SELECT 
                COUNT(*) as total_transactions,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count,
                SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as total_amount,
                MIN(created_at) as first_transaction,
                MAX(created_at) as last_transaction
              FROM mpesa_pending_transactions 
              WHERE invoice_id = ?";
$stats_stmt = mysqli_prepare($mysqli, $stats_sql);
mysqli_stmt_bind_param($stats_stmt, 'i', $invoice_id);
mysqli_stmt_execute($stats_stmt);
$stats_result = mysqli_stmt_get_result($stats_stmt);
$stats = mysqli_fetch_assoc($stats_result);
mysqli_stmt_close($stats_stmt);

// Get API query logs
$query_logs_sql = "SELECT 
                    ql.*,
                    pt.amount,
                    pt.status
                   FROM mpesa_query_logs ql
                   LEFT JOIN mpesa_pending_transactions pt ON ql.checkout_request_id = pt.checkout_request_id
                   WHERE pt.invoice_id = ?
                   ORDER BY ql.created_at DESC
                   LIMIT 10";
$query_logs_stmt = mysqli_prepare($mysqli, $query_logs_sql);
mysqli_stmt_bind_param($query_logs_stmt, 'i', $invoice_id);
mysqli_stmt_execute($query_logs_stmt);
$query_logs_result = mysqli_stmt_get_result($query_logs_stmt);

$query_logs = [];
while ($log = mysqli_fetch_assoc($query_logs_result)) {
    if ($log['response_data']) {
        $log['response_json'] = json_decode($log['response_data'], true);
    }
    $query_logs[] = $log;
}
mysqli_stmt_close($query_logs_stmt);

// Helper functions
function formatPhoneDisplay($phone) {
    if (empty($phone)) return 'N/A';
    
    $phone = preg_replace('/\D/', '', $phone);
    
    if (strlen($phone) === 12 && substr($phone, 0, 3) === '254') {
        return '0' . substr($phone, 3);
    } elseif (strlen($phone) === 10 && substr($phone, 0, 1) === '0') {
        return $phone;
    } elseif (strlen($phone) === 9) {
        return '0' . $phone;
    }
    
    return $phone;
}

function determineDisplayStatus($transaction) {
    // Priority: callback result > payment record > transaction status
    if ($transaction['result_code'] !== null) {
        if ($transaction['result_code'] == 0) {
            return [
                'text' => 'Completed',
                'class' => 'success',
                'icon' => 'check-circle',
                'details' => 'M-Pesa confirmed payment'
            ];
        } else {
            return [
                'text' => 'Failed',
                'class' => 'danger',
                'icon' => 'times-circle',
                'details' => $transaction['result_desc'] ?? 'Payment failed'
            ];
        }
    }
    
    if ($transaction['payment_id']) {
        return [
            'text' => 'Recorded',
            'class' => 'info',
            'icon' => 'file-invoice-dollar',
            'details' => 'Payment recorded in system'
        ];
    }
    
    switch ($transaction['status']) {
        case 'pending':
            return [
                'text' => 'Pending',
                'class' => 'warning',
                'icon' => 'clock',
                'details' => 'Waiting for M-Pesa response'
            ];
        case 'completed':
            return [
                'text' => 'Processed',
                'class' => 'primary',
                'icon' => 'sync-alt',
                'details' => 'Transaction processed'
            ];
        case 'failed':
            return [
                'text' => 'Failed',
                'class' => 'danger',
                'icon' => 'exclamation-triangle',
                'details' => 'Transaction failed'
            ];
        case 'cancelled':
            return [
                'text' => 'Cancelled',
                'class' => 'secondary',
                'icon' => 'ban',
                'details' => 'Transaction cancelled'
            ];
        default:
            return [
                'text' => 'Unknown',
                'class' => 'dark',
                'icon' => 'question-circle',
                'details' => 'Status unknown'
            ];
    }
}

function formatStatusBadge($status_data) {
    return '<span class="badge badge-' . $status_data['class'] . '">
                <i class="fas fa-' . $status_data['icon'] . ' mr-1"></i>
                ' . $status_data['text'] . '
            </span>';
}
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0 text-white">
                <i class="fas fa-fw fa-history mr-2"></i>M-Pesa Transaction Logs
                <small class="text-light ml-2">Invoice #<?php echo htmlspecialchars($invoice['invoice_number']); ?></small>
            </h3>
            <div class="card-tools">
                <a href="process_payment.php?invoice_id=<?php echo $invoice_id; ?>" class="btn btn-light btn-sm">
                    <i class="fas fa-credit-card mr-2"></i>Process Payment
                </a>
                <a href="billing_invoices.php" class="btn btn-light btn-sm ml-2">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Invoices
                </a>
            </div>
        </div>
    </div>

    <div class="card-body">
        <?php if (isset($_SESSION['alert_message'])): ?>
            <div class="alert alert-<?php echo $_SESSION['alert_type']; ?> alert-dismissible">
                <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
                <i class="icon fas fa-<?php echo $_SESSION['alert_type'] == 'success' ? 'check' : 
                                      ($_SESSION['alert_type'] == 'warning' ? 'exclamation-triangle' : 'exclamation-triangle'); ?>"></i>
                <?php echo $_SESSION['alert_message']; ?>
            </div>
            <?php 
            unset($_SESSION['alert_type']);
            unset($_SESSION['alert_message']);
            ?>
        <?php endif; ?>

        <!-- Invoice Summary -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card card-outline card-info">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-file-invoice mr-2"></i>Invoice Summary</h3>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3">
                                <div class="info-box bg-light">
                                    <span class="info-box-icon bg-info"><i class="fas fa-user"></i></span>
                                    <div class="info-box-content">
                                        <span class="info-box-text">Patient</span>
                                        <span class="info-box-number"><?php echo htmlspecialchars($invoice['patient_name']); ?></span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="info-box bg-light">
                                    <span class="info-box-icon bg-success"><i class="fas fa-receipt"></i></span>
                                    <div class="info-box-content">
                                        <span class="info-box-text">Invoice Amount</span>
                                        <span class="info-box-number">KSH <?php echo number_format($invoice['invoice_amount'], 2); ?></span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="info-box bg-light">
                                    <span class="info-box-icon bg-warning"><i class="fas fa-mobile-alt"></i></span>
                                    <div class="info-box-content">
                                        <span class="info-box-text">Patient Phone</span>
                                        <span class="info-box-number"><?php echo formatPhoneDisplay($invoice['patient_phone']); ?></span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="info-box bg-light">
                                    <span class="info-box-icon bg-danger"><i class="fas fa-balance-scale"></i></span>
                                    <div class="info-box-content">
                                        <span class="info-box-text">Remaining Balance</span>
                                        <span class="info-box-number">KSH <?php echo number_format($invoice['remaining_balance'], 2); ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card card-dark">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-chart-bar mr-2"></i>M-Pesa Transaction Statistics</h3>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3 col-sm-6">
                                <div class="info-box">
                                    <span class="info-box-icon bg-primary"><i class="fas fa-exchange-alt"></i></span>
                                    <div class="info-box-content">
                                        <span class="info-box-text">Total Transactions</span>
                                        <span class="info-box-number"><?php echo $stats['total_transactions']; ?></span>
                                        <div class="progress">
                                            <div class="progress-bar bg-primary" style="width: 100%"></div>
                                        </div>
                                        <small>First: <?php echo $stats['first_transaction'] ? date('M j, Y', strtotime($stats['first_transaction'])) : 'N/A'; ?></small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3 col-sm-6">
                                <div class="info-box">
                                    <span class="info-box-icon bg-success"><i class="fas fa-check-circle"></i></span>
                                    <div class="info-box-content">
                                        <span class="info-box-text">Completed</span>
                                        <span class="info-box-number"><?php echo $stats['completed_count'] ?? 0; ?></span>
                                        <div class="progress">
                                            <div class="progress-bar bg-success" 
                                                 style="width: <?php echo $stats['total_transactions'] > 0 ? ($stats['completed_count'] / $stats['total_transactions'] * 100) : 0; ?>%"></div>
                                        </div>
                                        <small>Amount: KSH <?php echo number_format($stats['total_amount'] ?? 0, 2); ?></small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3 col-sm-6">
                                <div class="info-box">
                                    <span class="info-box-icon bg-warning"><i class="fas fa-clock"></i></span>
                                    <div class="info-box-content">
                                        <span class="info-box-text">Pending</span>
                                        <span class="info-box-number"><?php echo $stats['pending_count']; ?></span>
                                        <div class="progress">
                                            <div class="progress-bar bg-warning" 
                                                 style="width: <?php echo $stats['total_transactions'] > 0 ? ($stats['pending_count'] / $stats['total_transactions'] * 100) : 0; ?>%"></div>
                                        </div>
                                        <small>Waiting for confirmation</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3 col-sm-6">
                                <div class="info-box">
                                    <span class="info-box-icon bg-danger"><i class="fas fa-times-circle"></i></span>
                                    <div class="info-box-content">
                                        <span class="info-box-text">Failed/Cancelled</span>
                                        <span class="info-box-number"><?php echo $stats['failed_count'] + $stats['cancelled_count']; ?></span>
                                        <div class="progress">
                                            <div class="progress-bar bg-danger" 
                                                 style="width: <?php echo $stats['total_transactions'] > 0 ? (($stats['failed_count'] + $stats['cancelled_count']) / $stats['total_transactions'] * 100) : 0; ?>%"></div>
                                        </div>
                                        <small>Last: <?php echo $stats['last_transaction'] ? date('M j, Y', strtotime($stats['last_transaction'])) : 'N/A'; ?></small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card card-secondary">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-filter mr-2"></i>Filter Transactions</h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-tool" data-card-widget="collapse">
                                <i class="fas fa-minus"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <form method="GET" action="" class="form-horizontal">
                            <input type="hidden" name="invoice_id" value="<?php echo $invoice_id; ?>">
                            
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label>Status</label>
                                        <select class="form-control" name="status">
                                            <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All Status</option>
                                            <option value="pending" <?php echo $filter_status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                            <option value="completed" <?php echo $filter_status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                            <option value="failed" <?php echo $filter_status === 'failed' ? 'selected' : ''; ?>>Failed</option>
                                            <option value="cancelled" <?php echo $filter_status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label>Date From</label>
                                        <input type="date" class="form-control" name="date_from" 
                                               value="<?php echo htmlspecialchars($filter_date_from); ?>">
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label>Date To</label>
                                        <input type="date" class="form-control" name="date_to" 
                                               value="<?php echo htmlspecialchars($filter_date_to); ?>">
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label>Phone Number</label>
                                        <input type="text" class="form-control" name="phone" 
                                               placeholder="Search phone..." 
                                               value="<?php echo htmlspecialchars($filter_phone); ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-12 text-right">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-search mr-2"></i>Apply Filters
                                    </button>
                                    <a href="mpesa_logs.php?invoice_id=<?php echo $invoice_id; ?>" class="btn btn-secondary">
                                        <i class="fas fa-undo mr-2"></i>Reset
                                    </a>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Transactions Table -->
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-list mr-2"></i>Transaction Logs
                            <span class="badge badge-info ml-2"><?php echo $total_count; ?> records</span>
                        </h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-tool" onclick="exportToCSV()" title="Export CSV">
                                <i class="fas fa-download"></i>
                            </button>
                            <button type="button" class="btn btn-tool" onclick="refreshData()" title="Refresh">
                                <i class="fas fa-sync-alt"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="card-body p-0">
                        <?php if (empty($transactions)): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                <h4>No M-Pesa transactions found</h4>
                                <p class="text-muted">No M-Pesa payments have been initiated for this invoice.</p>
                                <a href="process_payment.php?invoice_id=<?php echo $invoice_id; ?>" class="btn btn-primary">
                                    <i class="fas fa-mobile-alt mr-2"></i>Initiate M-Pesa Payment
                                </a>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover table-striped">
                                    <thead class="bg-light">
                                        <tr>
                                            <th width="50">#</th>
                                            <th>Checkout ID</th>
                                            <th>Phone</th>
                                            <th class="text-right">Amount</th>
                                            <th>Status</th>
                                            <th>M-Pesa Receipt</th>
                                            <th>Initiated By</th>
                                            <th>Date & Time</th>
                                            <th class="text-center">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($transactions as $index => $tx): ?>
                                            <?php 
                                            $serial = $offset + $index + 1;
                                            $status = $tx['display_status'];
                                            ?>
                                            <tr>
                                                <td><?php echo $serial; ?></td>
                                                <td>
                                                    <small class="text-muted d-block"><?php echo substr($tx['checkout_request_id'], 0, 20) . '...'; ?></small>
                                                    <small class="text-info"><i class="fas fa-fingerprint"></i> <?php echo substr($tx['checkout_request_id'], -8); ?></small>
                                                </td>
                                                <td>
                                                    <div class="font-weight-bold"><?php echo $tx['formatted_phone']; ?></div>
                                                    <?php if ($tx['callback_formatted_phone'] && $tx['callback_formatted_phone'] != $tx['formatted_phone']): ?>
                                                        <small class="text-info">Paid: <?php echo $tx['callback_formatted_phone']; ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-right">
                                                    <span class="font-weight-bold">KSH <?php echo number_format($tx['amount'], 2); ?></span>
                                                    <?php if ($tx['callback_amount'] && $tx['callback_amount'] != $tx['amount']): ?>
                                                        <br><small class="text-success">Paid: KSH <?php echo number_format($tx['callback_amount'], 2); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php echo formatStatusBadge($status); ?>
                                                    <br>
                                                    <small class="text-muted" data-toggle="tooltip" title="<?php echo htmlspecialchars($status['details']); ?>">
                                                        <i class="fas fa-info-circle"></i> <?php echo substr($status['details'], 0, 30); ?>...
                                                    </small>
                                                    <?php if ($tx['result_desc'] && $tx['result_code'] != 0): ?>
                                                        <br><small class="text-danger"><?php echo substr($tx['result_desc'], 0, 30); ?>...</small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($tx['mpesa_receipt']): ?>
                                                        <span class="badge badge-success">
                                                            <i class="fas fa-receipt mr-1"></i> <?php echo $tx['mpesa_receipt']; ?>
                                                        </span>
                                                        <?php if ($tx['transaction_date']): ?>
                                                            <br><small class="text-muted"><?php echo date('M j, Y', strtotime($tx['transaction_date'])); ?></small>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($tx['initiated_by']): ?>
                                                        <span class="font-weight-bold"><?php echo htmlspecialchars($tx['initiated_by']); ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted">System</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="small">
                                                        <span class="font-weight-bold"><?php echo date('M j, Y', strtotime($tx['created_at'])); ?></span><br>
                                                        <span class="text-muted"><?php echo date('H:i:s', strtotime($tx['created_at'])); ?></span>
                                                        <?php if ($tx['callback_time']): ?>
                                                            <br><small class="text-info">Callback: <?php echo date('H:i', strtotime($tx['callback_time'])); ?></small>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td class="text-center">
                                                    <div class="btn-group btn-group-sm">
                                                        <button type="button" class="btn btn-info btn-sm" 
                                                                onclick="viewTransactionDetails('<?php echo $tx['checkout_request_id']; ?>')"
                                                                data-toggle="tooltip" title="View Details">
                                                            <i class="fas fa-eye"></i>
                                                        </button>
                                                        <button type="button" class="btn btn-warning btn-sm" 
                                                                onclick="checkTransactionStatus('<?php echo $tx['checkout_request_id']; ?>')"
                                                                data-toggle="tooltip" title="Check Status">
                                                            <i class="fas fa-sync-alt"></i>
                                                        </button>
                                                        <?php if ($tx['payment_id']): ?>
                                                            <a href="payment_receipt.php?payment_id=<?php echo $tx['payment_id']; ?>" 
                                                               class="btn btn-success btn-sm" target="_blank"
                                                               data-toggle="tooltip" title="View Receipt">
                                                                <i class="fas fa-receipt"></i>
                                                            </a>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($total_pages > 1): ?>
                    <div class="card-footer clearfix">
                        <ul class="pagination pagination-sm m-0 float-right">
                            <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?invoice_id=<?php echo $invoice_id; ?>&page=<?php echo $page-1; ?>&status=<?php echo $filter_status; ?>&date_from=<?php echo $filter_date_from; ?>&date_to=<?php echo $filter_date_to; ?>&phone=<?php echo $filter_phone; ?>">
                                        &laquo; Previous
                                    </a>
                                </li>
                            <?php endif; ?>
                            
                            <?php
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);
                            
                            for ($i = $start_page; $i <= $end_page; $i++): ?>
                                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?invoice_id=<?php echo $invoice_id; ?>&page=<?php echo $i; ?>&status=<?php echo $filter_status; ?>&date_from=<?php echo $filter_date_from; ?>&date_to=<?php echo $filter_date_to; ?>&phone=<?php echo $filter_phone; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                            <?php endfor; ?>
                            
                            <?php if ($page < $total_pages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?invoice_id=<?php echo $invoice_id; ?>&page=<?php echo $page+1; ?>&status=<?php echo $filter_status; ?>&date_from=<?php echo $filter_date_from; ?>&date_to=<?php echo $filter_date_to; ?>&phone=<?php echo $filter_phone; ?>">
                                        Next &raquo;
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>
                        <div class="float-left mt-2">
                            <small class="text-muted">
                                Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $limit, $total_count); ?> 
                                of <?php echo $total_count; ?> entries
                            </small>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- API Query Logs -->
        <?php if (!empty($query_logs)): ?>
        <div class="row mt-4">
            <div class="col-md-12">
                <div class="card card-warning">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-server mr-2"></i>API Query Logs (Last 10)</h3>
                        <div class="card-tools">
                            <button type="button" class="btn btn-tool" data-card-widget="collapse">
                                <i class="fas fa-minus"></i>
                            </button>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead class="bg-warning">
                                    <tr>
                                        <th>Time</th>
                                        <th>Checkout ID</th>
                                        <th>HTTP Code</th>
                                        <th>Query Type</th>
                                        <th>Result Code</th>
                                        <th>Response</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($query_logs as $log): ?>
                                        <tr>
                                            <td>
                                                <small><?php echo date('H:i:s', strtotime($log['created_at'])); ?></small><br>
                                                <small class="text-muted"><?php echo date('m/d', strtotime($log['created_at'])); ?></small>
                                            </td>
                                            <td>
                                                <small class="text-monospace"><?php echo substr($log['checkout_request_id'], 0, 15) . '...'; ?></small>
                                            </td>
                                            <td>
                                                <span class="badge badge-<?php echo $log['http_code'] == 200 ? 'success' : 'danger'; ?>">
                                                    <?php echo $log['http_code']; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <small><?php echo htmlspecialchars($log['query_type'] ?? 'unknown'); ?></small>
                                            </td>
                                            <td>
                                                <?php if ($log['response_json'] && isset($log['response_json']['ResultCode'])): ?>
                                                    <span class="badge badge-<?php echo $log['response_json']['ResultCode'] == 0 ? 'success' : 'danger'; ?>">
                                                        <?php echo $log['response_json']['ResultCode']; ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($log['response_json'] && isset($log['response_json']['ResultDesc'])): ?>
                                                    <small><?php echo htmlspecialchars($log['response_json']['ResultDesc']); ?></small>
                                                <?php else: ?>
                                                    <small class="text-muted">No response</small>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Quick Actions -->
        <div class="row mt-4">
            <div class="col-md-12">
                <div class="card card-success">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-bolt mr-2"></i>Quick Actions</h3>
                    </div>
                    <div class="card-body">
                        <div class="d-flex flex-wrap gap-2">
                            <a href="process_payment.php?invoice_id=<?php echo $invoice_id; ?>" class="btn btn-primary">
                                <i class="fas fa-mobile-alt mr-2"></i>New M-Pesa Payment
                            </a>
                            <button type="button" class="btn btn-info" onclick="syncAllTransactions()">
                                <i class="fas fa-sync-alt mr-2"></i>Sync All Transactions
                            </button>
                            <button type="button" class="btn btn-warning" onclick="exportToCSV()">
                                <i class="fas fa-download mr-2"></i>Export CSV
                            </button>
                            <a href="payment_history.php?invoice_id=<?php echo $invoice_id; ?>" class="btn btn-secondary">
                                <i class="fas fa-history mr-2"></i>Payment History
                            </a>
                            <a href="billing_invoice_edit.php?invoice_id=<?php echo $invoice_id; ?>" class="btn btn-outline-warning">
                                <i class="fas fa-edit mr-2"></i>Edit Invoice
                            </a>
                            <a href="billing_invoices.php" class="btn btn-outline-secondary">
                                <i class="fas fa-arrow-left mr-2"></i>Back to Invoices
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Transaction Details Modal -->
<div class="modal fade" id="transactionModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary">
                <h5 class="modal-title text-white">
                    <i class="fas fa-info-circle mr-2"></i>Transaction Details
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div id="transactionDetailsContent">
                    <!-- Content loaded via AJAX -->
                    <div class="text-center py-4">
                        <i class="fas fa-spinner fa-spin fa-2x text-primary"></i>
                        <p class="mt-2">Loading transaction details...</p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="printTransactionDetails()">
                    <i class="fas fa-print mr-1"></i> Print
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Status Check Modal -->
<div class="modal fade" id="statusCheckModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title text-white">
                    <i class="fas fa-sync-alt mr-2"></i>Checking Transaction Status
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div id="statusCheckContent">
                    <div class="text-center py-3">
                        <i class="fas fa-spinner fa-spin fa-2x text-warning mb-3"></i>
                        <h5>Checking M-Pesa API...</h5>
                        <p>Please wait while we check the transaction status with Safaricom.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Initialize tooltips
    $('[data-toggle="tooltip"]').tooltip();
    
    // Auto-refresh if there are pending transactions
    const pendingCount = <?php echo $stats['pending_count']; ?>;
    if (pendingCount > 0) {
        console.log('Auto-refresh enabled: ' + pendingCount + ' pending transaction(s)');
        setTimeout(refreshPage, 30000); // Refresh every 30 seconds
    }
});

function viewTransactionDetails(checkoutId) {
    $('#transactionModal').modal('show');
    
    $.ajax({
        url: 'ajax/check_specific_mpesa.php',
        type: 'GET',
        data: { 
            checkout_id: checkoutId,
            csrf_token: '<?php echo $_SESSION['csrf_token']; ?>'
        },
        beforeSend: function() {
            $('#transactionDetailsContent').html(`
                <div class="text-center py-4">
                    <i class="fas fa-spinner fa-spin fa-2x text-primary"></i>
                    <p class="mt-2">Loading transaction details...</p>
                </div>
            `);
        },
        success: function(response) {
            if (response.success) {
                displayTransactionDetails(response);
            } else {
                $('#transactionDetailsContent').html(`
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle mr-2"></i>
                        <strong>Error:</strong> ${response.error || 'Failed to load details'}
                    </div>
                `);
            }
        },
        error: function() {
            $('#transactionDetailsContent').html(`
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle mr-2"></i>
                    <strong>Error:</strong> Network error occurred
                </div>
            `);
        }
    });
}

function displayTransactionDetails(data) {
    const tx = data.transaction;
    const callback = data.callback;
    const payment = data.payment_record;
    const apiCheck = data.api_check;
    
    let html = `
        <div class="row">
            <div class="col-md-6">
                <h6><i class="fas fa-id-card mr-2"></i>Transaction Information</h6>
                <table class="table table-sm table-bordered">
                    <tr>
                        <th width="40%">Checkout ID:</th>
                        <td><code>${tx.checkout_request_id}</code></td>
                    </tr>
                    <tr>
                        <th>Merchant ID:</th>
                        <td><code>${tx.merchant_request_id}</code></td>
                    </tr>
                    <tr>
                        <th>Amount:</th>
                        <td class="font-weight-bold text-success">KSH ${parseFloat(tx.amount).toFixed(2)}</td>
                    </tr>
                    <tr>
                        <th>Phone Number:</th>
                        <td>${tx.formatted_phone} (${tx.phone_number})</td>
                    </tr>
                    <tr>
                        <th>Status:</th>
                        <td>
                            <span class="badge badge-${tx.status === 'completed' ? 'success' : tx.status === 'pending' ? 'warning' : 'danger'}">
                                ${tx.status.charAt(0).toUpperCase() + tx.status.slice(1)}
                            </span>
                            ${tx.status_updated ? '<span class="badge badge-info ml-2">Updated</span>' : ''}
                        </td>
                    </tr>
                    <tr>
                        <th>Created:</th>
                        <td>${formatDateTime(tx.created_at)}</td>
                    </tr>
                    <tr>
                        <th>Last Updated:</th>
                        <td>${tx.updated_at ? formatDateTime(tx.updated_at) : 'Never'}</td>
                    </tr>
                </table>
            </div>
            
            <div class="col-md-6">
                <h6><i class="fas fa-receipt mr-2"></i>Payment Information</h6>
    `;
    
    if (callback) {
        html += `
            <table class="table table-sm table-bordered">
                <tr>
                    <th width="40%">M-Pesa Receipt:</th>
                    <td><span class="badge badge-success">${callback.mpesa_receipt || 'N/A'}</span></td>
                </tr>
                <tr>
                    <th>Result Code:</th>
                    <td>
                        <span class="badge badge-${callback.result_code == 0 ? 'success' : 'danger'}">
                            ${callback.result_code}
                        </span>
                    </td>
                </tr>
                <tr>
                    <th>Result Description:</th>
                    <td>${callback.result_desc}</td>
                </tr>
                <tr>
                    <th>Paid Amount:</th>
                    <td class="font-weight-bold text-primary">KSH ${parseFloat(callback.amount || 0).toFixed(2)}</td>
                </tr>
                <tr>
                    <th>Paid Phone:</th>
                    <td>${callback.phone_number ? formatPhoneDisplay(callback.phone_number) : 'N/A'}</td>
                </tr>
                <tr>
                    <th>Transaction Date:</th>
                    <td>${callback.transaction_date || 'N/A'}</td>
                </tr>
                <tr>
                    <th>Callback Time:</th>
                    <td>${formatDateTime(callback.callback_time)}</td>
                </tr>
            </table>
        `;
    } else {
        html += `
            <div class="alert alert-info">
                <i class="fas fa-info-circle mr-2"></i>
                No M-Pesa callback received yet. The payment is still processing.
            </div>
        `;
    }
    
    if (payment) {
        html += `
            <h6 class="mt-3"><i class="fas fa-file-invoice-dollar mr-2"></i>System Payment Record</h6>
            <table class="table table-sm table-bordered">
                <tr>
                    <th width="40%">Payment Number:</th>
                    <td><span class="badge badge-info">${payment.payment_number}</span></td>
                </tr>
                <tr>
                    <th>Payment Amount:</th>
                    <td class="font-weight-bold">KSH ${parseFloat(payment.payment_amount).toFixed(2)}</td>
                </tr>
                <tr>
                    <th>Payment Date:</th>
                    <td>${formatDateTime(payment.payment_date)}</td>
                </tr>
                <tr>
                    <th>Accounting Status:</th>
                    <td>
                        <span class="badge badge-${payment.accounting_status === 'posted' ? 'success' : payment.accounting_status === 'failed' ? 'danger' : 'warning'}">
                            ${payment.accounting_status}
                        </span>
                    </td>
                </tr>
            </table>
        `;
    }
    
    html += `
            </div>
        </div>
        
        <div class="row mt-3">
            <div class="col-md-12">
                <h6><i class="fas fa-server mr-2"></i>API Check Result</h6>
    `;
    
    if (apiCheck && apiCheck.success) {
        html += `
            <div class="alert alert-${apiCheck.result.ResultCode == 0 ? 'success' : 'warning'}">
                <div class="d-flex justify-content-between">
                    <div>
                        <i class="fas fa-${apiCheck.result.ResultCode == 0 ? 'check-circle' : 'exclamation-triangle'} mr-2"></i>
                        <strong>HTTP ${apiCheck.http_code}</strong> - ${apiCheck.result.ResultDesc}
                    </div>
                    <div>
                        <span class="badge badge-${apiCheck.result.ResultCode == 0 ? 'success' : 'danger'}">
                            Result Code: ${apiCheck.result.ResultCode}
                        </span>
                    </div>
                </div>
                ${apiCheck.result.CallbackMetadata ? `
                    <hr>
                    <div class="small">
                        <strong>Transaction Details:</strong>
                        <ul class="mb-0">
                ` : ''}
        `;
        
        if (apiCheck.result.CallbackMetadata) {
            apiCheck.result.CallbackMetadata.Item.forEach(item => {
                html += `<li><strong>${item.Name}:</strong> ${item.Value}</li>`;
            });
            html += `</ul></div>`;
        }
        
        html += `</div>`;
    } else if (apiCheck) {
        html += `
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle mr-2"></i>
                <strong>API Check Failed:</strong> ${apiCheck.error || 'Unknown error'}
            </div>
        `;
    } else {
        html += `
            <div class="alert alert-info">
                <i class="fas fa-info-circle mr-2"></i>
                No API check performed yet.
            </div>
        `;
    }
    
    html += `
            </div>
        </div>
        
        <div class="row mt-3">
            <div class="col-md-12 text-center">
                <button type="button" class="btn btn-warning btn-sm" 
                        onclick="checkTransactionStatus('${tx.checkout_request_id}')">
                    <i class="fas fa-sync-alt mr-1"></i> Check Status Again
                </button>
                ${payment ? `
                <a href="payment_receipt.php?payment_id=${tx.payment_id}" 
                   class="btn btn-success btn-sm ml-2" target="_blank">
                    <i class="fas fa-receipt mr-1"></i> View Receipt
                </a>
                ` : ''}
                <button type="button" class="btn btn-info btn-sm ml-2" 
                        onclick="resendCallback('${tx.checkout_request_id}')">
                    <i class="fas fa-paper-plane mr-1"></i> Resend Callback
                </button>
            </div>
        </div>
    `;
    
    $('#transactionDetailsContent').html(html);
}

function checkTransactionStatus(checkoutId) {
    $('#statusCheckModal').modal('show');
    
    $.ajax({
        url: 'ajax/check_specific_mpesa.php',
        type: 'GET',
        data: { 
            checkout_id: checkoutId,
            csrf_token: '<?php echo $_SESSION['csrf_token']; ?>'
        },
        success: function(response) {
            let statusHtml = '';
            
            if (response.success) {
                const tx = response.transaction;
                const apiCheck = response.api_check;
                
                if (apiCheck && apiCheck.success) {
                    if (apiCheck.result.ResultCode == 0) {
                        statusHtml = `
                            <div class="alert alert-success">
                                <h5><i class="fas fa-check-circle mr-2"></i>Payment Confirmed!</h5>
                                <p><strong>Receipt:</strong> ${apiCheck.result.CallbackMetadata?.Item?.find(i => i.Name === 'MpesaReceiptNumber')?.Value || 'N/A'}</p>
                                <p><strong>Amount:</strong> KSH ${apiCheck.result.CallbackMetadata?.Item?.find(i => i.Name === 'Amount')?.Value || tx.amount}</p>
                                <p><strong>Status:</strong> ${apiCheck.result.ResultDesc}</p>
                                <p class="small text-muted mb-0">The payment has been confirmed by M-Pesa.</p>
                            </div>
                        `;
                        
                        // Auto-refresh page after successful check
                        setTimeout(() => {
                            location.reload();
                        }, 2000);
                    } else {
                        statusHtml = `
                            <div class="alert alert-warning">
                                <h5><i class="fas fa-exclamation-triangle mr-2"></i>Payment ${apiCheck.result.ResultCode === '1037' ? 'Pending' : 'Failed'}</h5>
                                <p><strong>Code:</strong> ${apiCheck.result.ResultCode}</p>
                                <p><strong>Description:</strong> ${apiCheck.result.ResultDesc}</p>
                                <p class="small text-muted mb-0">${apiCheck.result.ResultCode === '1037' ? 'Customer has not responded to the prompt. Please ask them to check their phone.' : 'The payment was not successful.'}</p>
                            </div>
                        `;
                    }
                } else {
                    statusHtml = `
                        <div class="alert alert-danger">
                            <h5><i class="fas fa-exclamation-circle mr-2"></i>API Check Failed</h5>
                            <p>${apiCheck?.error || 'Unable to check status with M-Pesa'}</p>
                            <p class="small text-muted mb-0">Please try again or check your M-Pesa configuration.</p>
                        </div>
                    `;
                }
            } else {
                statusHtml = `
                    <div class="alert alert-danger">
                        <h5><i class="fas fa-exclamation-circle mr-2"></i>Error</h5>
                        <p>${response.error || 'Failed to check transaction status'}</p>
                    </div>
                `;
            }
            
            $('#statusCheckContent').html(statusHtml);
            
            // Auto-close modal after 3 seconds
            setTimeout(() => {
                $('#statusCheckModal').modal('hide');
            }, 3000);
        },
        error: function() {
            $('#statusCheckContent').html(`
                <div class="alert alert-danger">
                    <h5><i class="fas fa-exclamation-circle mr-2"></i>Network Error</h5>
                    <p>Failed to connect to server. Please check your internet connection.</p>
                </div>
            `);
        }
    });
}

function syncAllTransactions() {
    if (!confirm('This will check the status of all pending transactions with M-Pesa API. Continue?')) {
        return;
    }
    
    $.ajax({
        url: 'ajax/check_mpesa_status.php',
        type: 'GET',
        data: { 
            invoice_id: <?php echo $invoice_id; ?>,
            csrf_token: '<?php echo $_SESSION['csrf_token']; ?>'
        },
        beforeSend: function() {
            showToast('Syncing transactions...', 'info');
        },
        success: function(response) {
            if (response.success) {
                const synced = response.api_check ? 1 : 0;
                showToast(`Synced ${synced} transaction(s)`, 'success');
                
                // Reload page after sync
                setTimeout(() => {
                    location.reload();
                }, 1000);
            } else {
                showToast('Sync failed: ' + (response.error || 'Unknown error'), 'error');
            }
        },
        error: function() {
            showToast('Network error during sync', 'error');
        }
    });
}

function exportToCSV() {
    // Collect all filter parameters
    const params = new URLSearchParams({
        invoice_id: <?php echo $invoice_id; ?>,
        export: 'csv',
        status: '<?php echo $filter_status; ?>',
        date_from: '<?php echo $filter_date_from; ?>',
        date_to: '<?php echo $filter_date_to; ?>',
        phone: '<?php echo $filter_phone; ?>',
        csrf_token: '<?php echo $_SESSION['csrf_token']; ?>'
    });
    
    window.location.href = 'ajax/export_mpesa_logs.php?' + params.toString();
}

function refreshData() {
    location.reload();
}

function refreshPage() {
    // Only refresh if there are pending transactions
    const pendingCount = <?php echo $stats['pending_count']; ?>;
    if (pendingCount > 0) {
        console.log('Auto-refreshing page...');
        location.reload();
    }
}

function resendCallback(checkoutId) {
    if (!confirm('Resend callback simulation for testing? This will not actually send money.')) {
        return;
    }
    
    $.ajax({
        url: 'ajax/simulate_callback.php',
        type: 'POST',
        data: { 
            checkout_id: checkoutId,
            csrf_token: '<?php echo $_SESSION['csrf_token']; ?>'
        },
        beforeSend: function() {
            showToast('Simulating callback...', 'info');
        },
        success: function(response) {
            showToast(response.message || 'Callback simulated', response.success ? 'success' : 'error');
            if (response.success) {
                setTimeout(() => {
                    location.reload();
                }, 1500);
            }
        }
    });
}

function printTransactionDetails() {
    const printContent = $('#transactionDetailsContent').html();
    const originalContent = $('body').html();
    
    $('body').html(`
        <div class="container mt-4">
            <h3>M-Pesa Transaction Details</h3>
            <p>Invoice: <?php echo htmlspecialchars($invoice['invoice_number']); ?></p>
            <p>Printed: ${new Date().toLocaleString()}</p>
            <hr>
            ${printContent}
        </div>
    `);
    
    window.print();
    $('body').html(originalContent);
    location.reload();
}

function showToast(message, type = 'info') {
    // Simple toast notification
    const toast = $(`
        <div class="toast" role="alert" style="position: fixed; top: 20px; right: 20px; z-index: 9999;">
            <div class="toast-header bg-${type} text-white">
                <strong class="mr-auto">Notification</strong>
                <button type="button" class="ml-2 mb-1 close text-white" data-dismiss="toast">
                    <span>&times;</span>
                </button>
            </div>
            <div class="toast-body">
                ${message}
            </div>
        </div>
    `);
    
    $('body').append(toast);
    toast.toast({ delay: 3000 }).toast('show');
    
    setTimeout(() => {
        toast.remove();
    }, 3000);
}

function formatDateTime(datetime) {
    if (!datetime) return 'N/A';
    const date = new Date(datetime);
    return date.toLocaleDateString() + ' ' + date.toLocaleTimeString();
}

function formatPhoneDisplay(phone) {
    if (!phone) return 'N/A';
    phone = phone.toString().replace(/\D/g, '');
    if (phone.length === 12 && phone.startsWith('254')) {
        return '0' + phone.substring(3);
    }
    return phone;
}
</script>

<style>
.info-box {
    min-height: 80px;
    margin-bottom: 0;
}
.info-box-icon {
    border-radius: 0.25rem 0 0 0.25rem;
}
.table-hover tbody tr:hover {
    background-color: rgba(0,0,0,.03);
}
.badge {
    font-size: 85%;
}
.toast {
    min-width: 250px;
}
</style>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>