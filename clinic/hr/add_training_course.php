<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/inc_all.php';

// Get providers for dropdown
$providers_sql = "SELECT provider_id, provider_name FROM training_providers WHERE is_active = 1 ORDER BY provider_name";
$providers_result = $mysqli->query($providers_sql);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        // Start transaction
        $mysqli->begin_transaction();

        // Validate and sanitize inputs
        $course_name = trim($_POST['course_name']);
        $course_code = !empty(trim($_POST['course_code'] ?? '')) ? trim($_POST['course_code']) : null;
        $category = !empty(trim($_POST['category'] ?? '')) ? trim($_POST['category']) : null;
        $training_type = $_POST['training_type'];
        $duration_hours = !empty($_POST['duration_hours']) ? floatval($_POST['duration_hours']) : null;
        $learning_objectives = !empty(trim($_POST['learning_objectives'] ?? '')) ? trim($_POST['learning_objectives']) : null;
        $prerequisites = !empty(trim($_POST['prerequisites'] ?? '')) ? trim($_POST['prerequisites']) : null;
        $provider_id = !empty($_POST['provider_id']) ? intval($_POST['provider_id']) : null;
        $is_active = isset($_POST['is_active']) ? 1 : 1; // Default active

        // Validate required fields
        if (empty($course_name) || empty($training_type)) {
            throw new Exception("Course name and training type are required!");
        }

        // Validate course code uniqueness if provided
        if ($course_code) {
            $check_code_sql = "SELECT COUNT(*) as count FROM training_courses WHERE course_code = ?";
            $check_code_stmt = $mysqli->prepare($check_code_sql);
            $check_code_stmt->bind_param("s", $course_code);
            $check_code_stmt->execute();
            $duplicate_code_count = $check_code_stmt->get_result()->fetch_assoc()['count'];
            
            if ($duplicate_code_count > 0) {
                throw new Exception("A course with this code already exists!");
            }
        }

        // Validate duration
        if ($duration_hours && $duration_hours <= 0) {
            throw new Exception("Duration must be a positive number!");
        }

        // Generate course code if not provided
        if (!$course_code) {
            $course_code = generateCourseCode($course_name, $mysqli);
        }

        // Insert training course
        $course_sql = "INSERT INTO training_courses (
            course_name, course_code, category, training_type, duration_hours,
            learning_objectives, prerequisites, provider_id, is_active
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $course_stmt = $mysqli->prepare($course_sql);
        
        if (!$course_stmt) {
            throw new Exception("Prepare failed: " . $mysqli->error);
        }

        $course_stmt->bind_param("ssssdssii", 
            $course_name, $course_code, $category, $training_type, $duration_hours,
            $learning_objectives, $prerequisites, $provider_id, $is_active
        );
        
        if ($course_stmt->execute()) {
            $course_id = $mysqli->insert_id;

            // Log the action
            $audit_sql = "INSERT INTO hr_audit_log (user_id, action, description, table_name, record_id) VALUES (?, 'training_course_created', ?, 'training_courses', ?)";
            $audit_stmt = $mysqli->prepare($audit_sql);
            $description = "Created training course: " . $course_name . " (" . $course_code . ")";
            $audit_stmt->bind_param("isi", $_SESSION['user_id'], $description, $course_id);
            $audit_stmt->execute();

            // Commit transaction
            $mysqli->commit();

            $_SESSION['alert_type'] = "success";
            $_SESSION['alert_message'] = "Training course added successfully! Course Code: " . $course_code;
            header("Location: manage_training_courses.php");
            exit;
        } else {
            throw new Exception("Course creation failed: " . $course_stmt->error);
        }

    } catch (Exception $e) {
        $mysqli->rollback();
        $_SESSION['alert_type'] = "error";
        $_SESSION['alert_message'] = "Error adding training course: " . $e->getMessage();
        error_log("Training Course Add Error: " . $e->getMessage());
    }
}

// Function to generate unique course code
function generateCourseCode($courseName, $mysqli) {
    $prefix = 'TRG';
    $base_code = $prefix . '_' . strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $courseName), 0, 6));
    $code = $base_code;
    $counter = 1;
    
    // Check if code exists and find unique one
    while (true) {
        $check_sql = "SELECT COUNT(*) as count FROM training_courses WHERE course_code = ?";
        $check_stmt = $mysqli->prepare($check_sql);
        $check_stmt->bind_param("s", $code);
        $check_stmt->execute();
        $exists = $check_stmt->get_result()->fetch_assoc()['count'];
        
        if (!$exists) {
            break;
        }
        $code = $base_code . '_' . $counter;
        $counter++;
    }
    
    return $code;
}
?>

