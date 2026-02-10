<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

$scheme_id = intval($_GET['id']);

// Get scheme data
$scheme_sql = mysqli_query($mysqli, "
    SELECT s.*, c.company_name 
    FROM insurance_schemes s
    LEFT JOIN insurance_companies c ON s.insurance_company_id = c.insurance_company_id
    WHERE s.scheme_id = $scheme_id
");
if (mysqli_num_rows($scheme_sql) == 0) {
    header("Location: insurance_schemes.php");
    exit;
}
$scheme = mysqli_fetch_assoc($scheme_sql);

// Check if scheme has patients
$patient_check = mysqli_query($mysqli, "SELECT patient_id FROM patients WHERE insurance_scheme_id = $scheme_id");
$has_patients = mysqli_num_rows($patient_check) > 0;

if (isset($_POST['delete_scheme'])) {
    if (!$has_patients) {
        $delete_sql = mysqli_query($mysqli, "DELETE FROM insurance_schemes WHERE scheme_id = $scheme_id");
        if ($delete_sql) {
            $_SESSION['alert'] = "success";
            $_SESSION['message'] = "Insurance scheme deleted successfully!";
            header("Location: insurance_schemes.php");
            exit;
        } else {
            $_SESSION['alert'] = "danger";
            $_SESSION['message'] = "Error deleting insurance scheme: " . mysqli_error($mysqli);
        }
    }
}

// If has patients, redirect back with error
if ($has_patients) {
    $_SESSION['alert'] = "danger";
    $_SESSION['message'] = "Cannot delete scheme - there are patients associated with this scheme. Please deactivate instead.";
    header("Location: insurance_scheme_view.php?id=$scheme_id");
    exit;
}
?>

<div class="card">
    <div class="card-header bg-danger text-white py-2">
        <h3 class="card-title mt-2 mb-0"><i class="fas fa-fw fa-trash mr-2"></i>Delete Insurance Scheme</h3>
        <div class="card-tools">
            <a href="insurance_scheme_view.php?id=<?php echo $scheme_id; ?>" class="btn btn-light">
                <i class="fas fa-arrow-left mr-2"></i>Back
            </a>
        </div>
    </div>
    
    <div class="card-body">
        <div class="alert alert-danger">
            <h5><i class="fas fa-exclamation-triangle mr-2"></i>Warning!</h5>
            <p class="mb-0">You are about to delete the insurance scheme <strong>"<?php echo htmlspecialchars($scheme['scheme_name']); ?>"</strong>. This action cannot be undone.</p>
        </div>
        
        <div class="card mb-3">
            <div class="card-body">
                <h6>Scheme Details:</h6>
                <dl class="row mb-0">
                    <dt class="col-sm-3">Scheme Name:</dt>
                    <dd class="col-sm-9"><?php echo htmlspecialchars($scheme['scheme_name']); ?></dd>
                    
                    <dt class="col-sm-3">Scheme Code:</dt>
                    <dd class="col-sm-9"><?php echo htmlspecialchars($scheme['scheme_code'] ?: '—'); ?></dd>
                    
                    <dt class="col-sm-3">Company:</dt>
                    <dd class="col-sm-9"><?php echo htmlspecialchars($scheme['company_name']); ?></dd>
                    
                    <dt class="col-sm-3">Type:</dt>
                    <dd class="col-sm-9"><?php echo htmlspecialchars($scheme['scheme_type']); ?></dd>
                    
                    <dt class="col-sm-3">Annual Limit:</dt>
                    <dd class="col-sm-9">
                        <?php if ($scheme['annual_limit']): ?>
                            $<?php echo number_format($scheme['annual_limit'], 2); ?>
                        <?php else: ?>
                            <span class="text-muted">Unlimited</span>
                        <?php endif; ?>
                    </dd>
                    
                    <dt class="col-sm-3">Status:</dt>
                    <dd class="col-sm-9">
                        <span class="badge badge-<?php echo $scheme['is_active'] ? 'success' : 'secondary'; ?>">
                            <?php echo $scheme['is_active'] ? 'Active' : 'Inactive'; ?>
                        </span>
                    </dd>
                    
                    <dt class="col-sm-3">Created:</dt>
                    <dd class="col-sm-9"><?php echo date('F j, Y', strtotime($scheme['created_at'])); ?></dd>
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
                    <button type="submit" name="delete_scheme" class="btn btn-danger">
                        <i class="fas fa-trash mr-2"></i>Delete Scheme
                    </button>
                    <a href="insurance_scheme_view.php?id=<?php echo $scheme_id; ?>" class="btn btn-secondary">
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