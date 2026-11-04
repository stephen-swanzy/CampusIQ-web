<?php

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/paystack.php';

requireLogin(['student']);
$db = getDb();
$user = currentUser();
$settings = getSchoolSettings();
$currency = $settings['currency'] ?? 'GH₵';
$paystackPublicKey = paystackGetPublicKey();
$isTestMode = paystackIsTestMode();

// Fetch student details
$stmtStudent = $db->prepare('SELECT s.*, c.name as class_name FROM students s LEFT JOIN classes c ON c.id = s.class_id WHERE s.user_id = :uid LIMIT 1');
$stmtStudent->execute(['uid' => $user['id']]);
$student = $stmtStudent->fetch();

$studentId = (int)($student['id'] ?? 0);
$successMsg = '';
$errorMsg = '';
$receiptData = null;

// Handle Fee Payment Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'pay_fee') {
    $payAmount = (float)($_POST['amount'] ?? 0);
    $payMethod = trim($_POST['payment_method'] ?? 'Mobile Money');
    $phoneNumber = trim($_POST['phone_number'] ?? '');
    $notes = trim($_POST['notes'] ?? 'Student Portal Online Payment');

    if ($studentId <= 0) {
        $errorMsg = 'Student profile not found. Please contact administration.';
    } elseif ($payAmount <= 0) {
        $errorMsg = 'Please enter a valid payment amount greater than zero.';
    } else {
        // Generate unique reference
        $refPrefix = ($payMethod === 'Card') ? 'CARD' : (($payMethod === 'Bank Transfer') ? 'BANK' : 'MM');
        $referenceNumber = 'PAY-' . $refPrefix . '-' . strtoupper(bin2hex(random_bytes(4)));

        // Look for existing invoice or create one
        $stmtInv = $db->prepare('SELECT id FROM invoices WHERE student_id = :sid ORDER BY id DESC LIMIT 1');
        $stmtInv->execute(['sid' => $studentId]);
        $inv = $stmtInv->fetch();
        $invoiceId = $inv ? (int)$inv['id'] : null;

        if (!$invoiceId) {
            $invNum = 'INV-' . date('Y') . '-' . str_pad((string)$studentId, 4, '0', STR_PAD_LEFT);
            $insInv = $db->prepare('INSERT INTO invoices (student_id, invoice_number, total_amount, status) VALUES (:sid, :inv_num, :amt, "issued")');
            $insInv->execute([
                'sid' => $studentId,
                'inv_num' => $invNum,
                'amt' => $payAmount
            ]);
            $invoiceId = (int)$db->lastInsertId();
        }

        // Insert payment record
        $insPay = $db->prepare('INSERT INTO payments (invoice_id, student_id, amount, payment_method, payment_date, reference_number, notes) VALUES (:inv_id, :sid, :amt, :method, NOW(), :ref, :notes)');
        $insPay->execute([
            'inv_id' => $invoiceId,
            'sid' => $studentId,
            'amt' => $payAmount,
            'method' => $payMethod . ($phoneNumber ? " ($phoneNumber)" : ''),
            'ref' => $referenceNumber,
            'notes' => $notes
        ]);
        $paymentId = (int)$db->lastInsertId();

        // Update fee records
        $stmtFees = $db->prepare('SELECT id, amount, status FROM fees WHERE student_id = :sid AND status != "paid" ORDER BY due_date ASC, id ASC');
        $stmtFees->execute(['sid' => $studentId]);
        $unpaidFees = $stmtFees->fetchAll();

        $runningCredit = $payAmount;
        foreach ($unpaidFees as $uf) {
            $feeAmt = (float)$uf['amount'];
            if ($runningCredit >= $feeAmt) {
                $db->prepare('UPDATE fees SET status = "paid" WHERE id = :id')->execute(['id' => $uf['id']]);
                $runningCredit -= $feeAmt;
            } elseif ($runningCredit > 0) {
                $db->prepare('UPDATE fees SET status = "partial" WHERE id = :id')->execute(['id' => $uf['id']]);
                $runningCredit = 0;
            }
        }

        // Check overall balance to update invoice status
        $totBilled = (float)$db->query("SELECT COALESCE(SUM(amount), 0) FROM fees WHERE student_id = $studentId")->fetchColumn();
        $totPaid = (float)$db->query("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE student_id = $studentId")->fetchColumn();
        $newBal = max(0, $totBilled - $totPaid);

        if ($newBal <= 0) {
            $db->prepare('UPDATE invoices SET status = "paid" WHERE id = :id')->execute(['id' => $invoiceId]);
        }

        // Log action
        $db->prepare('INSERT INTO activity_logs (user_id, action, details) VALUES (:uid, "Fee Payment", :details)')->execute([
            'uid' => $user['id'],
            'details' => "Paid {$currency} {$payAmount} via {$payMethod} (Ref: {$referenceNumber})"
        ]);

        $successMsg = "Payment of {$currency} " . number_format($payAmount, 2) . " successfully processed! Reference: {$referenceNumber}";

        $receiptData = [
            'reference' => $referenceNumber,
            'amount' => $payAmount,
            'method' => $payMethod,
            'phone' => $phoneNumber,
            'date' => date('d M Y, h:i A'),
            'student_name' => $user['name'],
            'student_code' => $student['student_id'] ?? 'N/A',
            'class_name' => $student['class_name'] ?? 'N/A',
            'balance' => $newBal
        ];
    }
}

