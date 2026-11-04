<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin(['accountant', 'super_admin', 'school_admin']);
$db = getDb();
$user = currentUser();
$settings = getSchoolSettings();
$currency = $settings['currency'] ?? 'GH₵';

$successMsg = '';
$errorMsg = '';
$receiptData = null;

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'record_payment') {
        $studentId = (int)($_POST['student_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $method = trim($_POST['payment_method'] ?? 'Cash');
        $ref = trim($_POST['reference_number'] ?? '');
        $notes = trim($_POST['notes'] ?? 'Accounts Office Collection');
        $invoiceId = !empty($_POST['invoice_id']) ? (int)$_POST['invoice_id'] : null;

        if ($studentId <= 0 || $amount <= 0) {
            $errorMsg = 'Please select a student and enter a valid payment amount.';
        } else {
            if (empty($ref)) {
                $ref = 'PAY-' . strtoupper(substr($method, 0, 3)) . '-' . strtoupper(bin2hex(random_bytes(3)));
            }

            // If no invoice provided, find open invoice or create one
            if (!$invoiceId) {
                $chkInv = $db->prepare('SELECT id FROM invoices WHERE student_id = :sid AND status != "paid" ORDER BY id DESC LIMIT 1');
                $chkInv->execute(['sid' => $studentId]);
                $openInv = $chkInv->fetch();
                if ($openInv) {
                    $invoiceId = (int)$openInv['id'];
                } else {
                    $invNum = 'INV-' . date('Y') . '-' . str_pad((string)$studentId, 4, '0', STR_PAD_LEFT);
                    $insInv = $db->prepare('INSERT INTO invoices (student_id, invoice_number, total_amount, status) VALUES (:sid, :inv_num, :amt, "issued")');
                    $insInv->execute(['sid' => $studentId, 'inv_num' => $invNum, 'amt' => $amount]);
                    $invoiceId = (int)$db->lastInsertId();
                }
            }

            // Insert into payments
            $insPay = $db->prepare('INSERT INTO payments (invoice_id, student_id, amount, payment_method, payment_date, reference_number, notes) VALUES (:inv_id, :sid, :amt, :method, NOW(), :ref, :notes)');
            $insPay->execute([
                'inv_id' => $invoiceId,
                'sid' => $studentId,
                'amt' => $amount,
                'method' => $method,
                'ref' => $ref,
                'notes' => $notes
            ]);

            // Update fees status
            $feesStmt = $db->prepare('SELECT id, amount, status FROM fees WHERE student_id = :sid AND status != "paid" ORDER BY due_date ASC, id ASC');
            $feesStmt->execute(['sid' => $studentId]);
            $unpaidFees = $feesStmt->fetchAll();

            $running = $amount;
            foreach ($unpaidFees as $uf) {
                $feeAmt = (float)$uf['amount'];
                if ($running >= $feeAmt) {
                    $db->prepare('UPDATE fees SET status = "paid" WHERE id = :id')->execute(['id' => $uf['id']]);
                    $running -= $feeAmt;
                } elseif ($running > 0) {
                    $db->prepare('UPDATE fees SET status = "partial" WHERE id = :id')->execute(['id' => $uf['id']]);
                    $running = 0;
                }
            }

            // Update invoice status if fully settled
            $totBilled = (float)$db->query("SELECT COALESCE(SUM(amount), 0) FROM fees WHERE student_id = $studentId")->fetchColumn();
            $totPaid = (float)$db->query("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE student_id = $studentId")->fetchColumn();
            if (($totBilled - $totPaid) <= 0 && $invoiceId) {
                $db->prepare('UPDATE invoices SET status = "paid" WHERE id = :id')->execute(['id' => $invoiceId]);
            }

            // Log activity
            $db->prepare('INSERT INTO activity_logs (user_id, action, details) VALUES (:uid, "Payment Recorded", :details)')->execute([
                'uid' => $user['id'],
                'details' => "Accountant recorded payment of {$currency} {$amount} (Ref: {$ref})"
            ]);

            $successMsg = "Payment of {$currency} " . number_format($amount, 2) . " successfully recorded! Reference: {$ref}";
        }
    } elseif ($action === 'delete_payment') {
        $payId = (int)($_POST['payment_id'] ?? 0);
        $del = $db->prepare('DELETE FROM payments WHERE id = :id');
        $del->execute(['id' => $payId]);
        $successMsg = 'Payment record deleted.';
    }
}

// Filters
$filterMethod = trim($_GET['method'] ?? '');
$search = trim($_GET['search'] ?? '');

$sql = "
    SELECT p.*, s.student_id as student_code, u.name as student_name, c.name as class_name
    FROM payments p
    JOIN students s ON s.id = p.student_id
    JOIN users u ON u.id = s.user_id
    LEFT JOIN classes c ON c.id = s.class_id
    WHERE 1=1
";
$params = [];

if (!empty($filterMethod)) {
    $sql .= " AND p.payment_method LIKE :pmethod";
    $params['pmethod'] = "%{$filterMethod}%";
}
if (!empty($search)) {
    $sql .= " AND (u.name LIKE :search OR s.student_id LIKE :search2 OR p.reference_number LIKE :search3)";
    $params['search'] = "%{$search}%";
    $params['search2'] = "%{$search}%";
    $params['search3'] = "%{$search}%";
}
$sql .= " ORDER BY p.payment_date DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$payments = $stmt->fetchAll();

// Metrics
$totalCollected = array_sum(array_column($payments, 'amount'));
$momoCount = count(array_filter($payments, fn($p) => stripos($p['payment_method'], 'Money') !== false || stripos($p['payment_method'], 'MoMo') !== false || stripos($p['payment_method'], 'Telecel') !== false));
$cashCount = count(array_filter($payments, fn($p) => stripos($p['payment_method'], 'Cash') !== false));

// Students list for modal
$studentsList = $db->query('SELECT s.id, s.student_id as student_code, u.name, c.name as class_name FROM students s JOIN users u ON u.id = s.user_id LEFT JOIN classes c ON c.id = s.class_id WHERE s.status = "active" ORDER BY u.name ASC')->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-3">
    <div>
        <p class="text-primary fw-semibold mb-1"><i class="fa-solid fa-money-bill-wave me-2"></i>Accounts & Collections</p>
        <h2 class="fw-bold mb-0">Payments Registry</h2>
        <small class="text-muted">Record offline and digital fee collections, review transaction audits, and print receipts.</small>
    </div>
    <div>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#recordPaymentModal">
            <i class="fa-solid fa-plus me-2"></i>Record Payment
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

<!-- Stats Row -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card p-3 border-0 shadow-sm d-flex flex-row align-items-center gap-3">
            <div class="rounded-3 p-3 bg-success bg-opacity-10 text-success fs-3">
                <i class="fa-solid fa-hand-holding-dollar"></i>
            </div>
            <div>
                <span class="text-muted small fw-semibold">Total Revenue Collected</span>
                <h4 class="fw-bold mb-0 text-dark"><?php echo $currency; ?> <?php echo number_format($totalCollected, 2); ?></h4>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card p-3 border-0 shadow-sm d-flex flex-row align-items-center gap-3">
            <div class="rounded-3 p-3 bg-primary bg-opacity-10 text-primary fs-3">
                <i class="fa-solid fa-receipt"></i>
            </div>
            <div>
                <span class="text-muted small fw-semibold">Total Receipts</span>
                <h4 class="fw-bold mb-0 text-dark"><?php echo count($payments); ?></h4>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card p-3 border-0 shadow-sm d-flex flex-row align-items-center gap-3">
            <div class="rounded-3 p-3 bg-info bg-opacity-10 text-info fs-3">
                <i class="fa-solid fa-mobile-screen"></i>
            </div>
            <div>
                <span class="text-muted small fw-semibold">Mobile Money</span>
                <h4 class="fw-bold mb-0 text-dark"><?php echo $momoCount; ?></h4>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card p-3 border-0 shadow-sm d-flex flex-row align-items-center gap-3">
            <div class="rounded-3 p-3 bg-warning bg-opacity-10 text-warning fs-3">
                <i class="fa-solid fa-vault"></i>
            </div>
            <div>
                <span class="text-muted small fw-semibold">Cash Collections</span>
                <h4 class="fw-bold mb-0 text-dark"><?php echo $cashCount; ?></h4>
            </div>
        </div>
    </div>
</div>

<!-- Filters Bar -->
<div class="card p-3 mb-4 border-0 shadow-sm">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-6">
            <label class="form-label small fw-bold text-muted mb-1">Search Student / Reference #</label>
            <input type="text" name="search" class="form-control form-control-sm" placeholder="e.g. Esi or PAY-..." value="<?php echo e($search); ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label small fw-bold text-muted mb-1">Payment Method</label>
            <select name="method" class="form-select form-select-sm">
                <option value="">All Channels</option>
                <option value="Cash" <?php echo ($filterMethod === 'Cash') ? 'selected' : ''; ?>>Cash</option>
                <option value="Mobile Money" <?php echo ($filterMethod === 'Mobile Money') ? 'selected' : ''; ?>>Mobile Money (MoMo / Telecel)</option>
                <option value="Card" <?php echo ($filterMethod === 'Card') ? 'selected' : ''; ?>>Debit / Credit Card</option>
                <option value="Bank Transfer" <?php echo ($filterMethod === 'Bank Transfer') ? 'selected' : ''; ?>>Bank Transfer</option>
            </select>
        </div>
        <div class="col-md-2 d-flex gap-2">
            <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fa-solid fa-filter me-1"></i>Filter</button>
            <a href="payments.php" class="btn btn-light btn-sm"><i class="fa-solid fa-rotate-left"></i></a>
        </div>
    </form>
</div>

<!-- Payments Table -->
<div class="card p-0 border-0 shadow-sm overflow-hidden">
    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
        <h5 class="fw-bold mb-0 text-dark">Verified Receipts</h5>
        <span class="badge bg-light text-secondary"><?php echo count($payments); ?> Record(s)</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Reference #</th>
                    <th>Student Details</th>
                    <th>Class</th>
                    <th>Amount Received</th>
                    <th>Payment Channel</th>
                    <th>Date & Time</th>
                    <th>Narration</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($payments)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-5 text-muted">
                            <i class="fa-solid fa-receipt fs-1 text-secondary opacity-50 mb-3 d-block"></i>
                            No payments recorded yet. Click <strong>"Record Payment"</strong> to register fee settlements.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($payments as $p): ?>
                        <tr>
                            <td>
                                <strong class="text-primary font-monospace"><?php echo e($p['reference_number'] ?: 'TXN-'.$p['id']); ?></strong>
                            </td>
                            <td>
                                <div class="fw-bold text-dark"><?php echo e($p['student_name']); ?></div>
                                <small class="text-muted"><?php echo e($p['student_code']); ?></small>
                            </td>
                            <td>
                                <span class="badge bg-secondary bg-opacity-10 text-secondary border">
                                    <?php echo e($p['class_name'] ?? 'N/A'); ?>
                                </span>
                            </td>
                            <td>
                                <strong class="text-success fs-6"><?php echo $currency; ?> <?php echo number_format((float)$p['amount'], 2); ?></strong>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border fw-semibold">
                                    <?php echo e($p['payment_method']); ?>
                                </span>
                            </td>
                            <td>
                                <small class="text-dark"><?php echo date('d M Y, h:i A', strtotime($p['payment_date'])); ?></small>
                            </td>
                            <td>
                                <small class="text-muted text-truncate d-block" style="max-width: 180px;" title="<?php echo e($p['notes']); ?>">
                                    <?php echo e($p['notes'] ?: 'Fee settlement'); ?>
                                </small>
                            </td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                    <!-- Print Receipt Modal -->
                                    <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#receiptModal_<?php echo $p['id']; ?>" title="Print Official Receipt">
                                        <i class="fa-solid fa-receipt"></i>
                                    </button>

                                    <!-- Delete Payment -->
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete this payment transaction?');">
                                        <input type="hidden" name="action" value="delete_payment">
                                        <input type="hidden" name="payment_id" value="<?php echo $p['id']; ?>">
                                        <button type="submit" class="btn btn-outline-danger" title="Delete">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>

                        <!-- Printable Official Receipt Modal -->
                        <div class="modal fade" id="receiptModal_<?php echo $p['id']; ?>" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog modal-dialog-centered">
                                <div class="modal-content border-0 shadow-lg">
                                    <div class="modal-body p-4" id="printableReceipt_<?php echo $p['id']; ?>">
                                        <div class="text-center pb-3 border-bottom">
                                            <div class="brand-mark mx-auto mb-2 text-primary fs-1"><i class="fa-solid fa-graduation-cap"></i></div>
                                            <h5 class="fw-bold mb-0 text-dark"><?php echo e($settings['school_name'] ?? 'CampusIQ'); ?></h5>
                                            <small class="text-muted">Official Accounts Payment Receipt</small>
                                        </div>
                                        <div class="my-3 py-2 bg-success bg-opacity-10 rounded text-center">
                                            <span class="text-muted small d-block">Amount Received</span>
                                            <h2 class="fw-bold text-success mb-0"><?php echo $currency; ?> <?php echo number_format((float)$p['amount'], 2); ?></h2>
                                        </div>
                                        <div class="row g-2 small mb-3">
                                            <div class="col-6 text-muted">Receipt Ref:</div>
                                            <div class="col-6 text-end fw-bold font-monospace"><?php echo e($p['reference_number'] ?: 'TXN-' . $p['id']); ?></div>
                                            <div class="col-6 text-muted">Student Name:</div>
                                            <div class="col-6 text-end fw-semibold"><?php echo e($p['student_name']); ?></div>
                                            <div class="col-6 text-muted">Student ID:</div>
                                            <div class="col-6 text-end"><?php echo e($p['student_code']); ?></div>
                                            <div class="col-6 text-muted">Class:</div>
                                            <div class="col-6 text-end"><?php echo e($p['class_name'] ?? 'N/A'); ?></div>
                                            <div class="col-6 text-muted">Payment Channel:</div>
                                            <div class="col-6 text-end"><?php echo e($p['payment_method']); ?></div>
                                            <div class="col-6 text-muted">Payment Date:</div>
                                            <div class="col-6 text-end"><?php echo date('d M Y, h:i A', strtotime($p['payment_date'])); ?></div>
                                            <div class="col-6 text-muted">Narration:</div>
                                            <div class="col-6 text-end fst-italic"><?php echo e($p['notes'] ?: 'None'); ?></div>
                                        </div>
                                        <div class="p-2 border rounded text-center bg-light">
                                            <small class="text-muted"><i class="fa-solid fa-stamp text-primary me-1"></i>Official Digital Receipt &bull; CampusIQ Accounts System</small>
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

<!-- Record Payment Modal -->
<div class="modal fade" id="recordPaymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form method="POST" class="modal-content border-0 shadow">
            <input type="hidden" name="action" value="record_payment">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-hand-holding-dollar me-2"></i>Record Student Fee Payment</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Select Student <span class="text-danger">*</span></label>
                        <select name="student_id" class="form-select form-select-lg" required>
                            <option value="">Choose Student...</option>
                            <?php foreach ($studentsList as $s): ?>
                                <option value="<?php echo $s['id']; ?>">
                                    <?php echo e($s['name']); ?> (<?php echo e($s['student_code']); ?> - <?php echo e($s['class_name'] ?? 'N/A'); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Amount Paid (<?php echo $currency; ?>) <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" name="amount" class="form-control form-control-lg" placeholder="0.00" required>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Payment Channel / Mode <span class="text-danger">*</span></label>
                        <select name="payment_method" class="form-select" required>
                            <option value="Cash" selected>Cash at Accounts Office</option>
                            <option value="MTN Mobile Money">MTN Mobile Money (MoMo)</option>
                            <option value="Telecel Cash">Telecel Cash</option>
                            <option value="AT Money">AT Money (AirtelTigo)</option>
                            <option value="Bank Transfer">Bank Transfer / Direct Deposit</option>
                            <option value="Debit Card">POS / Card Terminal</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Reference / Slip # (Optional)</label>
                        <input type="text" name="reference_number" class="form-control" placeholder="Leave empty to auto-generate">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold">Narration / Remarks</label>
                    <input type="text" name="notes" class="form-control" value="Term Tuition Settlement" placeholder="e.g. Paid in full for Term 1">
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary px-4"><i class="fa-solid fa-check me-1"></i>Save Payment & Issue Receipt</button>
            </div>
        </form>
    </div>
</div>

<script>
function printReceipt(divId) {
    const content = document.getElementById(divId).innerHTML;
    const printWin = window.open('', '', 'height=650,width=800');
    printWin.document.write('<html><head><title>Payment Receipt</title>');
    printWin.document.write('<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">');
    printWin.document.write('<style>body { font-family: sans-serif; padding: 25px; } .brand-mark { text-align: center; margin-bottom: 10px; }</style>');
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
