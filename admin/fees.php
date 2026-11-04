<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin(['super_admin', 'school_admin', 'accountant']);

$db = getDb();
$settings = getSchoolSettings();
$currency = $settings['currency'] ?? 'GH₵';
$adminUser = currentUser();
$error = '';
$success = '';
$activeTab = $_GET['tab'] ?? 'assigned';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_fee') {
        $activeTab = 'assigned';
        $studentId = (int)($_POST['student_id'] ?? 0);
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $dueDate = !empty($_POST['due_date']) ? $_POST['due_date'] : date('Y-m-d', strtotime('+30 days'));
        $status = in_array($_POST['status'] ?? 'pending', ['pending', 'partial', 'paid']) ? $_POST['status'] : 'pending';
        $yearId = getCurrentAcademicYearId() ?? 1;

        if ($studentId <= 0 || $categoryId <= 0 || $amount <= 0) {
            $error = 'Please select a student, fee category, and enter a valid fee amount.';
        } else {
            $stmt = $db->prepare('INSERT INTO fees (student_id, category_id, academic_year_id, amount, due_date, status) VALUES (:student_id, :category_id, :year_id, :amount, :due_date, :status)');
            $stmt->execute([
                'student_id' => $studentId,
                'category_id' => $categoryId,
                'year_id' => $yearId,
                'amount' => $amount,
                'due_date' => $dueDate,
                'status' => $status,
            ]);

            $db->prepare('INSERT INTO activity_logs (user_id, action, details) VALUES (:uid, "Fee Assigned", :details)')
                ->execute([
                    'uid' => $adminUser['id'],
                    'details' => "Assigned fee figure of {$currency} {$amount} to student ID #{$studentId}",
                ]);

            $success = "Fee obligation assigned successfully!";
        }
    } elseif ($action === 'edit_fee') {
        $activeTab = 'assigned';
        $feeId = (int)($_POST['fee_id'] ?? 0);
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $dueDate = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
        $status = in_array($_POST['status'] ?? 'pending', ['pending', 'partial', 'paid', 'overdue']) ? $_POST['status'] : 'pending';

        if ($feeId <= 0 || $amount <= 0 || $categoryId <= 0) {
            $error = 'Please enter a valid fee amount and choose a category.';
        } else {
            $upd = $db->prepare('UPDATE fees SET category_id = :cid, amount = :amt, due_date = :due, status = :status WHERE id = :id');
            $upd->execute([
                'cid' => $categoryId,
                'amt' => $amount,
                'due' => $dueDate,
                'status' => $status,
                'id' => $feeId
            ]);

            $db->prepare('INSERT INTO activity_logs (user_id, action, details) VALUES (:uid, "Fee Updated", :details)')
                ->execute([
                    'uid' => $adminUser['id'],
                    'details' => "Updated fee #{$feeId} figure to {$currency} {$amount}",
                ]);

            $success = "Fee figure updated successfully to {$currency} " . number_format($amount, 2) . "!";
        }
    } elseif ($action === 'delete_fee') {
        $activeTab = 'assigned';
        $feeId = (int)($_POST['fee_id'] ?? 0);
        $db->prepare('DELETE FROM fees WHERE id = :id')->execute(['id' => $feeId]);

        $db->prepare('INSERT INTO activity_logs (user_id, action, details) VALUES (:uid, "Fee Deleted", :details)')
            ->execute([
                'uid' => $adminUser['id'],
                'details' => "Removed fee obligation #{$feeId}",
            ]);

        $success = "Fee record deleted.";
    } elseif ($action === 'create_category') {
        $activeTab = 'categories';
        $name = trim($_POST['name'] ?? '');
        $amount = (float)($_POST['amount'] ?? 0);
        $desc = trim($_POST['description'] ?? '');

        if (empty($name) || $amount <= 0) {
            $error = 'Please provide a category name and a valid amount.';
        } else {
            $ins = $db->prepare('INSERT INTO fee_categories (name, description, amount, status) VALUES (:name, :desc, :amt, "active")');
            $ins->execute(['name' => $name, 'desc' => $desc, 'amt' => $amount]);
            $success = "Fee category '{$name}' created with base figure of {$currency} " . number_format($amount, 2) . ".";
        }
    } elseif ($action === 'edit_category') {
        $activeTab = 'categories';
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $amount = (float)($_POST['amount'] ?? 0);
        $desc = trim($_POST['description'] ?? '');
        $status = in_array($_POST['status'] ?? 'active', ['active', 'inactive']) ? $_POST['status'] : 'active';

        if ($categoryId <= 0 || empty($name) || $amount <= 0) {
            $error = 'Please provide a valid category name and fee figure.';
        } else {
            $upd = $db->prepare('UPDATE fee_categories SET name = :name, description = :desc, amount = :amt, status = :status WHERE id = :id');
            $upd->execute([
                'name' => $name,
                'desc' => $desc,
                'amt' => $amount,
                'status' => $status,
                'id' => $categoryId
            ]);
            $success = "Category '{$name}' updated! Base figure is now {$currency} " . number_format($amount, 2) . ".";
        }
    } elseif ($action === 'delete_category') {
        $activeTab = 'categories';
        $categoryId = (int)($_POST['category_id'] ?? 0);
        
        $chk = $db->prepare('SELECT COUNT(*) FROM fees WHERE category_id = :id');
        $chk->execute(['id' => $categoryId]);
        $linkedCount = (int)$chk->fetchColumn();

        if ($linkedCount > 0) {
            $upd = $db->prepare('UPDATE fee_categories SET status = "inactive" WHERE id = :id');
            $upd->execute(['id' => $categoryId]);
            $success = "Fee category marked as Inactive because {$linkedCount} student record(s) reference it.";
        } else {
            $del = $db->prepare('DELETE FROM fee_categories WHERE id = :id');
            $del->execute(['id' => $categoryId]);
            $success = 'Fee category deleted.';
        }
    }
}