// Compute financial totals
$totalBilled = 0.0;
$totalPaid = 0.0;
$feesList = [];
$paymentsList = [];

if ($studentId > 0) {
    // Total fees
    $feesStmt = $db->prepare('
        SELECT f.*, fc.name as category_name
        FROM fees f
        JOIN fee_categories fc ON fc.id = f.category_id
        WHERE f.student_id = :sid
        ORDER BY f.created_at DESC
    ');
    $feesStmt->execute(['sid' => $studentId]);
    $feesList = $feesStmt->fetchAll();

    foreach ($feesList as $f) {
        $totalBilled += (float)$f['amount'];
    }

    // Payments
    $payStmt = $db->prepare('
        SELECT p.*
        FROM payments p
        WHERE p.student_id = :sid
        ORDER BY p.payment_date DESC
    ');
    $payStmt->execute(['sid' => $studentId]);
    $paymentsList = $payStmt->fetchAll();

    foreach ($paymentsList as $p) {
        $totalPaid += (float)$p['amount'];
    }
}

$balanceDue = max(0, $totalBilled - $totalPaid);

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-3">
    <div>
        <p class="text-primary fw-semibold mb-1"><i class="fa-solid fa-wallet me-2"></i>Finance & Accounts</p>
        <h2 class="fw-bold mb-0">Student Fee Statement</h2>
        <small class="text-muted">View your tuition and school fees, track payment history, and make instant online payments.</small>
    </div>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-outline-secondary" onclick="window.print();">
            <i class="fa-solid fa-print me-2"></i>Print Statement
        </button>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#payFeeModal">
            <i class="fa-solid fa-credit-card me-2"></i>Pay School Fees
        </button>
    </div>
</div>

<?php if ($successMsg): ?>
    <div class="alert alert-success alert-dismissible fade show d-flex align-items-center mb-4" role="alert">
        <i class="fa-solid fa-circle-check fs-5 me-2"></i>
        <div><?php echo e($successMsg); ?></div>
        <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($errorMsg): ?>
    <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center mb-4" role="alert">
        <i class="fa-solid fa-triangle-exclamation fs-5 me-2"></i>
        <div><?php echo e($errorMsg); ?></div>
        <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Financial Metrics Row -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card p-3 border-0 shadow-sm d-flex flex-row align-items-center gap-3">
            <div class="rounded-3 p-3 bg-primary bg-opacity-10 text-primary fs-3">
                <i class="fa-solid fa-file-invoice-dollar"></i>
            </div>
            <div>
                <span class="text-muted small fw-semibold">Total Fees Billed</span>
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
                <span class="text-muted small fw-semibold">Total Amount Paid</span>
                <h4 class="fw-bold mb-0 text-dark"><?php echo $currency; ?> <?php echo number_format($totalPaid, 2); ?></h4>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card p-3 border-0 shadow-sm d-flex flex-row align-items-center gap-3">
            <div class="rounded-3 p-3 <?php echo ($balanceDue > 0) ? 'bg-danger bg-opacity-10 text-danger' : 'bg-success bg-opacity-10 text-success'; ?> fs-3">
                <i class="fa-solid fa-scale-balanced"></i>
            </div>
            <div>
                <span class="text-muted small fw-semibold">Outstanding Balance</span>
                <h4 class="fw-bold mb-0 <?php echo ($balanceDue > 0) ? 'text-danger' : 'text-success'; ?>">
                    <?php echo $currency; ?> <?php echo number_format($balanceDue, 2); ?>
                </h4>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card p-3 border-0 shadow-sm d-flex flex-row align-items-center gap-3">
            <div class="rounded-3 p-3 bg-info bg-opacity-10 text-info fs-3">
                <i class="fa-solid fa-shield-halved"></i>
            </div>
            <div>
                <span class="text-muted small fw-semibold">Account Status</span>
                <div>
                    <?php if ($balanceDue <= 0 && $totalBilled > 0): ?>
                        <span class="badge bg-success"><i class="fa-solid fa-check me-1"></i>Fully Cleared</span>
                    <?php elseif ($totalPaid > 0): ?>
                        <span class="badge bg-warning text-dark"><i class="fa-solid fa-clock me-1"></i>Partially Paid</span>
                    <?php else: ?>
                        <span class="badge bg-danger"><i class="fa-solid fa-exclamation me-1"></i>Pending Payment</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Fee Breakdown Table -->
    <div class="col-lg-7">
        <div class="card p-0 border-0 shadow-sm overflow-hidden h-100">
            <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                <h5 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-receipt text-primary me-2"></i>Billed Fee Items</h5>
                <span class="badge bg-light text-secondary"><?php echo count($feesList); ?> Item(s)</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Fee Category</th>
                            <th>Due Date</th>
                            <th>Amount</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($feesList)): ?>
                            <tr>
                                <td colspan="4" class="text-center py-5 text-muted">
                                    <i class="fa-solid fa-clipboard-check fs-2 text-secondary opacity-50 mb-2 d-block"></i>
                                    No fee obligations currently assigned to your account.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($feesList as $f): ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold text-dark"><?php echo e($f['category_name']); ?></div>
                                        <small class="text-muted">Issued: <?php echo date('d M Y', strtotime($f['created_at'])); ?></small>
                                    </td>
                                    <td>
                                        <span class="small text-muted">
                                            <?php echo !empty($f['due_date']) ? date('d M Y', strtotime($f['due_date'])) : 'End of Term'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <strong class="text-dark"><?php echo $currency; ?> <?php echo number_format((float)$f['amount'], 2); ?></strong>
                                    </td>
                                    <td>
                                        <?php if ($f['status'] === 'paid'): ?>
                                            <span class="badge bg-success-subtle text-success border border-success border-opacity-25 px-2 py-1">
                                                <i class="fa-solid fa-check me-1"></i>Paid
                                            </span>
                                        <?php elseif ($f['status'] === 'partial'): ?>
                                            <span class="badge bg-warning-subtle text-warning border border-warning border-opacity-25 px-2 py-1">
                                                <i class="fa-solid fa-clock me-1"></i>Partial
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-danger-subtle text-danger border border-danger border-opacity-25 px-2 py-1">
                                                <i class="fa-solid fa-circle-exclamation me-1"></i>Pending
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Payment History -->
    <div class="col-lg-5">
        <div class="card p-0 border-0 shadow-sm overflow-hidden h-100">
            <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                <h5 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-history text-primary me-2"></i>Payment History</h5>
                <span class="badge bg-light text-secondary"><?php echo count($paymentsList); ?> Receipt(s)</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Reference & Channel</th>
                            <th>Amount</th>
                            <th class="text-end">Receipt</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($paymentsList)): ?>
                            <tr>
                                <td colspan="3" class="text-center py-5 text-muted">
                                    <i class="fa-solid fa-credit-card fs-2 text-secondary opacity-50 mb-2 d-block"></i>
                                    No payments recorded yet.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($paymentsList as $p): ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold text-dark"><?php echo e($p['reference_number'] ?: 'TXN-'. $p['id']); ?></div>
                                        <small class="text-muted d-block"><?php echo e($p['payment_method']); ?></small>
                                        <small class="text-muted"><?php echo date('d M Y, h:i A', strtotime($p['payment_date'])); ?></small>
                                    </td>
                                    <td>
                                        <strong class="text-success"><?php echo $currency; ?> <?php echo number_format((float)$p['amount'], 2); ?></strong>
                                    </td>
                                    <td class="text-end">
                                        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#receiptModal_<?php echo $p['id']; ?>">
                                            <i class="fa-solid fa-receipt me-1"></i>Receipt
                                        </button>
                                    </td>
                                </tr>

                                <!-- Receipt Modal for each payment -->
                                <div class="modal fade" id="receiptModal_<?php echo $p['id']; ?>" tabindex="-1" aria-hidden="true">
                                    <div class="modal-dialog modal-dialog-centered">
                                        <div class="modal-content border-0 shadow-lg">
                                            <div class="modal-body p-4" id="printableReceipt_<?php echo $p['id']; ?>">
                                                <div class="text-center pb-3 border-bottom">
                                                    <div class="brand-mark mx-auto mb-2 text-primary fs-1"><i class="fa-solid fa-graduation-cap"></i></div>
                                                    <h5 class="fw-bold mb-0 text-dark"><?php echo e($settings['school_name'] ?? 'CampusIQ'); ?></h5>
                                                    <small class="text-muted">Official Payment Receipt & Statement</small>
                                                </div>
                                                <div class="my-3 py-2 bg-light rounded text-center">
                                                    <span class="text-muted small d-block">Amount Paid</span>
                                                    <h2 class="fw-bold text-success mb-0"><?php echo $currency; ?> <?php echo number_format((float)$p['amount'], 2); ?></h2>
                                                </div>
                                                <div class="row g-2 small mb-3">
                                                    <div class="col-6 text-muted">Receipt Ref:</div>
                                                    <div class="col-6 text-end fw-bold"><?php echo e($p['reference_number'] ?: 'TXN-' . $p['id']); ?></div>
                                                    <div class="col-6 text-muted">Student Name:</div>
                                                    <div class="col-6 text-end fw-semibold"><?php echo e($user['name']); ?></div>
                                                    <div class="col-6 text-muted">Student ID:</div>
                                                    <div class="col-6 text-end"><?php echo e($student['student_id'] ?? 'N/A'); ?></div>
                                                    <div class="col-6 text-muted">Class:</div>
                                                    <div class="col-6 text-end"><?php echo e($student['class_name'] ?? 'N/A'); ?></div>
                                                    <div class="col-6 text-muted">Payment Channel:</div>
                                                    <div class="col-6 text-end"><?php echo e($p['payment_method']); ?></div>
                                                    <div class="col-6 text-muted">Date & Time:</div>
                                                    <div class="col-6 text-end"><?php echo date('d M Y, h:i A', strtotime($p['payment_date'])); ?></div>
                                                </div>
                                                <div class="p-2 border rounded text-center bg-light-subtle">
                                                    <small class="text-muted"><i class="fa-solid fa-shield-check text-success me-1"></i>Verified & Authenticated by CampusIQ Financial System</small>
                                                </div>
                                            </div>
                                            <div class="modal-footer bg-light d-flex justify-content-between">
                                                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                                                <button type="button" class="btn btn-primary btn-sm" onclick="printReceipt('printableReceipt_<?php echo $p['id']; ?>');">
                                                    <i class="fa-solid fa-print me-1"></i>Print Receipt
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Paystack Checkout Modal -->
<div class="modal fade" id="payFeeModal" tabindex="-1" aria-labelledby="payFeeModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-primary text-white py-3">
                <div class="d-flex align-items-center gap-2">
                    <div class="rounded-circle bg-white bg-opacity-20 p-2 text-white">
                        <i class="fa-solid fa-credit-card"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold mb-0" id="payFeeModalLabel">Pay School Fees — Paystack Checkout</h5>
                        <small class="opacity-75">Instant settlement via Mobile Money & Bank Cards</small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <!-- Status/Error Alert -->
                <div id="paystackAlert" class="alert alert-danger d-none align-items-center mb-3" role="alert">
                    <i class="fa-solid fa-triangle-exclamation me-2"></i>
                    <span id="paystackAlertText"></span>
                </div>

                <!-- Outstanding Balance Card -->
                <div class="card bg-light border-0 p-3 mb-4 rounded-3">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <div>
                            <span class="text-muted small fw-semibold d-block">Current Outstanding Balance:</span>
                            <h3 class="fw-bold mb-0 <?php echo ($balanceDue > 0) ? 'text-danger' : 'text-success'; ?>">
                                <?php echo $currency; ?> <?php echo number_format($balanceDue, 2); ?>
                            </h3>
                        </div>
                        <div class="d-flex gap-2">
                            <?php if ($balanceDue > 0): ?>
                                <button type="button" class="btn btn-sm btn-outline-primary" onclick="setPayAmount(<?php echo $balanceDue; ?>)">
                                    <i class="fa-solid fa-check-double me-1"></i>Pay Full Balance
                                </button>
                                <?php if ($balanceDue > 200): ?>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="setPayAmount(<?php echo round($balanceDue / 2, 2); ?>)">
                                        Pay 50%
                                    </button>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="badge bg-success-subtle text-success border border-success border-opacity-25 px-3 py-2">
                                    <i class="fa-solid fa-circle-check me-1"></i>All Fees Settled
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Payment Form Fields -->
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Amount to Pay (<?php echo $currency; ?>) <span class="text-danger">*</span></label>
                        <div class="input-group input-group-lg">
                            <span class="input-group-text bg-white fw-bold text-primary"><?php echo $currency; ?></span>
                            <input 
                                type="number" 
                                step="0.01" 
                                min="1" 
                                id="payAmountInput" 
                                class="form-control fw-bold fs-4 text-dark" 
                                value="<?php echo $balanceDue > 0 ? $balanceDue : '100.00'; ?>" 
                                required
                                oninput="updateButtonAmount(this.value)"
                            >
                        </div>
                        <small class="text-muted">Enter any amount you wish to credit toward your fees.</small>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-bold">Student Account</label>
                        <div class="p-2 bg-light rounded border">
                            <strong class="d-block text-dark"><?php echo e($user['name']); ?></strong>
                            <span class="small text-muted">ID: <?php echo e($student['student_id'] ?? 'N/A'); ?> &bull; <?php echo e($student['class_name'] ?? 'Class'); ?></span>
                        </div>
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-bold">Payment Narration / Note</label>
                        <input type="text" id="payNotesInput" class="form-control" value="Fees Settlement - <?php echo e($user['name']); ?>">
                    </div>
                </div>

                <!-- Supported Channels & Paystack Trust Badge -->
                <div class="p-3 bg-white rounded-3 border mb-3">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                        <span class="small fw-bold text-dark"><i class="fa-solid fa-shield-halved text-success me-1"></i>Available Channels via Paystack:</span>
                        <span class="badge bg-light text-secondary border">Ghana &bull; GHS</span>
                    </div>
                    <div class="d-flex flex-wrap gap-2 align-items-center text-muted small">
                        <span class="badge bg-warning bg-opacity-10 text-dark border border-warning border-opacity-25 px-2 py-1">
                            <i class="fa-solid fa-mobile-screen me-1 text-warning"></i>MTN MoMo
                        </span>
                        <span class="badge bg-danger bg-opacity-10 text-dark border border-danger border-opacity-25 px-2 py-1">
                            <i class="fa-solid fa-mobile-screen me-1 text-danger"></i>Telecel Cash
                        </span>
                        <span class="badge bg-primary bg-opacity-10 text-dark border border-primary border-opacity-25 px-2 py-1">
                            <i class="fa-solid fa-mobile-screen me-1 text-primary"></i>AT Money
                        </span>
                        <span class="badge bg-info bg-opacity-10 text-dark border border-info border-opacity-25 px-2 py-1">
                            <i class="fa-solid fa-credit-card me-1 text-info"></i>Visa / Mastercard
                        </span>
                    </div>
                </div>

                <?php if ($isTestMode): ?>
                    <div class="alert alert-warning py-2 px-3 small d-flex align-items-center justify-content-between mb-3 border-warning">
                        <div>
                            <i class="fa-solid fa-flask-vial me-2"></i>
                            <strong>Paystack Test Mode Active:</strong> Use test numbers (e.g. <code>0551234987</code>) or test cards without real money.
                        </div>
                        <span class="badge bg-dark text-white">Sandbox</span>
                    </div>
                <?php endif; ?>

                <div class="d-flex align-items-center gap-2 small text-muted">
                    <i class="fa-solid fa-lock text-success"></i>
                    <span>Transactions are verified server-side with 256-bit encryption. Secret keys are never exposed.</span>
                </div>
            </div>

            <div class="modal-footer bg-light py-3 d-flex flex-wrap justify-content-between">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>

                <div class="d-flex gap-2">
                    <?php if ($isTestMode): ?>
                        <button type="button" class="btn btn-outline-warning" id="simPayBtn" onclick="simulateStudentTestPayment()">
                            <i class="fa-solid fa-flask me-1"></i>Simulate Test Payment
                        </button>
                    <?php endif; ?>

                    <button type="button" class="btn btn-primary px-4 fw-bold" id="payWithPaystackBtn" onclick="initiateStudentPaystackPayment()">
                        <span id="payBtnSpinner" class="spinner-border spinner-border-sm me-2 d-none" role="status"></span>
                        <i class="fa-solid fa-lock me-1" id="payBtnLockIcon"></i>
                        Pay <span id="payBtnAmountText"><?php echo $currency; ?> <?php echo number_format($balanceDue > 0 ? $balanceDue : 100, 2); ?></span> with Paystack
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Verifying Progress Modal (Backdrop) -->
<div class="modal fade" id="verifyingPaymentModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg text-center p-4">
            <div class="spinner-border text-primary mx-auto my-3" style="width: 3.5rem; height: 3.5rem;" role="status"></div>
            <h5 class="fw-bold mb-1 text-dark">Verifying Paystack Payment</h5>
            <p class="text-muted small mb-0">Communicating with Paystack and securing your fee ledger...</p>
        </div>
    </div>
