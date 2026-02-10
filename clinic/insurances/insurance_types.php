<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

$alert = '';
$message = '';

// Handle type updates
if (isset($_POST['update_types'])) {
    $types = $_POST['types'] ?? [];
    $active_types = $_POST['active_types'] ?? [];
    
    // You might want to save these to a settings table
    $_SESSION['insurance_types'] = $types;
    $_SESSION['active_insurance_types'] = $active_types;
    
    $alert = "success";
    $message = "Insurance types updated successfully!";
}

// Get scheme type statistics
$type_stats_sql = mysqli_query($mysqli, "
    SELECT 
        scheme_type,
        COUNT(*) as total_schemes,
        SUM(is_active) as active_schemes,
        SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive_schemes,
        SUM(outpatient_cover) as outpatient_count,
        SUM(inpatient_cover) as inpatient_count,
        SUM(maternity_cover) as maternity_count,
        SUM(dental_cover) as dental_count,
        SUM(optical_cover) as optical_count,
        AVG(annual_limit) as avg_annual_limit,
        SUM(annual_limit) as total_annual_limit
    FROM insurance_schemes
    GROUP BY scheme_type
    ORDER BY total_schemes DESC
");

// Get default insurance types (from enum definition or settings)
$default_types = ['NHIF', 'SHA', 'PRIVATE', 'CORPORATE', 'INDIVIDUAL'];
$active_types = ['NHIF', 'SHA', 'PRIVATE', 'CORPORATE', 'INDIVIDUAL']; // Default all active

// Get from session if set
if (isset($_SESSION['insurance_types'])) {
    $default_types = $_SESSION['insurance_types'];
}
if (isset($_SESSION['active_insurance_types'])) {
    $active_types = $_SESSION['active_insurance_types'];
}
?>

<div class="card">
    <div class="card-header bg-dark py-2">
        <h3 class="card-title mt-2 mb-0 text-white"><i class="fas fa-fw fa-tags mr-2"></i>Insurance Scheme Types</h3>
        <div class="card-tools">
            <a href="insurance_management.php" class="btn btn-light">
                <i class="fas fa-arrow-left mr-2"></i>Back
            </a>
        </div>
    </div>
    
    <div class="card-body">
        <?php if ($alert): ?>
            <div class="alert alert-<?php echo $alert; ?> alert-dismissible">
                <button type="button" class="close" data-dismiss="alert">&times;</button>
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
        
        <!-- Type Statistics -->
        <div class="card mb-4">
            <div class="card-header bg-light">
                <h5 class="card-title mb-0">Scheme Type Statistics</h5>
            </div>
            <div class="card-body">
                <?php if (mysqli_num_rows($type_stats_sql) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Scheme Type</th>
                                    <th class="text-center">Total Schemes</th>
                                    <th class="text-center">Active</th>
                                    <th class="text-center">Inactive</th>
                                    <th class="text-center">Avg Annual Limit</th>
                                    <th class="text-center">Coverage</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($type = mysqli_fetch_assoc($type_stats_sql)): ?>
                                <tr>
                                    <td>
                                        <span class="badge badge-info"><?php echo htmlspecialchars($type['scheme_type']); ?></span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge badge-primary"><?php echo $type['total_schemes']; ?></span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge badge-success"><?php echo $type['active_schemes']; ?></span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge badge-secondary"><?php echo $type['inactive_schemes']; ?></span>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($type['avg_annual_limit']): ?>
                                            <span class="font-weight-bold">$<?php echo number_format($type['avg_annual_limit'], 2); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <div class="d-flex flex-wrap justify-content-center gap-1">
                                            <?php if ($type['outpatient_count']): ?>
                                                <span class="badge badge-success" data-toggle="tooltip" title="Outpatient">OP</span>
                                            <?php endif; ?>
                                            <?php if ($type['inpatient_count']): ?>
                                                <span class="badge badge-primary" data-toggle="tooltip" title="Inpatient">IP</span>
                                            <?php endif; ?>
                                            <?php if ($type['maternity_count']): ?>
                                                <span class="badge badge-info" data-toggle="tooltip" title="Maternity">M</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <a href="insurance_schemes.php?type=<?php echo urlencode($type['scheme_type']); ?>" class="btn btn-sm btn-secondary">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center py-4">
                        <i class="fas fa-tags fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No Scheme Type Data</h5>
                        <p class="text-muted">No insurance schemes have been created yet.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Type Management -->
        <div class="card">
            <div class="card-header bg-light">
                <h5 class="card-title mb-0">Manage Scheme Types</h5>
            </div>
            <div class="card-body">
                <form method="POST" autocomplete="off">
                    <div class="row">
                        <div class="col-md-6">
                            <h6>Available Scheme Types</h6>
                            <p class="text-muted small mb-3">Configure the types of insurance schemes available in your system.</p>
                            
                            <div id="type-container">
                                <?php foreach($default_types as $index => $type): ?>
                                <div class="input-group mb-2 type-row">
                                    <input type="text" class="form-control" name="types[]" value="<?php echo htmlspecialchars($type); ?>" placeholder="e.g., NHIF, Private, Corporate">
                                    <div class="input-group-append">
                                        <button type="button" class="btn btn-danger remove-type" data-toggle="tooltip" title="Remove">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <button type="button" class="btn btn-sm btn-outline-primary" id="add-type">
                                <i class="fas fa-plus mr-1"></i> Add New Type
                            </button>
                        </div>
                        
                        <div class="col-md-6">
                            <h6>Active Types</h6>
                            <p class="text-muted small mb-3">Select which types should be available for new schemes.</p>
                            
                            <div class="list-group">
                                <?php foreach($default_types as $type): ?>
                                <div class="list-group-item">
                                    <div class="custom-control custom-checkbox">
                                        <input type="checkbox" class="custom-control-input" id="type_<?php echo preg_replace('/[^a-z0-9]/i', '_', strtolower($type)); ?>" name="active_types[]" value="<?php echo htmlspecialchars($type); ?>" <?php echo in_array($type, $active_types) ? 'checked' : ''; ?>>
                                        <label class="custom-control-label" for="type_<?php echo preg_replace('/[^a-z0-9]/i', '_', strtolower($type)); ?>">
                                            <?php echo htmlspecialchars($type); ?>
                                        </label>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row mt-4">
                        <div class="col-12">
                            <button type="submit" name="update_types" class="btn btn-primary">
                                <i class="fas fa-save mr-2"></i>Save Changes
                            </button>
                            <button type="button" class="btn btn-secondary" onclick="resetToDefaults()">
                                <i class="fas fa-undo mr-2"></i>Reset to Defaults
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Type Usage Guide -->
        <div class="card mt-4">
            <div class="card-header bg-light">
                <h5 class="card-title mb-0">Scheme Type Guide</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6>Common Scheme Types:</h6>
                        <ul class="list-unstyled">
                            <li class="mb-2">
                                <span class="badge badge-primary mr-2">NHIF</span>
                                <small class="text-muted">National Hospital Insurance Fund - Government health insurance</small>
                            </li>
                            <li class="mb-2">
                                <span class="badge badge-info mr-2">SHA</span>
                                <small class="text-muted">Social Health Authority - Social health insurance programs</small>
                            </li>
                            <li class="mb-2">
                                <span class="badge badge-success mr-2">PRIVATE</span>
                                <small class="text-muted">Private health insurance from commercial providers</small>
                            </li>
                            <li class="mb-2">
                                <span class="badge badge-warning mr-2">CORPORATE</span>
                                <small class="text-muted">Company-sponsored health insurance for employees</small>
                            </li>
                            <li class="mb-2">
                                <span class="badge badge-secondary mr-2">INDIVIDUAL</span>
                                <small class="text-muted">Individual/family health insurance plans</small>
                            </li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <h6>Best Practices:</h6>
                        <ul class="small text-muted">
                            <li class="mb-1">Use descriptive names that are easily understood by staff</li>
                            <li class="mb-1">Keep the list concise - too many types can be confusing</li>
                            <li class="mb-1">Deactivate types that are no longer in use instead of deleting them</li>
                            <li class="mb-1">Consider adding regional/state-specific types if needed</li>
                            <li class="mb-1">Types cannot be deleted if they are in use by existing schemes</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('[data-toggle="tooltip"]').tooltip();
    
    // Add new type row
    $('#add-type').click(function() {
        const newRow = `
            <div class="input-group mb-2 type-row">
                <input type="text" class="form-control" name="types[]" placeholder="e.g., NHIF, Private, Corporate">
                <div class="input-group-append">
                    <button type="button" class="btn btn-danger remove-type" data-toggle="tooltip" title="Remove">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
        `;
        $('#type-container').append(newRow);
        $('[data-toggle="tooltip"]').tooltip(); // Reinitialize tooltips
    });
    
    // Remove type row
    $(document).on('click', '.remove-type', function() {
        if ($('.type-row').length > 1) {
            $(this).closest('.type-row').remove();
        } else {
            alert('At least one scheme type is required.');
        }
    });
    
    // Update active types list when types are added/removed
    $(document).on('input', 'input[name="types[]"]', function() {
        updateActiveTypesList();
    });
});

function resetToDefaults() {
    if (confirm('Reset all scheme types to default values? This will overwrite any custom types you have added.')) {
        const defaultTypes = ['NHIF', 'SHA', 'PRIVATE', 'CORPORATE', 'INDIVIDUAL'];
        const container = $('#type-container');
        container.empty();
        
        defaultTypes.forEach(function(type) {
            container.append(`
                <div class="input-group mb-2 type-row">
                    <input type="text" class="form-control" name="types[]" value="${type}">
                    <div class="input-group-append">
                        <button type="button" class="btn btn-danger remove-type" data-toggle="tooltip" title="Remove">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
            `);
        });
        
        updateActiveTypesList();
    }
}

function updateActiveTypesList() {
    // This function would update the active types checklist
    // For now, it's a placeholder - the server will handle the updates
    console.log('Types updated - server will process on form submission');
}
</script>

<style>
.type-row {
    transition: all 0.3s ease;
}

.type-row:hover {
    background-color: #f8f9fa;
    padding-left: 5px;
}

.list-group-item:hover {
    background-color: #f8f9fa;
}

.custom-control-label {
    cursor: pointer;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>