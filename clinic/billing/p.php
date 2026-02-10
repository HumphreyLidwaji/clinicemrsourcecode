<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Get current page name for redirects
$current_page = basename($_SERVER['PHP_SELF']);

// ========================
// MPESA CONFIGURATION
// ========================

// Include M-Pesa configuration
$mpesa_config = [
    'env' => 'sandbox', // 'sandbox' or 'live'
    'business_shortcode' => '174379', // Lipa Na M-Pesa Online Shortcode
    'consumer_key' => 'r4usqZGBKmbSEsCAsRjjzRcIj7saeItlWDkwAjaVpNoGfnZQ',
    'consumer_secret' => 'N6A82GiRGk3kvnjJAT0hnoOLkMY8CNredYTtG4VQj5NGRJsrqJ0TnKxqbSIWA12h',
    'passkey' => 'SbNICbFs28Nex9CYPBpbZKsO/jjG9u2FLJwp26gt45SXhERRZaM7KO7OVHdojBzkOEMjrzehOtM4TclFrAi5movjI37J37yaSO1nQqswuqfGshg3LhQwM2cF26vhQbSJsnwDqTXOmLDA6xLht0hOllPkVi+IbreT4fthiiazKUMrw1+W59KTprGW0cOnBx8oa6yseJI4QAWJwsuGtOAKA/pc43xx7d1T2PJ6OSObeEZ1SHbgZ7imMSYCk+eNvC1guty0vuwq9YtWkuZMssU1gDb2Yw5uhrgDBcdHwn5x0nDmf4bZIh1LPFV661SlCh3EZl33E25Tr9u1SQrJzljEqQ==',
    'callback_url' => 'https://yourdomain.com/mpesa/callback.php',
    'transaction_type' => 'CustomerPayBillOnline',
    'account_reference' => 'MEDICAL_BILL',
    'transaction_desc' => 'Payment for medical services'
];

// ========================
// MPESA FUNCTIONS
// ========================

function formatPhoneForMpesa($phone) {
    $phone = preg_replace('/\D/', '', $phone);
    
    if (substr($phone, 0, 1) === '0') {
        $phone = '254' . substr($phone, 1);
    } elseif (substr($phone, 0, 1) === '7') {
        $phone = '254' . $phone;
    } elseif (strlen($phone) === 9) {
        $phone = '254' . $phone;
    }
    
    return $phone;
}

function initiateMpesaPayment($mysqli, $phone, $amount, $invoice_id, $account_reference, $description) {
    global $mpesa_config;
    
    // Get M-Pesa access token
    $endpoint = ($mpesa_config['env'] == 'live') ? 
                'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials' :
                'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';
    
    $credentials = base64_encode($mpesa_config['consumer_key'] . ':' . $mpesa_config['consumer_secret']);
    
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ['Authorization: Basic ' . $credentials],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 30
    ]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    $result = json_decode($response);
    $access_token = $result->access_token ?? null;
    
    if (!$access_token) {
        return ['success' => false, 'error' => 'Failed to get M-Pesa access token'];
    }
    
    // Prepare STK Push request
    $timestamp = date('YmdHis');
    $password = base64_encode($mpesa_config['business_shortcode'] . $mpesa_config['passkey'] . $timestamp);
    
    $stk_data = [
        'BusinessShortCode' => $mpesa_config['business_shortcode'],
        'Password' => $password,
        'Timestamp' => $timestamp,
        'TransactionType' => $mpesa_config['transaction_type'],
        'Amount' => $amount,
        'PartyA' => $phone,
        'PartyB' => $mpesa_config['business_shortcode'],
        'PhoneNumber' => $phone,
        'CallBackURL' => $mpesa_config['callback_url'],
        'AccountReference' => $account_reference,
        'TransactionDesc' => $description
    ];
    
    // Send STK Push request
    $endpoint = ($mpesa_config['env'] == 'live') ? 
                'https://api.safaricom.co.ke/mpesa/stkpush/v1/processrequest' :
                'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest';
    
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $access_token,
            'Content-Type: application/json'
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($stk_data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 30
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $result = json_decode($response, true);
    
    // Log the request
    $log_sql = "INSERT INTO mpesa_logs SET 
               invoice_id = ?,
               phone_number = ?,
               amount = ?,
               request_data = ?,
               response_data = ?,
               http_code = ?,
               created_at = NOW()";
    $log_stmt = mysqli_prepare($mysqli, $log_sql);
    mysqli_stmt_bind_param($log_stmt, 'isdssi', 
        $invoice_id, $phone, $amount, json_encode($stk_data), $response, $httpCode
    );
    mysqli_stmt_execute($log_stmt);
    $log_id = mysqli_insert_id($mysqli);
    mysqli_stmt_close($log_stmt);
    
    if (isset($result['ResponseCode']) && $result['ResponseCode'] == "0") {
        // Save pending M-Pesa transaction
        $pending_sql = "INSERT INTO mpesa_pending_transactions SET 
                       log_id = ?,
                       invoice_id = ?,
                       merchant_request_id = ?,
                       checkout_request_id = ?,
                       amount = ?,
                       phone_number = ?,
                       status = 'pending',
                       created_at = NOW()";
        $pending_stmt = mysqli_prepare($mysqli, $pending_sql);
        $merchant_id = $result['MerchantRequestID'];
        $checkout_id = $result['CheckoutRequestID'];
        mysqli_stmt_bind_param($pending_stmt, 'iissds', 
            $log_id, $invoice_id, $merchant_id, $checkout_id, $amount, $phone
        );
        mysqli_stmt_execute($pending_stmt);
        mysqli_stmt_close($pending_stmt);
        
        return [
            'success' => true,
            'checkout_request_id' => $checkout_id,
            'merchant_request_id' => $merchant_id,
            'message' => 'M-Pesa payment initiated successfully'
        ];
    } else {
        return [
            'success' => false,
            'error' => $result['errorMessage'] ?? 'Failed to initiate M-Pesa payment',
            'response' => $result
        ];
    }
}