</div>

<!-- Dynamic Official Receipt Modal -->
<div class="modal fade" id="activeReceiptModal" tabindex="-1" aria-labelledby="activeReceiptModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-body p-4" id="printableReceiptArea">
                <div class="text-center pb-3 border-bottom">
                    <?php if (!empty($settings['school_logo']) && file_exists(__DIR__ . '/../' . $settings['school_logo'])): ?>
                        <img src="<?php echo baseUrl() . '/' . e($settings['school_logo']); ?>" alt="Crest" style="max-height: 55px;" class="mb-2">
                    <?php else: ?>
                        <div class="brand-mark mx-auto mb-2 text-primary fs-1"><i class="fa-solid fa-graduation-cap"></i></div>
                    <?php endif; ?>
                    <h5 class="fw-bold mb-0 text-dark"><?php echo e($settings['school_name'] ?? 'CampusIQ'); ?></h5>
                    <small class="text-muted text-uppercase fw-semibold" style="letter-spacing: 1px;">Official Fee Payment Receipt</small>
                </div>

                <div class="my-3 py-2 bg-success bg-opacity-10 rounded text-center border border-success border-opacity-25">
                    <span class="text-muted small d-block">Amount Received & Verified</span>
                    <h2 class="fw-bold text-success mb-0" id="rcptAmount"><?php echo $currency; ?> 0.00</h2>
                </div>

                <div class="row g-2 small mb-3">
                    <div class="col-6 text-muted">Receipt Ref:</div>
                    <div class="col-6 text-end fw-bold font-monospace" id="rcptRef">-</div>

                    <div class="col-6 text-muted">Student Name:</div>
                    <div class="col-6 text-end fw-semibold text-dark" id="rcptStudentName">-</div>

                    <div class="col-6 text-muted">Student ID:</div>
                    <div class="col-6 text-end" id="rcptStudentCode">-</div>

                    <div class="col-6 text-muted">Class:</div>
                    <div class="col-6 text-end" id="rcptClassName">-</div>

                    <div class="col-6 text-muted">Payment Channel:</div>
                    <div class="col-6 text-end fw-semibold" id="rcptMethod">-</div>

                    <div class="col-6 text-muted">Date & Time:</div>
                    <div class="col-6 text-end" id="rcptDate">-</div>

                    <div class="col-6 text-muted">Remaining Balance:</div>
                    <div class="col-6 text-end fw-bold text-danger" id="rcptBalance">-</div>
                </div>

                <div class="p-2 border rounded text-center bg-light">
                    <small class="text-success fw-bold">
                        <i class="fa-solid fa-circle-check me-1"></i>Verified via Paystack Gateway & Recorded in Ledger
                    </small>
                </div>
            </div>
            <div class="modal-footer bg-light d-flex justify-content-between">
                <button type="button" class="btn btn-secondary btn-sm" onclick="location.reload();">Done</button>
                <button type="button" class="btn btn-primary btn-sm" onclick="printReceiptContent('printableReceiptArea');">
                    <i class="fa-solid fa-print me-1"></i>Print Official Receipt
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Paystack Inline JS Library -->
<script src="https://js.paystack.co/v1/inline.js"></script>

