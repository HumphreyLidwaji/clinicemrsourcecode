<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
// Define APP_VERSION if not already defined
if (!defined('APP_VERSION')) {
    define('APP_VERSION', '1.0.0');
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Handle form submissions via POST
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_icd_code'])) {
        $icd_code = sanitizeInput($_POST['icd_code']);
        $title = sanitizeInput($_POST['title']);
        $description = sanitizeInput($_POST['description']);
        
        $stmt = $mysqli->prepare("INSERT INTO icd11_codes SET icd_code=?, title=?, description=?");
        $stmt->bind_param("sss", $icd_code, $title, $description);
        
        if ($stmt->execute()) {
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "ICD-11 code added successfully!";
        } else {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error adding ICD-11 code: " . $mysqli->error;
        }
        $stmt->close();
    }
    
    elseif (isset($_POST['update_icd_code'])) {
        $icd_code = sanitizeInput($_POST['icd_code']);
        $title = sanitizeInput($_POST['title']);
        $description = sanitizeInput($_POST['description']);
        $original_code = sanitizeInput($_POST['original_code']);
        
        $stmt = $mysqli->prepare("UPDATE icd11_codes SET icd_code=?, title=?, description=? WHERE icd_code=?");
        $stmt->bind_param("ssss", $icd_code, $title, $description, $original_code);
        
        if ($stmt->execute()) {
            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "ICD-11 code updated successfully!";
        } else {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Error updating ICD-11 code: " . $mysqli->error;
        }
        $stmt->close();
    }
    
    // Handle Excel import
    elseif (isset($_POST['import_excel'])) {
        if (isset($_FILES['excel_file']) && $_FILES['excel_file']['error'] === UPLOAD_ERR_OK) {
            $import_result = importICDCodesFromExcel($mysqli, $_FILES['excel_file']);
            
            if ($import_result['success']) {
                $_SESSION['alert_type'] = "success";
                $_SESSION['alert_message'] = $import_result['message'];
            } else {
                $_SESSION['alert_type'] = "error";
                $_SESSION['alert_message'] = $import_result['message'];
            }
        } else {
            $_SESSION['alert_type'] = "error";
            $_SESSION['alert_message'] = "Please select a valid Excel file to import.";
        }
    }
    
    // Redirect to prevent form resubmission
    header("Location: icd11_codes.php");
    exit;
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ajax_request'])) {
    header('Content-Type: application/json');
    
    if (isset($_POST['get_icd_code_details'])) {
        $icd_code = sanitizeInput($_POST['icd_code']);
        $code = $mysqli->query("SELECT * FROM icd11_codes WHERE icd_code = '$icd_code'")->fetch_assoc();
        echo json_encode($code);
        exit;
    }
    
    if (isset($_POST['delete_icd_code'])) {
        $icd_code = sanitizeInput($_POST['icd_code']);
        $stmt = $mysqli->prepare("DELETE FROM icd11_codes WHERE icd_code = ?");
        $stmt->bind_param("s", $icd_code);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'ICD-11 code deleted successfully!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error deleting ICD-11 code: ' . $mysqli->error]);
        }
        $stmt->close();
        exit;
    }
    
    if (isset($_POST['bulk_delete_codes'])) {
        $codes_to_delete = $_POST['codes'] ?? [];
        $deleted_count = 0;
        $error_count = 0;
        
        foreach ($codes_to_delete as $code) {
            $stmt = $mysqli->prepare("DELETE FROM icd11_codes WHERE icd_code = ?");
            $stmt->bind_param("s", $code);
            if ($stmt->execute()) {
                $deleted_count++;
            } else {
                $error_count++;
            }
            $stmt->close();
        }
        
        echo json_encode([
            'success' => true, 
            'message' => "Bulk delete completed: $deleted_count codes deleted" . ($error_count > 0 ? ", $error_count errors" : "")
        ]);
        exit;
    }
}