// Fetch students for dropdown
$students = $db->query('SELECT s.id, u.name, s.student_id, c.name as class_name FROM students s JOIN users u ON u.id = s.user_id LEFT JOIN classes c ON c.id = s.class_id WHERE s.status = "active" ORDER BY u.name ASC')->fetchAll();

// Fetch fee categories with counts
$categories = $db->query('
    SELECT fc.*, 
           (SELECT COUNT(*) FROM fees f WHERE f.category_id = fc.id) as assigned_count,
           (SELECT COALESCE(SUM(f.amount), 0) FROM fees f WHERE f.category_id = fc.id) as total_assigned_amount
    FROM fee_categories fc 
    ORDER BY fc.name ASC
')->fetchAll();

// List all fees
$fees = $db->query('
    SELECT f.*, u.name as student_name, s.student_id as student_code, c.name as class_name, fc.name as category_name 
    FROM fees f 
    JOIN students s ON s.id = f.student_id 
    JOIN users u ON u.id = s.user_id 
    JOIN fee_categories fc ON fc.id = f.category_id 
    LEFT JOIN classes c ON c.id = s.class_id
    ORDER BY f.created_at DESC LIMIT 100
')->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-3">
    <div>
        <p class="text-primary fw-semibold mb-1"><i class="fa-solid fa-wallet me-2"></i>Finance &amp; Tariffs</p>
        <h2 class="fw-bold mb-0">Fee Management &amp; Figures</h2>
        <small class="text-muted">Configure institutional fee rates and modify student fee figures.</small>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#createCategoryModal">
            <i class="fa-solid fa-tags me-2"></i>New Fee Category
        </button>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addFeeModal">
            <i class="fa-solid fa-plus me-2"></i>Assign Fee
        </button>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger d-flex align-items-center gap-2 mb-4">
        <i class="fa-solid fa-circle-exclamation fs-5"></i>
        <div><?php echo e($error); ?></div>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success d-flex align-items-center gap-2 mb-4">
        <i class="fa-solid fa-circle-check fs-5"></i>
        <div><?php echo e($success); ?></div>
    </div>
<?php endif; ?>

<!-- Tabs Navigation -->
<ul class="nav nav-pills gap-2 mb-4" id="adminFeeTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link <?php echo $activeTab === 'assigned' ? 'active' : ''; ?> rounded-pill px-4 py-2 fw-semibold" id="assigned-tab" data-bs-toggle="pill" data-bs-target="#assigned-pane" type="button" role="tab">
            <i class="fa-solid fa-users-line me-2"></i>1. Assigned Student Fees (<?php echo count($fees); ?>)
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link <?php echo $activeTab === 'categories' ? 'active' : ''; ?> rounded-pill px-4 py-2 fw-semibold" id="categories-tab" data-bs-toggle="pill" data-bs-target="#categories-pane" type="button" role="tab">
            <i class="fa-solid fa-tags me-2"></i>2. Standard Fee Rates &amp; Categories (<?php echo count($categories); ?>)
        </button>
    </li>
</ul>

<div class="tab-content" id="adminFeeTabsContent">
    <!-- TAB 1: ASSIGNED STUDENT FEES -->
    <div class="tab-pane fade <?php echo $activeTab === 'assigned' ? 'show active' : ''; ?>" id="assigned-pane" role="tabpanel" aria-labelledby="assigned-tab">
        <div class="card p-0 border-0 shadow-sm overflow-hidden mb-4">
            <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="fw-bold mb-0 text-dark">Student Invoices &amp; Obligations</h5>
                    <small class="text-muted">Click "Edit Fee" to alter the billed figure, status, or due date for any student.</small>
                </div>
                <span class="badge bg-light text-secondary"><?php echo count($fees); ?> Record(s)</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Student</th>
                            <th>Class</th>
                            <th>Fee Category</th>
                            <th>Amount Billed</th>
                            <th>Due Date</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($fees)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">
                                    <i class="fa-solid fa-receipt fa-2x mb-3 text-muted d-block"></i>
                                    No fee records found. Click <strong>"Assign Fee"</strong> above to create a bill.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($fees as $fee): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo e($fee['student_name']); ?></strong><br>
                                        <small class="text-muted"><?php echo e($fee['student_code']); ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-secondary border"><?php echo e($fee['class_name'] ?? 'N/A'); ?></span>
                                    </td>
                                    <td><strong><?php echo e($fee['category_name']); ?></strong></td>
                                    <td><strong class="text-primary fs-6"><?php echo e($currency); ?> <?php echo number_format((float)$fee['amount'], 2); ?></strong></td>
                                    <td><?php echo e(!empty($fee['due_date']) ? date('d M Y', strtotime($fee['due_date'])) : 'N/A'); ?></td>
                                    <td>
                                        <?php if ($fee['status'] === 'paid'): ?>
                                            <span class="badge bg-success-subtle text-success border border-success border-opacity-25 px-2 py-1"><i class="fa-solid fa-check me-1"></i>Paid</span>
                                        <?php elseif ($fee['status'] === 'partial'): ?>
                                            <span class="badge bg-warning-subtle text-warning border border-warning border-opacity-25 px-2 py-1"><i class="fa-solid fa-clock me-1"></i>Partial</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger-subtle text-danger border border-danger border-opacity-25 px-2 py-1"><i class="fa-solid fa-circle-exclamation me-1"></i>Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <button type="button" class="btn btn-sm btn-outline-primary me-1" 
                                            onclick='openAdminEditFeeModal(<?php echo json_encode($fee); ?>)' title="Change Fee Figure">
                                            <i class="fa-solid fa-pen-to-square me-1"></i>Edit Fee
                                        </button>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Delete this student fee record?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo csrfToken(); ?>">
                                            <input type="hidden" name="action" value="delete_fee">
                                            <input type="hidden" name="fee_id" value="<?php echo $fee['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- TAB 2: FEE CATEGORIES & BASE FIGURES -->
    <div class="tab-pane fade <?php echo $activeTab === 'categories' ? 'show active' : ''; ?>" id="categories-pane" role="tabpanel" aria-labelledby="categories-tab">
        <div class="card p-0 border-0 shadow-sm overflow-hidden mb-4">
            <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="fw-bold mb-0 text-dark">Institutional Fee Categories &amp; Base Figures</h5>
                    <small class="text-muted">Change standard figures for tuition, transportation, examination, etc.</small>
                </div>
                <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#createCategoryModal">
                    <i class="fa-solid fa-plus me-1"></i>Add New Category
                </button>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Category Name</th>
                            <th>Description</th>
                            <th>Standard Figure (Rate)</th>
                            <th>Active Invoices</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($categories)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-5 text-muted">
                                    <i class="fa-solid fa-tags fs-1 text-secondary opacity-50 mb-3 d-block"></i>
                                    No fee categories configured.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($categories as $cat): ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold text-dark fs-6">
                                            <i class="fa-solid fa-tag text-primary me-2"></i><?php echo e($cat['name']); ?>
                                        </div>
                                    </td>
                                    <td><span class="text-muted small"><?php echo !empty($cat['description']) ? e($cat['description']) : '<em>No description</em>'; ?></span></td>
                                    <td>
                                        <span class="badge bg-primary bg-opacity-10 text-primary fs-6 px-3 py-2 border border-primary border-opacity-25 fw-bold">
                                            <?php echo $currency; ?> <?php echo number_format((float)$cat['amount'], 2); ?>
                                        </span>
                                    </td>
                                    <td><span class="badge bg-light text-dark border"><?php echo (int)$cat['assigned_count']; ?> Student(s)</span></td>
                                    <td>
                                        <?php if ($cat['status'] === 'active'): ?>
                                            <span class="badge bg-success-subtle text-success border border-success border-opacity-25 px-2 py-1">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary-subtle text-secondary border border-secondary border-opacity-25 px-2 py-1">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <button type="button" class="btn btn-sm btn-outline-primary me-1" 
                                            onclick='openAdminEditCategoryModal(<?php echo json_encode($cat); ?>)' title="Change fee figure or details">
                                            <i class="fa-solid fa-pen-to-square me-1"></i>Edit Figure
                                        </button>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to remove or deactivate this fee category?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo csrfToken(); ?>">
                                            <input type="hidden" name="action" value="delete_category">
                                            <input type="hidden" name="category_id" value="<?php echo $cat['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Assign Fee Modal -->
<div class="modal fade" id="addFeeModal" tabindex="-1" aria-labelledby="addFeeModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow" style="border-radius: 16px;">
            <div class="modal-header border-0 pb-0">
                <div>
                    <h5 class="modal-title fw-bold" id="addFeeModalLabel">Assign Student Fee</h5>
                    <small class="text-muted">Issue a fee invoice or payment obligation</small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" class="p-3">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo csrfToken(); ?>">
                    <input type="hidden" name="action" value="add_fee">

                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold">Select Student <span class="text-danger">*</span></label>
                            <select class="form-select" name="student_id" required>
                                <option value="">-- Choose Student --</option>
                                <?php foreach ($students as $stu): ?>
                                    <option value="<?php echo (int) $stu['id']; ?>">
                                        <?php echo e($stu['name']); ?> (<?php echo e($stu['student_id']); ?><?php echo !empty($stu['class_name']) ? ' - ' . e($stu['class_name']) : ''; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Fee Category <span class="text-danger">*</span></label>
                            <select class="form-select" name="category_id" id="feeCategorySelect" required>
                                <option value="">-- Choose Category --</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo (int) $cat['id']; ?>" data-amount="<?php echo (float) $cat['amount']; ?>">
                                        <?php echo e($cat['name']); ?> (<?php echo e($currency); ?> <?php echo number_format((float)$cat['amount'], 2); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Amount Figure (<?php echo e($currency); ?>) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" class="form-control" name="amount" id="feeAmountInput" required placeholder="0.00">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Due Date</label>
                            <input type="date" class="form-control" name="due_date" value="<?php echo date('Y-m-d', strtotime('+30 days')); ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Initial Status</label>
                            <select class="form-select" name="status">
                                <option value="pending" selected>Pending</option>
                                <option value="partial">Partial</option>
                                <option value="paid">Paid</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="fa-solid fa-floppy-disk me-1"></i> Save Fee
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Admin Edit Fee Modal (Change Student Fee Figure) -->
<div class="modal fade" id="adminEditFeeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content border-0 shadow" style="border-radius: 16px;">
            <input type="hidden" name="csrf_token" value="<?php echo csrfToken(); ?>">
            <input type="hidden" name="action" value="edit_fee">
            <input type="hidden" name="fee_id" id="adminEditFeeId">
            <div class="modal-header border-0 pb-0">
                <div>
                    <h5 class="modal-title fw-bold">Edit Student Fee Figure</h5>
                    <small class="text-muted" id="adminEditFeeStudentInfo">Update fee details</small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Fee Category <span class="text-danger">*</span></label>
                    <select name="category_id" id="adminEditFeeCategory" class="form-select" required>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>">
                                <?php echo e($cat['name']); ?> (Default: <?php echo $currency; ?> <?php echo number_format((float)$cat['amount'], 2); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Amount Figure (<?php echo $currency; ?>) <span class="text-danger">*</span></label>
                    <input type="number" step="0.01" name="amount" id="adminEditFeeAmount" class="form-control" required>
                    <small class="text-muted">Enter the adjusted figure for this student.</small>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Due Date</label>
                        <input type="date" name="due_date" id="adminEditFeeDueDate" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Payment Status</label>
                        <select name="status" id="adminEditFeeStatus" class="form-select">
                            <option value="pending">Pending</option>
                            <option value="partial">Partial</option>
                            <option value="paid">Paid</option>
                            <option value="overdue">Overdue</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary px-4"><i class="fa-solid fa-floppy-disk me-1"></i>Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Admin Create Category Modal -->
<div class="modal fade" id="createCategoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content border-0 shadow" style="border-radius: 16px;">
            <input type="hidden" name="csrf_token" value="<?php echo csrfToken(); ?>">
            <input type="hidden" name="action" value="create_category">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-tags me-2 text-primary"></i>Create Fee Category</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Category Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control" placeholder="e.g. Tuition Fee, ICT Lab, Sports" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Standard Figure (<?php echo $currency; ?>) <span class="text-danger">*</span></label>
                    <input type="number" step="0.01" name="amount" class="form-control" placeholder="0.00" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Description</label>
                    <textarea name="description" class="form-control" rows="3" placeholder="Fee description..."></textarea>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary px-4"><i class="fa-solid fa-floppy-disk me-1"></i>Create Category</button>
            </div>
        </form>
    </div>
</div>

<!-- Admin Edit Category Modal (Change Base Category Figure) -->
<div class="modal fade" id="adminEditCategoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content border-0 shadow" style="border-radius: 16px;">
            <input type="hidden" name="csrf_token" value="<?php echo csrfToken(); ?>">
            <input type="hidden" name="action" value="edit_category">
            <input type="hidden" name="category_id" id="adminEditCatId">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-sliders me-2 text-primary"></i>Edit Category Base Figure</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Category Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" id="adminEditCatName" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Standard Figure / Rate (<?php echo $currency; ?>) <span class="text-danger">*</span></label>
                    <input type="number" step="0.01" name="amount" id="adminEditCatAmount" class="form-control" required>
                    <small class="text-muted">Updating this figure changes the default tariff for future fee billings.</small>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Description</label>
                    <textarea name="description" id="adminEditCatDesc" class="form-control" rows="3"></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Status</label>
                    <select name="status" id="adminEditCatStatus" class="form-select">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary px-4"><i class="fa-solid fa-floppy-disk me-1"></i>Update Figure</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const catSelect = document.getElementById('feeCategorySelect');
    const amountInput = document.getElementById('feeAmountInput');
    if (catSelect && amountInput) {
        catSelect.addEventListener('change', function() {
            const opt = this.options[this.selectedIndex];
            const amt = opt ? opt.getAttribute('data-amount') : null;
            if (amt) {
                amountInput.value = amt;
            }
        });
    }
});

function openAdminEditCategoryModal(cat) {
    document.getElementById('adminEditCatId').value = cat.id || '';
    document.getElementById('adminEditCatName').value = cat.name || '';
    document.getElementById('adminEditCatAmount').value = parseFloat(cat.amount || 0).toFixed(2);
    document.getElementById('adminEditCatDesc').value = cat.description || '';
    document.getElementById('adminEditCatStatus').value = cat.status || 'active';

    const modalEl = document.getElementById('adminEditCategoryModal');
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    modal.show();
}

function openAdminEditFeeModal(fee) {
    document.getElementById('adminEditFeeId').value = fee.id || '';
    document.getElementById('adminEditFeeStudentInfo').innerText = (fee.student_name || 'Student') + ' (' + (fee.student_code || '') + ')';
    document.getElementById('adminEditFeeCategory').value = fee.category_id || '';
    document.getElementById('adminEditFeeAmount').value = parseFloat(fee.amount || 0).toFixed(2);
    document.getElementById('adminEditFeeDueDate').value = fee.due_date || '';
    document.getElementById('adminEditFeeStatus').value = fee.status || 'pending';

    const modalEl = document.getElementById('adminEditFeeModal');
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    modal.show();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
