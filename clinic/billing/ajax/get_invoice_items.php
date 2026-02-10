<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';

header('Content-Type: text/html; charset=utf-8');

if (!isset($_GET['invoice_id']) || empty($_GET['invoice_id'])) {
    echo '<tr><td colspan="5" class="text-center text-danger">No invoice selected</td></tr>';
    exit;
}

$invoice_id = intval($_GET['invoice_id']);

// Get unpaid invoice items
$sql = "
    SELECT 
        ii.item_id,
        ii.item_name,
        ii.item_description,
        ii.item_quantity,
        ii.item_price,
        ii.item_total,
        ii.paid_amount,
        (ii.item_total - COALESCE(ii.paid_amount, 0)) as balance_due,
        s.service_name
    FROM invoice_items ii
    LEFT JOIN services s ON ii.service_id = s.service_id
    WHERE ii.item_invoice_id = ? 
    AND (ii.item_total - COALESCE(ii.paid_amount, 0)) > 0
    ORDER BY ii.item_order, ii.item_id
";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param('i', $invoice_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo '<tr><td colspan="5" class="text-center text-muted">All items are already paid</td></tr>';
    exit;
}

while ($item = $result->fetch_assoc()) {
    $item_id = intval($item['item_id']);
    $item_name = htmlspecialchars($item['item_name']);
    $item_description = htmlspecialchars($item['item_description'] ?? '');
    $service_name = htmlspecialchars($item['service_name'] ?? '');
    $quantity = floatval($item['item_quantity']);
    $total = floatval($item['item_total']);
    $balance_due = floatval($item['balance_due']);
    
    // Use service name if available, otherwise item name
    $display_name = !empty($service_name) ? $service_name : $item_name;
    ?>
    <tr>
        <td>
            <input type="checkbox" class="item-checkbox" 
                   value="<?php echo $item_id; ?>" 
                   data-balance="<?php echo number_format($balance_due, 2, '.', ''); ?>"
                   checked>
        </td>
        <td>
            <div class="font-weight-bold"><?php echo $display_name; ?></div>
            <?php if ($item_description): ?>
                <small class="text-muted"><?php echo $item_description; ?></small>
            <?php endif; ?>
            <?php if ($quantity > 1): ?>
                <small class="text-muted d-block">Qty: <?php echo $quantity; ?></small>
            <?php endif; ?>
        </td>
        <td class="text-right">
            $<?php echo number_format($total, 2); ?>
        </td>
        <td class="text-right">
            $<?php echo number_format($item['paid_amount'], 2); ?>
        </td>
        <td class="text-right font-weight-bold text-danger">
            $<?php echo number_format($balance_due, 2); ?>
        </td>
    </tr>
    <?php
}

$stmt->close();
?>