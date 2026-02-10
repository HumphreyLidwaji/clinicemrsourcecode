<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Default Column Sortby/Order Filter
$sort = "drug_name";
$order = "ASC";

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Filter parameters
$category_filter = $_GET['category'] ?? '';
$status_filter = $_GET['status'] ?? '';

// Category Filter
if ($category_filter) {
    $category_query = "AND drug_category = '" . sanitizeInput($category_filter) . "'";
} else {
    $category_query = '';
}

// Status Filter
if ($status_filter && $status_filter != 'all') {
    if ($status_filter == 'active') {
        $status_query = "AND drug_is_active = 1 AND drug_archived_at IS NULL";
    } elseif ($status_filter == 'inactive') {
        $status_query = "AND drug_is_active = 0";
    } elseif ($status_filter == 'archived') {
        $status_query = "AND drug_archived_at IS NOT NULL";
    }
} else {
    $status_query = '';
}

// Search Query
$q = sanitizeInput($_GET['q'] ?? '');
if (!empty($q)) {
    $search_query = "AND (
        drug_name LIKE '%$q%' 
        OR drug_generic_name LIKE '%$q%'
        OR drug_manufacturer LIKE '%$q%'
        OR drug_description LIKE '%$q%'
        OR drug_form LIKE '%$q%'
        OR drug_strength LIKE '%$q%'
    )";
} else {
    $search_query = '';
}

