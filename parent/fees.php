<?php

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/paystack.php';

requireLogin(['parent']);
$db = getDb();
$user = currentUser();
$settings = getSchoolSettings();
$currency = $settings['currency'] ?? 'GH₵';
$paystackPublicKey = paystackGetPublicKey();
$isTestMode = paystackIsTestMode();

// Resolve parent record
$stmtParent = $db->prepare('SELECT * FROM parents WHERE user_id = :uid LIMIT 1');
$stmtParent->execute(['uid' => $user['id']]);
$parent = $stmtParent->fetch();

$parentId = (int)($parent['id'] ?? 0);
$parentUserId = (int)$user['id'];

// Fetch children
$childrenStmt = $db->prepare('
    SELECT s.*, u.name as child_name, u.email as child_email, c.name as class_name
    FROM students s
    JOIN users u ON u.id = s.user_id
    LEFT JOIN classes c ON c.id = s.class_id
    WHERE (s.parent_id = :pid OR s.parent_id = :puid)
    ORDER BY u.name ASC
');
$childrenStmt->execute(['pid' => $parentId, 'puid' => $parentUserId]);
$children = $childrenStmt->fetchAll();

$selectedChildId = (int)($_GET['child_id'] ?? 0);
if ($selectedChildId === 0 && !empty($children)) {
    $selectedChildId = (int)$children[0]['id'];
}

$successMsg = '';
$errorMsg = '';
$receiptData = null;

// Handle Fee Payment Submission by Parent
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'pay_child_fee') {
    $targetStudentId = (int)($_POST['student_id'] ?? 0);
    $payAmount = (float)($_POST['amount'] ?? 0);
    $payMethod = trim($_POST['payment_method'] ?? 'Mobile Money');
    $phoneNumber = trim($_POST['phone_number'] ?? '');
    $notes = trim($_POST['notes'] ?? 'Parent Portal Fee Settlement');

    // Verify child belongs to this parent
    $childMatch = array_filter($children, fn($c) => (int)$c['id'] === $targetStudentId);
    $childData = !empty($childMatch) ? reset($childMatch) : null;

    if (!$childData) {
        $errorMsg = 'Invalid student selected or unauthorized access.';
    } elseif ($payAmount <= 0) {
        $errorMsg = 'Please enter a valid payment amount greater than zero.';
    } else {
        $refPrefix = ($payMethod === 'Card') ? 'CARD' : (($payMethod === 'Bank Transfer') ? 'BANK' : 'MM');
        $referenceNumber = 'PAY-PAR-' . $refPrefix . '-' . strtoupper(bin2hex(random_bytes(4)));

        // Look for or create invoice
        $stmtInv = $db->prepare('SELECT id FROM invoices WHERE student_id = :sid ORDER BY id DESC LIMIT 1');
        $stmtInv->execute(['sid' => $targetStudentId]);
        $inv = $stmtInv->fetch();
        $invoiceId = $inv ? (int)$inv['id'] : null;

        if (!$invoiceId) {
            $invNum = 'INV-' . date('Y') . '-' . str_pad((string)$targetStudentId, 4, '0', STR_PAD_LEFT);
            $insInv = $db->prepare('INSERT INTO invoices (student_id, invoice_number, total_amount, status) VALUES (:sid, :inv_num, :amt, "issued")');
            $insInv->execute([
                'sid' => $targetStudentId,
                'inv_num' => $invNum,
                'amt' => $payAmount
            ]);
            $invoiceId = (int)$db->lastInsertId();
        }

        // Insert payment
        $insPay = $db->prepare('INSERT INTO payments (invoice_id, student_id, amount, payment_method, payment_date, reference_number, notes) VALUES (:inv_id, :sid, :amt, :method, NOW(), :ref, :notes)');
        $insPay->execute([
            'inv_id' => $invoiceId,
            'sid' => $targetStudentId,
            'amt' => $payAmount,
            'method' => $payMethod . ($phoneNumber ? " ($phoneNumber)" : ''),
            'ref' => $referenceNumber,
            'notes' => $notes . ' (Paid by Parent: ' . $user['name'] . ')'
        ]);

        // Update fee records for child
        $stmtFees = $db->prepare('SELECT id, amount, status FROM fees WHERE student_id = :sid AND status != "paid" ORDER BY due_date ASC, id ASC');
        $stmtFees->execute(['sid' => $targetStudentId]);
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

        // Recalculate child balance
        $totBilled = (float)$db->query("SELECT COALESCE(SUM(amount), 0) FROM fees WHERE student_id = $targetStudentId")->fetchColumn();
        $totPaid = (float)$db->query("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE student_id = $targetStudentId")->fetchColumn();
        $newBal = max(0, $totBilled - $totPaid);

        if ($newBal <= 0) {
            $db->prepare('UPDATE invoices SET status = "paid" WHERE id = :id')->execute(['id' => $invoiceId]);
        }

        // Log activity
        $db->prepare('INSERT INTO activity_logs (user_id, action, details) VALUES (:uid, "Parent Fee Payment", :details)')->execute([
            'uid' => $user['id'],
            'details' => "Parent {$user['name']} paid {$currency} {$payAmount} for {$childData['child_name']} (Ref: {$referenceNumber})"
        ]);

        $successMsg = "Payment of {$currency} " . number_format($payAmount, 2) . " for {$childData['child_name']} processed successfully! Reference: {$referenceNumber}";

        $receiptData = [
            'reference' => $referenceNumber,
            'amount' => $payAmount,
            'method' => $payMethod,
            'phone' => $phoneNumber,
            'date' => date('d M Y, h:i A'),
            'parent_name' => $user['name'],
            'student_name' => $childData['child_name'],
            'student_code' => $childData['student_id'] ?? 'N/A',
            'class_name' => $childData['class_name'] ?? 'N/A',
            'balance' => $newBal
        ];

        $selectedChildId = $targetStudentId;
    }
}

