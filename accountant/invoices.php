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

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_invoice') {
        $studentId = (int)($_POST['student_id'] ?? 0);
        $amount = (float)($_POST['total_amount'] ?? 0);
        $status = in_array($_POST['status'] ?? '', ['draft', 'issued', 'paid']) ? $_POST['status'] : 'issued';

        if ($studentId <= 0 || $amount <= 0) {
            $errorMsg = 'Please select a student and enter a valid invoice amount.';
        } else {
            // Auto generate invoice number
            $invNum = 'INV-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $ins = $db->prepare('INSERT INTO invoices (student_id, invoice_number, total_amount, status) VALUES (:sid, :inv_num, :amt, :status)');
            $ins->execute([
                'sid' => $studentId,
                'inv_num' => $invNum,
                'amt' => $amount,
                'status' => $status
            ]);
            $successMsg = "Invoice {$invNum} created successfully!";
        }
    } elseif ($action === 'update_invoice_status') {
        $invoiceId = (int)($_POST['invoice_id'] ?? 0);
        $newStatus = in_array($_POST['new_status'] ?? '', ['draft', 'issued', 'paid', 'cancelled']) ? $_POST['new_status'] : 'issued';

        $upd = $db->prepare('UPDATE invoices SET status = :status WHERE id = :id');
        $upd->execute(['status' => $newStatus, 'id' => $invoiceId]);
        $successMsg = 'Invoice status updated successfully.';
    } elseif ($action === 'delete_invoice') {
        $invoiceId = (int)($_POST['invoice_id'] ?? 0);
        $del = $db->prepare('DELETE FROM invoices WHERE id = :id');
        $del->execute(['id' => $invoiceId]);
        $successMsg = 'Invoice deleted.';
    }
}

// Fetch filter parameters
$filterStatus = trim($_GET['status'] ?? '');
$filterClass = (int)($_GET['class_id'] ?? 0);
$search = trim($_GET['search'] ?? '');

$sql = "
    SELECT inv.*, s.student_id as student_code, u.name as student_name, c.name as class_name,
           (SELECT COALESCE(SUM(p.amount), 0) FROM payments p WHERE p.invoice_id = inv.id) as amount_paid
    FROM invoices inv
    JOIN students s ON s.id = inv.student_id
    JOIN users u ON u.id = s.user_id
    LEFT JOIN classes c ON c.id = s.class_id
    WHERE 1=1
";
$params = [];

if (!empty($filterStatus)) {
    $sql .= " AND inv.status = :status";
    $params['status'] = $filterStatus;
}
if ($filterClass > 0) {
    $sql .= " AND s.class_id = :cid";
    $params['cid'] = $filterClass;
}
if (!empty($search)) {
    $sql .= " AND (u.name LIKE :search OR s.student_id LIKE :search2 OR inv.invoice_number LIKE :search3)";
    $params['search'] = "%{$search}%";
    $params['search2'] = "%{$search}%";
    $params['search3'] = "%{$search}%";
}
$sql .= " ORDER BY inv.created_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$invoices = $stmt->fetchAll();

// Metrics
$totalInvoiced = array_sum(array_column($invoices, 'total_amount'));
$paidCount = count(array_filter($invoices, fn($i) => $i['status'] === 'paid'));
$issuedCount = count(array_filter($invoices, fn($i) => $i['status'] === 'issued'));