<div class="card">
    <div class="card-header bg-success py-2">
        <div class="d-flex justify-content-between align-items-center">
            <h3 class="card-title mt-2 mb-0 text-white">
                <i class="fas fa-fw fa-book mr-2"></i>Add Training Course
            </h3>
            <div class="card-tools">
                <a href="manage_training_courses.php" class="btn btn-light btn-sm">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Courses
                </a>
            </div>
        </div>
    </div>

    <div class="card-body">
        <?php if (isset($_SESSION['alert_message'])): ?>
            <div class="alert alert-<?php echo $_SESSION['alert_type']; ?> alert-dismissible fade show" role="alert">
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                <i class="fas fa-<?php echo $_SESSION['alert_type'] == 'success' ? 'check' : 'exclamation-triangle'; ?> mr-2"></i>
                <?php echo $_SESSION['alert_message']; ?>
            </div>
            <?php 
            unset($_SESSION['alert_type']);
            unset($_SESSION['alert_message']);
            ?>
        <?php endif; ?>

        <form method="POST" id="courseForm" autocomplete="off">
            <div class="row">
                <div class="col-md-6">
                    <!-- Course Information -->
                    <div class="card card-primary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-info-circle mr-2"></i>Course Information</h3>
                        </div>
                        <div class="card-body">
                            <div class="form-group mb-3">
                                <label>Course Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="course_name" required 
                                       maxlength="255" placeholder="Enter course name">
                                <div class="invalid-feedback">Please enter a course name.</div>
                            </div>

                            <div class="form-group mb-3">
                                <label>Course Code</label>
                                <input type="text" class="form-control" name="course_code" 
                                       maxlength="50" placeholder="Auto-generated if left blank">
                                <small class="form-text text-muted">Unique identifier for the course. Leave blank to auto-generate.</small>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group mb-3">
                                        <label>Category</label>
                                        <input type="text" class="form-control" name="category" 
                                               maxlength="100" placeholder="e.g., IT, Management, Safety">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group mb-3">
                                        <label>Training Type <span class="text-danger">*</span></label>
                                        <select class="form-control select2" name="training_type" required>
                                            <option value="">Select Type</option>
                                            <option value="technical">Technical</option>
                                            <option value="soft_skills">Soft Skills</option>
                                            <option value="compliance">Compliance</option>
                                            <option value="leadership">Leadership</option>
                                            <option value="mandatory">Mandatory</option>
                                        </select>
                                        <div class="invalid-feedback">Please select a training type.</div>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group mb-3">
                                <label>Duration (Hours)</label>
                                <input type="number" class="form-control" name="duration_hours" 
                                       step="0.5" min="0.5" max="500" placeholder="e.g., 8.0">
                                <small class="form-text text-muted">Total training hours. Use decimal for half hours (e.g., 3.5).</small>
                            </div>
                        </div>
                    </div>

                    <!-- Course Content -->
                    <div class="card card-info">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-graduation-cap mr-2"></i>Course Content</h3>
                        </div>
                        <div class="card-body">
                            <div class="form-group mb-3">
                                <label>Learning Objectives</label>
                                <textarea class="form-control" name="learning_objectives" rows="4" 
                                          placeholder="What participants will learn from this course..."></textarea>
                                <small class="form-text text-muted">Describe the key learning outcomes and objectives.</small>
                            </div>

                            <div class="form-group mb-0">
                                <label>Prerequisites</label>
                                <textarea class="form-control" name="prerequisites" rows="3" 
                                          placeholder="Required knowledge, skills, or experience..."></textarea>
                                <small class="form-text text-muted">Any requirements participants should meet before taking this course.</small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <!-- Quick Actions -->
                    <div class="card card-success">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-bolt mr-2"></i>Quick Actions</h3>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-info">
                                <small><i class="fas fa-info-circle mr-1"></i> Fields marked with <span class="text-danger">*</span> are required.</small>
                            </div>
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-success btn-lg">
                                    <i class="fas fa-save mr-2"></i>Add Course
                                </button>
                                <button type="reset" class="btn btn-outline-secondary">
                                    <i class="fas fa-undo mr-2"></i>Reset Form
                                </button>
                                <a href="manage_training_courses.php" class="btn btn-outline-danger">
                                    <i class="fas fa-times mr-2"></i>Cancel
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Provider & Settings -->
                    <div class="card card-warning">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-building mr-2"></i>Training Provider</h3>
                        </div>
                        <div class="card-body">
                            <div class="form-group mb-3">
                                <label>Training Provider</label>
                                <select class="form-control select2" name="provider_id">
                                    <option value="">Select Provider</option>
                                    <?php while($provider = $providers_result->fetch_assoc()): ?>
                                        <option value="<?php echo $provider['provider_id']; ?>">
                                            <?php echo htmlspecialchars($provider['provider_name']); ?>
                                        </option>
                                    <?php endwhile; ?>
                                </select>
                                <small class="form-text text-muted">Select the organization that provides this training.</small>
                            </div>

                            <div class="form-group mb-0">
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input" id="is_active" name="is_active" value="1" checked>
                                    <label class="custom-control-label" for="is_active">
                                        <strong>Active Course</strong>
                                    </label>
                                </div>
                                <small class="form-text text-muted">Active courses can be scheduled for training sessions.</small>
                            </div>
                        </div>
                    </div>

                    <!-- Training Type Guide -->
                    <div class="card card-secondary">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-question-circle mr-2"></i>Training Type Guide</h3>
                        </div>
                        <div class="card-body">
                            <div class="small">
                                <p class="mb-2"><strong>Technical:</strong> Job-specific skills (IT, engineering, etc.)</p>
                                <p class="mb-2"><strong>Soft Skills:</strong> Communication, teamwork, problem-solving</p>
                                <p class="mb-2"><strong>Compliance:</strong> Legal, safety, regulatory requirements</p>
                                <p class="mb-2"><strong>Leadership:</strong> Management, supervision, leadership skills</p>
                                <p class="mb-0"><strong>Mandatory:</strong> Required training for all employees</p>
                            </div>
                        </div>
                    </div>

                    <!-- Auto-Generated Preview -->
                    <div class="card card-info">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-eye mr-2"></i>Course Preview</h3>
                        </div>
                        <div class="card-body">
                            <div id="coursePreview" class="small text-muted">
                                <p class="mb-1">Course details will appear here as you fill the form.</p>
                                <p class="mb-0">Complete the form to see a preview of your course.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
