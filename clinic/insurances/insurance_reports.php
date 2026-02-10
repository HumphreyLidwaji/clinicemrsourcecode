<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Date range filter
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$report_type = $_GET['report_type'] ?? 'summary';

// Get overall statistics
$stats_sql = mysqli_query($mysqli, "
    SELECT 
        (SELECT COUNT(*) FROM insurance_companies) as total_companies,
        (SELECT COUNT(*) FROM insurance_companies WHERE is_active = 1) as active_companies,
        (SELECT COUNT(*) FROM insurance_schemes) as total_schemes,
        (SELECT COUNT(*) FROM insurance_schemes WHERE is_active = 1) as active_schemes,
        (SELECT COUNT(*) FROM patients WHERE insurance_scheme_id IS NOT NULL) as enrolled_patients,
        (SELECT SUM(claim_amount) FROM insurance_claims WHERE status = 'approved') as total_claims_amount,
        (SELECT COUNT(*) FROM insurance_claims) as total_claims,
        (SELECT COUNT(*) FROM insurance_claims WHERE status = 'approved') as approved_claims,
        (SELECT COUNT(*) FROM insurance_claims WHERE status = 'pending') as pending_claims,
        (SELECT COUNT(*) FROM insurance_claims WHERE status = 'rejected') as rejected_claims
");
$stats = mysqli_fetch_assoc($stats_sql);

// Get companies by scheme count
$companies_by_schemes_sql = mysqli_query($mysqli, "
    SELECT 
        c.company_name,
        c.company_code,
        COUNT(s.scheme_id) as scheme_count,
        SUM(CASE WHEN s.is_active = 1 THEN 1 ELSE 0 END) as active_schemes,
        SUM(CASE WHEN s.is_active = 0 THEN 1 ELSE 0 END) as inactive_schemes
    FROM insurance_companies c
    LEFT JOIN insurance_schemes s ON c.insurance_company_id = s.insurance_company_id
    GROUP BY c.insurance_company_id, c.company_name, c.company_code
    ORDER BY scheme_count DESC
    LIMIT 10
");

// Get schemes by coverage type
$schemes_by_coverage_sql = mysqli_query($mysqli, "
    SELECT 
        scheme_type,
        COUNT(*) as total_schemes,
        SUM(outpatient_cover) as outpatient_count,
        SUM(inpatient_cover) as inpatient_count,
        SUM(maternity_cover) as maternity_count,
        SUM(dental_cover) as dental_count,
        SUM(optical_cover) as optical_count
    FROM insurance_schemes
    WHERE is_active = 1
    GROUP BY scheme_type
    ORDER BY total_schemes DESC
");

// Get monthly claims data (last 6 months)
$monthly_claims_sql = mysqli_query($mysqli, "
    SELECT 
        DATE_FORMAT(claim_date, '%Y-%m') as month,
        COUNT(*) as claim_count,
        SUM(claim_amount) as claim_amount,
        SUM(CASE WHEN status = 'approved' THEN claim_amount ELSE 0 END) as approved_amount,
        SUM(CASE WHEN status = 'pending' THEN claim_amount ELSE 0 END) as pending_amount,
        SUM(CASE WHEN status = 'rejected' THEN claim_amount ELSE 0 END) as rejected_amount
    FROM insurance_claims
    WHERE claim_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(claim_date, '%Y-%m')
    ORDER BY month DESC
    LIMIT 6
");

// Get top schemes by patient count
$top_schemes_sql = mysqli_query($mysqli, "
    SELECT 
        s.scheme_name,
        s.scheme_code,
        c.company_name,
        COUNT(p.patient_id) as patient_count,
        SUM(CASE WHEN p.is_active = 1 THEN 1 ELSE 0 END) as active_patients
    FROM insurance_schemes s
    LEFT JOIN patients p ON s.scheme_id = p.insurance_scheme_id
    LEFT JOIN insurance_companies c ON s.insurance_company_id = c.insurance_company_id
    WHERE s.is_active = 1
    GROUP BY s.scheme_id, s.scheme_name, s.scheme_code, c.company_name
    ORDER BY patient_count DESC
    LIMIT 10
");

// Get claims by status
$claims_by_status_sql = mysqli_query($mysqli, "
    SELECT 
        status,
        COUNT(*) as claim_count,
        SUM(claim_amount) as claim_amount,
        AVG(claim_amount) as avg_claim_amount
    FROM insurance_claims
    GROUP BY status
    ORDER BY claim_count DESC
");
?>

<div class="card">
    <div class="card-header bg-dark py-2">
        <h3 class="card-title mt-2 mb-0 text-white"><i class="fas fa-fw fa-chart-bar mr-2"></i>Insurance Reports</h3>
        <div class="card-tools">
            <div class="btn-group">
                <a href="insurance_management.php" class="btn btn-light">
                    <i class="fas fa-arrow-left mr-2"></i>Back
                </a>
                <button class="btn btn-info ml-2" onclick="window.print()">
                    <i class="fas fa-print mr-2"></i>Print Report
                </button>
                <a href="insurance_export.php" class="btn btn-success ml-2">
                    <i class="fas fa-file-export mr-2"></i>Export
                </a>
            </div>
        </div>
    </div>
    
    <div class="card-body">
        <!-- Report Filters -->
        <div class="card mb-4">
            <div class="card-header bg-light">
                <h5 class="card-title mb-0">Report Filters</h5>
            </div>
            <div class="card-body">
                <form method="GET" autocomplete="off" class="form-inline">
                    <div class="form-group mr-3">
                        <label for="date_from" class="mr-2">From:</label>
                        <input type="date" class="form-control" id="date_from" name="date_from" value="<?php echo $date_from; ?>">
                    </div>
                    <div class="form-group mr-3">
                        <label for="date_to" class="mr-2">To:</label>
                        <input type="date" class="form-control" id="date_to" name="date_to" value="<?php echo $date_to; ?>">
                    </div>
                    <div class="form-group mr-3">
                        <label for="report_type" class="mr-2">Report:</label>
                        <select class="form-control" id="report_type" name="report_type">
                            <option value="summary" <?php echo $report_type == 'summary' ? 'selected' : ''; ?>>Summary</option>
                            <option value="companies" <?php echo $report_type == 'companies' ? 'selected' : ''; ?>>Companies</option>
                            <option value="schemes" <?php echo $report_type == 'schemes' ? 'selected' : ''; ?>>Schemes</option>
                            <option value="claims" <?php echo $report_type == 'claims' ? 'selected' : ''; ?>>Claims</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter mr-2"></i>Apply Filters
                    </button>
                    <a href="insurance_reports.php" class="btn btn-secondary ml-2">
                        <i class="fas fa-times mr-2"></i>Clear
                    </a>
                </form>
            </div>
        </div>
        
        <!-- Summary Statistics -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card bg-primary text-white">
                    <div class="card-body py-2">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h4 class="font-weight-bold mb-0"><?php echo $stats['total_companies'] ?? 0; ?></h4>
                                <small>Insurance Companies</small>
                            </div>
                            <i class="fas fa-hospital fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-success text-white">
                    <div class="card-body py-2">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h4 class="font-weight-bold mb-0"><?php echo $stats['total_schemes'] ?? 0; ?></h4>
                                <small>Insurance Schemes</small>
                            </div>
                            <i class="fas fa-file-medical fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-info text-white">
                    <div class="card-body py-2">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h4 class="font-weight-bold mb-0"><?php echo $stats['enrolled_patients'] ?? 0; ?></h4>
                                <small>Enrolled Patients</small>
                            </div>
                            <i class="fas fa-users fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-warning text-white">
                    <div class="card-body py-2">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h4 class="font-weight-bold mb-0">$<?php echo number_format($stats['total_claims_amount'] ?? 0, 2); ?></h4>
                                <small>Total Claims</small>
                            </div>
                            <i class="fas fa-file-invoice-dollar fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Claims Statistics -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">Claims by Status</h5>
                    </div>
                    <div class="card-body">
                        <?php if (mysqli_num_rows($claims_by_status_sql) > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Status</th>
                                            <th class="text-center">Count</th>
                                            <th class="text-right">Amount</th>
                                            <th class="text-right">Average</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while($claim = mysqli_fetch_assoc($claims_by_status_sql)): ?>
                                        <tr>
                                            <td>
                                                <?php 
                                                $status_badge = '';
                                                switch($claim['status']) {
                                                    case 'approved': $status_badge = 'badge-success'; break;
                                                    case 'pending': $status_badge = 'badge-warning'; break;
                                                    case 'rejected': $status_badge = 'badge-danger'; break;
                                                    default: $status_badge = 'badge-secondary';
                                                }
                                                ?>
                                                <span class="badge <?php echo $status_badge; ?>">
                                                    <?php echo ucfirst($claim['status']); ?>
                                                </span>
                                            </td>
                                            <td class="text-center"><?php echo $claim['claim_count']; ?></td>
                                            <td class="text-right">$<?php echo number_format($claim['claim_amount'], 2); ?></td>
                                            <td class="text-right">$<?php echo number_format($claim['avg_claim_amount'], 2); ?></td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-3">
                                <i class="fas fa-file-invoice-dollar fa-2x text-muted mb-2"></i>
                                <p class="text-muted">No claims data available</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">Monthly Claims (Last 6 Months)</h5>
                    </div>
                    <div class="card-body">
                        <?php if (mysqli_num_rows($monthly_claims_sql) > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Month</th>
                                            <th class="text-center">Claims</th>
                                            <th class="text-right">Total Amount</th>
                                            <th class="text-right">Approved</th>
                                            <th class="text-right">Pending</th>
                                            <th class="text-right">Rejected</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while($monthly = mysqli_fetch_assoc($monthly_claims_sql)): ?>
                                        <tr>
                                            <td><?php echo date('F Y', strtotime($monthly['month'] . '-01')); ?></td>
                                            <td class="text-center"><?php echo $monthly['claim_count']; ?></td>
                                            <td class="text-right">$<?php echo number_format($monthly['claim_amount'], 2); ?></td>
                                            <td class="text-right text-success">$<?php echo number_format($monthly['approved_amount'], 2); ?></td>
                                            <td class="text-right text-warning">$<?php echo number_format($monthly['pending_amount'], 2); ?></td>
                                            <td class="text-right text-danger">$<?php echo number_format($monthly['rejected_amount'], 2); ?></td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-3">
                                <i class="fas fa-chart-line fa-2x text-muted mb-2"></i>
                                <p class="text-muted">No monthly claims data available</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Companies by Schemes -->
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">Top Companies by Number of Schemes</h5>
                    </div>
                    <div class="card-body">
                        <?php if (mysqli_num_rows($companies_by_schemes_sql) > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Company</th>
                                            <th class="text-center">Total Schemes</th>
                                            <th class="text-center">Active</th>
                                            <th class="text-center">Inactive</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while($company = mysqli_fetch_assoc($companies_by_schemes_sql)): ?>
                                        <tr>
                                            <td>
                                                <div class="font-weight-bold"><?php echo htmlspecialchars($company['company_name']); ?></div>
                                                <?php if (!empty($company['company_code'])): ?>
                                                    <small class="text-muted"><?php echo htmlspecialchars($company['company_code']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge badge-primary"><?php echo $company['scheme_count']; ?></span>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge badge-success"><?php echo $company['active_schemes']; ?></span>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge badge-secondary"><?php echo $company['inactive_schemes']; ?></span>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-3">
                                <i class="fas fa-hospital fa-2x text-muted mb-2"></i>
                                <p class="text-muted">No company data available</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">Coverage by Scheme Type</h5>
                    </div>
                    <div class="card-body">
                        <?php if (mysqli_num_rows($schemes_by_coverage_sql) > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Scheme Type</th>
                                            <th class="text-center">Total</th>
                                            <th class="text-center">OP</th>
                                            <th class="text-center">IP</th>
                                            <th class="text-center">Mat</th>
                                            <th class="text-center">Den</th>
                                            <th class="text-center">Opt</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while($coverage = mysqli_fetch_assoc($schemes_by_coverage_sql)): ?>
                                        <tr>
                                            <td>
                                                <span class="badge badge-info"><?php echo htmlspecialchars($coverage['scheme_type']); ?></span>
                                            </td>
                                            <td class="text-center"><?php echo $coverage['total_schemes']; ?></td>
                                            <td class="text-center">
                                                <?php if ($coverage['outpatient_count']): ?>
                                                    <span class="badge badge-success"><?php echo $coverage['outpatient_count']; ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <?php if ($coverage['inpatient_count']): ?>
                                                    <span class="badge badge-primary"><?php echo $coverage['inpatient_count']; ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <?php if ($coverage['maternity_count']): ?>
                                                    <span class="badge badge-info"><?php echo $coverage['maternity_count']; ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <?php if ($coverage['dental_count']): ?>
                                                    <span class="badge badge-warning"><?php echo $coverage['dental_count']; ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <?php if ($coverage['optical_count']): ?>
                                                    <span class="badge badge-secondary"><?php echo $coverage['optical_count']; ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-3">
                                <i class="fas fa-chart-pie fa-2x text-muted mb-2"></i>
                                <p class="text-muted">No coverage data available</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Top Schemes by Patients -->
        <div class="card">
            <div class="card-header bg-light">
                <h5 class="card-title mb-0">Top Insurance Schemes by Patient Enrollment</h5>
            </div>
            <div class="card-body">
                <?php if (mysqli_num_rows($top_schemes_sql) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Scheme Name</th>
                                    <th>Company</th>
                                    <th class="text-center">Total Patients</th>
                                    <th class="text-center">Active Patients</th>
                                    <th class="text-center">Utilization Rate</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($scheme = mysqli_fetch_assoc($top_schemes_sql)): 
                                    $utilization_rate = $scheme['patient_count'] > 0 ? ($scheme['active_patients'] / $scheme['patient_count']) * 100 : 0;
                                ?>
                                <tr>
                                    <td>
                                        <div class="font-weight-bold"><?php echo htmlspecialchars($scheme['scheme_name']); ?></div>
                                        <?php if (!empty($scheme['scheme_code'])): ?>
                                            <small class="text-muted"><?php echo htmlspecialchars($scheme['scheme_code']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($scheme['company_name']); ?></td>
                                    <td class="text-center">
                                        <span class="badge badge-primary"><?php echo $scheme['patient_count']; ?></span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge badge-success"><?php echo $scheme['active_patients']; ?></span>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($utilization_rate >= 80): ?>
                                            <span class="badge badge-success"><?php echo number_format($utilization_rate, 1); ?>%</span>
                                        <?php elseif ($utilization_rate >= 50): ?>
                                            <span class="badge badge-warning"><?php echo number_format($utilization_rate, 1); ?>%</span>
                                        <?php else: ?>
                                            <span class="badge badge-danger"><?php echo number_format($utilization_rate, 1); ?>%</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center py-4">
                        <i class="fas fa-users fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No Patient Enrollment Data</h5>
                        <p class="text-muted">No patients are enrolled in any insurance schemes yet.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Report Footer -->
    <div class="card-footer bg-light">
        <div class="row">
            <div class="col-md-6">
                <small class="text-muted">
                    Report generated: <?php echo date('F j, Y \a\t g:i A'); ?>
                </small>
            </div>
            <div class="col-md-6 text-right">
                <small class="text-muted">
                    Data as of: <?php echo date('F j, Y'); ?>
                </small>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Print report styling
    $('button[onclick="window.print()"]').click(function() {
        // Add print-specific styling
        $('.card-header').addClass('d-print-block');
        $('.card-footer').addClass('d-print-block');
        $('.btn-group').addClass('d-print-none');
    });
});
</script>

<style>
@media print {
    .btn-group, .form-inline, .card-header .btn {
        display: none !important;
    }
    .card {
        border: none !important;
        box-shadow: none !important;
    }
    .card-header {
        background-color: #f8f9fa !important;
        color: #000 !important;
        border-bottom: 2px solid #dee2e6 !important;
    }
    .card-footer {
        border-top: 2px solid #dee2e6 !important;
    }
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>