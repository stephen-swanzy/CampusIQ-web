<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin(['super_admin','school_admin']);

$db = getDb();
$error = '';
$success = '';

// Handle Create Class Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_class') {
    verifyCsrfToken();

    $name = trim($_POST['name'] ?? '');
    $level = trim($_POST['level'] ?? '');
    $section = trim($_POST['section'] ?? '');
    $departmentId = !empty($_POST['department_id']) ? (int) $_POST['department_id'] : null;
    $classTeacherId = !empty($_POST['class_teacher_id']) ? (int) $_POST['class_teacher_id'] : null;
    $status = in_array($_POST['status'] ?? 'active', ['active', 'inactive']) ? $_POST['status'] : 'active';

    if ($name === '') {
        $error = 'Please enter a name for the class (e.g. JHS 1A, Grade 4B).';
    } else {
        // Check if class with same name already exists
        $check = $db->prepare('SELECT id FROM classes WHERE name = :name LIMIT 1');
        $check->execute(['name' => $name]);
        if ($check->fetch()) {
            $error = 'A class with this name already exists.';
        } else {
            $stmt = $db->prepare('INSERT INTO classes (name, level, section, department_id, class_teacher_id, status) VALUES (:name, :level, :section, :department_id, :class_teacher_id, :status)');
            $stmt->execute([
                'name' => $name,
                'level' => $level !== '' ? $level : null,
                'section' => $section !== '' ? $section : null,
                'department_id' => $departmentId,
                'class_teacher_id' => $classTeacherId,
                'status' => $status,
            ]);

            // Activity log
            $adminId = (int) ($_SESSION['campusiq_user']['id'] ?? 0);
            $db->prepare('INSERT INTO activity_logs (user_id, action, details) VALUES (:uid, "Class Created", :details)')
                ->execute([
                    'uid' => $adminId,
                    'details' => "Created class: {$name}",
                ]);

            $success = "Class '{$name}' created successfully!";
        }
    }
}

// Fetch departments for dropdown
$departments = $db->query('SELECT id, name FROM departments ORDER BY name ASC')->fetchAll();

// Fetch teachers for class teacher dropdown
$teachers = $db->query('SELECT u.id, u.name, t.teacher_id FROM teachers t JOIN users u ON u.id = t.user_id ORDER BY u.name ASC')->fetchAll();

// Query all classes
$stmt = $db->query('SELECT c.*, d.name as department_name, u.name as teacher_name FROM classes c LEFT JOIN departments d ON d.id = c.department_id LEFT JOIN users u ON u.id = c.class_teacher_id ORDER BY c.name ASC');
$classes = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <p class="text-primary fw-semibold mb-1">Academics</p>
        <h2 class="fw-bold mb-0">Class Management</h2>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addClassModal">
        <i class="fa-solid fa-plus me-2"></i>Create Class
    </button>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger d-flex align-items-center gap-2 mb-4">
        <i class="fa-solid fa-circle-exclamation fs-5"></i>
        <div><?php echo e($error); ?></div>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success d-flex align-items-center gap-2 mb-4">
        <i class="fa-solid fa-circle-check fs-5"></i>
        <div><?php echo e($success); ?></div>
    </div>
<?php endif; ?>

<div class="card p-4">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead>
                <tr>
                    <th>Class</th>
                    <th>Level</th>
                    <th>Department</th>
                    <th>Class Teacher</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($classes)): ?>
                    <tr>
                        <td colspan="5">
                            <div class="empty-state text-center py-4">
                                <i class="fa-solid fa-school fa-2x mb-3 text-muted d-block"></i>
                                No classes found. Click "Create Class" above to add one.
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($classes as $class): ?>
                        <tr>
                            <td><strong><?php echo e($class['name']); ?></strong></td>
                            <td><?php echo e($class['level'] ?? 'N/A'); ?></td>
                            <td><?php echo e($class['department_name'] ?? 'General'); ?></td>
                            <td>
                                <?php if (!empty($class['teacher_name'])): ?>
                                    <i class="fa-solid fa-user-tie me-1 text-primary"></i><?php echo e($class['teacher_name']); ?>
                                <?php else: ?>
                                    <span class="text-muted">Unassigned</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge-soft <?php echo $class['status'] === 'active' ? 'badge-success' : 'badge-warning'; ?>">
                                    <?php echo e(ucfirst($class['status'])); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Create Class Modal -->
<div class="modal fade" id="addClassModal" tabindex="-1" aria-labelledby="addClassModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow" style="border-radius: 16px;">
            <div class="modal-header border-0 pb-0">
                <div>
                    <h5 class="modal-title fw-bold" id="addClassModalLabel">Create New Class</h5>
                    <small class="text-muted">Add a classroom or stream to the academic registry</small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" class="p-3">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo csrfToken(); ?>">
                    <input type="hidden" name="action" value="create_class">

                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold">Class Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" required placeholder="e.g. JHS 2A, Primary 4B, SHS 2 Science">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Grade / Level</label>
                            <input type="text" class="form-control" name="level" placeholder="e.g. JHS 2, Primary 4">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Section / Stream</label>
                            <input type="text" class="form-control" name="section" placeholder="e.g. A, B, Science">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label fw-semibold">Department</label>
                            <select class="form-select" name="department_id">
                                <option value="">-- Select Department --</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo (int) $dept['id']; ?>"><?php echo e($dept['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label fw-semibold">Assign Class Teacher</label>
                            <select class="form-select" name="class_teacher_id">
                                <option value="">-- No Class Teacher Assigned --</option>
                                <?php foreach ($teachers as $tch): ?>
                                    <option value="<?php echo (int) $tch['id']; ?>">
                                        <?php echo e($tch['name']); ?> (<?php echo e($tch['teacher_id']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Status</label>
                            <select class="form-select" name="status">
                                <option value="active" selected>Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="fa-solid fa-floppy-disk me-1"></i> Create Class
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
