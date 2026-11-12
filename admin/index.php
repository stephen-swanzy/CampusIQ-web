<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin(['super_admin', 'school_admin']);

require_once __DIR__ . '/../includes/backup_service.php';
$backupService = new CampusBackupService();
$backupService->checkAndRunScheduledBackup();

require_once __DIR__ . '/../includes/security.php';
$dashPepper = campusPasswordPepper();
$dashSamplePw = 'Student@2024';
$dashSampleHmac = hash_hmac('sha256', $dashSamplePw, $dashPepper);
$dashSampleHash1 = campusPasswordHash($dashSamplePw);
$dashSampleHash2 = campusPasswordHash($dashSamplePw);

// Parse Bcrypt components
$dashSalt1 = substr($dashSampleHash1, 7, 22);
$dashSalt2 = substr($dashSampleHash2, 7, 22);

require_once __DIR__ . '/../includes/header.php';

$db = getDb();
$user = currentUser();
$settings = getSchoolSettings();
$currency = $settings['currency'] ?? 'GH₵';

// Time of day greeting with cute emoji
$hour = (int) date('H');
if ($hour < 12) {
    $greeting = 'Good morning ☀️';
} elseif ($hour < 17) {
    $greeting = 'Good afternoon 🌤️';
} else {
    $greeting = 'Good evening 🌙';
}

// Session Context
$academicYear = $db->query('SELECT name FROM academic_years WHERE is_current = 1 LIMIT 1')->fetchColumn() ?: '2026/2027';
$term = $db->query('SELECT name FROM terms WHERE is_current = 1 LIMIT 1')->fetchColumn() ?: 'Term 1';

// KPI Metrics
$studentCount = (int) $db->query('SELECT COUNT(*) FROM students')->fetchColumn();
$teacherCount = (int) $db->query('SELECT COUNT(*) FROM teachers')->fetchColumn();
$parentCount = (int) $db->query('SELECT COUNT(*) FROM parents')->fetchColumn();
$classCount = (int) $db->query('SELECT COUNT(*) FROM classes')->fetchColumn();
$subjectCount = (int) $db->query('SELECT COUNT(*) FROM subjects')->fetchColumn();
$todayAttendance = (int) $db->query("SELECT COUNT(*) FROM attendance WHERE date = CURDATE() AND status = 'Present'")->fetchColumn();
$attendanceRate = $studentCount > 0 ? round(($todayAttendance / $studentCount) * 100) : 0;

$totalBilled = (float) $db->query('SELECT COALESCE(SUM(amount), 0) FROM fees')->fetchColumn();
$totalCollected = (float) $db->query('SELECT COALESCE(SUM(amount), 0) FROM payments')->fetchColumn();
$pendingFees = max(0, $totalBilled - $totalCollected);
$collectionRate = $totalBilled > 0 ? round(($totalCollected / $totalBilled) * 100) : 0;

$recentPayments = $db->query('SELECT p.*, s.student_id, u.name as student_name FROM payments p JOIN students s ON s.id = p.student_id JOIN users u ON u.id = s.user_id ORDER BY p.created_at DESC LIMIT 5')->fetchAll();
$recentActivity = $db->query('SELECT a.*, u.name FROM activity_logs a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.created_at DESC LIMIT 6')->fetchAll();
$upcomingEvents = $db->query('SELECT * FROM events ORDER BY event_date ASC LIMIT 4')->fetchAll();
$announcements = $db->query('SELECT a.*, u.name as author_name FROM announcements a JOIN users u ON u.id = a.author_id WHERE a.status = "published" ORDER BY a.created_at DESC LIMIT 3')->fetchAll();

// 6-Month dynamic data for Analytics Chart
$monthlyLabels = [];
$monthlyRevenue = [];
$monthlyAtt = [];
for ($i = 5; $i >= 0; $i--) {
    $mStart = date('Y-m-01', strtotime("-$i months"));
    $mEnd = date('Y-m-t', strtotime("-$i months"));
    $monthlyLabels[] = date('M', strtotime("-$i months"));
    $monthlyRevenue[] = (float) $db->query("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE payment_date >= '$mStart' AND payment_date <= '$mEnd'")->fetchColumn();
    $monthlyAtt[] = (int) $db->query("SELECT COUNT(*) FROM attendance WHERE date >= '$mStart' AND date <= '$mEnd' AND status = 'Present'")->fetchColumn();
}
?>

