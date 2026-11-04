<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin(['accountant']);
$db = getDb();
$user = currentUser();
$settings = getSchoolSettings();
$currency = $settings['currency'] ?? 'GH₵';

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

$totalBilled = (float)$db->query('SELECT COALESCE(SUM(amount), 0) FROM fees')->fetchColumn();
$totalPayments = (float)$db->query('SELECT COALESCE(SUM(amount), 0) FROM payments')->fetchColumn();
$totalOutstanding = max(0, $totalBilled - $totalPayments);
$invoiceCount = (int)$db->query('SELECT COUNT(*) FROM invoices')->fetchColumn();
$paymentsCount = (int)$db->query('SELECT COUNT(*) FROM payments')->fetchColumn();
$collectionRate = $totalBilled > 0 ? round(($totalPayments / $totalBilled) * 100) : 0;

// Breakdown by channel/method
$methodStats = $db->query('
    SELECT payment_method, COUNT(*) as total_txns, COALESCE(SUM(amount), 0) as total_amount
    FROM payments
    GROUP BY payment_method
')->fetchAll();

// Recent 5 payments
$recentPayments = $db->query('
    SELECT p.*, u.name as student_name, s.student_id as student_code, c.name as class_name
    FROM payments p
    JOIN students s ON s.id = p.student_id
    JOIN users u ON u.id = s.user_id
    LEFT JOIN classes c ON c.id = s.class_id
    ORDER BY p.payment_date DESC
    LIMIT 6
')->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<!-- 1. Accountant Hero Header -->
<div class="dash-hero">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                <span class="dash-chip primary">
                    <i class="fa-solid fa-coins"></i> Bursar & Accounts 💳
                </span>
                <span class="dash-chip purple">
                    <i class="fa-solid fa-building-columns"></i> Financial Administration
                </span>
                <span class="dash-chip amber">
                    <i class="fa-solid fa-calendar-check"></i> <?php echo e($academicYear); ?> • <?php echo e($term); ?>
                </span>
                <span class="dash-chip success">
                    <span class="pulse-dot me-1"></span> Accounts Reconciled ✨
                </span>
            </div>
            <h1 class="dash-hero-title"><?php echo $greeting; ?>, <?php echo e($user['name']); ?> <span class="wave-emoji">👋</span></h1>
            <p class="dash-hero-subtitle">Monitor institutional revenue collections, generate student invoices, register payments via Cash, Card, and Mobile Money, and produce audits.</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="invoices.php" class="btn btn-outline-primary px-3 shadow-sm bg-white">
                <i class="fa-solid fa-file-invoice me-2"></i>Issue Invoice
            </a>
            <a href="payments.php" class="btn btn-primary px-3 shadow-sm">
                <i class="fa-solid fa-hand-holding-dollar me-2"></i>Record Payment
            </a>
        </div>
    </div>
</div>

<!-- 2. Action Launcher Grid -->
<div class="action-launcher-grid">
    <a href="payments.php" class="action-tile">
        <div class="action-tile-icon bg-soft-emerald">
            <i class="fa-solid fa-money-bill-wave"></i>
        </div>
        <span class="action-tile-title">Record Payment</span>
        <span class="action-tile-sub">Cash / MoMo / Card</span>
    </a>
    <a href="invoices.php" class="action-tile">
        <div class="action-tile-icon bg-soft-blue">
            <i class="fa-solid fa-file-invoice-dollar"></i>
        </div>
        <span class="action-tile-title">Manage Invoices</span>
        <span class="action-tile-sub"><?php echo $invoiceCount; ?> Generated</span>
    </a>
    <a href="fees.php" class="action-tile">
        <div class="action-tile-icon bg-soft-amber">
            <i class="fa-solid fa-wallet"></i>
        </div>
        <span class="action-tile-title">Fee Categories</span>
        <span class="action-tile-sub">Tuition & Levies</span>
    </a>
    <a href="reports.php" class="action-tile">
        <div class="action-tile-icon bg-soft-cyan">
            <i class="fa-solid fa-chart-column"></i>
        </div>
        <span class="action-tile-title">Audit Reports</span>
        <span class="action-tile-sub">Debtors & Yield</span>
    </a>
    <a href="reports.php#debtors" class="action-tile">
        <div class="action-tile-icon bg-soft-rose">
            <i class="fa-solid fa-users-slash"></i>
        </div>
        <span class="action-tile-title">Debtors Watch</span>
        <span class="action-tile-sub">Overdue Accounts</span>
    </a>
    <a href="<?php echo baseUrl(); ?>/admin/settings.php" class="action-tile">
        <div class="action-tile-icon bg-soft-purple">
            <i class="fa-solid fa-sliders"></i>
        </div>
        <span class="action-tile-title">Currency Settings</span>
        <span class="action-tile-sub"><?php echo e($currency); ?> Base</span>
    </a>
</div>

<!-- 3. KPI Metrics 2.0 -->
<div class="kpi-row">
    <div class="kpi-card-v2">
        <div class="kpi-header">
            <span class="kpi-lbl mb-0">Total Tuition Billed</span>
            <div class="kpi-icon-wrap bg-soft-blue"><i class="fa-solid fa-receipt"></i></div>
        </div>
        <div class="kpi-val text-truncate" title="<?php echo $currency; ?> <?php echo number_format($totalBilled, 2); ?>">
            <?php echo $currency; ?> <?php echo number_format($totalBilled); ?>
        </div>
        <span class="kpi-badge badge-soft-blue"><i class="fa-solid fa-calculator me-1"></i>Official Assessment</span>
    </div>

    <div class="kpi-card-v2">
        <div class="kpi-header">
            <span class="kpi-lbl mb-0">Total Collected</span>
            <div class="kpi-icon-wrap bg-soft-emerald"><i class="fa-solid fa-hand-holding-dollar"></i></div>
        </div>
        <div class="kpi-val text-truncate" title="<?php echo $currency; ?> <?php echo number_format($totalPayments, 2); ?>">
            <?php echo $currency; ?> <?php echo number_format($totalPayments); ?>
        </div>
        <span class="kpi-badge badge-soft-emerald"><i class="fa-solid fa-circle-check me-1"></i><?php echo $collectionRate; ?>% Realized</span>
    </div>

    <div class="kpi-card-v2">
        <div class="kpi-header">
            <span class="kpi-lbl mb-0">Outstanding Balance</span>
            <div class="kpi-icon-wrap bg-soft-rose"><i class="fa-solid fa-scale-balanced"></i></div>
        </div>
        <div class="kpi-val text-truncate" title="<?php echo $currency; ?> <?php echo number_format($totalOutstanding, 2); ?>">
            <?php echo $currency; ?> <?php echo number_format($totalOutstanding); ?>
        </div>
        <span class="kpi-badge badge-soft-rose"><i class="fa-solid fa-clock me-1"></i>Pending Inflow</span>
    </div>

    <div class="kpi-card-v2">
        <div class="kpi-header">
            <span class="kpi-lbl mb-0">Transactions</span>
            <div class="kpi-icon-wrap bg-soft-purple"><i class="fa-solid fa-money-check-dollar"></i></div>
        </div>
        <div class="kpi-val"><?php echo number_format($paymentsCount); ?></div>
        <span class="kpi-badge badge-soft-purple"><i class="fa-solid fa-file-invoice me-1"></i><?php echo $invoiceCount; ?> Invoices Active</span>
    </div>
</div>

<!-- 4. Channel Breakdown & Recent Transactions Table -->
<div class="row g-4">
    <!-- Left: Transactions Table -->
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm rounded-4 p-4 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold mb-0 text-dark">
                    <i class="fa-solid fa-receipt text-primary me-2"></i>Latest Received Payments
                </h5>
                <a href="payments.php" class="small text-primary text-decoration-none fw-semibold">
                    View Registry <i class="fa-solid fa-arrow-right ms-1"></i>
                </a>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Ref #</th>
                            <th>Student</th>
                            <th>Amount</th>
                            <th>Channel</th>
                            <th>Date & Time</th>
                            <th class="text-end">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentPayments)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-5 text-muted">
                                    <i class="fa-solid fa-cash-register fs-1 text-secondary opacity-50 mb-3 d-block"></i>
                                    <h6 class="fw-bold">No Payments Recorded Yet</h6>
                                    <p class="small">Click <strong>Record Payment</strong> above to log your first fee transaction.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recentPayments as $rp): ?>
                                <tr>
                                    <td>
                                        <strong class="font-monospace text-primary small">
                                            <?php echo e($rp['reference_number'] ?: 'TXN-'.$rp['id']); ?>
                                        </strong>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-dark small"><?php echo e($rp['student_name']); ?></div>
                                        <small class="text-muted"><?php echo e($rp['class_name'] ?? 'N/A'); ?> • <?php echo e($rp['student_code']); ?></small>
                                    </td>
                                    <td>
                                        <strong class="text-success"><?php echo $currency; ?> <?php echo number_format((float)$rp['amount'], 2); ?></strong>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border small"><?php echo e($rp['payment_method']); ?></span>
                                    </td>
                                    <td>
                                        <small class="text-muted"><?php echo date('d M Y, h:i A', strtotime($rp['payment_date'])); ?></small>
                                    </td>
                                    <td class="text-end">
                                        <span class="badge-soft-emerald badge px-2 py-1 rounded-pill small">
                                            <i class="fa-solid fa-check me-1"></i>Settled
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Right: Revenue Collection Channels -->
    <div class="col-lg-4">
        <!-- Collection Channel Share -->
        <div class="card border-0 shadow-sm rounded-4 p-4 mb-4">
            <h5 class="fw-bold mb-3 text-dark">
                <i class="fa-solid fa-credit-card text-success me-2"></i>Revenue Channels
            </h5>
            <?php if (empty($methodStats)): ?>
                <div class="text-center py-4 text-muted small">No payment methods recorded.</div>
            <?php else: ?>
                <div class="d-grid gap-3">
                    <?php foreach ($methodStats as $ms): ?>
                        <?php 
                            $mAmount = (float)$ms['total_amount'];
                            $mShare = $totalPayments > 0 ? round(($mAmount / $totalPayments) * 100) : 0;
                        ?>
                        <div class="p-3 rounded-3 bg-light border border-opacity-75">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <strong class="text-dark small"><i class="fa-solid fa-money-check text-primary me-2"></i><?php echo e($ms['payment_method']); ?></strong>
                                <span class="badge badge-soft-blue small"><?php echo $mShare; ?>%</span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center">
                                <small class="text-muted"><?php echo (int)$ms['total_txns']; ?> Transactions</small>
                                <strong class="text-success small"><?php echo $currency; ?> <?php echo number_format($mAmount, 2); ?></strong>
                            </div>
                            <div class="progress rounded-pill mt-2" style="height: 6px;">
                                <div class="progress-bar bg-primary" style="width: <?php echo $mShare; ?>%;"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Quick Reconciliation Guide -->
        <div class="card border-0 shadow-sm rounded-4 p-4">
            <h6 class="fw-bold text-dark mb-2"><i class="fa-solid fa-shield-halved text-primary me-2"></i>Bursar Daily Protocols</h6>
            <ul class="list-unstyled small text-muted mb-0 d-grid gap-2">
                <li><i class="fa-solid fa-circle-check text-success me-2"></i>Issue official printed receipts for all in-person cash payments.</li>
                <li><i class="fa-solid fa-circle-check text-success me-2"></i>Cross-verify MoMo transaction IDs against daily telecom logs.</li>
                <li><i class="fa-solid fa-circle-check text-success me-2"></i>Review outstanding balances prior to terminal exam card issuance.</li>
            </ul>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
