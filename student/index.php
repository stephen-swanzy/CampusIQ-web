<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin(['student']);
$db = getDb();
$user = currentUser();
$settings = getSchoolSettings();
$currency = $settings['currency'] ?? 'GH₵';

$stmt = $db->prepare('SELECT s.*, c.name as class_name FROM students s LEFT JOIN classes c ON c.id = s.class_id WHERE s.user_id = :user_id LIMIT 1');
$stmt->execute(['user_id' => $user['id']]);
$student = $stmt->fetch();
$studentId = (int)($student['id'] ?? 0);
$classId = (int)($student['class_id'] ?? 0);

// Session Context
$academicYear = $db->query('SELECT name FROM academic_years WHERE is_current = 1 LIMIT 1')->fetchColumn() ?: '2026/2027';
$term = $db->query('SELECT name FROM terms WHERE is_current = 1 LIMIT 1')->fetchColumn() ?: 'Term 1';

// Time of day greeting
$hour = (int) date('H');
if ($hour < 12) {
    $greeting = 'Good morning ☀️';
} elseif ($hour < 17) {
    $greeting = 'Good afternoon 🌤️';
} else {
    $greeting = 'Good evening 🌙';
}

// Attendance
$attendance = $db->prepare('SELECT COUNT(*) as total, SUM(CASE WHEN status = "Present" THEN 1 ELSE 0 END) as present FROM attendance WHERE student_id = :student_id');
$attendance->execute(['student_id' => $studentId]);
$attendanceStats = $attendance->fetch();
$totalSessions = (int)($attendanceStats['total'] ?? 0);
$presentSessions = (int)($attendanceStats['present'] ?? 0);
$attPercent = $totalSessions > 0 ? round(($presentSessions / $totalSessions) * 100) : 100;

// Financial metrics
$totBilled = (float)$db->query("SELECT COALESCE(SUM(amount), 0) FROM fees WHERE student_id = $studentId")->fetchColumn();
$totPaid = (float)$db->query("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE student_id = $studentId")->fetchColumn();
$balanceDue = max(0, $totBilled - $totPaid);
$paidPercent = $totBilled > 0 ? round(($totPaid / $totBilled) * 100) : 100;

