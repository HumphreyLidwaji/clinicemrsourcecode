<?php
// Start output buffering at the VERY beginning
if (ob_get_level()) ob_end_clean();
ob_start();

error_reporting(E_ALL);
ini_set('display_errors', 1);

$order_id = intval($_GET['order_id']);

if ($order_id == 0) {
    ob_clean();
    die('Invalid Order ID');
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/plugins/TCPDF/tcpdf.php';

// Clean any output before starting
ob_clean();

try {
    // Get company details for header
    $company_stmt = $mysqli->prepare("
        SELECT company_name, company_address, company_city, company_state, 
               company_zip, company_country, company_phone, company_email,
               company_website, company_logo, company_tax_id
        FROM companies 
        LIMIT 1
    ");

    if ($company_stmt) {
        $company_stmt->execute();
        $company_result = $company_stmt->get_result();
        $company = $company_result->fetch_assoc();
    }

    // Get lab order details
    $order_sql = "
        SELECT lo.*, 
               p.patient_first_name, 
               p.patient_last_name, 
               p.patient_mrn, 
               p.patient_dob, 
               p.patient_gender, 
               p.patient_phone, 
               p.patient_email,
        
               d.department_name,
               doc.user_name AS doctor_name
        FROM lab_orders lo
        JOIN patients p 
            ON lo.lab_order_patient_id = p.patient_id
        LEFT JOIN departments d 
            ON lo.lab_order_department_id = d.department_id
        LEFT JOIN users doc 
            ON lo.lab_order_doctor_id = doc.user_id
        WHERE lo.lab_order_id = ?
    ";
    
    $order_stmt = $mysqli->prepare($order_sql);
    $order_stmt->bind_param("i", $order_id);
    $order_stmt->execute();
    $order_result = $order_stmt->get_result();

    if ($order_result->num_rows === 0) {
        throw new Exception("Lab order not found.");
    }

    $order = $order_result->fetch_assoc();

    // Get all tests for this order with their results
    $tests_sql = "SELECT lt.*, lot.*, lr.*, 
                         tech.user_name as technician_name,
                         lr.created_at as result_date,
                         cat.category_name
                  FROM lab_order_tests lot
                  JOIN lab_tests lt ON lot.test_id = lt.test_id
                  JOIN lab_test_categories cat ON lt.category_id = cat.category_id
                  LEFT JOIN lab_results lr ON lot.lab_order_test_id = lr.lab_order_test_id
                  LEFT JOIN users tech ON lr.performed_by = tech.user_id
                  WHERE lot.lab_order_id = ?
                  ORDER BY lt.category_id, lt.test_name";

    $tests_stmt = $mysqli->prepare($tests_sql);
    $tests_stmt->bind_param("i", $order_id);
    $tests_stmt->execute();
    $tests_result = $tests_stmt->get_result();

    $tests = [];
    $categories = [];
    $total_tests = 0;
    $completed_tests = 0;
    $abnormal_count = 0;

    while ($test = $tests_result->fetch_assoc()) {
        $tests[] = $test;
        $total_tests++;
        
        if ($test['result_value']) {
            $completed_tests++;
        }
        
        // Check if abnormal
        if (in_array($test['abnormal_flag'] ?? '', ['high', 'low', 'critical_high', 'critical_low', 'abnormal'])) {
            $abnormal_count++;
        }
        
        // Group by category
        $category_id = $test['category_id'];
        if (!isset($categories[$category_id])) {
            $categories[$category_id] = [
                'category_name' => $test['category_name'],
                'tests' => []
            ];
        }
        $categories[$category_id]['tests'][] = $test;
    }

    // Create custom PDF class for lab reports
    class LabReportPDF extends TCPDF {
        protected $company;
        protected $order;
        
        public function setLabData($company, $order) {
            $this->company = $company;
            $this->order = $order;
        }
        
        // Page header
        public function Header() {
            // Set header font
            $this->SetFont('helvetica', 'B', 14);
            
            // Position at top
            $this->SetY(10);
            
            // Center - Company name
            $this->Cell(0, 8, strtoupper($this->company['company_name'] ?? 'DIAGNOSTIC LABORATORY'), 0, 1, 'C');
            
            // Subtitle
            $this->SetFont('helvetica', '', 10);
            $this->Cell(0, 6, 'Accredited Medical Testing Facility', 0, 1, 'C');
            
            // Contact info
            $this->SetFont('helvetica', '', 8);
            $contact_info = '';
            if (!empty($this->company['company_address'])) {
                $contact_info .= $this->company['company_address'];
                if (!empty($this->company['company_city'])) {
                    $contact_info .= ', ' . $this->company['company_city'];
                }
            }
            
            if (!empty($this->company['company_phone'])) {
                if ($contact_info) $contact_info .= ' | ';
                $contact_info .= 'Tel: ' . $this->company['company_phone'];
            }
            
            if (!empty($this->company['company_email'])) {
                if ($contact_info) $contact_info .= ' | ';
                $contact_info .= 'Email: ' . $this->company['company_email'];
            }
            
            if ($contact_info) {
                $this->Cell(0, 6, $contact_info, 0, 1, 'C');
            }
            
            // Line separator
            $this->SetLineWidth(0.5);
            $this->Line(15, 35, 195, 35);
            
            // Report title
            $this->SetY(40);
            $this->SetFont('helvetica', 'B', 16);
            $this->Cell(0, 10, 'LABORATORY TEST REPORT', 0, 1, 'C');
            
            // Report number and date
            $this->SetFont('helvetica', '', 10);
            $this->Cell(0, 6, 'Report No: ' . $this->order['order_number'], 0, 1, 'C');
            $this->Cell(0, 6, 'Date: ' . date('F j, Y'), 0, 1, 'C');
            
            // Reset Y position
            $this->SetY(65);
        }
        
        // Page footer
        public function Footer() {
            // Position at 15 mm from bottom
            $this->SetY(-15);
            
            // Set font
            $this->SetFont('helvetica', 'I', 8);
            $this->SetTextColor(128, 128, 128);
            
            // Page number
            $this->Cell(0, 10, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
            
            // Confidential notice
            $this->SetY(-25);
            $this->SetFont('helvetica', '', 7);
            $this->Cell(0, 10, 'CONFIDENTIAL - For authorized medical use only. Unauthorized disclosure prohibited.', 0, 0, 'C');
        }
    }
    
    // Create PDF
    $pdf = new LabReportPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->setLabData($company, $order);
    
    // Document information
    $pdf->SetCreator($company['company_name'] ?? 'Lab System');
    $pdf->SetAuthor($order['doctor_name'] ?? 'Medical Doctor');
    $pdf->SetTitle('Lab Report - ' . $order['order_number']);
    $pdf->SetSubject('Laboratory Test Results');
    
    // Set margins
    $pdf->SetMargins(15, 65, 15);
    $pdf->SetAutoPageBreak(TRUE, 25);
    $pdf->SetHeaderMargin(10);
    $pdf->SetFooterMargin(20);
    
    // Add a page
    $pdf->AddPage();
 
    
    // Calculate patient age
    $patient_age = '';
    if ($order['patient_dob']) {
        $birthDate = new DateTime($order['patient_dob']);
        $today = new DateTime();
        $interval = $today->diff($birthDate);
        
        if ($interval->y > 0) {
            $patient_age = $interval->y . ' years';
        } elseif ($interval->m > 0) {
            $patient_age = $interval->m . ' months';
        } else {
            $patient_age = $interval->d . ' days';
        }
    }
    
    // Patient Information Section
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->SetFillColor(240, 240, 240);
    $pdf->Cell(0, 8, 'PATIENT INFORMATION', 1, 1, 'C', true);
    
    $pdf->SetFont('helvetica', '', 10);
    
    // Row 1
    $pdf->Cell(95, 6, 'Patient Name: ' . $order['patient_first_name'] . ' ' . $order['patient_last_name'], 1, 0, 'L');
    $pdf->Cell(95, 6, 'MRN: ' . $order['patient_mrn'], 1, 1, 'L');
    
    // Row 2
    $pdf->Cell(95, 6, 'Date of Birth: ' . date('m/d/Y', strtotime($order['patient_dob'])) . ' (' . $patient_age . ')', 1, 0, 'L');
    $pdf->Cell(95, 6, 'Gender: ' . $order['patient_gender'], 1, 1, 'L');
    
    // Row 3 - Address
    $patient_address = '';
    if (!empty($order['patient_address'])) {
        $patient_address = $order['patient_address'];
        if (!empty($order['patient_city'])) {
            $patient_address .= ', ' . $order['patient_city'];
        }
        if (!empty($order['patient_state'])) {
            $patient_address .= ', ' . $order['patient_state'];
        }
        if (!empty($order['patient_zip'])) {
            $patient_address .= ' ' . $order['patient_zip'];
        }
    }
    $pdf->Cell(95, 6, 'Address: ' . $patient_address, 1, 0, 'L');
    $pdf->Cell(95, 6, 'Phone: ' . $order['patient_phone'], 1, 1, 'L');
    
    // Row 4
    $pdf->Cell(95, 6, 'Ordering Physician: Dr. ' . $order['doctor_name'], 1, 0, 'L');
    $pdf->Cell(95, 6, 'Department: ' . $order['department_name'], 1, 1, 'L');
    
    // Row 5
    $pdf->Cell(95, 6, 'Order Date: ' . date('m/d/Y g:i A', strtotime($order['created_at'])), 1, 0, 'L');
    $pdf->Cell(95, 6, 'Report Status: ' . strtoupper($order['lab_order_status']), 1, 1, 'L');
    
    $pdf->Ln(8);
    
    // Test Results Summary
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->SetFillColor(220, 230, 241);
    $pdf->Cell(0, 7, 'TEST SUMMARY', 1, 1, 'C', true);
    
    $pdf->SetFont('helvetica', '', 9);
    $pdf->Cell(95, 6, 'Total Tests Ordered: ' . $total_tests, 1, 0, 'L');
    $pdf->Cell(95, 6, 'Tests Completed: ' . $completed_tests, 1, 1, 'L');
    
    $pdf->Cell(95, 6, 'Abnormal Results: ' . $abnormal_count, 1, 0, 'L');
    $pdf->Cell(95, 6, 'Completion Rate: ' . ($total_tests > 0 ? round(($completed_tests / $total_tests) * 100, 1) : 0) . '%', 1, 1, 'L');
    
    $pdf->Ln(10);
    
    // Test Results Section
    if (!empty($categories)) {
        foreach ($categories as $category_id => $category) {
            // Category header
            $pdf->SetFont('helvetica', 'B', 12);
            $pdf->SetTextColor(0, 64, 128);
            $pdf->Cell(0, 8, strtoupper($category['category_name']), 0, 1, 'L');
            $pdf->SetTextColor(0, 0, 0);
            $pdf->Ln(2);
            
            // Table header
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->SetFillColor(220, 220, 220);
            
            $pdf->Cell(80, 8, 'TEST NAME', 1, 0, 'C', true);
            $pdf->Cell(20, 8, 'RESULT', 1, 0, 'C', true);
            $pdf->Cell(20, 8, 'UNIT', 1, 0, 'C', true);
            $pdf->Cell(40, 8, 'REFERENCE RANGE', 1, 0, 'C', true);
            $pdf->Cell(15, 8, 'FLAG', 1, 0, 'C', true);
            $pdf->Cell(20, 8, 'STATUS', 1, 1, 'C', true);
            
            // Test results
            $pdf->SetFont('helvetica', '', 8);
            foreach ($category['tests'] as $test) {
                $result_value = $test['result_value'] ?? 'Pending';
                $result_unit = $test['result_unit'] ?? '';
                $abnormal_flag = $test['abnormal_flag'] ?? '';
                $has_result = !empty($test['result_value']);
                
                // Parse reference range
                $reference_range = 'N/A';
                if (!empty($test['reference_ranges'])) {
                    $ranges = json_decode($test['reference_ranges'], true);
                    if (is_array($ranges) && !empty($ranges)) {
                        $range_texts = [];
                        foreach ($ranges as $range) {
                            $range_text = $range['min'] . ' - ' . $range['max'];
                            if (!empty($range['unit'])) {
                                $range_text .= ' ' . $range['unit'];
                            }
                            if (!empty($range['condition'])) {
                                $range_text .= ' (' . $range['condition'] . ')';
                            }
                            $range_texts[] = $range_text;
                        }
                        $reference_range = implode("\n", $range_texts);
                    }
                } elseif (!empty($test['reference_range'])) {
                    $reference_range = $test['reference_range'];
                }
                
                // Determine flag
                $flag = '';
                $flag_color = array(0, 0, 0);
                switch ($abnormal_flag) {
                    case 'high':
                    case 'critical_high':
                        $flag = 'H';
                        $flag_color = array(220, 20, 60); // Crimson
                        break;
                    case 'low':
                    case 'critical_low':
                        $flag = 'L';
                        $flag_color = array(255, 140, 0); // Dark Orange
                        break;
                    case 'abnormal':
                        $flag = 'A';
                        $flag_color = array(255, 215, 0); // Gold
                        break;
                    default:
                        $flag = '';
                }
                
                // Test name
                $test_name = $test['test_name'];
                if (!empty($test['test_code'])) {
                    $test_name .= "\n(" . $test['test_code'] . ")";
                }
                
                $pdf->MultiCell(80, isset($test['test_code']) ? 12 : 8, $test_name, 1, 'L', false, 0);
                $pdf->Cell(20, isset($test['test_code']) ? 12 : 8, $result_value, 1, 0, 'C', false);
                $pdf->Cell(20, isset($test['test_code']) ? 12 : 8, $result_unit, 1, 0, 'C', false);
                $pdf->MultiCell(40, isset($test['test_code']) ? 12 : 8, $reference_range, 1, 'L', false, 0);
                
                // Flag cell
                if ($flag) {
                    $pdf->SetTextColorArray($flag_color);
                    $pdf->Cell(15, isset($test['test_code']) ? 12 : 8, $flag, 1, 0, 'C', false);
                    $pdf->SetTextColor(0, 0, 0);
                } else {
                    $pdf->Cell(15, isset($test['test_code']) ? 12 : 8, '', 1, 0, 'C', false);
                }
                
                // Status cell
                if ($has_result) {
                    $pdf->SetFillColor(220, 255, 220);
                    $pdf->Cell(20, isset($test['test_code']) ? 12 : 8, 'COMPLETED', 1, 1, 'C', true);
                } else {
                    $pdf->SetFillColor(255, 255, 200);
                    $pdf->Cell(20, isset($test['test_code']) ? 12 : 8, 'PENDING', 1, 1, 'C', true);
                }
            }
            
            $pdf->Ln(5);
        }
        
        // Legend
        $pdf->SetFont('helvetica', 'I', 8);
        $pdf->Cell(0, 6, 'Legend: H = High, L = Low, A = Abnormal', 0, 1, 'L');
        $pdf->Ln(5);
    }
    
    // Clinical Notes
    if (!empty($order['clinical_notes'])) {
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->SetFillColor(240, 240, 240);
        $pdf->Cell(0, 8, 'CLINICAL NOTES', 1, 1, 'L', true);
        
        $pdf->SetFont('helvetica', '', 9);
        $pdf->MultiCell(0, 6, $order['clinical_notes'], 1, 'L');
        $pdf->Ln(5);
    }
    
    // Interpretation and Signature Section
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->SetFillColor(220, 230, 241);
    $pdf->Cell(0, 8, 'INTERPRETATION & AUTHORIZATION', 1, 1, 'C', true);
    
    $pdf->SetFont('helvetica', '', 9);
    $pdf->MultiCell(0, 6, 'Results should be interpreted in the context of clinical findings and patient history. All tests performed meet quality control standards.', 0, 'L');
    
    $pdf->Ln(10);
    
    // Signature lines
    $pdf->SetFont('helvetica', '', 10);
    
    // Left signature - Laboratory Technologist
    $pdf->Cell(90, 5, '________________________________________', 0, 0, 'L');
    
    // Right signature - Pathologist
    $pdf->Cell(0, 5, '________________________________________', 0, 1, 'R');
    
    // Signature labels
    $pdf->Cell(90, 5, 'Laboratory Technologist', 0, 0, 'L');
    $pdf->Cell(0, 5, 'Reviewing Pathologist', 0, 1, 'R');
    
    $pdf->Cell(90, 5, date('F j, Y'), 0, 0, 'L');
    $pdf->Cell(0, 5, date('F j, Y'), 0, 1, 'R');
    
    $pdf->Ln(10);
    
    // Disclaimer
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->SetTextColor(100, 100, 100);
    
    $disclaimer = "This report is generated by " . ($company['company_name'] ?? 'the Laboratory Information System') . ". ";
    $disclaimer .= "Results are valid only for the sample tested. For any questions regarding this report, please contact the laboratory. ";
    $disclaimer .= "Unauthorized reproduction or distribution is prohibited.";
    
    $pdf->MultiCell(0, 4, $disclaimer, 0, 'C');
    
    $pdf->Cell(0, 4, 'Report generated on: ' . date('F j, Y \a\t g:i A'), 0, 1, 'C');
    
    // Clean output buffer and output PDF
    ob_end_clean();
    $pdf->Output('Lab_Report_' . $order['order_number'] . '.pdf', 'I');
    exit;
    
} catch (Exception $e) {
    ob_clean();
    die('PDF Generation Error: ' . $e->getMessage());
}
?>