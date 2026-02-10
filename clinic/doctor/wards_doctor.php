<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';



// Handle ward selection
$selected_ward_id = $_POST['ward_id'] ?? $_SESSION['selected_ward_id_doctor'] ?? null;

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['ward_id'])) {
    $_SESSION['selected_ward_id_doctor'] = intval($_POST['ward_id']);
    $selected_ward_id = $_SESSION['selected_ward_id_doctor'];
}

// Get all active wards
$wards = $mysqli->query("
    SELECT w.*,
           (SELECT COUNT(*) FROM beds b WHERE b.bed_ward_id = w.ward_id) as total_beds,
           (SELECT COUNT(*) FROM beds b WHERE b.bed_ward_id = w.ward_id AND b.bed_status = 'occupied') as occupied_beds,
           (SELECT COUNT(*) FROM beds b WHERE b.bed_ward_id = w.ward_id AND b.bed_status = 'available') as available_beds,
           (SELECT COUNT(*) FROM beds b WHERE b.bed_ward_id = w.ward_id AND b.bed_status = 'maintenance') as maintenance_beds
    FROM wards w 
    WHERE w.ward_is_active = 1 
    ORDER BY w.ward_name
");

// Get selected ward details and beds if a ward is selected
$selected_ward = null;
$beds = null;
$beds_count = 0;

if ($selected_ward_id) {
    $ward_stmt = $mysqli->prepare("SELECT * FROM wards WHERE ward_id = ?");
    $ward_stmt->bind_param("i", $selected_ward_id);
    $ward_stmt->execute();
    $selected_ward = $ward_stmt->get_result()->fetch_assoc();
    $ward_stmt->close();
    
    if ($selected_ward) {
        $beds_stmt = $mysqli->query("
            SELECT b.*, 
                   p.patient_first_name, 
                   p.patient_last_name,
                   p.patient_id,
                   p.patient_dob,
                   p.patient_gender         
                  
            FROM beds b 
            LEFT JOIN patients p ON b.bed_assigned_to = p.patient_id 
            WHERE b.bed_ward_id = $selected_ward_id 
            ORDER BY b.bed_number
        ");
        $beds_count = $beds_stmt->num_rows;
    }
}

// Get statistics
$total_wards = $wards->num_rows;
$total_beds_all = $mysqli->query("SELECT COUNT(*) FROM beds")->fetch_row()[0];
$occupied_beds_all = $mysqli->query("SELECT COUNT(*) FROM beds WHERE bed_status = 'occupied'")->fetch_row()[0];
$available_beds_all = $mysqli->query("SELECT COUNT(*) FROM beds WHERE bed_status = 'available'")->fetch_row()[0];
$maintenance_beds_all = $mysqli->query("SELECT COUNT(*) FROM beds WHERE bed_status = 'maintenance'")->fetch_row()[0];
?>

<div class="card">
    <div class="card-header bg-info py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0">
                <i class="fas fa-fw fa-user-md mr-2"></i>
                Ward Overview - Doctor Rounds
                <small class="ml-2 badge badge-light">Doctor View</small>
            </h3>
        </div>
    </div>

    <div class="card-body">
        <!-- Statistics Bar -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card bg-info text-white">
                    <div class="card-body text-center py-3">
                        <h4 class="mb-0"><?php echo $total_wards; ?></h4>
                        <small>Wards</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-success text-white">
                    <div class="card-body text-center py-3">
                        <h4 class="mb-0"><?php echo $available_beds_all; ?></h4>
                        <small>Available Beds</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-warning text-white">
                    <div class="card-body text-center py-3">
                        <h4 class="mb-0"><?php echo $occupied_beds_all; ?></h4>
                        <small>Occupied Beds</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-danger text-white">
                    <div class="card-body text-center py-3">
                        <h4 class="mb-0"><?php echo $maintenance_beds_all; ?></h4>
                        <small>Maintenance</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Ward Selection -->
        <div class="card mb-4">
            <div class="card-header bg-light">
                <h5 class="card-title mb-0">
                    <i class="fas fa-hospital mr-2"></i>Select Ward to View Beds
                </h5>
            </div>
            <div class="card-body">
                <form method="POST" class="row align-items-end">
                    <div class="col-md-8">
                        <div class="form-group mb-0">
                            <label>Choose Ward:</label>
                            <select class="form-control" name="ward_id" onchange="this.form.submit()">
                                <option value="">-- View All Wards --</option>
                                <?php 
                                $wards->data_seek(0);
                                while($ward = $wards->fetch_assoc()): 
                                    $is_selected = $selected_ward_id == $ward['ward_id'];
                                ?>
                                    <option value="<?php echo $ward['ward_id']; ?>" <?php echo $is_selected ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($ward['ward_name']); ?> 
                                        (<?php echo $ward['ward_type']; ?>)
                                        - <?php echo $ward['available_beds']; ?>/<?php echo $ward['total_beds']; ?> available
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group mb-0">
                            <?php if ($selected_ward_id): ?>
                                <button type="submit" name="ward_id" value="" class="btn btn-outline-secondary btn-block">
                                    <i class="fas fa-times mr-1"></i>Clear Selection
                                </button>
                            <?php else: ?>
                                <button type="submit" class="btn btn-secondary btn-block" disabled>
                                    Select a Ward
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($selected_ward_id && $selected_ward): ?>
            <!-- Selected Ward Beds -->
            <div class="card mb-4">
                <div class="card-header bg-light">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-bed mr-2"></i>
                        Beds in <?php echo htmlspecialchars($selected_ward['ward_name']); ?>
                        <span class="badge badge-info ml-2"><?php echo $beds_count; ?> beds</span>
                    </h5>
                </div>
                <div class="card-body">
                    <!-- Ward Info -->
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="card bg-light">
                                <div class="card-body py-3">
                                    <h6 class="card-title">Ward Information</h6>
                                    <p class="mb-1"><strong>Name:</strong> <?php echo htmlspecialchars($selected_ward['ward_name']); ?></p>
                                    <p class="mb-1"><strong>Type:</strong> 
                                        <span class="badge badge-<?php echo getWardTypeBadgeColor($selected_ward['ward_type']); ?>">
                                            <?php echo htmlspecialchars($selected_ward['ward_type']); ?>
                                        </span>
                                    </p>
                                    <?php if (!empty($selected_ward['ward_description'])): ?>
                                        <p class="mb-0"><strong>Description:</strong> <?php echo htmlspecialchars($selected_ward['ward_description']); ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card bg-light">
                                <div class="card-body py-3">
                                    <h6 class="card-title">Bed Statistics</h6>
                                    <div class="row text-center">
                                        <div class="col-4">
                                            <div class="h5 mb-0 text-primary"><?php echo $beds_count; ?></div>
                                            <small class="text-muted">Total</small>
                                        </div>
                                        <div class="col-4">
                                            <div class="h5 mb-0 text-success">
                                                <?php echo $mysqli->query("SELECT COUNT(*) FROM beds WHERE bed_ward_id = $selected_ward_id AND bed_status = 'available'")->fetch_row()[0]; ?>
                                            </div>
                                            <small class="text-muted">Available</small>
                                        </div>
                                        <div class="col-4">
                                            <div class="h5 mb-0 text-warning">
                                                <?php echo $mysqli->query("SELECT COUNT(*) FROM beds WHERE bed_ward_id = $selected_ward_id AND bed_status = 'occupied'")->fetch_row()[0]; ?>
                                            </div>
                                            <small class="text-muted">Occupied</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Beds Grid -->
                    <?php if ($beds_count > 0): ?>
                    <div class="row">
                        <?php while($bed = $beds_stmt->fetch_assoc()): 
                            $bed_status = $bed['bed_status'] ?? 'available';
                            $status_class = getBedStatusClass($bed_status);
                            $patient_name = 'Unoccupied';
                            $patient_info = '';
                            $medical_info = '';
                            
                            if (!empty($bed['patient_first_name']) && !empty($bed['patient_last_name'])) {
                                $patient_name = htmlspecialchars($bed['patient_first_name'] . ' ' . $bed['patient_last_name']);
                                $patient_info = calculateAge($bed['patient_dob']) . ' • ' . $bed['patient_gender'];
                                
                                // Medical information for doctors
                                $medical_info = [];
                                if (!empty($bed['patient_blood_group'])) {
                                    $medical_info[] = 'Blood: ' . $bed['patient_blood_group'];
                                }
                                if (!empty($bed['patient_allergies'])) {
                                    $medical_info[] = 'Allergies';
                                }
                                if (!empty($bed['admission_reason'])) {
                                    $medical_info[] = 'Admitted: ' . date('M j', strtotime($bed['admission_date']));
                                }
                            }
                        ?>
                        <div class="col-xl-3 col-lg-4 col-md-6 mb-3">
                            <div class="card bed-card border-<?php echo $status_class; ?>">
                                <div class="card-header py-2 bg-<?php echo $status_class; ?> text-white">
                                    <strong>Bed <?php echo htmlspecialchars($bed['bed_number']); ?></strong>
                                </div>
                                <div class="card-body p-3">
                                    <div class="text-center mb-2">
                                        <i class="fas fa-bed fa-2x text-<?php echo $status_class; ?>"></i>
                                    </div>
                                    <div class="text-center">
                                        <span class="badge badge-<?php echo $status_class; ?> mb-2">
                                            <?php echo ucfirst($bed_status); ?>
                                        </span>
                                        <p class="mb-1"><strong>Type:</strong> <?php echo htmlspecialchars($bed['bed_type'] ?? 'Regular'); ?></p>
                                        <p class="mb-1"><strong>Patient:</strong> <?php echo $patient_name; ?></p>
                                        
                                        <?php if ($patient_info): ?>
                                            <p class="mb-1"><small class="text-muted"><?php echo $patient_info; ?></small></p>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($medical_info)): ?>
                                            <div class="mb-2">
                                                <?php foreach($medical_info as $info): ?>
                                                    <span class="badge badge-light border mr-1 mb-1"><?php echo $info; ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($bed['admission_reason'])): ?>
                                            <p class="mb-1">
                                                <small class="text-muted">
                                                    <i class="fas fa-clipboard-list mr-1"></i>
                                                    <?php echo htmlspecialchars(substr($bed['admission_reason'], 0, 30)); ?>...
                                                </small>
                                            </p>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($bed['doctor_first_name'])): ?>
                                            <p class="mb-1">
                                                <small class="text-info">
                                                    <i class="fas fa-user-md mr-1"></i>
                                                    Dr. <?php echo htmlspecialchars($bed['doctor_first_name'] . ' ' . $bed['doctor_last_name']); ?>
                                                </small>
                                            </p>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($bed['bed_notes'])): ?>
                                            <p class="mb-1">
                                                <small class="text-muted">
                                                    <i class="fas fa-sticky-note mr-1"></i>
                                                    <?php echo htmlspecialchars(substr($bed['bed_notes'], 0, 30)); ?>...
                                                </small>
                                            </p>
                                        <?php endif; ?>
                                        
                                        <?php if ($bed_status == 'occupied'): ?>
                                            <div class="mt-2">
                                                <a href="patient_chart.php?patient_id=<?php echo $bed['patient_id']; ?>" class="btn btn-sm btn-outline-info btn-block">
                                                    <i class="fas fa-file-medical mr-1"></i>View Chart
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-4">
                        <i class="fas fa-bed fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No Beds Found</h5>
                        <p class="text-muted">This ward doesn't have any beds.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <!-- All Wards Overview -->
            <div class="card">
                <div class="card-header bg-light">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-list mr-2"></i>All Wards Overview
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <?php 
                        $wards->data_seek(0);
                        while($ward = $wards->fetch_assoc()): 
                            $occupancy_rate = $ward['total_beds'] > 0 ? round(($ward['occupied_beds'] / $ward['total_beds']) * 100) : 0;
                        ?>
                        <div class="col-md-6 col-lg-4 mb-3">
                            <div class="card ward-overview-card">
                                <div class="card-body">
                                    <h6 class="card-title"><?php echo htmlspecialchars($ward['ward_name']); ?></h6>
                                    <span class="badge badge-<?php echo getWardTypeBadgeColor($ward['ward_type']); ?>">
                                        <?php echo $ward['ward_type']; ?>
                                    </span>
                                    <div class="mt-2">
                                        <small class="text-muted">
                                            <?php echo $ward['total_beds']; ?> total beds • 
                                            <?php echo $ward['available_beds']; ?> available •
                                            <?php echo $ward['occupied_beds']; ?> patients
                                        </small>
                                    </div>
                                    <div class="progress mt-2" style="height: 8px;">
                                        <div class="progress-bar bg-success" style="width: <?php echo 100 - $occupancy_rate; ?>%"></div>
                                        <div class="progress-bar bg-warning" style="width: <?php echo $occupancy_rate; ?>%"></div>
                                    </div>
                                    <div class="text-center mt-2">
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="ward_id" value="<?php echo $ward['ward_id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-info">
                                                <i class="fas fa-user-md mr-1"></i>View Patients
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endwhile; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
.ward-overview-card {
    transition: transform 0.2s;
    border: 1px solid #dee2e6;
    height: 100%;
}
.ward-overview-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}
.bed-card {
    transition: transform 0.2s;
    height: 100%;
}
.bed-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}
</style>

<?php
// Helper functions
function getBedStatusClass($status) {
    switch ($status) {
        case 'available': return 'success';
        case 'occupied': return 'warning';
        case 'maintenance': return 'danger';
        case 'reserved': return 'info';
        default: return 'secondary';
    }
}

function getWardTypeBadgeColor($ward_type) {
    switch ($ward_type) {
        case 'ICU': return 'danger';
        case 'CCU': return 'danger';
        case 'General': return 'primary';
        case 'Pediatric': return 'info';
        case 'Maternity': return 'success';
        case 'Surgical': return 'warning';
        case 'Psychiatric': return 'secondary';
        default: return 'secondary';
    }
}

function calculateAge($dob) {
    if (empty($dob)) return 'N/A';
    $birthDate = new DateTime($dob);
    $today = new DateTime();
    $age = $today->diff($birthDate);
    return $age->y . ' years';
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>