<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';

requireLogin(['super_admin','school_admin']);

$db = getDb();
$error = '';
$success = '';

// Handle Add Student Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_student') {
    verifyCsrfToken();

    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $phone = trim($_POST['phone'] ?? '');
    $studentId = trim($_POST['student_id'] ?? '');
    $classId = !empty($_POST['class_id']) ? (int) $_POST['class_id'] : null;
    $parentId = !empty($_POST['parent_id']) ? (int) $_POST['parent_id'] : null;
    $gender = in_array($_POST['gender'] ?? '', ['Male', 'Female', 'Other']) ? $_POST['gender'] : 'Male';
    $dob = !empty($_POST['dob']) ? $_POST['dob'] : null;
    $address = trim($_POST['address'] ?? '');
    $admissionDate = !empty($_POST['admission_date']) ? $_POST['admission_date'] : date('Y-m-d');
    $status = in_array($_POST['status'] ?? 'active', ['active', 'inactive', 'graduated']) ? $_POST['status'] : 'active';

    if ($name === '' || $email === '') {
        $error = 'Please enter both the student\'s full name and email address.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        // Check if email already in use
        $checkUser = $db->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $checkUser->execute(['email' => $email]);
        if ($checkUser->fetch()) {
            $error = 'A user account with this email address already exists.';
        } else {
            // Check student_id if provided
            if ($studentId !== '') {
                $checkStu = $db->prepare('SELECT id FROM students WHERE student_id = :sid LIMIT 1');
                $checkStu->execute(['sid' => $studentId]);
                if ($checkStu->fetch()) {
                    $error = 'A student with this Student ID already exists.';
                }
            }

            if (!$error) {
                // If password empty, use standard default
                $plainPassword = $password !== '' ? $password : 'Student@123';
                $passwordHash = campusPasswordHash($plainPassword);

                // Insert into users
                $userStmt = $db->prepare('INSERT INTO users (name, email, password_hash, role, status, phone) VALUES (:name, :email, :password_hash, "student", :status, :phone)');
                $userStmt->execute([
                    'name' => $name,
                    'email' => $email,
                    'password_hash' => $passwordHash,
                    'status' => $status,
                    'phone' => $phone !== '' ? $phone : null,
                ]);
                $userId = (int) $db->lastInsertId();

                // Generate student_id if empty
                if ($studentId === '') {
                    $studentId = 'STU-' . str_pad((string) $userId, 3, '0', STR_PAD_LEFT);
                }

                // Insert into students
                $stuStmt = $db->prepare('INSERT INTO students (user_id, student_id, dob, gender, address, admission_date, class_id, parent_id, status) VALUES (:user_id, :student_id, :dob, :gender, :address, :admission_date, :class_id, :parent_id, :status)');
                $stuStmt->execute([
                    'user_id' => $userId,
                    'student_id' => $studentId,
                    'dob' => $dob,
                    'gender' => $gender,
                    'address' => $address !== '' ? $address : null,
                    'admission_date' => $admissionDate,
                    'class_id' => $classId,
                    'parent_id' => $parentId,
                    'status' => $status,
                ]);
                $newStudentPk = (int) $db->lastInsertId();

                // Create enrollment record if current academic year and class exist
                $currentYearId = getCurrentAcademicYearId();
                $currentTermId = getCurrentTermId();
                if ($currentYearId && $classId) {
                    $db->prepare('INSERT INTO enrollments (student_id, class_id, academic_year_id, term_id, status) VALUES (:student_id, :class_id, :year_id, :term_id, "active")')
                        ->execute([
                            'student_id' => $newStudentPk,
                            'class_id' => $classId,
                            'year_id' => $currentYearId,
                            'term_id' => $currentTermId,
                        ]);
                }

                // Activity log
                $adminId = (int) ($_SESSION['campusiq_user']['id'] ?? 0);
                $db->prepare('INSERT INTO activity_logs (user_id, action, details) VALUES (:uid, "Student Added", :details)')
                    ->execute([
                        'uid' => $adminId,
                        'details' => "Added student: {$name} ({$studentId})",
                    ]);

                $success = "Student '{$name}' ({$studentId}) added successfully! Default password: '{$plainPassword}'.";
            }
        }
    }
}

// Fetch classes for dropdown
$classes = $db->query('SELECT id, name FROM classes ORDER BY name ASC')->fetchAll();

// Fetch parents for dropdown
$parents = $db->query('SELECT p.id, u.name, u.phone FROM parents p JOIN users u ON u.id = p.user_id ORDER BY u.name ASC')->fetchAll();

