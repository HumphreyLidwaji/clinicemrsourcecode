<?php
// Start output buffering at the VERY beginning
if (ob_get_level()) ob_end_clean();
ob_start();

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Get report ID first
$report_id = isset($_GET['report_id']) ? intval($_GET['report_id']) : 0;

if ($report_id == 0) {
    ob_clean();
    die('Invalid report ID');
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/plugins/TCPDF/tcpdf.php';

ob_clean();

try {
    // Get company details for header
    $company_stmt = $mysqli->prepare("
        SELECT company_name, company_address, company_city, company_state, 
               company_zip, company_country, company_phone, company_email,
               company_website
        FROM companies 
        LIMIT 1
    ");
    
    if ($company_stmt) {
        $company_stmt->execute();
        $company_result = $company_stmt->get_result();
        $company = $company_result->fetch_assoc();
    }

    // Get complete report details
    $report_sql = "SELECT rr.*, 
                          p.patient_first_name, p.patient_last_name, p.patient_mrn, 
                          p.patient_gender, p.patient_dob, p.patient_phone,
                          ru.user_name as radiologist_name, 
                          u.user_name as referring_doctor_name, 
                          d.department_name,
                          ro.order_number, ro.clinical_notes,
                          cb.user_name as created_by_name
                   FROM radiology_reports rr
                   LEFT JOIN patients p ON rr.patient_id = p.patient_id
                   LEFT JOIN users ru ON rr.radiologist_id = ru.user_id
                   LEFT JOIN users u ON rr.referring_doctor_id = u.user_id
                   LEFT JOIN radiology_orders ro ON rr.radiology_order_id = ro.radiology_order_id
                   LEFT JOIN departments d ON ro.department_id = d.department_id
                   LEFT JOIN users cb ON rr.created_by = cb.user_id
                   WHERE rr.report_id = ?";
    
    $report_stmt = $mysqli->prepare($report_sql);
    
    if (!$report_stmt) {
        throw new Exception("Database error: " . $mysqli->error);
    }
    
    $report_stmt->bind_param("i", $report_id);
    
    if (!$report_stmt->execute()) {
        throw new Exception("Execute failed: " . $report_stmt->error);
    }
    
    $report_result = $report_stmt->get_result();
    
    if ($report_result->num_rows == 0) {
        throw new Exception("Report not found");
    }

    $report = $report_result->fetch_assoc();

    // Get studies with details
    $studies_sql = "SELECT rrs.*, 
                           ri.imaging_name, ri.imaging_code,
                           u.user_name as performed_by_name
                    FROM radiology_report_studies rrs
                    LEFT JOIN radiology_order_studies ros ON rrs.radiology_order_study_id = ros.radiology_order_study_id
                    LEFT JOIN radiology_imagings ri ON ros.imaging_id = ri.imaging_id
                    LEFT JOIN users u ON ros.performed_by = u.user_id
                    WHERE rrs.report_id = ?
                    ORDER BY ros.performed_date ASC";
    
    $studies_stmt = $mysqli->prepare($studies_sql);
    $studies_stmt->bind_param("i", $report_id);
    $studies_stmt->execute();
    $studies_result = $studies_stmt->get_result();

    // Calculate patient age
    $patient_age = '';
    if (!empty($report['patient_dob'])) {
        $birthDate = new DateTime($report['patient_dob']);
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

    // Create custom class for header/footer
    class RadiologyReportPDF extends TCPDF {
        protected $company;
        protected $report;
        
        public function setCompanyData($company, $report) {
            $this->company = $company;
            $this->report = $report;
        }
        
        // Page header
        public function Header() {
            // Position at 15 mm from top
            $this->SetY(15);
            
            // Set font
            $this->SetFont('helvetica', 'B', 16);
            
            // Title
            $this->Cell(0, 8, 'RADIOLOGY REPORT', 0, 1, 'C');
            
            // Facility name
            $this->SetFont('helvetica', 'B', 12);
            $this->Cell(0, 6, strtoupper($this->company['company_name'] ?? 'MEDICAL IMAGING CENTER'), 0, 1, 'C');
            
            // Department
            $this->SetFont('helvetica', '', 10);
            $this->Cell(0, 5, $this->report['department_name'] ?? 'Radiology Department', 0, 1, 'C');
            
            // Line separator
            $this->SetLineWidth(0.5);
            $this->Line(15, 40, 195, 40);
            
            // Reset Y position for content
            $this->SetY(45);
        }
        
        // Page footer
        public function Footer() {
            // Position at 15 mm from bottom
            $this->SetY(-15);
            
            // Set font
            $this->SetFont('helvetica', 'I', 8);
            $this->SetTextColor(128, 128, 128);
            
            // Report ID at bottom left
            $this->SetX(15);
            $this->Cell(30, 10, 'Report ID: ' . $this->report['report_number'], 0, 0, 'L');
            
            // Page number in center
            $this->Cell(130, 10, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
            
            // Date at bottom right
            $this->Cell(30, 10, date('m/d/Y'), 0, 0, 'R');
        }
    }

    // Create PDF with professional settings
    $pdf = new RadiologyReportPDF('P', 'mm', 'A4', true, 'UTF-8', false);

    // Pass company and report data
    $pdf->setCompanyData($company, $report);

    // Document information
    $pdf->SetCreator($company['company_name'] ?? 'Medical Facility');
    $pdf->SetAuthor($report['radiologist_name'] ?? 'Radiologist');
    $pdf->SetTitle('Radiology Report - ' . $report['report_number']);
    $pdf->SetSubject('Medical Imaging Report');

    // Set margins
    $pdf->SetMargins(15, 45, 15); // Top margin increased for header
    $pdf->SetAutoPageBreak(TRUE, 25);
    $pdf->SetHeaderMargin(10);
    $pdf->SetFooterMargin(15);
    
    // Set default monospaced font
    $pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
    
    // Remove default header/footer
    $pdf->setPrintHeader(true);
    $pdf->setPrintFooter(true);
    
    // Set image scale factor
    $pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);

    // Add a page
    $pdf->AddPage();
  

    // Report header section
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->SetTextColor(0, 64, 128);
    $pdf->Cell(0, 10, 'DIAGNOSTIC IMAGING REPORT', 0, 1, 'C');
    
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(0, 6, 'Report Number: ' . $report['report_number'], 0, 1, 'C');
    $pdf->Cell(0, 6, 'Report Date: ' . date('F j, Y', strtotime($report['report_date'])), 0, 1, 'C');
    
    $pdf->Ln(5);

    // Status indicator
    $status_color = '';
    switch(strtolower($report['report_status'])) {
        case 'final':
            $status_color = array(0, 128, 0); // Green
            break;
        case 'preliminary':
            $status_color = array(255, 165, 0); // Orange
            break;
        case 'draft':
            $status_color = array(220, 20, 60); // Crimson
            break;
        default:
            $status_color = array(100, 100, 100); // Gray
    }
    
    $pdf->SetFillColorArray($status_color);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(40, 8, strtoupper($report['report_status']), 1, 0, 'C', true);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(10);

    // Two-column layout for patient and order info
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->SetFillColor(240, 240, 240);
    $pdf->Cell(95, 7, 'PATIENT INFORMATION', 1, 0, 'C', true);
    $pdf->Cell(95, 7, 'ORDER & REFERRAL', 1, 1, 'C', true);
    
    $pdf->SetFont('helvetica', '', 9);
    
    // Row 1
    $pdf->Cell(95, 6, 'Name: ' . $report['patient_first_name'] . ' ' . $report['patient_last_name'], 1, 0, 'L');
    $pdf->Cell(95, 6, 'Order #: ' . $report['order_number'], 1, 1, 'L');
    
    // Row 2
    $pdf->Cell(95, 6, 'MRN: ' . $report['patient_mrn'], 1, 0, 'L');
    $pdf->Cell(95, 6, 'Referring MD: ' . ($report['referring_doctor_name'] ?? 'N/A'), 1, 1, 'L');
    
    // Row 3
    $pdf->Cell(95, 6, 'Age/DOB: ' . $patient_age . ' (' . date('m/d/Y', strtotime($report['patient_dob'])) . ')', 1, 0, 'L');
    $pdf->Cell(95, 6, 'Radiologist: ' . ($report['radiologist_name'] ?? 'N/A'), 1, 1, 'L');
    
    // Row 4
    $pdf->Cell(95, 6, 'Gender: ' . $report['patient_gender'], 1, 0, 'L');
    $pdf->Cell(95, 6, 'Report Date: ' . date('m/d/Y', strtotime($report['report_date'])), 1, 1, 'L');
    
    $pdf->Ln(8);

    // Clinical Information Section
    if (!empty($report['clinical_history']) || !empty($report['clinical_notes'])) {
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->SetFillColor(220, 230, 241);
        $pdf->Cell(0, 8, 'CLINICAL INFORMATION', 1, 1, 'L', true);
        
        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetFillColor(249, 252, 255);
        
        if (!empty($report['clinical_history'])) {
            $pdf->MultiCell(0, 6, 'Clinical History: ' . $report['clinical_history'], 1, 'L', true);
        }
        
        if (!empty($report['clinical_notes'])) {
            $pdf->MultiCell(0, 6, 'Clinical Notes: ' . $report['clinical_notes'], 1, 'L', true);
        }
        
        $pdf->Ln(5);
    }

    // Studies Performed Section
    if ($studies_result->num_rows > 0) {
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->SetFillColor(220, 230, 241);
        $pdf->Cell(0, 8, 'STUDIES PERFORMED', 1, 1, 'L', true);
        
        $studies_stmt->data_seek(0); // Reset pointer
        $study_count = 0;
        
        while ($study = $studies_result->fetch_assoc()) {
            $study_count++;
            
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->SetFillColor(245, 245, 245);
            $pdf->MultiCell(0, 6, "Study #{$study_count}: {$study['imaging_name']} ({$study['imaging_code']})", 1, 'L', true);
            
            $pdf->SetFont('helvetica', '', 8);
            if (!empty($study['study_findings'])) {
                $pdf->MultiCell(0, 5, "Findings:\n" . $study['study_findings'], 1, 'L');
            }
            
            if (!empty($study['study_impression'])) {
                $pdf->MultiCell(0, 5, "Impression:\n" . $study['study_impression'], 1, 'L');
            }
            
            if ($study_count < $studies_result->num_rows) {
                $pdf->Ln(2);
            }
        }
        
        $pdf->Ln(5);
    }

    // Technique (if available)
    if (!empty($report['technique'])) {
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(0, 6, 'TECHNIQUE:', 0, 1, 'L');
        
        $pdf->SetFont('helvetica', '', 9);
        $pdf->MultiCell(0, 5, $report['technique'], 0, 'L');
        $pdf->Ln(5);
    }

    // Comparison (if available)
    if (!empty($report['comparison'])) {
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(0, 6, 'COMPARISON:', 0, 1, 'L');
        
        $pdf->SetFont('helvetica', '', 9);
        $pdf->MultiCell(0, 5, $report['comparison'], 0, 'L');
        $pdf->Ln(5);
    }

    // FINDINGS Section
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->SetTextColor(0, 64, 128);
    $pdf->Cell(0, 8, 'FINDINGS', 0, 1, 'L');
    $pdf->SetTextColor(0, 0, 0);
    
    $pdf->SetFont('helvetica', '', 10);
    if (!empty($report['findings'])) {
        $pdf->MultiCell(0, 6, $report['findings'], 0, 'L');
    } else {
        $pdf->MultiCell(0, 6, 'No significant findings reported.', 0, 'L');
    }
    $pdf->Ln(8);

    // IMPRESSION Section
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->SetTextColor(0, 64, 128);
    $pdf->Cell(0, 8, 'IMPRESSION', 0, 1, 'L');
    $pdf->SetTextColor(0, 0, 0);
    
    $pdf->SetFont('helvetica', '', 10);
    if (!empty($report['impression'])) {
        $pdf->MultiCell(0, 6, $report['impression'], 0, 'L');
    } else {
        $pdf->MultiCell(0, 6, 'No impression provided.', 0, 'L');
    }
    $pdf->Ln(8);

    // RECOMMENDATIONS Section
    if (!empty($report['recommendations'])) {
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->SetTextColor(0, 64, 128);
        $pdf->Cell(0, 8, 'RECOMMENDATIONS', 0, 1, 'L');
        $pdf->SetTextColor(0, 0, 0);
        
        $pdf->SetFont('helvetica', '', 10);
        $pdf->MultiCell(0, 6, $report['recommendations'], 0, 'L');
        $pdf->Ln(8);
    }

    // CONCLUSION Section (if available)
    if (!empty($report['conclusion'])) {
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Cell(0, 7, 'CONCLUSION:', 0, 1, 'L');
        
        $pdf->SetFont('helvetica', '', 10);
        $pdf->MultiCell(0, 6, $report['conclusion'], 0, 'L');
        $pdf->Ln(10);
    }

    // Signature Section
    $pdf->SetFont('helvetica', '', 10);
    
    // Left side - Radiologist signature
    $pdf->Cell(90, 5, '________________________________________', 0, 0, 'L');
    
    // Right side - Date
    $pdf->Cell(0, 5, '________________________________________', 0, 1, 'R');
    
    // Signature labels
    $pdf->Cell(90, 5, ($report['radiologist_name'] ?? '') . ($report['radiologist_title'] ? ', ' . $report['radiologist_title'] : ', MD'), 0, 0, 'L');
    $pdf->Cell(0, 5, date('F j, Y'), 0, 1, 'R');
    
    $pdf->Cell(90, 5, 'Radiologist', 0, 0, 'L');
    $pdf->Cell(0, 5, 'Date', 0, 1, 'R');
    
    $pdf->Ln(10);

    // Disclaimer section
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->SetTextColor(100, 100, 100);
    
    $disclaimer = "CONFIDENTIAL MEDICAL REPORT - This report contains protected health information intended only for the ";
    $disclaimer .= "referring physician and medical team. Unauthorized disclosure is prohibited by law. ";
    $disclaimer .= "If received in error, please contact the radiology department immediately.";
    
    $pdf->MultiCell(0, 4, $disclaimer, 0, 'C');
    
    $pdf->Cell(0, 4, 'Report generated by: ' . $report['created_by_name'] . ' on ' . date('F j, Y \a\t g:i A'), 0, 1, 'C');
    
    if (!empty($company['company_phone'])) {
        $pdf->Cell(0, 4, 'For questions, contact: ' . $company['company_phone'], 0, 1, 'C');
    }

    // Output the PDF
    ob_clean();
    $pdf->Output('Radiology_Report_' . $report['report_number'] . '.pdf', 'I');
    exit;
    
} catch (Exception $e) {
    ob_clean();
    die('PDF Generation Error: ' . $e->getMessage());
}