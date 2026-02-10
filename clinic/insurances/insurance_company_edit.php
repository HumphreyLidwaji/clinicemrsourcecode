<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

$company_id = intval($_GET['id']);
$alert = '';
$message = '';

// Get company data
$company_sql = mysqli_query($mysqli, "SELECT * FROM insurance_companies WHERE insurance_company_id = $company_id");
if (mysqli_num_rows($company_sql) == 0) {
    header("Location: insurance_management.php");
    exit;
}
$company = mysqli_fetch_assoc($company_sql);

if (isset($_POST['edit_company'])) {
    $company_name = sanitizeInput($_POST['company_name']);
    $company_code = sanitizeInput($_POST['company_code']);
    $contact_person = sanitizeInput($_POST['contact_person']);
    $phone = sanitizeInput($_POST['phone']);
    $email = sanitizeInput($_POST['email']);
    $address = sanitizeInput($_POST['address']);
    $is_active = intval($_POST['is_active']);
    
    // Check if name conflicts with other companies
    $check_sql = mysqli_query($mysqli, "SELECT insurance_company_id FROM insurance_companies WHERE company_name = '$company_name' AND insurance_company_id != $company_id");
    if (mysqli_num_rows($check_sql) > 0) {
        $alert = "danger";
        $message = "Insurance company '$company_name' already exists!";
    } else {
        $update_sql = mysqli_query($mysqli, "UPDATE insurance_companies SET 
            company_name = '$company_name',
            company_code = '$company_code',
            contact_person = '$contact_person',
            phone = '$phone',
            email = '$email',
            address = '$address',
            is_active = $is_active 
            WHERE insurance_company_id = $company_id");
        if ($update_sql) {
            header("Location: insurance_company_view.php?id=$company_id");
            exit;
        } else {
            $alert = "danger";
            $message = "Error updating insurance company: " . mysqli_error($mysqli);
        }
    }
}
?>

<div class="card">
    <div class="card-header bg-dark py-2">
        <h3 class="card-title mt-2 mb-0 text-white"><i class="fas fa-fw fa-edit mr-2"></i>Edit Insurance Company</h3>
        <div class="card-tools">
            <a href="insurance_company_view.php?id=<?php echo $company_id; ?>" class="btn btn-light">
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
                        <input type="text" class="form-control" name="company_name" value="<?php echo htmlspecialchars($company['company_name']); ?>" required autofocus>
                    </div>
                    
                    <div class="form-group">
                        <label>Company Code</label>
                        <input type="text" class="form-control" name="company_code" value="<?php echo htmlspecialchars($company['company_code']); ?>" placeholder="e.g., NHIF, AAR, etc.">
                    </div>
                    
                    <div class="form-group">
                        <label>Contact Person</label>
                        <input type="text" class="form-control" name="contact_person" value="<?php echo htmlspecialchars($company['contact_person']); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>Status</label>
                        <select class="form-control" name="is_active" required>
                            <option value="1" <?php echo $company['is_active'] ? 'selected' : ''; ?>>Active</option>
                            <option value="0" <?php echo !$company['is_active'] ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="form-group">
                        <label>Phone</label>
                        <input type="tel" class="form-control" name="phone" value="<?php echo htmlspecialchars($company['phone']); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" class="form-control" name="email" value="<?php echo htmlspecialchars($company['email']); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>Address</label>
                        <textarea class="form-control" name="address" rows="4"><?php echo htmlspecialchars($company['address']); ?></textarea>
                    </div>
                </div>
            </div>
            
            <div class="row mt-3">
                <div class="col-12">
                    <button type="submit" name="edit_company" class="btn btn-primary">
                        <i class="fas fa-save mr-2"></i>Save Changes
                    </button>
                    <a href="insurance_company_view.php?id=<?php echo $company_id; ?>" class="btn btn-secondary">
                        <i class="fas fa-times mr-2"></i>Cancel
                    </a>
                    <a href="insurance_company_delete.php?id=<?php echo $company_id; ?>" class="btn btn-danger float-right" onclick="return confirm('Are you sure you want to delete this company? This action cannot be undone.')">
                        <i class="fas fa-trash mr-2"></i>Delete Company
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