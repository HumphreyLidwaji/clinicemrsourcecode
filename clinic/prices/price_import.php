<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/price_functions.php';

$price_list_id = intval($_GET['price_list_id'] ?? 0);
$template = isset($_GET['template']);

// Handle CSV upload
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['csv_file'])) {
    $price_list_id = intval($_POST['price_list_id']);
    $changed_by = intval($_SESSION['user_id']);
    
    if ($_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
        $tmp_name = $_FILES['csv_file']['tmp_name'];
        $csv_data = [];
        
        // Read CSV file
        if (($handle = fopen($tmp_name, 'r')) !== FALSE) {
            while (($row = fgetcsv($handle, 1000, ',')) !== FALSE) {
                $csv_data[] = $row;
            }
            fclose($handle);
        }
        
        if (count($csv_data) > 0) {
            // Process CSV data
            $updates = [];
            $errors = [];
            $success_count = 0;
            
            // Skip header row if exists
            $start_row = 0;
            if (strtoupper($csv_data[0][0]) == 'ENTITY TYPE') {
                $start_row = 1;
            }
            
            for ($i = $start_row; $i < count($csv_data); $i++) {
                $row = $csv_data[$i];
                
                // Skip empty rows
                if (empty($row[0]) || empty($row[1]) || empty($row[2])) {
                    continue;
                }
                
                $entity_type = strtoupper(trim($row[0]));
                $entity_id = intval($row[1]);
                $price = floatval($row[2]);
                $coverage = isset($row[3]) ? floatval($row[3]) : 100;
                
                // Validate data
                if (!in_array($entity_type, ['ITEM', 'SERVICE'])) {
                    $errors[] = "Row $i: Invalid entity type '$entity_type'";
                    continue;
                }
                
                if ($entity_id <= 0) {
                    $errors[] = "Row $i: Invalid entity ID";
                    continue;
                }
                
                if ($price < 0) {
                    $errors[] = "Row $i: Price cannot be negative";
                    continue;
                }
                
                if ($coverage < 0 || $coverage > 100) {
                    $errors[] = "Row $i: Coverage must be between 0-100%";
                    continue;
                }
                
                $updates[] = [
                    'entity_type' => $entity_type,
                    'entity_id' => $entity_id,
                    'price' => $price,
                    'covered_percentage' => $coverage,
                    'reason' => 'CSV Import'
                ];
            }
            
            // Perform bulk update
            if (count($updates) > 0) {
                $result = bulkUpdatePrices($mysqli, $price_list_id, $updates, $changed_by);
                
                if ($result['success']) {
                    $_SESSION['alert_message'] = sprintf(
                        "Import completed: %d successful, %d errors",
                        $result['success_count'],
                        $result['error_count']
                    );
                    
                    if (!empty($result['errors'])) {
                        $_SESSION['import_errors'] = $result['errors'];
                    }
                } else {
                    $_SESSION['alert_message'] = "Import failed: " . $result['message'];
                }
            } else {
                $_SESSION['alert_message'] = "No valid data found in CSV file";
            }
            
            header("Location: price_import.php?price_list_id=$price_list_id");
            exit;
        } else {
            $error_message = "CSV file is empty";
        }
    } else {
        $error_message = "Error uploading file: " . $_FILES['csv_file']['error'];
    }
}

// Download template
if ($template && $price_list_id) {
    $price_list = getPriceListDetails($mysqli, $price_list_id);
    $items = getPriceListItems($mysqli, $price_list_id);
    $services = getPriceListServices($mysqli, $price_list_id);
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="price_import_template_' . $price_list['list_name'] . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Write headers
    fputcsv($output, ['ENTITY TYPE', 'ENTITY ID', 'NEW PRICE', 'COVERAGE %', 'NOTES']);
    fputcsv($output, ['ITEM or SERVICE', 'ID from system', 'e.g., 100.00', '0-100, default 100', 'Optional notes']);
    fputcsv($output, []); // Empty row
    
    // Write items
    fputcsv($output, ['ITEMS', '', '', '', '']);
    foreach ($items as $item) {
        fputcsv($output, [
            'ITEM',
            $item['item_id'],
            $item['price'],
            $item['covered_percentage'],
            $item['item_name']
        ]);
    }
    
    fputcsv($output, []); // Empty row
    
    // Write services
    fputcsv($output, ['SERVICES', '', '', '', '']);
    foreach ($services as $service) {
        fputcsv($output, [
            'SERVICE',
            $service['medical_service_id'],
            $service['price'],
            $service['covered_percentage'],
            $service['service_name']
        ]);
    }
    
    fclose($output);
    exit;
}