// Main query for drugs
$sql = mysqli_query(
    $mysqli,
    "
    SELECT SQL_CALC_FOUND_ROWS *,
           (SELECT COUNT(*) FROM inventory_items WHERE drug_id = drugs.drug_id) as inventory_count,
           (SELECT COUNT(*) FROM prescription_items WHERE pi_drug_id = drugs.drug_id) as prescription_count
    FROM drugs
    WHERE 1=1
      $category_query
      $status_query
      $search_query
    ORDER BY $sort $order
    LIMIT $record_from, $record_to
");

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Get statistics
$total_drugs = $num_rows[0];
$active_drugs = 0;
$inactive_drugs = 0;
$archived_drugs = 0;
$with_inventory = 0;
$with_prescriptions = 0;

// Reset pointer and calculate
mysqli_data_seek($sql, 0);
while ($drug = mysqli_fetch_assoc($sql)) {
    if ($drug['drug_archived_at']) {
        $archived_drugs++;
    } elseif ($drug['drug_is_active']) {
        $active_drugs++;
    } else {
        $inactive_drugs++;
    }
    
    if ($drug['inventory_count'] > 0) {
        $with_inventory++;
    }
    if ($drug['prescription_count'] > 0) {
        $with_prescriptions++;
    }
}
mysqli_data_seek($sql, $record_from);

// Get unique categories for filter
$categories_sql = mysqli_query($mysqli, "
    SELECT DISTINCT drug_category 
    FROM drugs 
    WHERE drug_category IS NOT NULL AND drug_category != ''
    ORDER BY drug_category"
);

// Common drug forms for quick reference
$common_forms = ['Tablet', 'Capsule', 'Syrup', 'Injection', 'Ointment', 'Cream', 'Drops', 'Inhaler', 'Spray', 'Suppository'];
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0">
                <i class="fas fa-fw fa-capsules mr-2"></i>Drugs Master Database
            </h3>
            <div class="card-tools">
                <a href="drugs_add.php" class="btn btn-success">
                    <i class="fas fa-plus mr-2"></i>Add Drug
                </a>
            </div>
        </div>
    </div>
    
    <!-- Statistics Row -->
    <div class="card-body border-bottom py-3">
        <div class="row text-center">
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-primary"><i class="fas fa-capsules"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Total Drugs</span>
                        <span class="info-box-number"><?php echo $total_drugs; ?></span>
                        <span class="info-box-text small text-muted">
                            In database
                        </span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-success"><i class="fas fa-check-circle"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Active</span>
                        <span class="info-box-number"><?php echo $active_drugs; ?></span>
                        <span class="info-box-text small text-success">
                            Available for use
                        </span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-warning"><i class="fas fa-pause-circle"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Inactive</span>
                        <span class="info-box-number"><?php echo $inactive_drugs; ?></span>
                        <span class="info-box-text small text-warning">
                            Temporarily disabled
                        </span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-info"><i class="fas fa-boxes"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">With Inventory</span>
                        <span class="info-box-number"><?php echo $with_inventory; ?></span>
                        <span class="info-box-text small text-info">
                            Linked to stock
                        </span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-secondary"><i class="fas fa-prescription"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Prescribed</span>
                        <span class="info-box-number"><?php echo $with_prescriptions; ?></span>
                        <span class="info-box-text small text-secondary">
                            In prescriptions
                        </span>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="info-box bg-light">
                    <span class="info-box-icon bg-danger"><i class="fas fa-archive"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Archived</span>
                        <span class="info-box-number"><?php echo $archived_drugs; ?></span>
                        <span class="info-box-text small text-danger">
                            No longer used
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card-header pb-2 pt-3">
        <form autocomplete="off">
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <div class="input-group">
                            <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search drug names, generic names, manufacturers..." autofocus>
                            <div class="input-group-append">
                                <button class="btn btn-secondary" type="button" data-toggle="collapse" data-target="#advancedFilter">
                                    <i class="fas fa-filter"></i>
                                </button>
                                <button class="btn btn-primary">
                                    <i class="fa fa-search"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="btn-toolbar form-group float-right">
                        <div class="btn-group">
                            <span class="btn btn-light border">
                                <i class="fas fa-capsules text-primary mr-1"></i>
                                Total: <strong><?php echo $total_drugs; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-check-circle text-success mr-1"></i>
                                Active: <strong><?php echo $active_drugs; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-boxes text-info mr-1"></i>
                                In Stock: <strong><?php echo $with_inventory; ?></strong>
                            </span>
                            <a href="?<?php echo $url_query_strings_sort ?>&export=pdf" class="btn btn-light border ml-2">
                                <i class="fa fa-fw fa-file-pdf mr-2"></i>Export
                            </a>
                           
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse <?php 
                if ($category_filter || $status_filter) { 
                    echo "show"; 
                } 
            ?>" id="advancedFilter">
                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Category</label>
                            <select class="form-control select2" name="category" onchange="this.form.submit()">
                                <option value="">- All Categories -</option>
                                <?php
                                while($category = mysqli_fetch_assoc($categories_sql)) {
                                    $category_name = nullable_htmlentities($category['drug_category']);
                                    $selected = $category_filter == $category_name ? 'selected' : '';
                                    echo "<option value='$category_name' $selected>$category_name</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Status</label>
                            <select class="form-control select2" name="status" onchange="this.form.submit()">
                                <option value="all">- All Status -</option>
                                <option value="active" <?php echo $status_filter == 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo $status_filter == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                <option value="archived" <?php echo $status_filter == 'archived' ? 'selected' : ''; ?>>Archived</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Quick Actions</label>
                            <div class="btn-group-vertical w-100">
                                <a href="drugs_add.php" class="btn btn-outline-success btn-sm">
                                    <i class="fas fa-plus mr-1"></i> Add New Drug
                                </a>
                                <a href="drugs_manage.php" class="btn btn-outline-secondary btn-sm">
                                    <i class="fas fa-times mr-1"></i> Clear Filters
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row mt-2">
                    <div class="col-md-12">
                        <div class="form-group mb-0">
                            <label>Quick Status Filters</label>
                            <div class="btn-group btn-group-toggle" data-toggle="buttons">
                                <a href="?status=active" class="btn btn-outline-success btn-sm <?php echo $status_filter == 'active' ? 'active' : ''; ?>">
                                    <i class="fas fa-check-circle mr-1"></i> Active
                                </a>
                                <a href="?status=inactive" class="btn btn-outline-warning btn-sm <?php echo $status_filter == 'inactive' ? 'active' : ''; ?>">
                                    <i class="fas fa-pause-circle mr-1"></i> Inactive
                                </a>
                                <a href="?status=archived" class="btn btn-outline-danger btn-sm <?php echo $status_filter == 'archived' ? 'active' : ''; ?>">
                                    <i class="fas fa-archive mr-1"></i> Archived
                                </a>
                                <a href="drugs_manage.php" class="btn btn-outline-secondary btn-sm">
                                    <i class="fas fa-times mr-1"></i> Clear
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
    
    <div class="table-responsive-sm">
        <table class="table table-hover mb-0">
            <thead class="<?php if ($num_rows[0] == 0) { echo "d-none"; } ?> bg-light">
            <tr>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=drug_name&order=<?php echo $disp; ?>">
                        Drug Name <?php if ($sort == 'drug_name') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>Generic Name</th>
                <th>Form & Strength</th>
                <th>Manufacturer</th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=drug_category&order=<?php echo $disp; ?>">
                        Category <?php if ($sort == 'drug_category') { echo $order_icon; } ?>
                    </a>
                </th>
                <th class="text-center">Inventory</th>
                <th class="text-center">Prescriptions</th>
                <th>Status</th>
                <th class="text-center">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php
            while ($row = mysqli_fetch_array($sql)) {
                $drug_id = intval($row['drug_id']);
                $drug_name = nullable_htmlentities($row['drug_name']);
                $drug_generic_name = nullable_htmlentities($row['drug_generic_name']);
                $drug_form = nullable_htmlentities($row['drug_form']);
                $drug_strength = nullable_htmlentities($row['drug_strength']);
                $drug_manufacturer = nullable_htmlentities($row['drug_manufacturer']);
                $drug_category = nullable_htmlentities($row['drug_category']);
                $drug_description = nullable_htmlentities($row['drug_description']);
                $drug_is_active = intval($row['drug_is_active']);
                $drug_archived_at = nullable_htmlentities($row['drug_archived_at']);
                $inventory_count = intval($row['inventory_count']);
                $prescription_count = intval($row['prescription_count']);
                $created_at = nullable_htmlentities($row['drug_created_at']);
                $updated_at = nullable_htmlentities($row['drug_updated_at']);

                // Status determination
                if ($drug_archived_at) {
                    $status = 'archived';
                    $status_text = 'Archived';
                    $status_badge = 'badge-secondary';
                    $table_class = 'table-secondary';
                } elseif (!$drug_is_active) {
                    $status = 'inactive';
                    $status_text = 'Inactive';
                    $status_badge = 'badge-warning';
                    $table_class = 'table-warning';
                } else {
                    $status = 'active';
                    $status_text = 'Active';
                    $status_badge = 'badge-success';
                    $table_class = '';
                }

                // Inventory status
                if ($inventory_count > 0) {
                    $inventory_badge = 'badge-success';
                    $inventory_text = $inventory_count . ' items';
                } else {
                    $inventory_badge = 'badge-light';
                    $inventory_text = 'None';
                }

                // Prescription status
                if ($prescription_count > 0) {
                    $prescription_badge = 'badge-info';
                    $prescription_text = $prescription_count;
                } else {
                    $prescription_badge = 'badge-light';
                    $prescription_text = '0';
                }
                ?>
                <tr class="<?php echo $table_class; ?>">
                    <td>
                        <div class="font-weight-bold text-primary"><?php echo $drug_name; ?></div>
                        <small class="text-muted">ID: <?php echo $drug_id; ?></small>
                        <?php if ($drug_description): ?>
                            <div class="small text-muted mt-1"><?php echo truncate($drug_description, 50); ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($drug_generic_name): ?>
                            <div class="font-weight-bold text-info"><?php echo $drug_generic_name; ?></div>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($drug_form): ?>
                            <span class="badge badge-light"><?php echo $drug_form; ?></span>
                        <?php endif; ?>
                        <?php if ($drug_strength): ?>
                            <span class="badge badge-info"><?php echo $drug_strength; ?></span>
                        <?php endif; ?>
                        <?php if (!$drug_form && !$drug_strength): ?>
                            <span class="text-muted small">Not specified</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($drug_manufacturer): ?>
                            <div class="font-weight-bold"><?php echo $drug_manufacturer; ?></div>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($drug_category): ?>
                            <span class="badge badge-primary"><?php echo $drug_category; ?></span>
                        <?php else: ?>
                            <span class="text-muted">Uncategorized</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <span class="badge <?php echo $inventory_badge; ?>"><?php echo $inventory_text; ?></span>
                        <?php if ($inventory_count == 0): ?>
                            <div class="small">
                                <a href="inventory_add_item.php?drug_id=<?php echo $drug_id; ?>" class="text-success">
                                    <i class="fas fa-plus mr-1"></i>Add Stock
                                </a>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <span class="badge <?php echo $prescription_badge; ?>"><?php echo $prescription_text; ?></span>
                    </td>
                    <td>
                        <span class="badge <?php echo $status_badge; ?>"><?php echo $status_text; ?></span>
                        <?php if ($drug_archived_at): ?>
                            <div class="small text-muted"><?php echo date('M j, Y', strtotime($drug_archived_at)); ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="dropdown dropleft text-center">
                            <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                <i class="fas fa-ellipsis-h"></i>
                            </button>
                            <div class="dropdown-menu">
                                <a class="dropdown-item" href="drugs_view.php?drug_id=<?php echo $drug_id; ?>">
                                    <i class="fas fa-fw fa-eye mr-2"></i>View Details
                                </a>
                                <a class="dropdown-item" href="drugs_edit.php?drug_id=<?php echo $drug_id; ?>">
                                    <i class="fas fa-fw fa-edit mr-2"></i>Edit Drug
                                </a>
                                
                               

                                <div class="dropdown-divider"></div>

                                <?php if ($status == 'active'): ?>
                                    <a class="dropdown-item text-warning confirm-link" href="post.php?deactivate_drug=<?php echo $drug_id; ?>">
                                        <i class="fas fa-fw fa-pause mr-2"></i>Deactivate
                                    </a>
                                <?php elseif ($status == 'inactive'): ?>
                                    <a class="dropdown-item text-success confirm-link" href="post.php?activate_drug=<?php echo $drug_id; ?>">
                                        <i class="fas fa-fw fa-play mr-2"></i>Activate
                                    </a>
                                <?php endif; ?>

                                <?php if ($status != 'archived'): ?>
                                    <a class="dropdown-item text-danger confirm-link" href="post.php?archive_drug=<?php echo $drug_id; ?>">
                                        <i class="fas fa-fw fa-archive mr-2"></i>Archive
                                    </a>
                                <?php else: ?>
                                    <a class="dropdown-item text-info confirm-link" href="post.php?restore_drug=<?php echo $drug_id; ?>">
                                        <i class="fas fa-fw fa-undo mr-2"></i>Restore
                                    </a>
                                <?php endif; ?>

                                <?php if ($inventory_count == 0 && $prescription_count == 0): ?>
                                    <div class="dropdown-divider"></div>
                                    <a class="dropdown-item text-danger confirm-link" href="post.php?delete_drug=<?php echo $drug_id; ?>">
                                        <i class="fas fa-fw fa-trash mr-2"></i>Delete Permanently
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php
            } 
            
            if ($num_rows[0] == 0) {
                ?>
                <tr>
                    <td colspan="9" class="text-center py-5">
                        <i class="fas fa-capsules fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No Drugs Found</h5>
                        <p class="text-muted">No drugs match your current filters in the database.</p>
                        <div class="mt-3">
                            <a href="drugs_add.php" class="btn btn-success">
                                <i class="fas fa-plus mr-2"></i>Add First Drug
                            </a>
                            <a href="drugs_manage.php" class="btn btn-secondary ml-2">
                                <i class="fas fa-times mr-2"></i>Clear Filters
                            </a>
                        </div>
                    </td>
                </tr>
                <?php
            }
            ?>
            </tbody>
        </table>
    </div>
    
    <?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/filter_footer.php'; ?>
</div>

<!-- Quick Add Modal -->
<div class="modal fade" id="quickAddModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Quick Add Drug</h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <form method="post" action="post.php">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    
                    <div class="form-group">
                        <label>Drug Name *</label>
                        <input type="text" class="form-control" name="drug_name" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Generic Name</label>
                        <input type="text" class="form-control" name="drug_generic_name">
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Form</label>
                                <select class="form-control" name="drug_form">
                                    <option value="">- Select Form -</option>
                                    <?php foreach ($common_forms as $form): ?>
                                        <option value="<?php echo $form; ?>"><?php echo $form; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Strength</label>
                                <input type="text" class="form-control" name="drug_strength" placeholder="e.g., 500mg, 250mg/5ml">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success" name="add_drug">
                        <i class="fas fa-plus mr-2"></i>Add Drug
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('.select2').select2();

    // Confirm action links
    $('.confirm-link').click(function(e) {
        if (!confirm('Are you sure you want to perform this action?')) {
            e.preventDefault();
        }
    });

    // Quick filter buttons
    $('.btn-group-toggle .btn').click(function(e) {
        e.preventDefault();
        window.location.href = $(this).attr('href');
    });

    // Quick add modal
    $('#quickAddBtn').click(function() {
        $('#quickAddModal').modal('show');
    });
});

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + N for new drug
    if (e.ctrlKey && e.keyCode === 78) {
        e.preventDefault();
        window.location.href = 'drugs_add.php';
    }
    // Ctrl + F for focus search
    if (e.ctrlKey && e.keyCode === 70) {
        e.preventDefault();
        $('input[name="q"]').focus();
    }
    // Escape to clear filters
    if (e.keyCode === 27) {
        window.location.href = 'drugs_manage.php';
    }
});
</script>

<style>
.info-box {
    padding: 10px;
    border-radius: 5px;
}
.info-box-icon {
    float: left;
    height: 70px;
    width: 70px;
    text-align: center;
    font-size: 30px;
    line-height: 70px;
    border-radius: 5px;
}
.info-box-content {
    margin-left: 80px;
}
.info-box-text {
    display: block;
    font-size: 14px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.info-box-number {
    display: block;
    font-weight: bold;
    font-size: 18px;
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>