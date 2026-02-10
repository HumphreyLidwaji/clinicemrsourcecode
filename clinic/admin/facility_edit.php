<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
// Define APP_VERSION if not already defined
if (!defined('APP_VERSION')) {
    define('APP_VERSION', '1.0.0');
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (isset($_POST['edit_company'])) {
        validateCSRFToken($csrf_token);

        // Company details
        $company_name = sanitizeInput($_POST['name'] ?? '');
        $company_address = sanitizeInput($_POST['address'] ?? '');
        $company_city = sanitizeInput($_POST['city'] ?? '');
        $company_state = sanitizeInput($_POST['state'] ?? '');
        $company_zip = sanitizeInput($_POST['zip'] ?? '');
        $company_country = sanitizeInput($_POST['country'] ?? '');
        $company_phone_country_code = sanitizeInput($_POST['phone_country_code'] ?? '');
        $company_phone = sanitizeInput($_POST['phone'] ?? '');
        $company_email = sanitizeInput(filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL));
        $company_website = sanitizeInput($_POST['website'] ?? '');
        $company_tax_id = sanitizeInput($_POST['tax_id'] ?? '');
        $company_locale = sanitizeInput($_POST['locale'] ?? '');
        $company_currency = sanitizeInput($_POST['currency'] ?? '');
        
        // Facility details
        $facility_code = sanitizeInput($_POST['facility_code'] ?? '');
        $mfl_code = sanitizeInput($_POST['mfl_code'] ?? '');
        $facility_type = sanitizeInput($_POST['facility_type'] ?? '');
        $county = sanitizeInput($_POST['county'] ?? '');
        $sub_county = sanitizeInput($_POST['sub_county'] ?? '');
        $ward = sanitizeInput($_POST['ward'] ?? '');
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        // Handle file upload
        if (!empty($_FILES['file']['name'])) {
            $file_name = sanitizeInput($_FILES['file']['name']);
            $file_tmp = $_FILES['file']['tmp_name'];
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            $allowed_ext = ['jpg', 'jpeg', 'png', 'gif'];
            
            if (in_array($file_ext, $allowed_ext)) {
                $new_file_name = "company_logo.$file_ext";
                move_uploaded_file($file_tmp, "../uploads/settings/$new_file_name");
                $company_logo = $new_file_name;
                
                // Update logo in database
                mysqli_query($mysqli, "UPDATE companies SET company_logo = '$company_logo' WHERE company_id = 1");
            }
        }

        $query = "UPDATE companies SET 
            company_name = '$company_name',
            facility_code = '$facility_code',
            mfl_code = '$mfl_code',
            facility_type = '$facility_type',
            company_address = '$company_address',
            company_city = '$company_city',
            company_state = '$company_state',
            company_zip = '$company_zip',
            company_country = '$company_country',
            county = '$county',
            sub_county = '$sub_county',
            ward = '$ward',
            company_phone_country_code = '$company_phone_country_code',
            company_phone = '$company_phone',
            company_email = '$company_email',
            company_website = '$company_website',
            company_tax_id = '$company_tax_id',
            company_locale = '$company_locale',
            company_currency = '$company_currency',
            is_active = $is_active,
            company_updated_at = NOW()
            WHERE company_id = 1";

        if (mysqli_query($mysqli, $query)) {
            logAction("Settings", "Edit", "$session_name updated company and facility details");
            flash_alert("Company and facility details updated successfully", 'success');
        } else {
            flash_alert("Error updating company details: " . mysqli_error($mysqli), 'error');
        }
        
        header("Location: facility_dashboard.php");
        exit;
    }
}