// Excel Import Function
function importICDCodesFromExcel($mysqli, $file) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/PHPExcel/Classes/PHPExcel.php';
    
    try {
        $input_file_type = PHPExcel_IOFactory::identify($file['tmp_name']);
        $obj_reader = PHPExcel_IOFactory::createReader($input_file_type);
        $obj_php_excel = $obj_reader->load($file['tmp_name']);
        
        $worksheet = $obj_php_excel->getActiveSheet();
        $highest_row = $worksheet->getHighestRow();
        
        $imported_count = 0;
        $updated_count = 0;
        $error_count = 0;
        $errors = [];
        
        // Start transaction for data consistency
        $mysqli->begin_transaction();
        
        for ($row = 2; $row <= $highest_row; $row++) {
            $icd_code = trim($worksheet->getCellByColumnAndRow(0, $row)->getValue());
            $title = trim($worksheet->getCellByColumnAndRow(1, $row)->getValue());
            $description = trim($worksheet->getCellByColumnAndRow(2, $row)->getValue());
            
            // Skip empty rows
            if (empty($icd_code) || empty($title)) {
                continue;
            }
            
            // Check if code already exists
            $check_stmt = $mysqli->prepare("SELECT icd_code FROM icd11_codes WHERE icd_code = ?");
            $check_stmt->bind_param("s", $icd_code);
            $check_stmt->execute();
            $exists = $check_stmt->get_result()->num_rows > 0;
            $check_stmt->close();
            
            if ($exists) {
                // Update existing code
                $stmt = $mysqli->prepare("UPDATE icd11_codes SET title=?, description=? WHERE icd_code=?");
                $stmt->bind_param("sss", $title, $description, $icd_code);
                if ($stmt->execute()) {
                    $updated_count++;
                } else {
                    $error_count++;
                    $errors[] = "Row $row: " . $stmt->error;
                }
                $stmt->close();
            } else {
                // Insert new code
                $stmt = $mysqli->prepare("INSERT INTO icd11_codes SET icd_code=?, title=?, description=?");
                $stmt->bind_param("sss", $icd_code, $title, $description);
                if ($stmt->execute()) {
                    $imported_count++;
                } else {
                    $error_count++;
                    $errors[] = "Row $row: " . $stmt->error;
                }
                $stmt->close();
            }
        }
        
        $mysqli->commit();
        
        $message = "Import completed: $imported_count new codes imported, $updated_count codes updated";
        if ($error_count > 0) {
            $message .= ", $error_count errors occurred";
        }
        
        return [
            'success' => true,
            'message' => $message,
            'imported' => $imported_count,
            'updated' => $updated_count,
            'errors' => $errors
        ];
        
    } catch (Exception $e) {
        $mysqli->rollback();
        return [
            'success' => false,
            'message' => 'Error importing Excel file: ' . $e->getMessage()
        ];
    }
}

// Default Column Sortby/Order Filter
$sort = "icd_code";
$order = "ASC";

// Search Query
$q = sanitizeInput($_GET['q'] ?? '');
if (!empty($q)) {
    $search_query = "AND (
        icd_code LIKE '%$q%' 
        OR title LIKE '%$q%'
        OR description LIKE '%$q%'
    )";
} else {
    $search_query = '';
}

// Get all ICD-11 codes
$icd_codes_sql = "
    SELECT SQL_CALC_FOUND_ROWS *
    FROM icd11_codes 
    WHERE 1=1
    $search_query
    ORDER BY $sort $order
    LIMIT $record_from, $record_to
";