// Get price lists for dropdown
$price_lists = getAllPriceLists($mysqli);

// Get selected price list details
$selected_list = null;
if ($price_list_id) {
    $selected_list = getPriceListDetails($mysqli, $price_list_id);
}
?>

<div class="card">
    <div class="card-header bg-dark py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-file-import mr-2"></i>Import Prices
        </h3>
        <div class="card-tools">
            <a href="price_management.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left mr-2"></i>Back
            </a>
            <?php if ($price_list_id): ?>
            <a href="bulk_price_update.php?price_list_id=<?php echo $price_list_id; ?>" class="btn btn-info ml-2">
                <i class="fas fa-sync-alt mr-2"></i>Bulk Update
            </a>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="card-body">
        <?php if (isset($_SESSION['alert_message'])): ?>
        <div class="alert alert-<?php echo strpos($_SESSION['alert_message'], 'failed') !== false ? 'danger' : 'success'; ?> alert-dismissible fade show">
            <i class="fas fa-<?php echo strpos($_SESSION['alert_message'], 'failed') !== false ? 'exclamation-circle' : 'check-circle'; ?> mr-2"></i>
            <?php echo $_SESSION['alert_message']; ?>
            <button type="button" class="close" data-dismiss="alert">
                <span>&times;</span>
            </button>
        </div>
        <?php unset($_SESSION['alert_message']); endif; ?>
        
        <?php if (isset($_SESSION['import_errors'])): ?>
        <div class="alert alert-warning">
            <h6><i class="fas fa-exclamation-triangle mr-2"></i>Import Errors:</h6>
            <ul class="mb-0">
                <?php foreach($_SESSION['import_errors'] as $error): ?>
                <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php unset($_SESSION['import_errors']); endif; ?>
        
        <?php if (isset($error_message)): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error_message); ?>
        </div>
        <?php endif; ?>
        
        <div class="row">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header bg-light">
                        <h6 class="card-title mb-0">Select Price List</h6>
                    </div>
                    <div class="card-body">
                        <form method="GET" id="selectPriceListForm">
                            <div class="form-group">
                                <label>Price List</label>
                                <select class="form-control" name="price_list_id" id="priceListSelect" required>
                                    <option value="">Select Price List</option>
                                    <?php foreach($price_lists as $pl): ?>
                                    <option value="<?php echo $pl['price_list_id']; ?>" 
                                        <?php echo ($price_list_id == $pl['price_list_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($pl['list_name'] . " (" . $pl['payer_type'] . ")"); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-primary btn-block">
                                <i class="fas fa-check mr-2"></i>Select Price List
                            </button>
                        </form>
                    </div>
                </div>
                
                <?php if ($selected_list): ?>
                <div class="card mt-3">
                    <div class="card-header bg-light">
                        <h6 class="card-title mb-0">Import Instructions</h6>
                    </div>
                    <div class="card-body">
                        <ol class="pl-3" style="font-size: 0.9rem;">
                            <li class="mb-2">Download the template for this price list</li>
                            <li class="mb-2">Update prices in the template</li>
                            <li class="mb-2">Save as CSV (UTF-8 encoding)</li>
                            <li class="mb-2">Upload using the form on the right</li>
                            <li>Review import results</li>
                        </ol>
                        
                        <div class="mt-3">
                            <a href="price_import.php?price_list_id=<?php echo $price_list_id; ?>&template=1" 
                               class="btn btn-success btn-block">
                                <i class="fas fa-download mr-2"></i>Download Template
                            </a>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="col-md-8">
                <?php if ($price_list_id): ?>
                <div class="card">
                    <div class="card-header bg-light">
                        <h6 class="card-title mb-0">Upload CSV File</h6>
                    </div>
                    <div class="card-body">
                        <form method="POST" enctype="multipart/form-data" onsubmit="return validateImport()">
                            <input type="hidden" name="price_list_id" value="<?php echo $price_list_id; ?>">
                            
                            <div class="form-group">
                                <label>Select CSV File</label>
                                <div class="custom-file">
                                    <input type="file" class="custom-file-input" id="csvFile" name="csv_file" accept=".csv" required>
                                    <label class="custom-file-label" for="csvFile">Choose CSV file</label>
                                </div>
                                <small class="form-text text-muted">
                                    Max file size: 2MB. Supported format: CSV with columns: Entity Type, Entity ID, New Price, Coverage %
                                </small>
                            </div>
                            
                            <div class="form-group">
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input" id="backupData" name="backup_data" value="1" checked>
                                    <label class="custom-control-label" for="backupData">Create backup before import</label>
                                </div>
                                <small class="form-text text-muted">Creates a backup of current prices before importing</small>
                            </div>
                            
                            <div class="form-group">
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input" id="validateOnly" name="validate_only" value="1">
                                    <label class="custom-control-label" for="validateOnly">Validate only (don't import)</label>
                                </div>
                                <small class="form-text text-muted">Check for errors without importing</small>
                            </div>
                            
                            <div class="mt-4">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-upload mr-2"></i>Upload and Import
                                </button>
                                <button type="reset" class="btn btn-secondary ml-2">
                                    <i class="fas fa-times mr-2"></i>Clear
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- CSV Format Example -->
                <div class="card mt-3">
                    <div class="card-header bg-light">
                        <h6 class="card-title mb-0">CSV Format Example</h6>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered">
                                <thead class="thead-light">
                                    <tr>
                                        <th>ENTITY TYPE</th>
                                        <th>ENTITY ID</th>
                                        <th>NEW PRICE</th>
                                        <th>COVERAGE %</th>
                                        <th>NOTES</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>ITEM</td>
                                        <td>105</td>
                                        <td>150.00</td>
                                        <td>100</td>
                                        <td>Paracetamol 500mg</td>
                                    </tr>
                                    <tr>
                                        <td>SERVICE</td>
                                        <td>42</td>
                                        <td>750.00</td>
                                        <td>80</td>
                                        <td>Doctor Consultation</td>
                                    </tr>
                                    <tr>
                                        <td>ITEM</td>
                                        <td>89</td>
                                        <td>45.50</td>
                                        <td>100</td>
                                        <td>Bandages</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="alert alert-info mt-3">
                            <h6><i class="fas fa-info-circle mr-2"></i>Important Notes:</h6>
                            <ul class="mb-0">
                                <li>Entity Type must be either "ITEM" or "SERVICE" (uppercase)</li>
                                <li>Entity ID must be the actual ID from the system (get from template)</li>
                                <li>New Price should be in decimal format (e.g., 100.50)</li>
                                <li>Coverage % is optional (defaults to 100)</li>
                                <li>Notes column is optional and for reference only</li>
                            </ul>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div class="card">
                    <div class="card-body text-center py-5">
                        <i class="fas fa-file-import fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">Select a Price List</h5>
                        <p class="text-muted">Please select a price list from the dropdown to begin importing prices.</p>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // File input label
    $('#csvFile').on('change', function() {
        var fileName = $(this).val().split('\\').pop();
        $(this).next('.custom-file-label').html(fileName);
    });
    
    // Auto-submit price list selection
    $('#priceListSelect').change(function() {
        if ($(this).val()) {
            $('#selectPriceListForm').submit();
        }
    });
});

function validateImport() {
    var fileInput = document.getElementById('csvFile');
    var fileName = fileInput.value;
    
    if (!fileName.toLowerCase().endsWith('.csv')) {
        alert('Please select a CSV file');
        return false;
    }
    
    if (!confirm('Are you sure you want to import prices? This will update existing prices.')) {
        return false;
    }
    
    return true;
}
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>