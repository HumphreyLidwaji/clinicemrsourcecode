<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

/* -----------------------------------------------------------
   PROCESS FORM SUBMISSION
----------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    $from_location_id = intval($_POST['from_location_id']);
    $to_location_id   = intval($_POST['to_location_id']);
    $transfer_date    = $_POST['transfer_date'];
    $notes            = trim($_POST['notes']);

    // Unique transfer number
    $transfer_number = 'TRF-' . date('Ymd') . '-' . strtoupper(uniqid());

    $mysqli->begin_transaction();

    try {
        /* Insert Transfer Header */
        $transfer_sql = "
            INSERT INTO inventory_transfers 
                (transfer_number, from_location_id, to_location_id, transfer_date, notes, requested_by, transfer_status)
            VALUES (?, ?, ?, ?, ?, ?, 'pending')
        ";

        $stmt = $mysqli->prepare($transfer_sql);
        $stmt->bind_param("siissi",
            $transfer_number,
            $from_location_id,
            $to_location_id,
            $transfer_date,
            $notes,
            $_SESSION['user_id']
        );

        if (!$stmt->execute()) {
            throw new Exception("Failed to create transfer: " . $stmt->error);
        }

        $transfer_id = $mysqli->insert_id;
        $stmt->close();

        /* Insert Transfer Items */
        if (isset($_POST['item_ids']) && is_array($_POST['item_ids'])) {

            foreach ($_POST['item_ids'] as $i => $item_id) {
                $quantity    = floatval($_POST['quantities'][$i]);
                $item_notes  = trim($_POST['item_notes'][$i] ?? '');

                if ($quantity <= 0) {
                    continue;
                }

                /* Validate item stock */
                $stock_sql = "SELECT item_quantity FROM inventory_items WHERE item_id = ?";
                $s = $mysqli->prepare($stock_sql);
                $s->bind_param("i", $item_id);
                $s->execute();
                $stock = $s->get_result()->fetch_assoc();
                $s->close();

                if ($stock['item_quantity'] < $quantity) {
                    throw new Exception("Insufficient stock for item ID: " . $item_id);
                }

                /* Insert Transfer Item */
                $item_sql = "
                    INSERT INTO inventory_transfer_items
                        (transfer_id, item_id, quantity, notes)
                    VALUES (?, ?, ?, ?)
                ";

                $i_stmt = $mysqli->prepare($item_sql);
                $i_stmt->bind_param("iids", $transfer_id, $item_id, $quantity, $item_notes);

                if (!$i_stmt->execute()) {
                    throw new Exception("Failed to add transfer item: " . $i_stmt->error);
                }

                $i_stmt->close();
            }
        }

        $mysqli->commit();

        $_SESSION['alert_type']    = "success";
        $_SESSION['alert_message'] = "Bulk transfer created successfully! Transfer #: $transfer_number";
        header("Location: inventory_transfers.php");
        exit;

    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type']    = "error";
        $_SESSION['alert_message'] = "Error creating transfer: " . $e->getMessage();
    }
}

/* -----------------------------------------------------------
   LOAD LOCATIONS
----------------------------------------------------------- */
$locations = [];
$res = $mysqli->query("SELECT location_id, location_name, location_type FROM inventory_locations ORDER BY location_name");
while ($loc = $res->fetch_assoc()) {
    $locations[] = $loc;
}

/* -----------------------------------------------------------
   LOAD ITEMS FROM SELECTED LOCATION
----------------------------------------------------------- */
$location_items = [];
$selected_location_id = $_GET['location_id'] ?? null;