<script>
const PAYSTACK_PUBLIC_KEY = '<?php echo e($paystackPublicKey); ?>';
const STUDENT_DB_ID = <?php echo (int)$studentId; ?>;
const STUDENT_USER_ID = <?php echo (int)$user['id']; ?>;
const STUDENT_NAME = '<?php echo addslashes($user['name']); ?>';
const STUDENT_CODE = '<?php echo addslashes($student['student_id'] ?? 'N/A'); ?>';
const STUDENT_EMAIL = '<?php echo addslashes($user['email'] ?? 'student@campusiq.edu.gh'); ?>';
const CURRENCY_SYMBOL = '<?php echo addslashes($currency); ?>';

function setPayAmount(amt) {
    const input = document.getElementById('payAmountInput');
    input.value = parseFloat(amt).toFixed(2);
    updateButtonAmount(input.value);
}

function updateButtonAmount(amt) {
    const textSpan = document.getElementById('payBtnAmountText');
    const val = parseFloat(amt) || 0;
    textSpan.textContent = CURRENCY_SYMBOL + ' ' + val.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function showPaystackAlert(msg) {
    const box = document.getElementById('paystackAlert');
    const text = document.getElementById('paystackAlertText');
    text.textContent = msg;
    box.classList.remove('d-none');
    box.classList.add('d-flex');
}

function hidePaystackAlert() {
    const box = document.getElementById('paystackAlert');
    box.classList.add('d-none');
    box.classList.remove('d-flex');
}

function initiateStudentPaystackPayment() {
    hidePaystackAlert();
    const amountVal = parseFloat(document.getElementById('payAmountInput').value) || 0;
    const notesVal = document.getElementById('payNotesInput').value.trim();

    if (amountVal <= 0) {
        showPaystackAlert('Please enter a valid payment amount greater than zero.');
        return;
    }

    if (!PAYSTACK_PUBLIC_KEY || PAYSTACK_PUBLIC_KEY.indexOf('your_paystack_public_key_here') !== -1) {
        showPaystackAlert('Paystack Public Key is not configured yet. Please configure it in Admin Settings or use the Simulate Test Payment button.');
        return;
    }

    // Generate unique client reference
    const reference = 'PIQ-STU-' + STUDENT_DB_ID + '-' + Date.now() + '-' + Math.floor(Math.random() * 10000);

    const payModalEl = document.getElementById('payFeeModal');
    const payModal = bootstrap.Modal.getInstance(payModalEl) || new bootstrap.Modal(payModalEl);

    const handler = PaystackPop.setup({
        key: PAYSTACK_PUBLIC_KEY,
        email: STUDENT_EMAIL,
        amount: Math.round(amountVal * 100), // in pesewas
        currency: 'GHS',
        ref: reference,
        channels: ['card', 'mobile_money'],
        metadata: {
            custom_fields: [
                { display_name: "Student Name", variable_name: "student_name", value: STUDENT_NAME },
                { display_name: "Student ID", variable_name: "student_id", value: STUDENT_CODE }
            ],
            student_id: STUDENT_DB_ID,
            user_id: STUDENT_USER_ID,
            notes: notesVal
        },
        callback: function(response) {
            payModal.hide();
            verifyPaystackReference(response.reference, notesVal);
        },
        onClose: function() {
            // User closed the popup
        }
    });

    handler.openIframe();
}

function simulateStudentTestPayment() {
    hidePaystackAlert();
    const amountVal = parseFloat(document.getElementById('payAmountInput').value) || 0;
    const notesVal = document.getElementById('payNotesInput').value.trim();

    if (amountVal <= 0) {
        showPaystackAlert('Please enter a valid payment amount.');
        return;
    }

    const testRef = 'TEST-STU-' + STUDENT_DB_ID + '-' + Date.now() + '-' + Math.floor(Math.random() * 10000);

    const payModalEl = document.getElementById('payFeeModal');
    const payModal = bootstrap.Modal.getInstance(payModalEl) || new bootstrap.Modal(payModalEl);
    payModal.hide();

    verifyPaystackReference(testRef, notesVal);
}

function verifyPaystackReference(reference, notes) {
    const verifyingModalEl = document.getElementById('verifyingPaymentModal');
    const verifyingModal = new bootstrap.Modal(verifyingModalEl);
    verifyingModal.show();

    fetch('<?php echo baseUrl(); ?>/api/paystack_verify.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            reference: reference,
            student_id: STUDENT_DB_ID,
            notes: notes
        })
    })
    .then(res => res.json())
    .then(data => {
        verifyingModal.hide();
        if (data.success && data.receipt) {
            displayReceiptModal(data.receipt);
        } else {
            alert('Payment Verification Notice: ' + (data.error || 'Could not verify payment.'));
            location.reload();
        }
    })
    .catch(err => {
        verifyingModal.hide();
        alert('Server communication error during verification: ' + err.message);
    });
}

