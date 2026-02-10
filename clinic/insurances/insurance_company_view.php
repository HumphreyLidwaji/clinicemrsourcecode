<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

$company_id = intval($_GET['id']);

// Get company data
$company_sql = mysqli_query($mysqli, "
    SELECT c.*, u.user_name as created_by_name 
    FROM insurance_companies c
    LEFT JOIN users u ON c.created_by = u.user_id
    WHERE c.insurance_company_id = $company_id
");
if (mysqli_num_rows($company_sql) == 0) {
    header("Location: insurance_management.php");
    exit;
}
$company = mysqli_fetch_assoc($company_sql);

// Get company schemes
$schemes_sql = mysqli_query($mysqli, "
    SELECT * FROM insurance_schemes 
    WHERE insurance_company_id = $company_id 
    ORDER BY is_active DESC, scheme_name
");

// Get scheme statistics
$scheme_stats_sql = mysqli_query($mysqli, "
    SELECT 
        COUNT(*) as total_schemes,
        SUM(is_active) as active_schemes,
        SUM(outpatient_cover) as outpatient_count,
        SUM(inpatient_cover) as inpatient_count,
        SUM(maternity_cover) as maternity_count,
        SUM(dental_cover) as dental_count,
        SUM(optical_cover) as optical_count,
        SUM(requires_preauthorization) as preauth_count
    FROM insurance_schemes 
    WHERE insurance_company_id = $company_id
");
$scheme_stats = mysqli_fetch_assoc($scheme_stats_sql);
?>

<div class="card">
    <div class="card-header bg-dark py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-fw fa-hospital mr-2"></i><?php echo htmlspecialchars($company['company_name']); ?>
        </h3>
        <div class="card-tools">
            <div class="btn-group">
                <a href="insurance_company_edit.php?id=<?php echo $company_id; ?>" class="btn btn-light">
                    <i class="fas fa-edit mr-2"></i>Edit
                </a>
                <a href="insurance_scheme_new.php?company=<?php echo $company_id; ?>" class="btn btn-primary ml-2">
                    <i class="fas fa-plus mr-2"></i>Add Scheme
                </a>
                <a href="insurance_management.php" class="btn btn-secondary ml-2">
                    <i class="fas fa-arrow-left mr-2"></i>Back
                </a>
            </div>
        </div>
    </div>
    
    <div class="card-body">
        <!-- Company Details -->
        <div class="row mb-4">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">Company Information</h5>
                    </div>
                    <div class="card-body">
                        <dl class="row mb-0">
                            <dt class="col-sm-3">Company Code:</dt>
                            <dd class="col-sm-9">
                                <?php if (!empty($company['company_code'])): ?>
                                    <span class="badge badge-info"><?php echo htmlspecialchars($company['company_code']); ?></span>
                                <?php else: ?>
                                    <span class="text-muted">Not set</span>
                                <?php endif; ?>
                            </dd>
                            
                            <dt class="col-sm-3">Contact Person:</dt>
                            <dd class="col-sm-9"><?php echo htmlspecialchars($company['contact_person'] ?: '—'); ?></dd>
                            
                            <dt class="col-sm-3">Phone:</dt>
                            <dd class="col-sm-9"><?php echo htmlspecialchars($company['phone'] ?: '—'); ?></dd>
                            
                            <dt class="col-sm-3">Email:</dt>
                            <dd class="col-sm-9"><?php echo htmlspecialchars($company['email'] ?: '—'); ?></dd>
                            
                            <dt class="col-sm-3">Status:</dt>
                            <dd class="col-sm-9">
                                <span class="badge badge-<?php echo $company['is_active'] ? 'success' : 'secondary'; ?>">
                                    <?php echo $company['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </dd>
                            
                            <?php if (!empty($company['address'])): ?>
                            <dt class="col-sm-3">Address:</dt>
                            <dd class="col-sm-9"><?php echo nl2br(htmlspecialchars($company['address'])); ?></dd>
                            <?php endif; ?>
                            
                            <dt class="col-sm-3">Created:</dt>
                            <dd class="col-sm-9">
                                <?php echo date('F j, Y', strtotime($company['created_at'])); ?>
                                <?php if ($company['created_by_name']): ?>
                                    <span class="text-muted">by <?php echo htmlspecialchars($company['created_by_name']); ?></span>
                                <?php endif; ?>
                            </dd>
                        </dl>
                    </div>
                </div>
            </div>
            
            <div class="col-md-4">
                <!-- Quick Stats -->
                <div class="card bg-primary text-white mb-3">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="font-weight-bold mb-0"><?php echo $scheme_stats['total_schemes'] ?? 0; ?></h2>
                                <small>Total Schemes</small>
                            </div>
                            <i class="fas fa-file-medical fa-3x"></i>
                        </div>
                    </div>
                </div>
                
                <div class="card bg-success text-white mb-3">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h2 class="font-weight-bold mb-0"><?php echo $scheme_stats['active_schemes'] ?? 0; ?></h2>
                                <small>Active Schemes</small>
                            </div>
                            <i class="fas fa-check-circle fa-3x"></i>
                        </div>
                    </div>
                </div>
                
                <div class="card bg-info text-white">
                    <div class="card-body">
                        <h6 class="card-title">Coverage Summary</h6>
                        <div class="row small">
                            <div class="col-6">
                                <div><i class="fas fa-stethoscope mr-1"></i> OP: <?php echo $scheme_stats['outpatient_count'] ?? 0; ?></div>
                                <div><i class="fas fa-procedures mr-1"></i> IP: <?php echo $scheme_stats['inpatient_count'] ?? 0; ?></div>
                            </div>
                            <div class="col-6">
                                <div><i class="fas fa-baby mr-1"></i> Mat: <?php echo $scheme_stats['maternity_count'] ?? 0; ?></div>
                                <div><i class="fas fa-tooth mr-1"></i> Den: <?php echo $scheme_stats['dental_count'] ?? 0; ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Schemes Section -->
        <div class="card">
            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">Insurance Schemes</h5>
                <a href="insurance_scheme_new.php?company=<?php echo $company_id; ?>" class="btn btn-sm btn-primary">
                    <i class="fas fa-plus mr-1"></i>Add Scheme
                </a>
            </div>
            <div class="card-body p-0">
                <?php if (mysqli_num_rows($schemes_sql) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Scheme Name</th>
                                    <th>Code</th>
                                    <th>Type</th>
                                    <th class="text-center">Coverage</th>
                                    <th class="text-center">Annual Limit</th>
                                    <th class="text-center">Status</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($scheme = mysqli_fetch_assoc($schemes_sql)): ?>
                                <tr>
                                    <td>
                                        <div class="font-weight-bold"><?php echo htmlspecialchars($scheme['scheme_name']); ?></div>
                                        <?php if (!empty($scheme['coverage_description'])): ?>
                                            <small class="text-muted"><?php echo substr(htmlspecialchars($scheme['coverage_description']), 0, 50) . '...'; ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($scheme['scheme_code'])): ?>
                                            <span class="badge badge-light"><?php echo htmlspecialchars($scheme['scheme_code']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge badge-info"><?php echo htmlspecialchars($scheme['scheme_type']); ?></span>
                                    </td>
                                    <td class="text-center">
                                        <div class="d-flex flex-wrap justify-content-center gap-1">
                                            <?php if ($scheme['outpatient_cover']): ?>
                                                <span class="badge badge-success">OP</span>
                                            <?php endif; ?>
                                            <?php if ($scheme['inpatient_cover']): ?>
                                                <span class="badge badge-primary">IP</span>
                                            <?php endif; ?>
                                            <?php if ($scheme['maternity_cover']): ?>
                                                <span class="badge badge-info">MAT</span>
                                            <?php endif; ?>
                                            <?php if ($scheme['dental_cover']): ?>
                                                <span class="badge badge-warning">DEN</span>
                                            <?php endif; ?>
                                            <?php if ($scheme['optical_cover']): ?>
                                                <span class="badge badge-secondary">OPT</span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($scheme['requires_preauthorization']): ?>
                                            <div class="mt-1">
                                                <span class="badge badge-danger">Pre-auth</span>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($scheme['annual_limit']): ?>
                                            <span class="font-weight-bold">$<?php echo number_format($scheme['annual_limit'], 2); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge badge-<?php echo $scheme['is_active'] ? 'success' : 'secondary'; ?>">
                                            <?php echo $scheme['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group">
                                            <a href="insurance_scheme_view.php?id=<?php echo $scheme['scheme_id']; ?>" class="btn btn-sm btn-secondary">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="insurance_scheme_edit.php?id=<?php echo $scheme['scheme_id']; ?>" class="btn btn-sm btn-primary">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center py-4">
                        <i class="fas fa-file-medical fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No Insurance Schemes</h5>
                        <p class="text-muted">This company doesn't have any insurance schemes yet.</p>
                        <a href="insurance_scheme_new.php?company=<?php echo $company_id; ?>" class="btn btn-primary">
                            <i class="fas fa-plus mr-2"></i>Add First Scheme
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>