<!-- 1. Executive Hero Header -->
<div class="dash-hero">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                <span class="dash-chip primary">
                    <i class="fa-solid fa-graduation-cap"></i> <?php echo e($settings['school_name'] ?? 'CampusIQ'); ?>
                </span>
                <span class="dash-chip purple">
                    <i class="fa-solid fa-calendar-days"></i> <?php echo e($academicYear); ?> • <?php echo e($term); ?>
                </span>
                <span class="dash-chip success">
                    <span class="pulse-dot me-1"></span> Live Session ✨
                </span>
            </div>
            <h1 class="dash-hero-title"><?php echo $greeting; ?>, <?php echo e($user['name']); ?> <span class="wave-emoji">👋</span></h1>
            <p class="dash-hero-subtitle">Welcome to your institution's central administration overview. Monitor real-time campus attendance, staff operations, student progress, and tuition flows.</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="reports.php" class="btn btn-outline-primary px-3 shadow-sm bg-white">
                <i class="fa-solid fa-chart-pie me-2"></i>Reports Engine
            </a>
            <a href="settings.php" class="btn btn-primary px-3 shadow-sm">
                <i class="fa-solid fa-gear me-2"></i>School Settings
            </a>
        </div>
    </div>
</div>

<!-- 2. Quick Action Launcher -->
<div class="action-launcher-grid">
    <a href="students.php" class="action-tile">
        <div class="action-tile-icon bg-soft-blue">
            <i class="fa-solid fa-user-plus"></i>
        </div>
        <span class="action-tile-title">Enroll Student</span>
        <span class="action-tile-sub"><?php echo $studentCount; ?> Enrolled</span>
    </a>
    <a href="teachers.php" class="action-tile">
        <div class="action-tile-icon bg-soft-emerald">
            <i class="fa-solid fa-chalkboard-user"></i>
        </div>
        <span class="action-tile-title">Faculty Staff</span>
        <span class="action-tile-sub"><?php echo $teacherCount; ?> Teachers</span>
    </a>
    <a href="attendance.php" class="action-tile">
        <div class="action-tile-icon bg-soft-purple">
            <i class="fa-solid fa-clipboard-check"></i>
        </div>
        <span class="action-tile-title">Attendance</span>
        <span class="action-tile-sub"><?php echo $attendanceRate; ?>% Today</span>
    </a>
    <a href="<?php echo baseUrl(); ?>/accountant/payments.php" class="action-tile">
        <div class="action-tile-icon bg-soft-amber">
            <i class="fa-solid fa-hand-holding-dollar"></i>
        </div>
        <span class="action-tile-title">Fee Collection</span>
        <span class="action-tile-sub">Record Cash/MoMo</span>
    </a>
    <a href="reports.php" class="action-tile">
        <div class="action-tile-icon bg-soft-rose">
            <i class="fa-solid fa-chart-line"></i>
        </div>
        <span class="action-tile-title">Exam Analytics</span>
        <span class="action-tile-sub">Academic Yield</span>
    </a>
    <a href="backups.php" class="action-tile">
        <div class="action-tile-icon bg-soft-cyan">
            <i class="fa-solid fa-shield-halved"></i>
        </div>
        <span class="action-tile-title">Cloud &amp; Offline</span>
        <span class="action-tile-sub">Data Protection</span>
    </a>
    <a href="system_reports.php" class="action-tile">
        <div class="action-tile-icon bg-soft-rose">
            <i class="fa-solid fa-flag"></i>
        </div>
        <span class="action-tile-title">System Concerns</span>
        <span class="action-tile-sub">User Feedback</span>
    </a>
</div>

