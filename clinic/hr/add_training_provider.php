<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        // Start transaction
        $mysqli->begin_transaction();

        // Validate and sanitize inputs
        $provider_name = trim($_POST['provider_name']);
        $nita_accreditation_no = !empty(trim($_POST['nita_accreditation_no'] ?? '')) ? trim($_POST['nita_accreditation_no']) : null;
        $contact_person = !empty(trim($_POST['contact_person'] ?? '')) ? trim($_POST['contact_person']) : null;
        $phone = !empty(trim($_POST['phone'] ?? '')) ? trim($_POST['phone']) : null;
        $email = !empty(trim($_POST['email'] ?? '')) ? trim($_POST['email']) : null;
        $address = !empty(trim($_POST['address'] ?? '')) ? trim($_POST['address']) : null;
        $is_active = isset($_POST['is_active']) ? 1 : 1; // Default active

        // Validate required fields
        if (empty($provider_name)) {
            throw new Exception("Provider name is required!");
        }

        // Check for duplicate provider name
        $check_sql = "SELECT COUNT(*) as count FROM training_providers WHERE provider_name = ?";
        $check_stmt = $mysqli->prepare($check_sql);
        $check_stmt->bind_param("s", $provider_name);
        $check_stmt->execute();
        $duplicate_count = $check_stmt->get_result()->fetch_assoc()['count'];
        
        if ($duplicate_count > 0) {
            throw new Exception("A training provider with this name already exists!");
        }

        // Check for duplicate NITA number if provided
        if ($nita_accreditation_no) {
            $check_nita_sql = "SELECT COUNT(*) as count FROM training_providers WHERE nita_accreditation_no = ?";
            $check_nita_stmt = $mysqli->prepare($check_nita_sql);
            $check_nita_stmt->bind_param("s", $nita_accreditation_no);
            $check_nita_stmt->execute();
            $duplicate_nita_count = $check_nita_stmt->get_result()->fetch_assoc()['count'];
            
            if ($duplicate_nita_count > 0) {
                throw new Exception("A training provider with this NITA accreditation number already exists!");
            }
        }

        // Insert training provider
        $provider_sql = "INSERT INTO training_providers (
            provider_name, nita_accreditation_no, contact_person, 
            phone, email, address, is_active
        ) VALUES (?, ?, ?, ?, ?, ?, ?)";
        
        $provider_stmt = $mysqli->prepare($provider_sql);
        
        if (!$provider_stmt) {
            throw new Exception("Prepare failed: " . $mysqli->error);
        }

        $provider_stmt->bind_param("ssssssi", 
            $provider_name, $nita_accreditation_no, $contact_person,
            $phone, $email, $address, $is_active
        );
        
        if ($provider_stmt->execute()) {
            $provider_id = $mysqli->insert_id;

            // Log the action
            $audit_sql = "INSERT INTO hr_audit_log (user_id, action, description, table_name, record_id) VALUES (?, 'training_provider_created', ?, 'training_providers', ?)";
            $audit_stmt = $mysqli->prepare($audit_sql);
            $description = "Created training provider: " . $provider_name;
            $audit_stmt->bind_param("isi", $_SESSION['user_id'], $description, $provider_id);
            $audit_stmt->execute();

            // Commit transaction
            $mysqli->commit();

            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Training provider added successfully!";
            header("Location: manage_training_providers.php");
            exit;
        } else {
            throw new Exception("Provider creation failed: " . $provider_stmt->error);
        }

    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error adding training provider: " . $e->getMessage();
        error_log("Training Provider Add Error: " . $e->getMessage());
    }
}
?>

