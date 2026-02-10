<?php
// post/billing_handlers/PaymentHandler.php
require_once $_SERVER['DOCUMENT_ROOT'] . '/services/AccountingService.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/services/AccountingTransactionBuilder.php';

class PaymentHandler {
    private $mysqli;
    private $user_id;
    private $accountingService;
    private $accountingBuilder;
    
    public function __construct($mysqli, $user_id) {
        $this->mysqli = $mysqli;
        $this->user_id = $user_id;
        $this->accountingService = new AccountingService($mysqli, $user_id);
        $this->accountingBuilder = new AccountingTransactionBuilder($this->accountingService);
    }
    
    public function execute($action, $data) {
        switch ($action) {
            case 'process_payment':
                $this->processPayment($data);
                break;
            case 'process_partial_insurance':
                $this->processPartialInsurance($data);
                break;
            default:
                throw new Exception("Invalid payment action: $action");
        }
    }
    
    private function processPayment($data) {
        // Validate required fields
        $required = ['amount', 'payment_method', 'payment_date'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new Exception("Missing required field: $field");
            }
        }
        
        $amount = floatval($data['amount']);
        $payment_method = sanitizeInput($data['payment_method']);
        $payment_date = sanitizeInput($data['payment_date']);
        $invoice_id = intval($data['invoice_id'] ?? 0);
        $patient_id = intval($data['patient_id'] ?? 0);
        $visit_id = intval($data['visit_id'] ?? 0);
        $register_id = intval($data['register_id'] ?? 0);
        $notes = sanitizeInput($data['notes'] ?? '');
        $selected_items = $data['selected_items'] ?? '';
        
        // Validate cash register
        if (!$register_id || !$this->isRegisterOpen($register_id)) {
            throw new Exception('Cash register is not open or invalid');
        }
        
        // Validate invoice if provided
        if ($invoice_id) {
            $invoice = $this->getInvoice($invoice_id);
            if (!$invoice) {
                throw new Exception('Invoice not found');
            }
            
            $balance_due = $invoice['invoice_amount'] - $invoice['paid_amount'];
            if ($amount > $balance_due) {
                throw new Exception("Payment amount ($" . number_format($amount, 2) . ") exceeds invoice balance ($" . number_format($balance_due, 2) . ")");
            }
            
            $patient_id = $invoice['invoice_client_id'];
            $visit_id = $invoice['visit_id'];
        }
        
        // Validate patient for direct payments
        if (!$invoice_id && !$patient_id) {
            throw new Exception('Patient is required for direct payments');
        }
        
        $this->mysqli->begin_transaction();
        
        try {
            // Generate payment number
            $payment_number = $this->generatePaymentNumber();
            
            // Insert payment record
            $payment_sql = "INSERT INTO payments 
                           (payment_number, payment_invoice_id, register_id, payment_amount, 
                            payment_method, transaction_type, payment_date, payment_status, 
                            payment_received_by, notes, created_at) 
                           VALUES (?, ?, ?, ?, ?, 'payment', ?, 'completed', ?, ?, NOW())";
            
            $stmt = $this->mysqli->prepare($payment_sql);
            $stmt->bind_param("siidssis", 
                $payment_number,
                $invoice_id,
                $register_id,
                $amount,
                $payment_method,
                $payment_date,
                $this->user_id,
                $notes
            );
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to create payment: " . $stmt->error);
            }
            
            $payment_id = $this->mysqli->insert_id;
            $stmt->close();
            
            // Update invoice if applicable
            if ($invoice_id) {
                $this->updateInvoicePayment($invoice_id, $amount);
                
                // Update individual invoice items if specific items were selected
                if (!empty($selected_items)) {
                    $this->updateInvoiceItemsPayment($selected_items, $amount, $payment_id);
                }
                
                // Create co-pay invoice if this is an insurance payment and there's remaining balance
                if ($payment_method === 'insurance') {
                    $remaining_balance = $invoice['invoice_amount'] - ($invoice['paid_amount'] + $amount);
                    if ($remaining_balance > 0) {
                        $this->createCoPayInvoice($invoice_id, $remaining_balance, $payment_id);
                    }
                }
            }
            
            // Add method-specific details
            $this->addPaymentDetails($payment_id, $payment_method, $data);
            
            // Log the transaction
            $this->logCashRegisterTransaction($register_id, $payment_id, $amount, $payment_method, 'payment');
            
            // Record accounting entry
            $accounting_result = $this->accountingBuilder
                ->setDate(date('Y-m-d'))
                ->setReference($payment_number)
                ->setDescription("Payment received" . ($invoice_id ? " for invoice " . $invoice['invoice_number'] : " from patient"))
                ->setModule('billing')
                ->setSourceDocument('payments', $payment_id)
                ->useTemplate('payment_received', [
                    'amount' => $amount,
                    'payment_method' => $payment_method,
                    'invoice_id' => $invoice_id,
                    'patient_id' => $patient_id
                ])
                ->execute();
            
            if (!$accounting_result['success']) {
                throw new Exception("Accounting entry failed: " . $accounting_result['error']);
            }
            
            $this->mysqli->commit();
            
            // Success response
            $_SESSION['alert_type'] = 'success';
            $_SESSION['alert_message'] = "Payment of $" . number_format($amount, 2) . " processed successfully";
            
