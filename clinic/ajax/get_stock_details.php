<?php
require_once "../includes/inc_all.php";

if (!isset($_SESSION['user_id'])) {
    die("Unauthorized");
}

if (isset($_GET['stock_id'])) {
    $stock_id = intval($_GET['stock_id']);
    
    $stock = mysqli_fetch_assoc(mysqli_query($mysqli, "
        SELECT ds.*, d.drug_name, d.drug_form, d.drug_unit
        FROM drug_stocks ds
        JOIN drugs d ON ds.stock_drug_id = d.drug_id
        WHERE ds.stock_id = $stock_id
    "));
    
    if ($stock) {
        ?>
        <form action="../post/pharmacy.php" method="post">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="stock_id" value="<?php echo $stock_id; ?>">
            
            <div class="form-group">
                <label>Drug</label>
                <input type="text" class="form-control" value="<?php echo $stock['drug_name']; ?>" disabled>
            </div>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label>Batch Number</label>
                        <input type="text" class="form-control" name="batch_number" value="<?php echo $stock['stock_batch_number']; ?>">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label>Expiry Date</label>
                        <input type="date" class="form-control" name="expiry_date" value="<?php echo $stock['stock_expiry_date']; ?>">
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label>Location</label>
                        <select class="form-control" name="location">
                            <option value="Main Pharmacy" <?php echo $stock['stock_location'] == 'Main Pharmacy' ? 'selected' : ''; ?>>Main Pharmacy</option>
                            <option value="Ward Stock" <?php echo $stock['stock_location'] == 'Ward Stock' ? 'selected' : ''; ?>>Ward Stock</option>
                            <option value="Emergency" <?php echo $stock['stock_location'] == 'Emergency' ? 'selected' : ''; ?>>Emergency</option>
                            <option value="ICU" <?php echo $stock['stock_location'] == 'ICU' ? 'selected' : ''; ?>>ICU</option>
                        </select>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label>Available Quantity</label>
                        <input type="number" class="form-control" name="available_quantity" value="<?php echo $stock['stock_available_quantity']; ?>" min="0">
                    </div>
                </div>
            </div>
            
            <div class="form-group">
                <label>Purchase Price</label>
                <input type="number" class="form-control" name="purchase_price" step="0.01" value="<?php echo $stock['stock_purchase_price']; ?>">
            </div>
            
            <div class="text-right">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button type="submit" name="update_stock" class="btn btn-primary">Update Stock</button>
            </div>
        </form>
        <?php
    } else {
        echo "<div class='alert alert-danger'>Stock record not found</div>";
    }
}
?>