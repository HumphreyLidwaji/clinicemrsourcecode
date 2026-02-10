<?php
// post/billing_handlers/CashRegisterHandler.php
class CashRegisterHandler {
    private $mysqli;
    private $user_id;
    
    public function __construct($mysqli, $user_id) {
        $this->mysqli = $mysqli;
        $this->user_id = $user_id;
    }
    
    public function execute($action, $data) {
        switch ($action) {
            case 'open_register':
                $this->openCashRegister($data);
                break;
            case 'close_register':
                $this->closeCashRegister($data);
                break;
            case 'suspend_register':
                $this->suspendCashRegister($data);
                break;
            case 'resume_register':
                $this->resumeCashRegister($data);
                break;
            case 'add_cash_transaction':
                $this->addCashTransaction($data);
                break;
            default:
                throw new Exception("Invalid cash register action: $action");
        }
    }
    
    private function openCashRegister($data) {
        $opening_balance = floatval($data['opening_balance']);
        $notes = sanitizeInput($data['notes'] ?? '');
        
        // Check if register is already open for today
        $check_sql = "SELECT register_id FROM cash_register WHERE register_date = CURDATE() AND status = 'open'";
        $check_result = $this->mysqli->query($check_sql);
        
        if ($check_result->num_rows > 0) {
            $_SESSION['alert_type'] = 'warning';
            $_SESSION['alert_message'] = 'Cash register is already open for today';
            header("Location: " . $_SERVER['HTTP_REFERER']);
            exit;
        }
        
        // Insert new cash register record
        $sql = "INSERT INTO cash_register 
                (register_date, opened_at, opened_by, opening_balance, notes, status) 
                VALUES (CURDATE(), NOW(), ?, ?, ?, 'open')";
        
        $stmt = $this->mysqli->prepare($sql);
        $stmt->bind_param("ids", $this->user_id, $opening_balance, $notes);
        
        if ($stmt->execute()) {
            // Log the action
            $register_id = $this->mysqli->insert_id;
            $this->logCashRegisterAction($register_id, 'opened', "Register opened with balance: $" . number_format($opening_balance, 2));
            
            $_SESSION['alert_type'] = 'success';
            $_SESSION['alert_message'] = 'Cash register opened successfully';
        } else {
            throw new Exception('Failed to open cash register: ' . $this->mysqli->error);
        }
        
        $stmt->close();
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit;
    }
    
    private function closeCashRegister($data) {
        $register_id = intval($data['register_id']);
        $actual_cash = floatval($data['actual_cash']);
        $closing_notes = sanitizeInput($data['closing_notes'] ?? '');
        
        // Get register details
        $register_sql = "SELECT * FROM cash_register WHERE register_id = ? AND status = 'open'";
        $stmt = $this->mysqli->prepare($register_sql);
        $stmt->bind_param("i", $register_id);
        $stmt->execute();
        $register = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$register) {
            throw new Exception('Cash register not found or already closed');
        }
        
        // Calculate totals
        $payment_stats = $this->getRegisterPaymentStats($register_id);
        $cash_sales = $payment_stats['cash_amount'];
        $card_sales = $payment_stats['card_amount'];
        $insurance_sales = $payment_stats['insurance_amount'];
        $total_sales = $payment_stats['total_amount'];
        $total_refunds = $payment_stats['refund_amount'];
        $net_sales = $total_sales - $total_refunds;
        
        $expected_cash = $register['opening_balance'] + $cash_sales;
        $cash_difference = $actual_cash - $expected_cash;
        
        // Update cash register record
        $update_sql = "UPDATE cash_register SET 
                        closed_at = NOW(),
                        closed_by = ?,
                        closing_balance = ?,
                        expected_cash = ?,
                        actual_cash = ?,
                        cash_sales = ?,
                        card_sales = ?,
                        insurance_sales = ?,
                        total_sales = ?,
                        total_refunds = ?,
                        net_sales = ?,
                        status = 'closed',
                        notes = CONCAT(COALESCE(notes, ''), ' | Closing: ', ?)
                       WHERE register_id = ?";
        
        $stmt = $this->mysqli->prepare($update_sql);
        $stmt->bind_param("iddddddddddsi", 
            $this->user_id, 
            $actual_cash, 
            $expected_cash, 
            $actual_cash,
            $cash_sales,
            $card_sales,
            $insurance_sales,
            $total_sales,
            $total_refunds,
            $net_sales,
            $closing_notes,
            $register_id
        );
        
        if ($stmt->execute()) {
            // Log the action
            $log_message = "Register closed. Expected: $" . number_format($expected_cash, 2) . 
                          ", Actual: $" . number_format($actual_cash, 2) . 
                          ", Difference: $" . number_format($cash_difference, 2);
            $this->logCashRegisterAction($register_id, 'closed', $log_message);
            
            $_SESSION['alert_type'] = 'success';
            $_SESSION['alert_message'] = 'Cash register closed successfully. ' . 
                                       ($cash_difference != 0 ? 'Difference: $' . number_format($cash_difference, 2) : 'Cash balanced');
        } else {
            throw new Exception('Failed to close cash register: ' . $this->mysqli->error);
        }
        