function checkMpesaStatus($mysqli, $checkout_request_id) {
    global $mpesa_config;
    
    // Get access token
    $endpoint = ($mpesa_config['env'] == 'live') ? 
                'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials' :
                'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';
    
    $credentials = base64_encode($mpesa_config['consumer_key'] . ':' . $mpesa_config['consumer_secret']);
    
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ['Authorization: Basic ' . $credentials],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    $result = json_decode($response);
    $access_token = $result->access_token ?? null;
    
    if (!$access_token) {
        return ['success' => false, 'error' => 'Failed to get access token'];
    }
    
    // Prepare query request
    $timestamp = date('YmdHis');
    $password = base64_encode($mpesa_config['business_shortcode'] . $mpesa_config['passkey'] . $timestamp);
    
    $query_data = [
        'BusinessShortCode' => $mpesa_config['business_shortcode'],
        'Password' => $password,
        'Timestamp' => $timestamp,
        'CheckoutRequestID' => $checkout_request_id
    ];
    
    // Send query request
    $endpoint = ($mpesa_config['env'] == 'live') ? 
                'https://api.safaricom.co.ke/mpesa/stkpushquery/v1/query' :
                'https://sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query';
    
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $access_token,
            'Content-Type: application/json'
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($query_data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $result = json_decode($response, true);
    
    return [
        'success' => true,
        'result' => $result
    ];
}

// ========================
// ACCOUNTING CONFIGURATION
// ========================

// Default account mappings (fallback if service_accounts table doesn't have mapping)
$revenue_accounts = [
    'pharmacy' => 19,      // Product Sales (4010)
    'laboratory' => 20,    // Service Revenue (4020)
    'imaging' => 20,       // Service Revenue (4020)
    'radiology' => 20,     // Service Revenue (4020)
    'services' => 20,      // Service Revenue (4020)
    'procedure' => 20,     // Service Revenue (4020)
    'admission' => 20,     // Service Revenue (4020)
    'opd' => 20,           // Service Revenue (4020)
    'theatre' => 20,       // Service Revenue (4020)
    'surgery' => 20,       // Service Revenue (4020)
    'consultation' => 20,  // Service Revenue (4020)
    'ward' => 20,          // Service Revenue (4020)
    'nursing' => 20,       // Service Revenue (4020)
    'physiotherapy' => 20, // Service Revenue (4020)
    'dental' => 20,        // Service Revenue (4020)
];

// Map payment methods to debit accounts
$payment_accounts = [
    'cash' => 1,           // Cash (1010)
    'mpesa_stk' => 1,      // Cash (1010)
    'card' => 1,           // Cash (1010)
    'insurance' => 3,      // Accounts Receivable (1100)
    'check' => 1,          // Cash (1010)
    'bank_transfer' => 1,  // Cash (1010)
    'credit' => 3,         // Accounts Receivable (1100)
    'nhif' => 3,           // Accounts Receivable (1100)
];

// ========================
// PAYMENT PROCESSING
// ========================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    // Handle M-Pesa initiation
    if (isset($_POST['initiate_mpesa'])) {
        // Validate CSRF
        if (!isset($_SESSION['csrf_token']) || $csrf_token !== $_SESSION['csrf_token']) {
            flash_alert("Invalid CSRF token", 'error');
            header("Location: $current_page?invoice_id=" . ($_POST['invoice_id'] ?? 0));
            exit;
        }
        
        $invoice_id = intval($_POST['invoice_id'] ?? 0);
        $mpesa_phone = sanitizeInput($_POST['mpesa_phone'] ?? '');
        $mpesa_amount = floatval($_POST['mpesa_amount'] ?? 0);
        
        // Validate phone number
        $formatted_phone = formatPhoneForMpesa($mpesa_phone);
        if (!$formatted_phone || strlen($formatted_phone) !== 12) {
            flash_alert("Invalid phone number format. Use format: 07XXXXXXXX or 254XXXXXXXXX", 'error');
            header("Location: $current_page?invoice_id=$invoice_id");
            exit;
        }
        
        // Get invoice details
        $invoice_sql = "SELECT i.*, CONCAT(p.patient_first_name, ' ', p.patient_last_name) as patient_name
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
            header("Location: $current_page?invoice_id=$invoice_id");
            exit;
        }
        
        // Validate amount
        $remaining_balance = $invoice['invoice_amount'] - ($invoice['paid_amount'] ?? 0);
        if ($mpesa_amount <= 0 || $mpesa_amount > $remaining_balance) {
            flash_alert("Invalid amount. Must be greater than 0 and not exceed remaining balance of KSH " . number_format($remaining_balance, 2), 'error');
            header("Location: $current_page?invoice_id=$invoice_id");
            exit;
        }
        
        // Initiate M-Pesa payment
        $mpesa_result = initiateMpesaPayment(
            $mysqli,
            $formatted_phone,
            $mpesa_amount,
            $invoice_id,
            $invoice['invoice_number'],
            "Medical bill payment - " . $invoice['patient_name']
        );
        
        if ($mpesa_result['success']) {
            // Store M-Pesa session data
            $_SESSION['mpesa_checkout_id'] = $mpesa_result['checkout_request_id'];
            $_SESSION['mpesa_invoice_id'] = $invoice_id;
            $_SESSION['mpesa_amount'] = $mpesa_amount;
            $_SESSION['mpesa_phone'] = $formatted_phone;
            
            // Redirect to payment status page
            flash_alert("✅ M-Pesa payment initiated! Check your phone for payment prompt.", 'success');
            header("Location: payment_status.php?checkout=" . $mpesa_result['checkout_request_id']);
            exit;
        } else {
            flash_alert("❌ Failed to initiate M-Pesa payment: " . ($mpesa_result['error'] ?? 'Unknown error'), 'error');
            header("Location: $current_page?invoice_id=$invoice_id");
            exit;
        }
    }
    
    if (isset($_POST['process_payment'])) {
        // ... [Keep existing process_payment logic, but add M-Pesa completion handling]
        
        // After successful payment processing, if it was M-Pesa:
        if ($payment_method === 'mpesa_stk' && !empty($mpesa_transaction_id)) {
            // Update M-Pesa pending transaction status
            $update_mpesa_sql = "UPDATE mpesa_pending_transactions SET 
                                status = 'completed',
                                payment_id = ?,
                                updated_at = NOW()
                                WHERE checkout_request_id IN (
                                    SELECT checkout_request_id FROM mpesa_logs 
                                    WHERE reference = ? 
                                    ORDER BY created_at DESC LIMIT 1
                                )";
            $update_stmt = mysqli_prepare($mysqli, $update_mpesa_sql);
            mysqli_stmt_bind_param($update_stmt, 'is', $payment_id, $mpesa_transaction_id);
            mysqli_stmt_execute($update_stmt);
            mysqli_stmt_close($update_stmt);
        }
    }
}