<!-- 3. KPI Metrics 2.0 -->
<div class="kpi-row kpi-grid-6">
    <div class="kpi-card-v2">
        <div class="kpi-header">
            <span class="kpi-lbl mb-0">Total Students</span>
            <div class="kpi-icon-wrap bg-soft-blue"><i class="fa-solid fa-user-graduate"></i></div>
        </div>
        <div class="kpi-val"><?php echo number_format($studentCount); ?></div>
        <span class="kpi-badge badge-soft-blue"><i class="fa-solid fa-users me-1"></i>Active Students</span>
    </div>

    <div class="kpi-card-v2">
        <div class="kpi-header">
            <span class="kpi-lbl mb-0">Teachers & Faculty</span>
            <div class="kpi-icon-wrap bg-soft-emerald"><i class="fa-solid fa-chalkboard-user"></i></div>
        </div>
        <div class="kpi-val"><?php echo number_format($teacherCount); ?></div>
        <span class="kpi-badge badge-soft-emerald"><i class="fa-solid fa-circle-check me-1"></i><?php echo $classCount; ?> Classes Managed</span>
    </div>

    <div class="kpi-card-v2">
        <div class="kpi-header">
            <span class="kpi-lbl mb-0">Present Today</span>
            <div class="kpi-icon-wrap bg-soft-purple"><i class="fa-solid fa-calendar-check"></i></div>
        </div>
        <div class="kpi-val"><?php echo number_format($todayAttendance); ?></div>
        <span class="kpi-badge badge-soft-emerald"><i class="fa-solid fa-arrow-trend-up me-1"></i><?php echo $attendanceRate; ?>% Daily Presence</span>
    </div>

    <div class="kpi-card-v2">
        <div class="kpi-header">
            <span class="kpi-lbl mb-0">Tuition Collected</span>
            <div class="kpi-icon-wrap bg-soft-emerald"><i class="fa-solid fa-money-bill-wave"></i></div>
        </div>
        <div class="kpi-val" title="<?php echo $currency; ?> <?php echo number_format($totalCollected, 2); ?>">
            <?php echo $currency; ?> <?php echo number_format($totalCollected); ?>
        </div>
        <span class="kpi-badge badge-soft-emerald"><i class="fa-solid fa-circle-check me-1"></i><?php echo $collectionRate; ?>% Yield</span>
    </div>

    <div class="kpi-card-v2">
        <div class="kpi-header">
            <span class="kpi-lbl mb-0">Outstanding Fees</span>
            <div class="kpi-icon-wrap bg-soft-rose"><i class="fa-solid fa-scale-balanced"></i></div>
        </div>
        <div class="kpi-val" title="<?php echo $currency; ?> <?php echo number_format($pendingFees, 2); ?>">
            <?php echo $currency; ?> <?php echo number_format($pendingFees); ?>
        </div>
        <span class="kpi-badge badge-soft-rose"><i class="fa-solid fa-clock me-1"></i>Unsettled Balance</span>
    </div>

    <div class="kpi-card-v2">
        <div class="kpi-header">
            <span class="kpi-lbl mb-0">Registered Parents</span>
            <div class="kpi-icon-wrap bg-soft-amber"><i class="fa-solid fa-people-roof"></i></div>
        </div>
        <div class="kpi-val"><?php echo number_format($parentCount); ?></div>
        <span class="kpi-badge badge-soft-amber"><i class="fa-solid fa-mobile-screen me-1"></i>Linked Guardians</span>
    </div>
</div>

