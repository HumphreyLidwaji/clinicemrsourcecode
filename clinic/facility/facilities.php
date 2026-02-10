<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Get current user session info
$session_user_id = $_SESSION['user_id'] ?? 0;
$session_user_name = $_SESSION['user_name'] ?? 'User';

// Get all facilities to display
$facility_sql = "SELECT f.*,
                        u.user_name as created_by_name,
                        u2.user_name as updated_by_name
                 FROM facilities f
                 LEFT JOIN users u ON f.created_by = u.user_id
                 LEFT JOIN users u2 ON f.updated_by = u2.user_id
                 ORDER BY f.is_active DESC, f.facility_name ASC";
$facility_result = $mysqli->query($facility_sql);

$facilities = [];
while ($row = $facility_result->fetch_assoc()) {
    $facilities[] = $row;
}

// Get total counts
$total_facilities = count($facilities);
$active_facilities = array_filter($facilities, function($facility) {
    return $facility['is_active'] == 1;
});
$active_count = count($active_facilities);
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0 text-white">
                <i class="fas fa-fw fa-hospital mr-2"></i>
                Facility Management
            </h3>
            <div class="card-tools">
                <a href="facility_add.php" class="btn btn-light">
                    <i class="fas fa-plus mr-2"></i>Add New Facility
                </a>
            </div>
        </div>
    </div>

    <div class="card-body">
        <?php if (isset($_SESSION['alert_message'])): ?>
            <div class="alert alert-<?php echo $_SESSION['alert_type']; ?> alert-dismissible">
                <button type="button" class="close" data-dismiss="alert">&times;</button>
                <i class="icon fas fa-<?php echo $_SESSION['alert_type'] == 'success' ? 'check' : 'exclamation-triangle'; ?>"></i>
                <?php echo $_SESSION['alert_message']; ?>
            </div>
            <?php 
            unset($_SESSION['alert_type']);
            unset($_SESSION['alert_message']);
            ?>
        <?php endif; ?>

        <!-- Summary Cards -->
        <div class="row mb-4">
            <div class="col-md-3 col-sm-6">
                <div class="small-box bg-info">
                    <div class="inner">
                        <h3><?php echo $total_facilities; ?></h3>
                        <p>Total Facilities</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-hospital"></i>
                    </div>
                    <a href="#" class="small-box-footer" onclick="filterFacilities('all')">
                        View All <i class="fas fa-arrow-circle-right"></i>
                    </a>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="small-box bg-success">
                    <div class="inner">
                        <h3><?php echo $active_count; ?></h3>
                        <p>Active Facilities</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <a href="#" class="small-box-footer" onclick="filterFacilities('active')">
                        View Active <i class="fas fa-arrow-circle-right"></i>
                    </a>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="small-box bg-warning">
                    <div class="inner">
                        <h3><?php echo $total_facilities - $active_count; ?></h3>
                        <p>Inactive Facilities</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-times-circle"></i>
                    </div>
                    <a href="#" class="small-box-footer" onclick="filterFacilities('inactive')">
                        View Inactive <i class="fas fa-arrow-circle-right"></i>
                    </a>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="small-box bg-primary">
                    <div class="inner">
                        <h3><?php echo count(array_filter($facilities, function($f) { 
                            return $f['nhif_accreditation_status'] == 'ACCREDITED'; 
                        })); ?></h3>
                        <p>NHIF Accredited</p>
                    </div>
                    <div class="icon">
                        <i class="fas fa-certificate"></i>
                    </div>
                    <a href="#" class="small-box-footer" onclick="filterFacilities('accredited')">
                        View Accredited <i class="fas fa-arrow-circle-right"></i>
                    </a>
                </div>
            </div>
        </div>

        <?php if ($total_facilities > 0): ?>
            <!-- Facilities Table -->
            <div class="card">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h4 class="card-title mb-0">
                            <i class="fas fa-list mr-2"></i>All Facilities
                        </h4>
                        <div class="d-flex">
                            <div class="input-group input-group-sm" style="width: 200px;">
                                <input type="text" id="searchFacilities" class="form-control" placeholder="Search facilities...">
                                <div class="input-group-append">
                                    <button class="btn btn-outline-secondary" type="button">
                                        <i class="fas fa-search"></i>
                                    </button>
                                </div>
                            </div>
                            <button class="btn btn-sm btn-outline-primary ml-2" onclick="exportFacilities()">
                                <i class="fas fa-download mr-1"></i>Export
                            </button>
                        </div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0" id="facilitiesTable">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Facility Name</th>
                                    <th>MFL Code</th>
                                    <th>Type</th>
                                    <th>Level</th>
                                    <th>County</th>
                                    <th>NHIF Status</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($facilities as $index => $facility): ?>
                                    <?php
                                    // Determine status styling
                                    $status_class = $facility['is_active'] ? 'success' : 'danger';
                                    $status_icon = $facility['is_active'] ? 'check-circle' : 'times-circle';
                                    $status_text = $facility['is_active'] ? 'Active' : 'Inactive';
                                    
                                    // Determine NHIF status styling
                                    switch ($facility['nhif_accreditation_status']) {
                                        case 'ACCREDITED':
                                            $nhif_class = 'success';
                                            $nhif_icon = 'check-circle';
                                            break;
                                        case 'PENDING':
                                            $nhif_class = 'warning';
                                            $nhif_icon = 'clock';
                                            break;
                                        case 'SUSPENDED':
                                            $nhif_class = 'danger';
                                            $nhif_icon = 'times-circle';
                                            break;
                                        default:
                                            $nhif_class = 'secondary';
                                            $nhif_icon = 'circle';
                                    }
                                    
                                    // Determine facility level styling
                                    $level_colors = [
                                        'LEVEL_2' => 'info',
                                        'LEVEL_3' => 'primary',
                                        'LEVEL_4' => 'success',
                                        'LEVEL_5' => 'warning',
                                        'LEVEL_6' => 'danger'
                                    ];
                                    $level_class = $level_colors[$facility['facility_level']] ?? 'secondary';
                                    
                                    // Determine facility type styling
                                    $type_colors = [
                                        'DISPENSARY' => 'info',
                                        'HEALTH_CENTER' => 'primary',
                                        'SUB_COUNTY_HOSPITAL' => 'warning',
                                        'COUNTY_REFERRAL' => 'success',
                                        'NATIONAL_REFERRAL' => 'danger',
                                        'CLINIC' => 'secondary'
                                    ];
                                    $type_class = $type_colors[$facility['facility_type']] ?? 'secondary';
                                    ?>
                                    <tr class="facility-row" 
                                        data-status="<?php echo $facility['is_active'] ? 'active' : 'inactive'; ?>"
                                        data-nhif="<?php echo strtolower($facility['nhif_accreditation_status']); ?>">
                                        <td><?php echo $index + 1; ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($facility['facility_name']); ?></strong>
                                            <br>
                                            <small class="text-muted">
                                                Internal Code: <?php echo htmlspecialchars($facility['facility_internal_code']); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <code class="bg-light p-1 rounded"><?php echo htmlspecialchars($facility['mfl_code']); ?></code>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?php echo $type_class; ?>">
                                                <?php echo str_replace('_', ' ', $facility['facility_type']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?php echo $level_class; ?>">
                                                <?php echo str_replace('_', ' ', $facility['facility_level']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($facility['county']); ?>
                                            <br>
                                            <small class="text-muted"><?php echo htmlspecialchars($facility['sub_county']); ?></small>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?php echo $nhif_class; ?>">
                                                <i class="fas fa-<?php echo $nhif_icon; ?> mr-1"></i>
                                                <?php echo ucfirst(strtolower($facility['nhif_accreditation_status'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?php echo $status_class; ?>">
                                                <i class="fas fa-<?php echo $status_icon; ?> mr-1"></i>
                                                <?php echo $status_text; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <button type="button" class="btn btn-info" 
                                                        onclick="viewFacility(<?php echo $facility['facility_id']; ?>)"
                                                        title="View Details">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <button type="button" class="btn btn-warning"
                                                        onclick="editFacility(<?php echo $facility['facility_id']; ?>)"
                                                        title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button type="button" class="btn btn-danger"
                                                        onclick="deleteFacility(<?php echo $facility['facility_id']; ?>)"
                                                        title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <small class="text-muted">
                                Showing <?php echo $total_facilities; ?> facility/facilities
                            </small>
                        </div>
                        <div>
                            <button class="btn btn-sm btn-outline-secondary" onclick="printFacilities()">
                                <i class="fas fa-print mr-1"></i>Print
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Facility Details Modal -->
            <div class="modal fade" id="facilityModal" tabindex="-1" role="dialog" aria-hidden="true">
                <div class="modal-dialog modal-lg" role="document">
                    <div class="modal-content">
                        <div class="modal-header bg-primary text-white">
                            <h5 class="modal-title" id="modalTitle">
                                <i class="fas fa-hospital mr-2"></i>Facility Details
                            </h5>
                            <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                        </div>
                        <div class="modal-body" id="modalBody">
                            <!-- Details will be loaded via AJAX -->
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                            <button type="button" class="btn btn-primary" id="editButton">
                                <i class="fas fa-edit mr-1"></i>Edit Facility
                            </button>
                        </div>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <!-- No Facilities Registered -->
            <div class="text-center py-5">
                <div class="mb-4">
                    <i class="fas fa-hospital fa-4x text-muted"></i>
                </div>
                <h3 class="text-muted">No Facilities Registered</h3>
                <p class="text-muted mb-4">
                    You haven't registered any facilities yet. Start by adding your first facility.
                </p>
                <div class="mt-4">
                    <a href="facility_add.php" class="btn btn-primary btn-lg">
                        <i class="fas fa-plus mr-2"></i>Add Your First Facility
                    </a>
                </div>
                <div class="mt-3">
                    <p class="text-muted small">
                        <i class="fas fa-info-circle mr-1"></i>
                        Facilities are healthcare institutions like hospitals, clinics, dispensaries, etc.
                    </p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Quick Actions -->
        <?php if ($total_facilities > 0): ?>
            <div class="row mt-4">
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-bolt mr-2"></i>Quick Actions
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <a href="facility_add.php" class="btn btn-success">
                                    <i class="fas fa-plus mr-2"></i>Add New Facility
                                </a>
                                <button class="btn btn-primary" onclick="exportFacilities()">
                                    <i class="fas fa-download mr-2"></i>Export All Facilities
                                </button>
                                <button class="btn btn-info" onclick="bulkUpdate()">
                                    <i class="fas fa-sync-alt mr-2"></i>Bulk Update
                                </button>
                                <button class="btn btn-warning" onclick="printFacilities()">
                                    <i class="fas fa-print mr-2"></i>Print List
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-chart-bar mr-2"></i>Statistics Summary
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <h6>By Facility Type</h6>
                                    <ul class="list-unstyled">
                                        <?php
                                        $type_counts = [];
                                        foreach ($facilities as $facility) {
                                            $type = $facility['facility_type'];
                                            $type_counts[$type] = ($type_counts[$type] ?? 0) + 1;
                                        }
                                        arsort($type_counts);
                                        foreach ($type_counts as $type => $count):
                                        ?>
                                            <li class="mb-2">
                                                <div class="d-flex justify-content-between">
                                                    <span><?php echo str_replace('_', ' ', $type); ?></span>
                                                    <span class="badge badge-primary"><?php echo $count; ?></span>
                                                </div>
                                                <div class="progress" style="height: 5px;">
                                                    <div class="progress-bar" role="progressbar" 
                                                         style="width: <?php echo ($count / $total_facilities) * 100; ?>%">
                                                    </div>
                                                </div>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                                <div class="col-md-6">
                                    <h6>By County</h6>
                                    <ul class="list-unstyled">
                                        <?php
                                        $county_counts = [];
                                        foreach ($facilities as $facility) {
                                            $county = $facility['county'];
                                            $county_counts[$county] = ($county_counts[$county] ?? 0) + 1;
                                        }
                                        arsort($county_counts);
                                        $i = 0;
                                        foreach ($county_counts as $county => $count):
                                            if ($i++ >= 5) break;
                                        ?>
                                            <li class="mb-2">
                                                <div class="d-flex justify-content-between">
                                                    <span><?php echo htmlspecialchars($county); ?></span>
                                                    <span class="badge badge-info"><?php echo $count; ?></span>
                                                </div>
                                                <div class="progress" style="height: 5px;">
                                                    <div class="progress-bar bg-info" role="progressbar" 
                                                         style="width: <?php echo ($count / $total_facilities) * 100; ?>%">
                                                    </div>
                                                </div>
                                            </li>
                                        <?php endforeach; ?>
                                        <?php if (count($county_counts) > 5): ?>
                                            <li class="text-center mt-2">
                                                <small class="text-muted">
                                                    +<?php echo count($county_counts) - 5; ?> more counties
                                                </small>
                                            </li>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
.small-box {
    border-radius: .25rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.12), 0 1px 2px rgba(0,0,0,0.24);
    display: block;
    margin-bottom: 20px;
    position: relative;
    transition: transform 0.3s ease;
}

.small-box:hover {
    transform: translateY(-5px);
}

.small-box > .inner {
    padding: 10px;
}

.small-box > .small-box-footer {
    background: rgba(0,0,0,0.1);
    color: rgba(255,255,255,0.8);
    display: block;
    padding: 3px 0;
    position: relative;
    text-align: center;
    text-decoration: none;
    z-index: 10;
}

.small-box > .small-box-footer:hover {
    background: rgba(0,0,0,0.15);
    color: #ffffff;
}

.small-box h3 {
    font-size: 2.2rem;
    font-weight: bold;
    margin: 0 0 10px 0;
    padding: 0;
    white-space: nowrap;
}

.small-box p {
    font-size: 1rem;
}

.small-box .icon {
    color: rgba(0,0,0,0.15);
    z-index: 0;
    position: absolute;
    right: 10px;
    top: 10px;
    font-size: 70px;
    transition: all .3s linear;
}

.small-box:hover .icon {
    font-size: 75px;
}

.bg-info { background-color: #17a2b8 !important; }
.bg-success { background-color: #28a745 !important; }
.bg-warning { background-color: #ffc107 !important; }
.bg-primary { background-color: #007bff !important; }

.facility-row:hover {
    background-color: #f8f9fa;
    cursor: pointer;
}

.progress {
    background-color: #e9ecef;
    border-radius: .25rem;
    overflow: hidden;
}

.progress-bar {
    background-color: #007bff;
    transition: width .6s ease;
}
</style>

<script>
$(document).ready(function() {
    // Initialize DataTable for facilities
    $('#facilitiesTable').DataTable({
        pageLength: 10,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
        order: [[0, 'asc']],
        language: {
            search: "_INPUT_",
            searchPlaceholder: "Search facilities..."
        }
    });
    
    // Search functionality
    $('#searchFacilities').on('keyup', function() {
        const searchTerm = $(this).val().toLowerCase();
        $('.facility-row').each(function() {
            const rowText = $(this).text().toLowerCase();
            $(this).toggle(rowText.includes(searchTerm));
        });
    });
    
    // Click row to view details
    $('.facility-row').on('click', function(e) {
        if (!$(e.target).closest('.btn-group').length) {
            const facilityId = $(this).find('.btn-info').attr('onclick').match(/\d+/)[0];
            viewFacility(facilityId);
        }
    });
});

function viewFacility(facilityId) {
    $.ajax({
        url: 'ajax/get_facility_details.php',
        method: 'POST',
        data: {
            facility_id: facilityId,
            csrf_token: '<?php echo $_SESSION['csrf_token']; ?>'
        },
        success: function(response) {
            const data = JSON.parse(response);
            if (data.success) {
                $('#modalTitle').html(`
                    <i class="fas fa-hospital mr-2"></i>
                    ${data.facility.facility_name}
                `);
                
                $('#modalBody').html(`
                    <div class="row">
                        <div class="col-md-6">
                            <h6>Basic Information</h6>
                            <dl class="row">
                                <dt class="col-sm-4">MFL Code:</dt>
                                <dd class="col-sm-8"><code>${data.facility.mfl_code}</code></dd>
                                
                                <dt class="col-sm-4">Internal Code:</dt>
                                <dd class="col-sm-8"><code>${data.facility.facility_internal_code}</code></dd>
                                
                                <dt class="col-sm-4">Type:</dt>
                                <dd class="col-sm-8">
                                    <span class="badge badge-${getTypeColor(data.facility.facility_type)}">
                                        ${data.facility.facility_type.replace(/_/g, ' ')}
                                    </span>
                                </dd>
                                
                                <dt class="col-sm-4">Level:</dt>
                                <dd class="col-sm-8">
                                    <span class="badge badge-${getLevelColor(data.facility.facility_level)}">
                                        ${data.facility.facility_level.replace(/_/g, ' ')}
                                    </span>
                                </dd>
                                
                                <dt class="col-sm-4">Ownership:</dt>
                                <dd class="col-sm-8">
                                    <span class="badge badge-${getOwnershipColor(data.facility.ownership)}">
                                        ${data.facility.ownership.replace(/_/g, ' ')}
                                    </span>
                                </dd>
                            </dl>
                        </div>
                        <div class="col-md-6">
                            <h6>Contact & Status</h6>
                            <dl class="row">
                                <dt class="col-sm-4">Phone:</dt>
                                <dd class="col-sm-8">${data.facility.phone || '<span class="text-muted">Not set</span>'}</dd>
                                
                                <dt class="col-sm-4">Email:</dt>
                                <dd class="col-sm-8">${data.facility.email || '<span class="text-muted">Not set</span>'}</dd>
                                
                                <dt class="col-sm-4">NHIF Status:</dt>
                                <dd class="col-sm-8">
                                    <span class="badge badge-${getNHIFColor(data.facility.nhif_accreditation_status)}">
                                        ${data.facility.nhif_accreditation_status}
                                    </span>
                                </dd>
                                
                                <dt class="col-sm-4">Status:</dt>
                                <dd class="col-sm-8">
                                    <span class="badge badge-${data.facility.is_active == 1 ? 'success' : 'danger'}">
                                        ${data.facility.is_active == 1 ? 'Active' : 'Inactive'}
                                    </span>
                                </dd>
                                
                                <dt class="col-sm-4">KRA PIN:</dt>
                                <dd class="col-sm-8">${data.facility.kra_pin || '<span class="text-muted">Not set</span>'}</dd>
                            </dl>
                        </div>
                    </div>
                    
                    <div class="row mt-3">
                        <div class="col-md-12">
                            <h6>Location</h6>
                            <dl class="row">
                                <dt class="col-sm-2">County:</dt>
                                <dd class="col-sm-4">${data.facility.county}</dd>
                                
                                <dt class="col-sm-2">Sub-County:</dt>
                                <dd class="col-sm-4">${data.facility.sub_county}</dd>
                                
                                <dt class="col-sm-2">Ward:</dt>
                                <dd class="col-sm-4">${data.facility.ward}</dd>
                                
                                <dt class="col-sm-2">Address:</dt>
                                <dd class="col-sm-10">${data.facility.physical_address || '<span class="text-muted">Not specified</span>'}</dd>
                            </dl>
                        </div>
                    </div>
                    
                    ${data.facility.sha_facility_code ? `
                    <div class="row mt-2">
                        <div class="col-md-12">
                            <dl class="row">
                                <dt class="col-sm-2">SHA Code:</dt>
                                <dd class="col-sm-10"><code>${data.facility.sha_facility_code}</code></dd>
                            </dl>
                        </div>
                    </div>
                    ` : ''}
                    
                    <div class="row mt-3">
                        <div class="col-md-6">
                            <small class="text-muted">
                                <i class="fas fa-calendar-plus"></i> Created: 
                                ${formatDate(data.facility.created_at)}
                                ${data.facility.created_by_name ? 'by ' + data.facility.created_by_name : ''}
                            </small>
                        </div>
                        <div class="col-md-6">
                            <small class="text-muted">
                                <i class="fas fa-calendar-check"></i> Updated: 
                                ${formatDate(data.facility.updated_at)}
                                ${data.facility.updated_by_name ? 'by ' + data.facility.updated_by_name : ''}
                            </small>
                        </div>
                    </div>
                `);
                
                // Update edit button
                $('#editButton').attr('onclick', `editFacility(${facilityId})`);
                
                // Show modal
                $('#facilityModal').modal('show');
            } else {
                alert('Error loading facility details: ' + data.message);
            }
        },
        error: function() {
            alert('Error loading facility details');
        }
    });
}

function editFacility(facilityId) {
    window.location.href = 'facility_edit.php?id=' + facilityId;
}

function deleteFacility(facilityId) {
    if (confirm('Are you sure you want to delete this facility? This action cannot be undone.')) {
        $.ajax({
            url: 'ajax/delete_facility.php',
            method: 'POST',
            data: {
                facility_id: facilityId,
                csrf_token: '<?php echo $_SESSION['csrf_token']; ?>'
            },
            success: function(response) {
                const data = JSON.parse(response);
                if (data.success) {
                    location.reload();
                } else {
                    alert('Error deleting facility: ' + data.message);
                }
            },
            error: function() {
                alert('Error deleting facility');
            }
        });
    }
}

function filterFacilities(filter) {
    const rows = $('.facility-row');
    
    rows.each(function() {
        let show = true;
        
        switch(filter) {
            case 'active':
                show = $(this).data('status') === 'active';
                break;
            case 'inactive':
                show = $(this).data('status') === 'inactive';
                break;
            case 'accredited':
                show = $(this).data('nhif') === 'accredited';
                break;
            // 'all' shows all rows
        }
        
        $(this).toggle(show);
    });
}

function exportFacilities() {
    // You can implement export functionality here
    // For now, show a message
    alert('Export functionality would be implemented here.\nOptions: CSV, Excel, PDF');
    // window.location.href = 'export_facilities.php?format=csv';
}

function printFacilities() {
    const printContent = $('#facilitiesTable').clone();
    printContent.find('.btn-group').remove();
    
    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
        <html>
            <head>
                <title>Facilities List</title>
                <style>
                    body { font-family: Arial, sans-serif; margin: 20px; }
                    table { width: 100%; border-collapse: collapse; }
                    th { background-color: #f2f2f2; padding: 8px; text-align: left; }
                    td { padding: 8px; border-bottom: 1px solid #ddd; }
                    .badge { padding: 3px 8px; border-radius: 3px; font-size: 12px; }
                    code { background: #f5f5f5; padding: 2px 4px; border-radius: 3px; }
                </style>
            </head>
            <body>
                <h2>Facilities List</h2>
                <p>Generated on: ${new Date().toLocaleString()}</p>
                ${printContent[0].outerHTML}
            </body>
        </html>
    `);
    printWindow.document.close();
    printWindow.print();
}

function bulkUpdate() {
    alert('Bulk update functionality would be implemented here.\nYou could update multiple facilities at once.');
}

// Helper functions
function getTypeColor(type) {
    const colors = {
        'DISPENSARY': 'info',
        'HEALTH_CENTER': 'primary',
        'SUB_COUNTY_HOSPITAL': 'warning',
        'COUNTY_REFERRAL': 'success',
        'NATIONAL_REFERRAL': 'danger',
        'CLINIC': 'secondary'
    };
    return colors[type] || 'secondary';
}

function getLevelColor(level) {
    const colors = {
        'LEVEL_2': 'info',
        'LEVEL_3': 'primary',
        'LEVEL_4': 'success',
        'LEVEL_5': 'warning',
        'LEVEL_6': 'danger'
    };
    return colors[level] || 'secondary';
}

function getOwnershipColor(ownership) {
    const colors = {
        'MOH': 'danger',
        'COUNTY': 'primary',
        'PRIVATE': 'success',
        'FAITH_BASED': 'info',
        'NGO': 'warning',
        'ARMED_FORCES': 'dark'
    };
    return colors[ownership] || 'secondary';
}

function getNHIFColor(status) {
    switch(status) {
        case 'ACCREDITED': return 'success';
        case 'PENDING': return 'warning';
        case 'SUSPENDED': return 'danger';
        default: return 'secondary';
    }
}

function formatDate(dateString) {
    if (!dateString) return 'Never';
    const date = new Date(dateString);
    return date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
}
</script>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>