// Pending assignments
$pendingAssign = 0;
$pendingAssignmentsList = [];
if ($classId > 0) {
    $pendingAssign = (int)$db->query("SELECT COUNT(*) FROM assignments a WHERE a.class_id = $classId AND a.status = 'published' AND NOT EXISTS (SELECT 1 FROM assignment_submissions sub WHERE sub.assignment_id = a.id AND sub.student_id = $studentId)")->fetchColumn();
    $pendingAssignmentsList = $db->query("
        SELECT a.*, s.name as subject_name
        FROM assignments a
        LEFT JOIN subjects s ON s.id = a.subject_id
        WHERE a.class_id = $classId AND a.status = 'published'
        AND NOT EXISTS (SELECT 1 FROM assignment_submissions sub WHERE sub.assignment_id = a.id AND sub.student_id = $studentId)
        ORDER BY a.due_date ASC LIMIT 4
    ")->fetchAll();
}

// Recent announcements
$announcements = $db->query("SELECT * FROM announcements WHERE status = 'published' AND audience IN ('all', 'students') ORDER BY created_at DESC LIMIT 3")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<!-- 1. Student Hero Header -->
<div class="dash-hero">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                <span class="dash-chip primary">
                    <i class="fa-solid fa-graduation-cap"></i> Student Portal 🎒
                </span>
                <span class="dash-chip purple">
                    <i class="fa-solid fa-chalkboard"></i> Class: <?php echo e($student['class_name'] ?? 'Unassigned'); ?>
                </span>
                <span class="dash-chip rose">
                    <i class="fa-solid fa-calendar-check"></i> <?php echo e($academicYear); ?> • <?php echo e($term); ?>
                </span>
                <?php if (!empty($student['student_id'])): ?>
                    <span class="dash-chip success">
                        <i class="fa-solid fa-id-card"></i> <?php echo e($student['student_id']); ?>
                    </span>
                <?php endif; ?>
            </div>
            <h1 class="dash-hero-title"><?php echo $greeting; ?>, <?php echo e($user['name']); ?> <span class="wave-emoji">👋</span></h1>
            <p class="dash-hero-subtitle">Access your coursework tasks, track your terminal evaluations, submit assignments, and review tuition statements.</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="assignments.php" class="btn btn-outline-primary px-3 shadow-sm bg-white">
                <i class="fa-solid fa-file-lines me-2"></i>Tasks (<?php echo $pendingAssign; ?>)
            </a>
            <a href="fees.php" class="btn btn-primary px-3 shadow-sm">
                <i class="fa-solid fa-wallet me-2"></i>Pay Fees
            </a>
        </div>
    </div>
</div>

<!-- 2. Action Launcher Grid -->
<div class="action-launcher-grid">
    <a href="attendance.php" class="action-tile">
        <div class="action-tile-icon bg-soft-blue">
            <i class="fa-solid fa-calendar-check"></i>
        </div>
        <span class="action-tile-title">Attendance</span>
        <span class="action-tile-sub"><?php echo $presentSessions; ?> Days Present</span>
    </a>
    <a href="results.php" class="action-tile">
        <div class="action-tile-icon bg-soft-emerald">
            <i class="fa-solid fa-square-poll-vertical"></i>
        </div>
        <span class="action-tile-title">Report Card</span>
        <span class="action-tile-sub">Terminal Grades</span>
    </a>
    <a href="assignments.php" class="action-tile">
        <div class="action-tile-icon bg-soft-amber">
            <i class="fa-solid fa-file-pen"></i>
        </div>
        <span class="action-tile-title">Assignments</span>
        <span class="action-tile-sub"><?php echo $pendingAssign; ?> Due Soon</span>
    </a>
    <a href="resources.php" class="action-tile">
        <div class="action-tile-icon bg-soft-cyan">
            <i class="fa-solid fa-folder-open"></i>
        </div>
        <span class="action-tile-title">Study Library</span>
        <span class="action-tile-sub">PDF Resources</span>
    </a>
    <a href="fees.php" class="action-tile">
        <div class="action-tile-icon <?php echo $balanceDue > 0 ? 'bg-soft-rose' : 'bg-soft-emerald'; ?>">
            <i class="fa-solid fa-wallet"></i>
        </div>
        <span class="action-tile-title">School Fees</span>
        <span class="action-tile-sub <?php echo $balanceDue > 0 ? 'text-danger fw-bold' : 'text-success'; ?>">
            <?php echo $balanceDue > 0 ? $currency . ' ' . number_format($balanceDue) : 'Settled'; ?>
        </span>
    </a>
    <a href="profile.php" class="action-tile">
        <div class="action-tile-icon bg-soft-purple">
            <i class="fa-solid fa-id-badge"></i>
        </div>
        <span class="action-tile-title">Student Profile</span>
        <span class="action-tile-sub">Academic Bio</span>
    </a>
</div>

<!-- 3. KPI Metrics 2.0 -->
<div class="kpi-row kpi-grid-4">
    <div class="kpi-card-v2">
        <div class="kpi-header">
            <span class="kpi-lbl mb-0">Enrolled Class</span>
            <div class="kpi-icon-wrap bg-soft-blue"><i class="fa-solid fa-school"></i></div>
        </div>
        <div class="kpi-val"><?php echo e($student['class_name'] ?? 'Assigned Class'); ?></div>
        <span class="kpi-badge badge-soft-blue"><i class="fa-solid fa-graduation-cap me-1"></i>Current Roster</span>
    </div>

    <div class="kpi-card-v2">
        <div class="kpi-header">
            <span class="kpi-lbl mb-0">Attendance Rate</span>
            <div class="kpi-icon-wrap bg-soft-emerald"><i class="fa-solid fa-calendar-check"></i></div>
        </div>
        <div class="kpi-val"><?php echo $attPercent; ?>%</div>
        <span class="kpi-badge badge-soft-emerald"><i class="fa-solid fa-circle-check me-1"></i><?php echo $presentSessions; ?> of <?php echo $totalSessions; ?> Sessions</span>
    </div>

    <div class="kpi-card-v2">
        <div class="kpi-header">
            <span class="kpi-lbl mb-0">Pending Coursework</span>
            <div class="kpi-icon-wrap bg-soft-amber"><i class="fa-solid fa-clock"></i></div>
        </div>
        <div class="kpi-val"><?php echo $pendingAssign; ?></div>
        <span class="kpi-badge badge-soft-amber"><i class="fa-solid fa-hourglass-half me-1"></i>Awaiting Submission</span>
    </div>

    <div class="kpi-card-v2">
        <div class="kpi-header">
            <span class="kpi-lbl mb-0">Tuition Balance</span>
            <div class="kpi-icon-wrap <?php echo $balanceDue > 0 ? 'bg-soft-rose' : 'bg-soft-emerald'; ?>">
                <i class="fa-solid fa-scale-balanced"></i>
            </div>
        </div>
        <div class="kpi-val" title="<?php echo $currency; ?> <?php echo number_format($balanceDue, 2); ?>">
            <?php echo $currency; ?> <?php echo number_format($balanceDue, 2); ?>
        </div>
        <?php if ($balanceDue > 0): ?>
            <span class="kpi-badge badge-soft-rose"><i class="fa-solid fa-circle-exclamation me-1"></i>Payment Due</span>
        <?php else: ?>
            <span class="kpi-badge badge-soft-emerald"><i class="fa-solid fa-circle-check me-1"></i>Fully Cleared</span>
        <?php endif; ?>
    </div>
</div>

<!-- 4. Coursework Tasks & Financial Statement Section -->
<div class="row g-4">
    <!-- Left: Pending Homework Tasks -->
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm rounded-4 p-4 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="fa-solid fa-file-pen text-primary me-2"></i>Pending Homework & Tasks
                </h5>
                <a href="assignments.php" class="small text-primary text-decoration-none fw-semibold">View All</a>
            </div>

            <?php if (empty($pendingAssignmentsList)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="fa-solid fa-circle-check fs-1 text-success opacity-75 mb-3 d-block"></i>
                    <h6 class="fw-bold text-dark">You're All Caught Up!</h6>
                    <p class="small">No pending assignments or homework due at this time. Great job!</p>
                </div>
            <?php else: ?>
                <div class="d-grid gap-3">
                    <?php foreach ($pendingAssignmentsList as $pas): ?>
                        <div class="p-3 rounded-3 bg-light border border-opacity-75 d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <div>
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <strong class="text-dark small"><?php echo e($pas['title']); ?></strong>
                                    <?php if (!empty($pas['subject_name'])): ?>
                                        <span class="badge bg-primary bg-opacity-10 text-primary small">
                                            <?php echo e($pas['subject_name']); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <small class="text-danger fw-semibold">
                                    <i class="fa-regular fa-clock me-1"></i>Due <?php echo date('M d, Y', strtotime($pas['due_date'])); ?>
                                </small>
                            </div>
                            <a href="assignments.php?id=<?php echo (int)$pas['id']; ?>" class="btn btn-sm btn-primary">
                                <i class="fa-solid fa-cloud-arrow-up me-1"></i>Submit Work
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Right: Fee Status & Announcements -->
    <div class="col-lg-5">
        <!-- Tuition Card -->
        <div class="card border-0 shadow-sm rounded-4 p-4 mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-receipt text-success me-2"></i>Fee Statement</h5>
                <a href="fees.php" class="small text-primary text-decoration-none fw-semibold">Pay Online</a>
            </div>
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="text-muted small">Settlement Progress</span>
                <span class="fw-bold text-dark"><?php echo $paidPercent; ?>% Paid</span>
            </div>
            <div class="progress rounded-pill mb-3" style="height: 10px;">
                <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo min(100, $paidPercent); ?>%;"></div>
            </div>
            <div class="row g-2 text-center pt-2 border-top">
                <div class="col-6 border-end">
                    <small class="text-muted d-block">Paid to Date</small>
                    <strong class="text-success"><?php echo $currency; ?> <?php echo number_format($totPaid, 2); ?></strong>
                </div>
                <div class="col-6">
                    <small class="text-muted d-block">Outstanding</small>
                    <strong class="<?php echo $balanceDue > 0 ? 'text-danger' : 'text-success'; ?>"><?php echo $currency; ?> <?php echo number_format($balanceDue, 2); ?></strong>
                </div>
            </div>
        </div>

        <!-- School Announcements -->
        <div class="card border-0 shadow-sm rounded-4 p-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="fa-solid fa-bullhorn text-primary me-2"></i>Campus Notices
                </h5>
            </div>
            <?php if (empty($announcements)): ?>
                <div class="text-center py-4 text-muted">
                    <i class="fa-solid fa-bell-slash fs-2 opacity-50 mb-2 d-block"></i>
                    No new announcements.
                </div>
            <?php else: ?>
                <div class="d-grid gap-3">
                    <?php foreach ($announcements as $ann): ?>
                        <div class="border-bottom pb-2">
                            <strong class="text-dark d-block small mb-1"><?php echo e($ann['title']); ?></strong>
                            <small class="text-muted"><?php echo date('M d, Y', strtotime($ann['created_at'])); ?></small>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