<!-- 4. Main Analytics & Activity Section -->
<div class="row g-4 mb-4">
    <!-- Left: Dynamic Chart + Recent Payments -->
    <div class="col-lg-8">
        <!-- Interactive Chart Card -->
        <div class="card border-0 shadow-sm rounded-4 p-4 mb-4">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 pb-2 border-bottom">
                <div>
                    <h5 class="fw-bold mb-1 text-dark"><i class="fa-solid fa-chart-column text-primary me-2"></i>Institutional Yield & Attendance</h5>
                    <small class="text-muted">6-month trend tracking fee settlement and classroom presence</small>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge badge-soft-blue px-3 py-2 rounded-pill"><i class="fa-solid fa-circle text-primary me-1" style="font-size: 8px;"></i> Revenue (<?php echo e($currency); ?>)</span>
                    <span class="badge badge-soft-emerald px-3 py-2 rounded-pill"><i class="fa-solid fa-circle text-success me-1" style="font-size: 8px;"></i> Attendance</span>
                </div>
            </div>
            <div class="chart-box" style="height: 310px;">
                <canvas id="overviewChart"></canvas>
            </div>
        </div>

        <!-- Recent Payments Table -->
        <div class="card border-0 shadow-sm rounded-4 p-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-receipt text-success me-2"></i>Recent Payments</h5>
                <a href="<?php echo baseUrl(); ?>/accountant/payments.php" class="small text-primary text-decoration-none fw-semibold">
                    View Registry <i class="fa-solid fa-arrow-right ms-1"></i>
                </a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Student</th>
                            <th>Amount</th>
                            <th>Method</th>
                            <th>Date</th>
                            <th class="text-end">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentPayments)): ?>
                            <tr>
                                <td colspan="5" class="text-center py-4 text-muted">
                                    <i class="fa-solid fa-cash-register fs-3 opacity-50 mb-2 d-block"></i>
                                    No payments registered yet.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recentPayments as $p): ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold text-dark"><?php echo e($p['student_name']); ?></div>
                                        <small class="text-muted"><?php echo e($p['student_id']); ?></small>
                                    </td>
                                    <td>
                                        <strong class="text-success"><?php echo $currency; ?> <?php echo number_format((float)$p['amount'], 2); ?></strong>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border"><?php echo e($p['payment_method']); ?></span>
                                    </td>
                                    <td>
                                        <small class="text-muted"><?php echo date('d M Y', strtotime($p['payment_date'])); ?></small>
                                    </td>
                                    <td class="text-end">
                                        <span class="badge-soft-emerald badge px-2 py-1 rounded-pill"><i class="fa-solid fa-check me-1"></i>Paid</span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Right: Financial Health, Events, Announcements -->
    <div class="col-lg-4">
        <!-- Financial Health Gauge Card -->
        <div class="card border-0 shadow-sm rounded-4 p-4 mb-4">
            <h5 class="fw-bold mb-3 text-dark"><i class="fa-solid fa-vault text-warning me-2"></i>Financial Recovery</h5>
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="text-muted small">Collection Rate</span>
                <span class="fw-bold text-dark"><?php echo $collectionRate; ?>%</span>
            </div>
            <div class="progress rounded-pill mb-3" style="height: 10px;">
                <div class="progress-bar bg-primary" role="progressbar" style="width: <?php echo min(100, $collectionRate); ?>%;"></div>
            </div>
            <div class="row g-2 text-center pt-2 border-top">
                <div class="col-6 border-end">
                    <small class="text-muted d-block">Collected</small>
                    <strong class="text-success"><?php echo $currency; ?> <?php echo number_format($totalCollected); ?></strong>
                </div>
                <div class="col-6">
                    <small class="text-muted d-block">Outstanding</small>
                    <strong class="text-danger"><?php echo $currency; ?> <?php echo number_format($pendingFees); ?></strong>
                </div>
            </div>
        </div>

        <!-- Upcoming Events with Calendar Badges -->
        <div class="card border-0 shadow-sm rounded-4 p-4 mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-calendar-day text-primary me-2"></i>School Events</h5>
                <a href="events.php" class="small text-primary text-decoration-none fw-semibold">View All</a>
            </div>
            <?php if (empty($upcomingEvents)): ?>
                <div class="text-center py-4 text-muted">
                    <i class="fa-solid fa-calendar-xmark fs-2 text-secondary opacity-50 mb-2 d-block"></i>
                    No upcoming events scheduled.
                </div>
            <?php else: ?>
                <div class="d-grid gap-3">
                    <?php foreach ($upcomingEvents as $ev): ?>
                        <?php 
                            $evTime = strtotime($ev['event_date']);
                            $evMonth = date('M', $evTime);
                            $evDay = date('d', $evTime);
                        ?>
                        <div class="d-flex align-items-center gap-3 p-2 rounded-3 bg-light border border-opacity-50">
                            <div class="event-calendar-badge">
                                <div class="event-cal-month"><?php echo $evMonth; ?></div>
                                <div class="event-cal-day"><?php echo $evDay; ?></div>
                            </div>
                            <div class="min-w-0 flex-grow-1">
                                <div class="fw-bold text-dark text-truncate small"><?php echo e($ev['title']); ?></div>
                                <small class="text-muted d-block text-truncate"><?php echo !empty($ev['description']) ? e($ev['description']) : date('l, F j', $evTime); ?></small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Announcements Board -->
        <div class="card border-0 shadow-sm rounded-4 p-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-bullhorn text-danger me-2"></i>Notice Board</h5>
                <a href="announcements.php" class="small text-primary text-decoration-none fw-semibold">New Notice</a>
            </div>
            <?php if (empty($announcements)): ?>
                <div class="text-center py-4 text-muted">
                    <i class="fa-solid fa-bell-slash fs-2 text-secondary opacity-50 mb-2 d-block"></i>
                    No announcements published.
                </div>
            <?php else: ?>
                <div class="d-grid gap-3">
                    <?php foreach ($announcements as $an): ?>
                        <div class="border-bottom pb-2">
                            <strong class="text-dark d-block small mb-1"><?php echo e($an['title']); ?></strong>
                            <div class="d-flex justify-content-between align-items-center">
                                <small class="text-muted"><i class="fa-solid fa-user-pen me-1"></i><?php echo e($an['author_name']); ?></small>
                                <small class="text-muted"><?php echo date('M d', strtotime($an['created_at'])); ?></small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- 5. Interactive Password Salt & Pepper Cryptographic Pipeline (Table Form) -->
