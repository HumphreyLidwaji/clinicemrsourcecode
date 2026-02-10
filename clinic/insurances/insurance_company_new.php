<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

$alert = '';
$message = '';

if (isset($_POST['add_company'])) {
    $company_name = sanitizeInput($_POST['company_name']);
    $company_code = sanitizeInput($_POST['company_code']);
    $contact_person = sanitizeInput($_POST['contact_person']);
    $phone = sanitizeInput($_POST['phone']);
    $email = sanitizeInput($_POST['email']);
    $address = sanitizeInput($_POST['address']);
    $is_active = intval($_POST['is_active']);
    
    // Check if company already exists
    $check_sql = mysqli_query($mysqli, "SELECT insurance_company_id FROM insurance_companies WHERE company_name = '$company_name'");
    if (mysqli_num_rows($check_sql) > 0) {
        $alert = "danger";
        $message = "Insurance company '$company_name' already exists!";
    } else {
        $insert_sql = mysqli_query($mysqli, "INSERT INTO insurance_companies SET 
            company_name = '$company_name', 
            company_code = '$company_code',
            contact_person = '$contact_person',
            phone = '$phone',
            email = '$email',
            address = '$address',
            is_active = $is_active,
            created_by = $session_user_id");
        if ($insert_sql) {
            $company_id = mysqli_insert_id($mysqli);
            header("Location: insurance_company_view.php?id=$company_id");
            exit;
        } else {
            $alert = "danger";
            $message = "Error adding insurance company: " . mysqli_error($mysqli);
        }
    }
}
?>

<div class="card">
    <div class="card-header bg-dark py-2">
        <h3 class="card-title mt-2 mb-0 text-white"><i class="fas fa-fw fa-hospital mr-2"></i>New Insurance Company</h3>
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
        
        <form method="POST" autocomplete="off">
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label>Company Name <strong class="text-danger">*</strong></label>
                        <input type="text" class="form-control" name="company_name" placeholder="Enter company name" required autofocus>
                    </div>
                    
                    <div class="form-group">
                        <label>Company Code</label>
                        <input type="text" class="form-control" name="company_code" placeholder="e.g., NHIF, AAR, etc.">
                        <small class="form-text text-muted">Unique identifier for the company</small>
                    </div>
                    
                    <div class="form-group">
                        <label>Contact Person</label>
                        <input type="text" class="form-control" name="contact_person" placeholder="Primary contact person">
                    </div>
                    
                    <div class="form-group">
                        <label>Status</label>
                        <select class="form-control" name="is_active" required>
                            <option value="1" selected>Active</option>
                            <option value="0">Inactive</option>
                        </select>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="form-group">
                        <label>Phone</label>
                        <input type="tel" class="form-control" name="phone" placeholder="Phone number">
                    </div>
                    
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" class="form-control" name="email" placeholder="Email address">
                    </div>
                    
                    <div class="form-group">
                        <label>Address</label>
                        <textarea class="form-control" name="address" rows="4" placeholder="Full company address"></textarea>
                    </div>
                </div>
            </div>
            
            <div class="row mt-3">
                <div class="col-12">
                    <button type="submit" name="add_company" class="btn btn-primary">
                        <i class="fas fa-plus mr-2"></i>Add Company
                    </button>
                    <a href="insurance_management.php" class="btn btn-secondary">
                        <i class="fas fa-times mr-2"></i>Cancel
                    </a>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
$(document).ready(function() {
    // Auto-focus on company name
    $('input[name="company_name"]').focus();
});
</script>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>