<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Default Column Sortby/Order Filter
$sort = "s.created_at";
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
        s.scheme_name LIKE '%$q%' 
        OR s.scheme_code LIKE '%$q%'
        OR c.company_name LIKE '%$q%'
        OR s.coverage_description LIKE '%$q%'
    )";
} else {
    $search_query = '';
}

// Status Filter
if ($status_filter) {
    $status_query = "AND s.is_active = '" . ($status_filter == 'active' ? '1' : '0') . "'";
} else {
    $status_query = '';
}

// Company Filter
if ($company_filter) {
    $company_query = "AND s.insurance_company_id = " . intval($company_filter);
} else {
    $company_query = '';
}

// Type Filter
if ($type_filter) {
    $type_query = "AND s.scheme_type = '" . sanitizeInput($type_filter) . "'";
} else {
    $type_query = '';
}

// Get statistics
$stats_sql = "SELECT 
    COUNT(*) as total_schemes,
    SUM(s.is_active) as active_schemes,
    SUM(s.outpatient_cover) as outpatient_count,
    SUM(s.inpatient_cover) as inpatient_count,
    SUM(s.maternity_cover) as maternity_count,
    SUM(s.dental_cover) as dental_count,
    SUM(s.optical_cover) as optical_count,
    SUM(s.requires_preauthorization) as preauth_count,
    COUNT(DISTINCT s.scheme_type) as scheme_types,
    COUNT(DISTINCT s.insurance_company_id) as companies_with_schemes
    FROM insurance_schemes s
    WHERE 1=1
    $status_query
    $company_query
    $type_query
    $search_query";

$stats_result = $mysqli->query($stats_sql);
$stats = $stats_result->fetch_assoc();