<div class="card border-0 shadow-sm rounded-4 p-4 mb-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 pb-2 border-bottom">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <span class="badge bg-warning text-dark fw-bold px-2 py-1"><i class="fa-solid fa-graduation-cap me-1"></i> Assignment Mode</span>
                <span class="badge bg-info text-dark fw-bold px-2 py-1"><i class="fa-solid fa-shield-halved me-1"></i> Cryptography Pipeline</span>
            </div>
            <h5 class="fw-bold mb-1 text-dark"><i class="fa-solid fa-table-list text-primary me-2"></i>Password Salting &amp; Peppering Cryptographic Architecture</h5>
            <small class="text-muted">Live inspection showing how plaintext passwords undergo HMAC-SHA256 peppering, per-user dynamic salting, and Bcrypt stretching</small>
        </div>
        <a href="security_demo.php" class="btn btn-outline-primary btn-sm px-3 shadow-sm bg-white">
            <i class="fa-solid fa-sliders me-1"></i> Advanced Security Simulator
        </a>
    </div>

    <!-- Live Cryptography Table -->
    <div class="table-responsive mb-4">
        <table class="table table-bordered table-hover align-middle mb-0">
            <thead class="table-dark">
                <tr>
                    <th style="width: 14%;"><i class="fa-solid fa-layer-group me-1"></i> Security Stage</th>
                    <th style="width: 18%;"><i class="fa-solid fa-tag me-1"></i> Component Name</th>
                    <th style="width: 46%;"><i class="fa-solid fa-code me-1"></i> Actual Value / Output String</th>
                    <th style="width: 22%;"><i class="fa-solid fa-circle-info me-1"></i> Cryptographic Role &amp; Location</th>
                </tr>
            </thead>
            <tbody>
                <!-- Row 1: Actual Plaintext Password -->
                <tr>
                    <td><span class="badge bg-secondary px-2 py-1 fw-bold">Stage 0</span></td>
                    <td class="fw-bold text-dark">
                        <i class="fa-solid fa-key text-danger me-1"></i> Actual Password
                    </td>
                    <td>
                        <span class="font-monospace fw-bold text-danger fs-6 bg-light px-2 py-1 rounded border d-inline-block">
                            <?php echo htmlspecialchars($dashSamplePw); ?>
                        </span>
                        <small class="text-muted ms-2">(Plaintext entered by user)</small>
                    </td>
                    <td>
                        <span class="badge bg-danger-subtle text-danger border border-danger">Memory Only</span>
                        <small class="d-block text-muted mt-1">Never stored on disk or written to the database.</small>
                    </td>
                </tr>

                <!-- Row 2: Application Secret Pepper -->
                <tr class="table-info bg-opacity-10">
                    <td><span class="badge bg-info text-dark px-2 py-1 fw-bold">Stage 1</span></td>
                    <td class="fw-bold text-dark">
                        <i class="fa-solid fa-pepper-hot text-danger me-1"></i> Secret Pepper
                    </td>
                    <td>
                        <div class="font-monospace small text-secondary bg-white p-2 rounded border text-break">
                            <?php echo htmlspecialchars($dashPepper); ?>
                        </div>
                        <small class="text-muted">Length: 64 hexadecimal characters (256-bit entropy)</small>
                    </td>
                    <td>
                        <span class="badge bg-info-subtle text-info-emphasis border border-info">App Environment Only</span>
                        <small class="d-block text-muted mt-1">Stored in server env; protected against SQL database dumps.</small>
                    </td>
                </tr>

                <!-- Row 3: HMAC-SHA256 Peppered Hash -->
                <tr>
                    <td><span class="badge bg-info text-dark px-2 py-1 fw-bold">Stage 2</span></td>
                    <td class="fw-bold text-dark">
                        <i class="fa-solid fa-blender text-primary me-1"></i> Peppered HMAC-SHA256
                    </td>
                    <td>
                        <div class="font-monospace small text-primary fw-semibold bg-light p-2 rounded border text-break">
                            <?php echo htmlspecialchars($dashSampleHmac); ?>
                        </div>
                        <small class="text-muted"><code>hash_hmac('sha256', password, PEPPER)</code> &bull; Normalizes 72-byte Bcrypt limit</small>
                    </td>
                    <td>
                        <span class="badge bg-primary-subtle text-primary border border-primary">Pre-Hash Buffer</span>
                        <small class="d-block text-muted mt-1">Intermediate digest feeding into Blowfish key expansion.</small>
                    </td>
                </tr>

                <!-- Row 4: Unique Cryptographic Salt -->
                <tr class="table-warning bg-opacity-10">
                    <td><span class="badge bg-warning text-dark px-2 py-1 fw-bold">Stage 3</span></td>
                    <td class="fw-bold text-dark">
                        <i class="fa-solid fa-cube text-warning me-1"></i> Dynamic Cryptographic Salt
                    </td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <span class="font-monospace fw-bold text-dark bg-warning bg-opacity-25 p-2 rounded border border-warning text-break fs-6">
                                <?php echo htmlspecialchars($dashSalt1); ?>
                            </span>
                            <span class="badge bg-warning text-dark border">22 Base64 Characters</span>
                        </div>
                        <small class="text-muted">Generated freshly by CSPRNG (<code>random_bytes(16)</code>) per user/hash</small>
                    </td>
                    <td>
                        <span class="badge bg-warning-subtle text-dark border border-warning">Embedded in Hash</span>
                        <small class="d-block text-muted mt-1">Neutralizes pre-computed Rainbow Tables and dictionary attacks.</small>
                    </td>
                </tr>

                <!-- Row 5: Final Bcrypt Stored Hash -->
                <tr class="table-success bg-opacity-10">
                    <td><span class="badge bg-success px-2 py-1 fw-bold">Stage 4</span></td>
                    <td class="fw-bold text-dark">
                        <i class="fa-solid fa-lock text-success me-1"></i> Final Stored Hash
                    </td>
                    <td>
                        <div class="font-monospace small text-success fw-bold bg-white p-2 rounded border border-success text-break fs-6 mb-2">
                            <?php echo htmlspecialchars($dashSampleHash1); ?>
                        </div>
                        <!-- Anatomy of final string -->
                        <div class="d-flex flex-wrap gap-1 font-monospace small">
                            <span class="badge bg-dark" title="Algorithm">$2y$</span>
                            <span class="badge bg-primary" title="Cost Factor">10$</span>
                            <span class="badge bg-warning text-dark" title="Salt"><?php echo htmlspecialchars($dashSalt1); ?></span>
                            <span class="badge bg-success" title="Checksum"><?php echo htmlspecialchars(substr($dashSampleHash1, 29)); ?></span>
                        </div>
                    </td>
                    <td>
                        <span class="badge bg-success-subtle text-success border border-success">Saved in MySQL</span>
                        <small class="d-block text-muted mt-1">Column: <code>users.password_hash</code> (60 chars)</small>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Live Rainbow Table Immunity Proof -->
    <div class="p-3 bg-light rounded-3 border">
        <div class="d-flex align-items-center justify-content-between mb-2">
            <span class="fw-bold text-dark small"><i class="fa-solid fa-shield-virus text-warning me-1"></i> Rainbow Table Defense Proof (Same Password, 2 Different Salts &amp; Hashes):</span>
            <span class="badge bg-dark font-monospace">Password = "<?php echo htmlspecialchars($dashSamplePw); ?>"</span>
        </div>
        <div class="row g-2 font-monospace small">
            <div class="col-md-6">
                <div class="p-2 bg-white rounded border">
                    <span class="text-muted fw-bold d-block mb-1"><i class="fa-solid fa-fingerprint text-primary me-1"></i> Hash #1 (Salt A: <code><?php echo htmlspecialchars($dashSalt1); ?></code>):</span>
                    <span class="text-primary text-break"><?php echo htmlspecialchars($dashSampleHash1); ?></span>
                </div>
            </div>
            <div class="col-md-6">
                <div class="p-2 bg-white rounded border">
                    <span class="text-muted fw-bold d-block mb-1"><i class="fa-solid fa-fingerprint text-danger me-1"></i> Hash #2 (Salt B: <code><?php echo htmlspecialchars($dashSalt2); ?></code>):</span>
                    <span class="text-danger text-break"><?php echo htmlspecialchars($dashSampleHash2); ?></span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 6. Real-Time Activity Log Table -->
