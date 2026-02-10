<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

$company_id = isset($_GET['company']) ? intval($_GET['company']) : 0;
$alert = '';
$message = '';

// Get companies for dropdown
$companies_sql = mysqli_query($mysqli, "SELECT * FROM insurance_companies WHERE is_active = 1 ORDER BY company_name");

if (isset($_POST['add_scheme'])) {
    $insurance_company_id = intval($_POST['insurance_company_id']);
    $scheme_code = sanitizeInput($_POST['scheme_code']);
    $scheme_name = sanitizeInput($_POST['scheme_name']);
    $scheme_type = sanitizeInput($_POST['scheme_type']);
    $coverage_description = sanitizeInput($_POST['coverage_description']);
    $requires_preauthorization = isset($_POST['requires_preauthorization']) ? 1 : 0;
    $outpatient_cover = isset($_POST['outpatient_cover']) ? 1 : 0;
    $inpatient_cover = isset($_POST['inpatient_cover']) ? 1 : 0;
    $maternity_cover = isset($_POST['maternity_cover']) ? 1 : 0;
    $dental_cover = isset($_POST['dental_cover']) ? 1 : 0;
    $optical_cover = isset($_POST['optical_cover']) ? 1 : 0;
    $annual_limit = floatval($_POST['annual_limit']);
    $is_active = intval($_POST['is_active']);
    
    // Check if scheme already exists
    $check_sql = mysqli_query($mysqli, "SELECT scheme_id FROM insurance_schemes WHERE scheme_code = '$scheme_code' AND insurance_company_id = $insurance_company_id");
    if (mysqli_num_rows($check_sql) > 0) {
        $alert = "danger";
        $message = "Insurance scheme '$scheme_code' already exists for this company!";
    } else {
        $insert_sql = mysqli_query($mysqli, "INSERT INTO insurance_schemes SET 
            insurance_company_id = $insurance_company_id,
            scheme_code = '$scheme_code',
            scheme_name = '$scheme_name',
            scheme_type = '$scheme_type',
            coverage_description = '$coverage_description',
            requires_preauthorization = $requires_preauthorization,
            outpatient_cover = $outpatient_cover,
            inpatient_cover = $inpatient_cover,
            maternity_cover = $maternity_cover,
            dental_cover = $dental_cover,
            optical_cover = $optical_cover,
            annual_limit = $annual_limit,
            is_active = $is_active,
            created_by = $session_user_id");
        if ($insert_sql) {
            $scheme_id = mysqli_insert_id($mysqli);
            header("Location: insurance_scheme_view.php?id=$scheme_id");
            exit;
        } else {
            $alert = "danger";
            $message = "Error adding insurance scheme: " . mysqli_error($mysqli);
        }
    }
}
?>

<div class="card">
    <div class="card-header bg-dark py-2">
        <h3 class="card-title mt-2 mb-0 text-white"><i class="fas fa-fw fa-file-medical mr-2"></i>New Insurance Scheme</h3>
        <div class="card-tools">
            <a href="<?php echo $company_id ? "insurance_company_view.php?id=$company_id" : "insurance_schemes.php"; ?>" class="btn btn-light">
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
        
        <form method="POST" autocomplete="off">
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label>Insurance Company <strong class="text-danger">*</strong></label>
                        <select class="form-control" name="insurance_company_id" required <?php echo $company_id ? 'disabled' : ''; ?>>
                            <option value="">Select Company</option>
                            <?php while($company = mysqli_fetch_assoc($companies_sql)): ?>
                                <option value="<?php echo $company['insurance_company_id']; ?>" <?php echo $company['insurance_company_id'] == $company_id ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($company['company_name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                        <?php if ($company_id): ?>
                            <input type="hidden" name="insurance_company_id" value="<?php echo $company_id; ?>">
                        <?php endif; ?>
                    </div>
                    
                    <div class="form-group">
                        <label>Scheme Code <strong class="text-danger">*</strong></label>
                        <input type="text" class="form-control" name="scheme_code" placeholder="e.g., NHIF-CIVIL, AAR-PREMIUM" required>
                        <small class="form-text text-muted">Unique identifier for the scheme</small>
                    </div>
                    
                    <div class="form-group">
                        <label>Scheme Name <strong class="text-danger">*</strong></label>
                        <input type="text" class="form-control" name="scheme_name" placeholder="e.g., NHIF Civil Servants, AAR Premium Plan" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Scheme Type <strong class="text-danger">*</strong></label>
                        <select class="form-control" name="scheme_type" required>
                            <option value="">Select Type</option>
                            <option value="NHIF">NHIF</option>
                            <option value="SHA">SHA</option>
                            <option value="PRIVATE">Private</option>
                            <option value="CORPORATE">Corporate</option>
                            <option value="INDIVIDUAL">Individual</option>
                        </select>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="form-group">
                        <label>Annual Limit</label>
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text">$</span>
                            </div>
                            <input type="number" class="form-control" name="annual_limit" placeholder="Annual coverage limit" step="0.01" min="0">
                        </div>
                        <small class="form-text text-muted">Leave blank for unlimited</small>
                    </div>
                    
                    <div class="form-group">
                        <label>Status</label>
                        <select class="form-control" name="is_active" required>
                            <option value="1" selected>Active</option>
                            <option value="0">Inactive</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input" id="requires_preauthorization" name="requires_preauthorization" value="1">
                            <label class="custom-control-label" for="requires_preauthorization">Requires Pre-authorization</label>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="row mt-3">
                <div class="col-md-12">
                    <label class="mb-2">Coverage Options:</label>
                    <div class="row">
                        <div class="col-md-2">
                            <div class="custom-control custom-checkbox">
                                <input type="checkbox" class="custom-control-input" id="outpatient_cover" name="outpatient_cover" value="1" checked>
                                <label class="custom-control-label" for="outpatient_cover">Outpatient</label>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="custom-control custom-checkbox">
                                <input type="checkbox" class="custom-control-input" id="inpatient_cover" name="inpatient_cover" value="1" checked>
                                <label class="custom-control-label" for="inpatient_cover">Inpatient</label>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="custom-control custom-checkbox">
                                <input type="checkbox" class="custom-control-input" id="maternity_cover" name="maternity_cover" value="1">
                                <label class="custom-control-label" for="maternity_cover">Maternity</label>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="custom-control custom-checkbox">
                                <input type="checkbox" class="custom-control-input" id="dental_cover" name="dental_cover" value="1">
                                <label class="custom-control-label" for="dental_cover">Dental</label>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="custom-control custom-checkbox">
                                <input type="checkbox" class="custom-control-input" id="optical_cover" name="optical_cover" value="1">
                                <label class="custom-control-label" for="optical_cover">Optical</label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="form-group mt-3">
                <label>Coverage Description</label>
                <textarea class="form-control" name="coverage_description" rows="4" placeholder="Detailed description of coverage, benefits, exclusions, etc."></textarea>
            </div>
            
            <div class="row mt-3">
                <div class="col-12">
                    <button type="submit" name="add_scheme" class="btn btn-primary">
                        <i class="fas fa-plus mr-2"></i>Add Scheme
                    </button>
                    <a href="<?php echo $company_id ? "insurance_company_view.php?id=$company_id" : "insurance_schemes.php"; ?>" class="btn btn-secondary">
                        <i class="fas fa-times mr-2"></i>Cancel
                    </a>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
$(document).ready(function() {
    // Auto-focus on scheme code
    $('input[name="scheme_code"]').focus();
    
    // If company is preselected, set it in the hidden field
    <?php if ($company_id): ?>
    $('select[name="insurance_company_id"]').removeAttr('disabled');
    <?php endif; ?>
});
</script>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>