if ($selected_location_id) {

    $loc_id = intval($selected_location_id);

    $items_sql = "
        SELECT 
            i.*, 
            ili.quantity AS location_stock
        FROM inventory_location_items ili
        INNER JOIN inventory_items i ON i.item_id = ili.item_id
        WHERE ili.location_id = ?
          AND ili.quantity > 0
         
        ORDER BY i.item_name
    ";

    $stmt = $mysqli->prepare($items_sql);
    $stmt->bind_param("i", $loc_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($item = $result->fetch_assoc()) {
        $location_items[] = $item;
    }
    $stmt->close();
}

?>

<!-- ============================================================
     PAGE CONTENT
============================================================ -->

<div class="card">
    <div class="card-header bg-primary py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0 text-white">
                <i class="fas fa-truck-loading mr-2"></i>
                Create Bulk Transfer
            </h3>
            <a href="inventory_transfers.php" class="btn btn-light">
                <i class="fas fa-arrow-left mr-2"></i>Back
            </a>
        </div>
    </div>

    <div class="card-body">
        <!-- Alerts -->
        <?php if (isset($_SESSION['alert_message'])): ?>
            <div class="alert alert-<?= $_SESSION['alert_type'] ?> alert-dismissible">
                <button class="close" data-dismiss="alert">×</button>
                <?= $_SESSION['alert_message'] ?>
            </div>
        <?php unset($_SESSION['alert_message'], $_SESSION['alert_type']); endif; ?>


        <form method="POST" id="bulkTransferForm">

            <div class="row">
                <!-- LEFT SIDE -->
                <div class="col-md-6">
                    <div class="card card-primary">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-info-circle mr-2"></i>Transfer Details
                            </h3>
                        </div>

                        <div class="card-body">

                            <!-- FROM LOCATION -->
                            <div class="form-group">
                                <label>From Location *</label>
                                <select class="form-control select2"
                                    name="from_location_id" required
                                    onchange="window.location='inventory_bulk_transfer.php?location_id=' + this.value">
                                    <option value="">Select Source</option>
                                    <?php foreach ($locations as $loc): ?>
                                        <option value="<?= $loc['location_id'] ?>"
                                            <?= ($selected_location_id == $loc['location_id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($loc['location_type'] . ' - ' . $loc['location_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- TO LOCATION -->
                            <div class="form-group">
                                <label>To Location *</label>
                                <select class="form-control select2" name="to_location_id" id="to_location_id" required>
                                    <option value="">Select Destination</option>
                                    <?php foreach ($locations as $loc): ?>
                                        <option value="<?= $loc['location_id'] ?>">
                                            <?= htmlspecialchars($loc['location_type'] . ' - ' . $loc['location_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- DATE -->
                            <div class="form-group">
                                <label>Transfer Date *</label>
                                <input type="datetime-local" class="form-control"
                                    name="transfer_date" value="<?= date('Y-m-d\TH:i') ?>" required>
                            </div>

                            <!-- NOTES -->
                            <div class="form-group">
                                <label>Notes</label>
                                <textarea class="form-control" name="notes" rows="3"
                                          placeholder="Optional..."></textarea>
                            </div>

                        </div>
                    </div>
                </div>

                <!-- RIGHT SIDE -->
                <div class="col-md-6">

                    <!-- ITEMS LIST -->
                    <div class="card card-success">
                        <div class="card-header d-flex justify-content-between">
                            <h3 class="card-title">
                                <i class="fas fa-boxes mr-2"></i>Items at Location
                            </h3>

                            <?php if ($selected_location_id && !empty($location_items)): ?>
                                <button type="button" class="btn btn-tool" onclick="selectAllItems()">
                                    <i class="fas fa-check-double"></i> Select All
                                </button>
                            <?php endif; ?>
                        </div>

                        <div class="card-body p-0" style="max-height:400px; overflow-y:auto;">

                            <?php if (!$selected_location_id): ?>
                                <div class="text-center py-5 text-muted">
                                    <i class="fas fa-map-marker-alt fa-3x mb-3"></i><br>
                                    Select a source location to load items.
                                </div>

                            <?php elseif (empty($location_items)): ?>
                                <div class="text-center py-5 text-muted">
                                    <i class="fas fa-box-open fa-3x mb-3"></i><br>
                                    No items with stock at this location.
                                </div>

                            <?php else: ?>
                                <table class="table table-hover mb-0">
                                    <thead class="bg-light sticky-top">
                                        <tr>
                                            <th style="width:40px;"></th>
                                            <th>Item</th>
                                            <th class="text-center">Available</th>
                                            <th class="text-center">Qty</th>
                                            <th>Notes</th>
                                        </tr>
                                    </thead>

                                    <tbody id="itemsTable">

                                    <?php foreach ($location_items as $i => $item): ?>
                                        <tr>
                                            <!-- SELECT -->
                                            <td>
                                                <input type="checkbox" class="item-checkbox"
                                                    name="item_ids[]" value="<?= $item['item_id'] ?>"
                                                    onchange="toggleRow(this, <?= $i ?>)">
                                            </td>

                                            <!-- ITEM INFO -->
                                            <td>
                                                <strong><?= htmlspecialchars($item['item_name']) ?></strong><br>
                                                <small class="text-muted"><?= htmlspecialchars($item['item_code']) ?></small>
                                            </td>

                                            <!-- STOCK -->
                                            <td class="text-center">
                                                <span class="badge badge-info">
                                                    <?= number_format($item['location_stock']) ?>
                                                </span><br>
                                                <small class="text-muted"><?= $item['item_unit_measure'] ?></small>
                                            </td>

                                            <!-- QUANTITY -->
                                            <td class="text-center">
                                                <input type="number" class="form-control form-control-sm quantity-input"
                                                    name="quantities[]"
                                                    data-index="<?= $i ?>"
                                                    data-max="<?= $item['location_stock'] ?>"
                                                    disabled min="0" step="0.01">
                                            </td>

                                            <!-- NOTES -->
                                            <td>
                                                <input type="text" class="form-control form-control-sm notes-input"
                                                    data-index="<?= $i ?>"
                                                    name="item_notes[]" disabled
                                                    placeholder="Optional...">
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>

                                    </tbody>
                                </table>
                            <?php endif; ?>

                        </div>
                    </div>

                    <!-- SUMMARY -->
                    <div class="card card-info mt-3">
                        <div class="card-header">
                            <h3 class="card-title">
                                <i class="fas fa-calculator mr-2"></i>Summary
                            </h3>
                        </div>
                        <div class="card-body">

                            <div class="alert alert-secondary text-center">
                                <div class="row">
                                    <div class="col-6">
                                        <small>Selected Items</small>
                                        <h4 id="selectedCount">0</h4>
                                    </div>
                                    <div class="col-6">
                                        <small>Total Qty</small>
                                        <h4 id="totalQuantity">0</h4>
                                    </div>
                                </div>
                            </div>

                            <button type="submit" id="createButton"
                                class="btn btn-success btn-block btn-lg" disabled>
                                <i class="fas fa-truck-loading mr-2"></i>Create Bulk Transfer
                            </button>

                        </div>
                    </div>

                </div>
            </div>

        </form>
    </div>
</div>


<!-- ============================================================
     JAVASCRIPT
============================================================ -->

<script>

function toggleRow(cb, index) {
    let qty  = document.querySelector(`.quantity-input[data-index="${index}"]`);
    let note = document.querySelector(`.notes-input[data-index="${index}"]`);

    qty.disabled  = !cb.checked;
    note.disabled = !cb.checked;

    if (!cb.checked) {
        qty.value = "";
        note.value = "";
    }

    updateSummary();
}

function selectAllItems() {
    let checkboxes = document.querySelectorAll('.item-checkbox');
    let allChecked = [...checkboxes].every(cb => cb.checked);

    checkboxes.forEach((cb, i) => {
        cb.checked = !allChecked;
        toggleRow(cb, i);
    });
}

function updateSummary() {
    let selected = document.querySelectorAll('.item-checkbox:checked').length;
    document.getElementById('selectedCount').innerText = selected;

    let totalQty = 0;
    document.querySelectorAll('.quantity-input:not(:disabled)').forEach(input => {
        let v = parseFloat(input.value) || 0;
        totalQty += v;
    });

    document.getElementById('totalQuantity').innerText = totalQty.toFixed(2);

    document.getElementById('createButton').disabled = !(selected > 0 && totalQty > 0);
}

document.addEventListener("input", function(e) {
    if (e.target.classList.contains("quantity-input")) {
        let max = parseFloat(e.target.dataset.max);
        let val = parseFloat(e.target.value) || 0;

        if (val > max) {
            e.target.value = max;
            alert("Exceeds available stock (" + max + ")");
        }

        updateSummary();
    }
});

document.getElementById('bulkTransferForm').addEventListener("submit", function(e) {
    let from = document.getElementById("from_location_id").value;
    let to   = document.getElementById("to_location_id").value;

    if (from === to) {
        alert("Source and destination cannot be the same!");
        e.preventDefault();
        return false;
    }

    if (!confirm("Create bulk transfer?")) {
        e.preventDefault();
    }
});

$(document).ready(() => $('.select2').select2({ theme:'bootstrap4' }));
</script>

<style>
.quantity-input:disabled, .notes-input:disabled {
    background:#f2f2f2;
}
#itemsTable tr:hover { background:#f8f9fa; }
.sticky-top { top:0; z-index:5; }
</style>


<?php require_once $_SERVER['DOCUMENT_ROOT'].'/includes/footer.php'; ?>