// ========================
// DISPLAY LOGIC
// ========================

$invoice_id = intval($_GET['invoice_id'] ?? 0);

if (!$invoice_id) {
    flash_alert("No invoice specified", 'error');
    header("Location: billing_dashboard.php");
    exit;
}

// Get invoice details
$invoice_sql = "SELECT i.*, p.patient_id, CONCAT(p.patient_first_name, ' ', p.patient_last_name) as patient_name,
                       p.patient_phone, p.patient_email, p.patient_mrn, p.patient_dob,
                       i.invoice_amount - IFNULL(i.paid_amount, 0) as remaining_balance,
                       IFNULL(i.paid_amount, 0) as paid_amount,
                       (SELECT COUNT(*) FROM payments WHERE payment_invoice_id = i.invoice_id AND payment_status = 'completed') as payment_count,
                       v.visit_status, v.visit_discharge_date
                FROM invoices i
                LEFT JOIN patients p ON i.invoice_client_id = p.patient_id
                LEFT JOIN visits v ON i.visit_id = v.visit_id
                WHERE i.invoice_id = ?";
$invoice_stmt = mysqli_prepare($mysqli, $invoice_sql);
mysqli_stmt_bind_param($invoice_stmt, 'i', $invoice_id);
mysqli_stmt_execute($invoice_stmt);
$invoice_result = mysqli_stmt_get_result($invoice_stmt);
$invoice = mysqli_fetch_assoc($invoice_result);
mysqli_stmt_close($invoice_stmt);

