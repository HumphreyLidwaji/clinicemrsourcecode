<?php
// laundry_wash_view.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

$page_title = "Wash Cycle Details";

// Get wash ID
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: laundry_management.php");
    exit();
}

$wash_id = intval($_GET['id']);

// Get wash cycle details
$sql = "
    SELECT wc.*, u.user_name as completed_by_name
    FROM laundry_wash_cycles wc
    LEFT JOIN users u ON wc.completed_by = u.user_id
    WHERE wc.wash_id = ?
";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $wash_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    $_SESSION['alert_message'] = "Wash cycle not found";
    $_SESSION['alert_type'] = "danger";
    header("Location: laundry_management.php");
    exit();
}

$wash = $result->fetch_assoc();

// Get items in this wash cycle
$items_sql = "
    SELECT wci.*, li.*, a.asset_name, a.asset_tag
    FROM wash_cycle_items wci
    LEFT JOIN laundry_items li ON wci.laundry_id = li.laundry_id
    LEFT JOIN assets a ON li.asset_id = a.asset_id
    WHERE wci.wash_id = ?
    ORDER BY a.asset_name
";
$items_stmt = $mysqli->prepare($items_sql);
$items_stmt->bind_param("i", $wash_id);
$items_stmt->execute();
$items_result = $items_stmt->get_result();
?>

<div class="card">
    <div class="card-header bg-dark py-2">
        <h3 class="card-title mt-2 mb-0 text-white">
            <i class="fas fa-fw fa-tint mr-2"></i>Wash Cycle Details
        </h3>
        <div class="card-tools">
            <button class="btn btn-light" onclick="window.print()">
                <i class="fas fa-fw fa-print mr-2"></i>Print
            </button>
            <a href="laundry_wash_cycles.php" class="btn btn-secondary ml-2">
                <i class="fas fa-fw fa-arrow-left mr-2"></i>Back to Wash History
            </a>
        </div>
    </div>
    
    <div class="card-body">
        <!-- Wash Summary -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card bg-primary text-white">
                    <div class="card-body text-center">
                        <h6>Wash Date</h6>
                        <h3><?php echo date('M j, Y', strtotime($wash['wash_date'])); ?></h3>
                        <small><?php echo date('g:i A', strtotime($wash['wash_time'])); ?></small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card bg-success text-white">
                    <div class="card-body text-center">
                        <h6>Items Washed</h6>
                        <h3><?php echo $wash['items_washed']; ?></h3>
                        <small>Total Items</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card bg-info text-white">
                    <div class="card-body text-center">
                        <h6>Completed By</h6>
                        <h5><?php echo htmlspecialchars($wash['completed_by_name']); ?></h5>
                        <small>Staff Member</small>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Wash Details -->
        <div class="row">
            <div class="col-md-6">
                <div class="card mb-4">
                    <div class="card-header bg-light">
                        <h5 class="mb-0"><i class="fas fa-cogs mr-2"></i>Wash Settings</h5>
                    </div>
                    <div class="card-body">
                        <table class="table table-borderless">
                            <tr>
                                <th width="40%">Temperature:</th>
                                <td>
                                    <span class="badge badge-<?php 
                                        switch($wash['temperature']) {
                                            case 'hot': echo 'danger'; break;
                                            case 'warm': echo 'warning'; break;
                                            case 'cold': echo 'info'; break;
                                            default: echo 'secondary';
                                        }
                                    ?>">
                                        <?php echo ucfirst($wash['temperature']); ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <th>Detergent Type:</th>
                                <td><?php echo ucfirst($wash['detergent_type'] ?? '—'); ?></td>
                            </tr>
                            <tr>
                                <th>Total Weight:</th>
                                <td>
                                    <?php if ($wash['total_weight']): ?>
                                        <?php echo $wash['total_weight']; ?> kg
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th>Additives:</th>
                                <td>
                                    <?php if ($wash['bleach_used']): ?>
                                        <span class="badge badge-light mr-2">Bleach</span>
                                    <?php endif; ?>
                                    <?php if ($wash['fabric_softener_used']): ?>
                                        <span class="badge badge-light">Fabric Softener</span>
                                    <?php endif; ?>
                                    <?php if (!$wash['bleach_used'] && !$wash['fabric_softener_used']): ?>
                                        <span class="text-muted">None</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                <!-- Notes -->
                <?php if ($wash['notes']): ?>
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="mb-0"><i class="fas fa-sticky-note mr-2"></i>Notes</h5>
                    </div>
                    <div class="card-body">
                        <p><?php echo nl2br(htmlspecialchars($wash['notes'])); ?></p>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="col-md-6">
                <!-- Items List -->
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="mb-0"><i class="fas fa-tshirt mr-2"></i>Items Washed</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($items_result->num_rows == 0): ?>
                            <p class="text-muted text-center">No items found</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Item</th>
                                            <th>Condition Before</th>
                                            <th>Condition After</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while($item = $items_result->fetch_assoc()): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($item['asset_name']); ?></strong><br>
                                                <small class="text-muted"><?php echo htmlspecialchars($item['asset_tag']); ?></small>
                                            </td>
                                            <td>
                                                <span class="badge badge-<?php 
                                                    switch($item['condition_before']) {
                                                        case 'clean': echo 'success'; break;
                                                        case 'dirty': echo 'warning'; break;
                                                        case 'damaged': echo 'danger'; break;
                                                        default: echo 'secondary';
                                                    }
                                                ?>">
                                                    <?php echo ucfirst($item['condition_before']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge badge-<?php 
                                                    switch($item['condition_after']) {
                                                        case 'clean': echo 'success'; break;
                                                        case 'dirty': echo 'warning'; break;
                                                        case 'damaged': echo 'danger'; break;
                                                        default: echo 'secondary';
                                                    }
                                                ?>">
                                                    <?php echo ucfirst($item['condition_after']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <a href="laundry_view.php?id=<?php echo $item['laundry_id']; ?>" 
                                                   class="btn btn-sm btn-outline-info">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Metadata -->
        <div class="row mt-4">
            <div class="col-md-12 text-center">
                <small class="text-muted">
                    <i class="fas fa-clock mr-1"></i>
                    Completed on <?php echo date('M j, Y', strtotime($wash['created_at'])); ?>
                </small>
            </div>
        </div>
    </div>
</div>

<style>
@media print {
    .card-header, .btn {
        display: none !important;
    }
    
    .card {
        border: none !important;
        box-shadow: none !important;
    }
}
</style>

<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>