<div class="card border-0 shadow-sm rounded-4 p-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="d-flex align-items-center gap-2">
            <h5 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-bolt text-warning me-2"></i>Live System Activity</h5>
            <span class="badge-soft-emerald badge px-2 py-1 rounded-pill">
                <span class="pulse-dot me-1"></span> LIVE MONITOR
            </span>
        </div>
        <small class="text-muted">Auto-refreshes every 5 seconds</small>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>User</th>
                    <th>Action</th>
                    <th>Details</th>
                    <th class="text-end">Timestamp</th>
                </tr>
            </thead>
            <tbody id="activityLogTable">
                <?php foreach ($recentActivity as $act): ?>
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center fw-bold" style="width: 32px; height: 32px; font-size: 0.8rem;">
                                    <?php echo strtoupper(substr($act['name'] ?? 'S', 0, 1)); ?>
                                </div>
                                <span class="fw-semibold text-dark"><?php echo e($act['name'] ?? 'System Core'); ?></span>
                            </div>
                        </td>
                        <td><span class="badge bg-light text-dark border"><?php echo e($act['action']); ?></span></td>
                        <td><small class="text-muted"><?php echo e($act['details'] ?? '-'); ?></small></td>
                        <td class="text-end"><small class="text-muted"><?php echo date('d M Y, H:i', strtotime($act['created_at'])); ?></small></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Chart.js and Live Polling -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    const ctx = document.getElementById('overviewChart');
    if (ctx) {
        const labels = <?php echo json_encode($monthlyLabels); ?>;
        const revData = <?php echo json_encode($monthlyRevenue); ?>;
        const attData = <?php echo json_encode($monthlyAtt); ?>;

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Revenue (<?php echo $currency; ?>)',
                        data: revData,
                        borderColor: '#1d4ed8',
                        backgroundColor: 'rgba(29, 78, 216, 0.08)',
                        fill: true,
                        tension: 0.35,
                        pointBackgroundColor: '#1d4ed8',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 5,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Attendance Count',
                        data: attData,
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.04)',
                        fill: true,
                        tension: 0.35,
                        pointBackgroundColor: '#10b981',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 5,
                        yAxisID: 'y1'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: '#0f172a',
                        padding: 12,
                        cornerRadius: 10,
                        titleFont: { size: 13, weight: 'bold' }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false }
                    },
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        grid: { color: 'rgba(226, 232, 240, 0.6)' },
                        ticks: {
                            callback: function(value) { return '<?php echo $currency; ?> ' + value.toLocaleString(); }
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        grid: { drawOnChartArea: false },
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });
    }

    // Live Activity Log Polling
    function updateActivityLog() {
        fetch('<?php echo baseUrl(); ?>/api/activity-logs.php')
            .then(res => res.json())
            .then(data => {
                if (data.success && data.activities) {
                    const tbody = document.getElementById('activityLogTable');
                    if (tbody) {
                        let html = '';
                        data.activities.forEach(act => {
                            const initial = (act.user || 'S').charAt(0).toUpperCase();
                            html += `
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center fw-bold" style="width: 32px; height: 32px; font-size: 0.8rem;">
                                                ${initial}
                                            </div>
                                            <span class="fw-semibold text-dark">${escapeHtml(act.user)}</span>
                                        </div>
                                    </td>
                                    <td><span class="badge bg-light text-dark border">${escapeHtml(act.action)}</span></td>
                                    <td><small class="text-muted">${escapeHtml(act.details || '-')}</small></td>
                                    <td class="text-end"><small class="text-muted">${escapeHtml(act.time)}</small></td>
                                </tr>
                            `;
                        });
                        tbody.innerHTML = html;
                    }
                }
            })
            .catch(err => console.log('Activity log update error:', err));
    }

    function escapeHtml(text) {
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return text ? String(text).replace(/[&<>"']/g, m => map[m]) : '';
    }

    setInterval(updateActivityLog, 5000);
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
