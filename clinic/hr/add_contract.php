<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $employee_id = intval($_POST['employee_id']);
        $start_date = $_POST['start_date'];
        $end_date = $_POST['end_date'] ?? null;
        $terms = $_POST['terms'] ?? null;
        
        // Validate required fields
        if (empty($employee_id) || empty($start_date)) {
            throw new Exception("Employee ID and start date are required!");
        }
        
        // Check if employee exists
        $check_sql = "SELECT employee_id FROM employees WHERE employee_id = ?";
        $check_stmt = $mysqli->prepare($check_sql);
        $check_stmt->bind_param("i", $employee_id);
        $check_stmt->execute();
        
        if ($check_stmt->get_result()->num_rows === 0) {
            throw new Exception("Employee not found!");
        }
        
        // Insert contract
        $sql = "INSERT INTO employee_contracts (employee_id, start_date, end_date, terms) VALUES (?, ?, ?, ?)";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("isss", $employee_id, $start_date, $end_date, $terms);
        
        if ($stmt->execute()) {
            // Log the action
            $audit_sql = "INSERT INTO hr_audit_log (user_id, action, description, table_name, record_id) VALUES (?, 'contract_added', ?, 'employee_contracts', ?)";
            $audit_stmt = $mysqli->prepare($audit_sql);
            $description = "Added contract for employee ID: $employee_id";
            $audit_stmt->bind_param("isi", $_SESSION['user_id'], $description, $stmt->insert_id);
            $audit_stmt->execute();
            
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Contract added successfully!";
            header("Location: view_employee.php?id=" . $employee_id);
            exit;
        } else {
            throw new Exception("Contract insertion failed: " . $stmt->error);
        }
        
    } catch (Exception $e) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error adding contract: " . $e->getMessage();
        header("Location: " . ($_POST['employee_id'] ? "view_employee.php?id=" . $_POST['employee_id'] : "manage_employees.php"));
        exit;
    }
} else {
    // Not a POST request, redirect
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Invalid request method!";
    header("Location: manage_employees.php");
    exit;
}
?>