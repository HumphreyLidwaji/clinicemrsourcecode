<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Default Column Sortby/Order Filter
$sort = "t.theatre_number";
$order = "ASC";

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Filter parameters
$status_filter = $_GET['status'] ?? '';

// Status Filter
if ($status_filter) {
    $status_query = "AND t.status = '" . sanitizeInput($status_filter) . "'";
} else {
    $status_query = '';
}

// Search Query
$q = sanitizeInput($_GET['q'] ?? '');
if (!empty($q)) {
    $search_query = "AND (
        t.theatre_number LIKE '%$q%' 
        OR t.theatre_name LIKE '%$q%'
        OR t.location LIKE '%$q%'
        OR t.description LIKE '%$q%'
    )";
} else {
    $search_query = '';
}

// Main query for theatres
$sql = mysqli_query(
    $mysqli,
    "
    SELECT SQL_CALC_FOUND_ROWS t.*,
           u.user_name as created_by_name,
           (SELECT COUNT(*) FROM surgeries s 
            WHERE s.theatre_id = t.theatre_id 
            AND s.scheduled_date = CURDATE() 
            AND s.status IN ('scheduled', 'confirmed', 'in_progress')
            AND s.archived_at IS NULL) as today_surgeries,
           (SELECT COUNT(*) FROM surgeries s 
            WHERE s.theatre_id = t.theatre_id 
            AND s.scheduled_date >= CURDATE() 
            AND s.status IN ('scheduled', 'confirmed')
            AND s.archived_at IS NULL) as upcoming_surgeries
    FROM theatres t
    LEFT JOIN users u ON t.created_by = u.user_id
    WHERE t.archived_at IS NULL
      $status_query
      $search_query
    ORDER BY $sort $order
    LIMIT $record_from, $record_to
");

$num_rows = mysqli_fetch_row(mysqli_query($mysqli, "SELECT FOUND_ROWS()"));

// Get statistics
$total_theatres = $num_rows[0];
$available_count = 0;
$in_use_count = 0;
$maintenance_count = 0;
$cleaning_count = 0;

// Reset pointer and calculate
mysqli_data_seek($sql, 0);
while ($theatre = mysqli_fetch_assoc($sql)) {
    switch($theatre['status']) {
        case 'available':
            $available_count++;
            break;
        case 'in_use':
            $in_use_count++;
            break;
        case 'maintenance':
            $maintenance_count++;
            break;
        case 'cleaning':
            $cleaning_count++;
            break;
    }
}
mysqli_data_seek($sql, $record_from);
?>

<div class="card">
    <div class="card-header bg-primary py-2">
        <h3 class="card-title mt-2 mb-0"><i class="fas fa-fw fa-hospital-alt mr-2"></i>Operation Theatre Management</h3>
        <div class="card-tools">
            <a href="theatre_new.php" class="btn btn-success">
                <i class="fas fa-plus mr-2"></i>New Theatre
            </a>
        </div>
    </div>



    <div class="card-header pb-2 pt-3">
        <form autocomplete="off">
            <div class="row">
                <div class="col-md-5">
                    <div class="form-group">
                        <div class="input-group">
                            <input type="search" class="form-control" name="q" value="<?php if (isset($q)) { echo stripslashes(nullable_htmlentities($q)); } ?>" placeholder="Search theatre number, name, location..." autofocus>
                            <div class="input-group-append">
                                <button class="btn btn-secondary" type="button" data-toggle="collapse" data-target="#advancedFilter"><i class="fas fa-filter"></i></button>
                                <button class="btn btn-primary"><i class="fa fa-search"></i></button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-7">
                    <div class="btn-toolbar form-group float-right">
                        <div class="btn-group">
                            <span class="btn btn-light border">
                                <i class="fas fa-hospital-alt text-primary mr-1"></i>
                                Total: <strong><?php echo $total_theatres; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-check-circle text-success mr-1"></i>
                                Available: <strong><?php echo $available_count; ?></strong>
                            </span>
                            <span class="btn btn-light border">
                                <i class="fas fa-procedures text-warning mr-1"></i>
                                In Use: <strong><?php echo $in_use_count; ?></strong>
                            </span>
                            <a href="ot_schedule.php" class="btn btn-info ml-2">
                                <i class="fas fa-fw fa-calendar-alt mr-2"></i>OT Schedule
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="collapse <?php if ($status_filter || isset($_GET['capacity_min']) || isset($_GET['capacity_max'])) { echo "show"; } ?>" id="advancedFilter">
                <div class="row">
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Status</label>
                            <select class="form-control select2" name="status" onchange="this.form.submit()">
                                <option value="">- All Status -</option>
                                <option value="available" <?php if ($status_filter == "available") { echo "selected"; } ?>>Available</option>
                                <option value="in_use" <?php if ($status_filter == "in_use") { echo "selected"; } ?>>In Use</option>
                                <option value="maintenance" <?php if ($status_filter == "maintenance") { echo "selected"; } ?>>Maintenance</option>
                                <option value="cleaning" <?php if ($status_filter == "cleaning") { echo "selected"; } ?>>Cleaning</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Capacity (Min)</label>
                            <input type="number" class="form-control" name="capacity_min" min="1" max="50" 
                                   value="<?php echo $_GET['capacity_min'] ?? ''; ?>" 
                                   onchange="this.form.submit()" placeholder="Min capacity">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Capacity (Max)</label>
                            <input type="number" class="form-control" name="capacity_max" min="1" max="50" 
                                   value="<?php echo $_GET['capacity_max'] ?? ''; ?>" 
                                   onchange="this.form.submit()" placeholder="Max capacity">
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Quick Actions</label>
                            <div class="btn-group btn-block">
                                <a href="theatre_management.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-times mr-2"></i>Clear Filters
                                </a>
                                <a href="theatre_new.php" class="btn btn-success">
                                    <i class="fas fa-plus mr-2"></i>New Theatre
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
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=t.theatre_number&order=<?php echo $disp; ?>">
                        Theatre # <?php if ($sort == 't.theatre_number') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=t.theatre_name&order=<?php echo $disp; ?>">
                        Theatre Name <?php if ($sort == 't.theatre_name') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>Location</th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=t.capacity&order=<?php echo $disp; ?>">
                        Capacity <?php if ($sort == 't.capacity') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=t.status&order=<?php echo $disp; ?>">
                        Status <?php if ($sort == 't.status') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>
                    <a class="text-dark" href="?<?php echo $url_query_strings_sort; ?>&sort=today_surgeries&order=<?php echo $disp; ?>">
                        Today's Surgeries <?php if ($sort == 'today_surgeries') { echo $order_icon; } ?>
                    </a>
                </th>
                <th>Upcoming Surgeries</th>
                <th class="text-center">Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php while ($row = mysqli_fetch_array($sql)) {
                $theatre_id = intval($row['theatre_id']);
                $theatre_number = nullable_htmlentities($row['theatre_number']);
                $theatre_name = nullable_htmlentities($row['theatre_name']);
                $location = nullable_htmlentities($row['location']);
                $capacity = intval($row['capacity']);
                $status = nullable_htmlentities($row['status']);
                $description = nullable_htmlentities($row['description']);
                $today_surgeries = intval($row['today_surgeries']);
                $upcoming_surgeries = intval($row['upcoming_surgeries']);
                $created_by_name = nullable_htmlentities($row['created_by_name']);

                // Status badge styling
                $status_badge = '';
                $status_icon = '';
                switch($status) {
                    case 'available':
                        $status_badge = 'badge-success';
                        $status_icon = 'fa-check-circle';
                        break;
                    case 'in_use':
                        $status_badge = 'badge-warning';
                        $status_icon = 'fa-procedures';
                        break;
                    case 'maintenance':
                        $status_badge = 'badge-danger';
                        $status_icon = 'fa-tools';
                        break;
                    case 'cleaning':
                        $status_badge = 'badge-info';
                        $status_icon = 'fa-broom';
                        break;
                    default:
                        $status_badge = 'badge-secondary';
                        $status_icon = 'fa-question';
                }

                // Capacity indicator
                $capacity_class = '';
                if ($capacity >= 10) {
                    $capacity_class = 'text-success font-weight-bold';
                } elseif ($capacity >= 5) {
                    $capacity_class = 'text-warning';
                } else {
                    $capacity_class = 'text-info';
                }
                ?>
                <tr class="<?php echo $status == 'available' ? 'table-success' : ($status == 'in_use' ? 'table-warning' : ''); ?>">
                    <td>
                        <div class="font-weight-bold text-primary">OT <?php echo $theatre_number; ?></div>
                        <small class="text-muted">ID: <?php echo $theatre_id; ?></small>
                    </td>
                    <td>
                        <div class="font-weight-bold"><?php echo $theatre_name; ?></div>
                        <?php if($description): ?>
                            <small class="text-muted"><?php echo strlen($description) > 50 ? substr($description, 0, 50) . '...' : $description; ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div><i class="fas fa-map-marker-alt text-muted mr-1"></i> <?php echo $location; ?></div>
                    </td>
                    <td>
                        <span class="<?php echo $capacity_class; ?>">
                            <i class="fas fa-users mr-1"></i><?php echo $capacity; ?> person(s)
                        </span>
                    </td>
                    <td>
                        <span class="badge <?php echo $status_badge; ?>">
                            <i class="fas <?php echo $status_icon; ?> mr-1"></i><?php echo ucfirst(str_replace('_', ' ', $status)); ?>
                        </span>
                    </td>
                    <td>
                        <?php if($today_surgeries > 0): ?>
                            <span class="badge badge-primary badge-pill"><?php echo $today_surgeries; ?></span>
                            <small class="text-muted">surgery(ies)</small>
                        <?php else: ?>
                            <span class="text-muted"><i class="fas fa-ban mr-1"></i>None</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if($upcoming_surgeries > 0): ?>
                            <span class="badge badge-info badge-pill"><?php echo $upcoming_surgeries; ?></span>
                            <small class="text-muted">scheduled</small>
                        <?php else: ?>
                            <span class="text-muted"><i class="fas fa-calendar-times mr-1"></i>None</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="dropdown dropleft text-center">
                            <button class="btn btn-secondary btn-sm" type="button" data-toggle="dropdown">
                                <i class="fas fa-ellipsis-h"></i>
                            </button>
                            <div class="dropdown-menu">
                                <a class="dropdown-item" href="theatre_view.php?id=<?php echo $theatre_id; ?>">
                                    <i class="fas fa-fw fa-eye mr-2"></i>View Details
                                </a>
                                <a class="dropdown-item" href="theatre_edit.php?id=<?php echo $theatre_id; ?>">
                                    <i class="fas fa-fw fa-edit mr-2"></i>Edit Theatre
                                </a>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item" href="ot_schedule.php?theatre=<?php echo $theatre_id; ?>">
                                    <i class="fas fa-fw fa-calendar-alt mr-2"></i>View Schedule
                                </a>
                                <a class="dropdown-item" href="surgery_new.php?theatre_id=<?php echo $theatre_id; ?>">
                                    <i class="fas fa-fw fa-plus mr-2"></i>Schedule Surgery
                                </a>
                                <div class="dropdown-divider"></div>
                                <?php if($status == 'available'): ?>
                                    <a class="dropdown-item text-warning confirm-action" href="post.php?set_theatre_maintenance=<?php echo $theatre_id; ?>" data-message="Set this theatre to maintenance mode?">
                                        <i class="fas fa-fw fa-tools mr-2"></i>Set Maintenance
                                    </a>
                                    <a class="dropdown-item text-info confirm-action" href="post.php?set_theatre_cleaning=<?php echo $theatre_id; ?>" data-message="Mark this theatre as cleaning?">
                                        <i class="fas fa-fw fa-broom mr-2"></i>Set Cleaning
                                    </a>
                                <?php elseif($status == 'maintenance' || $status == 'cleaning'): ?>
                                    <a class="dropdown-item text-success confirm-action" href="post.php?set_theatre_available=<?php echo $theatre_id; ?>" data-message="Set this theatre as available?">
                                        <i class="fas fa-fw fa-check mr-2"></i>Set Available
                                    </a>
                                <?php endif; ?>
                                <?php if($today_surgeries == 0 && $upcoming_surgeries == 0): ?>
                                    <div class="dropdown-divider"></div>
                                    <a class="dropdown-item text-danger confirm-action" href="post.php?archive_theatre=<?php echo $theatre_id; ?>" data-message="Are you sure you want to archive this theatre? This action cannot be undone.">
                                        <i class="fas fa-fw fa-archive mr-2"></i>Archive Theatre
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
                    <td colspan="8" class="text-center py-4">
                        <i class="fas fa-hospital-alt fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No Operation Theatres Found</h5>
                        <p class="text-muted">No theatres match your current filters.</p>
                        <a href="theatre_new.php" class="btn btn-primary mt-2">
                            <i class="fas fa-plus mr-2"></i>Add New Theatre
                        </a>
                    </td>
                </tr>
                <?php
            }
            ?>
            </tbody>
        </table>
    </div>
    
    <!-- Ends Card Body -->
    <?php require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/filter_footer.php'; ?>
</div>

<script>
$(document).ready(function() {
    $('.select2').select2();
    
    // Confirm action links
    $('.confirm-action').click(function(e) {
        if (!confirm($(this).data('message'))) {
            e.preventDefault();
        }
    });
});

// Keyboard shortcuts
$(document).keydown(function(e) {
    // Ctrl + N for new theatre
    if (e.ctrlKey && e.keyCode === 78) {
        e.preventDefault();
        window.location.href = 'theatre_new.php';
    }
    // Ctrl + F for focus search
    if (e.ctrlKey && e.keyCode === 70) {
        e.preventDefault();
        $('input[name="q"]').focus();
    }
});
</script>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>