        $stmt->close();
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit;
    }
    
    private function addCashTransaction($data) {
        $register_id = intval($data['register_id']);
        $transaction_type = sanitizeInput($data['transaction_type']);
        $payment_method = sanitizeInput($data['payment_method']);
        $amount = floatval($data['amount']);
        $description = sanitizeInput($data['description'] ?? '');
        $reference_number = sanitizeInput($data['reference_number'] ?? '');
        $invoice_id = intval($data['invoice_id'] ?? 0);
        
        // Verify register is open
        if (!$this->isRegisterOpen($register_id)) {
            throw new Exception('Cash register is not open');
        }
        
        // Insert transaction
        $sql = "INSERT INTO cash_register_transactions 
                (register_id, transaction_type, payment_method, amount, description, reference_number, invoice_id, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $this->mysqli->prepare($sql);
        $stmt->bind_param("isssssii", $register_id, $transaction_type, $payment_method, $amount, $description, $reference_number, $invoice_id, $this->user_id);
        
        if ($stmt->execute()) {
            // Log the action
            $this->logCashRegisterAction($register_id, 'transaction_added', 
                "Added $transaction_type: $" . number_format($amount, 2) . " via $payment_method");
            
            $_SESSION['alert_type'] = 'success';
            $_SESSION['alert_message'] = ucfirst($transaction_type) . ' transaction recorded successfully';
        } else {
            throw new Exception('Failed to record transaction: ' . $this->mysqli->error);
        }
        
        $stmt->close();
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit;
    }
    
    private function suspendCashRegister($data) {
        $register_id = intval($data['register_id']);
        $reason = sanitizeInput($data['reason'] ?? 'Temporarily suspended');
        
        $sql = "UPDATE cash_register SET status = 'suspended', notes = CONCAT(COALESCE(notes, ''), ' | Suspended: ', ?) WHERE register_id = ?";
        $stmt = $this->mysqli->prepare($sql);
        $stmt->bind_param("si", $reason, $register_id);
        
        if ($stmt->execute()) {
            $this->logCashRegisterAction($register_id, 'suspended', "Register suspended: $reason");
            $_SESSION['alert_type'] = 'warning';
            $_SESSION['alert_message'] = 'Cash register suspended';
        } else {
            throw new Exception('Failed to suspend cash register');
        }
        
        $stmt->close();
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit;
    }
    
    private function resumeCashRegister($data) {
        $register_id = intval($data['register_id']);
        
        $sql = "UPDATE cash_register SET status = 'open', notes = CONCAT(COALESCE(notes, ''), ' | Resumed: ', NOW()) WHERE register_id = ?";
        $stmt = $this->mysqli->prepare($sql);
        $stmt->bind_param("i", $register_id);
        
        if ($stmt->execute()) {
            $this->logCashRegisterAction($register_id, 'resumed', "Register resumed");
            $_SESSION['alert_type'] = 'success';
            $_SESSION['alert_message'] = 'Cash register resumed';
        } else {
            throw new Exception('Failed to resume cash register');
        }
        
        $stmt->close();
        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit;
    }
    
    // Helper methods
    private function getRegisterPaymentStats($register_id) {
        $sql = "SELECT 
                    payment_method,
                    transaction_type,
                    SUM(payment_amount) as total_amount
                FROM payments 
                WHERE register_id = ? 
                GROUP BY payment_method, transaction_type";
        
        $stmt = $this->mysqli->prepare($sql);
        $stmt->bind_param("i", $register_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $stats = [
            'cash_amount' => 0,
            'card_amount' => 0,
            'insurance_amount' => 0,
            'total_amount' => 0,
            'refund_amount' => 0
        ];
        
        while ($row = $result->fetch_assoc()) {
            $amount = floatval($row['total_amount']);
            
            if ($row['transaction_type'] === 'refund') {
                $stats['refund_amount'] += $amount;
            } else {
                switch ($row['payment_method']) {
                    case 'cash':
                        $stats['cash_amount'] += $amount;
                        break;
                    case 'card':
                        $stats['card_amount'] += $amount;
                        break;
                    case 'insurance':
                        $stats['insurance_amount'] += $amount;
                        break;
                }
                $stats['total_amount'] += $amount;
            }
        }
        
        $stmt->close();
        return $stats;
    }
    
    private function isRegisterOpen($register_id) {
        $sql = "SELECT status FROM cash_register WHERE register_id = ?";
        $stmt = $this->mysqli->prepare($sql);
        $stmt->bind_param("i", $register_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $register = $result->fetch_assoc();
        $stmt->close();
        
        return $register && $register['status'] === 'open';
    }
    
    private function logCashRegisterAction($register_id, $action, $description) {
        $sql = "INSERT INTO cash_register_logs 
                (register_id, user_id, action, description) 
                VALUES (?, ?, ?, ?)";
        
        $stmt = $this->mysqli->prepare($sql);
        $stmt->bind_param("iiss", $register_id, $this->user_id, $action, $description);
        $stmt->execute();
        $stmt->close();
    }
}
?>