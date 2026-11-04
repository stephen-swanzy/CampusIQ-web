<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin(['teacher']);
$db = getDb();
$user = currentUser();
$error = '';
$success = '';

// Get teacher ID
$tchStmt = $db->prepare('SELECT id FROM teachers WHERE user_id = :uid LIMIT 1');
$tchStmt->execute(['uid' => $user['id']]);
$teacherProfile = $tchStmt->fetch();
$teacherId = $teacherProfile['id'] ?? 0;

// Fetch classes assigned to this teacher (or all classes if not specifically mapped)
$classesStmt = $db->prepare('SELECT DISTINCT c.* FROM classes c 
    LEFT JOIN subject_teacher_assignments sta ON sta.class_id = c.id 
    WHERE c.class_teacher_id = :uid OR sta.teacher_id = :tid 
    ORDER BY c.name ASC');
$classesStmt->execute(['uid' => $user['id'], 'tid' => $teacherId]);
$classes = $classesStmt->fetchAll();

if (empty($classes)) {
    // Fallback to all active classes
    $classes = $db->query('SELECT * FROM classes WHERE status = "active" ORDER BY name ASC')->fetchAll();
}

$selectedClassId = (int) ($_GET['class_id'] ?? $_POST['class_id'] ?? ($classes[0]['id'] ?? 0));
$selectedDate = trim($_GET['date'] ?? $_POST['date'] ?? date('Y-m-d'));

// Handle Attendance Save POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_attendance') {
    verifyCsrfToken();
    $classId = (int) ($_POST['class_id'] ?? 0);
    $date = trim($_POST['date'] ?? date('Y-m-d'));

    if ($classId <= 0) {
        $error = 'Please select a valid class.';
    } else {
        // Fetch all students in this class
        $studentsStmt = $db->prepare('SELECT s.id, u.name FROM students s JOIN users u ON u.id = s.user_id WHERE s.class_id = :class_id AND s.status = "active" ORDER BY u.name ASC');
        $studentsStmt->execute(['class_id' => $classId]);
        $studentList = $studentsStmt->fetchAll();

        if (empty($studentList)) {
            $error = 'No active students found in the selected class.';
        } else {
            $count = 0;
            foreach ($studentList as $student) {
                $statusKey = 'status_' . $student['id'];
                $remarkKey = 'remarks_' . $student['id'];

                $status = in_array($_POST[$statusKey] ?? '', ['Present', 'Absent', 'Late', 'Excused'])
                    ? $_POST[$statusKey]
                    : 'Present';
                $remarks = trim($_POST[$remarkKey] ?? '');

                // Check existing record
                $checkStmt = $db->prepare('SELECT id FROM attendance WHERE student_id = :sid AND class_id = :cid AND date = :date LIMIT 1');
                $checkStmt->execute(['sid' => $student['id'], 'cid' => $classId, 'date' => $date]);
                $existing = $checkStmt->fetch();

                if ($existing) {
                    $updateStmt = $db->prepare('UPDATE attendance SET status = :status, remarks = :remarks, recorded_by = :uid WHERE id = :id');
                    $updateStmt->execute([
                        'status' => $status,
                        'remarks' => $remarks !== '' ? $remarks : null,
                        'uid' => $user['id'],
                        'id' => $existing['id'],
                    ]);
                } else {
                    $insertStmt = $db->prepare('INSERT INTO attendance (student_id, class_id, date, status, remarks, recorded_by) VALUES (:sid, :cid, :date, :status, :remarks, :uid)');
                    $insertStmt->execute([
                        'sid' => $student['id'],
                        'cid' => $classId,
                        'date' => $date,
                        'status' => $status,
                        'remarks' => $remarks !== '' ? $remarks : null,
                        'uid' => $user['id'],
                    ]);
                }
                $count++;
            }
            $success = "Attendance successfully saved for {$count} student(s) on " . date('d M Y', strtotime($date)) . "!";
        }
    }
}