if (!$invoice) {
    flash_alert("Invoice not found", 'error');
    header("Location: billing_dashboard.php");
    exit;
}

// ... [Keep rest of your existing display logic]
?>

<div class="card">
    <div class="card-header bg-success py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0">
                <i class="fas fa-fw fa-credit-card mr-2"></i>Process Payment
            </h3>
            <div class="card-tools">
                <a href="billing_invoices.php" class="btn btn-light">
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

        <?php if($invoice): ?>
        <div class="row">
            <div class="col-md-8">
                <!-- Invoice Information -->
                <div class="card card-primary">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-file-invoice mr-2"></i>Invoice Information</h3>
                    </div>
                    <div class="card-body">
                        <!-- ... [Keep existing invoice info display] -->
                    </div>
                </div>

                <!-- Payment Options Tabs -->
                <div class="card card-warning mt-3">
                    <div class="card-header">
                        <ul class="nav nav-tabs card-header-tabs">
                            <li class="nav-item">
                                <a class="nav-link active" data-toggle="tab" href="#mpesa_tab">
                                    <i class="fas fa-mobile-alt mr-1"></i> M-Pesa
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" data-toggle="tab" href="#manual_tab">
                                    <i class="fas fa-hand-holding-usd mr-1"></i> Manual Payment
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" data-toggle="tab" href="#other_tab">
                                    <i class="fas fa-credit-card mr-1"></i> Other Methods
                                </a>
                            </li>
                        </ul>
                    </div>
                    
                    <div class="card-body">
                        <div class="tab-content">
                            <!-- M-Pesa Tab -->
                            <div class="tab-pane fade show active" id="mpesa_tab">
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle mr-2"></i>
                                    <strong>How M-Pesa STK Push Works:</strong>
                                    <ol class="mb-0 mt-2">
                                        <li>Enter phone number and amount</li>
                                        <li>Click "Initiate M-Pesa Payment"</li>
                                        <li>Check your phone for payment prompt</li>
                                        <li>Enter your M-Pesa PIN to complete</li>
                                        <li>Payment will be automatically recorded</li>
                                    </ol>
                                </div>
                                
                                <form method="POST" id="mpesaForm" action="<?php echo $_SERVER['PHP_SELF']; ?>?invoice_id=<?php echo $invoice_id; ?>">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="invoice_id" value="<?php echo $invoice_id; ?>">
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label class="font-weight-bold">Phone Number <span class="text-danger">*</span></label>
                                                <div class="input-group">
                                                    <div class="input-group-prepend">
                                                        <span class="input-group-text">+254</span>
                                                    </div>
                                                    <input type="tel" class="form-control" name="mpesa_phone" 
                                                           id="mpesa_phone" placeholder="7XXXXXXXX" 
                                                           pattern="[0-9]{9}" required
                                                           value="<?php echo substr($invoice['patient_phone'], 3) ?? ''; ?>">
                                                </div>
                                                <small class="form-text text-muted">Enter 9 digits after 254 (e.g., 712345678)</small>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="form-group">
                                                <label class="font-weight-bold">Amount (KSH) <span class="text-danger">*</span></label>
                                                <input type="number" class="form-control" name="mpesa_amount" 
                                                       id="mpesa_amount" step="0.01" min="1" 
                                                       max="<?php echo $invoice['remaining_balance']; ?>"
                                                       value="<?php echo $invoice['remaining_balance']; ?>"
                                                       required>
                                                <small class="form-text text-muted">
                                                    Maximum: KSH <?php echo number_format($invoice['remaining_balance'], 2); ?>
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="font-weight-bold">Payment Description</label>
                                        <input type="text" class="form-control" 
                                               value="Medical Bill - <?php echo htmlspecialchars($invoice['invoice_number']); ?>"
                                               readonly>
                                    </div>
                                    
                                    <div class="alert alert-warning">
                                        <i class="fas fa-exclamation-triangle mr-2"></i>
                                        <strong>Important:</strong> 
                                        <ul class="mb-0 mt-2">
                                            <li>Ensure phone has sufficient M-Pesa balance</li>
                                            <li>Keep phone nearby to receive prompt</li>
                                            <li>Payment processing may take 1-2 minutes</li>
                                            <li>You will receive SMS confirmation</li>
                                        </ul>
                                    </div>
                                    
                                    <div class="text-center">
                                        <button type="submit" name="initiate_mpesa" class="btn btn-success btn-lg" id="initiateMpesaButton">
                                            <i class="fas fa-mobile-alt mr-2"></i>Initiate M-Pesa Payment
                                        </button>
                                        <button type="button" class="btn btn-outline-info btn-lg ml-2" onclick="checkMpesaBalance()">
                                            <i class="fas fa-balance-scale mr-2"></i>Check Balance
                                        </button>
                                    </div>
                                </form>
                            </div>
                            
                            <!-- Manual Payment Tab -->
                            <div class="tab-pane fade" id="manual_tab">
                                <!-- ... [Keep your existing manual payment form] -->
                            </div>
                            
                            <!-- Other Methods Tab -->
                            <div class="tab-pane fade" id="other_tab">
                                <!-- ... [Keep your existing other methods form] -->
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <!-- M-Pesa Status Card -->
                <div class="card card-info">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-mobile-alt mr-2"></i>M-Pesa Status</h3>
                    </div>
                    <div class="card-body">
                        <div id="mpesaStatusContainer">
                            <div class="text-center py-3">
                                <i class="fas fa-mobile-alt fa-3x text-muted mb-3"></i>
                                <h5>M-Pesa Payment Ready</h5>
                                <p class="text-muted small">Select M-Pesa tab to initiate payment</p>
                            </div>
                        </div>
                        
                        <div class="mt-3">
                            <button type="button" class="btn btn-outline-info btn-sm btn-block" onclick="refreshMpesaStatus()">
                                <i class="fas fa-sync-alt mr-1"></i> Refresh Status
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Quick Actions -->
                <div class="card card-success mt-3">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-bolt mr-2"></i>Quick Actions</h3>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="payment_receipt.php?invoice_id=<?php echo $invoice_id; ?>" class="btn btn-info">
                                <i class="fas fa-receipt mr-2"></i>View Receipts
                            </a>
                            <button type="button" class="btn btn-warning" onclick="testMpesaConnection()">
                                <i class="fas fa-wifi mr-2"></i>Test M-Pesa Connection
                            </button>
                            <a href="mpesa_logs.php?invoice_id=<?php echo $invoice_id; ?>" class="btn btn-outline-dark">
                                <i class="fas fa-history mr-2"></i>M-Pesa Logs
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- M-Pesa Modal -->
<div class="modal fade" id="mpesaModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header bg-success">
                <h5 class="modal-title text-white">
                    <i class="fas fa-mobile-alt mr-2"></i>M-Pesa Payment Status
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body text-center">
                <div id="mpesaModalContent">
                    <div class="mb-3">
                        <i class="fas fa-sync-alt fa-spin fa-3x text-primary"></i>
                    </div>
                    <h4>Processing Payment...</h4>
                    <p>Please wait while we process your M-Pesa payment.</p>
                    <div class="progress mb-3">
                        <div class="progress-bar progress-bar-striped progress-bar-animated" 
                             role="progressbar" style="width: 100%"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="checkPaymentStatus()">
                    <i class="fas fa-sync-alt mr-1"></i> Check Status
                </button>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Initialize M-Pesa form validation
    $('#mpesaForm').on('submit', function(e) {
        const phone = $('#mpesa_phone').val();
        const amount = parseFloat($('#mpesa_amount').val()) || 0;
        const maxAmount = <?php echo $invoice['remaining_balance']; ?>;
        
        if (!phone || phone.length !== 9) {
            alert('Please enter a valid 9-digit phone number (after 254)');
            e.preventDefault();
            return false;
        }
        
        if (amount <= 0 || amount > maxAmount) {
            alert('Amount must be between 1 and ' + maxAmount.toFixed(2));
            e.preventDefault();
            return false;
        }
        
        // Show processing modal
        $('#mpesaModal').modal('show');
        $('#initiateMpesaButton').html('<i class="fas fa-spinner fa-spin mr-2"></i>Processing...').prop('disabled', true);
        
        return true;
    });
    
    // Auto-format phone number
    $('#mpesa_phone').on('input', function(e) {
        let value = e.target.value.replace(/\D/g, '');
        if (value.length > 9) {
            value = value.substring(0, 9);
        }
        e.target.value = value;
    });
    
    // Check for pending M-Pesa payments
    checkPendingMpesa();
});