// Fetch students and classes for modal & filter
$studentsList = $db->query('SELECT s.id, s.student_id as student_code, u.name, c.name as class_name FROM students s JOIN users u ON u.id = s.user_id LEFT JOIN classes c ON c.id = s.class_id WHERE s.status = "active" ORDER BY u.name ASC')->fetchAll();
$classesList = $db->query('SELECT id, name FROM classes ORDER BY name ASC')->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-3">
    <div>
        <p class="text-primary fw-semibold mb-1"><i class="fa-solid fa-file-invoice-dollar me-2"></i>Accounts Department</p>
        <h2 class="fw-bold mb-0">Student Invoices</h2>
        <small class="text-muted">Issue, track, and manage official fee billing statements for all enrolled students.</small>
    </div>
    <div>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createInvoiceModal">
            <i class="fa-solid fa-plus me-2"></i>Generate Invoice
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
            <div class="rounded-3 p-3 bg-primary bg-opacity-10 text-primary fs-3">
                <i class="fa-solid fa-receipt"></i>
            </div>
            <div>
                <span class="text-muted small fw-semibold">Total Invoices</span>
                <h4 class="fw-bold mb-0 text-dark"><?php echo count($invoices); ?></h4>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card p-3 border-0 shadow-sm d-flex flex-row align-items-center gap-3">
            <div class="rounded-3 p-3 bg-success bg-opacity-10 text-success fs-3">
                <i class="fa-solid fa-file-circle-check"></i>
            </div>
            <div>
                <span class="text-muted small fw-semibold">Paid Invoices</span>
                <h4 class="fw-bold mb-0 text-dark"><?php echo $paidCount; ?></h4>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card p-3 border-0 shadow-sm d-flex flex-row align-items-center gap-3">
            <div class="rounded-3 p-3 bg-warning bg-opacity-10 text-warning fs-3">
                <i class="fa-solid fa-hourglass-half"></i>
            </div>
            <div>
                <span class="text-muted small fw-semibold">Pending / Issued</span>
                <h4 class="fw-bold mb-0 text-dark"><?php echo $issuedCount; ?></h4>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card p-3 border-0 shadow-sm d-flex flex-row align-items-center gap-3">
            <div class="rounded-3 p-3 bg-info bg-opacity-10 text-info fs-3">
                <i class="fa-solid fa-wallet"></i>
            </div>
            <div>
                <span class="text-muted small fw-semibold">Total Value Billed</span>
                <h4 class="fw-bold mb-0 text-dark"><?php echo $currency; ?> <?php echo number_format($totalInvoiced, 2); ?></h4>
            </div>
        </div>
    </div>
</div>

