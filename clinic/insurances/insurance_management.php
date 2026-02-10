<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Default Column Sortby/Order Filter
$sort = "c.created_at";
$order = "DESC";

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Filter parameters
$status_filter = $_GET['status'] ?? '';
$company_filter = $_GET['company'] ?? '';
$type_filter = $_GET['type'] ?? '';

// Search Query
$q = sanitizeInput($_GET['q'] ?? '');
if (!empty($q)) {
    $search_query = "AND (
        c.company_name LIKE '%$q%' 
        OR c.company_code LIKE '%$q%'
        OR c.contact_person LIKE '%$q%'
        OR c.email LIKE '%$q%'
        OR c.phone LIKE '%$q%'
    )";
} else {
    $search_query = '';
}

// Status Filter
if ($status_filter) {
    $status_query = "AND c.is_active = '" . ($status_filter == 'active' ? '1' : '0') . "'";
} else {
    $status_query = '';
}

// Get statistics
$stats_sql = "SELECT 
    COUNT(*) as total_companies,
    SUM(c.is_active) as active_companies,
    COUNT(*) - SUM(c.is_active) as inactive_companies,
    (SELECT COUNT(*) FROM insurance_schemes WHERE is_active = 1) as active_schemes,
    (SELECT COUNT(*) FROM insurance_schemes) as total_schemes,
    (SELECT COUNT(DISTINCT scheme_type) FROM insurance_schemes) as scheme_types,
    (SELECT COUNT(DISTINCT insurance_company_id) FROM insurance_schemes WHERE is_active = 1) as companies_with_schemes
    FROM insurance_companies c
    WHERE 1=1
    $status_query
    $search_query";

$stats_result = $mysqli->query($stats_sql);
$stats = $stats_result->fetch_assoc();

// Get coverage statistics
$coverage_stats_sql = "SELECT 
    SUM(outpatient_cover) as outpatient_count,
    SUM(inpatient_cover) as inpatient_count,
    SUM(maternity_cover) as maternity_count,
    SUM(dental_cover) as dental_count,
    SUM(optical_cover) as optical_count,
    SUM(requires_preauthorization) as preauth_count
    FROM insurance_schemes
    WHERE is_active = 1";

$coverage_stats_result = $mysqli->query($coverage_stats_sql);
$coverage_stats = $coverage_stats_result->fetch_assoc();

