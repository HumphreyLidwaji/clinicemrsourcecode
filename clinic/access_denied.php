<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
?>
<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-danger text-white">
                    <h3 class="card-title mb-0">
                        <i class="fas fa-exclamation-triangle mr-2"></i>Access Denied
                    </h3>
                </div>
                <div class="card-body text-center">
                    <i class="fas fa-lock fa-4x text-muted mb-3"></i>
                    <h4 class="text-danger">Permission Required</h4>
                    <p class="text-muted">You don't have the necessary permissions to access this resource.</p>
                    <div class="mt-4">
                        <a href="dashboard.php" class="btn btn-primary">
                            <i class="fas fa-tachometer-alt mr-2"></i>Return to Dashboard
                        </a>
                        <a href="javascript:history.back()" class="btn btn-secondary ml-2">
                            <i class="fas fa-arrow-left mr-2"></i>Go Back
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
 ?>