// Handle logo removal
if (isset($_GET['remove_company_logo'])) {
    validateCSRFToken($_GET['csrf_token'] ?? '');
    
    mysqli_query($mysqli, "UPDATE companies SET company_logo = '' WHERE company_id = 1");
    
    // Remove logo file
    $logo_files = glob("../uploads/settings/company_logo.*");
    foreach ($logo_files as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    
    logAction("Settings", "Edit", "$session_name removed company logo");
    flash_alert("Company logo removed successfully", 'success');
    
    header("Location: facility_edit.php");
    exit;
}

// Get current company details
$sql = mysqli_query($mysqli, "SELECT * FROM companies WHERE company_id = 1");
$row = mysqli_fetch_array($sql);

$company_id = intval($row['company_id']);
$company_name = nullable_htmlentities($row['company_name']);
$facility_code = nullable_htmlentities($row['facility_code']);
$mfl_code = nullable_htmlentities($row['mfl_code']);
$facility_type = nullable_htmlentities($row['facility_type']);
$company_country = nullable_htmlentities($row['company_country']);
$county = nullable_htmlentities($row['county']);
$sub_county = nullable_htmlentities($row['sub_county']);
$ward = nullable_htmlentities($row['ward']);
$company_address = nullable_htmlentities($row['company_address']);
$company_city = nullable_htmlentities($row['company_city']);
$company_state = nullable_htmlentities($row['company_state']);
$company_zip = nullable_htmlentities($row['company_zip']);
$company_phone_country_code = formatPhoneNumber($row['company_phone_country_code']);
$company_phone = nullable_htmlentities(formatPhoneNumber($row['company_phone'], $company_phone_country_code));
$company_email = nullable_htmlentities($row['company_email']);
$company_website = nullable_htmlentities($row['company_website']);
$company_logo = nullable_htmlentities($row['company_logo']);
$company_locale = nullable_htmlentities($row['company_locale']);
$company_currency = nullable_htmlentities($row['company_currency']);
$company_tax_id = nullable_htmlentities($row['company_tax_id']);
$is_active = intval($row['is_active']);

$company_initials = nullable_htmlentities(initials($company_name));

// Kenya counties array for dropdown
$kenya_counties = [
    'Mombasa', 'Kwale', 'Kilifi', 'Tana River', 'Lamu', 'Taita-Taveta', 'Garissa', 'Wajir', 'Mandera',
    'Marsabit', 'Isiolo', 'Meru', 'Tharaka-Nithi', 'Embu', 'Kitui', 'Machakos', 'Makueni', 'Nyandarua',
    'Nyeri', 'Kirinyaga', 'Murang\'a', 'Kiambu', 'Turkana', 'West Pokot', 'Samburu', 'Trans Nzoia',
    'Uasin Gishu', 'Elgeyo-Marakwet', 'Nandi', 'Baringo', 'Laikipia', 'Nakuru', 'Narok', 'Kajiado',
    'Kericho', 'Bomet', 'Kakamega', 'Vihiga', 'Bungoma', 'Busia', 'Siaya', 'Kisumu', 'Homa Bay',
    'Migori', 'Kisii', 'Nyamira', 'Nairobi'
];
?>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-1 text-gray-800">
            <i class="fas fa-fw fa-edit text-primary mr-2"></i>
            Edit Facility Details
        </h1>
        <p class="text-muted">Update your healthcare facility information and settings</p>
    </div>
    <div class="btn-group">
        <a href="facility_dashboard.php" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left mr-2"></i>Back to Dashboard
        </a>
    </div>
</div>

<div class="row">
    <div class="col-lg-4">
        <!-- Logo Management Card -->
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">
                    <i class="fas fa-image mr-2"></i>Facility Logo
                </h6>
            </div>
            <div class="card-body text-center">
                <?php if ($company_logo): ?>
                    <img class="img-thumbnail mb-3 shadow-sm" src="../uploads/settings/<?php echo $company_logo; ?>" 
                         style="max-height: 200px; border: 3px solid #e3f2fd;" alt="Facility Logo">
                    <a href="?remove_company_logo&csrf_token=<?php echo $_SESSION['csrf_token'] ?>" 
                       class="btn btn-outline-danger btn-block" 
                       onclick="return confirm('Are you sure you want to remove the facility logo?')">
                        <i class="fas fa-trash mr-2"></i>Remove Logo
                    </a>
                <?php else: ?>
                    <div class="bg-primary text-light rounded-circle d-flex align-items-center justify-content-center mx-auto mb-3 shadow" 
                         style="width: 150px; height: 150px; font-size: 48px; background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);">
                        <?php echo $company_initials; ?>
                    </div>
                <?php endif; ?>
                
                <div class="form-group">
                    <label class="font-weight-bold text-dark">Upload New Logo</label>
                    <div class="custom-file">
                        <input type="file" class="custom-file-input" id="fileInput" name="file" accept=".jpg, .jpeg, .png">
                        <label class="custom-file-label" for="fileInput">Choose logo file...</label>
                    </div>
                    <small class="form-text text-muted">Recommended: Square image, JPG/PNG, max 500x500px</small>
                </div>

                <div class="form-group text-left">
                    <div class="custom-control custom-switch">
                        <input type="checkbox" class="custom-control-input" id="is_active" name="is_active" value="1" <?php echo $is_active ? 'checked' : ''; ?>>
                        <label class="custom-control-label font-weight-bold text-success" for="is_active">
                            <i class="fas fa-power-off mr-1"></i>Facility Active
                        </label>
                    </div>
                    <small class="form-text text-muted">Inactive facilities won't appear in system operations</small>
                </div>
            </div>
        </div>

        <!-- Quick Actions Card -->
        <div class="card shadow">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-info">
                    <i class="fas fa-bolt mr-2"></i>Quick Actions
                </h6>
            </div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <button type="submit" form="facilityForm" class="btn btn-success btn-lg">
                        <i class="fas fa-save mr-2"></i>Save All Changes
                    </button>
                    <button type="reset" form="facilityForm" class="btn btn-outline-secondary">
                        <i class="fas fa-undo mr-2"></i>Reset Form
                    </button>
                    <a href="facility_dashboard.php" class="btn btn-outline-danger">
                        <i class="fas fa-times mr-2"></i>Cancel
                    </a>
                </div>

                <div class="mt-3 p-3 bg-light rounded">
                    <h6 class="text-dark mb-2">Form Tips:</h6>
                    <ul class="list-unstyled small mb-0">
                        <li><i class="fas fa-check text-success mr-1"></i> Required fields are marked with *</li>
                        <li><i class="fas fa-save text-primary mr-1"></i> Save frequently to avoid losing changes</li>
                        <li><i class="fas fa-undo text-warning mr-1"></i> Use Reset to clear all fields</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <!-- Main Facility Form -->
        <div class="card shadow">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">
                    <i class="fas fa-edit mr-2"></i>Facility Details Form
                </h6>
            </div>
            <div class="card-body">
                <form id="facilityForm" method="post" enctype="multipart/form-data" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">

                    <!-- Facility Information Section -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <h5 class="section-title mb-3 text-primary">
                                <i class="fas fa-hospital mr-2"></i>Facility Information
                            </h5>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="font-weight-bold">Facility Name <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text bg-light border-right-0"><i class="fa fa-fw fa-building text-primary"></i></span>
                                    </div>
                                    <input type="text" class="form-control border-left-0" name="name" 
                                           placeholder="Enter facility name" value="<?php echo $company_name; ?>" required>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="font-weight-bold">Facility Type</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text bg-light border-right-0"><i class="fa fa-fw fa-clinic-medical text-primary"></i></span>
                                    </div>
                                    <select class="form-control border-left-0" name="facility_type">
                                        <option value="">- Select Facility Type -</option>
                                        <option value="National" <?php if ($facility_type == 'National') echo 'selected'; ?>>National Hospital</option>
                                        <option value="County" <?php if ($facility_type == 'County') echo 'selected'; ?>>County Hospital</option>
                                        <option value="Sub-County" <?php if ($facility_type == 'Sub-County') echo 'selected'; ?>>Sub-County Hospital</option>
                                        <option value="Health Center" <?php if ($facility_type == 'Health Center') echo 'selected'; ?>>Health Center</option>
                                        <option value="Dispensary" <?php if ($facility_type == 'Dispensary') echo 'selected'; ?>>Dispensary</option>
                                        <option value="Clinic" <?php if ($facility_type == 'Clinic') echo 'selected'; ?>>Private Clinic</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row mb-4">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label class="font-weight-bold">Facility Code</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text bg-light border-right-0"><i class="fa fa-fw fa-hashtag text-primary"></i></span>
                                    </div>
                                    <input type="text" class="form-control border-left-0" name="facility_code" 
                                           placeholder="FAC-001" value="<?php echo $facility_code; ?>">
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label class="font-weight-bold">MFL Code</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text bg-light border-right-0"><i class="fa fa-fw fa-barcode text-primary"></i></span>
                                    </div>
                                    <input type="text" class="form-control border-left-0" name="mfl_code" 
                                           placeholder="MFL Code" value="<?php echo $mfl_code; ?>">
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label class="font-weight-bold">Tax ID</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text bg-light border-right-0"><i class="fa fa-fw fa-balance-scale text-primary"></i></span>
                                    </div>
                                    <input type="text" class="form-control border-left-0" name="tax_id" 
                                           value="<?php echo $company_tax_id; ?>" placeholder="Tax Identification Number">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Location Information Section -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <h5 class="section-title mb-3 text-primary">
                                <i class="fas fa-map-marker-alt mr-2"></i>Location Information
                            </h5>
                        </div>

                        <div class="col-md-4">
                            <div class="form-group">
                                <label class="font-weight-bold">County</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text bg-light border-right-0"><i class="fa fa-fw fa-map text-primary"></i></span>
                                    </div>
                                    <select class="form-control select2 border-left-0" name="county">
                                        <option value="">- Select County -</option>
                                        <?php foreach($kenya_counties as $county_name) { ?>
                                            <option <?php if ($county == $county_name) { echo "selected"; } ?>>
                                                <?php echo $county_name; ?>
                                            </option>
                                        <?php } ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="form-group">
                                <label class="font-weight-bold">Sub-County</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text bg-light border-right-0"><i class="fa fa-fw fa-map-marker-alt text-primary"></i></span>
                                    </div>
                                    <input type="text" class="form-control border-left-0" name="sub_county" 
                                           placeholder="Sub-County" value="<?php echo $sub_county; ?>">
                                </div>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="form-group">
                                <label class="font-weight-bold">Ward</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text bg-light border-right-0"><i class="fa fa-fw fa-location-arrow text-primary"></i></span>
                                    </div>
                                    <input type="text" class="form-control border-left-0" name="ward" 
                                           placeholder="Ward" value="<?php echo $ward; ?>">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="form-group">
                                <label class="font-weight-bold">Street Address</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text bg-light border-right-0"><i class="fa fa-fw fa-road text-primary"></i></span>
                                    </div>
                                    <input type="text" class="form-control border-left-0" name="address" 
                                           placeholder="Full street address" value="<?php echo $company_address; ?>">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row mb-4">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label class="font-weight-bold">City/Town</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text bg-light border-right-0"><i class="fa fa-fw fa-city text-primary"></i></span>
                                    </div>
                                    <input type="text" class="form-control border-left-0" name="city" 
                                           placeholder="City" value="<?php echo $company_city; ?>">
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label class="font-weight-bold">Postal Code</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text bg-light border-right-0"><i class="fab fa-fw fa-usps text-primary"></i></span>
                                    </div>
                                    <input type="text" class="form-control border-left-0" name="zip" 
                                           placeholder="Postal Code" value="<?php echo $company_zip; ?>">
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label class="font-weight-bold">Country</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text bg-light border-right-0"><i class="fa fa-fw fa-globe-americas text-primary"></i></span>
                                    </div>
                                    <select class="form-control select2 border-left-0" name="country">
                                        <option value="">- Select Country -</option>
                                        <?php foreach($countries_array as $country_name) { ?>
                                            <option <?php if ($company_country == $country_name) { echo "selected"; } ?>>
                                                <?php echo $country_name; ?>
                                            </option>
                                        <?php } ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Contact Information Section -->
                    <div class="row">
                        <div class="col-12">
                            <h5 class="section-title mb-3 text-primary">
                                <i class="fas fa-phone-alt mr-2"></i>Contact Information
                            </h5>
                        </div>

                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="font-weight-bold">Email Address</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text bg-light border-right-0"><i class="fa fa-fw fa-envelope text-primary"></i></span>
                                    </div>
                                    <input type="email" class="form-control border-left-0" name="email" 
                                           placeholder="facility@example.com" value="<?php echo $company_email; ?>">
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="font-weight-bold">Phone Number</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text bg-light border-right-0"><i class="fa fa-fw fa-phone text-primary"></i></span>
                                    </div>
                                    <input type="tel" class="form-control border-left-0" name="phone" 
                                           value="<?php echo $company_phone; ?>" placeholder="Phone Number">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label class="font-weight-bold">Website</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text bg-light border-right-0"><i class="fa fa-fw fa-globe text-primary"></i></span>
                                    </div>
                                    <input type="text" class="form-control border-left-0" name="website" 
                                           placeholder="https://example.com" value="<?php echo $company_website; ?>">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Form Actions -->
                    <div class="row mt-4">
                        <div class="col-12">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <button type="submit" name="edit_company" class="btn btn-primary btn-lg px-4">
                                        <i class="fas fa-save mr-2"></i>Save Facility Details
                                    </button>
                                    <button type="reset" class="btn btn-outline-secondary ml-2">
                                        <i class="fas fa-undo mr-2"></i>Reset
                                    </button>
                                </div>
                                <div class="text-muted small">
                                    <i class="fas fa-info-circle mr-1"></i>
                                    All changes are logged for audit purposes
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Initialize Select2
    $('.select2').select2({
        theme: 'bootstrap4'
    });

    // File input label update
    $('#fileInput').on('change', function() {
        var fileName = $(this).val().split('\\').pop();
        $(this).next('.custom-file-label').html(fileName || 'Choose file...');
    });

    // Form validation
    $('#facilityForm').on('submit', function(e) {
        const facilityName = $('input[name="name"]').val().trim();
        
        if (!facilityName) {
            e.preventDefault();
            showAlert('Facility name is required!', 'error');
            $('input[name="name"]').focus();
            return false;
        }
        
        // Show loading state
        $('button[type="submit"]').html('<i class="fas fa-spinner fa-spin mr-2"></i>Saving...').prop('disabled', true);
    });

    // Real-time validation
    $('input[required]').on('blur', function() {
        const $this = $(this);
        if (!$this.val().trim()) {
            $this.addClass('is-invalid');
        } else {
            $this.removeClass('is-invalid');
        }
    });

    // Auto-generate facility code
    $('input[name="name"]').on('blur', function() {
        if (!$('input[name="facility_code"]').val()) {
            const name = $(this).val();
            if (name) {
                const code = name.toUpperCase().replace(/[^A-Z0-9]/g, '_').substring(0, 10);
                $('input[name="facility_code"]').val(code);
            }
        }
    });
});

