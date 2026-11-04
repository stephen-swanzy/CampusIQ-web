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
$activeTab = $_GET['tab'] ?? 'categories';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_category') {
        $activeTab = 'categories';
        $name = trim($_POST['name'] ?? '');
        $amount = (float)($_POST['amount'] ?? 0);
        $desc = trim($_POST['description'] ?? '');

        if (empty($name) || $amount <= 0) {
            $errorMsg = 'Please provide a category name and a valid amount.';
        } else {
            $ins = $db->prepare('INSERT INTO fee_categories (name, description, amount, status) VALUES (:name, :desc, :amt, "active")');
            $ins->execute(['name' => $name, 'desc' => $desc, 'amt' => $amount]);
            $successMsg = "Fee category '{$name}' created with standard figure of {$currency} " . number_format($amount, 2) . ".";
        }
    } elseif ($action === 'edit_category') {
        $activeTab = 'categories';
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $amount = (float)($_POST['amount'] ?? 0);
        $desc = trim($_POST['description'] ?? '');
        $status = in_array($_POST['status'] ?? 'active', ['active', 'inactive']) ? $_POST['status'] : 'active';

        if ($categoryId <= 0 || empty($name) || $amount <= 0) {
            $errorMsg = 'Please provide a valid category name and fee amount.';
        } else {
            $upd = $db->prepare('UPDATE fee_categories SET name = :name, description = :desc, amount = :amt, status = :status WHERE id = :id');
            $upd->execute([
                'name' => $name,
                'desc' => $desc,
                'amt' => $amount,
                'status' => $status,
                'id' => $categoryId
            ]);
            $successMsg = "Fee category '{$name}' updated successfully! Standard figure is now {$currency} " . number_format($amount, 2) . ".";
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
            $successMsg = "Fee category has {$linkedCount} assigned student records, so its status was safely set to Inactive.";
        } else {
            $del = $db->prepare('DELETE FROM fee_categories WHERE id = :id');
            $del->execute(['id' => $categoryId]);
            $successMsg = 'Fee category deleted successfully.';
        }
    } elseif ($action === 'assign_fee') {
        $activeTab = 'assigned';
        $studentId = (int)($_POST['student_id'] ?? 0);
        $classId = (int)($_POST['class_id'] ?? 0);
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $dueDate = !empty($_POST['due_date']) ? $_POST['due_date'] : date('Y-m-d', strtotime('+30 days'));
        $yearId = getCurrentAcademicYearId() ?? 1;

        if ($categoryId <= 0 || $amount <= 0) {
            $errorMsg = 'Please choose a fee category and enter a valid fee amount.';
        } elseif ($studentId > 0) {
            // Assign to single student
            $ins = $db->prepare('INSERT INTO fees (student_id, category_id, academic_year_id, amount, due_date, status) VALUES (:sid, :cid, :yid, :amt, :due, "pending")');
            $ins->execute([
                'sid' => $studentId,
                'cid' => $categoryId,
                'yid' => $yearId,
                'amt' => $amount,
                'due' => $dueDate
            ]);
            $successMsg = 'Fee obligation assigned to student successfully.';
        } elseif ($classId > 0) {
            // Batch assign to entire class
            $studentsInClass = $db->query("SELECT id FROM students WHERE class_id = {$classId} AND status = 'active'")->fetchAll();
            $ins = $db->prepare('INSERT INTO fees (student_id, category_id, academic_year_id, amount, due_date, status) VALUES (:sid, :cid, :yid, :amt, :due, "pending")');
            $count = 0;
            foreach ($studentsInClass as $s) {
                $ins->execute([
                    'sid' => $s['id'],
                    'cid' => $categoryId,
                    'yid' => $yearId,
                    'amt' => $amount,
                    'due' => $dueDate
                ]);
                $count++;
            }
            $successMsg = "Fee successfully assigned to all {$count} students in selected class!";
        } else {
            $errorMsg = 'Please select either a specific student or an entire class.';
        }
    } elseif ($action === 'edit_fee') {
        $activeTab = 'assigned';
        $feeId = (int)($_POST['fee_id'] ?? 0);
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $dueDate = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
        $status = in_array($_POST['status'] ?? 'pending', ['pending', 'partial', 'paid', 'overdue']) ? $_POST['status'] : 'pending';

        if ($feeId <= 0 || $amount <= 0 || $categoryId <= 0) {
            $errorMsg = 'Please enter a valid fee figure and select a fee category.';
        } else {
            $upd = $db->prepare('UPDATE fees SET category_id = :cid, amount = :amt, due_date = :due, status = :status WHERE id = :id');
            $upd->execute([
                'cid' => $categoryId,
                'amt' => $amount,
                'due' => $dueDate,
                'status' => $status,
                'id' => $feeId
            ]);
            $successMsg = "Student fee figure updated successfully to {$currency} " . number_format($amount, 2) . "!";
        }
    } elseif ($action === 'delete_fee') {
        $activeTab = 'assigned';
        $feeId = (int)($_POST['fee_id'] ?? 0);
        $del = $db->prepare('DELETE FROM fees WHERE id = :id');
        $del->execute(['id' => $feeId]);
        $successMsg = 'Fee record removed.';
    }
}

