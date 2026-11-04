<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin(['teacher']);
$db = getDb();
$user = currentUser();
$settings = getSchoolSettings();

$stmt = $db->prepare('SELECT t.id, t.teacher_id, d.name as department_name FROM teachers t LEFT JOIN departments d ON d.id = t.department_id WHERE t.user_id = :user_id LIMIT 1');
$stmt->execute(['user_id' => $user['id']]);
$teacher = $stmt->fetch();
$teacherId = (int)($teacher['id'] ?? 0);

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

$assignedClasses = $db->query('
    SELECT c.*, COUNT(DISTINCT e.student_id) as student_total
    FROM classes c
    JOIN subject_teacher_assignments sta ON sta.class_id = c.id
    LEFT JOIN enrollments e ON e.class_id = c.id
    WHERE sta.teacher_id = ' . $teacherId . '
    GROUP BY c.id
    ORDER BY c.name
')->fetchAll();

$attendanceCount = (int)$db->query('SELECT COUNT(*) as total FROM attendance WHERE recorded_by = ' . (int)$user['id'])->fetch()['total'];
$studentsCount = (int)$db->query('SELECT COUNT(DISTINCT s.id) as total FROM students s JOIN enrollments e ON e.student_id = s.id JOIN subject_teacher_assignments sta ON sta.class_id = e.class_id WHERE sta.teacher_id = ' . $teacherId)->fetch()['total'];
$assignmentsCount = (int)$db->query('SELECT COUNT(*) as total FROM assignments WHERE teacher_id = ' . $teacherId)->fetch()['total'];
$resourcesCount = (int)$db->query('SELECT COUNT(*) as total FROM learning_resources WHERE teacher_id = ' . $teacherId)->fetch()['total'];

// Recent assignments published by this teacher
$recentAssignments = $db->query('
    SELECT a.*, c.name as class_name,
    (SELECT COUNT(*) FROM assignment_submissions sub WHERE sub.assignment_id = a.id) as submission_count
    FROM assignments a
    LEFT JOIN classes c ON c.id = a.class_id
    WHERE a.teacher_id = ' . $teacherId . '
    ORDER BY a.created_at DESC LIMIT 4
')->fetchAll();

// Announcements
$teacherAnnouncements = $db->query("SELECT * FROM announcements WHERE status = 'published' AND audience IN ('all', 'teachers') ORDER BY created_at DESC LIMIT 3")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<!-- 1. Faculty Hero Header -->
<div class="dash-hero">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                <span class="dash-chip primary">
                    <i class="fa-solid fa-chalkboard-user"></i> Faculty Member 🎓
                </span>
                <span class="dash-chip purple">
                    <i class="fa-solid fa-building-columns"></i> <?php echo e($teacher['department_name'] ?? 'Academic Department'); ?>
                </span>
                <span class="dash-chip amber">
                    <i class="fa-solid fa-calendar-check"></i> <?php echo e($academicYear); ?> • <?php echo e($term); ?>
                </span>
                <?php if (!empty($teacher['teacher_id'])): ?>
                    <span class="dash-chip success">
                        <i class="fa-solid fa-id-badge"></i> <?php echo e($teacher['teacher_id']); ?>
                    </span>
                <?php endif; ?>
            </div>
            <h1 class="dash-hero-title"><?php echo $greeting; ?>, <?php echo e($user['name']); ?> <span class="wave-emoji">👋</span></h1>
            <p class="dash-hero-subtitle">Manage class rosters, mark daily student attendance, record terminal evaluation scores, and publish coursework materials.</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="attendance.php" class="btn btn-outline-primary px-3 shadow-sm bg-white">
                <i class="fa-solid fa-clipboard-check me-2"></i>Take Attendance
            </a>
            <a href="results.php" class="btn btn-primary px-3 shadow-sm">
                <i class="fa-solid fa-square-poll-vertical me-2"></i>Record Grades
            </a>
        </div>
    </div>
</div>

<!-- 2. Action Launcher Grid -->
<div class="action-launcher-grid">
    <a href="attendance.php" class="action-tile">
        <div class="action-tile-icon bg-soft-blue">
            <i class="fa-solid fa-clipboard-check"></i>
        </div>
        <span class="action-tile-title">Attendance</span>
        <span class="action-tile-sub">Daily Rollcall</span>
    </a>
    <a href="results.php" class="action-tile">
        <div class="action-tile-icon bg-soft-emerald">
            <i class="fa-solid fa-chart-simple"></i>
        </div>
        <span class="action-tile-title">Grades & Results</span>
        <span class="action-tile-sub">Terminal Scores</span>
    </a>
    <a href="assignments.php" class="action-tile">
        <div class="action-tile-icon bg-soft-amber">
            <i class="fa-solid fa-file-pen"></i>
        </div>
        <span class="action-tile-title">Assignments</span>
        <span class="action-tile-sub"><?php echo $assignmentsCount; ?> Active</span>
    </a>
    <a href="resources.php" class="action-tile">
        <div class="action-tile-icon bg-soft-cyan">
            <i class="fa-solid fa-folder-open"></i>
        </div>
        <span class="action-tile-title">Class Resources</span>
        <span class="action-tile-sub"><?php echo $resourcesCount; ?> Documents</span>
    </a>
    <a href="students.php" class="action-tile">
        <div class="action-tile-icon bg-soft-purple">
            <i class="fa-solid fa-users"></i>
        </div>
        <span class="action-tile-title">My Students</span>
        <span class="action-tile-sub"><?php echo $studentsCount; ?> Enrolled</span>
    </a>
    <a href="communication.php" class="action-tile">
        <div class="action-tile-icon bg-soft-rose">
            <i class="fa-solid fa-bullhorn"></i>
        </div>
        <span class="action-tile-title">Notices</span>
        <span class="action-tile-sub">Class Updates</span>
    </a>
</div>

<!-- 3. KPI Metrics 2.0 -->
<div class="kpi-row kpi-grid-4">
    <div class="kpi-card-v2">
        <div class="kpi-header">
            <span class="kpi-lbl mb-0">Assigned Classes</span>
            <div class="kpi-icon-wrap bg-soft-blue"><i class="fa-solid fa-school"></i></div>
        </div>
        <div class="kpi-val"><?php echo count($assignedClasses); ?></div>
        <span class="kpi-badge badge-soft-blue"><i class="fa-solid fa-chalkboard me-1"></i>Active Rosters</span>
    </div>

    <div class="kpi-card-v2">
        <div class="kpi-header">
            <span class="kpi-lbl mb-0">Enrolled Students</span>
            <div class="kpi-icon-wrap bg-soft-emerald"><i class="fa-solid fa-user-graduate"></i></div>
        </div>
        <div class="kpi-val"><?php echo number_format($studentsCount); ?></div>
        <span class="kpi-badge badge-soft-emerald"><i class="fa-solid fa-check me-1"></i>Under Tutelage</span>
    </div>

    <div class="kpi-card-v2">
        <div class="kpi-header">
            <span class="kpi-lbl mb-0">Attendance Logs</span>
            <div class="kpi-icon-wrap bg-soft-purple"><i class="fa-solid fa-clipboard-check"></i></div>
        </div>
        <div class="kpi-val"><?php echo number_format($attendanceCount); ?></div>
        <span class="kpi-badge badge-soft-blue"><i class="fa-solid fa-clock-rotate-left me-1"></i>Recorded Entries</span>
    </div>

    <div class="kpi-card-v2">
        <div class="kpi-header">
            <span class="kpi-lbl mb-0">Published Coursework</span>
            <div class="kpi-icon-wrap bg-soft-amber"><i class="fa-solid fa-book-open"></i></div>
        </div>
        <div class="kpi-val"><?php echo number_format($assignmentsCount + $resourcesCount); ?></div>
        <span class="kpi-badge badge-soft-amber"><i class="fa-solid fa-file-arrow-up me-1"></i>Tasks & Files</span>
    </div>
</div>

<!-- 4. Class Allocation & Recent Activities -->
<div class="row g-4">
    <!-- Left: Assigned Class Rosters -->
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm rounded-4 p-4 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="fa-solid fa-chalkboard text-primary me-2"></i>My Allocated Classes
                </h5>
                <span class="badge badge-soft-blue px-3 py-1 rounded-pill"><?php echo count($assignedClasses); ?> Classrooms</span>
            </div>

            <?php if (empty($assignedClasses)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="fa-solid fa-chalkboard-user fs-1 text-secondary opacity-50 mb-3 d-block"></i>
                    <h6 class="fw-bold">No Allocated Classes Yet</h6>
                    <p class="small">The administrator has not yet assigned direct teaching subjects to your faculty profile.</p>
                </div>
            <?php else: ?>
                <div class="d-grid gap-3">
                    <?php foreach ($assignedClasses as $cls): ?>
                        <div class="p-3 rounded-3 bg-light border border-opacity-75 d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <div>
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <strong class="fs-6 text-dark"><?php echo e($cls['name']); ?></strong>
                                    <span class="badge bg-secondary bg-opacity-10 text-secondary border small">
                                        <?php echo e($cls['level'] ?? 'Standard'); ?>
                                    </span>
                                </div>
                                <small class="text-muted"><i class="fa-solid fa-users me-1"></i><?php echo (int)($cls['student_total'] ?? 0); ?> Students Enrolled</small>
                            </div>
                            <div class="d-flex gap-2">
                                <a href="attendance.php?class_id=<?php echo (int)$cls['id']; ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="fa-solid fa-clipboard-check me-1"></i>Attendance
                                </a>
                                <a href="results.php?class_id=<?php echo (int)$cls['id']; ?>" class="btn btn-sm btn-primary">
                                    <i class="fa-solid fa-square-poll-vertical me-1"></i>Grades
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Right: Recent Assignments & Notice Board -->
    <div class="col-lg-5">
        <!-- Assignments Card -->
        <div class="card border-0 shadow-sm rounded-4 p-4 mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="fa-solid fa-file-pen text-warning me-2"></i>Active Coursework
                </h5>
                <a href="assignments.php" class="small text-primary text-decoration-none fw-semibold">View All</a>
            </div>

            <?php if (empty($recentAssignments)): ?>
                <div class="text-center py-4 text-muted">
                    <i class="fa-solid fa-file-lines fs-2 opacity-50 mb-2 d-block"></i>
                    No coursework published yet.
                </div>
            <?php else: ?>
                <div class="d-grid gap-3">
                    <?php foreach ($recentAssignments as $asn): ?>
                        <div class="border-bottom pb-2">
                            <div class="d-flex justify-content-between align-items-start mb-1">
                                <strong class="text-dark small"><?php echo e($asn['title']); ?></strong>
                                <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 small">
                                    <?php echo (int)$asn['submission_count']; ?> Submitted
                                </span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center">
                                <small class="text-muted"><i class="fa-solid fa-school me-1"></i><?php echo e($asn['class_name'] ?? 'All'); ?></small>
                                <small class="text-danger fw-semibold"><i class="fa-regular fa-clock me-1"></i>Due <?php echo date('M d', strtotime($asn['due_date'])); ?></small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Staff Notices -->
        <div class="card border-0 shadow-sm rounded-4 p-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="fa-solid fa-bullhorn text-danger me-2"></i>Staff Notices
                </h5>
                <a href="communication.php" class="small text-primary text-decoration-none fw-semibold">View All</a>
            </div>

            <?php if (empty($teacherAnnouncements)): ?>
                <div class="text-center py-4 text-muted">
                    <i class="fa-solid fa-bell-slash fs-2 opacity-50 mb-2 d-block"></i>
                    No notices posted.
                </div>
            <?php else: ?>
                <div class="d-grid gap-3">
                    <?php foreach ($teacherAnnouncements as $ta): ?>
                        <div class="border-bottom pb-2">
                            <strong class="text-dark d-block small mb-1"><?php echo e($ta['title']); ?></strong>
                            <div class="d-flex justify-content-between align-items-center">
                                <small class="text-muted"><?php echo e($ta['audience'] === 'all' ? 'Entire Campus' : 'Faculty Only'); ?></small>
                                <small class="text-muted"><?php echo date('M d', strtotime($ta['created_at'])); ?></small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