$icd_codes = $mysqli->query($icd_codes_sql);
$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Get statistics
$total_codes = $mysqli->query("SELECT COUNT(*) FROM icd11_codes")->fetch_row()[0];
$recent_codes = $mysqli->query("SELECT COUNT(*) FROM icd11_codes WHERE DATE(created_at) = CURDATE()")->fetch_row()[0];
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0">
                <i class="fas fa-fw fa-stethoscope mr-2"></i>ICD-11 Codes Management
            </h3>
            <div class="card-tools">
                <button type="button" class="btn btn-light" data-toggle="modal" data-target="#addICDCodeModal">
                    <i class="fas fa-plus mr-2"></i>New Code
                </button>
                <button type="button" class="btn btn-light ml-2" data-toggle="modal" data-target="#importExcelModal">
                    <i class="fas fa-file-import mr-2"></i>Import Excel
                </button>
            </div>
        </div>
    </div>

    <div class="card-header pb-2 pt-3">
        <form autocomplete="off">
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <div class="input-group">
                            <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search ICD codes, titles, descriptions..." autofocus>
                            <div class="input-group-append">
                                <button class="btn btn-primary"><i class="fa fa-search"></i></button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="btn-toolbar form-group float-right">
                        <div class="btn-group">
                            <span class="btn btn-light border">
                                <i class="fas fa-database text-primary mr-1"></i>
                                Total Codes: <strong><?php echo $total_codes; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-clock text-success mr-1"></i>
                                Today: <strong><?php echo $recent_codes; ?></strong>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
    
    <div class="card-body">
        <?php if (isset($_SESSION['alert_message'])): ?>
            <div class="alert alert-<?php echo $_SESSION['alert_type']; ?> alert-dismissible">
                <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
                <i class="icon fas fa-<?php echo $_SESSION['alert_type'] == 'success' ? 'check' : 'exclamation-triangle'; ?>"></i>
                <?php echo $_SESSION['alert_message']; ?>
            </div>
            <?php 
            unset($_SESSION['alert_type']);
            unset($_SESSION['alert_message']);
            ?>
        <?php endif; ?>

        <!-- Alert Container for AJAX Messages -->
        <div id="ajaxAlertContainer"></div>

        <!-- Bulk Actions -->
        <div class="row mb-3">
            <div class="col-md-12">
                <div class="btn-group" id="bulkActions" style="display: none;">
                    <button type="button" class="btn btn-outline-danger btn-sm" onclick="bulkDeleteCodes()">
                        <i class="fas fa-trash mr-1"></i>Delete Selected
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="clearSelection()">
                        <i class="fas fa-times mr-1"></i>Clear Selection
                    </button>
                </div>
            </div>
        </div>
    
        <div class="table-responsive-sm">
            <table class="table table-hover mb-0">
                <thead class="<?php if ($num_rows[0] == 0) { echo "d-none"; } ?> bg-light">
                <tr>
                    <th width="30">
                        <input type="checkbox" id="selectAll">
                    </th>
                    <th>
                        <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=icd_code&order=<?php echo $disp; ?>">
                            ICD-11 Code <?php if ($sort == 'icd_code') { echo $order_icon; } ?>
                        </a>
                    </th>
                    <th>Title</th>
                    <th>Description</th>
                    <th class="text-center">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php while($code = $icd_codes->fetch_assoc()): 
                    $icd_code = nullable_htmlentities($code['icd_code']);
                    $title = nullable_htmlentities($code['title']);
                    $description = nullable_htmlentities($code['description']);
                    ?>
                    <tr>
                        <td>
                            <input type="checkbox" class="code-checkbox" value="<?php echo $icd_code; ?>">
                        </td>
                        <td class="font-weight-bold text-primary">
                            <i class="fas fa-hashtag text-secondary mr-2"></i>
                            <?php echo $icd_code; ?>
                        </td>
                        <td>
                            <strong><?php echo $title; ?></strong>
                        </td>
                        <td>
                            <?php if ($description): ?>
                                <small class="text-muted"><?php echo strlen($description) > 100 ? substr($description, 0, 100) . '...' : $description; ?></small>
                            <?php else: ?>
                                <em class="text-muted">No description</em>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="dropdown dropleft text-center">
                                <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                    <i class="fas fa-ellipsis-h"></i>
                                </button>
                                <div class="dropdown-menu">
                                    <a class="dropdown-item" href="#" onclick="viewCodeDetails('<?php echo $icd_code; ?>')">
                                        <i class="fas fa-fw fa-eye mr-2"></i>View Details
                                    </a>
                                    <a class="dropdown-item" href="#" onclick="editICDCode('<?php echo $icd_code; ?>')">
                                        <i class="fas fa-fw fa-edit mr-2"></i>Edit Code
                                    </a>
                                    <div class="dropdown-divider"></div>
                                    <a class="dropdown-item text-danger" href="#" onclick="deleteICDCode('<?php echo $icd_code; ?>')">
                                        <i class="fas fa-fw fa-trash mr-2"></i>Delete Code
                                    </a>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; ?>

                <?php if ($num_rows[0] === 0): ?>
                    <tr>
                        <td colspan="5" class="text-center py-4">
                            <i class="fas fa-stethoscope fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted">No ICD-11 Codes Found</h5>
                            <p class="text-muted">
                                <?php echo $search_query ? 
                                    'Try adjusting your search criteria.' : 
                                    'Get started by adding your first ICD-11 code or importing from Excel.'; 
                                ?>
                            </p>
                            <div class="mt-3">
                                <button type="button" class="btn btn-primary mt-2" data-toggle="modal" data-target="#addICDCodeModal">
                                    <i class="fas fa-plus mr-2"></i>Add First Code
                                </button>
                                <button type="button" class="btn btn-outline-primary mt-2 ml-2" data-toggle="modal" data-target="#importExcelModal">
                                    <i class="fas fa-file-import mr-2"></i>Import from Excel
                                </button>
                            </div>
                            <?php if ($search_query): ?>
                                <a href="icd11_codes.php" class="btn btn-outline-secondary mt-2">
                                    <i class="fas fa-times mr-2"></i>Clear Search
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Ends Card Body -->
        <?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/filter_footer.php'; ?>
    </div>
</div>

