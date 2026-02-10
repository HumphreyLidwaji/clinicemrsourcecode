<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

$scheme_id = intval($_GET['id']);
$alert = '';
$message = '';

// Get scheme data
$scheme_sql = mysqli_query($mysqli, "SELECT * FROM insurance_schemes WHERE scheme_id = $scheme_id");
if (mysqli_num_rows($scheme_sql) == 0) {
    header("Location: insurance_schemes.php");
    exit;
}
$scheme = mysqli_fetch_assoc($scheme_sql);

// Get companies for dropdown
$companies_sql = mysqli_query($mysqli, "SELECT * FROM insurance_companies ORDER BY company_name");

if (isset($_POST['edit_scheme'])) {
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
    
    // Check if scheme code conflicts
    $check_sql = mysqli_query($mysqli, "SELECT scheme_id FROM insurance_schemes WHERE scheme_code = '$scheme_code' AND insurance_company_id = $insurance_company_id AND scheme_id != $scheme_id");
    if (mysqli_num_rows($check_sql) > 0) {
        $alert = "danger";
        $message = "Insurance scheme code '$scheme_code' already exists for this company!";
    } else {
        $update_sql = mysqli_query($mysqli, "UPDATE insurance_schemes SET 
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
            is_active = $is_active
            WHERE scheme_id = $scheme_id");
        if ($update_sql) {
            header("Location: insurance_scheme_view.php?id=$scheme_id");
            exit;
        } else {
            $alert = "danger";
            $message = "Error updating insurance scheme: " . mysqli_error($mysqli);
        }
    }
}
?>

<div class="card">
    <div class="card-header bg-dark py-2">
        <h3 class="card-title mt-2 mb-0 text-white"><i class="fas fa-fw fa-edit mr-2"></i>Edit Insurance Scheme</h3>
        <div class="card-tools">
            <a href="insurance_scheme_view.php?id=<?php echo $scheme_id; ?>" class="btn btn-light">
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
                        <select class="form-control" name="insurance_company_id" required>
                            <?php while($company = mysqli_fetch_assoc($companies_sql)): ?>
                                <option value="<?php echo $company['insurance_company_id']; ?>" <?php echo $company['insurance_company_id'] == $scheme['insurance_company_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($company['company_name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Scheme Code <strong class="text-danger">*</strong></label>
                        <input type="text" class="form-control" name="scheme_code" value="<?php echo htmlspecialchars($scheme['scheme_code']); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Scheme Name <strong class="text-danger">*</strong></label>
                        <input type="text" class="form-control" name="scheme_name" value="<?php echo htmlspecialchars($scheme['scheme_name']); ?>" required autofocus>
                    </div>
                    
                    <div class="form-group">
                        <label>Scheme Type <strong class="text-danger">*</strong></label>
                        <select class="form-control" name="scheme_type" required>
                            <option value="NHIF" <?php echo $scheme['scheme_type'] == 'NHIF' ? 'selected' : ''; ?>>NHIF</option>
                            <option value="SHA" <?php echo $scheme['scheme_type'] == 'SHA' ? 'selected' : ''; ?>>SHA</option>
                            <option value="PRIVATE" <?php echo $scheme['scheme_type'] == 'PRIVATE' ? 'selected' : ''; ?>>Private</option>
                            <option value="CORPORATE" <?php echo $scheme['scheme_type'] == 'CORPORATE' ? 'selected' : ''; ?>>Corporate</option>
                            <option value="INDIVIDUAL" <?php echo $scheme['scheme_type'] == 'INDIVIDUAL' ? 'selected' : ''; ?>>Individual</option>
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
                            <input type="number" class="form-control" name="annual_limit" value="<?php echo $scheme['annual_limit']; ?>" step="0.01" min="0">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Status</label>
                        <select class="form-control" name="is_active" required>
                            <option value="1" <?php echo $scheme['is_active'] ? 'selected' : ''; ?>>Active</option>
                            <option value="0" <?php echo !$scheme['is_active'] ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input" id="requires_preauthorization" name="requires_preauthorization" value="1" <?php echo $scheme['requires_preauthorization'] ? 'checked' : ''; ?>>
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
                                <input type="checkbox" class="custom-control-input" id="outpatient_cover" name="outpatient_cover" value="1" <?php echo $scheme['outpatient_cover'] ? 'checked' : ''; ?>>
                                <label class="custom-control-label" for="outpatient_cover">Outpatient</label>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="custom-control custom-checkbox">
                                <input type="checkbox" class="custom-control-input" id="inpatient_cover" name="inpatient_cover" value="1" <?php echo $scheme['inpatient_cover'] ? 'checked' : ''; ?>>
                                <label class="custom-control-label" for="inpatient_cover">Inpatient</label>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="custom-control custom-checkbox">
                                <input type="checkbox" class="custom-control-input" id="maternity_cover" name="maternity_cover" value="1" <?php echo $scheme['maternity_cover'] ? 'checked' : ''; ?>>
                                <label class="custom-control-label" for="maternity_cover">Maternity</label>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="custom-control custom-checkbox">
                                <input type="checkbox" class="custom-control-input" id="dental_cover" name="dental_cover" value="1" <?php echo $scheme['dental_cover'] ? 'checked' : ''; ?>>
                                <label class="custom-control-label" for="dental_cover">Dental</label>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="custom-control custom-checkbox">
                                <input type="checkbox" class="custom-control-input" id="optical_cover" name="optical_cover" value="1" <?php echo $scheme['optical_cover'] ? 'checked' : ''; ?>>
                                <label class="custom-control-label" for="optical_cover">Optical</label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="form-group mt-3">
                <label>Coverage Description</label>
                <textarea class="form-control" name="coverage_description" rows="4"><?php echo htmlspecialchars($scheme['coverage_description']); ?></textarea>
            </div>
            
            <div class="row mt-3">
                <div class="col-12">
                    <button type="submit" name="edit_scheme" class="btn btn-primary">
                        <i class="fas fa-save mr-2"></i>Save Changes
                    </button>
                    <a href="insurance_scheme_view.php?id=<?php echo $scheme_id; ?>" class="btn btn-secondary">
                        <i class="fas fa-times mr-2"></i>Cancel
                    </a>
                    <a href="insurance_scheme_delete.php?id=<?php echo $scheme_id; ?>" class="btn btn-danger float-right" onclick="return confirm('Are you sure you want to delete this scheme? This action cannot be undone.')">
                        <i class="fas fa-trash mr-2"></i>Delete Scheme
                    </a>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
$(document).ready(function() {
    // Auto-focus on scheme name
    $('input[name="scheme_name"]').focus();
});
</script>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>