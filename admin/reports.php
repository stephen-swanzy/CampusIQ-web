<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin(['super_admin', 'school_admin']);
$db = getDb();
$user = currentUser();
$settings = getSchoolSettings();
$currency = $settings['currency'] ?? 'GH₵';

$activeTab = $_GET['tab'] ?? 'academic';

// Fetch classes, exams, subjects
$classes = $db->query('SELECT id, name FROM classes ORDER BY name ASC')->fetchAll();
$exams = $db->query('SELECT id, name FROM examinations ORDER BY id DESC')->fetchAll();
$subjects = $db->query('SELECT id, name FROM subjects ORDER BY name ASC')->fetchAll();

// 1. Academic Data
$filterExamId = (int)($_GET['exam_id'] ?? ($exams[0]['id'] ?? 0));
$filterClassId = (int)($_GET['class_id'] ?? 0);
$filterSubjectId = (int)($_GET['subject_id'] ?? 0);

$academicSql = "
    SELECT r.*, e.name as exam_name, s.name as subject_name, st.student_id as student_code, u.name as student_name, c.name as class_name
    FROM results r
    JOIN examinations e ON e.id = r.exam_id
    JOIN subjects s ON s.id = r.subject_id
    JOIN students st ON st.id = r.student_id
    JOIN users u ON u.id = st.user_id
    LEFT JOIN classes c ON c.id = st.class_id
    WHERE 1=1
";
$acadParams = [];
if ($filterExamId > 0) {
    $academicSql .= " AND r.exam_id = :eid";
    $acadParams['eid'] = $filterExamId;
}
if ($filterClassId > 0) {
    $academicSql .= " AND st.class_id = :cid";
    $acadParams['cid'] = $filterClassId;
}
if ($filterSubjectId > 0) {
    $academicSql .= " AND r.subject_id = :sid";
    $acadParams['sid'] = $filterSubjectId;
}
$academicSql .= " ORDER BY r.total_score DESC";
$acadStmt = $db->prepare($academicSql);
$acadStmt->execute($acadParams);
$academicResults = $acadStmt->fetchAll();

$gradeDist = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'F' => 0];
$totalScoresSum = 0;
foreach ($academicResults as $ar) {
    $g = strtoupper(trim($ar['grade'] ?? 'F'));
    if (isset($gradeDist[$g])) $gradeDist[$g]++;
    else $gradeDist['F']++;
    $totalScoresSum += (float)$ar['total_score'];
}
$acadCount = count($academicResults);
$avgExamScore = ($acadCount > 0) ? round($totalScoresSum / $acadCount, 1) : 0.0;
$passCount = ($gradeDist['A'] + $gradeDist['B'] + $gradeDist['C'] + $gradeDist['D']);
$passRate = ($acadCount > 0) ? round(($passCount / $acadCount) * 100, 1) : 0.0;

// 2. Attendance Data
$attMonth = $_GET['att_month'] ?? date('Y-m');
$attClassId = (int)($_GET['att_class_id'] ?? 0);

$attSql = "
    SELECT a.*, st.student_id as student_code, u.name as student_name, c.name as class_name
    FROM attendance a
    JOIN students st ON st.id = a.student_id
    JOIN users u ON u.id = st.user_id
    LEFT JOIN classes c ON c.id = a.class_id
    WHERE a.date LIKE :mth
";
$attParams = ['mth' => $attMonth . '%'];
if ($attClassId > 0) {
    $attSql .= " AND a.class_id = :cid";
    $attParams['cid'] = $attClassId;
}
$attSql .= " ORDER BY a.date DESC";
$attStmt = $db->prepare($attSql);
$attStmt->execute($attParams);
$attendanceLogs = $attStmt->fetchAll();

$attTotal = count($attendanceLogs);
$attPresent = count(array_filter($attendanceLogs, fn($a) => in_array($a['status'], ['Present', 'Late'])));
$attAbsent = count(array_filter($attendanceLogs, fn($a) => $a['status'] === 'Absent'));
$attRate = ($attTotal > 0) ? round(($attPresent / $attTotal) * 100, 1) : 100.0;

