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
            is_active = $is_active
            WHERE company_id = 1";

        if (mysqli_query($mysqli, $query)) {
            logAction("Settings", "Edit", "$session_name updated company and facility details");
            flash_alert("Company and facility details updated successfully");
        } else {
            flash_alert("Error updating company details: " . mysqli_error($mysqli), 'error');
        }
        
        header("Location: company_details.php");
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
    flash_alert("Company logo removed successfully");
    
    header("Location: company_details.php");
    exit;
}

// Get current company details
$sql = mysqli_query($mysqli, "SELECT * FROM companies, settings WHERE companies.company_id = settings.company_id AND companies.company_id = 1");

if ($sql && mysqli_num_rows($sql) > 0) {
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
} else {
    // Initialize empty values if no company found
    $company_name = '';
    $facility_code = '';
    $mfl_code = '';
    $facility_type = '';
    $company_country = '';
    $county = '';
    $sub_county = '';
    $ward = '';
    $company_address = '';
    $company_city = '';
    $company_state = '';
    $company_zip = '';
    $company_phone_country_code = '';
    $company_phone = '';
    $company_email = '';
    $company_website = '';
    $company_logo = '';
    $company_locale = '';
    $company_currency = '';
    $company_tax_id = '';
    $is_active = 1;
    $company_initials = '';
}

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

