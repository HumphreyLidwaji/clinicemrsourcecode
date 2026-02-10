<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
// Get supplier ID from URL
$supplier_id = intval($_GET['supplier_id']);

if (!$supplier_id) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Supplier ID is required.";
    header("Location: suppliers.php");
    exit;
}

// Fetch supplier data
$sql = mysqli_query($mysqli, 
    "SELECT * FROM suppliers WHERE supplier_id = $supplier_id"
);

if (mysqli_num_rows($sql) == 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Supplier not found.";
    header("Location: suppliers.php");
    exit;
}

$supplier = mysqli_fetch_assoc($sql);

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
        // Check for duplicate supplier name (excluding current supplier)
        $check_sql = mysqli_query($mysqli, 
            "SELECT supplier_id FROM suppliers 
             WHERE supplier_name = '$supplier_name' 
             AND supplier_id != $supplier_id
             LIMIT 1"
        );

        if (mysqli_num_rows($check_sql) > 0) {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "A supplier with this name already exists.";
        } else {
            // Update supplier
            $update_sql = mysqli_query($mysqli,
                "UPDATE suppliers SET
                    supplier_name = '$supplier_name',
                    supplier_contact= '$supplier_contact_name',
                    supplier_email = '$supplier_email',
                    supplier_phone = '$supplier_phone',
                    supplier_address = '$supplier_address',
                    supplier_city = '$supplier_city',
                    supplier_state = '$supplier_state',
                    supplier_zip = '$supplier_zip',
                    supplier_country = '$supplier_country',
                    supplier_notes = '$supplier_notes',
                    supplier_is_active = $supplier_is_active,
                    supplier_updated_at = NOW()
                WHERE supplier_id = $supplier_id"
            );

            if ($update_sql) {
                $_SESSION['alert_type'] = "success";
                $_SESSION['alert_message'] = "Supplier updated successfully.";
                
                // Log the activity
                mysqli_query($mysqli,
                    "INSERT INTO logs SET
                    log_type = 'Supplier',
                    log_action = 'Modify',
                    log_description = 'Supplier $supplier_name was updated',
                    log_ip = '$session_ip',
                    log_user_agent = '$session_user_agent',
                    log_user_id = $session_user_id"
                );

                header("Location: suppliers.php");
                exit;
            } else {
                $_SESSION['alert_type'] = "error";
                $_SESSION['alert_message'] = "Error updating supplier: " . mysqli_error($mysqli);
            }
        }
    }
}

// Get supplier statistics for the sidebar
$stats_sql = mysqli_query($mysqli,
    "SELECT 
        COUNT(i.item_id) as items_count,
        COUNT(po.order_id) as pending_orders,
        SUM(poi.quantity_ordered * poi.unit_cost) as total_orders_value
    FROM suppliers s
    LEFT JOIN inventory_items i ON s.supplier_id = i.item_supplier_id
    LEFT JOIN purchase_orders po ON s.supplier_id = po.supplier_id AND po.order_status IN ('pending', 'ordered', 'shipped')
    LEFT JOIN purchase_order_items poi ON po.order_id = poi.order_id
    WHERE s.supplier_id = $supplier_id
    GROUP BY s.supplier_id"
);


$stats = mysqli_fetch_assoc($stats_sql);
$items_count = $stats['items_count'] ?? 0;
$pending_orders = $stats['pending_orders'] ?? 0;
$total_orders_value = $stats['total_orders_value'] ?? 0;
?>

