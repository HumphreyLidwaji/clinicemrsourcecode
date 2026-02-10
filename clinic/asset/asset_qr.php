<?php
// asset_qr.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Check if ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Invalid asset ID";
    header("Location: asset_management.php");
    exit;
}

$asset_id = intval($_GET['id']);

// Get asset details
$sql = "
    SELECT a.*,
           ac.category_name,
           al.location_name, al.building, al.floor, al.room_number,
           s.supplier_name,
           creator.user_name as created_by_name,
           updater.user_name as updated_by_name
    FROM assets a
    LEFT JOIN asset_categories ac ON a.category_id = ac.category_id
    LEFT JOIN asset_locations al ON a.location_id = al.location_id
    LEFT JOIN suppliers s ON a.supplier_id = s.supplier_id
    LEFT JOIN users creator ON a.created_by = creator.user_id
    LEFT JOIN users updater ON a.updated_by = updater.user_id
    WHERE a.asset_id = ?
";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $asset_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $_SESSION['alert_type'] = "error";
    $_SESSION['alert_message'] = "Asset not found";
    header("Location: asset_management.php");
    exit;
}

$asset = $result->fetch_assoc();

// QR Code data
$qr_data = array(
    'type' => 'asset',
    'id' => $asset_id,
    'tag' => $asset['asset_tag'],
    'name' => $asset['asset_name'],
    'url' => "https://" . $_SERVER['HTTP_HOST'] . "/clinic/asset/asset_view.php?id=" . $asset_id
);

$qr_json = json_encode($qr_data);
$qr_string = base64_encode($qr_json);

// Include QR Code library
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/phpqrcode/qrlib.php';

// Generate QR Code
$qr_temp_dir = $_SERVER['DOCUMENT_ROOT'] . '/temp/qrcodes/';
if (!file_exists($qr_temp_dir)) {
    mkdir($qr_temp_dir, 0777, true);
}

$qr_filename = 'asset_' . $asset_id . '_' . time() . '.png';
$qr_filepath = $qr_temp_dir . $qr_filename;
$qr_url = '/temp/qrcodes/' . $qr_filename;

// Generate QR Code
QRcode::png($qr_json, $qr_filepath, QR_ECLEVEL_H, 10, 2);

// Handle bulk QR generation
$bulk_mode = isset($_GET['bulk']) && $_GET['bulk'] == 'true';
$bulk_assets = array();

if ($bulk_mode) {
    $bulk_sql = "
        SELECT asset_id, asset_tag, asset_name 
        FROM assets 
        WHERE asset_id IN (" . implode(',', array_map('intval', explode(',', $_GET['ids']))) . ")
        ORDER BY asset_tag
    ";
    $bulk_result = $mysqli->query($bulk_sql);
    while ($bulk_asset = $bulk_result->fetch_assoc()) {
        $bulk_assets[] = $bulk_asset;
    }
}

