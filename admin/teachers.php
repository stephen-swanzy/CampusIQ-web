<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';

requireLogin(['super_admin','school_admin']);

$db = getDb();
$error = '';
$success = '';

// Handle Add Teacher Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_teacher') {
    verifyCsrfToken();

    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $phone = trim($_POST['phone'] ?? '');
    $teacherId = trim($_POST['teacher_id'] ?? '');
    $departmentId = !empty($_POST['department_id']) ? (int) $_POST['department_id'] : null;
    $designation = trim($_POST['designation'] ?? 'Teacher');
    $qualification = trim($_POST['qualification'] ?? '');
    $status = in_array($_POST['status'] ?? 'active', ['active', 'inactive']) ? $_POST['status'] : 'active';

    if ($name === '' || $email === '') {
        $error = 'Please enter both the teacher\'s name and email address.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        // Check if email already in use
        $checkUser = $db->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $checkUser->execute(['email' => $email]);
        if ($checkUser->fetch()) {
            $error = 'A user account with this email address already exists.';
        } else {
            // Check teacher_id if provided
            if ($teacherId !== '') {
                $checkTch = $db->prepare('SELECT id FROM teachers WHERE teacher_id = :tid LIMIT 1');
                $checkTch->execute(['tid' => $teacherId]);
                if ($checkTch->fetch()) {
                    $error = 'A teacher with this Teacher ID already exists.';
                }
            }

            if (!$error) {
                // If password empty, use standard default
                $plainPassword = $password !== '' ? $password : 'Teacher@123';
                $passwordHash = campusPasswordHash($plainPassword);

                // Insert into users
                $userStmt = $db->prepare('INSERT INTO users (name, email, password_hash, role, status, phone) VALUES (:name, :email, :password_hash, "teacher", :status, :phone)');
                $userStmt->execute([
                    'name' => $name,
                    'email' => $email,
                    'password_hash' => $passwordHash,
                    'status' => $status,
                    'phone' => $phone !== '' ? $phone : null,
                ]);
                $userId = (int) $db->lastInsertId();

                // Generate teacher_id if empty
                if ($teacherId === '') {
                    $teacherId = 'TCH-' . str_pad((string) $userId, 3, '0', STR_PAD_LEFT);
                }

                // Insert into teachers
                $tchStmt = $db->prepare('INSERT INTO teachers (user_id, teacher_id, department_id, designation, qualification, status) VALUES (:user_id, :teacher_id, :department_id, :designation, :qualification, :status)');
                $tchStmt->execute([
                    'user_id' => $userId,
                    'teacher_id' => $teacherId,
                    'department_id' => $departmentId,
                    'designation' => $designation !== '' ? $designation : null,
                    'qualification' => $qualification !== '' ? $qualification : null,
                    'status' => $status,
                ]);

                // Activity log
                $adminId = (int) ($_SESSION['campusiq_user']['id'] ?? 0);
                $db->prepare('INSERT INTO activity_logs (user_id, action, details) VALUES (:uid, "Teacher Added", :details)')
                    ->execute([
                        'uid' => $adminId,
                        'details' => "Added teacher: {$name} ({$teacherId})",
                    ]);

                $success = "Teacher '{$name}' ({$teacherId}) added successfully! Default password: '{$plainPassword}'.";
            }
        }
    }
}

// Fetch departments for dropdown
$departments = $db->query('SELECT id, name FROM departments ORDER BY name ASC')->fetchAll();

// Search & List Teachers
$search = trim($_GET['search'] ?? '');
$where = '';
$params = [];
if ($search !== '') {
    $where = 'WHERE u.name LIKE :search OR t.teacher_id LIKE :search2';
    $params = ['search' => '%' . $search . '%', 'search2' => '%' . $search . '%'];
}