<!-- Filters Bar -->
<div class="card p-3 mb-4 border-0 shadow-sm">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-4">
            <label class="form-label small fw-bold text-muted mb-1">Search Student / Invoice #</label>
            <input type="text" name="search" class="form-control form-control-sm" placeholder="e.g. Kwame or INV-2026..." value="<?php echo e($search); ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-bold text-muted mb-1">Status</label>
            <select name="status" class="form-select form-select-sm">
                <option value="">All Statuses</option>
                <option value="issued" <?php echo ($filterStatus === 'issued') ? 'selected' : ''; ?>>Issued / Unpaid</option>
                <option value="paid" <?php echo ($filterStatus === 'paid') ? 'selected' : ''; ?>>Paid</option>
                <option value="draft" <?php echo ($filterStatus === 'draft') ? 'selected' : ''; ?>>Draft</option>
                <option value="cancelled" <?php echo ($filterStatus === 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-bold text-muted mb-1">Class</label>
            <select name="class_id" class="form-select form-select-sm">
                <option value="">All Classes</option>
                <?php foreach ($classesList as $c): ?>
                    <option value="<?php echo $c['id']; ?>" <?php echo ($filterClass === (int)$c['id']) ? 'selected' : ''; ?>>
                        <?php echo e($c['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2 d-flex gap-2">
            <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fa-solid fa-filter me-1"></i>Filter</button>
            <a href="invoices.php" class="btn btn-light btn-sm"><i class="fa-solid fa-rotate-left"></i></a>
        </div>
    </form>
</div>

<!-- Invoices Table -->
<div class="card p-0 border-0 shadow-sm overflow-hidden">
    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
        <h5 class="fw-bold mb-0 text-dark">Invoice Registry</h5>
        <span class="badge bg-light text-secondary"><?php echo count($invoices); ?> Invoice(s)</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Invoice #</th>
                    <th>Student Details</th>
                    <th>Class</th>
                    <th>Total Billed</th>
                    <th>Amount Paid</th>
                    <th>Status</th>
                    <th>Created Date</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($invoices)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-5 text-muted">
                            <i class="fa-solid fa-file-invoice fs-1 text-secondary opacity-50 mb-3 d-block"></i>
                            No invoices found matching criteria. Click <strong>"Generate Invoice"</strong> to bill a student.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($invoices as $inv): 
                        $dueBal = max(0, (float)$inv['total_amount'] - (float)$inv['amount_paid']);
                    ?>
                        <tr>
                            <td>
                                <strong class="text-primary font-monospace"><?php echo e($inv['invoice_number']); ?></strong>
                            </td>
                            <td>
                                <div class="fw-bold text-dark"><?php echo e($inv['student_name']); ?></div>
                                <small class="text-muted"><?php echo e($inv['student_code']); ?></small>
                            </td>
                            <td>
                                <span class="badge bg-secondary bg-opacity-10 text-secondary border">
                                    <?php echo e($inv['class_name'] ?? 'N/A'); ?>
                                </span>
                            </td>
                            <td>
                                <strong><?php echo $currency; ?> <?php echo number_format((float)$inv['total_amount'], 2); ?></strong>
                            </td>
                            <td>
                                <span class="text-success fw-semibold"><?php echo $currency; ?> <?php echo number_format((float)$inv['amount_paid'], 2); ?></span>
                            </td>
                            <td>
                                <?php if ($inv['status'] === 'paid'): ?>
                                    <span class="badge bg-success-subtle text-success border border-success border-opacity-25 px-2 py-1"><i class="fa-solid fa-check me-1"></i>Paid</span>
                                <?php elseif ($inv['status'] === 'issued'): ?>
                                    <span class="badge bg-warning-subtle text-warning border border-warning border-opacity-25 px-2 py-1"><i class="fa-solid fa-clock me-1"></i>Issued</span>
                                <?php elseif ($inv['status'] === 'draft'): ?>
                                    <span class="badge bg-secondary-subtle text-secondary px-2 py-1">Draft</span>
                                <?php else: ?>
                                    <span class="badge bg-danger-subtle text-danger px-2 py-1">Cancelled</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <small class="text-muted"><?php echo date('d M Y', strtotime($inv['created_at'])); ?></small>
                            </td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                    <!-- Printable View -->
                                    <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#viewInvModal_<?php echo $inv['id']; ?>" title="Print Official Invoice">
                                        <i class="fa-solid fa-print"></i>
                                    </button>

                                    <!-- Status Toggle Dropdown -->
                                    <div class="btn-group btn-group-sm">
                                        <button type="button" class="btn btn-light border dropdown-toggle" data-bs-toggle="dropdown" title="Change Status">
                                            <i class="fa-solid fa-sliders"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                            <li>
                                                <form method="POST">
                                                    <input type="hidden" name="action" value="update_invoice_status">
                                                    <input type="hidden" name="invoice_id" value="<?php echo $inv['id']; ?>">
                                                    <input type="hidden" name="new_status" value="paid">
                                                    <button type="submit" class="dropdown-item text-success"><i class="fa-solid fa-check me-2"></i>Mark as Paid</button>
                                                </form>
                                            </li>
                                            <li>
                                                <form method="POST">
                                                    <input type="hidden" name="action" value="update_invoice_status">
                                                    <input type="hidden" name="invoice_id" value="<?php echo $inv['id']; ?>">
                                                    <input type="hidden" name="new_status" value="issued">
                                                    <button type="submit" class="dropdown-item text-warning"><i class="fa-solid fa-clock me-2"></i>Mark as Issued</button>
                                                </form>
                                            </li>
                                            <li>
                                                <form method="POST">
                                                    <input type="hidden" name="action" value="update_invoice_status">
                                                    <input type="hidden" name="invoice_id" value="<?php echo $inv['id']; ?>">
                                                    <input type="hidden" name="new_status" value="cancelled">
                                                    <button type="submit" class="dropdown-item text-danger"><i class="fa-solid fa-ban me-2"></i>Cancel Invoice</button>
                                                </form>
                                            </li>
                                        </ul>
                                    </div>

                                    <!-- Delete -->
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Delete this invoice?');">
                                        <input type="hidden" name="action" value="delete_invoice">
                                        <input type="hidden" name="invoice_id" value="<?php echo $inv['id']; ?>">
                                        <button type="submit" class="btn btn-outline-danger" title="Delete">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>

                        <!-- Printable Invoice Modal -->
                        <div class="modal fade" id="viewInvModal_<?php echo $inv['id']; ?>" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog modal-dialog-centered modal-lg">
                                <div class="modal-content border-0 shadow-lg">
                                    <div class="modal-body p-5" id="printableInv_<?php echo $inv['id']; ?>">
                                        <!-- Header -->
                                        <div class="d-flex justify-content-between align-items-start border-bottom pb-4 mb-4">
                                            <div>
                                                <div class="brand-mark mb-2 text-primary fs-2"><i class="fa-solid fa-graduation-cap"></i></div>
                                                <h4 class="fw-bold mb-1 text-dark"><?php echo e($settings['school_name'] ?? 'CampusIQ'); ?></h4>
                                                <p class="text-muted small mb-0"><?php echo e($settings['address'] ?? 'Accra, Ghana'); ?> &bull; <?php echo e($settings['phone'] ?? '+233 302 000 000'); ?></p>
                                            </div>
                                            <div class="text-end">
                                                <h3 class="fw-bold text-primary mb-1">INVOICE</h3>
                                                <span class="badge bg-light text-dark border font-monospace fs-6"><?php echo e($inv['invoice_number']); ?></span>
                                                <div class="text-muted small mt-2">Date: <?php echo date('d M Y', strtotime($inv['created_at'])); ?></div>
                                            </div>
                                        </div>

                                        <!-- Billed To -->
                                        <div class="row mb-4">
                                            <div class="col-6">
                                                <span class="text-muted small d-block">Billed To (Student):</span>
                                                <h5 class="fw-bold text-dark mb-1"><?php echo e($inv['student_name']); ?></h5>
                                                <div class="text-muted small">Student ID: <strong><?php echo e($inv['student_code']); ?></strong></div>
                                                <div class="text-muted small">Class: <strong><?php echo e($inv['class_name'] ?? 'N/A'); ?></strong></div>
                                            </div>
                                            <div class="col-6 text-end">
                                                <span class="text-muted small d-block">Status:</span>
                                                <h4 class="fw-bold text-uppercase <?php echo ($inv['status'] === 'paid') ? 'text-success' : 'text-danger'; ?>">
                                                    <?php echo e($inv['status']); ?>
                                                </h4>
                                            </div>
                                        </div>

                                        <!-- Summary Table -->
                                        <div class="table-responsive mb-4">
                                            <table class="table table-bordered align-middle">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>Billing Description</th>
                                                        <th class="text-end">Amount</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <tr>
                                                        <td>Institutional Academic Tuition & Term Fees</td>
                                                        <td class="text-end fw-bold"><?php echo $currency; ?> <?php echo number_format((float)$inv['total_amount'], 2); ?></td>
                                                    </tr>
                                                    <tr>
                                                        <td class="text-end text-muted">Total Paid to Date:</td>
                                                        <td class="text-end text-success fw-bold"><?php echo $currency; ?> <?php echo number_format((float)$inv['amount_paid'], 2); ?></td>
                                                    </tr>
                                                    <tr class="table-light">
                                                        <td class="text-end fw-bold">Balance Due:</td>
                                                        <td class="text-end fw-bold text-danger fs-5"><?php echo $currency; ?> <?php echo number_format($dueBal, 2); ?></td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>

                                        <!-- Footer notes -->
                                        <div class="p-3 bg-light rounded text-muted small text-center">
                                            Please make payment via Mobile Money, Bank Transfer, or at the Accounts Office before term resumption.
                                        </div>
                                    </div>
                                    <div class="modal-footer bg-light d-flex justify-content-between">
                                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                                        <button type="button" class="btn btn-primary btn-sm" onclick="printInvoice('printableInv_<?php echo $inv['id']; ?>');">
                                            <i class="fa-solid fa-print me-1"></i>Print Invoice
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

<!-- Generate Invoice Modal -->
<div class="modal fade" id="createInvoiceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content border-0 shadow">
            <input type="hidden" name="action" value="create_invoice">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-plus me-2"></i>Issue Student Invoice</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <label class="form-label fw-bold">Select Student <span class="text-danger">*</span></label>
                    <select name="student_id" class="form-select" required>
                        <option value="">Choose Student...</option>
                        <?php foreach ($studentsList as $s): ?>
                            <option value="<?php echo $s['id']; ?>">
                                <?php echo e($s['name']); ?> (<?php echo e($s['student_code']); ?> - <?php echo e($s['class_name'] ?? 'No Class'); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Total Bill Amount (<?php echo $currency; ?>) <span class="text-danger">*</span></label>
                    <input type="number" step="0.01" name="total_amount" class="form-control form-control-lg" placeholder="0.00" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Initial Status</label>
                    <select name="status" class="form-select">
                        <option value="issued" selected>Issued (Payable)</option>
                        <option value="draft">Draft (Review Only)</option>
                        <option value="paid">Paid (Fully Settled)</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-paper-plane me-1"></i>Issue Invoice</button>
            </div>
        </form>
    </div>
</div>

<script>
function printInvoice(divId) {
    const content = document.getElementById(divId).innerHTML;
    const printWin = window.open('', '', 'height=700,width=850');
    printWin.document.write('<html><head><title>Print Invoice</title>');
    printWin.document.write('<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">');
    printWin.document.write('<style>body { font-family: sans-serif; padding: 25px; } .brand-mark { text-align: left; }</style>');
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