// Compute financial overview for each child
$childrenStats = [];
$allChildrenIds = array_column($children, 'id');

if (!empty($allChildrenIds)) {
    foreach ($children as $c) {
        $cid = (int)$c['id'];
        $b = (float)$db->query("SELECT COALESCE(SUM(amount), 0) FROM fees WHERE student_id = $cid")->fetchColumn();
        $p = (float)$db->query("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE student_id = $cid")->fetchColumn();
        $childrenStats[$cid] = [
            'billed' => $b,
            'paid' => $p,
            'balance' => max(0, $b - $p)
        ];
    }
}

// Fetch fees and payments for the selected child
$activeChildFees = [];
$activeChildPayments = [];
$activeChildData = null;

if ($selectedChildId > 0) {
    foreach ($children as $c) {
        if ((int)$c['id'] === $selectedChildId) {
            $activeChildData = $c;
            break;
        }
    }

    $activeChildFees = $db->query("
        SELECT f.*, fc.name as category_name
        FROM fees f
        JOIN fee_categories fc ON fc.id = f.category_id
        WHERE f.student_id = $selectedChildId
        ORDER BY f.created_at DESC
    ")->fetchAll();

    $activeChildPayments = $db->query("
        SELECT p.*
        FROM payments p
        WHERE p.student_id = $selectedChildId
        ORDER BY p.payment_date DESC
    ")->fetchAll();
}

$activeBilled = $childrenStats[$selectedChildId]['billed'] ?? 0;
$activePaid = $childrenStats[$selectedChildId]['paid'] ?? 0;
$activeBalance = $childrenStats[$selectedChildId]['balance'] ?? 0;

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-3">
    <div>
        <p class="text-primary fw-semibold mb-1"><i class="fa-solid fa-children me-2"></i>Parent Financial Portal</p>
        <h2 class="fw-bold mb-0">Children Fee Management</h2>
        <small class="text-muted">Review fee obligations for your children, track verified receipts, and settle dues online.</small>
    </div>
    <div class="d-flex gap-2">
        <?php if (!empty($children)): ?>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#payChildFeeModal">
                <i class="fa-solid fa-credit-card me-2"></i>Pay Fees for Child
            </button>
        <?php endif; ?>
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

<?php if (empty($children)): ?>
    <div class="card p-5 text-center border-0 shadow-sm">
        <i class="fa-solid fa-user-slash fs-1 text-secondary opacity-50 mb-3 d-block"></i>
        <h5 class="fw-bold">No Students Linked to Your Parent Account</h5>
        <p class="text-muted">Please contact the school administrator to connect your account to your children's student profiles.</p>
    </div>
<?php else: ?>

    <!-- Children Selector Tabs -->
    <div class="card p-3 mb-4 border-0 shadow-sm">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <span class="fw-bold text-dark"><i class="fa-solid fa-user-graduate text-primary me-2"></i>Select Ward / Child:</span>
            <div class="d-flex flex-wrap gap-2">
                <?php foreach ($children as $c): 
                    $cid = (int)$c['id'];
                    $cBal = $childrenStats[$cid]['balance'] ?? 0;
                    $isSelected = ($cid === $selectedChildId);
                ?>
                    <a href="fees.php?child_id=<?php echo $cid; ?>" class="btn <?php echo $isSelected ? 'btn-primary' : 'btn-outline-secondary'; ?> d-flex align-items-center gap-2">
                        <i class="fa-solid fa-graduation-cap"></i>
                        <span><?php echo e($c['child_name']); ?></span>
                        <span class="badge <?php echo $cBal > 0 ? 'bg-danger' : 'bg-success'; ?> text-white ms-1">
                            <?php echo $currency; ?> <?php echo number_format($cBal, 2); ?>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Active Child Metrics Row -->
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="card p-3 border-0 shadow-sm d-flex flex-row align-items-center gap-3">
                <div class="rounded-3 p-3 bg-primary bg-opacity-10 text-primary fs-3">
                    <i class="fa-solid fa-file-invoice-dollar"></i>
                </div>
                <div>
                    <span class="text-muted small fw-semibold">Total Fees Billed</span>
                    <h4 class="fw-bold mb-0 text-dark"><?php echo $currency; ?> <?php echo number_format($activeBilled, 2); ?></h4>
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
                    <h4 class="fw-bold mb-0 text-dark"><?php echo $currency; ?> <?php echo number_format($activePaid, 2); ?></h4>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card p-3 border-0 shadow-sm d-flex flex-row align-items-center gap-3">
                <div class="rounded-3 p-3 <?php echo ($activeBalance > 0) ? 'bg-danger bg-opacity-10 text-danger' : 'bg-success bg-opacity-10 text-success'; ?> fs-3">
                    <i class="fa-solid fa-scale-balanced"></i>
                </div>
                <div>
                    <span class="text-muted small fw-semibold">Outstanding Balance</span>
                    <h4 class="fw-bold mb-0 <?php echo ($activeBalance > 0) ? 'text-danger' : 'text-success'; ?>">
                        <?php echo $currency; ?> <?php echo number_format($activeBalance, 2); ?>
                    </h4>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card p-3 border-0 shadow-sm d-flex flex-row align-items-center gap-3">
                <div class="rounded-3 p-3 bg-info bg-opacity-10 text-info fs-3">
                    <i class="fa-solid fa-school"></i>
                </div>
                <div>
                    <span class="text-muted small fw-semibold">Enrolled Class</span>
                    <h5 class="fw-bold mb-0 text-dark"><?php echo e($activeChildData['class_name'] ?? 'Not Enrolled'); ?></h5>
                    <small class="text-muted">ID: <?php echo e($activeChildData['student_id'] ?? 'N/A'); ?></small>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- Fees Breakdown for active child -->
        <div class="col-lg-7">
            <div class="card p-0 border-0 shadow-sm overflow-hidden h-100">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold mb-0 text-dark">
                        <i class="fa-solid fa-receipt text-primary me-2"></i>Billed Fees for <?php echo e($activeChildData['child_name'] ?? 'Child'); ?>
                    </h5>
                    <span class="badge bg-light text-secondary"><?php echo count($activeChildFees); ?> Item(s)</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Category</th>
                                <th>Due Date</th>
                                <th>Amount</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($activeChildFees)): ?>
                                <tr>
                                    <td colspan="4" class="text-center py-5 text-muted">
                                        <i class="fa-solid fa-clipboard-check fs-2 text-secondary opacity-50 mb-2 d-block"></i>
                                        No fee items assigned to this child yet.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($activeChildFees as $f): ?>
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

        <!-- Payments list for active child -->
        <div class="col-lg-5">
            <div class="card p-0 border-0 shadow-sm overflow-hidden h-100">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-history text-primary me-2"></i>Payment Receipts</h5>
                    <span class="badge bg-light text-secondary"><?php echo count($activeChildPayments); ?> Record(s)</span>
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
                            <?php if (empty($activeChildPayments)): ?>
                                <tr>
                                    <td colspan="3" class="text-center py-5 text-muted">
                                        <i class="fa-solid fa-credit-card fs-2 text-secondary opacity-50 mb-2 d-block"></i>
                                        No payments recorded yet for this child.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($activeChildPayments as $p): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold text-dark"><?php echo e($p['reference_number'] ?: 'TXN-'.$p['id']); ?></div>
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

                                    <!-- Receipt Modal -->
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
                                                        <div class="col-6 text-muted">Paid For (Ward):</div>
                                                        <div class="col-6 text-end fw-semibold"><?php echo e($activeChildData['child_name'] ?? 'N/A'); ?></div>
                                                        <div class="col-6 text-muted">Student ID:</div>
                                                        <div class="col-6 text-end"><?php echo e($activeChildData['student_id'] ?? 'N/A'); ?></div>
                                                        <div class="col-6 text-muted">Class:</div>
                                                        <div class="col-6 text-end"><?php echo e($activeChildData['class_name'] ?? 'N/A'); ?></div>
                                                        <div class="col-6 text-muted">Payer (Parent):</div>
                                                        <div class="col-6 text-end"><?php echo e($user['name']); ?></div>
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

    <!-- Paystack Checkout Modal for Parent -->
    <div class="modal fade" id="payChildFeeModal" tabindex="-1" aria-labelledby="payChildFeeModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-header bg-primary text-white py-3">
                    <div class="d-flex align-items-center gap-2">
                        <div class="rounded-circle bg-white bg-opacity-20 p-2 text-white">
                            <i class="fa-solid fa-credit-card"></i>
                        </div>
                        <div>
                            <h5 class="modal-title fw-bold mb-0" id="payChildFeeModalLabel">Pay Fees for Ward — Paystack Checkout</h5>
                            <small class="opacity-75">Instant settlement via Mobile Money & Bank Cards</small>
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <!-- Status/Error Alert -->
                    <div id="parentPaystackAlert" class="alert alert-danger d-none align-items-center mb-3" role="alert">
                        <i class="fa-solid fa-triangle-exclamation me-2"></i>
                        <span id="parentPaystackAlertText"></span>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Select Child / Student <span class="text-danger">*</span></label>
                            <select id="parentChildSelect" class="form-select form-select-lg" required onchange="handleParentChildChange(this)">
                                <?php foreach ($children as $c): 
                                    $cid = (int)$c['id'];
                                    $cBal = $childrenStats[$cid]['balance'] ?? 0;
                                ?>
                                    <option 
                                        value="<?php echo $cid; ?>" 
                                        data-balance="<?php echo $cBal; ?>" 
                                        data-name="<?php echo e($c['child_name']); ?>"
                                        data-code="<?php echo e($c['student_id'] ?? 'N/A'); ?>"
                                        data-class="<?php echo e($c['class_name'] ?? 'Class'); ?>"
                                        <?php echo ($cid === $selectedChildId) ? 'selected' : ''; ?>
                                    >
                                        <?php echo e($c['child_name']); ?> (Bal: <?php echo $currency; ?> <?php echo number_format($cBal, 2); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">Amount to Pay (<?php echo $currency; ?>) <span class="text-danger">*</span></label>
                            <div class="input-group input-group-lg">
                                <span class="input-group-text bg-white fw-bold text-primary"><?php echo $currency; ?></span>
                                <input 
                                    type="number" 
                                    step="0.01" 
                                    min="1" 
                                    id="parentPayAmountInput" 
                                    class="form-control fw-bold fs-4 text-dark" 
                                    value="<?php echo $activeBalance > 0 ? $activeBalance : '100.00'; ?>" 
                                    required
                                    oninput="updateParentButtonAmount(this.value)"
                                >
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="d-flex flex-wrap gap-2 align-items-center">
                                <span class="small text-muted fw-semibold">Quick Amounts:</span>
                                <button type="button" class="btn btn-sm btn-outline-primary" onclick="setParentQuickAmount('full')">
                                    <i class="fa-solid fa-check-double me-1"></i>Pay Full Balance
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="setParentQuickAmount('half')">
                                    Pay 50%
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="setParentQuickAmount(100)">
                                    <?php echo $currency; ?> 100
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="setParentQuickAmount(250)">
                                    <?php echo $currency; ?> 250
                                </button>
                            </div>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-bold">Payment Narration / Note</label>
                            <input type="text" id="parentPayNotesInput" class="form-control" value="Fees Settlement by Parent - <?php echo e($user['name']); ?>">
                        </div>
                    </div>

                    <!-- Supported Channels Banner -->
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
                                <strong>Paystack Test Mode Active:</strong> Test payments run in sandbox without real money.
                            </div>
                            <span class="badge bg-dark text-white">Sandbox</span>
                        </div>
                    <?php endif; ?>

                    <div class="d-flex align-items-center gap-2 small text-muted">
                        <i class="fa-solid fa-lock text-success"></i>
                        <span>Backend verification ensures receipts are tamper-proof and updated directly in MySQL.</span>
                    </div>
                </div>

                <div class="modal-footer bg-light py-3 d-flex flex-wrap justify-content-between">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>

                    <div class="d-flex gap-2">
                        <?php if ($isTestMode): ?>
                            <button type="button" class="btn btn-outline-warning" id="parentSimBtn" onclick="simulateParentTestPayment()">
                                <i class="fa-solid fa-flask me-1"></i>Simulate Test Payment
                            </button>
                        <?php endif; ?>

                        <button type="button" class="btn btn-primary px-4 fw-bold" id="parentPayBtn" onclick="initiateParentPaystackPayment()">
                            <span id="parentPayBtnSpinner" class="spinner-border spinner-border-sm me-2 d-none" role="status"></span>
                            <i class="fa-solid fa-lock me-1" id="parentPayBtnLock"></i>
                            Pay <span id="parentPayBtnAmountText"><?php echo $currency; ?> <?php echo number_format($activeBalance > 0 ? $activeBalance : 100, 2); ?></span> with Paystack
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Verifying Progress Modal for Parent -->
    <div class="modal fade" id="verifyingParentPaymentModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg text-center p-4">
                <div class="spinner-border text-primary mx-auto my-3" style="width: 3.5rem; height: 3.5rem;" role="status"></div>
                <h5 class="fw-bold mb-1 text-dark">Verifying Ward Fee Payment</h5>
                <p class="text-muted small mb-0">Communicating with Paystack and securing school ledger records...</p>
            </div>
        </div>
    </div>

    <!-- Official Receipt Modal for Parent -->
    <div class="modal fade" id="activeParentReceiptModal" tabindex="-1" aria-labelledby="activeParentReceiptModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-body p-4" id="printableParentReceiptArea">
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
                        <h2 class="fw-bold text-success mb-0" id="parentRcptAmount"><?php echo $currency; ?> 0.00</h2>
                    </div>

                    <div class="row g-2 small mb-3">
                        <div class="col-6 text-muted">Receipt Ref:</div>
                        <div class="col-6 text-end fw-bold font-monospace" id="parentRcptRef">-</div>

                        <div class="col-6 text-muted">Paid For (Ward):</div>
                        <div class="col-6 text-end fw-semibold text-dark" id="parentRcptStudentName">-</div>

                        <div class="col-6 text-muted">Student ID:</div>
                        <div class="col-6 text-end" id="parentRcptStudentCode">-</div>

                        <div class="col-6 text-muted">Class:</div>
                        <div class="col-6 text-end" id="parentRcptClassName">-</div>

                        <div class="col-6 text-muted">Payer (Parent):</div>
                        <div class="col-6 text-end fw-semibold text-dark"><?php echo e($user['name']); ?></div>

                        <div class="col-6 text-muted">Payment Channel:</div>
                        <div class="col-6 text-end fw-semibold" id="parentRcptMethod">-</div>

                        <div class="col-6 text-muted">Date & Time:</div>
                        <div class="col-6 text-end" id="parentRcptDate">-</div>

                        <div class="col-6 text-muted">Remaining Balance:</div>
                        <div class="col-6 text-end fw-bold text-danger" id="parentRcptBalance">-</div>
                    </div>

                    <div class="p-2 border rounded text-center bg-light">
                        <small class="text-success fw-bold">
                            <i class="fa-solid fa-circle-check me-1"></i>Verified via Paystack Gateway & Recorded in Ledger
                        </small>
                    </div>
                </div>
                <div class="modal-footer bg-light d-flex justify-content-between">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="location.reload();">Done</button>
                    <button type="button" class="btn btn-primary btn-sm" onclick="printReceiptContent('printableParentReceiptArea');">
                        <i class="fa-solid fa-print me-1"></i>Print Official Receipt
                    </button>
                </div>
            </div>
        </div>
    </div>

<?php endif; ?>

<!-- Paystack Inline JS Library -->
<script src="https://js.paystack.co/v1/inline.js"></script>

<script>
const PAYSTACK_PUBLIC_KEY = '<?php echo e($paystackPublicKey); ?>';
const PARENT_USER_ID = <?php echo (int)$user['id']; ?>;
const PARENT_EMAIL = '<?php echo addslashes($user['email'] ?? 'parent@campusiq.edu.gh'); ?>';
const PARENT_NAME = '<?php echo addslashes($user['name']); ?>';
const CURRENCY_SYMBOL = '<?php echo addslashes($currency); ?>';

function handleParentChildChange(sel) {
    const opt = sel.options[sel.selectedIndex];
    const bal = opt ? parseFloat(opt.getAttribute('data-balance') || 0) : 0;
    const input = document.getElementById('parentPayAmountInput');
    if (bal > 0) {
        input.value = bal.toFixed(2);
    }
    updateParentButtonAmount(input.value);
}

function setParentQuickAmount(type) {
    const sel = document.getElementById('parentChildSelect');
    const opt = sel ? sel.options[sel.selectedIndex] : null;
    const bal = opt ? parseFloat(opt.getAttribute('data-balance') || 0) : 0;
    const input = document.getElementById('parentPayAmountInput');

    if (type === 'full') {
        input.value = (bal > 0 ? bal : 100).toFixed(2);
    } else if (type === 'half') {
        input.value = (bal > 0 ? (bal / 2) : 50).toFixed(2);
    } else if (typeof type === 'number') {
        input.value = type.toFixed(2);
    }
    updateParentButtonAmount(input.value);
}

function updateParentButtonAmount(amt) {
    const textSpan = document.getElementById('parentPayBtnAmountText');
    const val = parseFloat(amt) || 0;
    if (textSpan) {
        textSpan.textContent = CURRENCY_SYMBOL + ' ' + val.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
}

function showParentPaystackAlert(msg) {
    const box = document.getElementById('parentPaystackAlert');
    const text = document.getElementById('parentPaystackAlertText');
    text.textContent = msg;
    box.classList.remove('d-none');
    box.classList.add('d-flex');
}

function hideParentPaystackAlert() {
    const box = document.getElementById('parentPaystackAlert');
    box.classList.add('d-none');
    box.classList.remove('d-flex');
}

function initiateParentPaystackPayment() {
    hideParentPaystackAlert();
    const sel = document.getElementById('parentChildSelect');
    if (!sel || !sel.value) {
        showParentPaystackAlert('Please select a student / child to pay for.');
        return;
    }
    const studentDbId = parseInt(sel.value, 10);
    const selectedOpt = sel.options[sel.selectedIndex];
    const childName = selectedOpt.getAttribute('data-name') || 'Student';
    const childCode = selectedOpt.getAttribute('data-code') || 'N/A';

    const amountVal = parseFloat(document.getElementById('parentPayAmountInput').value) || 0;
    const notesVal = document.getElementById('parentPayNotesInput').value.trim();

    if (amountVal <= 0) {
        showParentPaystackAlert('Please enter a valid payment amount greater than zero.');
        return;
    }

    if (!PAYSTACK_PUBLIC_KEY || PAYSTACK_PUBLIC_KEY.indexOf('your_paystack_public_key_here') !== -1) {
        showParentPaystackAlert('Paystack Public Key is not configured yet. Please configure it in Admin Settings or use the Simulate Test Payment button.');
        return;
    }

    const reference = 'PIQ-PAR-' + studentDbId + '-' + Date.now() + '-' + Math.floor(Math.random() * 10000);

    const payModalEl = document.getElementById('payChildFeeModal');
    const payModal = bootstrap.Modal.getInstance(payModalEl) || new bootstrap.Modal(payModalEl);

    const handler = PaystackPop.setup({
        key: PAYSTACK_PUBLIC_KEY,
        email: PARENT_EMAIL,
        amount: Math.round(amountVal * 100), // in pesewas
        currency: 'GHS',
        ref: reference,
        channels: ['card', 'mobile_money'],
        metadata: {
            custom_fields: [
                { display_name: "Paid For", variable_name: "student_name", value: childName },
                { display_name: "Student ID", variable_name: "student_id", value: childCode },
                { display_name: "Payer (Parent)", variable_name: "parent_name", value: PARENT_NAME }
            ],
            student_id: studentDbId,
            user_id: PARENT_USER_ID,
            notes: notesVal
        },
        callback: function(response) {
            payModal.hide();
            verifyParentPaystackReference(response.reference, studentDbId, notesVal);
        },
        onClose: function() {
            // User closed the popup
        }
    });

    handler.openIframe();
}

function simulateParentTestPayment() {
    hideParentPaystackAlert();
    const sel = document.getElementById('parentChildSelect');
    if (!sel || !sel.value) {
        showParentPaystackAlert('Please select a child to pay for.');
        return;
    }
    const studentDbId = parseInt(sel.value, 10);
    const amountVal = parseFloat(document.getElementById('parentPayAmountInput').value) || 0;
    const notesVal = document.getElementById('parentPayNotesInput').value.trim();

    if (amountVal <= 0) {
        showParentPaystackAlert('Please enter a valid payment amount.');
        return;
    }

    const testRef = 'TEST-PAR-' + studentDbId + '-' + Date.now() + '-' + Math.floor(Math.random() * 10000);

    const payModalEl = document.getElementById('payChildFeeModal');
    const payModal = bootstrap.Modal.getInstance(payModalEl) || new bootstrap.Modal(payModalEl);
    payModal.hide();

    verifyParentPaystackReference(testRef, studentDbId, notesVal);
}

function verifyParentPaystackReference(reference, studentDbId, notes) {
    const verifyingModalEl = document.getElementById('verifyingParentPaymentModal');
    const verifyingModal = new bootstrap.Modal(verifyingModalEl);
    verifyingModal.show();

    fetch('<?php echo baseUrl(); ?>/api/paystack_verify.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            reference: reference,
            student_id: studentDbId,
            notes: notes
        })
    })
    .then(res => res.json())
    .then(data => {
        verifyingModal.hide();
        if (data.success && data.receipt) {
            displayParentReceiptModal(data.receipt);
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

function displayParentReceiptModal(receipt) {
    document.getElementById('parentRcptAmount').textContent = receipt.currency + ' ' + parseFloat(receipt.amount).toLocaleString('en-US', { minimumFractionDigits: 2 });
    document.getElementById('parentRcptRef').textContent = receipt.reference;
    document.getElementById('parentRcptStudentName').textContent = receipt.student_name;
    document.getElementById('parentRcptStudentCode').textContent = receipt.student_code;
    document.getElementById('parentRcptClassName').textContent = receipt.class_name;
    document.getElementById('parentRcptMethod').textContent = receipt.method;
    document.getElementById('parentRcptDate').textContent = receipt.date;
    document.getElementById('parentRcptBalance').textContent = receipt.currency + ' ' + parseFloat(receipt.balance).toLocaleString('en-US', { minimumFractionDigits: 2 });

    const rcptModalEl = document.getElementById('activeParentReceiptModal');
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
