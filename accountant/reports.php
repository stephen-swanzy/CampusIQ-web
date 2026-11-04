<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin(['accountant', 'super_admin', 'school_admin']);
$db = getDb();
$user = currentUser();
$settings = getSchoolSettings();
$currency = $settings['currency'] ?? 'GH₵';

// Gross Financial Metrics
$totalBilled = (float)$db->query('SELECT COALESCE(SUM(amount), 0) FROM fees')->fetchColumn();
$totalCollected = (float)$db->query('SELECT COALESCE(SUM(amount), 0) FROM payments')->fetchColumn();
$totalOutstanding = max(0, $totalBilled - $totalCollected);
$collectionRate = ($totalBilled > 0) ? round(($totalCollected / $totalBilled) * 100, 1) : 0.0;

// Class by Class Breakdown
$classesData = $db->query('
    SELECT c.id, c.name as class_name,
           COUNT(DISTINCT s.id) as student_count,
           COALESCE((SELECT SUM(f.amount) FROM fees f JOIN students st ON st.id = f.student_id WHERE st.class_id = c.id), 0) as class_billed,
           COALESCE((SELECT SUM(p.amount) FROM payments p JOIN students st ON st.id = p.student_id WHERE st.class_id = c.id), 0) as class_paid
    FROM classes c
    LEFT JOIN students s ON s.class_id = c.id AND s.status = "active"
    GROUP BY c.id
    ORDER BY c.name ASC
')->fetchAll();

// Payment Channels Breakdown
$channelsData = $db->query('
    SELECT payment_method, COUNT(*) as txn_count, SUM(amount) as channel_total
    FROM payments
    GROUP BY payment_method
    ORDER BY channel_total DESC
')->fetchAll();

// Outstanding Debtors List
$debtors = $db->query('
    SELECT s.id, s.student_id as student_code, u.name as student_name, c.name as class_name,
           pu.name as parent_name, pu.email as parent_email,
           COALESCE((SELECT SUM(amount) FROM fees WHERE student_id = s.id), 0) as stu_billed,
           COALESCE((SELECT SUM(amount) FROM payments WHERE student_id = s.id), 0) as stu_paid
    FROM students s
    JOIN users u ON u.id = s.user_id
    LEFT JOIN classes c ON c.id = s.class_id
    LEFT JOIN parents p ON p.id = s.parent_id
    LEFT JOIN users pu ON pu.id = p.user_id
    WHERE s.status = "active"
    HAVING (stu_billed - stu_paid) > 0
    ORDER BY (stu_billed - stu_paid) DESC
')->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-3">
    <div>
        <p class="text-primary fw-semibold mb-1"><i class="fa-solid fa-chart-column me-2"></i>Accounts Analytics</p>
        <h2 class="fw-bold mb-0">Financial & Fee Reports</h2>
        <small class="text-muted">Institutional collection metrics, revenue streams, and outstanding debtors audit.</small>
    </div>
    <div>
        <button type="button" class="btn btn-outline-secondary" onclick="window.print();">
            <i class="fa-solid fa-print me-2"></i>Print Financial Report
        </button>
    </div>
</div>

<!-- Key Financial Indicators -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card p-3 border-0 shadow-sm d-flex flex-row align-items-center gap-3">
            <div class="rounded-3 p-3 bg-primary bg-opacity-10 text-primary fs-3">
                <i class="fa-solid fa-file-invoice-dollar"></i>
            </div>
            <div>
                <span class="text-muted small fw-semibold">Gross Fees Billed</span>
                <h4 class="fw-bold mb-0 text-dark"><?php echo $currency; ?> <?php echo number_format($totalBilled, 2); ?></h4>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card p-3 border-0 shadow-sm d-flex flex-row align-items-center gap-3">
            <div class="rounded-3 p-3 bg-success bg-opacity-10 text-success fs-3">
                <i class="fa-solid fa-circle-check"></i>
            </div>
            <div>
                <span class="text-muted small fw-semibold">Total Revenue Collected</span>
                <h4 class="fw-bold mb-0 text-dark"><?php echo $currency; ?> <?php echo number_format($totalCollected, 2); ?></h4>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card p-3 border-0 shadow-sm d-flex flex-row align-items-center gap-3">
            <div class="rounded-3 p-3 bg-danger bg-opacity-10 text-danger fs-3">
                <i class="fa-solid fa-hand-holding-hand"></i>
            </div>
            <div>
                <span class="text-muted small fw-semibold">Outstanding Debt</span>
                <h4 class="fw-bold mb-0 text-danger"><?php echo $currency; ?> <?php echo number_format($totalOutstanding, 2); ?></h4>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card p-3 border-0 shadow-sm d-flex flex-row align-items-center gap-3">
            <div class="rounded-3 p-3 bg-info bg-opacity-10 text-info fs-3">
                <i class="fa-solid fa-percent"></i>
            </div>
            <div>
                <span class="text-muted small fw-semibold">Collection Rate</span>
                <h4 class="fw-bold mb-0 text-dark"><?php echo $collectionRate; ?>%</h4>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <!-- Class by Class Fee Performance -->
    <div class="col-lg-8">
        <div class="card p-0 border-0 shadow-sm overflow-hidden h-100">
            <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                <h5 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-school text-primary me-2"></i>Fee Collection by Class</h5>
                <span class="badge bg-light text-secondary"><?php echo count($classesData); ?> Classes</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Class</th>
                            <th>Students</th>
                            <th>Total Billed</th>
                            <th>Collected</th>
                            <th>Balance Due</th>
                            <th>Collection %</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($classesData as $cd): 
                            $b = (float)$cd['class_billed'];
                            $p = (float)$cd['class_paid'];
                            $bal = max(0, $b - $p);
                            $rate = ($b > 0) ? round(($p / $b) * 100, 1) : 0;
                        ?>
                            <tr>
                                <td><strong class="text-dark"><?php echo e($cd['class_name']); ?></strong></td>
                                <td><?php echo (int)$cd['student_count']; ?></td>
                                <td><?php echo $currency; ?> <?php echo number_format($b, 2); ?></td>
                                <td class="text-success fw-semibold"><?php echo $currency; ?> <?php echo number_format($p, 2); ?></td>
                                <td class="<?php echo ($bal > 0) ? 'text-danger fw-semibold' : 'text-muted'; ?>"><?php echo $currency; ?> <?php echo number_format($bal, 2); ?></td>
                                <td style="min-width: 130px;">
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="progress flex-grow-1" style="height: 6px;">
                                            <div class="progress-bar bg-success" style="width: <?php echo min(100, $rate); ?>%"></div>
                                        </div>
                                        <span class="small text-muted"><?php echo $rate; ?>%</span>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Revenue by Payment Channel -->
    <div class="col-lg-4">
        <div class="card p-4 border-0 shadow-sm h-100">
            <h5 class="fw-bold mb-3 text-dark"><i class="fa-solid fa-wallet text-primary me-2"></i>Revenue by Channel</h5>
            <?php if (empty($channelsData)): ?>
                <div class="text-center py-4 text-muted">No payments recorded.</div>
            <?php else: ?>
                <div class="d-flex flex-column gap-3">
                    <?php foreach ($channelsData as $ch): 
                        $pct = ($totalCollected > 0) ? round(((float)$ch['channel_total'] / $totalCollected) * 100, 1) : 0;
                    ?>
                        <div class="p-3 bg-light rounded-3 border">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <strong class="text-dark"><?php echo e($ch['payment_method']); ?></strong>
                                <span class="badge bg-primary"><?php echo $pct; ?>%</span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center small text-muted">
                                <span><?php echo (int)$ch['txn_count']; ?> Transaction(s)</span>
                                <strong class="text-success"><?php echo $currency; ?> <?php echo number_format((float)$ch['channel_total'], 2); ?></strong>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Outstanding Debtors Table -->
<div class="card p-0 border-0 shadow-sm overflow-hidden">
    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
        <div>
            <h5 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-hand-holding-dollar text-danger me-2"></i>Outstanding Debtors Roster</h5>
            <small class="text-muted">Students with pending fee balances requiring follow-up</small>
        </div>
        <span class="badge bg-danger text-white"><?php echo count($debtors); ?> Debtor(s)</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Student Name</th>
                    <th>Student ID</th>
                    <th>Class</th>
                    <th>Guardian / Parent</th>
                    <th>Total Billed</th>
                    <th>Total Paid</th>
                    <th>Outstanding Debt</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($debtors)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-5 text-muted">
                            <i class="fa-solid fa-circle-check fs-1 text-success mb-2 d-block"></i>
                            All student fees are fully paid! No outstanding balances detected.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($debtors as $d): 
                        $debAmt = (float)$d['stu_billed'] - (float)$d['stu_paid'];
                    ?>
                        <tr>
                            <td><strong class="text-dark"><?php echo e($d['student_name']); ?></strong></td>
                            <td><span class="badge bg-light text-secondary border"><?php echo e($d['student_code']); ?></span></td>
                            <td><span class="badge bg-secondary bg-opacity-10 text-secondary"><?php echo e($d['class_name'] ?? 'N/A'); ?></span></td>
                            <td>
                                <div><?php echo e($d['parent_name'] ?? 'Not Specified'); ?></div>
                                <small class="text-muted"><?php echo e($d['parent_email'] ?? ''); ?></small>
                            </td>
                            <td><?php echo $currency; ?> <?php echo number_format((float)$d['stu_billed'], 2); ?></td>
                            <td class="text-success"><?php echo $currency; ?> <?php echo number_format((float)$d['stu_paid'], 2); ?></td>
                            <td><strong class="text-danger fs-6"><?php echo $currency; ?> <?php echo number_format($debAmt, 2); ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
