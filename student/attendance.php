<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin(['student']);
$db = getDb();
$user = currentUser();

$stmtStudent = $db->prepare('SELECT s.*, c.name as class_name FROM students s LEFT JOIN classes c ON c.id = s.class_id WHERE s.user_id = :uid LIMIT 1');
$stmtStudent->execute(['uid' => $user['id']]);
$student = $stmtStudent->fetch();
$studentId = (int)($student['id'] ?? 0);

$records = [];
$totalDays = 0;
$presentDays = 0;
$lateDays = 0;
$absentDays = 0;

if ($studentId > 0) {
    $stmtAtt = $db->prepare('SELECT * FROM attendance WHERE student_id = :sid ORDER BY date DESC');
    $stmtAtt->execute(['sid' => $studentId]);
    $records = $stmtAtt->fetchAll();

    $totalDays = count($records);
    foreach ($records as $r) {
        if ($r['status'] === 'Present') $presentDays++;
        elseif ($r['status'] === 'Late') $lateDays++;
        elseif ($r['status'] === 'Absent') $absentDays++;
    }
}

$attendanceRate = ($totalDays > 0) ? round((($presentDays + $lateDays) / $totalDays) * 100, 1) : 100.0;

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-3">
    <div>
        <p class="text-primary fw-semibold mb-1"><i class="fa-solid fa-calendar-check me-2"></i>Student Portal</p>
        <h2 class="fw-bold mb-0">My Attendance Record</h2>
        <small class="text-muted">Review your official classroom attendance log, presence rates, and remarks.</small>
    </div>
    <div>
        <span class="badge bg-primary fs-6 px-3 py-2">
            <i class="fa-solid fa-school me-1"></i>Class: <?php echo e($student['class_name'] ?? 'Not Enrolled'); ?>
        </span>
    </div>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card p-3 border-0 shadow-sm d-flex flex-row align-items-center gap-3">
            <div class="rounded-3 p-3 bg-primary bg-opacity-10 text-primary fs-3">
                <i class="fa-solid fa-calendar-days"></i>
            </div>
            <div>
                <span class="text-muted small fw-semibold">Total Sessions</span>
                <h4 class="fw-bold mb-0 text-dark"><?php echo $totalDays; ?></h4>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card p-3 border-0 shadow-sm d-flex flex-row align-items-center gap-3">
            <div class="rounded-3 p-3 bg-success bg-opacity-10 text-success fs-3">
                <i class="fa-solid fa-check"></i>
            </div>
            <div>
                <span class="text-muted small fw-semibold">Days Present</span>
                <h4 class="fw-bold mb-0 text-dark"><?php echo $presentDays; ?></h4>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card p-3 border-0 shadow-sm d-flex flex-row align-items-center gap-3">
            <div class="rounded-3 p-3 bg-warning bg-opacity-10 text-warning fs-3">
                <i class="fa-solid fa-clock"></i>
            </div>
            <div>
                <span class="text-muted small fw-semibold">Times Late</span>
                <h4 class="fw-bold mb-0 text-dark"><?php echo $lateDays; ?></h4>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card p-3 border-0 shadow-sm d-flex flex-row align-items-center gap-3">
            <div class="rounded-3 p-3 bg-info bg-opacity-10 text-info fs-3">
                <i class="fa-solid fa-percent"></i>
            </div>
            <div>
                <span class="text-muted small fw-semibold">Attendance Rate</span>
                <h4 class="fw-bold mb-0 text-dark"><?php echo $attendanceRate; ?>%</h4>
            </div>
        </div>
    </div>
</div>

<!-- Attendance Log Table -->
<div class="card p-0 border-0 shadow-sm overflow-hidden">
    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
        <h5 class="fw-bold mb-0 text-dark">Session History</h5>
        <span class="badge bg-light text-secondary"><?php echo count($records); ?> Recorded Day(s)</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Date</th>
                    <th>Day</th>
                    <th>Status</th>
                    <th>Remarks</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($records)): ?>
                    <tr>
                        <td colspan="4" class="text-center py-5 text-muted">
                            <i class="fa-solid fa-calendar-xmark fs-2 text-secondary opacity-50 mb-2 d-block"></i>
                            No attendance records entered for you yet.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($records as $r): ?>
                        <tr>
                            <td>
                                <strong><?php echo date('d M Y', strtotime($r['date'])); ?></strong>
                            </td>
                            <td>
                                <span class="text-muted"><?php echo date('l', strtotime($r['date'])); ?></span>
                            </td>
                            <td>
                                <?php if ($r['status'] === 'Present'): ?>
                                    <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2 py-1">
                                        <i class="fa-solid fa-circle-check me-1"></i>Present
                                    </span>
                                <?php elseif ($r['status'] === 'Late'): ?>
                                    <span class="badge bg-warning bg-opacity-10 text-warning border border-warning border-opacity-25 px-2 py-1">
                                        <i class="fa-solid fa-clock me-1"></i>Late
                                    </span>
                                <?php elseif ($r['status'] === 'Excused'): ?>
                                    <span class="badge bg-info bg-opacity-10 text-info border border-info border-opacity-25 px-2 py-1">
                                        <i class="fa-solid fa-shield me-1"></i>Excused
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 px-2 py-1">
                                        <i class="fa-solid fa-circle-xmark me-1"></i>Absent
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="text-muted small"><?php echo e($r['remarks'] ?: '—'); ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
