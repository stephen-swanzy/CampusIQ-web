<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin(['parent']);
$db = getDb();
$user = currentUser();
$settings = getSchoolSettings();
$currency = $settings['currency'] ?? 'GH₵';

$stmt = $db->prepare('SELECT p.*, u.name FROM parents p JOIN users u ON u.id = p.user_id WHERE p.user_id = :user_id LIMIT 1');
$stmt->execute(['user_id' => $user['id']]);
$parent = $stmt->fetch();

$parentId = (int)($parent['id'] ?? 0);
$parentUserId = (int)$user['id'];

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

// Fetch children with attendance and financial details
$childrenStmt = $db->prepare('
    SELECT s.*, u.name as child_name, c.name as class_name
    FROM students s
    JOIN users u ON u.id = s.user_id
    LEFT JOIN classes c ON c.id = s.class_id
    WHERE (s.parent_id = :pid OR s.parent_id = :puid)
    ORDER BY u.name ASC
');
$childrenStmt->execute(['pid' => $parentId, 'puid' => $parentUserId]);
$rawChildren = $childrenStmt->fetchAll();

$children = [];
$totalOutstanding = 0.0;
$totalBilledAll = 0.0;
$totalPaidAll = 0.0;

foreach ($rawChildren as $c) {
    $cid = (int)$c['id'];
    $b = (float)$db->query("SELECT COALESCE(SUM(amount), 0) FROM fees WHERE student_id = $cid")->fetchColumn();
    $p = (float)$db->query("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE student_id = $cid")->fetchColumn();
    $bal = max(0, $b - $p);

    $totalBilledAll += $b;
    $totalPaidAll += $p;
    $totalOutstanding += $bal;

    // Today's attendance
    $todayAtt = $db->query("SELECT status FROM attendance WHERE student_id = $cid AND date = CURDATE() LIMIT 1")->fetchColumn();

    // Overall attendance rate
    $attStats = $db->query("SELECT COUNT(*) as total, SUM(CASE WHEN status = 'Present' THEN 1 ELSE 0 END) as present FROM attendance WHERE student_id = $cid")->fetch();
    $attTotal = (int)($attStats['total'] ?? 0);
    $attPres = (int)($attStats['present'] ?? 0);
    $attRate = $attTotal > 0 ? round(($attPres / $attTotal) * 100) : 100;

    $c['billed'] = $b;
    $c['paid'] = $p;
    $c['balance'] = $bal;
    $c['today_status'] = $todayAtt;
    $c['attendance_rate'] = $attRate;
    $c['attendance_total'] = $attTotal;
    $c['attendance_present'] = $attPres;

    $children[] = $c;
}

// Announcements
$announcements = $db->query("SELECT * FROM announcements WHERE status = 'published' AND audience IN ('all', 'parents') ORDER BY created_at DESC LIMIT 3")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<!-- 1. Guardian Hero Header -->
<div class="dash-hero">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                <span class="dash-chip primary">
                    <i class="fa-solid fa-heart text-danger"></i> Guardian Portal 🏡
                </span>
                <span class="dash-chip purple">
                    <i class="fa-solid fa-children"></i> <?php echo count($children); ?> Ward(s) Enrolled
                </span>
                <span class="dash-chip success">
                    <i class="fa-solid fa-calendar-check"></i> <?php echo e($academicYear); ?> • <?php echo e($term); ?>
                </span>
            </div>
            <h1 class="dash-hero-title"><?php echo $greeting; ?>, <?php echo e($user['name']); ?> <span class="wave-emoji">👋</span></h1>
            <p class="dash-hero-subtitle">Review your children's terminal performance reports, monitor daily classroom attendance, and manage school fee disbursements.</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="children.php" class="btn btn-outline-primary px-3 shadow-sm bg-white">
                <i class="fa-solid fa-users-viewfinder me-2"></i>My Wards
            </a>
            <a href="fees.php" class="btn btn-primary px-3 shadow-sm">
                <i class="fa-solid fa-credit-card me-2"></i>Pay School Fees
            </a>
        </div>
    </div>
</div>

<!-- 2. Action Launcher Grid -->
<div class="action-launcher-grid">
    <a href="children.php" class="action-tile">
        <div class="action-tile-icon bg-soft-blue">
            <i class="fa-solid fa-children"></i>
        </div>
        <span class="action-tile-title">My Wards</span>
        <span class="action-tile-sub"><?php echo count($children); ?> Profiles</span>
    </a>
    <a href="attendance.php" class="action-tile">
        <div class="action-tile-icon bg-soft-emerald">
            <i class="fa-solid fa-calendar-check"></i>
        </div>
        <span class="action-tile-title">Attendance</span>
        <span class="action-tile-sub">Daily Rollcall</span>
    </a>
    <a href="results.php" class="action-tile">
        <div class="action-tile-icon bg-soft-purple">
            <i class="fa-solid fa-chart-line"></i>
        </div>
        <span class="action-tile-title">Report Cards</span>
        <span class="action-tile-sub">Exam Grades</span>
    </a>
    <a href="fees.php" class="action-tile">
        <div class="action-tile-icon <?php echo $totalOutstanding > 0 ? 'bg-soft-rose' : 'bg-soft-emerald'; ?>">
            <i class="fa-solid fa-wallet"></i>
        </div>
        <span class="action-tile-title">Pay Tuition</span>
        <span class="action-tile-sub <?php echo $totalOutstanding > 0 ? 'text-danger fw-bold' : 'text-success'; ?>">
            <?php echo $totalOutstanding > 0 ? $currency . ' ' . number_format($totalOutstanding) : 'Settled'; ?>
        </span>
    </a>
    <a href="messages.php" class="action-tile">
        <div class="action-tile-icon bg-soft-amber">
            <i class="fa-solid fa-envelope"></i>
        </div>
        <span class="action-tile-title">Staff Notices</span>
        <span class="action-tile-sub">School Mail</span>
    </a>
</div>

<!-- 3. KPI Metrics 2.0 -->
<div class="kpi-row kpi-grid-2">
    <div class="kpi-card-v2">
        <div class="kpi-header">
            <span class="kpi-lbl mb-0">Enrolled Children</span>
            <div class="kpi-icon-wrap bg-soft-blue"><i class="fa-solid fa-children"></i></div>
        </div>
        <div class="kpi-val"><?php echo count($children); ?></div>
        <span class="kpi-badge badge-soft-blue"><i class="fa-solid fa-user-check me-1"></i>Active Students</span>
    </div>

    <div class="kpi-card-v2">
        <div class="kpi-header">
            <span class="kpi-lbl mb-0">Total Outstanding Balance</span>
            <div class="kpi-icon-wrap <?php echo $totalOutstanding > 0 ? 'bg-soft-rose' : 'bg-soft-emerald'; ?>">
                <i class="fa-solid fa-scale-balanced"></i>
            </div>
        </div>
        <div class="kpi-val" title="<?php echo $currency; ?> <?php echo number_format($totalOutstanding, 2); ?>">
            <?php echo $currency; ?> <?php echo number_format($totalOutstanding, 2); ?>
        </div>
        <?php if ($totalOutstanding > 0): ?>
            <span class="kpi-badge badge-soft-rose"><i class="fa-solid fa-clock me-1"></i>Unsettled Balance</span>
        <?php else: ?>
            <span class="kpi-badge badge-soft-emerald"><i class="fa-solid fa-circle-check me-1"></i>All Fees Paid</span>
        <?php endif; ?>
    </div>
</div>

<!-- 4. Wards Academic Profiles & Announcements -->
<div class="row g-4">
    <!-- Left: Wards Profile Cards -->
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm rounded-4 p-4 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="fa-solid fa-children text-primary me-2"></i>My Children's Academic Status
                </h5>
                <span class="badge badge-soft-blue px-3 py-1 rounded-pill"><?php echo count($children); ?> Wards</span>
            </div>

            <?php if (empty($children)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="fa-solid fa-user-slash fs-1 text-secondary opacity-50 mb-3 d-block"></i>
                    <h6 class="fw-bold">No Students Linked</h6>
                    <p class="small">Your parent profile does not have any registered students linked yet. Please contact the school administration.</p>
                </div>
            <?php else: ?>
                <div class="d-grid gap-3">
                    <?php foreach ($children as $ch): ?>
                        <div class="p-3 rounded-4 bg-light border border-opacity-75">
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center fw-bold fs-5" style="width: 48px; height: 48px;">
                                        <?php echo strtoupper(substr($ch['child_name'], 0, 1)); ?>
                                    </div>
                                    <div>
                                        <h6 class="fw-bold text-dark mb-0"><?php echo e($ch['child_name']); ?></h6>
                                        <small class="text-muted">
                                            ID: <strong><?php echo e($ch['student_id'] ?? 'N/A'); ?></strong> • 
                                            Class: <strong><?php echo e($ch['class_name'] ?? 'General'); ?></strong>
                                        </small>
                                    </div>
                                </div>
                                <div>
                                    <?php if ($ch['today_status'] === 'Present'): ?>
                                        <span class="badge-soft-emerald badge px-3 py-2 rounded-pill">
                                            <i class="fa-solid fa-circle-check me-1"></i>Present Today
                                        </span>
                                    <?php elseif ($ch['today_status'] === 'Absent'): ?>
                                        <span class="badge-soft-rose badge px-3 py-2 rounded-pill">
                                            <i class="fa-solid fa-circle-xmark me-1"></i>Absent Today
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary bg-opacity-10 text-secondary border px-3 py-2 rounded-pill">
                                            <i class="fa-regular fa-clock me-1"></i>Rollcall Pending
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="row g-2 pt-2 border-top mb-3">
                                <div class="col-6 col-md-4">
                                    <small class="text-muted d-block">Term Attendance</small>
                                    <strong class="text-dark"><?php echo $ch['attendance_rate']; ?>% Presence</strong>
                                </div>
                                <div class="col-6 col-md-4">
                                    <small class="text-muted d-block">Outstanding Fees</small>
                                    <strong class="<?php echo $ch['balance'] > 0 ? 'text-danger' : 'text-success'; ?>">
                                        <?php echo $currency; ?> <?php echo number_format($ch['balance'], 2); ?>
                                    </strong>
                                </div>
                                <div class="col-12 col-md-4 d-flex align-items-center justify-content-md-end gap-2 mt-2 mt-md-0">
                                    <a href="results.php?student_id=<?php echo (int)$ch['id']; ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="fa-solid fa-chart-line me-1"></i>Report Card
                                    </a>
                                    <?php if ($ch['balance'] > 0): ?>
                                        <a href="fees.php?student_id=<?php echo (int)$ch['id']; ?>" class="btn btn-sm btn-success">
                                            <i class="fa-solid fa-wallet me-1"></i>Pay
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Right: Guardian Notice Board -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm rounded-4 p-4 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="fa-solid fa-bullhorn text-danger me-2"></i>Guardian Notices
                </h5>
            </div>

            <?php if (empty($announcements)): ?>
                <div class="text-center py-4 text-muted">
                    <i class="fa-solid fa-bell-slash fs-2 opacity-50 mb-2 d-block"></i>
                    No notices posted for guardians.
                </div>
            <?php else: ?>
                <div class="d-grid gap-3">
                    <?php foreach ($announcements as $an): ?>
                        <div class="border-bottom pb-2">
                            <strong class="text-dark d-block small mb-1"><?php echo e($an['title']); ?></strong>
                            <small class="text-muted"><?php echo date('M d, Y', strtotime($an['created_at'])); ?></small>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="mt-4 pt-3 border-top">
                <h6 class="fw-bold text-dark small mb-2"><i class="fa-solid fa-headset text-primary me-2"></i>School Administration Help</h6>
                <p class="text-muted small mb-2">Have a question regarding fees, reports, or classroom attendance? Contact school authorities directly.</p>
                <div class="d-flex align-items-center gap-2 small text-muted">
                    <i class="fa-solid fa-phone text-primary"></i> <?php echo e($settings['phone'] ?? '+233 000 000 000'); ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