// Main query for insurance companies
$sql = mysqli_query(
    $mysqli,
    "
    SELECT SQL_CALC_FOUND_ROWS c.*,
           creator.user_name as created_by_name,
           COUNT(s.scheme_id) as scheme_count,
           SUM(CASE WHEN s.is_active = 1 THEN 1 ELSE 0 END) as active_scheme_count,
           MAX(s.created_at) as latest_scheme_date
    FROM insurance_companies c
    LEFT JOIN insurance_schemes s ON c.insurance_company_id = s.insurance_company_id
    LEFT JOIN users creator ON c.created_by = creator.user_id
    WHERE 1=1
      $status_query
      $search_query
    GROUP BY c.insurance_company_id
    ORDER BY $sort $order
    LIMIT $record_from, $record_to
");

if (!$sql) {
    die("Query failed: " . mysqli_error($mysqli));
}

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Get recent schemes
$recent_schemes_sql = "
    SELECT s.*, c.company_name, c.company_code
    FROM insurance_schemes s
    JOIN insurance_companies c ON s.insurance_company_id = c.insurance_company_id
    WHERE s.is_active = 1
    ORDER BY s.created_at DESC
    LIMIT 5
";
$recent_schemes_result = $mysqli->query($recent_schemes_sql);
?>

<div class="card">
    <div class="card-header bg-dark py-2">
        <h3 class="card-title mt-2 mb-0 text-white"><i class="fas fa-fw fa-hospital mr-2"></i>Insurance Companies Management</h3>
        <div class="card-tools">
            <div class="btn-group">
                <a href="insurance_company_new.php" class="btn btn-success">
                    <i class="fas fa-plus mr-2"></i>New Company
                </a>
                <a href="insurance_scheme_new.php" class="btn btn-primary ml-2">
                    <i class="fas fa-file-medical mr-2"></i>New Scheme
                </a>
                <a href="insurance_reports.php" class="btn btn-info ml-2">
                    <i class="fas fa-chart-bar mr-2"></i>Reports
                </a>
                <div class="btn-group ml-2">
                    <button type="button" class="btn btn-secondary dropdown-toggle" data-toggle="dropdown">
                        <i class="fas fa-tasks mr-2"></i>Quick Actions
                    </button>
                    <div class="dropdown-menu">
                        <a class="dropdown-item" href="insurance_schemes.php">
                            <i class="fas fa-list mr-2"></i>All Schemes
                        </a>
                        <a class="dropdown-item" href="insurance_types.php">
                            <i class="fas fa-tags mr-2"></i>Scheme Types
                        </a>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item" href="insurance_import.php">
                            <i class="fas fa-file-import mr-2"></i>Import Data
                        </a>
                        <a class="dropdown-item" href="insurance_export.php">
                            <i class="fas fa-file-export mr-2"></i>Export Data
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Alert Row -->
    <?php if ($stats['inactive_companies'] > 0 || $stats['active_schemes'] == 0): ?>
    <div class="row mt-3">
        <div class="col-12">
            <div class="alert-container">
                <?php if ($stats['inactive_companies'] > 0): ?>
                <div class="alert alert-warning alert-dismissible fade show mb-2" role="alert">
                    <i class="fas fa-exclamation-triangle mr-2"></i>
                    <strong><?php echo $stats['inactive_companies']; ?> company(s)</strong> are inactive.
                    <a href="?status=inactive" class="alert-link ml-2">View Inactive Companies</a>
                    <button type="button" class="close" data-dismiss="alert">
                        <span>&times;</span>
                    </button>
                </div>
                <?php endif; ?>
                
                <?php if ($stats['active_schemes'] == 0): ?>
                <div class="alert alert-danger alert-dismissible fade show mb-2" role="alert">
                    <i class="fas fa-exclamation-circle mr-2"></i>
                    <strong>No active insurance schemes found.</strong>
                    <a href="insurance_scheme_new.php" class="alert-link ml-2">Add New Scheme</a>
                    <button type="button" class="close" data-dismiss="alert">
                        <span>&times;</span>
                    </button>
                </div>
                <?php endif; ?>
                
                <?php if ($coverage_stats['preauth_count'] > 0): ?>
                <div class="alert alert-info alert-dismissible fade show mb-2" role="alert">
                    <i class="fas fa-clipboard-check mr-2"></i>
                    <strong><?php echo $coverage_stats['preauth_count']; ?> scheme(s)</strong> require pre-authorization.
                    <button type="button" class="close" data-dismiss="alert">
                        <span>&times;</span>
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="card-header pb-2 pt-3">
        <form autocomplete="off">
            <div class="row">
                <div class="col-md-5">
                    <div class="form-group">
                        <div class="input-group">
                            <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search companies, codes, contacts..." autofocus>
                            <div class="input-group-append">
                                <button class="btn btn-secondary" type="button" data-toggle="collapse" data-target="#advancedFilter"><i class="fas fa-filter"></i></button>
                                <button class="btn btn-primary"><i class="fa fa-search"></i></button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-7">
                    <div class="btn-toolbar form-group float-right">
                        <div class="btn-group">
                            <span class="btn btn-light border" data-toggle="tooltip" title="Total Companies">
                                <i class="fas fa-hospital text-primary mr-1"></i>
                                <strong><?php echo $stats['total_companies'] ?? 0; ?></strong>
                            </span>
                            <span class="btn btn-light border" data-toggle="tooltip" title="Active Companies">
                                <i class="fas fa-check-circle text-success mr-1"></i>
                                <strong><?php echo $stats['active_companies'] ?? 0; ?></strong>
                            </span>
                            <span class="btn btn-light border" data-toggle="tooltip" title="Total Schemes">
                                <i class="fas fa-file-medical text-info mr-1"></i>
                                <strong><?php echo $stats['total_schemes'] ?? 0; ?></strong>
                            </span>
                            <span class="btn btn-light border" data-toggle="tooltip" title="Active Schemes">
                                <i class="fas fa-shield-alt text-warning mr-1"></i>
                                <strong><?php echo $stats['active_schemes'] ?? 0; ?></strong>
                            </span>
                            <a href="insurance_company_new.php" class="btn btn-success ml-2">
                                <i class="fas fa-fw fa-plus mr-2"></i>Add Company
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse <?php if ($status_filter) { echo "show"; } ?>" id="advancedFilter">
                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Status</label>
                            <select class="form-control select2" name="status" onchange="this.form.submit()">
                                <option value="">- All Status -</option>
                                <option value="active" <?php if ($status_filter == "active") { echo "selected"; } ?>>Active</option>
                                <option value="inactive" <?php if ($status_filter == "inactive") { echo "selected"; } ?>>Inactive</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-8">
                        <div class="form-group">
                            <label>Quick Actions</label>
                            <div class="btn-group btn-block">
                                <a href="insurance_management.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-times mr-2"></i>Clear Filters
                                </a>
                                <a href="insurance_company_new.php" class="btn btn-success">
                                    <i class="fas fa-plus mr-2"></i>Add Company
                                </a>
                                <a href="insurance_scheme_new.php" class="btn btn-primary">
                                    <i class="fas fa-file-medical mr-2"></i>Add Scheme
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row mt-2">
                    <div class="col-md-12">
                        <div class="form-group">
                            <label>Quick Filters</label>
                            <div class="btn-group btn-group-toggle" data-toggle="buttons">
                                <a href="?status=active" class="btn btn-outline-success btn-sm <?php echo $status_filter == 'active' ? 'active' : ''; ?>">
                                    <i class="fas fa-check-circle mr-1"></i> Active Companies
                                </a>
                                <a href="?status=inactive" class="btn btn-outline-warning btn-sm <?php echo $status_filter == 'inactive' ? 'active' : ''; ?>">
                                    <i class="fas fa-pause-circle mr-1"></i> Inactive Companies
                                </a>
                                <a href="insurance_schemes.php" class="btn btn-outline-info btn-sm">
                                    <i class="fas fa-list mr-1"></i> View All Schemes
                                </a>
                                <a href="insurance_reports.php" class="btn btn-outline-dark btn-sm">
                                    <i class="fas fa-chart-bar mr-1"></i> View Reports
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
    
    <div class="table-responsive-sm">
        <table class="table table-hover mb-0">
            <thead class="<?php if ($num_rows[0] == 0) { echo "d-none"; } ?> bg-light">
            <tr>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=c.company_name&order=<?php echo $disp; ?>">
                        Company Name <?php if ($sort == 'c.company_name') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=c.company_code&order=<?php echo $disp; ?>">
                        Code <?php if ($sort == 'c.company_code') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>Contact Information</th>
                <th class="text-center">
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=scheme_count&order=<?php echo $disp; ?>">
                        Schemes <?php if ($sort == 'scheme_count') { echo $order_icon; } ?>
                    </a>
                </th>
                <th class="text-center">
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=c.is_active&order=<?php echo $disp; ?>">
                        Status <?php if ($sort == 'c.is_active') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=c.created_at&order=<?php echo $disp; ?>">
                        Created <?php if ($sort == 'c.created_at') { echo $order_icon; } ?>
                    </a>
                </th>
                <th class="text-center">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php 
            if ($num_rows[0] == 0) {
                ?>
                <tr>
                    <td colspan="7" class="text-center py-5">
                        <i class="fas fa-hospital fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No insurance companies found</h5>
                        <p class="text-muted">
                            <?php 
                            if ($q || $status_filter) {
                                echo "Try adjusting your search or filter criteria.";
                            } else {
                                echo "Get started by adding your first insurance company.";
                            }
                            ?>
                        </p>
                        <a href="insurance_company_new.php" class="btn btn-primary">
                            <i class="fas fa-plus mr-2"></i>Add First Company
                        </a>
                        <?php if ($q || $status_filter): ?>
                            <a href="insurance_management.php" class="btn btn-secondary ml-2">
                                <i class="fas fa-times mr-2"></i>Clear Filters
                            </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php
            } else {
                while ($row = mysqli_fetch_array($sql)) {
                    $company_id = intval($row['insurance_company_id']);
                    $company_name = nullable_htmlentities($row['company_name']);
                    $company_code = nullable_htmlentities($row['company_code']);
                    $contact_person = nullable_htmlentities($row['contact_person']);
                    $phone = nullable_htmlentities($row['phone']);
                    $email = nullable_htmlentities($row['email']);
                    $address = nullable_htmlentities($row['address']);
                    $is_active = boolval($row['is_active'] ?? 0);
                    $scheme_count = intval($row['scheme_count'] ?? 0);
                    $active_scheme_count = intval($row['active_scheme_count'] ?? 0);
                    $created_at = nullable_htmlentities($row['created_at']);
                    $created_by = nullable_htmlentities($row['created_by_name']);
                    $latest_scheme_date = nullable_htmlentities($row['latest_scheme_date']);

                    // Status badge styling
                    $status_badge = $is_active ? "badge-success" : "badge-secondary";
                    $status_text = $is_active ? "Active" : "Inactive";
                    $status_icon = $is_active ? "fa-check-circle" : "fa-pause-circle";

                    // Scheme count indicator
                    $scheme_indicator = '';
                    if ($scheme_count > 0) {
                        if ($active_scheme_count == 0) {
                            $scheme_indicator = '<small class="text-danger d-block">No active schemes</small>';
                        } elseif ($active_scheme_count < $scheme_count) {
                            $scheme_indicator = '<small class="text-warning d-block">' . $active_scheme_count . ' active of ' . $scheme_count . '</small>';
                        } else {
                            $scheme_indicator = '<small class="text-success d-block">' . $scheme_count . ' active schemes</small>';
                        }
                    }
                    ?>
                    <tr>
                        <td>
                            <div class="font-weight-bold">
                                <a href="insurance_company_view.php?id=<?php echo $company_id; ?>" class="text-dark">
                                    <?php echo $company_name; ?>
                                </a>
                            </div>
                            <?php if (!empty($address)): ?>
                                <small class="text-muted"><?php echo strlen($address) > 50 ? substr($address, 0, 50) . '...' : $address; ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($company_code)): ?>
                                <span class="badge badge-info"><?php echo $company_code; ?></span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($contact_person)): ?>
                                <div class="font-weight-bold"><?php echo $contact_person; ?></div>
                            <?php endif; ?>
                            <?php if (!empty($phone)): ?>
                                <small class="text-muted d-block">
                                    <i class="fas fa-phone fa-xs mr-1"></i><?php echo $phone; ?>
                                </small>
                            <?php endif; ?>
                            <?php if (!empty($email)): ?>
                                <small class="text-muted d-block">
                                    <i class="fas fa-envelope fa-xs mr-1"></i><?php echo $email; ?>
                                </small>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($scheme_count > 0): ?>
                                <div class="font-weight-bold"><?php echo $scheme_count; ?></div>
                                <?php echo $scheme_indicator; ?>
                                <?php if ($latest_scheme_date): ?>
                                    <small class="text-muted d-block">
                                        Latest: <?php echo date('M j, Y', strtotime($latest_scheme_date)); ?>
                                    </small>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <span class="badge <?php echo $status_badge; ?> badge-pill">
                                <i class="fas <?php echo $status_icon; ?> mr-1"></i>
                                <?php echo $status_text; ?>
                            </span>
                            <?php if ($created_by): ?>
                                <small class="text-muted d-block">
                                    By: <?php echo $created_by; ?>
                                </small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="text-muted small">
                                <?php echo date('M j, Y', strtotime($created_at)); ?>
                            </div>
                            <?php if ($latest_scheme_date): ?>
                                <small class="text-info d-block">
                                    Updated: <?php echo date('M j, Y', strtotime($latest_scheme_date)); ?>
                                </small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="dropdown dropleft text-center">
                                <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                    <i class="fas fa-ellipsis-h"></i>
                                </button>
                                <div class="dropdown-menu">
                                    <a class="dropdown-item" href="insurance_company_view.php?id=<?php echo $company_id; ?>">
                                        <i class="fas fa-fw fa-eye mr-2"></i>View Details
                                    </a>
                                    <a class="dropdown-item" href="insurance_company_edit.php?id=<?php echo $company_id; ?>">
                                        <i class="fas fa-fw fa-edit mr-2"></i>Edit Company
                                    </a>
                                    <a class="dropdown-item" href="insurance_schemes.php?company=<?php echo $company_id; ?>">
                                        <i class="fas fa-fw fa-list mr-2"></i>View Schemes
                                    </a>
                                    <a class="dropdown-item" href="insurance_scheme_new.php?company=<?php echo $company_id; ?>">
                                        <i class="fas fa-fw fa-plus mr-2"></i>Add Scheme
                                    </a>
                                    <div class="dropdown-divider"></div>
                                    <?php if ($is_active): ?>
                                        <a class="dropdown-item text-warning" href="#" onclick="toggleStatus(<?php echo $company_id; ?>, 'company')">
                                            <i class="fas fa-fw fa-pause mr-2"></i>Deactivate
                                        </a>
                                    <?php else: ?>
                                        <a class="dropdown-item text-success" href="#" onclick="toggleStatus(<?php echo $company_id; ?>, 'company')">
                                            <i class="fas fa-fw fa-play mr-2"></i>Activate
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($scheme_count == 0): ?>
                                        <a class="dropdown-item text-danger" href="insurance_company_delete.php?id=<?php echo $company_id; ?>" onclick="return confirm('Are you sure you want to delete this company? This action cannot be undone.')">
                                            <i class="fas fa-fw fa-trash mr-2"></i>Delete
                                        </a>
                                    <?php else: ?>
                                        <span class="dropdown-item text-muted disabled" style="cursor: not-allowed;">
                                            <i class="fas fa-fw fa-trash mr-2"></i>Delete (has schemes)
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php
                }
            }
            ?>
            </tbody>
        </table>
    </div>
    
    <!-- Statistics Cards Row -->
    <div class="row mt-3 mx-2">
        <div class="col-md-3">
            <div class="card bg-primary text-white">
                <div class="card-body py-2">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="font-weight-bold mb-0"><?php echo $stats['total_companies'] ?? 0; ?></h4>
                            <small>Companies</small>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-hospital fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white">
                <div class="card-body py-2">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="font-weight-bold mb-0"><?php echo $stats['active_companies'] ?? 0; ?></h4>
                            <small>Active</small>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-check-circle fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-info text-white">
                <div class="card-body py-2">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h4 class="font-weight-bold mb-0"><?php echo $stats['active_schemes'] ?? 0; ?></h4>
                            <small>Active Schemes</small>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-shield-alt fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-warning text-white">
                <div class="card-body py-2">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h5 class="font-weight-bold mb-0">Coverage</h5>
                            <small class="d-block">OP: <?php echo $coverage_stats['outpatient_count']; ?></small>
                            <small class="d-block">IP: <?php echo $coverage_stats['inpatient_count']; ?></small>
                            <small class="d-block">Mat: <?php echo $coverage_stats['maternity_count']; ?></small>
                        </div>
                        <div class="align-self-center">
                            <i class="fas fa-heartbeat fa-2x"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Recent Schemes Section -->
    <?php if ($recent_schemes_result->num_rows > 0): ?>
    <div class="card mt-3 mx-2">
        <div class="card-header bg-light">
            <h5 class="card-title mb-0">
                <i class="fas fa-clock mr-2"></i>Recent Insurance Schemes
            </h5>
        </div>
        <div class="card-body p-0">
            <div class="list-group list-group-flush">
                <?php while($scheme = $recent_schemes_result->fetch_assoc()): ?>
                <a href="insurance_scheme_view.php?id=<?php echo $scheme['scheme_id']; ?>" class="list-group-item list-group-item-action">
                    <div class="d-flex w-100 justify-content-between">
                        <h6 class="mb-1"><?php echo htmlspecialchars($scheme['scheme_name']); ?></h6>
                        <small class="text-muted"><?php echo date('M j', strtotime($scheme['created_at'])); ?></small>
                    </div>
                    <p class="mb-1">
                        <span class="badge badge-light"><?php echo htmlspecialchars($scheme['company_name']); ?></span>
                        <span class="badge badge-info"><?php echo htmlspecialchars($scheme['scheme_type']); ?></span>
                        <?php if ($scheme['requires_preauthorization']): ?>
                            <span class="badge badge-warning">Pre-auth</span>
                        <?php endif; ?>
                    </p>
                    <small class="text-muted">
                        <?php 
                        $coverages = [];
                        if ($scheme['outpatient_cover']) $coverages[] = 'OP';
                        if ($scheme['inpatient_cover']) $coverages[] = 'IP';
                        if ($scheme['maternity_cover']) $coverages[] = 'Maternity';
                        echo implode(', ', $coverages);
                        ?>
                    </small>
                </a>
                <?php endwhile; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/filter_footer.php'; ?>