// Clean up old QR codes (older than 1 hour)
$old_files = glob($qr_temp_dir . 'asset_*.png');
foreach ($old_files as $old_file) {
    if (filemtime($old_file) < time() - 3600) {
        unlink($old_file);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Asset QR Code - <?php echo htmlspecialchars($asset['asset_tag']); ?> - ITFlow</title>
    
    <!-- Google Font: Source Sans Pro -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="/plugins/fontawesome-free/css/all.min.css">
    <!-- Theme style -->
    <link rel="stylesheet" href="/dist/css/adminlte.min.css">
    
    <style>
        @media print {
            body * {
                visibility: hidden;
            }
            .print-area, .print-area * {
                visibility: visible;
            }
            .print-area {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
            }
            .no-print {
                display: none !important;
            }
            .card {
                border: none !important;
                box-shadow: none !important;
            }
            .page-break {
                page-break-after: always;
            }
        }
        .qr-container {
            text-align: center;
            padding: 20px;
        }
        .qr-code {
            max-width: 300px;
            margin: 0 auto;
        }
        .asset-info {
            background: #f8f9fa;
            border-radius: 5px;
            padding: 15px;
            margin-top: 20px;
        }
        .btn-group-print {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 1000;
        }
        .qr-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        .qr-item {
            border: 1px solid #dee2e6;
            border-radius: 5px;
            padding: 15px;
            text-align: center;
        }
    </style>
</head>
<body class="hold-transition layout-top-nav">
<div class="wrapper">
    <div class="content-wrapper">
        <div class="content">
            <div class="container">
                <!-- Print Control Buttons -->
                <div class="btn-group-print no-print">
                    <div class="btn-group" role="group">
                        <button type="button" class="btn btn-primary" onclick="window.print()">
                            <i class="fas fa-print mr-2"></i>Print QR Code
                        </button>
                        <button type="button" class="btn btn-success" onclick="downloadQR()">
                            <i class="fas fa-download mr-2"></i>Download QR
                        </button>
                        <a href="asset_view.php?id=<?php echo $asset_id; ?>" class="btn btn-secondary">
                            <i class="fas fa-arrow-left mr-2"></i>Back to Asset
                        </a>
                    </div>
                </div>

                <!-- Main Content -->
                <div class="card print-area">
                    <div class="card-header bg-primary">
                        <h3 class="card-title text-white">
                            <i class="fas fa-qrcode mr-2"></i>Asset QR Code
                            <?php if ($bulk_mode): ?>
                                <span class="badge badge-warning ml-2">Bulk Generation</span>
                            <?php endif; ?>
                        </h3>
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

                        <?php if ($bulk_mode && count($bulk_assets) > 0): ?>
                            <!-- Bulk QR Codes -->
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle mr-2"></i>
                                Generating QR codes for <?php echo count($bulk_assets); ?> assets
                            </div>
                            
                            <div class="qr-grid">
                                <?php foreach ($bulk_assets as $index => $bulk_asset): 
                                    // Generate QR for each asset
                                    $bulk_qr_data = array(
                                        'type' => 'asset',
                                        'id' => $bulk_asset['asset_id'],
                                        'tag' => $bulk_asset['asset_tag'],
                                        'name' => $bulk_asset['asset_name'],
                                        'url' => "https://" . $_SERVER['HTTP_HOST'] . "/clinic/asset/asset_view.php?id=" . $bulk_asset['asset_id']
                                    );
                                    $bulk_qr_json = json_encode($bulk_qr_data);
                                    $bulk_qr_filename = 'asset_' . $bulk_asset['asset_id'] . '_' . time() . '_' . $index . '.png';
                                    $bulk_qr_filepath = $qr_temp_dir . $bulk_qr_filename;
                                    QRcode::png($bulk_qr_json, $bulk_qr_filepath, QR_ECLEVEL_H, 8, 2);
                                ?>
                                <div class="qr-item <?php echo ($index > 0 && ($index + 1) % 4 == 0) ? 'page-break' : ''; ?>">
                                    <div class="qr-container">
                                        <img src="/temp/qrcodes/<?php echo $bulk_qr_filename; ?>" 
                                             alt="QR Code for <?php echo htmlspecialchars($bulk_asset['asset_tag']); ?>"
                                             class="img-fluid qr-code mb-3">
                                        
                                        <div class="asset-info">
                                            <h5 class="font-weight-bold mb-2"><?php echo htmlspecialchars($bulk_asset['asset_tag']); ?></h5>
                                            <p class="mb-1"><?php echo htmlspecialchars($bulk_asset['asset_name']); ?></p>
                                            <small class="text-muted">
                                                ID: <?php echo str_pad($bulk_asset['asset_id'], 6, '0', STR_PAD_LEFT); ?>
                                            </small>
                                        </div>
                                        
                                        <div class="mt-3">
                                            <small class="text-muted">
                                                Scan to view asset details
                                            </small>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <div class="mt-4 text-center no-print">
                                <div class="btn-group">
                                    <button type="button" class="btn btn-primary" onclick="window.print()">
                                        <i class="fas fa-print mr-2"></i>Print All QR Codes
                                    </button>
                                    <a href="asset_management.php" class="btn btn-secondary">
                                        <i class="fas fa-arrow-left mr-2"></i>Back to Assets
                                    </a>
                                </div>
                            </div>
                            
                        <?php else: ?>
                            <!-- Single QR Code -->
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="qr-container">
                                        <img src="<?php echo $qr_url; ?>" 
                                             alt="QR Code for <?php echo htmlspecialchars($asset['asset_tag']); ?>"
                                             class="img-fluid qr-code mb-3"
                                             id="qrImage">
                                        
                                        <div class="mt-3">
                                            <button type="button" class="btn btn-outline-primary btn-sm" onclick="copyQRData()">
                                                <i class="fas fa-copy mr-1"></i>Copy QR Data
                                            </button>
                                        </div>
                                        
                                        <div class="mt-4">
                                            <h5>QR Code Information:</h5>
                                            <div class="small text-left text-muted bg-light p-3 rounded">
                                                <pre id="qrData" class="mb-0"><?php echo htmlspecialchars($qr_json); ?></pre>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="asset-info">
                                        <h4 class="font-weight-bold mb-3">
                                            <?php echo htmlspecialchars($asset['asset_tag']); ?>
                                            <small class="text-muted d-block"><?php echo htmlspecialchars($asset['asset_name']); ?></small>
                                        </h4>
                                        
                                        <table class="table table-sm">
                                            <tr>
                                                <th width="40%">Asset ID:</th>
                                                <td>#<?php echo str_pad($asset_id, 6, '0', STR_PAD_LEFT); ?></td>
                                            </tr>
                                            <tr>
                                                <th>Category:</th>
                                                <td><?php echo htmlspecialchars($asset['category_name']); ?></td>
                                            </tr>
                                            <?php if ($asset['serial_number']): ?>
                                            <tr>
                                                <th>Serial Number:</th>
                                                <td><?php echo htmlspecialchars($asset['serial_number']); ?></td>
                                            </tr>
                                            <?php endif; ?>
                                            <?php if ($asset['model']): ?>
                                            <tr>
                                                <th>Model:</th>
                                                <td><?php echo htmlspecialchars($asset['model']); ?></td>
                                            </tr>
                                            <?php endif; ?>
                                            <?php if ($asset['location_name']): ?>
                                            <tr>
                                                <th>Location:</th>
                                                <td><?php echo htmlspecialchars($asset['location_name']); ?></td>
                                            </tr>
                                            <?php endif; ?>
                                            <tr>
                                                <th>Current Value:</th>
                                                <td class="text-success font-weight-bold">
                                                    $<?php echo number_format($asset['current_value'], 2); ?>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th>Status:</th>
                                                <td>
                                                    <span class="badge badge-<?php 
                                                        switch($asset['status']) {
                                                            case 'active': echo 'success'; break;
                                                            case 'under_maintenance': echo 'warning'; break;
                                                            case 'disposed': echo 'danger'; break;
                                                            default: echo 'secondary';
                                                        }
                                                    ?>">
                                                        <?php echo ucfirst(str_replace('_', ' ', $asset['status'])); ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        </table>
                                        
                                        <div class="mt-4">
                                            <h6>QR Code Usage:</h6>
                                            <ul class="small text-muted">
                                                <li>Print and attach to physical asset</li>
                                                <li>Use for inventory scanning</li>
                                                <li>Quick access to asset details via mobile</li>
                                                <li>Asset tracking and management</li>
                                            </ul>
                                        </div>
                                    </div>
                                    
                                    <div class="mt-4 no-print">
                                        <h5>Additional Options:</h5>
                                        <div class="d-grid gap-2">
                                            <a href="asset_qr_print.php?id=<?php echo $asset_id; ?>" target="_blank" class="btn btn-outline-primary">
                                                <i class="fas fa-print mr-2"></i>Print with Label
                                            </a>
                                            <button type="button" class="btn btn-outline-success" onclick="generateQRWithCustomText()">
                                                <i class="fas fa-edit mr-2"></i>Customize QR Text
                                            </button>
                                            <a href="asset_qr.php?bulk=true&ids=<?php echo $asset_id; ?>" class="btn btn-outline-info">
                                                <i class="fas fa-layer-group mr-2"></i>Generate Multiple Copies
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row mt-4 no-print">
                                <div class="col-12">
                                    <div class="card">
                                        <div class="card-header">
                                            <h5 class="card-title mb-0">
                                                <i class="fas fa-share-alt mr-2"></i>Share QR Code
                                            </h5>
                                        </div>
                                        <div class="card-body">
                                            <div class="row">
                                                <div class="col-md-4">
                                                    <button type="button" class="btn btn-block btn-outline-dark mb-2" onclick="shareQR('email')">
                                                        <i class="fas fa-envelope mr-2"></i>Email QR Code
                                                    </button>
                                                </div>
                                                <div class="col-md-4">
                                                    <button type="button" class="btn btn-block btn-outline-info mb-2" onclick="shareQR('download')">
                                                        <i class="fas fa-download mr-2"></i>Download High-Res
                                                    </button>
                                                </div>
                                                <div class="col-md-4">
                                                    <button type="button" class="btn btn-block btn-outline-success mb-2" onclick="shareQR('link')">
                                                        <i class="fas fa-link mr-2"></i>Copy QR Link
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="card-footer text-center no-print">
                        <small class="text-muted">
                            QR Code generated on <?php echo date('F j, Y, g:i a'); ?> | 
                            Expires: <?php echo date('F j, Y, g:i a', time() + 3600); ?>
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- QR Code Customization Modal -->
<div class="modal fade" id="customQRModal" tabindex="-1" role="dialog" aria-labelledby="customQRModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="customQRModalLabel">
                    <i class="fas fa-edit mr-2"></i>Customize QR Code
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <form id="customQRForm">
                    <div class="form-group">
                        <label for="qrTitle">QR Code Title</label>
                        <input type="text" class="form-control" id="qrTitle" 
                               value="<?php echo htmlspecialchars($asset['asset_tag'] . ' - ' . $asset['asset_name']); ?>">
                    </div>
                    <div class="form-group">
                        <label for="qrSize">QR Code Size</label>
                        <select class="form-control" id="qrSize">
                            <option value="8">Small (200x200)</option>
                            <option value="10" selected>Medium (250x250)</option>
                            <option value="12">Large (300x300)</option>
                            <option value="15">Extra Large (375x375)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="qrColor">QR Code Color</label>
                        <input type="color" class="form-control" id="qrColor" value="#000000">
                    </div>
                    <div class="form-group">
                        <label for="qrText">Additional Text</label>
                        <textarea class="form-control" id="qrText" rows="3" 
                                  placeholder="Add custom text below QR code..."></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">
                    <i class="fas fa-times mr-2"></i>Cancel
                </button>
                <button type="button" class="btn btn-primary" onclick="applyCustomQR()">
                    <i class="fas fa-check mr-2"></i>Apply Changes
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Scripts -->
<script src="/plugins/jquery/jquery.min.js"></script>
<script src="/plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="/dist/js/adminlte.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>

<script>
$(document).ready(function() {
    // Initialize tooltips
    $('[data-toggle="tooltip"]').tooltip();
    
    // Auto-cleanup old modal on close
    $('#customQRModal').on('hidden.bs.modal', function () {
        $(this).remove();
    });
});

function downloadQR() {
    const qrImage = document.getElementById('qrImage');
    const link = document.createElement('a');
    link.href = qrImage.src;
    link.download = 'asset_qr_<?php echo $asset_id; ?>_<?php echo date('Ymd_His'); ?>.png';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

function copyQRData() {
    const qrData = document.getElementById('qrData');
    const textArea = document.createElement('textarea');
    textArea.value = qrData.textContent;
    document.body.appendChild(textArea);
    textArea.select();
    document.execCommand('copy');
    document.body.removeChild(textArea);
    
    // Show feedback
    const originalText = event.target.innerHTML;
    event.target.innerHTML = '<i class="fas fa-check mr-1"></i>Copied!';
    event.target.classList.remove('btn-outline-primary');
    event.target.classList.add('btn-success');
    
    setTimeout(function() {
        event.target.innerHTML = originalText;
        event.target.classList.remove('btn-success');
        event.target.classList.add('btn-outline-primary');
    }, 2000);
}

function generateQRWithCustomText() {
    $('#customQRModal').modal('show');
}

function applyCustomQR() {
    const title = $('#qrTitle').val();
    const size = $('#qrSize').val();
    const color = $('#qrColor').val();
    const text = $('#qrText').val();
    
    // Show loading
    $('#customQRModal').modal('hide');
    
    // In a real implementation, you would make an AJAX call to regenerate the QR
    // For now, we'll just show an alert
    alert('Custom QR code generation would be implemented here.\n\n' +
          'Title: ' + title + '\n' +
          'Size: ' + size + '\n' +
          'Color: ' + color + '\n' +
          'Text: ' + text);
}

function shareQR(method) {
    const qrImage = document.getElementById('qrImage');
    
    switch(method) {
        case 'email':
            const subject = 'QR Code for Asset: <?php echo htmlspecialchars($asset['asset_tag']); ?>';
            const body = 'Please find attached the QR code for asset:\n\n' +
                        'Asset Tag: <?php echo htmlspecialchars($asset['asset_tag']); ?>\n' +
                        'Asset Name: <?php echo htmlspecialchars($asset['asset_name']); ?>\n' +
                        'Link: <?php echo "https://" . $_SERVER['HTTP_HOST'] . "/clinic/asset/asset_view.php?id=" . $asset_id; ?>\n\n' +
                        'This QR code expires on: <?php echo date('F j, Y, g:i a', time() + 3600); ?>';
            window.location.href = 'mailto:?subject=' + encodeURIComponent(subject) + 
                                   '&body=' + encodeURIComponent(body);
            break;
            
        case 'download':
            // Trigger download of high-resolution version
            const highResUrl = '<?php echo $qr_url; ?>' + '&size=500';
            const link = document.createElement('a');
            link.href = highResUrl;
            link.download = 'asset_qr_highres_<?php echo $asset_id; ?>.png';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            break;
            
        case 'link':
            // Copy QR link to clipboard
            const qrLink = '<?php echo "https://" . $_SERVER['HTTP_HOST'] . $qr_url; ?>';
            const tempInput = document.createElement('input');
            tempInput.value = qrLink;
            document.body.appendChild(tempInput);
            tempInput.select();
            document.execCommand('copy');
            document.body.removeChild(tempInput);
            
            // Show feedback
            alert('QR Code link copied to clipboard!');
            break;
    }
}

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + P to print
    if (e.ctrlKey && e.keyCode === 80) {
        e.preventDefault();
        window.print();
    }
    // Ctrl + D to download
    if (e.ctrlKey && e.keyCode === 68) {
        e.preventDefault();
        downloadQR();
    }
    // Ctrl + C to copy QR data
    if (e.ctrlKey && e.keyCode === 67) {
        e.preventDefault();
        copyQRData();
    }
    // Escape to go back
    if (e.keyCode === 27) {
        window.history.back();
    }
});

// Print optimization
window.onbeforeprint = function() {
    // Add print-specific styling
    $('body').addClass('print-mode');
};

window.onafterprint = function() {
    // Remove print-specific styling
    $('body').removeClass('print-mode');
};

// Auto-refresh QR code if it's about to expire
setTimeout(function() {
    if (!document.hidden) {
        const expireTime = <?php echo (time() + 3600) * 1000; ?>;
        const now = Date.now();
        
        if (expireTime - now < 300000) { // 5 minutes before expiry
            if (confirm('QR code is about to expire. Would you like to refresh it?')) {
                location.reload();
            }
        }
    }
}, 300000); // Check every 5 minutes
</script>

<style>
.print-mode .no-print {
    display: none !important;
}

.print-mode .qr-container {
    page-break-inside: avoid;
}

.print-mode .asset-info {
    border: 1px solid #000 !important;
}

@media print {
    @page {
        margin: 0.5cm;
        size: A4;
    }
    
    body {
        background: white !important;
        color: black !important;
    }
    
    .card {
        border: none !important;
        box-shadow: none !important;
    }
    
    .card-header {
        background: white !important;
        color: black !important;
        border-bottom: 2px solid #000 !important;
    }
    
    .btn, .no-print {
        display: none !important;
    }
    
    .qr-code {
        max-width: 250px !important;
    }
}
</style>

</body>
</html>