<div class="card">
    <div class="card-header bg-info py-2">
        <h3 class="card-title mt-2 mb-0"><i class="fas fa-fw fa-edit mr-2"></i>Edit Supplier</h3>
        <div class="card-tools">
            <a href="suppliers.php" class="btn btn-secondary">
                <i class="fas fa-fw fa-arrow-left mr-2"></i>Back to Suppliers
            </a>
        </div>
    </div>

    <div class="card-body">
        <div class="row">
            <div class="col-md-8">
                <form action="supplier_edit.php?supplier_id=<?php echo $supplier_id; ?>" method="POST" autocomplete="off">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Supplier Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="supplier_name" value="<?php echo nullable_htmlentities($supplier['supplier_name']); ?>" required autofocus>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Contact Person</label>
                                <input type="text" class="form-control" name="supplier_contact_name" value="<?php echo nullable_htmlentities($supplier['supplier_contact']); ?>">
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Email</label>
                                <input type="email" class="form-control" name="supplier_email" value="<?php echo nullable_htmlentities($supplier['supplier_email']); ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Phone</label>
                                <input type="text" class="form-control" name="supplier_phone" value="<?php echo nullable_htmlentities($supplier['supplier_phone']); ?>">
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Address</label>
                        <input type="text" class="form-control" name="supplier_address" value="<?php echo nullable_htmlentities($supplier['supplier_address']); ?>">
                    </div>

                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>City</label>
                                <input type="text" class="form-control" name="supplier_city" value="<?php echo nullable_htmlentities($supplier['supplier_city']); ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>State/Province</label>
                                <input type="text" class="form-control" name="supplier_state" value="<?php echo nullable_htmlentities($supplier['supplier_state']); ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>ZIP/Postal Code</label>
                                <input type="text" class="form-control" name="supplier_zip" value="<?php echo nullable_htmlentities($supplier['supplier_zip']); ?>">
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Country</label>
                        <input type="text" class="form-control" name="supplier_country" value="<?php echo nullable_htmlentities($supplier['supplier_country']); ?>">
                    </div>

                    <div class="form-group">
                        <label>Notes</label>
                        <textarea class="form-control" name="supplier_notes" rows="4" placeholder="Any additional notes about this supplier..."><?php echo nullable_htmlentities($supplier['supplier_notes']); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label>Status</label>
                        <select class="form-control" name="supplier_is_active" required>
                            <option value="1" <?php echo $supplier['supplier_is_active'] ? 'selected' : ''; ?>>Active</option>
                            <option value="0" <?php echo !$supplier['supplier_is_active'] ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-fw fa-save mr-2"></i>Update Supplier
                        </button>
                        <a href="suppliers.php" class="btn btn-secondary">
                            <i class="fas fa-fw fa-times mr-2"></i>Cancel
                        </a>
                        <?php if ($items_count == 0): ?>
                            <a href="post.php?delete_supplier=<?php echo $supplier_id; ?>" class="btn btn-danger float-right" onclick="return confirm('Are you sure you want to delete this supplier? This action cannot be undone.');">
                                <i class="fas fa-fw fa-trash mr-2"></i>Delete Supplier
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <div class="col-md-4">
                <!-- Supplier Statistics -->
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0"><i class="fas fa-chart-bar mr-2"></i>Supplier Statistics</h5>
                    </div>
                    <div class="card-body">
                        <div class="text-center mb-4">
                            <div class="supplier-avatar bg-primary rounded-circle d-inline-flex align-items-center justify-content-center" style="width: 80px; height: 80px;">
                                <span class="h4 text-white font-weight-bold">
                                    <?php echo strtoupper(substr($supplier['supplier_name'], 0, 2)); ?>
                                </span>
                            </div>
                            <h5 class="mt-3 mb-1"><?php echo nullable_htmlentities($supplier['supplier_name']); ?></h5>
                            <span class="badge badge-<?php echo $supplier['supplier_is_active'] ? 'success' : 'danger'; ?>">
                                <?php echo $supplier['supplier_is_active'] ? 'Active' : 'Inactive'; ?>
                            </span>
                        </div>

                        <div class="list-group list-group-flush">
                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                <div>
                                    <i class="fas fa-boxes text-primary mr-2"></i>
                                    <span>Items Supplied</span>
                                </div>
                                <span class="badge badge-primary badge-pill"><?php echo $items_count; ?></span>
                            </div>
                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                <div>
                                    <i class="fas fa-shopping-cart text-warning mr-2"></i>
                                    <span>Pending Orders</span>
                                </div>
                                <span class="badge badge-warning badge-pill"><?php echo $pending_orders; ?></span>
                            </div>
                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                <div>
                                    <i class="fas fa-dollar-sign text-success mr-2"></i>
                                    <span>Total Orders Value</span>
                                </div>
                                <span class="text-success font-weight-bold">$<?php echo number_format($total_orders_value, 2); ?></span>
                            </div>
                        </div>

                        <div class="mt-4">
                            <h6 class="text-muted mb-2">Quick Actions</h6>
                            <div class="d-grid gap-2">
                                <a href="inventory.php?supplier=<?php echo $supplier_id; ?>" class="btn btn-outline-primary btn-sm">
                                    <i class="fas fa-boxes mr-2"></i>View Items
                                </a>
                                <a href="purchase_orders.php?supplier=<?php echo $supplier_id; ?>" class="btn btn-outline-warning btn-sm">
                                    <i class="fas fa-shopping-cart mr-2"></i>View Orders
                                </a>
                                <a href="purchase_order_create.php?supplier_id=<?php echo $supplier_id; ?>" class="btn btn-outline-success btn-sm">
                                    <i class="fas fa-plus mr-2"></i>Create Order
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Supplier Information -->
                <div class="card mt-3">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0"><i class="fas fa-info-circle mr-2"></i>Supplier Information</h5>
                    </div>
                    <div class="card-body">
                        <div class="small">
                            <div class="mb-2">
                                <strong>Created:</strong><br>
                                <?php echo date('M j, Y g:i A', strtotime($supplier['supplier_created_at'])); ?>
                            </div>
                            <?php if ($supplier['supplier_updated_at'] && $supplier['supplier_updated_at'] != $supplier['supplier_created_at']): ?>
                                <div class="mb-2">
                                    <strong>Last Updated:</strong><br>
                                    <?php echo date('M j, Y g:i A', strtotime($supplier['supplier_updated_at'])); ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($supplier['supplier_contact']): ?>
                                <div class="mb-2">
                                    <strong>Contact Person:</strong><br>
                                    <?php echo nullable_htmlentities($supplier['supplier_contact']); ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($supplier['supplier_email']): ?>
                                <div class="mb-2">
                                    <strong>Email:</strong><br>
                                    <a href="mailto:<?php echo nullable_htmlentities($supplier['supplier_email']); ?>">
                                        <?php echo nullable_htmlentities($supplier['supplier_email']); ?>
                                    </a>
                                </div>
                            <?php endif; ?>
                            <?php if ($supplier['supplier_phone']): ?>
                                <div class="mb-2">
                                    <strong>Phone:</strong><br>
                                    <a href="tel:<?php echo nullable_htmlentities($supplier['supplier_phone']); ?>">
                                        <?php echo nullable_htmlentities($supplier['supplier_phone']); ?>
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
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
});
</script>

<style>
.supplier-avatar {
    font-size: 1.5rem;
    border: 3px solid #e9ecef;
}
.list-group-item {
    border: none;
    padding: 0.75rem 0;
}
</style>

<?php 
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
    ?>
    