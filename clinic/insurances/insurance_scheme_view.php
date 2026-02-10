<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

$scheme_id = intval($_GET['id']);

// Get scheme data with company info
$scheme_sql = mysqli_query($mysqli, "
    SELECT s.*, c.company_name, c.company_code, c.contact_person, c.phone, c.email, 
           u.user_name as created_by_name 
    FROM insurance_schemes s
    LEFT JOIN insurance_companies c ON s.insurance_company_id = c.insurance_company_id
    LEFT JOIN users u ON s.created_by = u.user_id
    WHERE s.scheme_id = $scheme_id
");
if (mysqli_num_rows($scheme_sql) == 0) {
    header("Location: insurance_schemes.php");
    exit;
}
$scheme = mysqli_fetch_assoc($scheme_sql);

$usage_sql = mysqli_query($mysqli, "
    SELECT 
        COUNT(DISTINCT p.patient_id) AS total_patients,
        SUM(CASE WHEN p.patient_status = 'Active' THEN 1 ELSE 0 END) AS active_patients,
        SUM(CASE WHEN p.patient_status = 'Inactive' THEN 1 ELSE 0 END) AS inactive_patients
    FROM patients p
    INNER JOIN visit_insurance vi ON vi.patient_id = p.patient_id
    WHERE vi.insurance_scheme_id = $scheme_id
");
$usage_stats = mysqli_fetch_assoc($usage_sql);


// Get recent claims (example)
$recent_claims_sql = mysqli_query($mysqli, "
    SELECT 
        c.claim_id,
        c.claim_date,
        c.claim_amount,
        c.status,
        p.patient_first_name,
        p.patient_mrn
    FROM insurance_claims c
    LEFT JOIN patients p ON c.patient_id = p.patient_id
    WHERE c.scheme_id = $scheme_id
    ORDER BY c.claim_date DESC
    LIMIT 5
");
?>

<div class="card">
    <div class="card-header bg-dark py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-fw fa-file-medical mr-2"></i><?php echo htmlspecialchars($scheme['scheme_name']); ?>
        </h3>
        <div class="card-tools">
            <div class="btn-group">
                <a href="insurance_scheme_edit.php?id=<?php echo $scheme_id; ?>" class="btn btn-light">
                    <i class="fas fa-edit mr-2"></i>Edit
                </a>
                <a href="insurance_schemes.php" class="btn btn-secondary ml-2">
                    <i class="fas fa-arrow-left mr-2"></i>Back
                </a>
            </div>
        </div>
    </div>
    
    <div class="card-body">
        <!-- Scheme Details -->
        <div class="row mb-4">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">Scheme Information</h5>
                    </div>
                    <div class="card-body">
                        <dl class="row mb-0">
                            <dt class="col-sm-4">Scheme Code:</dt>
                            <dd class="col-sm-8">
                                <?php if (!empty($scheme['scheme_code'])): ?>
                                    <span class="badge badge-info"><?php echo htmlspecialchars($scheme['scheme_code']); ?></span>
                                <?php else: ?>
                                    <span class="text-muted">Not set</span>
                                <?php endif; ?>
                            </dd>
                            
                            <dt class="col-sm-4">Insurance Company:</dt>
                            <dd class="col-sm-8">
                                <a href="insurance_company_view.php?id=<?php echo $scheme['insurance_company_id']; ?>" class="text-primary">
                                    <?php echo htmlspecialchars($scheme['company_name']); ?>
                                </a>
                                <?php if (!empty($scheme['company_code'])): ?>
                                    <span class="text-muted">(<?php echo htmlspecialchars($scheme['company_code']); ?>)</span>
                                <?php endif; ?>
                            </dd>
                            
                            <dt class="col-sm-4">Scheme Type:</dt>
                            <dd class="col-sm-8">
                                <span class="badge badge-info"><?php echo htmlspecialchars($scheme['scheme_type']); ?></span>
                            </dd>
                            
                            <dt class="col-sm-4">Annual Limit:</dt>
                            <dd class="col-sm-8">
                                <?php if ($scheme['annual_limit']): ?>
                                    <span class="font-weight-bold">$<?php echo number_format($scheme['annual_limit'], 2); ?></span>
                                <?php else: ?>
                                    <span class="text-muted">Unlimited</span>
                                <?php endif; ?>
                            </dd>
                            
                            <dt class="col-sm-4">Status:</dt>
                            <dd class="col-sm-8">
                                <span class="badge badge-<?php echo $scheme['is_active'] ? 'success' : 'secondary'; ?>">
                                    <?php echo $scheme['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </dd>
                            
                            <dt class="col-sm-4">Pre-authorization:</dt>
                            <dd class="col-sm-8">
                                <?php if ($scheme['requires_preauthorization']): ?>
                                    <span class="badge badge-danger">Required</span>
                                <?php else: ?>
                                    <span class="badge badge-success">Not Required</span>
                                <?php endif; ?>
                            </dd>
                            
                            <?php if (!empty($scheme['coverage_description'])): ?>
                            <dt class="col-sm-4">Coverage Description:</dt>
                            <dd class="col-sm-8"><?php echo nl2br(htmlspecialchars($scheme['coverage_description'])); ?></dd>
                            <?php endif; ?>
                            
                            <dt class="col-sm-4">Created:</dt>
                            <dd class="col-sm-8">
                                <?php echo date('F j, Y', strtotime($scheme['created_at'])); ?>
                                <?php if ($scheme['created_by_name']): ?>
                                    <span class="text-muted">by <?php echo htmlspecialchars($scheme['created_by_name']); ?></span>
                                <?php endif; ?>
                            </dd>
                        </dl>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <!-- Coverage Summary -->
                <div class="card bg-primary text-white mb-3">
                    <div class="card-body">
                        <h6 class="card-title mb-3">Coverage Summary</h6>
                        <div class="row">
                            <div class="col-6">
                                <div class="text-center mb-3">
                                    <?php if ($scheme['outpatient_cover']): ?>
                                        <i class="fas fa-stethoscope fa-2x"></i>
                                        <div class="small mt-1">Outpatient</div>
                                        <span class="badge badge-success">Covered</span>
                                    <?php else: ?>
                                        <i class="fas fa-stethoscope fa-2x text-light"></i>
                                        <div class="small mt-1">Outpatient</div>
                                        <span class="badge badge-light text-dark">Not Covered</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="text-center mb-3">
                                    <?php if ($scheme['inpatient_cover']): ?>
                                        <i class="fas fa-procedures fa-2x"></i>
                                        <div class="small mt-1">Inpatient</div>
                                        <span class="badge badge-success">Covered</span>
                                    <?php else: ?>
                                        <i class="fas fa-procedures fa-2x text-light"></i>
                                        <div class="small mt-1">Inpatient</div>
                                        <span class="badge badge-light text-dark">Not Covered</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="text-center">
                                    <?php if ($scheme['maternity_cover']): ?>
                                        <i class="fas fa-baby fa-2x"></i>
                                        <div class="small mt-1">Maternity</div>
                                        <span class="badge badge-success">Covered</span>
                                    <?php else: ?>
                                        <i class="fas fa-baby fa-2x text-light"></i>
                                        <div class="small mt-1">Maternity</div>
                                        <span class="badge badge-light text-dark">Not Covered</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="text-center">
                                    <?php if ($scheme['dental_cover'] || $scheme['optical_cover']): ?>
                                        <i class="fas fa-plus-square fa-2x"></i>
                                        <div class="small mt-1">
                                            <?php if ($scheme['dental_cover']) echo 'Dental '; ?>
                                            <?php if ($scheme['optical_cover']) echo 'Optical'; ?>
                                        </div>
                                        <span class="badge badge-success">Covered</span>
                                    <?php else: ?>
                                        <i class="fas fa-plus-square fa-2x text-light"></i>
                                        <div class="small mt-1">Additional</div>
                                        <span class="badge badge-light text-dark">Not Covered</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Quick Stats -->
                <div class="card bg-success text-white mb-3">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="font-weight-bold mb-0"><?php echo $usage_stats['total_patients'] ?? 0; ?></h2>
                                <small>Enrolled Patients</small>
                            </div>
                            <i class="fas fa-users fa-3x"></i>
                        </div>
                    </div>
                </div>
                
                <div class="card bg-info text-white">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="font-weight-bold mb-0"><?php echo $usage_stats['active_patients'] ?? 0; ?></h2>
                                <small>Active Patients</small>
                            </div>
                            <i class="fas fa-user-check fa-3x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Recent Claims Section -->
        <div class="card">
            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">Recent Claims</h5>
                <a href="insurance_claims.php?scheme=<?php echo $scheme_id; ?>" class="btn btn-sm btn-primary">
                    <i class="fas fa-list mr-1"></i>View All Claims
                </a>
            </div>
            <div class="card-body p-0">
                <?php if (mysqli_num_rows($recent_claims_sql) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Claim ID</th>
                                    <th>Patient</th>
                                    <th>Date</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($claim = mysqli_fetch_assoc($recent_claims_sql)): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($claim['claim_id']); ?></td>
                                    <td>
                                        <div class="font-weight-bold"><?php echo htmlspecialchars($claim['patient_name']); ?></div>
                                        <small class="text-muted"><?php echo htmlspecialchars($claim['patient_number']); ?></small>
                                    </td>
                                    <td><?php echo date('M j, Y', strtotime($claim['claim_date'])); ?></td>
                                    <td>$<?php echo number_format($claim['claim_amount'], 2); ?></td>
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
                                    <td class="text-center">
                                        <a href="insurance_claim_view.php?id=<?php echo $claim['claim_id']; ?>" class="btn btn-sm btn-secondary">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center py-4">
                        <i class="fas fa-file-invoice-dollar fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No Recent Claims</h5>
                        <p class="text-muted">No claims have been submitted for this scheme yet.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Enrolled Patients Section -->
        <div class="card mt-3">
            <div class="card-header bg-light">
                <h5 class="card-title mb-0">Enrolled Patients</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <div class="card bg-light mb-3">
                            <div class="card-body text-center">
                                <h2 class="font-weight-bold text-primary"><?php echo $usage_stats['total_patients'] ?? 0; ?></h2>
                                <small class="text-muted">Total Enrolled</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card bg-light mb-3">
                            <div class="card-body text-center">
                                <h2 class="font-weight-bold text-success"><?php echo $usage_stats['active_patients'] ?? 0; ?></h2>
                                <small class="text-muted">Active Patients</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card bg-light mb-3">
                            <div class="card-body text-center">
                                <h2 class="font-weight-bold text-warning"><?php echo $usage_stats['inactive_patients'] ?? 0; ?></h2>
                                <small class="text-muted">Inactive Patients</small>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="text-center mt-3">
                    <a href="patients.php?scheme=<?php echo $scheme_id; ?>" class="btn btn-primary">
                        <i class="fas fa-users mr-2"></i>View All Patients
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>