function displayReceiptModal(receipt) {
    document.getElementById('rcptAmount').textContent = receipt.currency + ' ' + parseFloat(receipt.amount).toLocaleString('en-US', { minimumFractionDigits: 2 });
    document.getElementById('rcptRef').textContent = receipt.reference;
    document.getElementById('rcptStudentName').textContent = receipt.student_name;
    document.getElementById('rcptStudentCode').textContent = receipt.student_code;
    document.getElementById('rcptClassName').textContent = receipt.class_name;
    document.getElementById('rcptMethod').textContent = receipt.method;
    document.getElementById('rcptDate').textContent = receipt.date;
    document.getElementById('rcptBalance').textContent = receipt.currency + ' ' + parseFloat(receipt.balance).toLocaleString('en-US', { minimumFractionDigits: 2 });

    const rcptModalEl = document.getElementById('activeReceiptModal');
    const rcptModal = new bootstrap.Modal(rcptModalEl);
    rcptModal.show();
}

function printReceiptContent(divId) {
    const content = document.getElementById(divId).innerHTML;
    const printWin = window.open('', '', 'height=700,width=850');
    printWin.document.write('<html><head><title>Official Fee Receipt</title>');
    printWin.document.write('<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">');
    printWin.document.write('<style>body { font-family: sans-serif; padding: 30px; background: #fff; } .border { border: 1px solid #dee2e6!important; } </style>');
    printWin.document.write('</head><body>');
    printWin.document.write(content);
    printWin.document.write('</body></html>');
    printWin.document.close();
    printWin.focus();
    setTimeout(() => {
        printWin.print();
        printWin.close();
    }, 500);
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