<!-- Add ICD Code Modal -->
<div class="modal fade" id="addICDCodeModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-plus mr-2"></i>Add New ICD-11 Code</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>ICD-11 Code *</label>
                                <input type="text" class="form-control" name="icd_code" required 
                                       placeholder="e.g., 1A00.0" pattern="[A-Za-z0-9.-]+"
                                       title="Enter a valid ICD-11 code format">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Title *</label>
                                <input type="text" class="form-control" name="title" required 
                                       placeholder="e.g., Cholera due to Vibrio cholerae 01, biovar cholerae">
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Description</label>
                        <textarea class="form-control" name="description" rows="4" 
                                  placeholder="Detailed description of the condition, symptoms, or additional information..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_icd_code" class="btn btn-primary">Add ICD Code</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit ICD Code Modal -->
<div class="modal fade" id="editICDCodeModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-warning text-white">
                <h5 class="modal-title"><i class="fas fa-edit mr-2"></i>Edit ICD-11 Code</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST">
                <input type="hidden" name="original_code" id="edit_original_code">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>ICD-11 Code *</label>
                                <input type="text" class="form-control" name="icd_code" id="edit_icd_code" required
                                       pattern="[A-Za-z0-9.-]+" title="Enter a valid ICD-11 code format">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Title *</label>
                                <input type="text" class="form-control" name="title" id="edit_title" required>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Description</label>
                        <textarea class="form-control" name="description" id="edit_description" rows="4"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_icd_code" class="btn btn-warning">Update ICD Code</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Code Details Modal -->
<div class="modal fade" id="viewCodeModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="fas fa-eye mr-2"></i>ICD-11 Code Details</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="font-weight-bold">ICD-11 Code</label>
                            <div class="form-control-plaintext bg-light rounded p-2" id="view_icd_code"></div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label class="font-weight-bold">Title</label>
                            <div class="form-control-plaintext bg-light rounded p-2" id="view_title"></div>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label class="font-weight-bold">Description</label>
                    <div class="form-control-plaintext bg-light rounded p-2" id="view_description"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                <button type="button" class="btn btn-warning" onclick="editCurrentCode()">Edit This Code</button>
            </div>
        </div>
    </div>
</div>

<!-- Import Excel Modal -->
<div class="modal fade" id="importExcelModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-file-import mr-2"></i>Import ICD-11 Codes from Excel</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle mr-2"></i>
                        <strong>Excel Format Requirements:</strong><br>
                        • First row should contain headers: ICD Code, Title, Description<br>
                        • Supported formats: .xls, .xlsx<br>
                        • Existing codes will be updated, new codes will be added
                    </div>
                    
                    <div class="form-group">
                        <label>Excel File *</label>
                        <input type="file" class="form-control-file" name="excel_file" accept=".xls,.xlsx" required>
                    </div>
                    
                    <div class="mt-3">
                        <h6>Sample Excel Structure:</h6>
                        <table class="table table-bordered table-sm">
                            <thead class="bg-light">
                                <tr>
                                    <th>ICD Code</th>
                                    <th>Title</th>
                                    <th>Description</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>1A00.0</td>
                                    <td>Cholera due to Vibrio cholerae 01</td>
                                    <td>Acute intestinal infection caused by ingestion of contaminated water or food</td>
                                </tr>
                                <tr>
                                    <td>1B10</td>
                                    <td>Typhoid fever</td>
                                    <td>Systemic infection caused by Salmonella typhi</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" name="import_excel" class="btn btn-success">Import Codes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Bulk selection functionality
    $('#selectAll').change(function() {
        $('.code-checkbox').prop('checked', this.checked);
        toggleBulkActions();
    });
    
    $('.code-checkbox').change(function() {
        toggleBulkActions();
        updateSelectAllCheckbox();
    });
});

function toggleBulkActions() {
    const checkedCount = $('.code-checkbox:checked').length;
    if (checkedCount > 0) {
        $('#bulkActions').show();
    } else {
        $('#bulkActions').hide();
    }
}

function updateSelectAllCheckbox() {
    const total = $('.code-checkbox').length;
    const checked = $('.code-checkbox:checked').length;
    $('#selectAll').prop('checked', total > 0 && total === checked);
}

function clearSelection() {
    $('.code-checkbox').prop('checked', false);
    $('#selectAll').prop('checked', false);
    toggleBulkActions();
}