$stmt = $db->prepare('SELECT t.*, u.name, u.email, u.phone, d.name as department_name FROM teachers t JOIN users u ON u.id = t.user_id LEFT JOIN departments d ON d.id = t.department_id ' . $where . ' ORDER BY u.name ASC');
$stmt->execute($params);
$teachers = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <p class="text-primary fw-semibold mb-1">Staff Management</p>
        <h2 class="fw-bold mb-0">Teacher Directory</h2>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTeacherModal">
        <i class="fa-solid fa-user-plus me-2"></i>Add Teacher
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

<div class="card p-4 mb-4">
    <form class="row g-3 align-items-end" method="GET">
        <div class="col-md-8">
            <label class="form-label">Search</label>
            <input type="text" class="form-control" name="search" value="<?php echo e($search); ?>" placeholder="Search by teacher name or teacher ID">
        </div>
        <div class="col-md-4">
            <button type="submit" class="btn btn-primary w-100"><i class="fa-solid fa-magnifying-glass me-2"></i>Search</button>
        </div>
    </form>
</div>

<div class="card p-4">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead>
                <tr>
                    <th>Teacher</th>
                    <th>Teacher ID</th>
                    <th>Department</th>
                    <th>Designation</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($teachers)): ?>
                    <tr>
                        <td colspan="7">
                            <div class="empty-state text-center py-4">
                                <i class="fa-solid fa-chalkboard-user fa-2x mb-3 text-muted d-block"></i>
                                No teachers found.
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($teachers as $teacher): ?>
                        <tr>
                            <td>
                                <strong><?php echo e($teacher['name']); ?></strong>
                                <?php if (!empty($teacher['qualification'])): ?>
                                    <br><small class="text-muted"><?php echo e($teacher['qualification']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge bg-light text-dark border"><?php echo e($teacher['teacher_id']); ?></span></td>
                            <td><?php echo e($teacher['department_name'] ?? 'General'); ?></td>
                            <td><?php echo e($teacher['designation'] ?? 'Teacher'); ?></td>
                            <td><?php echo e($teacher['email']); ?></td>
                            <td><?php echo e($teacher['phone'] ?? 'N/A'); ?></td>
                            <td>
                                <span class="badge-soft <?php echo $teacher['status'] === 'active' ? 'badge-success' : 'badge-warning'; ?>">
                                    <?php echo e(ucfirst($teacher['status'])); ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add Teacher Modal -->
<div class="modal fade" id="addTeacherModal" tabindex="-1" aria-labelledby="addTeacherModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow" style="border-radius: 16px;">
            <div class="modal-header border-0 pb-0">
                <div>
                    <h5 class="modal-title fw-bold" id="addTeacherModalLabel">Add New Teacher</h5>
                    <small class="text-muted">Register a new faculty or teaching staff member</small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" class="p-3">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo csrfToken(); ?>">
                    <input type="hidden" name="action" value="add_teacher">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" required placeholder="e.g. John Mensah">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Email Address <span class="text-danger">*</span></label>
                            <input type="email" class="form-control" name="email" required placeholder="john.mensah@school.edu">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Password</label>
                            <input type="password" class="form-control" name="password" placeholder="Default: Teacher@123">
                            <small class="text-muted">Leave blank to use default (Teacher@123)</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Phone Number</label>
                            <input type="text" class="form-control" name="phone" placeholder="024 000 0000">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Teacher ID (Optional)</label>
                            <input type="text" class="form-control" name="teacher_id" placeholder="Auto-generated if empty">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Department</label>
                            <select class="form-select" name="department_id">
                                <option value="">-- Select Department --</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo (int) $dept['id']; ?>"><?php echo e($dept['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Designation</label>
                            <input type="text" class="form-control" name="designation" placeholder="e.g. Senior Teacher, HOD">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Qualification</label>
                            <input type="text" class="form-control" name="qualification" placeholder="e.g. B.Ed Mathematics, M.Sc">
                        </div>
                        <div class="col-md-12">
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
                        <i class="fa-solid fa-floppy-disk me-1"></i> Save Teacher
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