// Main query for insurance schemes
$sql = mysqli_query(
    $mysqli,
    "
    SELECT SQL_CALC_FOUND_ROWS s.*,
           c.company_name,
           c.company_code,
           creator.user_name as created_by_name
    FROM insurance_schemes s
    LEFT JOIN insurance_companies c ON s.insurance_company_id = c.insurance_company_id
    LEFT JOIN users creator ON s.created_by = creator.user_id
    WHERE 1=1
      $status_query
      $company_query
      $type_query
      $search_query
    ORDER BY $sort $order
    LIMIT $record_from, $record_to
");

if (!$sql) {
    die("Query failed: " . mysqli_error($mysqli));
}

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Get companies for filter
$companies_sql = "SELECT * FROM insurance_companies ORDER BY company_name";
$companies_result = $mysqli->query($companies_sql);

// Get scheme types
$types_sql = "SELECT DISTINCT scheme_type FROM insurance_schemes ORDER BY scheme_type";
$types_result = $mysqli->query($types_sql);
?>

<div class="card">
    <div class="card-header bg-dark py-2">
        <h3 class="card-title mt-2 mb-0 text-white"><i class="fas fa-fw fa-file-medical mr-2"></i>Insurance Schemes Management</h3>
        <div class="card-tools">
            <div class="btn-group">
                <a href="insurance_scheme_new.php" class="btn btn-primary">
                    <i class="fas fa-plus mr-2"></i>New Scheme
                </a>
                <a href="insurance_management.php" class="btn btn-light ml-2">
                    <i class="fas fa-hospital mr-2"></i>Companies
                </a>
                <a href="insurance_reports.php" class="btn btn-info ml-2">
                    <i class="fas fa-chart-bar mr-2"></i>Reports
                </a>
            </div>
        </div>
    </div>
    
    <div class="card-header pb-2 pt-3">
        <form autocomplete="off">
            <div class="row">
                <div class="col-md-5">
                    <div class="form-group">
                        <div class="input-group">
                            <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search schemes, companies, coverage..." autofocus>
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
                            <span class="btn btn-light border" data-toggle="tooltip" title="Total Schemes">
                                <i class="fas fa-file-medical text-primary mr-1"></i>
                                <strong><?php echo $stats['total_schemes'] ?? 0; ?></strong>
                            </span>
                            <span class="btn btn-light border" data-toggle="tooltip" title="Active Schemes">
                                <i class="fas fa-check-circle text-success mr-1"></i>
                                <strong><?php echo $stats['active_schemes'] ?? 0; ?></strong>
                            </span>
                            <span class="btn btn-light border" data-toggle="tooltip" title="Outpatient Coverage">
                                <i class="fas fa-stethoscope text-info mr-1"></i>
                                <strong><?php echo $stats['outpatient_count'] ?? 0; ?></strong>
                            </span>
                            <span class="btn btn-light border" data-toggle="tooltip" title="Inpatient Coverage">
                                <i class="fas fa-procedures text-warning mr-1"></i>
                                <strong><?php echo $stats['inpatient_count'] ?? 0; ?></strong>
                            </span>
                            <a href="insurance_scheme_new.php" class="btn btn-success ml-2">
                                <i class="fas fa-fw fa-plus mr-2"></i>Add Scheme
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse <?php if ($company_filter || $status_filter || $type_filter) { echo "show"; } ?>" id="advancedFilter">
                <div class="row">
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Status</label>
                            <select class="form-control select2" name="status" onchange="this.form.submit()">
                                <option value="">- All Status -</option>
                                <option value="active" <?php if ($status_filter == "active") { echo "selected"; } ?>>Active</option>
                                <option value="inactive" <?php if ($status_filter == "inactive") { echo "selected"; } ?>>Inactive</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Company</label>
                            <select class="form-control select2" name="company" onchange="this.form.submit()">
                                <option value="">- All Companies -</option>
                                <?php while($company = $companies_result->fetch_assoc()): ?>
                                    <option value="<?php echo $company['insurance_company_id']; ?>" <?php if ($company_filter == $company['insurance_company_id']) { echo "selected"; } ?>>
                                        <?php echo htmlspecialchars($company['company_name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Scheme Type</label>
                            <select class="form-control select2" name="type" onchange="this.form.submit()">
                                <option value="">- All Types -</option>
                                <?php while($type = $types_result->fetch_assoc()): ?>
                                    <option value="<?php echo $type['scheme_type']; ?>" <?php if ($type_filter == $type['scheme_type']) { echo "selected"; } ?>>
                                        <?php echo htmlspecialchars($type['scheme_type']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Quick Actions</label>
                            <div class="btn-group btn-block">
                                <a href="insurance_schemes.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-times mr-2"></i>Clear Filters
                                </a>
                                <a href="insurance_scheme_new.php" class="btn btn-primary">
                                    <i class="fas fa-plus mr-2"></i>Add Scheme
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
                <th>Scheme Name</th>
                <th>Company</th>
                <th class="text-center">Type</th>
                <th class="text-center">Coverage</th>
                <th class="text-center">Annual Limit</th>
                <th class="text-center">Status</th>
                <th class="text-center">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php 
            if ($num_rows[0] == 0) {
                ?>
                <tr>
                    <td colspan="7" class="text-center py-5">
                        <i class="fas fa-file-medical fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No insurance schemes found</h5>
                        <p class="text-muted">
                            <?php 
                            if ($q || $company_filter || $status_filter || $type_filter) {
                                echo "Try adjusting your search or filter criteria.";
                            } else {
                                echo "Get started by adding your first insurance scheme.";
                            }
                            ?>
                        </p>
                        <a href="insurance_scheme_new.php" class="btn btn-primary">
                            <i class="fas fa-plus mr-2"></i>Add First Scheme
                        </a>
                        <?php if ($q || $company_filter || $status_filter || $type_filter): ?>
                            <a href="insurance_schemes.php" class="btn btn-secondary ml-2">
                                <i class="fas fa-times mr-2"></i>Clear Filters
                            </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php
            } else {
                while ($row = mysqli_fetch_array($sql)) {
                    $scheme_id = intval($row['scheme_id']);
                    $scheme_name = nullable_htmlentities($row['scheme_name']);
                    $scheme_code = nullable_htmlentities($row['scheme_code']);
                    $scheme_type = nullable_htmlentities($row['scheme_type']);
                    $company_name = nullable_htmlentities($row['company_name']);
                    $company_code = nullable_htmlentities($row['company_code']);
                    $coverage_description = nullable_htmlentities($row['coverage_description']);
                    $annual_limit = floatval($row['annual_limit'] ?? 0);
                    $is_active = boolval($row['is_active'] ?? 0);
                    $outpatient_cover = boolval($row['outpatient_cover'] ?? 0);
                    $inpatient_cover = boolval($row['inpatient_cover'] ?? 0);
                    $maternity_cover = boolval($row['maternity_cover'] ?? 0);
                    $dental_cover = boolval($row['dental_cover'] ?? 0);
                    $optical_cover = boolval($row['optical_cover'] ?? 0);
                    $requires_preauthorization = boolval($row['requires_preauthorization'] ?? 0);
                    $created_at = nullable_htmlentities($row['created_at']);

                    // Status badge styling
                    $status_badge = $is_active ? "badge-success" : "badge-secondary";
                    $status_text = $is_active ? "Active" : "Inactive";
                    $status_icon = $is_active ? "fa-check-circle" : "fa-pause-circle";

                    // Type badge styling
                    $type_badge = "";
                    switch($scheme_type) {
                        case 'NHIF': $type_badge = "badge-primary"; break;
                        case 'SHA': $type_badge = "badge-info"; break;
                        case 'PRIVATE': $type_badge = "badge-success"; break;
                        case 'CORPORATE': $type_badge = "badge-warning"; break;
                        case 'INDIVIDUAL': $type_badge = "badge-secondary"; break;
                        default: $type_badge = "badge-light";
                    }
                    ?>
                    <tr>
                        <td>
                            <div class="font-weight-bold">
                                <a href="insurance_scheme_view.php?id=<?php echo $scheme_id; ?>" class="text-dark">
                                    <?php echo $scheme_name; ?>
                                </a>
                            </div>
                            <?php if (!empty($scheme_code)): ?>
                                <small class="text-muted">Code: <?php echo $scheme_code; ?></small>
                            <?php endif; ?>
                            <?php if (!empty($coverage_description)): ?>
                                <small class="text-muted d-block"><?php echo strlen($coverage_description) > 60 ? substr($coverage_description, 0, 60) . '...' : $coverage_description; ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="font-weight-bold"><?php echo $company_name; ?></div>
                            <?php if (!empty($company_code)): ?>
                                <small class="text-muted"><?php echo $company_code; ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <span class="badge <?php echo $type_badge; ?>">
                                <?php echo $scheme_type; ?>
                            </span>
                        </td>
                        <td class="text-center">
                            <div class="d-flex flex-wrap justify-content-center gap-1">
                                <?php if ($outpatient_cover): ?>
                                    <span class="badge badge-success" data-toggle="tooltip" title="Outpatient">OP</span>
                                <?php endif; ?>
                                <?php if ($inpatient_cover): ?>
                                    <span class="badge badge-primary" data-toggle="tooltip" title="Inpatient">IP</span>
                                <?php endif; ?>
                                <?php if ($maternity_cover): ?>
                                    <span class="badge badge-info" data-toggle="tooltip" title="Maternity">M</span>
                                <?php endif; ?>
                                <?php if ($dental_cover): ?>
                                    <span class="badge badge-warning" data-toggle="tooltip" title="Dental">D</span>
                                <?php endif; ?>
                                <?php if ($optical_cover): ?>
                                    <span class="badge badge-secondary" data-toggle="tooltip" title="Optical">O</span>
                                <?php endif; ?>
                            </div>
                            <?php if ($requires_preauthorization): ?>
                                <div class="mt-1">
                                    <span class="badge badge-danger" data-toggle="tooltip" title="Requires Pre-authorization">Pre-auth</span>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($annual_limit > 0): ?>
                                <div class="font-weight-bold">$<?php echo number_format($annual_limit, 2); ?></div>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <span class="badge <?php echo $status_badge; ?> badge-pill">
                                <i class="fas <?php echo $status_icon; ?> mr-1"></i>
                                <?php echo $status_text; ?>
                            </span>
                        </td>
                        <td>
                            <div class="dropdown dropleft text-center">
                                <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                    <i class="fas fa-ellipsis-h"></i>
                                </button>
                                <div class="dropdown-menu">
                                    <a class="dropdown-item" href="insurance_scheme_view.php?id=<?php echo $scheme_id; ?>">
                                        <i class="fas fa-fw fa-eye mr-2"></i>View Details
                                    </a>
                                    <a class="dropdown-item" href="insurance_scheme_edit.php?id=<?php echo $scheme_id; ?>">
                                        <i class="fas fa-fw fa-edit mr-2"></i>Edit Scheme
                                    </a>
                                    <a class="dropdown-item" href="insurance_company_view.php?id=<?php echo $row['insurance_company_id']; ?>">
                                        <i class="fas fa-fw fa-hospital mr-2"></i>View Company
                                    </a>
                                    <div class="dropdown-divider"></div>
                                    <?php if ($is_active): ?>
                                        <a class="dropdown-item text-warning" href="#" onclick="toggleStatus(<?php echo $scheme_id; ?>, 'scheme')">
                                            <i class="fas fa-fw fa-pause mr-2"></i>Deactivate
                                        </a>
                                    <?php else: ?>
                                        <a class="dropdown-item text-success" href="#" onclick="toggleStatus(<?php echo $scheme_id; ?>, 'scheme')">
                                            <i class="fas fa-fw fa-play mr-2"></i>Activate
                                        </a>
                                    <?php endif; ?>
                                    <a class="dropdown-item text-danger" href="insurance_scheme_delete.php?id=<?php echo $scheme_id; ?>" onclick="return confirm('Are you sure you want to delete this scheme? This action cannot be undone.')">
                                        <i class="fas fa-fw fa-trash mr-2"></i>Delete
                                    </a>
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
    
    <?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/filter_footer.php'; ?>
</div>

<script>
$(document).ready(function() {
    $('.select2').select2();
    $('[data-toggle="tooltip"]').tooltip();

    // Auto-submit when filters change
    $('select[name="status"], select[name="company"], select[name="type"]').change(function() {
        $(this).closest('form').submit();
    });
});

function toggleStatus(id, type) {
    let url = type === 'company' ? 'ajax/insurance_company_toggle.php' : 'ajax/insurance_scheme_toggle.php';
    
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
</script>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>