// Fetch categories with summary statistics
$categories = $db->query('
    SELECT fc.*, 
           (SELECT COUNT(*) FROM fees f WHERE f.category_id = fc.id) as assigned_count,
           (SELECT COALESCE(SUM(f.amount), 0) FROM fees f WHERE f.category_id = fc.id) as total_assigned_amount
    FROM fee_categories fc 
    ORDER BY fc.name ASC
')->fetchAll();

// Fetch filter parameters
$filterClass = (int)($_GET['class_id'] ?? 0);
$filterCat = (int)($_GET['category_id'] ?? 0);
$filterStatus = trim($_GET['status'] ?? '');

if ($filterClass > 0 || $filterCat > 0 || !empty($filterStatus)) {
    $activeTab = 'assigned';
}

$sql = "
    SELECT f.*, u.name as student_name, s.student_id as student_code, c.name as class_name, fc.name as category_name
    FROM fees f
    JOIN students s ON s.id = f.student_id
    JOIN users u ON u.id = s.user_id
    JOIN fee_categories fc ON fc.id = f.category_id
    LEFT JOIN classes c ON c.id = s.class_id
    WHERE 1=1
";
$params = [];

if ($filterClass > 0) {
    $sql .= " AND s.class_id = :cid";
    $params['cid'] = $filterClass;
}
if ($filterCat > 0) {
    $sql .= " AND f.category_id = :cat_id";
    $params['cat_id'] = $filterCat;
}
if (!empty($filterStatus)) {
    $sql .= " AND f.status = :status";
    $params['status'] = $filterStatus;
}
$sql .= " ORDER BY f.created_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$fees = $stmt->fetchAll();

// Metrics
$totalBilled = array_sum(array_column($fees, 'amount'));
$paidCount = count(array_filter($fees, fn($f) => $f['status'] === 'paid'));
$pendingCount = count(array_filter($fees, fn($f) => in_array($f['status'], ['pending', 'partial'])));

// Lists for dropdowns
$studentsList = $db->query('SELECT s.id, s.student_id as student_code, u.name, c.name as class_name FROM students s JOIN users u ON u.id = s.user_id LEFT JOIN classes c ON c.id = s.class_id WHERE s.status = "active" ORDER BY u.name ASC')->fetchAll();
$classesList = $db->query('SELECT id, name FROM classes ORDER BY name ASC')->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-3">
    <div>
        <p class="text-primary fw-semibold mb-1"><i class="fa-solid fa-wallet me-2"></i>Accounts &amp; Billing</p>
        <h2 class="fw-bold mb-0">Fee Structure &amp; Billing Figures</h2>
        <small class="text-muted">Manage standard fee rates (Tuition, Transport, etc.) and edit individual student bills.</small>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#createCategoryModal">
            <i class="fa-solid fa-tags me-2"></i>New Fee Category
        </button>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#assignFeeModal">
            <i class="fa-solid fa-plus me-2"></i>Assign Fee
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
                <i class="fa-solid fa-tags"></i>
            </div>
            <div>
                <span class="text-muted small fw-semibold">Fee Categories</span>
                <h4 class="fw-bold mb-0 text-dark"><?php echo count($categories); ?> Types</h4>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card p-3 border-0 shadow-sm d-flex flex-row align-items-center gap-3">
            <div class="rounded-3 p-3 bg-success bg-opacity-10 text-success fs-3">
                <i class="fa-solid fa-check-double"></i>
            </div>
            <div>
                <span class="text-muted small fw-semibold">Settled Obligations</span>
                <h4 class="fw-bold mb-0 text-dark"><?php echo $paidCount; ?></h4>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card p-3 border-0 shadow-sm d-flex flex-row align-items-center gap-3">
            <div class="rounded-3 p-3 bg-warning bg-opacity-10 text-warning fs-3">
                <i class="fa-solid fa-clock"></i>
            </div>
            <div>
                <span class="text-muted small fw-semibold">Pending Settlement</span>
                <h4 class="fw-bold mb-0 text-dark"><?php echo $pendingCount; ?></h4>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card p-3 border-0 shadow-sm d-flex flex-row align-items-center gap-3">
            <div class="rounded-3 p-3 bg-info bg-opacity-10 text-info fs-3">
                <i class="fa-solid fa-coins"></i>
            </div>
            <div>
                <span class="text-muted small fw-semibold">Gross Obligation Value</span>
                <h4 class="fw-bold mb-0 text-dark"><?php echo $currency; ?> <?php echo number_format($totalBilled, 2); ?></h4>
            </div>
        </div>
    </div>
</div>

<!-- Tabs Navigation -->
<ul class="nav nav-pills gap-2 mb-4" id="feeManagementTabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link <?php echo $activeTab === 'categories' ? 'active' : ''; ?> rounded-pill px-4 py-2 fw-semibold" id="categories-tab" data-bs-toggle="pill" data-bs-target="#categories-pane" type="button" role="tab" aria-controls="categories-pane" aria-selected="<?php echo $activeTab === 'categories' ? 'true' : 'false'; ?>">
            <i class="fa-solid fa-sliders me-2"></i>1. Standard Fee Categories &amp; Base Figures
            <span class="badge bg-secondary bg-opacity-25 text-dark ms-2"><?php echo count($categories); ?></span>
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link <?php echo $activeTab === 'assigned' ? 'active' : ''; ?> rounded-pill px-4 py-2 fw-semibold" id="assigned-tab" data-bs-toggle="pill" data-bs-target="#assigned-pane" type="button" role="tab" aria-controls="assigned-pane" aria-selected="<?php echo $activeTab === 'assigned' ? 'true' : 'false'; ?>">
            <i class="fa-solid fa-user-tag me-2"></i>2. Assigned Student Fee Bills
            <span class="badge bg-secondary bg-opacity-25 text-dark ms-2"><?php echo count($fees); ?></span>
        </button>
    </li>
</ul>

<div class="tab-content" id="feeManagementTabsContent">
    <!-- TAB 1: FEE CATEGORIES & BASE FIGURES -->
    <div class="tab-pane fade <?php echo $activeTab === 'categories' ? 'show active' : ''; ?>" id="categories-pane" role="tabpanel" aria-labelledby="categories-tab">
        <div class="card p-0 border-0 shadow-sm overflow-hidden mb-4">
            <div class="card-header bg-white py-3 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <h5 class="fw-bold mb-0 text-dark">Institutional Fee Rates &amp; Tariffs</h5>
                    <small class="text-muted">Change base figures here. New invoices and fee assignments will automatically use these amounts.</small>
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
                            <th>Total Billed</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($categories)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">
                                    <i class="fa-solid fa-tags fs-1 text-secondary opacity-50 mb-3 d-block"></i>
                                    No fee categories found. Click <strong>"Add New Category"</strong> to create one.
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
                                    <td>
                                        <span class="text-muted small"><?php echo !empty($cat['description']) ? e($cat['description']) : '<em>No description provided</em>'; ?></span>
                                    </td>
                                    <td>
                                        <span class="badge bg-primary bg-opacity-10 text-primary fs-6 px-3 py-2 border border-primary border-opacity-25 fw-bold">
                                            <?php echo $currency; ?> <?php echo number_format((float)$cat['amount'], 2); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border">
                                            <?php echo (int)$cat['assigned_count']; ?> Student(s)
                                        </span>
                                    </td>
                                    <td>
                                        <span class="fw-semibold text-secondary">
                                            <?php echo $currency; ?> <?php echo number_format((float)$cat['total_assigned_amount'], 2); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($cat['status'] === 'active'): ?>
                                            <span class="badge bg-success-subtle text-success border border-success border-opacity-25 px-2 py-1">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary-subtle text-secondary border border-secondary border-opacity-25 px-2 py-1">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <button type="button" class="btn btn-sm btn-outline-primary me-1" 
                                            onclick='openEditCategoryModal(<?php echo json_encode($cat); ?>)' title="Change fee figure or details">
                                            <i class="fa-solid fa-pen-to-square me-1"></i>Edit Figure
                                        </button>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to remove or deactivate this fee category?');">
                                            <input type="hidden" name="action" value="delete_category">
                                            <input type="hidden" name="category_id" value="<?php echo $cat['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete or Deactivate">
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

    <!-- TAB 2: ASSIGNED STUDENT FEES -->
    <div class="tab-pane fade <?php echo $activeTab === 'assigned' ? 'show active' : ''; ?>" id="assigned-pane" role="tabpanel" aria-labelledby="assigned-tab">
        <!-- Filters Bar -->
        <div class="card p-3 mb-4 border-0 shadow-sm">
            <form method="GET" class="row g-2 align-items-end">
                <input type="hidden" name="tab" value="assigned">
                <div class="col-md-4">
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
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted mb-1">Fee Category</label>
                    <select name="category_id" class="form-select form-select-sm">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>" <?php echo ($filterCat === (int)$cat['id']) ? 'selected' : ''; ?>>
                                <?php echo e($cat['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold text-muted mb-1">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All Statuses</option>
                        <option value="pending" <?php echo ($filterStatus === 'pending') ? 'selected' : ''; ?>>Pending</option>
                        <option value="partial" <?php echo ($filterStatus === 'partial') ? 'selected' : ''; ?>>Partial</option>
                        <option value="paid" <?php echo ($filterStatus === 'paid') ? 'selected' : ''; ?>>Paid</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fa-solid fa-filter me-1"></i>Filter</button>
                    <a href="fees.php?tab=assigned" class="btn btn-light btn-sm"><i class="fa-solid fa-rotate-left"></i></a>
                </div>
            </form>
        </div>

        <!-- Fees Table -->
        <div class="card p-0 border-0 shadow-sm overflow-hidden mb-4">
            <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="fw-bold mb-0 text-dark">Assigned Student Fee Bills</h5>
                    <small class="text-muted">You can adjust the individual fee amount, due date, or status for any student below.</small>
                </div>
                <span class="badge bg-light text-secondary"><?php echo count($fees); ?> Record(s)</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Student Details</th>
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
                                    <i class="fa-solid fa-wallet fs-1 text-secondary opacity-50 mb-3 d-block"></i>
                                    No fee records match your query. Click <strong>"Assign Fee"</strong> to add bills.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($fees as $f): ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold text-dark"><?php echo e($f['student_name']); ?></div>
                                        <small class="text-muted"><?php echo e($f['student_code']); ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary bg-opacity-10 text-secondary border">
                                            <?php echo e($f['class_name'] ?? 'N/A'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <strong><?php echo e($f['category_name']); ?></strong>
                                    </td>
                                    <td>
                                        <strong class="fs-6 text-primary"><?php echo $currency; ?> <?php echo number_format((float)$f['amount'], 2); ?></strong>
                                    </td>
                                    <td>
                                        <span class="small text-muted">
                                            <?php echo !empty($f['due_date']) ? date('d M Y', strtotime($f['due_date'])) : 'End of Term'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($f['status'] === 'paid'): ?>
                                            <span class="badge bg-success-subtle text-success border border-success border-opacity-25 px-2 py-1"><i class="fa-solid fa-check me-1"></i>Paid</span>
                                        <?php elseif ($f['status'] === 'partial'): ?>
                                            <span class="badge bg-warning-subtle text-warning border border-warning border-opacity-25 px-2 py-1"><i class="fa-solid fa-clock me-1"></i>Partial</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger-subtle text-danger border border-danger border-opacity-25 px-2 py-1"><i class="fa-solid fa-circle-exclamation me-1"></i>Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <button type="button" class="btn btn-sm btn-outline-primary me-1" 
                                            onclick='openEditFeeModal(<?php echo json_encode($f); ?>)' title="Edit Fee Amount or Due Date">
                                            <i class="fa-solid fa-pen-to-square me-1"></i>Edit Fee
                                        </button>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Remove this student fee record?');">
                                            <input type="hidden" name="action" value="delete_fee">
                                            <input type="hidden" name="fee_id" value="<?php echo $f['id']; ?>">
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
<div class="modal fade" id="assignFeeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form method="POST" class="modal-content border-0 shadow">
            <input type="hidden" name="action" value="assign_fee">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-plus me-2"></i>Assign Student Fee Obligation</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="p-3 bg-light rounded-3 mb-3 border">
                    <span class="fw-bold d-block mb-2">Assignment Mode:</span>
                    <div class="d-flex gap-4">
                        <label class="form-check-label">
                            <input type="radio" name="target_mode" value="student" checked onclick="document.getElementById('singleStudentRow').style.display='block'; document.getElementById('classRow').style.display='none';"> Single Student
                        </label>
                        <label class="form-check-label">
                            <input type="radio" name="target_mode" value="class" onclick="document.getElementById('singleStudentRow').style.display='none'; document.getElementById('classRow').style.display='block';"> Entire Class (Batch)
                        </label>
                    </div>
                </div>

                <div class="mb-3" id="singleStudentRow">
                    <label class="form-label fw-bold">Select Student</label>
                    <select name="student_id" class="form-select">
                        <option value="">Choose Student...</option>
                        <?php foreach ($studentsList as $s): ?>
                            <option value="<?php echo $s['id']; ?>"><?php echo e($s['name']); ?> (<?php echo e($s['student_code']); ?> - <?php echo e($s['class_name'] ?? 'N/A'); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3" id="classRow" style="display: none;">
                    <label class="form-label fw-bold">Select Class</label>
                    <select name="class_id" class="form-select">
                        <option value="">Choose Class...</option>
                        <?php foreach ($classesList as $c): ?>
                            <option value="<?php echo $c['id']; ?>"><?php echo e($c['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Fee Category <span class="text-danger">*</span></label>
                        <select name="category_id" id="catSelectAssign" class="form-select" required onchange="updateCatAmount(this)">
                            <option value="">Choose Category...</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>" data-amount="<?php echo (float)$cat['amount']; ?>">
                                    <?php echo e($cat['name']); ?> (<?php echo $currency; ?> <?php echo number_format((float)$cat['amount'], 2); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Amount (<?php echo $currency; ?>) <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" name="amount" id="catAmountInput" class="form-control" placeholder="0.00" required>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold">Due Date</label>
                    <input type="date" name="due_date" class="form-control" value="<?php echo date('Y-m-d', strtotime('+30 days')); ?>">
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary px-4"><i class="fa-solid fa-floppy-disk me-1"></i>Save Fee</button>
            </div>
        </form>
    </div>
</div>

<!-- Create Category Modal -->
<div class="modal fade" id="createCategoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content border-0 shadow">
            <input type="hidden" name="action" value="create_category">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-tags me-2"></i>Create Fee Category</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <label class="form-label fw-bold">Category Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control" placeholder="e.g. Tuition Fee, ICT Lab, Sports" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Standard Amount Figure (<?php echo $currency; ?>) <span class="text-danger">*</span></label>
                    <input type="number" step="0.01" name="amount" class="form-control" placeholder="0.00" required>
                    <small class="text-muted">This figure will be the standard default amount charged for this category.</small>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Description / Purpose</label>
                    <textarea name="description" class="form-control" rows="3" placeholder="Brief explanation of this fee..."></textarea>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk me-1"></i>Create Category</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Fee Category Modal (Change Figure of Fee Category) -->
<div class="modal fade" id="editCategoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content border-0 shadow">
            <input type="hidden" name="action" value="edit_category">
            <input type="hidden" name="category_id" id="editCatId">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-pen-to-square me-2"></i>Edit Fee Category Figure</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <label class="form-label fw-bold">Category Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" id="editCatName" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Standard Figure / Rate (<?php echo $currency; ?>) <span class="text-danger">*</span></label>
                    <input type="number" step="0.01" name="amount" id="editCatAmount" class="form-control" required>
                    <small class="text-muted">Change this number to update the default tuition, transport, or lab fee figure.</small>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Description / Notes</label>
                    <textarea name="description" id="editCatDesc" class="form-control" rows="3"></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Status</label>
                    <select name="status" id="editCatStatus" class="form-select">
                        <option value="active">Active (Available for billing)</option>
                        <option value="inactive">Inactive (Hidden)</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary px-4"><i class="fa-solid fa-floppy-disk me-1"></i>Update Figure</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Student Fee Modal (Change Individual Student Fee Figure) -->
<div class="modal fade" id="editFeeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content border-0 shadow">
            <input type="hidden" name="action" value="edit_fee">
            <input type="hidden" name="fee_id" id="editFeeId">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-pen-to-square me-2"></i>Edit Student Fee Figure</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="p-3 bg-light rounded-3 mb-3 border">
                    <span class="text-muted small d-block">Student:</span>
                    <strong class="fs-6 text-dark" id="editFeeStudentName">Student Name</strong>
                    <div class="small text-muted" id="editFeeStudentInfo">ID / Class</div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold">Fee Category <span class="text-danger">*</span></label>
                    <select name="category_id" id="editFeeCategory" class="form-select" required>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>">
                                <?php echo e($cat['name']); ?> (Default: <?php echo $currency; ?> <?php echo number_format((float)$cat['amount'], 2); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold">Amount Billed (<?php echo $currency; ?>) <span class="text-danger">*</span></label>
                    <input type="number" step="0.01" name="amount" id="editFeeAmount" class="form-control" required>
                    <small class="text-muted">Adjust this figure for scholarships, discounts, or fee alterations.</small>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Due Date</label>
                        <input type="date" name="due_date" id="editFeeDueDate" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Payment Status</label>
                        <select name="status" id="editFeeStatus" class="form-select">
                            <option value="pending">Pending</option>
                            <option value="partial">Partial</option>
                            <option value="paid">Paid</option>
                            <option value="overdue">Overdue</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary px-4"><i class="fa-solid fa-floppy-disk me-1"></i>Save Fee Figure</button>
            </div>
        </form>
    </div>
</div>

<script>
function updateCatAmount(sel) {
    const opt = sel.options[sel.selectedIndex];
    const amt = opt ? opt.getAttribute('data-amount') : null;
    if (amt) {
        document.getElementById('catAmountInput').value = amt;
    }
}

function openEditCategoryModal(cat) {
    document.getElementById('editCatId').value = cat.id || '';
    document.getElementById('editCatName').value = cat.name || '';
    document.getElementById('editCatAmount').value = parseFloat(cat.amount || 0).toFixed(2);
    document.getElementById('editCatDesc').value = cat.description || '';
    document.getElementById('editCatStatus').value = cat.status || 'active';

    const modalEl = document.getElementById('editCategoryModal');
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    modal.show();
}

function openEditFeeModal(fee) {
    document.getElementById('editFeeId').value = fee.id || '';
    document.getElementById('editFeeStudentName').innerText = fee.student_name || 'Student';
    document.getElementById('editFeeStudentInfo').innerText = (fee.student_code || '') + (fee.class_name ? ' - ' + fee.class_name : '');
    document.getElementById('editFeeCategory').value = fee.category_id || '';
    document.getElementById('editFeeAmount').value = parseFloat(fee.amount || 0).toFixed(2);
    document.getElementById('editFeeDueDate').value = fee.due_date || '';
    document.getElementById('editFeeStatus').value = fee.status || 'pending';

    const modalEl = document.getElementById('editFeeModal');
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    modal.show();
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