            // Redirect to receipt
            header("Location: billing_payment_receipt.php?id=" . $payment_id);
            exit;
            
        } catch (Exception $e) {
            $this->mysqli->rollback();
            throw $e;
        }
    }
    
    private function processPartialInsurance($data) {
        $invoice_id = intval($data['invoice_id'] ?? 0);
        $amount = floatval($data['amount'] ?? 0);
        $insurance_provider = sanitizeInput($data['insurance_provider'] ?? '');
        $claim_number = sanitizeInput($data['claim_number'] ?? '');
        $approval_code = sanitizeInput($data['approval_code'] ?? '');
        $register_id = intval($data['register_id'] ?? 0);
        $notes = sanitizeInput($data['notes'] ?? '');
        
        // Validate inputs
        if (!$invoice_id || $amount <= 0) {
            throw new Exception('Invalid invoice or amount');
        }
        
        if (empty($insurance_provider)) {
            throw new Exception('Insurance provider is required');
        }
        
        // Validate register
        if (!$register_id || !$this->isRegisterOpen($register_id)) {
            throw new Exception('Cash register is not open');
        }
        
        $invoice = $this->getInvoice($invoice_id);
        if (!$invoice) {
            throw new Exception('Invoice not found');
        }
        
        $balance_due = $invoice['invoice_amount'] - $invoice['paid_amount'];
        if ($amount > $balance_due) {
            throw new Exception("Payment amount exceeds invoice balance");
        }
        
        $this->mysqli->begin_transaction();
        
        try {
            // Generate payment number
            $payment_number = $this->generatePaymentNumber();
            $payment_date = date('Y-m-d H:i:s');
            
            // Insert insurance payment record
            $payment_sql = "INSERT INTO payments 
                           (payment_number, payment_invoice_id, register_id, payment_amount, 
                            payment_method, transaction_type, payment_date, payment_status, 
                            payment_received_by, notes, created_at) 
                           VALUES (?, ?, ?, ?, 'insurance', 'payment', ?, 'completed', ?, ?, NOW())";
            
            $stmt = $this->mysqli->prepare($payment_sql);
            $stmt->bind_param("siidssis", 
                $payment_number,
                $invoice_id,
                $register_id,
                $amount,
                $payment_date,
                $this->user_id,
                $notes
            );
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to create insurance payment: " . $stmt->error);
            }
            
            $payment_id = $this->mysqli->insert_id;
            $stmt->close();
            
            // Update invoice
            $this->updateInvoicePayment($invoice_id, $amount);
            
            // Add insurance payment details
            $details_sql = "INSERT INTO payment_insurance_details 
                           (payment_id, insurance_provider, claim_number, approval_code, created_at) 
                           VALUES (?, ?, ?, ?, NOW())";
            
            $stmt = $this->mysqli->prepare($details_sql);
            $stmt->bind_param("isss", $payment_id, $insurance_provider, $claim_number, $approval_code);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to save insurance details: " . $stmt->error);
            }
            $stmt->close();
            
            // Create co-pay invoice for remaining balance
            $remaining_balance = $invoice['invoice_amount'] - ($invoice['paid_amount'] + $amount);
            if ($remaining_balance > 0) {
                $this->createCoPayInvoice($invoice_id, $remaining_balance, $payment_id);
            }
            
            // Update invoice status if fully paid by insurance
            if ($remaining_balance <= 0) {
                $update_sql = "UPDATE invoices SET invoice_status = 'paid' WHERE invoice_id = ?";
                $stmt = $this->mysqli->prepare($update_sql);
                $stmt->bind_param("i", $invoice_id);
                $stmt->execute();
                $stmt->close();
            }
            
            // Log transaction
            $this->logCashRegisterTransaction($register_id, $payment_id, $amount, 'insurance', 'payment');
            
            // Record accounting entry for insurance payment
            $accounting_result = $this->accountingBuilder
                ->setDate(date('Y-m-d'))
                ->setReference($payment_number)
                ->setDescription("Insurance payment received from " . $insurance_provider . " for invoice " . $invoice['invoice_number'])
                ->setModule('billing')
                ->setSourceDocument('payments', $payment_id)
                ->useTemplate('insurance_payment_received', [
                    'amount' => $amount,
                    'insurance_provider' => $insurance_provider,
                    'invoice_id' => $invoice_id,
                    'patient_id' => $invoice['invoice_client_id']
                ])
                ->execute();
            
            if (!$accounting_result['success']) {
                throw new Exception("Accounting entry failed: " . $accounting_result['error']);
            }
            
            $this->mysqli->commit();
            
            $_SESSION['alert_type'] = 'success';
            $_SESSION['alert_message'] = "Partial insurance payment of $" . number_format($amount, 2) . " processed. Co-pay invoice created for remaining balance.";
            header("Location: billing_payment_receipt.php?id=" . $payment_id);
            exit;
            
        } catch (Exception $e) {
            $this->mysqli->rollback();
            throw $e;
        }
    }
    
    // Helper methods (copy all the helper methods from your original file)
    private function generatePaymentNumber() {
        // ... implementation from original file
    }
    
    private function getInvoice($invoice_id) {
        // ... implementation from original file
    }
    
    private function updateInvoicePayment($invoice_id, $amount) {
        // ... implementation from original file
    }
    
    private function updateInvoiceItemsPayment($selected_items, $total_amount, $payment_id) {
        // ... implementation from original file
    }
    
    private function createCoPayInvoice($original_invoice_id, $remaining_balance, $insurance_payment_id) {
        // ... implementation from original file
    }
    
    private function generateCoPayInvoiceNumber() {
        // ... implementation from original file
    }
    
    private function copyInvoiceItems($original_invoice_id, $new_invoice_id, $remaining_balance) {
        // ... implementation from original file
    }
    
    private function addPaymentDetails($payment_id, $payment_method, $post_data) {
        // ... implementation from original file
    }
    
    private function logCashRegisterTransaction($register_id, $payment_id, $amount, $method, $type) {
        // ... implementation from original file
    }
    
    private function isRegisterOpen($register_id) {
        // ... implementation from original file
    }
}
?>