function checkPendingMpesa() {
    const invoiceId = <?php echo $invoice_id; ?>;
    
    $.ajax({
        url: 'ajax/check_mpesa_status.php',
        type: 'GET',
        data: { invoice_id: invoiceId },
        success: function(response) {
            if (response.success && response.pending) {
                updateMpesaStatus(response);
            }
        }
    });
}

function refreshMpesaStatus() {
    $('#mpesaStatusContainer').html(`
        <div class="text-center py-2">
            <i class="fas fa-sync-alt fa-spin"></i> Checking status...
        </div>
    `);
    
    setTimeout(() => {
        checkPendingMpesa();
    }, 1000);
}

function updateMpesaStatus(data) {
    let html = '';
    
    if (data.status === 'pending') {
        html = `
            <div class="alert alert-warning">
                <h6><i class="fas fa-clock mr-2"></i>Pending M-Pesa Payment</h6>
                <div class="small">
                    <p><strong>Amount:</strong> KSH ${data.amount}</p>
                    <p><strong>Phone:</strong> ${data.phone}</p>
                    <p><strong>Initiated:</strong> ${data.time}</p>
                </div>
                <button class="btn btn-sm btn-outline-warning btn-block mt-2" 
                        onclick="checkSpecificPayment('${data.checkout_id}')">
                    Check Status
                </button>
            </div>
        `;
    } else if (data.status === 'completed') {
        html = `
            <div class="alert alert-success">
                <h6><i class="fas fa-check-circle mr-2"></i>M-Pesa Payment Completed</h6>
                <div class="small">
                    <p><strong>Receipt:</strong> ${data.receipt}</p>
                    <p><strong>Amount:</strong> KSH ${data.amount}</p>
                    <p><strong>Completed:</strong> ${data.time}</p>
                </div>
            </div>
        `;
    }
    
    $('#mpesaStatusContainer').html(html);
}