$(document).ready(function() {
    // Initialize Select2
    $('.select2').select2();

    // Real-time course preview
    function updateCoursePreview() {
        const courseName = $('input[name="course_name"]').val();
        const courseCode = $('input[name="course_code"]').val();
        const category = $('input[name="category"]').val();
        const trainingType = $('select[name="training_type"]').val();
        const duration = $('input[name="duration_hours"]').val();
        
        let previewHtml = '';
        
        if (courseName) {
            previewHtml += `<p class="mb-1"><strong>${courseName}</strong></p>`;
        }
        
        if (courseCode) {
            previewHtml += `<p class="mb-1"><small>Code: ${courseCode}</small></p>`;
        }
        
        if (category) {
            previewHtml += `<p class="mb-1"><small>Category: ${category}</small></p>`;
        }
        
        if (trainingType) {
            const typeLabel = trainingType.charAt(0).toUpperCase() + trainingType.slice(1).replace('_', ' ');
            previewHtml += `<p class="mb-1"><small>Type: ${typeLabel}</small></p>`;
        }
        
        if (duration) {
            previewHtml += `<p class="mb-0"><small>Duration: ${duration} hours</small></p>`;
        }
        
        if (previewHtml) {
            $('#coursePreview').html(previewHtml);
        } else {
            $('#coursePreview').html('<p class="mb-1">Course details will appear here as you fill the form.</p><p class="mb-0">Complete the form to see a preview of your course.</p>');
        }
    }

    // Update preview on form changes
    $('#courseForm').on('input change', 'input, select, textarea', function() {
        updateCoursePreview();
    });

    // Form validation
    $('#courseForm').on('submit', function(e) {
        let isValid = true;
        
        // Validate required fields
        $(this).find('[required]').each(function() {
            if (!$(this).val()) {
                $(this).addClass('is-invalid');
                isValid = false;
            } else {
                $(this).removeClass('is-invalid');
            }
        });

        // Validate duration if provided
        const duration = $('input[name="duration_hours"]').val();
        if (duration && (duration <= 0 || duration > 500)) {
            $('input[name="duration_hours"]').addClass('is-invalid');
            isValid = false;
        }

        if (!isValid) {
            e.preventDefault();
            alert('Please fix the errors in the form before submitting.');
            return false;
        }

        // Show loading state
        $('button[type="submit"]').html('<i class="fas fa-spinner fa-spin mr-2"></i>Adding Course...').prop('disabled', true);
    });

    // Auto-generate course code suggestion
    $('input[name="course_name"]').on('blur', function() {
        if ($(this).val() && !$('input[name="course_code"]').val()) {
            const courseName = $(this).val();
            const baseCode = 'TRG_' + courseName.replace(/[^A-Za-z0-9]/g, '').substring(0, 6).toUpperCase();
            $('input[name="course_code"]').attr('placeholder', 'Suggested: ' + baseCode);
        }
    });
});
</script>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php';
?>