function showAlert(message, type) {
    const alertClass = type === 'error' ? 'alert-danger' : 'alert-success';
    
    const alertHtml = `
        <div class="alert ${alertClass} alert-dismissible fade show" role="alert">
            <i class="fas fa-${type === 'error' ? 'exclamation-triangle' : 'check'} mr-2"></i>
            ${message}
            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
    `;
    
    $('.card-body').prepend(alertHtml);
    
    setTimeout(() => {
        $('.alert').alert('close');
    }, 5000);
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + S to save
    if (e.ctrlKey && e.keyCode === 83) {
        e.preventDefault();
        $('#facilityForm').submit();
    }
    // Escape to go back
    if (e.keyCode === 27) {
        window.location.href = 'facility_dashboard.php';
    }
});
</script>

<style>
.section-title {
    border-bottom: 2px solid #e3f2fd;
    padding-bottom: 0.5rem;
    font-weight: 600;
}

.card {
    border: none;
    box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
}

.card-header {
    border-bottom: 1px solid #e3e6f0;
    background: linear-gradient(180deg, #f8f9fc 0%, #e3e6f0 100%);
}

.form-control:focus, .custom-select:focus {
    border-color: #007bff;
    box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
}

.input-group-text {
    transition: all 0.3s ease;
}

.input-group:focus-within .input-group-text {
    background-color: #007bff;
    color: white;
    border-color: #007bff;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>