</div>

<script>
$(document).ready(function() {
    $('.select2').select2();
    $('[data-toggle="tooltip"]').tooltip();

    // Auto-submit when filters change
    $('select[name="status"]').change(function() {
        $(this).closest('form').submit();
    });

    // Quick filter buttons
    $('.btn-group-toggle .btn').click(function(e) {
        e.preventDefault();
        window.location.href = $(this).attr('href');
    });
});

function toggleStatus(id, type) {
    let url = type === 'company' ? 'ajax/insurance_company_toggle.php' : 'ajax/insurance_scheme_toggle.php';
    let itemName = type === 'company' ? 'company' : 'scheme';
    
    $.ajax({
        url: url,
        method: 'POST',
        data: { id: id },
        success: function(response) {
            location.reload();
        },
        error: function() {
            alert('Error updating status');
        }
    });
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + N for new company
    if (e.ctrlKey && e.keyCode === 78) {
        e.preventDefault();
        window.location.href = 'insurance_company_new.php';
    }
    // Ctrl + S for new scheme
    if (e.ctrlKey && e.keyCode === 83) {
        e.preventDefault();
        window.location.href = 'insurance_scheme_new.php';
    }
    // Ctrl + F for focus search
    if (e.ctrlKey && e.keyCode === 70) {
        e.preventDefault();
        $('input[name="q"]').focus();
    }
    // Ctrl + R for reports
    if (e.ctrlKey && e.keyCode === 82) {
        e.preventDefault();
        window.location.href = 'insurance_reports.php';
    }
});
</script>

<style>
.card .card-body {
    padding: 1rem;
}

.list-group-item {
    border: none;
    padding: 0.75rem 1rem;
}

.list-group-item:hover {
    background-color: #f8f9fa;
}

.badge-pill {
    padding: 0.5em 0.8em;
}

.btn-group-toggle .btn.active {
    background-color: #007bff;
    border-color: #007bff;
    color: white;
}

.alert-container .alert {
    margin-bottom: 0.5rem;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>