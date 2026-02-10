<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $supplier_name = sanitizeInput($_POST['supplier_name']);
    $supplier_contact_name = sanitizeInput($_POST['supplier_contact_name']);
    $supplier_email = sanitizeInput($_POST['supplier_email']);
    $supplier_phone = sanitizeInput($_POST['supplier_phone']);
    $supplier_address = sanitizeInput($_POST['supplier_address']);
    $supplier_city = sanitizeInput($_POST['supplier_city']);
    $supplier_state = sanitizeInput($_POST['supplier_state']);
    $supplier_zip = sanitizeInput($_POST['supplier_zip']);
    $supplier_country = sanitizeInput($_POST['supplier_country']);
    $supplier_notes = sanitizeInput($_POST['supplier_notes']);
    $supplier_is_active = intval($_POST['supplier_is_active']);

    // Validate required fields
    if (empty($supplier_name)) {
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Supplier name is required.";
    } else {
        // Check for duplicate supplier name
        $check_sql = mysqli_query($mysqli, 
            "SELECT supplier_id FROM suppliers 
             WHERE supplier_name = '$supplier_name' 
             LIMIT 1"
        );

        if (mysqli_num_rows($check_sql) > 0) {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "A supplier with this name already exists.";
        } else {
            // Insert new supplier
            $insert_sql = mysqli_query($mysqli,
                "INSERT INTO suppliers SET
                    supplier_name = '$supplier_name',
                    supplier_contact = '$supplier_contact_name',
                    supplier_email = '$supplier_email',
                    supplier_phone = '$supplier_phone',
                    supplier_address = '$supplier_address',
                    supplier_city = '$supplier_city',
                    supplier_state = '$supplier_state',
                    supplier_zip = '$supplier_zip',
                    supplier_country = '$supplier_country',
                    supplier_notes = '$supplier_notes',
                    supplier_is_active = $supplier_is_active,
                    supplier_created_at = NOW(),
                    supplier_updated_at = NOW()"
            );

            if ($insert_sql) {
                $new_supplier_id = mysqli_insert_id($mysqli);
                
                $_SESSION['alert_type'] = "success";
                $_SESSION['alert_message'] = "Supplier added successfully.";
                
                // Log the activity
                mysqli_query($mysqli,
                    "INSERT INTO logs SET
                    log_type = 'Supplier',
                    log_action = 'Create',
                    log_description = 'Supplier $supplier_name was created',
                    log_ip = '$session_ip',
                    log_user_agent = '$session_user_agent',
                    log_user_id = $session_user_id"
                );

                // Redirect to edit page or suppliers list
                if (isset($_POST['add_another'])) {
                    // Stay on the same page to add another supplier
                    $_SESSION['alert_type'] = "success";
                    $_SESSION['alert_message'] = "Supplier added successfully. You can add another supplier below.";
                    header("Location: supplier_add.php");
                    exit;
                } else {
                    // Redirect to suppliers list
                    header("Location: suppliers.php");
                    exit;
                }
            } else {
                $_SESSION['alert_type'] = "error";
                $_SESSION['alert_message'] = "Error adding supplier: " . mysqli_error($mysqli);
            }
        }
    }
    
    // If there was an error, preserve form data
    $form_data = [
        'supplier_name' => $supplier_name,
        'supplier_contact_name' => $supplier_contact_name,
        'supplier_email' => $supplier_email,
        'supplier_phone' => $supplier_phone,
        'supplier_address' => $supplier_address,
        'supplier_city' => $supplier_city,
        'supplier_state' => $supplier_state,
        'supplier_zip' => $supplier_zip,
        'supplier_country' => $supplier_country,
        'supplier_notes' => $supplier_notes,
        'supplier_is_active' => $supplier_is_active
    ];
}
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0"><i class="fas fa-fw fa-plus-circle mr-2"></i>Add New Supplier</h3>
        <div class="card-tools">
            <a href="suppliers.php" class="btn btn-light">
                <i class="fas fa-fw fa-arrow-left mr-2"></i>Back to Suppliers
            </a>
        </div>
    </div>

    <div class="card-body">
        <?php if (isset($_SESSION['alert_message'])): ?>
            <div class="alert alert-<?php echo $_SESSION['alert_type']; ?> alert-dismissible">
                <button type="button" class="close" data-dismiss="alert">&times;</button>
                <i class="icon fas fa-<?php echo $_SESSION['alert_type'] == 'success' ? 'check' : 'exclamation-triangle'; ?>"></i>
                <?php echo $_SESSION['alert_message']; ?>
            </div>
            <?php 
            unset($_SESSION['alert_type']);
            unset($_SESSION['alert_message']);
            ?>
        <?php endif; ?>

        <div class="row">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header bg-light py-2">
                        <h5 class="card-title mb-0"><i class="fas fa-fw fa-info-circle mr-2"></i>Supplier Information</h5>
                    </div>
                    <div class="card-body">
                        <form action="supplier_add.php" method="POST" autocomplete="off">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Supplier Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="supplier_name" 
                                               value="<?php echo isset($form_data) ? nullable_htmlentities($form_data['supplier_name']) : ''; ?>" 
                                               required autofocus
                                               placeholder="Enter supplier company name">
                                        <small class="form-text text-muted">The official name of the supplier company</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Contact Person</label>
                                        <input type="text" class="form-control" name="supplier_contact_name" 
                                               value="<?php echo isset($form_data) ? nullable_htmlentities($form_data['supplier_contact_name']) : ''; ?>"
                                               placeholder="Primary contact person">
                                        <small class="form-text text-muted">Main point of contact at the supplier</small>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Email</label>
                                        <input type="email" class="form-control" name="supplier_email" 
                                               value="<?php echo isset($form_data) ? nullable_htmlentities($form_data['supplier_email']) : ''; ?>"
                                               placeholder="supplier@example.com">
                                        <small class="form-text text-muted">Primary email address for orders and communication</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Phone</label>
                                        <input type="text" class="form-control" name="supplier_phone" 
                                               value="<?php echo isset($form_data) ? nullable_htmlentities($form_data['supplier_phone']) : ''; ?>"
                                               placeholder="(555) 123-4567">
                                        <small class="form-text text-muted">Primary phone number</small>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Address</label>
                                <input type="text" class="form-control" name="supplier_address" 
                                       value="<?php echo isset($form_data) ? nullable_htmlentities($form_data['supplier_address']) : ''; ?>"
                                       placeholder="Street address">
                                <small class="form-text text-muted">Street address for shipping and correspondence</small>
                            </div>

                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>City</label>
                                        <input type="text" class="form-control" name="supplier_city" 
                                               value="<?php echo isset($form_data) ? nullable_htmlentities($form_data['supplier_city']) : ''; ?>"
                                               placeholder="City">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>State/Province</label>
                                        <input type="text" class="form-control" name="supplier_state" 
                                               value="<?php echo isset($form_data) ? nullable_htmlentities($form_data['supplier_state']) : ''; ?>"
                                               placeholder="State">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>ZIP/Postal Code</label>
                                        <input type="text" class="form-control" name="supplier_zip" 
                                               value="<?php echo isset($form_data) ? nullable_htmlentities($form_data['supplier_zip']) : ''; ?>"
                                               placeholder="ZIP code">
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Country</label>
                                <input type="text" class="form-control" name="supplier_country" 
                                       value="<?php echo isset($form_data) ? nullable_htmlentities($form_data['supplier_country']) : ''; ?>"
                                       placeholder="Country">
                            </div>

                            <div class="form-group">
                                <label>Notes</label>
                                <textarea class="form-control" name="supplier_notes" rows="4" 
                                          placeholder="Any additional notes about this supplier (payment terms, special requirements, etc.)"><?php echo isset($form_data) ? nullable_htmlentities($form_data['supplier_notes']) : ''; ?></textarea>
                                <small class="form-text text-muted">Additional information that might be helpful for your team</small>
                            </div>

                            <div class="form-group">
                                <label>Status <span class="text-danger">*</span></label>
                                <select class="form-control" name="supplier_is_active" required>
                                    <option value="1" <?php echo (isset($form_data) && $form_data['supplier_is_active'] == 1) ? 'selected' : 'selected'; ?>>Active</option>
                                    <option value="0" <?php echo (isset($form_data) && $form_data['supplier_is_active'] == 0) ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                                <small class="form-text text-muted">Active suppliers can be used for new orders and items</small>
                            </div>

                            <div class="form-group">
                                <button type="submit" name="save" class="btn btn-primary">
                                    <i class="fas fa-fw fa-save mr-2"></i>Save Supplier
                                </button>
                                <button type="submit" name="add_another" class="btn btn-success">
                                    <i class="fas fa-fw fa-plus-circle mr-2"></i>Save & Add Another
                                </button>
                                <a href="suppliers.php" class="btn btn-secondary">
                                    <i class="fas fa-fw fa-times mr-2"></i>Cancel
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <!-- Help Card -->
                <div class="card">
                    <div class="card-header bg-light py-2">
                        <h5 class="card-title mb-0"><i class="fas fa-fw fa-question-circle mr-2"></i>Adding a New Supplier</h5>
                    </div>
                    <div class="card-body">
                        <h6 class="text-info"><i class="fas fa-asterisk text-danger mr-1"></i> Required Information</h6>
                        <ul class="small pl-3 mb-3">
                            <li><strong>Supplier Name</strong> - Official company name</li>
                            <li><strong>Status</strong> - Active or Inactive</li>
                        </ul>

                        <h6 class="text-info"><i class="fas fa-star text-warning mr-1"></i> Recommended Information</h6>
                        <ul class="small pl-3 mb-3">
                            <li>Contact person for communication</li>
                            <li>Email and phone for orders</li>
                            <li>Address for shipping</li>
                        </ul>

                        <h6 class="text-info"><i class="fas fa-lightbulb text-info mr-1"></i> Tips</h6>
                        <ul class="small pl-3">
                            <li>Use consistent naming for similar suppliers</li>
                            <li>Add notes about payment terms or special requirements</li>
                            <li>Keep supplier information up to date</li>
                        </ul>

                        <div class="alert alert-warning small mt-3">
                            <i class="fas fa-exclamation-triangle mr-2"></i>
                            <strong>Note:</strong> Only active suppliers can be selected when creating purchase orders or adding inventory items.
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="card mt-3">
                    <div class="card-header bg-light py-2">
                        <h5 class="card-title mb-0"><i class="fas fa-fw fa-bolt mr-2"></i>Quick Actions</h5>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="suppliers.php" class="btn btn-outline-primary btn-sm">
                                <i class="fas fa-list mr-2"></i>View All Suppliers
                            </a>
                            <a href="inventory_add_item.php" class="btn btn-outline-success btn-sm">
                                <i class="fas fa-boxes mr-2"></i>Add Inventory Item
                            </a>
                            <a href="purchase_order_create.php" class="btn btn-outline-warning btn-sm">
                                <i class="fas fa-shopping-cart mr-2"></i>Create Purchase Order
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Recent Suppliers -->
                <div class="card mt-3">
                    <div class="card-header bg-light py-2">
                        <h5 class="card-title mb-0"><i class="fas fa-fw fa-history mr-2"></i>Recent Suppliers</h5>
                    </div>
                    <div class="card-body">
                        <?php
                        $recent_suppliers_sql = mysqli_query($mysqli,
                            "SELECT supplier_name, supplier_contact_name, supplier_is_active 
                             FROM suppliers 
                             ORDER BY supplier_created_at DESC 
                             LIMIT 5"
                        );
                        
                        if (mysqli_num_rows($recent_suppliers_sql) > 0) {
                            echo '<div class="list-group list-group-flush small">';
                            while ($recent = mysqli_fetch_assoc($recent_suppliers_sql)) {
                                $status_class = $recent['supplier_is_active'] ? 'success' : 'danger';
                                $status_text = $recent['supplier_is_active'] ? 'Active' : 'Inactive';
                                echo '
                                <div class="list-group-item px-0">
                                    <div class="font-weight-bold">' . nullable_htmlentities($recent['supplier_name']) . '</div>
                                    ' . ($recent['supplier_contact_name'] ? '<div class="text-muted">' . nullable_htmlentities($recent['supplier_contact_name']) . '</div>' : '') . '
                                    <span class="badge badge-' . $status_class . ' badge-sm">' . $status_text . '</span>
                                </div>';
                            }
                            echo '</div>';
                        } else {
                            echo '<p class="text-muted small mb-0">No suppliers added yet.</p>';
                        }
                        ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Form validation
    $('form').submit(function(e) {
        const supplierName = $('input[name="supplier_name"]').val().trim();
        if (!supplierName) {
            e.preventDefault();
            alert('Supplier name is required.');
            $('input[name="supplier_name"]').focus();
            return false;
        }
    });

    // Phone number formatting
    $('input[name="supplier_phone"]').on('input', function() {
        let phone = $(this).val().replace(/\D/g, '');
        if (phone.length > 0) {
            if (phone.length <= 3) {
                phone = phone;
            } else if (phone.length <= 6) {
                phone = phone.replace(/(\d{3})(\d{0,3})/, '($1) $2');
            } else {
                phone = phone.replace(/(\d{3})(\d{3})(\d{0,4})/, '($1) $2-$3');
            }
            $(this).val(phone);
        }
    });

    // Auto-focus on supplier name field
    $('input[name="supplier_name"]').focus();

    // Clear form data when coming from a successful "Add Another"
    <?php if (isset($_SESSION['alert_type']) && $_SESSION['alert_type'] == 'success' && strpos($_SESSION['alert_message'], 'add another') !== false): ?>
        // Clear any stored form data
        $('form')[0].reset();
    <?php endif; ?>

    // Auto-close alerts after 5 seconds
    setTimeout(() => {
        $('.alert').alert('close');
    }, 5000);
});

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + S to save
    if (e.ctrlKey && e.keyCode === 83) {
        e.preventDefault();
        $('button[name="save"]').click();
    }
    // Ctrl + Shift + S to save and add another
    if (e.ctrlKey && e.shiftKey && e.keyCode === 83) {
        e.preventDefault();
        $('button[name="add_another"]').click();
    }
    // Escape to cancel
    if (e.keyCode === 27) {
        e.preventDefault();
        window.location.href = 'suppliers.php';
    }
});
</script>

<style>
.list-group-item {
    border: none;
    padding: 0.5rem 0;
}
.badge-sm {
    font-size: 0.7em;
}
.card-header {
    background-color: #f8f9fa;
    border-bottom: 1px solid #dee2e6;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>