// Search & List Students
$search = trim($_GET['search'] ?? '');
$where = '';
$params = [];
if ($search !== '') {
    $where = 'WHERE u.name LIKE :search OR s.student_id LIKE :search2 OR c.name LIKE :search3';
    $params = [
        'search' => '%' . $search . '%',
        'search2' => '%' . $search . '%',
        'search3' => '%' . $search . '%',
    ];
}

$stmt = $db->prepare('SELECT s.*, u.name, u.email, u.phone, c.name as class_name FROM students s JOIN users u ON u.id = s.user_id LEFT JOIN classes c ON c.id = s.class_id ' . $where . ' ORDER BY s.created_at DESC');
$stmt->execute($params);
$students = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <p class="text-primary fw-semibold mb-1">Students</p>
        <h2 class="fw-bold mb-0">Student Directory</h2>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addStudentModal">
        <i class="fa-solid fa-user-plus me-2"></i>Add Student
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
            <input type="text" class="form-control" name="search" value="<?php echo e($search); ?>" placeholder="Search by name, student ID, or class">
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
                    <th>Student</th>
                    <th>ID</th>
                    <th>Class</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($students)): ?>
                    <tr>
                        <td colspan="6">
                            <div class="empty-state text-center py-4">
                                <i class="fa-solid fa-user-slash fa-2x mb-3 text-muted d-block"></i>
                                No students found.
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($students as $student): ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-3">
                                    <div class="avatar-sm"><?php echo strtoupper(substr($student['name'], 0, 1)); ?></div>
                                    <div>
                                        <strong><?php echo e($student['name']); ?></strong><br>
                                        <small class="text-muted"><?php echo e($student['gender'] ?? 'N/A'); ?></small>
                                    </div>
                                </div>
                            </td>
                            <td><span class="badge bg-light text-dark border"><?php echo e($student['student_id']); ?></span></td>
                            <td><?php echo e($student['class_name'] ?? 'Unassigned'); ?></td>
                            <td><?php echo e($student['email']); ?></td>
                            <td><?php echo e($student['phone'] ?? 'N/A'); ?></td>
                            <td>
                                <span class="badge-soft <?php echo $student['status'] === 'active' ? 'badge-success' : 'badge-warning'; ?>"><?php echo e(ucfirst($student['status'])); ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add Student Modal -->
<div class="modal fade" id="addStudentModal" tabindex="-1" aria-labelledby="addStudentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow" style="border-radius: 16px;">
            <div class="modal-header border-0 pb-0">
                <div>
                    <h5 class="modal-title fw-bold" id="addStudentModalLabel">Add New Student</h5>
                    <small class="text-muted">Register a new student and create portal access</small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" class="p-3">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo csrfToken(); ?>">
                    <input type="hidden" name="action" value="add_student">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" required placeholder="e.g. Kwame Mensah">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Email Address <span class="text-danger">*</span></label>
                            <input type="email" class="form-control" name="email" required placeholder="kwame@student.campusiq.edu">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Password</label>
                            <input type="password" class="form-control" name="password" placeholder="Default: Student@123">
                            <small class="text-muted">Leave blank to use default (Student@123)</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Student ID (Optional)</label>
                            <input type="text" class="form-control" name="student_id" placeholder="Auto-generated if empty">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Class / Grade</label>
                            <select class="form-select" name="class_id">
                                <option value="">-- Unassigned --</option>
                                <?php foreach ($classes as $cls): ?>
                                    <option value="<?php echo (int) $cls['id']; ?>"><?php echo e($cls['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Gender</label>
                            <select class="form-select" name="gender">
                                <option value="Male" selected>Male</option>
                                <option value="Female">Female</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Date of Birth</label>
                            <input type="date" class="form-control" name="dob">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Admission Date</label>
                            <input type="date" class="form-control" name="admission_date" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Phone Number</label>
                            <input type="text" class="form-control" name="phone" placeholder="024 000 0000">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Link Parent / Guardian (Optional)</label>
                            <select class="form-select" name="parent_id">
                                <option value="">-- No Parent Linked --</option>
                                <?php foreach ($parents as $parent): ?>
                                    <option value="<?php echo (int) $parent['id']; ?>">
                                        <?php echo e($parent['name']); ?><?php echo !empty($parent['phone']) ? ' (' . e($parent['phone']) . ')' : ''; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label fw-semibold">Residential Address</label>
                            <input type="text" class="form-control" name="address" placeholder="e.g. Accra, Greater Accra">
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
                        <i class="fa-solid fa-floppy-disk me-1"></i> Save Student
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