// Chronic absentees
$absentees = $db->query("
    SELECT st.id, st.student_id as student_code, u.name, c.name as class_name, COUNT(*) as absent_count
    FROM attendance a
    JOIN students st ON st.id = a.student_id
    JOIN users u ON u.id = st.user_id
    LEFT JOIN classes c ON c.id = a.class_id
    WHERE a.status = 'Absent'
    GROUP BY st.id
    HAVING absent_count >= 2
    ORDER BY absent_count DESC
")->fetchAll();

// 3. Financial Data
$finTotalBilled = (float)$db->query('SELECT COALESCE(SUM(amount), 0) FROM fees')->fetchColumn();
$finTotalPaid = (float)$db->query('SELECT COALESCE(SUM(amount), 0) FROM payments')->fetchColumn();
$finBalance = max(0, $finTotalBilled - $finTotalPaid);
$finCollectionRate = ($finTotalBilled > 0) ? round(($finTotalPaid / $finTotalBilled) * 100, 1) : 0.0;

$finCatData = $db->query('
    SELECT fc.name as category_name, SUM(f.amount) as billed_amount
    FROM fees f
    JOIN fee_categories fc ON fc.id = f.category_id
    GROUP BY fc.id
    ORDER BY billed_amount DESC
')->fetchAll();

// 4. Enrollment Data
$totalStudents = (int)$db->query('SELECT COUNT(*) FROM students WHERE status = "active"')->fetchColumn();
$maleCount = (int)$db->query('SELECT COUNT(*) FROM students WHERE status = "active" AND LOWER(gender) = "male"')->fetchColumn();
$femaleCount = (int)$db->query('SELECT COUNT(*) FROM students WHERE status = "active" AND LOWER(gender) = "female"')->fetchColumn();

$classSizes = $db->query('
    SELECT c.name as class_name, COUNT(s.id) as count
    FROM classes c
    LEFT JOIN students s ON s.class_id = c.id AND s.status = "active"
    GROUP BY c.id
    ORDER BY count DESC
')->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-3">
    <div>
        <p class="text-primary fw-semibold mb-1"><i class="fa-solid fa-chart-line me-2"></i>Institutional Analytics</p>
        <h2 class="fw-bold mb-0">Administrative Reports Suite</h2>
        <small class="text-muted">High-level institutional insights across academics, attendance, finances, and enrollment.</small>
    </div>
    <div>
        <button type="button" class="btn btn-outline-secondary" onclick="window.print();">
            <i class="fa-solid fa-print me-2"></i>Print Report Sheet
        </button>
    </div>
</div>

<!-- Tabs Navigation -->
<ul class="nav nav-pills mb-4 gap-2" role="tablist">
    <li class="nav-item">
        <a class="nav-link <?php echo ($activeTab === 'academic') ? 'active' : ''; ?> px-4 fw-semibold" href="reports.php?tab=academic">
            <i class="fa-solid fa-graduation-cap me-2"></i>Academic Grades
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo ($activeTab === 'attendance') ? 'active' : ''; ?> px-4 fw-semibold" href="reports.php?tab=attendance">
            <i class="fa-solid fa-calendar-check me-2"></i>Attendance
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo ($activeTab === 'finance') ? 'active' : ''; ?> px-4 fw-semibold" href="reports.php?tab=finance">
            <i class="fa-solid fa-wallet me-2"></i>Fee & Revenue
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo ($activeTab === 'enrollment') ? 'active' : ''; ?> px-4 fw-semibold" href="reports.php?tab=enrollment">
            <i class="fa-solid fa-users me-2"></i>Enrollment Demographics
        </a>
    </li>
</ul>

<?php if ($activeTab === 'academic'): ?>
    <!-- 1. ACADEMIC REPORTS -->
    <div class="card p-3 mb-4 border-0 shadow-sm">
        <form method="GET" class="row g-2 align-items-end">
            <input type="hidden" name="tab" value="academic">
            <div class="col-md-4">
                <label class="form-label small fw-bold text-muted mb-1">Examination Session</label>
                <select name="exam_id" class="form-select form-select-sm">
                    <?php foreach ($exams as $e): ?>
                        <option value="<?php echo $e['id']; ?>" <?php echo ($filterExamId === (int)$e['id']) ? 'selected' : ''; ?>>
                            <?php echo e($e['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-bold text-muted mb-1">Class</label>
                <select name="class_id" class="form-select form-select-sm">
                    <option value="">All Classes</option>
                    <?php foreach ($classes as $c): ?>
                        <option value="<?php echo $c['id']; ?>" <?php echo ($filterClassId === (int)$c['id']) ? 'selected' : ''; ?>>
                            <?php echo e($c['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-bold text-muted mb-1">Subject</label>
                <select name="subject_id" class="form-select form-select-sm">
                    <option value="">All Subjects</option>
                    <?php foreach ($subjects as $s): ?>
                        <option value="<?php echo $s['id']; ?>" <?php echo ($filterSubjectId === (int)$s['id']) ? 'selected' : ''; ?>>
                            <?php echo e($s['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fa-solid fa-filter me-1"></i>Filter</button>
                <a href="reports.php?tab=academic" class="btn btn-light btn-sm"><i class="fa-solid fa-rotate-left"></i></a>
            </div>
        </form>
    </div>

    <!-- Academic KPI Row -->
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="card p-3 border-0 shadow-sm d-flex flex-row align-items-center gap-3">
                <div class="rounded-3 p-3 bg-primary bg-opacity-10 text-primary fs-3"><i class="fa-solid fa-file-pen"></i></div>
                <div>
                    <span class="text-muted small fw-semibold">Graded Papers</span>
                    <h4 class="fw-bold mb-0 text-dark"><?php echo $acadCount; ?></h4>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card p-3 border-0 shadow-sm d-flex flex-row align-items-center gap-3">
                <div class="rounded-3 p-3 bg-success bg-opacity-10 text-success fs-3"><i class="fa-solid fa-percent"></i></div>
                <div>
                    <span class="text-muted small fw-semibold">Pass Rate (A–D)</span>
                    <h4 class="fw-bold mb-0 text-success"><?php echo $passRate; ?>%</h4>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card p-3 border-0 shadow-sm d-flex flex-row align-items-center gap-3">
                <div class="rounded-3 p-3 bg-info bg-opacity-10 text-info fs-3"><i class="fa-solid fa-calculator"></i></div>
                <div>
                    <span class="text-muted small fw-semibold">Average Score</span>
                    <h4 class="fw-bold mb-0 text-dark"><?php echo $avgExamScore; ?>%</h4>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card p-3 border-0 shadow-sm d-flex flex-row align-items-center gap-3">
                <div class="rounded-3 p-3 bg-warning bg-opacity-10 text-warning fs-3"><i class="fa-solid fa-trophy"></i></div>
                <div>
                    <span class="text-muted small fw-semibold">Distinctions (Grade A)</span>
                    <h4 class="fw-bold mb-0 text-dark"><?php echo $gradeDist['A']; ?></h4>
                </div>
            </div>
        </div>
    </div>

    <!-- Grade Distributions Bar -->
    <div class="card p-4 border-0 shadow-sm mb-4">
        <h5 class="fw-bold mb-3 text-dark"><i class="fa-solid fa-chart-bar text-primary me-2"></i>Grade Frequency Distribution</h5>
        <div class="row g-3 text-center">
            <?php foreach (['A' => 'success', 'B' => 'primary', 'C' => 'info', 'D' => 'warning', 'F' => 'danger'] as $grd => $col): 
                $cnt = $gradeDist[$grd] ?? 0;
                $pct = ($acadCount > 0) ? round(($cnt / $acadCount) * 100, 1) : 0;
            ?>
                <div class="col">
                    <div class="p-3 bg-light rounded-3 border">
                        <span class="badge bg-<?php echo $col; ?> fs-5 mb-2"><?php echo $grd; ?></span>
                        <h4 class="fw-bold mb-0"><?php echo $cnt; ?></h4>
                        <small class="text-muted"><?php echo $pct; ?>% of marks</small>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Academic Records Table -->
    <div class="card p-0 border-0 shadow-sm overflow-hidden">
        <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
            <h5 class="fw-bold mb-0 text-dark">Subject Performance Registry</h5>
            <span class="badge bg-light text-secondary"><?php echo $acadCount; ?> Record(s)</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Student</th>
                        <th>Class</th>
                        <th>Subject</th>
                        <th>Class Score (30%)</th>
                        <th>Exam Score (70%)</th>
                        <th>Total Score</th>
                        <th>Grade</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($academicResults)): ?>
                        <tr><td colspan="7" class="text-center py-5 text-muted">No academic records found for this filter.</td></tr>
                    <?php else: ?>
                        <?php foreach ($academicResults as $ar): ?>
                            <tr>
                                <td><strong><?php echo e($ar['student_name']); ?></strong> <small class="text-muted">(<?php echo e($ar['student_code']); ?>)</small></td>
                                <td><span class="badge bg-secondary bg-opacity-10 text-secondary"><?php echo e($ar['class_name'] ?? 'N/A'); ?></span></td>
                                <td><span class="badge bg-primary bg-opacity-10 text-primary"><?php echo e($ar['subject_name']); ?></span></td>
                                <td><?php echo number_format((float)$ar['class_score'], 1); ?></td>
                                <td><?php echo number_format((float)$ar['exam_score'], 1); ?></td>
                                <td><strong class="fs-6"><?php echo number_format((float)$ar['total_score'], 1); ?></strong></td>
                                <td><span class="badge bg-primary px-2 py-1 fs-6"><?php echo e($ar['grade']); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php elseif ($activeTab === 'attendance'): ?>
    <!-- 2. ATTENDANCE REPORTS -->
    <div class="card p-3 mb-4 border-0 shadow-sm">
        <form method="GET" class="row g-2 align-items-end">
            <input type="hidden" name="tab" value="attendance">
            <div class="col-md-5">
                <label class="form-label small fw-bold text-muted mb-1">Month Period</label>
                <input type="month" name="att_month" class="form-control form-control-sm" value="<?php echo e($attMonth); ?>">
            </div>
            <div class="col-md-5">
                <label class="form-label small fw-bold text-muted mb-1">Class</label>
                <select name="att_class_id" class="form-select form-select-sm">
                    <option value="">All Classes</option>
                    <?php foreach ($classes as $c): ?>
                        <option value="<?php echo $c['id']; ?>" <?php echo ($attClassId === (int)$c['id']) ? 'selected' : ''; ?>>
                            <?php echo e($c['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fa-solid fa-filter me-1"></i>Filter</button>
            </div>
        </form>
    </div>

    <!-- Attendance KPI Row -->
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="card p-3 border-0 shadow-sm d-flex flex-row align-items-center gap-3">
                <div class="rounded-3 p-3 bg-primary bg-opacity-10 text-primary fs-3"><i class="fa-solid fa-calendar-days"></i></div>
                <div>
                    <span class="text-muted small fw-semibold">Recorded Sessions</span>
                    <h4 class="fw-bold mb-0 text-dark"><?php echo $attTotal; ?></h4>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card p-3 border-0 shadow-sm d-flex flex-row align-items-center gap-3">
                <div class="rounded-3 p-3 bg-success bg-opacity-10 text-success fs-3"><i class="fa-solid fa-check"></i></div>
                <div>
                    <span class="text-muted small fw-semibold">Presence Count</span>
                    <h4 class="fw-bold mb-0 text-dark"><?php echo $attPresent; ?></h4>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card p-3 border-0 shadow-sm d-flex flex-row align-items-center gap-3">
                <div class="rounded-3 p-3 bg-danger bg-opacity-10 text-danger fs-3"><i class="fa-solid fa-xmark"></i></div>
                <div>
                    <span class="text-muted small fw-semibold">Absence Count</span>
                    <h4 class="fw-bold mb-0 text-danger"><?php echo $attAbsent; ?></h4>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card p-3 border-0 shadow-sm d-flex flex-row align-items-center gap-3">
                <div class="rounded-3 p-3 bg-info bg-opacity-10 text-info fs-3"><i class="fa-solid fa-percent"></i></div>
                <div>
                    <span class="text-muted small fw-semibold">Presence Rate</span>
                    <h4 class="fw-bold mb-0 text-dark"><?php echo $attRate; ?>%</h4>
                </div>
            </div>
        </div>
    </div>

    <!-- Chronic Absentee Alert Box -->
    <div class="card p-0 border-0 shadow-sm overflow-hidden mb-4">
        <div class="card-header bg-white py-3 border-bottom">
            <h5 class="fw-bold mb-0 text-danger"><i class="fa-solid fa-triangle-exclamation me-2"></i>Chronic Absenteeism Watchlist (&ge; 2 Absences)</h5>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Student</th>
                        <th>Student ID</th>
                        <th>Class</th>
                        <th>Total Absences</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($absentees)): ?>
                        <tr><td colspan="5" class="text-center py-4 text-muted">No chronic absentee patterns detected. High attendance!</td></tr>
                    <?php else: ?>
                        <?php foreach ($absentees as $ab): ?>
                            <tr>
                                <td><strong><?php echo e($ab['name']); ?></strong></td>
                                <td><span class="badge bg-light text-secondary border"><?php echo e($ab['student_code']); ?></span></td>
                                <td><span class="badge bg-secondary bg-opacity-10 text-secondary"><?php echo e($ab['class_name'] ?? 'N/A'); ?></span></td>
                                <td><strong class="text-danger fs-6"><?php echo (int)$ab['absent_count']; ?> Absences</strong></td>
                                <td><span class="badge bg-danger-subtle text-danger">Requires Contact</span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php elseif ($activeTab === 'finance'): ?>
    <!-- 3. FINANCIAL REPORTS -->
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="card p-3 border-0 shadow-sm d-flex flex-row align-items-center gap-3">
                <div class="rounded-3 p-3 bg-primary bg-opacity-10 text-primary fs-3"><i class="fa-solid fa-file-invoice-dollar"></i></div>
                <div>
                    <span class="text-muted small fw-semibold">Total Fees Billed</span>
                    <h4 class="fw-bold mb-0 text-dark"><?php echo $currency; ?> <?php echo number_format($finTotalBilled, 2); ?></h4>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card p-3 border-0 shadow-sm d-flex flex-row align-items-center gap-3">
                <div class="rounded-3 p-3 bg-success bg-opacity-10 text-success fs-3"><i class="fa-solid fa-circle-check"></i></div>
                <div>
                    <span class="text-muted small fw-semibold">Revenue Collected</span>
                    <h4 class="fw-bold mb-0 text-dark"><?php echo $currency; ?> <?php echo number_format($finTotalPaid, 2); ?></h4>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card p-3 border-0 shadow-sm d-flex flex-row align-items-center gap-3">
                <div class="rounded-3 p-3 bg-danger bg-opacity-10 text-danger fs-3"><i class="fa-solid fa-scale-balanced"></i></div>
                <div>
                    <span class="text-muted small fw-semibold">Outstanding Debt</span>
                    <h4 class="fw-bold mb-0 text-danger"><?php echo $currency; ?> <?php echo number_format($finBalance, 2); ?></h4>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card p-3 border-0 shadow-sm d-flex flex-row align-items-center gap-3">
                <div class="rounded-3 p-3 bg-info bg-opacity-10 text-info fs-3"><i class="fa-solid fa-percent"></i></div>
                <div>
                    <span class="text-muted small fw-semibold">Collection Rate</span>
                    <h4 class="fw-bold mb-0 text-dark"><?php echo $finCollectionRate; ?>%</h4>
                </div>
            </div>
        </div>
    </div>

    <!-- Category Breakdown Table -->
    <div class="card p-0 border-0 shadow-sm overflow-hidden mb-4">
        <div class="card-header bg-white py-3 border-bottom">
            <h5 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-tags text-primary me-2"></i>Billed Revenue by Fee Category</h5>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Category Name</th>
                        <th>Total Value Billed</th>
                        <th>Share of Budget</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($finCatData as $fcd): 
                        $pct = ($finTotalBilled > 0) ? round(((float)$fcd['billed_amount'] / $finTotalBilled) * 100, 1) : 0;
                    ?>
                        <tr>
                            <td><strong class="text-dark"><?php echo e($fcd['category_name']); ?></strong></td>
                            <td><strong class="text-primary"><?php echo $currency; ?> <?php echo number_format((float)$fcd['billed_amount'], 2); ?></strong></td>
                            <td style="min-width: 140px;">
                                <div class="d-flex align-items-center gap-2">
                                    <div class="progress flex-grow-1" style="height: 6px;">
                                        <div class="progress-bar bg-primary" style="width: <?php echo $pct; ?>%"></div>
                                    </div>
                                    <span class="small text-muted"><?php echo $pct; ?>%</span>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php elseif ($activeTab === 'enrollment'): ?>
    <!-- 4. ENROLLMENT & DEMOGRAPHICS -->
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="card p-3 border-0 shadow-sm d-flex flex-row align-items-center gap-3">
                <div class="rounded-3 p-3 bg-primary bg-opacity-10 text-primary fs-3"><i class="fa-solid fa-user-graduate"></i></div>
                <div>
                    <span class="text-muted small fw-semibold">Total Students</span>
                    <h4 class="fw-bold mb-0 text-dark"><?php echo $totalStudents; ?></h4>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card p-3 border-0 shadow-sm d-flex flex-row align-items-center gap-3">
                <div class="rounded-3 p-3 bg-info bg-opacity-10 text-info fs-3"><i class="fa-solid fa-mars"></i></div>
                <div>
                    <span class="text-muted small fw-semibold">Male Students</span>
                    <h4 class="fw-bold mb-0 text-dark"><?php echo $maleCount; ?></h4>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card p-3 border-0 shadow-sm d-flex flex-row align-items-center gap-3">
                <div class="rounded-3 p-3 bg-danger bg-opacity-10 text-danger fs-3"><i class="fa-solid fa-venus"></i></div>
                <div>
                    <span class="text-muted small fw-semibold">Female Students</span>
                    <h4 class="fw-bold mb-0 text-dark"><?php echo $femaleCount; ?></h4>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card p-3 border-0 shadow-sm d-flex flex-row align-items-center gap-3">
                <div class="rounded-3 p-3 bg-success bg-opacity-10 text-success fs-3"><i class="fa-solid fa-school"></i></div>
                <div>
                    <span class="text-muted small fw-semibold">Total Classes</span>
                    <h4 class="fw-bold mb-0 text-dark"><?php echo count($classes); ?></h4>
                </div>
            </div>
        </div>
    </div>

    <!-- Class Sizes Table -->
    <div class="card p-0 border-0 shadow-sm overflow-hidden">
        <div class="card-header bg-white py-3 border-bottom">
            <h5 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-users-line text-primary me-2"></i>Class Roster Distribution</h5>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Class Name</th>
                        <th>Enrolled Students</th>
                        <th>Class Capacity Share</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($classSizes as $cs): 
                        $pct = ($totalStudents > 0) ? round(((int)$cs['count'] / $totalStudents) * 100, 1) : 0;
                    ?>
                        <tr>
                            <td><strong class="text-dark"><?php echo e($cs['class_name']); ?></strong></td>
                            <td><strong class="fs-6 text-primary"><?php echo (int)$cs['count']; ?></strong></td>
                            <td style="min-width: 140px;">
                                <div class="d-flex align-items-center gap-2">
                                    <div class="progress flex-grow-1" style="height: 6px;">
                                        <div class="progress-bar bg-success" style="width: <?php echo $pct; ?>%"></div>
                                    </div>
                                    <span class="small text-muted"><?php echo $pct; ?>%</span>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