<div class="card card-dark">
    <div class="card-header py-3">
        <h3 class="card-title"><i class="fas fa-fw fa-briefcase mr-2"></i>Company & Facility Details</h3>
    </div>
    <div class="card-body">
        <form action="" method="post" enctype="multipart/form-data" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?>">

            <div class="row">
                <div class="col-md-3 text-center">
                    <?php if ($company_logo) { ?>
                        <img class="img-thumbnail mb-3" src="<?php echo "../uploads/settings/$company_logo"; ?>" style="max-height: 200px;">
                        <a href="?remove_company_logo&csrf_token=<?php echo $_SESSION['csrf_token'] ?>" class="btn btn-outline-danger btn-block" onclick="return confirm('Are you sure you want to remove the company logo?')">
                            <i class="fas fa-trash mr-2"></i>Remove Logo
                        </a>
                        <hr>
                    <?php } else { ?>
                        <div class="bg-dark text-light rounded-circle d-flex align-items-center justify-content-center mx-auto mb-3" style="width: 150px; height: 150px; font-size: 48px;">
                            <?php echo $company_initials; ?>
                        </div>
                    <?php } ?>
                    
                    <div class="form-group">
                        <label>Upload Facility Logo</label>
                        <input type="file" class="form-control-file" name="file" accept=".jpg, .jpeg, .png">
                        <small class="form-text text-muted">Recommended: Square image, max 500x500px</small>
                    </div>

                    <div class="form-group">
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input" id="is_active" name="is_active" value="1" <?php echo $is_active ? 'checked' : ''; ?>>
                            <label class="custom-control-label" for="is_active">Facility Active</label>
                        </div>
                    </div>
                </div>

                <div class="col-md-9">
                    <h5 class="mb-3">Facility Information</h5>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Facility Code</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text"><i class="fa fa-fw fa-hashtag"></i></span>
                                    </div>
                                    <input type="text" class="form-control" name="facility_code" placeholder="Facility Code" value="<?php echo $facility_code; ?>">
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>MFL Code</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text"><i class="fa fa-fw fa-barcode"></i></span>
                                    </div>
                                    <input type="text" class="form-control" name="mfl_code" placeholder="MFL Code" value="<?php echo $mfl_code; ?>">
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Facility Type</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text"><i class="fa fa-fw fa-hospital"></i></span>
                                    </div>
                                    <select class="form-control" name="facility_type">
                                        <option value="">- Select Type -</option>
                                        <option value="National" <?php if ($facility_type == 'National') echo 'selected'; ?>>National</option>
                                        <option value="County" <?php if ($facility_type == 'County') echo 'selected'; ?>>County</option>
                                        <option value="Sub-County" <?php if ($facility_type == 'Sub-County') echo 'selected'; ?>>Sub-County</option>
                                        <option value="Health Center" <?php if ($facility_type == 'Health Center') echo 'selected'; ?>>Health Center</option>
                                        <option value="Dispensary" <?php if ($facility_type == 'Dispensary') echo 'selected'; ?>>Dispensary</option>
                                        <option value="Clinic" <?php if ($facility_type == 'Clinic') echo 'selected'; ?>>Clinic</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Facility Name <strong class="text-danger">*</strong></label>
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text"><i class="fa fa-fw fa-building"></i></span>
                            </div>
                            <input type="text" class="form-control" name="name" placeholder="Facility Name" value="<?php echo $company_name; ?>" required>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>County</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text"><i class="fa fa-fw fa-map"></i></span>
                                    </div>
                                    <select class="form-control select2" name="county">
                                        <option value="">- Select County -</option>
                                        <?php foreach($kenya_counties as $county_name) { ?>
                                            <option <?php if ($county == $county_name) { echo "selected"; } ?>><?php echo $county_name; ?></option>
                                        <?php } ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Sub-County</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text"><i class="fa fa-fw fa-map-marker-alt"></i></span>
                                    </div>
                                    <input type="text" class="form-control" name="sub_county" placeholder="Sub-County" value="<?php echo $sub_county; ?>">
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Ward</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text"><i class="fa fa-fw fa-location-arrow"></i></span>
                                    </div>
                                    <input type="text" class="form-control" name="ward" placeholder="Ward" value="<?php echo $ward; ?>">
                                </div>
                            </div>
                        </div>
                    </div>

                    <hr>
                    <h5 class="mb-3">Contact Information</h5>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Email</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text"><i class="fa fa-fw fa-envelope"></i></span>
                                    </div>
                                    <input type="email" class="form-control" name="email" placeholder="Email address" value="<?php echo $company_email; ?>">
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Phone</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text"><i class="fa fa-fw fa-phone"></i></span>
                                    </div>
                                    <input type="tel" class="form-control" name="phone" value="<?php echo $company_phone; ?>" placeholder="Phone Number" maxlength="200">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Website</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text"><i class="fa fa-fw fa-globe"></i></span>
                                    </div>
                                    <input type="text" class="form-control" name="website" placeholder="Website address" value="<?php echo $company_website; ?>">
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Tax ID</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text"><i class="fa fa-fw fa-balance-scale"></i></span>
                                    </div>
                                    <input type="text" class="form-control" name="tax_id" value="<?php echo $company_tax_id; ?>" placeholder="Tax ID" maxlength="200">
                                </div>
                            </div>
                        </div>
                    </div>

                    <hr>
                    <h5 class="mb-3">Address Information</h5>

                    <div class="form-group">
                        <label>Address</label>
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text"><i class="fa fa-fw fa-map-marker-alt"></i></span>
                            </div>
                            <input type="text" class="form-control" name="address" placeholder="Street Address" value="<?php echo $company_address; ?>">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>City</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text"><i class="fa fa-fw fa-city"></i></span>
                                    </div>
                                    <input type="text" class="form-control" name="city" placeholder="City" value="<?php echo $company_city; ?>">
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Postal Code</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text"><i class="fab fa-fw fa-usps"></i></span>
                                    </div>
                                    <input type="text" class="form-control" name="zip" placeholder="Zip or Postal Code" value="<?php echo $company_zip; ?>">
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Country</label>
                                <div class="input-group">
                                    <div class="input-group-prepend">
                                        <span class="input-group-text"><i class="fa fa-fw fa-globe-americas"></i></span>
                                    </div>
                                    <select class="form-control select2" name="country">
                                        <option value="">- Select Country -</option>
                                        <?php foreach($countries_array as $country_name) { ?>
                                            <option <?php if ($company_country == $country_name) { echo "selected"; } ?>><?php echo $country_name; ?></option>
                                        <?php } ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <hr>

                    <button type="submit" name="edit_company" class="btn btn-primary text-bold">
                        <i class="fas fa-check mr-2"></i>Save Facility Details
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>