function bulkDeleteCodes() {
    const selectedCodes = [];
    $('.code-checkbox:checked').each(function() {
        selectedCodes.push($(this).val());
    });
    
    if (selectedCodes.length === 0) {
        showAlert('Please select at least one code to delete.', 'error');
        return;
    }
    
    if (confirm(`Are you sure you want to delete ${selectedCodes.length} ICD-11 code(s)? This action cannot be undone.`)) {
        $.ajax({
            url: 'icd11_codes.php',
            type: 'POST',
            data: {
                ajax_request: 1,
                bulk_delete_codes: 1,
                codes: selectedCodes
            },
            success: function(response) {
                const result = JSON.parse(response);
                if (result.success) {
                    showAlert(result.message, 'success');
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else {
                    showAlert(result.message, 'error');
                }
            },
            error: function() {
                showAlert('Error performing bulk delete. Please try again.', 'error');
            }
        });
    }
}

function editICDCode(icdCode) {
    $.ajax({
        url: 'icd11_codes.php',
        type: 'POST',
        data: {
            ajax_request: 1,
            get_icd_code_details: 1,
            icd_code: icdCode
        },
        success: function(response) {
            const code = JSON.parse(response);
            $('#edit_original_code').val(code.icd_code);
            $('#edit_icd_code').val(code.icd_code);
            $('#edit_title').val(code.title);
            $('#edit_description').val(code.description);
            $('#editICDCodeModal').modal('show');
        },
        error: function() {
            showAlert('Error loading ICD code details. Please try again.', 'error');
        }
    });
}

function viewCodeDetails(icdCode) {
    $.ajax({
        url: 'icd11_codes.php',
        type: 'POST',
        data: {
            ajax_request: 1,
            get_icd_code_details: 1,
            icd_code: icdCode
        },
        success: function(response) {
            const code = JSON.parse(response);
            $('#view_icd_code').text(code.icd_code);
            $('#view_title').text(code.title);
            $('#view_description').text(code.description || 'No description available');
            $('#viewCodeModal').modal('show');
        },
        error: function() {
            showAlert('Error loading ICD code details. Please try again.', 'error');
        }
    });
}

function editCurrentCode() {
    const currentCode = $('#view_icd_code').text();
    $('#viewCodeModal').modal('hide');
    setTimeout(() => {
        editICDCode(currentCode);
    }, 500);
}

function deleteICDCode(icdCode) {
    if (confirm('Are you sure you want to delete this ICD-11 code? This action cannot be undone.')) {
        $.ajax({
            url: 'icd11_codes.php',
            type: 'POST',
            data: {
                ajax_request: 1,
                delete_icd_code: 1,
                icd_code: icdCode
            },
            success: function(response) {
                const result = JSON.parse(response);
                if (result.success) {
                    showAlert(result.message, 'success');
                    setTimeout(() => {
                        location.reload();
                    }, 1500);
                } else {
                    showAlert(result.message, 'error');
                }
            },
            error: function() {
                showAlert('Error deleting ICD code. Please try again.', 'error');
            }
        });
    }
}

function showAlert(message, type) {
    const alertClass = type === 'success' ? 'alert-success' : 'alert-danger';
    const iconClass = type === 'success' ? 'fa-check' : 'fa-exclamation-triangle';
    
    const alertHtml = `
        <div class="alert ${alertClass} alert-dismissible">
            <button type="button" class="close" data-dismiss="alert" aria-hidden="true">×</button>
            <i class="icon fas ${iconClass}"></i>
            ${message}
        </div>
    `;
    
    $('#ajaxAlertContainer').html(alertHtml);
    
    // Auto-dismiss success alerts after 5 seconds
    if (type === 'success') {
        setTimeout(() => {
            $('.alert').alert('close');
        }, 5000);
    }
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + N for new code
    if (e.ctrlKey && e.keyCode === 78) {
        e.preventDefault();
        $('#addICDCodeModal').modal('show');
    }
    // Ctrl + I for import
    if (e.ctrlKey && e.keyCode === 73) {
        e.preventDefault();
        $('#importExcelModal').modal('show');
    }
    // Ctrl + F for focus search
    if (e.ctrlKey && e.keyCode === 70) {
        e.preventDefault();
        $('input[name="q"]').focus();
    }
});

// Auto-close alerts after 5 seconds
setTimeout(() => {
    $('.alert').alert('close');
}, 5000);
</script>

<style>
.card {
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    margin-bottom: 1rem;
}

.form-control-plaintext.bg-light {
    background-color: #f8f9fa !important;
    border: 1px solid #e9ecef;
}

.table th {
    border-top: none;
    font-weight: 600;
}

.badge {
    font-size: 0.75em;
}

#bulkActions {
    transition: all 0.3s ease;
}
</style>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>