// Fetch students for the currently selected class
$studentsInClass = [];
$existingAttendance = [];
if ($selectedClassId > 0) {
    $stuQ = $db->prepare('SELECT s.id, s.student_id as student_code, u.name, u.photo 
        FROM students s 
        JOIN users u ON u.id = s.user_id 
        WHERE s.class_id = :cid AND s.status = "active" 
        ORDER BY u.name ASC');
    $stuQ->execute(['cid' => $selectedClassId]);
    $studentsInClass = $stuQ->fetchAll();

    // Fetch existing attendance for this date
    $attQ = $db->prepare('SELECT student_id, status, remarks FROM attendance WHERE class_id = :cid AND date = :dt');
    $attQ->execute(['cid' => $selectedClassId, 'dt' => $selectedDate]);
    while ($row = $attQ->fetch()) {
        $existingAttendance[$row['student_id']] = $row;
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <p class="text-primary fw-semibold mb-1">Classroom Management</p>
        <h2 class="fw-bold mb-0">Daily Class Attendance</h2>
    </div>
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

<!-- Class & Date Selector Filter -->
<div class="card p-4 mb-4">
    <form method="GET" class="row g-3 align-items-end">
        <div class="col-md-5">
            <label class="form-label fw-semibold">Select Class</label>
            <select class="form-select" name="class_id" onchange="this.form.submit()">
                <?php if (empty($classes)): ?>
                    <option value="">No classes available</option>
                <?php else: ?>
                    <?php foreach ($classes as $c): ?>
                        <option value="<?php echo (int) $c['id']; ?>" <?php echo $selectedClassId === (int) $c['id'] ? 'selected' : ''; ?>>
                            <?php echo e($c['name']); ?><?php echo !empty($c['level']) ? ' (' . e($c['level']) . ')' : ''; ?>
                        </option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
        </div>
        <div class="col-md-5">
            <label class="form-label fw-semibold">Date</label>
            <input type="date" class="form-control" name="date" value="<?php echo e($selectedDate); ?>" onchange="this.form.submit()">
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-outline-primary w-100">
                <i class="fa-solid fa-filter me-1"></i> Filter
            </button>
        </div>
    </form>
</div>

<!-- Attendance Form & Student Roster -->
<div class="card p-4">
    <?php if (empty($studentsInClass)): ?>
        <div class="empty-state text-center py-5">
            <i class="fa-solid fa-user-graduate fa-2x mb-3 text-muted d-block"></i>
            <h5>No Students in this Class</h5>
            <p class="text-muted">There are no active students enrolled in the selected class yet.</p>
        </div>
    <?php else: ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo csrfToken(); ?>">
            <input type="hidden" name="action" value="save_attendance">
            <input type="hidden" name="class_id" value="<?php echo (int) $selectedClassId; ?>">
            <input type="hidden" name="date" value="<?php echo e($selectedDate); ?>">

            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                <div>
                    <h5 class="fw-bold mb-0">Class Roster (<?php echo count($studentsInClass); ?> Students)</h5>
                    <small class="text-muted">Date: <?php echo date('l, d F Y', strtotime($selectedDate)); ?></small>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-outline-success" onclick="markAllStatus('Present')">
                        <i class="fa-solid fa-check-double me-1"></i> Mark All Present
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="markAllStatus('Absent')">
                        <i class="fa-solid fa-xmark me-1"></i> Mark All Absent
                    </button>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table align-middle">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 50px;">#</th>
                            <th>Student</th>
                            <th>Student ID</th>
                            <th style="width: 200px;">Attendance Status</th>
                            <th>Teacher Remark (Optional)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($studentsInClass as $idx => $stu): 
                            $saved = $existingAttendance[$stu['id']] ?? null;
                            $curStatus = $saved['status'] ?? 'Present';
                            $curRemark = $saved['remarks'] ?? '';
                        ?>
                            <tr>
                                <td><?php echo $idx + 1; ?></td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="avatar-sm bg-primary text-white d-flex align-items-center justify-content-center rounded-circle" style="width: 32px; height: 32px; font-size: 0.85rem;">
                                            <?php echo strtoupper(substr($stu['name'], 0, 1)); ?>
                                        </div>
                                        <span class="fw-semibold"><?php echo e($stu['name']); ?></span>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border"><?php echo e($stu['student_code']); ?></span>
                                </td>
                                <td>
                                    <select class="form-select attendance-status-select" name="status_<?php echo (int) $stu['id']; ?>">
                                        <option value="Present" <?php echo $curStatus === 'Present' ? 'selected' : ''; ?>>🟢 Present</option>
                                        <option value="Late" <?php echo $curStatus === 'Late' ? 'selected' : ''; ?>>🟡 Late</option>
                                        <option value="Absent" <?php echo $curStatus === 'Absent' ? 'selected' : ''; ?>>🔴 Absent</option>
                                        <option value="Excused" <?php echo $curStatus === 'Excused' ? 'selected' : ''; ?>>🔵 Excused</option>
                                    </select>
                                </td>
                                <td>
                                    <input type="text" class="form-control" name="remarks_<?php echo (int) $stu['id']; ?>" value="<?php echo e($curRemark); ?>" placeholder="e.g. Doctor appointment, arrived 8:30 AM">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="d-flex justify-content-end mt-4 pt-2 border-top">
                <button type="submit" class="btn btn-primary px-4 py-2">
                    <i class="fa-solid fa-floppy-disk me-2"></i> Save Class Attendance
                </button>
            </div>
        </form>
    <?php endif; ?>
</div>

<script>
function markAllStatus(statusVal) {
    const selects = document.querySelectorAll('.attendance-status-select');
    selects.forEach(s => {
        s.value = statusVal;
    });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
