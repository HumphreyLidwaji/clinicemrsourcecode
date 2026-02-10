<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

$company_id = intval($_GET['id']);

// Get company data
$company_sql = mysqli_query($mysqli, "SELECT * FROM insurance_companies WHERE insurance_company_id = $company_id");
if (mysqli_num_rows($company_sql) == 0) {
    header("Location: insurance_management.php");
    exit;
}
$company = mysqli_fetch_assoc($company_sql);

// Check if company has schemes
$scheme_check = mysqli_query($mysqli, "SELECT scheme_id FROM insurance_schemes WHERE insurance_company_id = $company_id");
$has_schemes = mysqli_num_rows($scheme_check) > 0;

if (isset($_POST['delete_company'])) {
    if (!$has_schemes) {
        $delete_sql = mysqli_query($mysqli, "DELETE FROM insurance_companies WHERE insurance_company_id = $company_id");
        if ($delete_sql) {
            $_SESSION['alert'] = "success";
            $_SESSION['message'] = "Insurance company deleted successfully!";
            header("Location: insurance_management.php");
            exit;
        } else {
            $_SESSION['alert'] = "danger";
            $_SESSION['message'] = "Error deleting insurance company: " . mysqli_error($mysqli);
        }
    }
}

// If has schemes, redirect back with error
if ($has_schemes) {
    $_SESSION['alert'] = "danger";
    $_SESSION['message'] = "Cannot delete company - there are insurance schemes associated with this company. Please deactivate instead.";
    header("Location: insurance_company_view.php?id=$company_id");
    exit;
}
?>

<div class="card">
    <div class="card-header bg-danger text-white py-2">
        <h3 class="card-title mt-2 mb-0"><i class="fas fa-fw fa-trash mr-2"></i>Delete Insurance Company</h3>
        <div class="card-tools">
            <a href="insurance_company_view.php?id=<?php echo $company_id; ?>" class="btn btn-light">
                <i class="fas fa-arrow-left mr-2"></i>Back
            </a>
        </div>
    </div>
    
    <div class="card-body">
        <div class="alert alert-danger">
            <h5><i class="fas fa-exclamation-triangle mr-2"></i>Warning!</h5>
            <p class="mb-0">You are about to delete the insurance company <strong>"<?php echo htmlspecialchars($company['company_name']); ?>"</strong>. This action cannot be undone.</p>
        </div>
        
        <div class="card mb-3">
            <div class="card-body">
                <h6>Company Details:</h6>
                <dl class="row mb-0">
                    <dt class="col-sm-3">Company Name:</dt>
                    <dd class="col-sm-9"><?php echo htmlspecialchars($company['company_name']); ?></dd>
                    
                    <dt class="col-sm-3">Company Code:</dt>
                    <dd class="col-sm-9"><?php echo htmlspecialchars($company['company_code'] ?: '—'); ?></dd>
                    
                    <dt class="col-sm-3">Contact Person:</dt>
                    <dd class="col-sm-9"><?php echo htmlspecialchars($company['contact_person'] ?: '—'); ?></dd>
                    
                    <dt class="col-sm-3">Created:</dt>
                    <dd class="col-sm-9"><?php echo date('F j, Y', strtotime($company['created_at'])); ?></dd>
                </dl>
            </div>
        </div>
        
        <form method="POST">
            <div class="form-group">
                <label>Type "DELETE" to confirm</label>
                <input type="text" class="form-control" name="confirmation" placeholder="Type DELETE here" required pattern="DELETE">
            </div>
            
            <div class="row mt-3">
                <div class="col-12">
                    <button type="submit" name="delete_company" class="btn btn-danger">
                        <i class="fas fa-trash mr-2"></i>Delete Company
                    </button>
                    <a href="insurance_company_view.php?id=<?php echo $company_id; ?>" class="btn btn-secondary">
                        <i class="fas fa-times mr-2"></i>Cancel
                    </a>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
$(document).ready(function() {
    // Focus on confirmation field
    $('input[name="confirmation"]').focus();
});
</script>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>