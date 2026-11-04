<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin(['teacher']);
$db = getDb();
$user = currentUser();

// Resolve teacher
$stmtTeacher = $db->prepare('SELECT id FROM teachers WHERE user_id = :uid LIMIT 1');
$stmtTeacher->execute(['uid' => $user['id']]);
$teacher = $stmtTeacher->fetch();
$teacherId = (int)($teacher['id'] ?? 0);

// Fetch classes taught by this teacher
$classesTaught = $db->query("
    SELECT DISTINCT c.id, c.name
    FROM classes c
    JOIN subject_teacher_assignments sta ON sta.class_id = c.id
    WHERE sta.teacher_id = $teacherId
    ORDER BY c.name ASC
")->fetchAll();

$filterClass = (int)($_GET['class_id'] ?? 0);

// Query students
$sql = "
    SELECT DISTINCT s.id, s.student_id as student_code, s.gender, u.name, u.email, c.name as class_name, c.id as class_id
    FROM students s
    JOIN users u ON u.id = s.user_id
    LEFT JOIN classes c ON c.id = s.class_id
    WHERE (
        s.class_id IN (SELECT class_id FROM subject_teacher_assignments WHERE teacher_id = :tid AND class_id IS NOT NULL)
        OR EXISTS (
            SELECT 1 FROM enrollments e 
            JOIN subject_teacher_assignments sta ON sta.class_id = e.class_id 
            WHERE e.student_id = s.id AND sta.teacher_id = :tid2
        )
    )
";
$params = ['tid' => $teacherId, 'tid2' => $teacherId];

if ($filterClass > 0) {
    $sql .= " AND s.class_id = :cid";
    $params['cid'] = $filterClass;
}

$sql .= " ORDER BY c.name ASC, u.name ASC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll();

// If no direct assignments assigned to teacher yet, fall back to showing all school active students so the teacher is never blocked
if (empty($students) && empty($classesTaught)) {
    $sqlFallback = "
        SELECT s.id, s.student_id as student_code, s.gender, u.name, u.email, c.name as class_name, c.id as class_id
        FROM students s
        JOIN users u ON u.id = s.user_id
        LEFT JOIN classes c ON c.id = s.class_id
        WHERE s.status = 'active'
    ";
    if ($filterClass > 0) {
        $sqlFallback .= " AND s.class_id = " . $filterClass;
    }
    $sqlFallback .= " ORDER BY c.name ASC, u.name ASC";
    $students = $db->query($sqlFallback)->fetchAll();
    $classesTaught = $db->query("SELECT id, name FROM classes ORDER BY name ASC")->fetchAll();
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-3">
    <div>
        <p class="text-primary fw-semibold mb-1"><i class="fa-solid fa-users me-2"></i>Classroom Roster</p>
        <h2 class="fw-bold mb-0">My Students</h2>
        <small class="text-muted">Directory of students enrolled in your allocated classes and subjects.</small>
    </div>
    <div>
        <span class="badge bg-primary fs-6 px-3 py-2">
            <i class="fa-solid fa-user-graduate me-1"></i><?php echo count($students); ?> Student(s) Enrolled
        </span>
    </div>
</div>

<!-- Class Filter Bar -->
<div class="card p-3 mb-4 border-0 shadow-sm">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-5">
            <label class="form-label small fw-bold text-muted mb-1">Filter by Class</label>
            <select name="class_id" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">All My Classes</option>
                <?php foreach ($classesTaught as $c): ?>
                    <option value="<?php echo $c['id']; ?>" <?php echo $filterClass === (int)$c['id'] ? 'selected' : ''; ?>>
                        <?php echo e($c['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <a href="students.php" class="btn btn-light btn-sm w-100"><i class="fa-solid fa-rotate-left me-1"></i>Reset</a>
        </div>
    </form>
</div>

<!-- Students Table -->
<div class="card p-0 border-0 shadow-sm overflow-hidden">
    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
        <h5 class="fw-bold mb-0 text-dark">Enrolled Student Directory</h5>
        <span class="badge bg-light text-secondary"><?php echo count($students); ?> Record(s)</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="min-width: 200px;">Full Name</th>
                    <th>Student ID</th>
                    <th>Class</th>
                    <th>Gender</th>
                    <th>Email Address</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($students)): ?>
                    <tr>
                        <td colspan="6" class="text-center py-5 text-muted">
                            <i class="fa-solid fa-users-slash fs-1 text-secondary opacity-50 mb-3 d-block"></i>
                            No students found for the selected criteria.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($students as $stu): ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="rounded-circle bg-primary bg-opacity-10 text-primary p-2 fs-6 d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                                        <i class="fa-solid fa-user-graduate"></i>
                                    </div>
                                    <div>
                                        <div class="fw-bold text-dark"><?php echo e($stu['name']); ?></div>
                                        <small class="text-muted">Student</small>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="badge bg-secondary bg-opacity-10 text-secondary border">
                                    <?php echo e($stu['student_code']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25">
                                    <i class="fa-solid fa-school me-1"></i><?php echo e($stu['class_name'] ?? 'Unassigned'); ?>
                                </span>
                            </td>
                            <td>
                                <span class="text-capitalize small"><?php echo e($stu['gender'] ?? 'N/A'); ?></span>
                            </td>
                            <td>
                                <span class="small text-muted"><i class="fa-regular fa-envelope me-1"></i><?php echo e($stu['email']); ?></span>
                            </td>
                            <td class="text-end">
                                <a href="results.php?class_id=<?php echo $stu['class_id']; ?>" class="btn btn-sm btn-outline-primary" title="Record Grade">
                                    <i class="fa-solid fa-award me-1"></i>Grades
                                </a>
                                <a href="attendance.php?class_id=<?php echo $stu['class_id']; ?>" class="btn btn-sm btn-outline-secondary" title="Attendance">
                                    <i class="fa-solid fa-clipboard-check me-1"></i>Attendance
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