function checkSpecificPayment(checkoutId) {
    $.ajax({
        url: 'ajax/check_specific_mpesa.php',
        type: 'GET',
        data: { checkout_id: checkoutId },
        success: function(response) {
            if (response.success) {
                updateMpesaStatus(response.data);
            }
        }
    });
}

function testMpesaConnection() {
    $.ajax({
        url: 'ajax/test_mpesa.php',
        type: 'GET',
        beforeSend: function() {
            $('#mpesaStatusContainer').html(`
                <div class="text-center py-2">
                    <i class="fas fa-sync-alt fa-spin"></i> Testing connection...
                </div>
            `);
        },
        success: function(response) {
            if (response.success) {
                $('#mpesaStatusContainer').html(`
                    <div class="alert alert-success">
                        <h6><i class="fas fa-check-circle mr-2"></i>Connection Successful</h6>
                        <p class="mb-0 small">M-Pesa API is working correctly.</p>
                    </div>
                `);
            } else {
                $('#mpesaStatusContainer').html(`
                    <div class="alert alert-danger">
                        <h6><i class="fas fa-exclamation-circle mr-2"></i>Connection Failed</h6>
                        <p class="mb-0 small">${response.error || 'Unable to connect to M-Pesa'}</p>
                    </div>
                `);
            }
        },
        error: function() {
            $('#mpesaStatusContainer').html(`
                <div class="alert alert-danger">
                    <h6><i class="fas fa-exclamation-circle mr-2"></i>Connection Error</h6>
                    <p class="mb-0 small">Network error occurred.</p>
                </div>
            `);
        }
    });
}

function checkMpesaBalance() {
    const phone = $('#mpesa_phone').val();
    if (!phone || phone.length !== 9) {
        alert('Please enter a valid phone number first');
        return;
    }
    
    // This is a placeholder - in production, you would use USSD simulation or another method
    alert('To check M-Pesa balance:\n1. Dial *144#\n2. Select "My Account"\n3. Check balance\n\nEnsure you have sufficient funds for the payment.');
}
</script>

<?php


require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>