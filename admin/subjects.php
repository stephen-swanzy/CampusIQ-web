<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin(['super_admin','school_admin']);

$db = getDb();
$error = '';
$success = '';

// Handle Add Subject Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_subject') {
    verifyCsrfToken();

    $name = trim($_POST['name'] ?? '');
    $code = strtoupper(trim($_POST['code'] ?? ''));
    $departmentId = !empty($_POST['department_id']) ? (int) $_POST['department_id'] : null;
    $description = trim($_POST['description'] ?? '');
    $status = in_array($_POST['status'] ?? 'active', ['active', 'inactive']) ? $_POST['status'] : 'active';

    if ($name === '' || $code === '') {
        $error = 'Please enter both the subject name and course code.';
    } else {
        // Check if subject code already exists
        $check = $db->prepare('SELECT id FROM subjects WHERE code = :code LIMIT 1');
        $check->execute(['code' => $code]);
        if ($check->fetch()) {
            $error = 'A subject with this course code already exists.';
        } else {
            $stmt = $db->prepare('INSERT INTO subjects (name, code, department_id, description, status) VALUES (:name, :code, :department_id, :description, :status)');
            $stmt->execute([
                'name' => $name,
                'code' => $code,
                'department_id' => $departmentId,
                'description' => $description !== '' ? $description : null,
                'status' => $status,
            ]);

            // Activity log
            $adminId = (int) ($_SESSION['campusiq_user']['id'] ?? 0);
            $db->prepare('INSERT INTO activity_logs (user_id, action, details) VALUES (:uid, "Subject Added", :details)')
                ->execute([
                    'uid' => $adminId,
                    'details' => "Added subject: {$name} ({$code})",
                ]);

            $success = "Subject '{$name}' ({$code}) created successfully!";
        }
    }
}

// Fetch departments for dropdown
$departments = $db->query('SELECT id, name FROM departments ORDER BY name ASC')->fetchAll();

// List all subjects
$subjects = $db->query('SELECT s.*, d.name as department_name FROM subjects s LEFT JOIN departments d ON d.id = s.department_id ORDER BY s.name ASC')->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <p class="text-primary fw-semibold mb-1">Academics</p>
        <h2 class="fw-bold mb-0">Subject Management</h2>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addSubjectModal">
        <i class="fa-solid fa-plus me-2"></i>Add Subject
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
                    <th>Subject Name</th>
                    <th>Code</th>
                    <th>Department</th>
                    <th>Description</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($subjects)): ?>
                    <tr>
                        <td colspan="5">
                            <div class="empty-state text-center py-4">
                                <i class="fa-solid fa-book-open fa-2x mb-3 text-muted d-block"></i>
                                No subjects found. Click "Add Subject" above to register a course.
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($subjects as $subject): ?>
                        <tr>
                            <td><strong><?php echo e($subject['name']); ?></strong></td>
                            <td><span class="badge bg-light text-dark border"><?php echo e($subject['code']); ?></span></td>
                            <td><?php echo e($subject['department_name'] ?? 'General'); ?></td>
                            <td><?php echo e($subject['description'] ?? '-'); ?></td>
                            <td>
                                <span class="badge-soft <?php echo ($subject['status'] ?? 'active') === 'active' ? 'badge-success' : 'badge-warning'; ?>">
                                    <?php echo e(ucfirst($subject['status'] ?? 'active')); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add Subject Modal -->
<div class="modal fade" id="addSubjectModal" tabindex="-1" aria-labelledby="addSubjectModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow" style="border-radius: 16px;">
            <div class="modal-header border-0 pb-0">
                <div>
                    <h5 class="modal-title fw-bold" id="addSubjectModalLabel">Add New Subject</h5>
                    <small class="text-muted">Register a curriculum subject or course</small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" class="p-3">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo csrfToken(); ?>">
                    <input type="hidden" name="action" value="add_subject">

                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label fw-semibold">Subject Title <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" required placeholder="e.g. Core Mathematics, Social Studies">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Subject Code <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="code" required placeholder="e.g. MTH101, ENG201">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Department</label>
                            <select class="form-select" name="department_id">
                                <option value="">-- General / All Departments --</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo (int) $dept['id']; ?>"><?php echo e($dept['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Description (Optional)</label>
                            <textarea class="form-control" name="description" rows="3" placeholder="Brief outline of syllabus or curriculum coverage"></textarea>
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
                        <i class="fa-solid fa-floppy-disk me-1"></i> Save Subject
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