<div class="card">
    <div class="card-header bg-success py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0 text-white">
                <i class="fas fa-fw fa-building mr-2"></i>Add Training Provider
            </h3>
            <div class="card-tools">
                <a href="manage_training_providers.php" class="btn btn-light btn-sm">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Providers
                </a>
            </div>
        </div>
    </div>

    <div class="card-body">
        <?php if (isset($_SESSION['alert_message'])): ?>
            <div class="alert alert-<?php echo $_SESSION['alert_type']; ?> alert-dismissible fade show" role="alert">
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                <i class="fas fa-<?php echo $_SESSION['alert_type'] == 'success' ? 'check' : 'exclamation-triangle'; ?> mr-2"></i>
                <?php echo $_SESSION['alert_message']; ?>
            </div>
            <?php 
            unset($_SESSION['alert_type']);
            unset($_SESSION['alert_message']);
            ?>
        <?php endif; ?>

        <form method="POST" id="providerForm" autocomplete="off">
            <div class="row">
                <div class="col-md-6">
                    <!-- Provider Information -->
                    <div class="card card-primary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-info-circle mr-2"></i>Provider Information</h3>
                        </div>
                        <div class="card-body">
                            <div class="form-group mb-3">
                                <label>Provider Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="provider_name" required 
                                       maxlength="255" placeholder="Enter provider name">
                                <div class="invalid-feedback">Please enter a provider name.</div>
                            </div>

                            <div class="form-group mb-3">
                                <label>NITA Accreditation Number</label>
                                <input type="text" class="form-control" name="nita_accreditation_no" 
                                       maxlength="100" placeholder="NITA accreditation number (if applicable)">
                                <small class="form-text text-muted">Leave blank if not NITA accredited.</small>
                            </div>

                            <div class="form-group mb-3">
                                <label>Contact Person</label>
                                <input type="text" class="form-control" name="contact_person" 
                                       maxlength="255" placeholder="Primary contact person">
                            </div>
                        </div>
                    </div>

                    <!-- Contact Information -->
                    <div class="card card-info">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-address-book mr-2"></i>Contact Information</h3>
                        </div>
                        <div class="card-body">
                            <div class="form-group mb-3">
                                <label>Phone Number</label>
                                <input type="tel" class="form-control" name="phone" 
                                       maxlength="50" placeholder="Phone number">
                            </div>

                            <div class="form-group mb-3">
                                <label>Email Address</label>
                                <input type="email" class="form-control" name="email" 
                                       maxlength="255" placeholder="Email address">
                                <div class="invalid-feedback">Please enter a valid email address.</div>
                            </div>

                            <div class="form-group mb-0">
                                <label>Address</label>
                                <textarea class="form-control" name="address" rows="3" 
                                          placeholder="Physical address of the provider"></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <!-- Quick Actions -->
                    <div class="card card-success">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-bolt mr-2"></i>Quick Actions</h3>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-info">
                                <small><i class="fas fa-info-circle mr-1"></i> Fields marked with <span class="text-danger">*</span> are required.</small>
                            </div>
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-success btn-lg">
                                    <i class="fas fa-save mr-2"></i>Add Provider
                                </button>
                                <button type="reset" class="btn btn-outline-secondary">
                                    <i class="fas fa-undo mr-2"></i>Reset Form
                                </button>
                                <a href="manage_training_providers.php" class="btn btn-outline-danger">
                                    <i class="fas fa-times mr-2"></i>Cancel
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Status & Additional Info -->
                    <div class="card card-warning">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-cog mr-2"></i>Settings</h3>
                        </div>
                        <div class="card-body">
                            <div class="form-group mb-3">
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input" id="is_active" name="is_active" value="1" checked>
                                    <label class="custom-control-label" for="is_active">
                                        <strong>Active Provider</strong>
                                    </label>
                                </div>
                                <small class="form-text text-muted">Active providers can be assigned to new courses.</small>
                            </div>

                            <div class="alert alert-warning">
                                <small>
                                    <i class="fas fa-lightbulb mr-1"></i>
                                    <strong>Tip:</strong> Ensure all contact information is accurate for smooth communication.
                                </small>
                            </div>
                        </div>
                    </div>

                    <!-- NITA Information -->
                    <div class="card card-secondary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-award mr-2"></i>About NITA Accreditation</h3>
                        </div>
                        <div class="card-body">
                            <p class="small text-muted mb-2">
                                <strong>NITA</strong> (National Industrial Training Authority) accreditation ensures training providers meet national standards for quality and compliance.
                            </p>
                            <ul class="small text-muted pl-3 mb-0">
                                <li>Required for certain technical and vocational training</li>
                                <li>Enhances credibility and recognition</li>
                                <li>May be required for government contracts</li>
                                <li>Validates training quality standards</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
$(document).ready(function() {
    // Form validation
    $('#providerForm').on('submit', function(e) {
        let isValid = true;
        
        // Validate required fields
        $(this).find('[required]').each(function() {
            if (!$(this).val().trim()) {
                $(this).addClass('is-invalid');
                isValid = false;
            } else {
                $(this).removeClass('is-invalid');
            }
        });

        // Validate email format if provided
        const email = $('input[name="email"]').val();
        if (email && !isValidEmail(email)) {
            $('input[name="email"]').addClass('is-invalid');
            isValid = false;
        }

        if (!isValid) {
            e.preventDefault();
            alert('Please fix the errors in the form before submitting.');
            return false;
        }

        // Show loading state
        $('button[type="submit"]').html('<i class="fas fa-spinner fa-spin mr-2"></i>Adding Provider...').prop('disabled', true);
    });

    function isValidEmail(email) {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return emailRegex.test(email);
    }

    // Auto-format phone number
    $('input[name="phone"]').on('input', function() {
        let value = $(this).val().replace(/\D/g, '');
        if (value.length > 0) {
            // Format Kenyan phone numbers
            if (value.startsWith('0')) {
                value = '+254' + value.substring(1);
            } else if (value.startsWith('254')) {
                value = '+' + value;
            } else if (value.startsWith('7') || value.startsWith('1')) {
                value = '+254' + value;
            }
        }
        $(this).val(value);
    });
});
</script>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>