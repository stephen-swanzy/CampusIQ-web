<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin(['teacher']);
$db = getDb();
$user = currentUser();

// Resolve teacher record
$stmtTeacher = $db->prepare('SELECT * FROM teachers WHERE user_id = :uid LIMIT 1');
$stmtTeacher->execute(['uid' => $user['id']]);
$teacher = $stmtTeacher->fetch();

if (!$teacher) {
    // Auto-create teacher profile if missing
    $autoCode = 'TCH-' . str_pad((string)$user['id'], 3, '0', STR_PAD_LEFT);
    $insTch = $db->prepare('INSERT INTO teachers (user_id, teacher_id, designation, status) VALUES (:uid, :tid, :desig, "active")');
    $insTch->execute([
        'uid' => $user['id'],
        'tid' => $autoCode,
        'desig' => 'Instructor'
    ]);
    $stmtTeacher->execute(['uid' => $user['id']]);
    $teacher = $stmtTeacher->fetch();
}

$teacherId = (int)$teacher['id'];
$successMsg = '';
$errorMsg = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_assignment') {
        $title = trim($_POST['title'] ?? '');
        $classId = (int)($_POST['class_id'] ?? 0);
        $subjectId = (int)($_POST['subject_id'] ?? 0);
        $dueDate = trim($_POST['due_date'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $status = in_array($_POST['status'] ?? '', ['draft', 'published', 'closed']) ? $_POST['status'] : 'published';

        if (empty($title) || $classId <= 0 || $subjectId <= 0 || empty($dueDate)) {
            $errorMsg = 'Please provide assignment title, class, subject, and due date.';
        } else {
            $attachmentPath = null;
            if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = __DIR__ . '/../assets/uploads/assignments/';
                if (!is_dir($uploadDir)) {
                    @mkdir($uploadDir, 0755, true);
                }
                $fileTmp = $_FILES['attachment']['tmp_name'];
                $fileName = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', basename($_FILES['attachment']['name']));
                $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                $allowed = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'txt', 'zip', 'png', 'jpg', 'jpeg'];

                if (!in_array($fileExt, $allowed)) {
                    $errorMsg = 'Invalid file type. Allowed: PDF, Word, PowerPoint, Excel, text, images, zip.';
                } else {
                    $uniqueName = 'assign_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $fileExt;
                    $targetPath = $uploadDir . $uniqueName;
                    if (move_uploaded_file($fileTmp, $targetPath)) {
                        $attachmentPath = 'assets/uploads/assignments/' . $uniqueName;
                    } else {
                        $errorMsg = 'Failed to upload attachment file.';
                    }
                }
            }

            if (empty($errorMsg)) {
                $ins = $db->prepare('INSERT INTO assignments (title, description, class_id, subject_id, teacher_id, due_date, attachment, status) VALUES (:title, :description, :class_id, :subject_id, :teacher_id, :due_date, :attachment, :status)');
                $ins->execute([
                    'title' => $title,
                    'description' => $description,
                    'class_id' => $classId,
                    'subject_id' => $subjectId,
                    'teacher_id' => $teacherId,
                    'due_date' => date('Y-m-d H:i:s', strtotime($dueDate)),
                    'attachment' => $attachmentPath,
                    'status' => $status
                ]);
                $successMsg = 'Assignment created successfully!';
            }
        }
    } elseif ($action === 'edit_assignment') {
        $assignmentId = (int)($_POST['assignment_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $classId = (int)($_POST['class_id'] ?? 0);
        $subjectId = (int)($_POST['subject_id'] ?? 0);
        $dueDate = trim($_POST['due_date'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $status = in_array($_POST['status'] ?? '', ['draft', 'published', 'closed']) ? $_POST['status'] : 'published';

        // Verify assignment belongs to this teacher
        $chk = $db->prepare('SELECT * FROM assignments WHERE id = :id AND teacher_id = :tid');
        $chk->execute(['id' => $assignmentId, 'tid' => $teacherId]);
        $existing = $chk->fetch();

        if (!$existing) {
            $errorMsg = 'Assignment not found or permission denied.';
        } elseif (empty($title) || $classId <= 0 || $subjectId <= 0 || empty($dueDate)) {
            $errorMsg = 'Please complete all required fields.';
        } else {
            $attachmentPath = $existing['attachment'];
            if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = __DIR__ . '/../assets/uploads/assignments/';
                if (!is_dir($uploadDir)) {
                    @mkdir($uploadDir, 0755, true);
                }
                $fileTmp = $_FILES['attachment']['tmp_name'];
                $fileName = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', basename($_FILES['attachment']['name']));
                $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                $allowed = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'txt', 'zip', 'png', 'jpg', 'jpeg'];

                if (in_array($fileExt, $allowed)) {
                    $uniqueName = 'assign_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $fileExt;
                    $targetPath = $uploadDir . $uniqueName;
                    if (move_uploaded_file($fileTmp, $targetPath)) {
                        $attachmentPath = 'assets/uploads/assignments/' . $uniqueName;
                    }
                }
            }

            $upd = $db->prepare('UPDATE assignments SET title = :title, description = :description, class_id = :class_id, subject_id = :subject_id, due_date = :due_date, attachment = :attachment, status = :status WHERE id = :id AND teacher_id = :tid');
            $upd->execute([
                'title' => $title,
                'description' => $description,
                'class_id' => $classId,
                'subject_id' => $subjectId,
                'due_date' => date('Y-m-d H:i:s', strtotime($dueDate)),
                'attachment' => $attachmentPath,
                'status' => $status,
                'id' => $assignmentId,
                'tid' => $teacherId
            ]);
            $successMsg = 'Assignment updated successfully!';
        }
    } elseif ($action === 'grade_submission') {
        $submissionId = (int)($_POST['submission_id'] ?? 0);
        $grade = trim($_POST['grade'] ?? '');
        $feedback = trim($_POST['feedback'] ?? '');
        $status = in_array($_POST['submission_status'] ?? '', ['submitted', 'graded', 'late']) ? $_POST['submission_status'] : 'graded';

        // Check if submission belongs to an assignment taught by this teacher
        $chkSub = $db->prepare('SELECT sub.id FROM assignment_submissions sub JOIN assignments a ON a.id = sub.assignment_id WHERE sub.id = :sid AND a.teacher_id = :tid');
        $chkSub->execute(['sid' => $submissionId, 'tid' => $teacherId]);
        if ($chkSub->fetch()) {
            $updSub = $db->prepare('UPDATE assignment_submissions SET grade = :grade, feedback = :feedback, status = :status WHERE id = :sid');
            $updSub->execute([
                'grade' => $grade,
                'feedback' => $feedback,
                'status' => $status,
                'sid' => $submissionId
            ]);
            $successMsg = 'Student submission graded successfully!';
        } else {
            $errorMsg = 'Submission record not found or access denied.';
        }
    } elseif ($action === 'delete_assignment') {
        $assignmentId = (int)($_POST['assignment_id'] ?? 0);
        $del = $db->prepare('DELETE FROM assignments WHERE id = :id AND teacher_id = :tid');
        $del->execute(['id' => $assignmentId, 'tid' => $teacherId]);
        $successMsg = 'Assignment deleted successfully.';
    } elseif ($action === 'toggle_status') {
        $assignmentId = (int)($_POST['assignment_id'] ?? 0);
        $newStatus = $_POST['new_status'] ?? 'published';
        if (in_array($newStatus, ['draft', 'published', 'closed'])) {
            $upd = $db->prepare('UPDATE assignments SET status = :status WHERE id = :id AND teacher_id = :tid');
            $upd->execute(['status' => $newStatus, 'id' => $assignmentId, 'tid' => $teacherId]);
            $successMsg = 'Assignment status updated to ' . ucfirst($newStatus) . '.';
        }
    }
}

// Fetch classes and subjects
$classes = $db->query('SELECT id, name FROM classes ORDER BY name ASC')->fetchAll();
$subjects = $db->query('SELECT id, name FROM subjects ORDER BY name ASC')->fetchAll();

// Fetch filter options
$filterClass = (int)($_GET['class_id'] ?? 0);
$filterSubject = (int)($_GET['subject_id'] ?? 0);
$filterStatus = trim($_GET['status'] ?? '');

$sql = 'SELECT a.*, c.name as class_name, s.name as subject_name,
        (SELECT COUNT(*) FROM assignment_submissions sub WHERE sub.assignment_id = a.id) as total_submissions,
        (SELECT COUNT(*) FROM assignment_submissions sub WHERE sub.assignment_id = a.id AND sub.status = "graded") as graded_submissions
        FROM assignments a 
        JOIN classes c ON c.id = a.class_id 
        JOIN subjects s ON s.id = a.subject_id 
        WHERE a.teacher_id = :tid';

$params = ['tid' => $teacherId];

if ($filterClass > 0) {
    $sql .= ' AND a.class_id = :cid';
    $params['cid'] = $filterClass;
}
if ($filterSubject > 0) {
    $sql .= ' AND a.subject_id = :sid';
    $params['sid'] = $filterSubject;
}
if (!empty($filterStatus)) {
    $sql .= ' AND a.status = :status';
    $params['status'] = $filterStatus;
}
$sql .= ' ORDER BY a.due_date DESC';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$assignments = $stmt->fetchAll();

// Submissions by assignment for modals
$assignmentSubmissions = [];
if (!empty($assignments)) {
    $assignIds = array_column($assignments, 'id');
    $placeholders = implode(',', array_fill(0, count($assignIds), '?'));
    $subStmt = $db->prepare("
        SELECT sub.*, s.student_id as student_code, u.name as student_name, u.email as student_email
        FROM assignment_submissions sub
        JOIN students s ON s.id = sub.student_id
        JOIN users u ON u.id = s.user_id
        WHERE sub.assignment_id IN ($placeholders)
        ORDER BY sub.submitted_at DESC
    ");
    $subStmt->execute($assignIds);
    $allSubs = $subStmt->fetchAll();
    foreach ($allSubs as $s) {
        $assignmentSubmissions[$s['assignment_id']][] = $s;
    }
}

// Compute metrics
$totalCount = count($assignments);
$activeCount = count(array_filter($assignments, fn($a) => $a['status'] === 'published'));
$totalSubmissions = array_sum(array_column($assignments, 'total_submissions'));
$totalGraded = array_sum(array_column($assignments, 'graded_submissions'));

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-3">
    <div>
        <p class="text-primary fw-semibold mb-1"><i class="fa-solid fa-file-pen me-2"></i>Academic Management</p>
        <h2 class="fw-bold mb-0">Assignments & Homework</h2>
        <small class="text-muted">Create assignments for students, review submissions online, and award grades.</small>
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createAssignmentModal">
            <i class="fa-solid fa-plus me-2"></i>New Assignment
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

<!-- Metrics Row -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card p-3 border-0 shadow-sm d-flex flex-row align-items-center gap-3">
            <div class="rounded-3 p-3 bg-primary bg-opacity-10 text-primary fs-3">
                <i class="fa-solid fa-book-open"></i>
            </div>
            <div>
                <span class="text-muted small fw-semibold">Total Assignments</span>
                <h4 class="fw-bold mb-0 text-dark"><?php echo $totalCount; ?></h4>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card p-3 border-0 shadow-sm d-flex flex-row align-items-center gap-3">
            <div class="rounded-3 p-3 bg-success bg-opacity-10 text-success fs-3">
                <i class="fa-solid fa-circle-check"></i>
            </div>
            <div>
                <span class="text-muted small fw-semibold">Active Published</span>
                <h4 class="fw-bold mb-0 text-dark"><?php echo $activeCount; ?></h4>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card p-3 border-0 shadow-sm d-flex flex-row align-items-center gap-3">
            <div class="rounded-3 p-3 bg-info bg-opacity-10 text-info fs-3">
                <i class="fa-solid fa-inbox"></i>
            </div>
            <div>
                <span class="text-muted small fw-semibold">Submissions Received</span>
                <h4 class="fw-bold mb-0 text-dark"><?php echo $totalSubmissions; ?></h4>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card p-3 border-0 shadow-sm d-flex flex-row align-items-center gap-3">
            <div class="rounded-3 p-3 bg-warning bg-opacity-10 text-warning fs-3">
                <i class="fa-solid fa-award"></i>
            </div>
            <div>
                <span class="text-muted small fw-semibold">Graded & Reviewed</span>
                <h4 class="fw-bold mb-0 text-dark"><?php echo $totalGraded; ?></h4>
            </div>
        </div>
    </div>
</div>

<!-- Filter Bar -->
<div class="card p-3 mb-4 border-0 shadow-sm">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label small fw-bold text-muted mb-1">Class</label>
            <select name="class_id" class="form-select form-select-sm">
                <option value="">All Classes</option>
                <?php foreach ($classes as $c): ?>
                    <option value="<?php echo $c['id']; ?>" <?php echo $filterClass === (int)$c['id'] ? 'selected' : ''; ?>>
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
                    <option value="<?php echo $s['id']; ?>" <?php echo $filterSubject === (int)$s['id'] ? 'selected' : ''; ?>>
                        <?php echo e($s['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-bold text-muted mb-1">Status</label>
            <select name="status" class="form-select form-select-sm">
                <option value="">All Statuses</option>
                <option value="published" <?php echo $filterStatus === 'published' ? 'selected' : ''; ?>>Published</option>
                <option value="draft" <?php echo $filterStatus === 'draft' ? 'selected' : ''; ?>>Draft</option>
                <option value="closed" <?php echo $filterStatus === 'closed' ? 'selected' : ''; ?>>Closed</option>
            </select>
        </div>
        <div class="col-md-3 d-flex gap-2">
            <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fa-solid fa-filter me-1"></i>Filter</button>
            <a href="assignments.php" class="btn btn-light btn-sm"><i class="fa-solid fa-rotate-left"></i></a>
        </div>
    </form>
</div>

<!-- Assignments Table -->
<div class="card p-0 border-0 shadow-sm overflow-hidden">
    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
        <h5 class="fw-bold mb-0 text-dark">Course Assignments</h5>
        <span class="badge bg-light text-secondary"><?php echo count($assignments); ?> Record(s)</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="min-width: 220px;">Title & Details</th>
                    <th>Class</th>
                    <th>Subject</th>
                    <th>Due Date</th>
                    <th>Attachment</th>
                    <th>Submissions</th>
                    <th>Status</th>
                    <th class="text-end" style="min-width: 150px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($assignments)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-5 text-muted">
                            <i class="fa-solid fa-folder-open fs-2 mb-2 d-block text-secondary opacity-50"></i>
                            No assignments found. Click <strong>"New Assignment"</strong> above to create homework for your students.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($assignments as $a): 
                        $dueTimestamp = strtotime($a['due_date']);
                        $isOverdue = time() > $dueTimestamp;
                        $subs = $assignmentSubmissions[$a['id']] ?? [];
                    ?>
                        <tr>
                            <td>
                                <div class="fw-bold text-dark"><?php echo e($a['title']); ?></div>
                                <?php if (!empty($a['description'])): ?>
                                    <small class="text-muted d-block text-truncate" style="max-width: 260px;" title="<?php echo e($a['description']); ?>">
                                        <?php echo e($a['description']); ?>
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-secondary bg-opacity-10 text-secondary border">
                                    <i class="fa-solid fa-school me-1"></i><?php echo e($a['class_name']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge bg-info bg-opacity-10 text-info border">
                                    <i class="fa-solid fa-book me-1"></i><?php echo e($a['subject_name']); ?>
                                </span>
                            </td>
                            <td>
                                <div class="<?php echo $isOverdue ? 'text-danger fw-semibold' : 'text-dark'; ?>">
                                    <i class="fa-regular fa-calendar-xmark me-1"></i>
                                    <?php echo date('d M Y, h:i A', $dueTimestamp); ?>
                                </div>
                                <small class="text-muted">
                                    <?php echo $isOverdue ? '<span class="badge bg-danger-subtle text-danger">Passed</span>' : '<span class="badge bg-success-subtle text-success">Active</span>'; ?>
                                </small>
                            </td>
                            <td>
                                <?php if (!empty($a['attachment'])): ?>
                                    <a href="<?php echo baseUrl() . '/' . e($a['attachment']); ?>" target="_blank" download class="btn btn-sm btn-outline-primary" title="Download Material">
                                        <i class="fa-solid fa-file-arrow-down me-1"></i>Download
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted small">None</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button type="button" class="btn btn-sm btn-light border position-relative" data-bs-toggle="modal" data-bs-target="#subsModal_<?php echo $a['id']; ?>">
                                    <i class="fa-solid fa-users-viewfinder me-1 text-primary"></i>
                                    <strong><?php echo $a['total_submissions']; ?></strong> Submitted
                                    <?php if ($a['graded_submissions'] > 0): ?>
                                        <span class="badge bg-success ms-1"><?php echo $a['graded_submissions']; ?> Graded</span>
                                    <?php endif; ?>
                                </button>
                            </td>
                            <td>
                                <?php if ($a['status'] === 'published'): ?>
                                    <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2 py-1">
                                        <i class="fa-solid fa-circle-check me-1"></i>Published
                                    </span>
                                <?php elseif ($a['status'] === 'draft'): ?>
                                    <span class="badge bg-warning bg-opacity-10 text-warning border border-warning border-opacity-25 px-2 py-1">
                                        <i class="fa-solid fa-file-lines me-1"></i>Draft
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary border-opacity-25 px-2 py-1">
                                        <i class="fa-solid fa-lock me-1"></i>Closed
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <div class="dropdown">
                                    <button class="btn btn-light btn-sm dropdown-toggle border" type="button" data-bs-toggle="dropdown">
                                        Actions
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                        <li>
                                            <button class="dropdown-item" data-bs-toggle="modal" data-bs-target="#subsModal_<?php echo $a['id']; ?>">
                                                <i class="fa-solid fa-list-check me-2 text-primary"></i>View Submissions
                                            </button>
                                        </li>
                                        <li>
                                            <button class="dropdown-item" data-bs-toggle="modal" data-bs-target="#editModal_<?php echo $a['id']; ?>">
                                                <i class="fa-solid fa-pen-to-square me-2 text-warning"></i>Edit Assignment
                                            </button>
                                        </li>
                                        <li><hr class="dropdown-divider"></li>
                                        <?php if ($a['status'] === 'published'): ?>
                                            <li>
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="action" value="toggle_status">
                                                    <input type="hidden" name="assignment_id" value="<?php echo $a['id']; ?>">
                                                    <input type="hidden" name="new_status" value="closed">
                                                    <button type="submit" class="dropdown-item text-secondary">
                                                        <i class="fa-solid fa-lock me-2"></i>Close Submissions
                                                    </button>
                                                </form>
                                            </li>
                                        <?php else: ?>
                                            <li>
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="action" value="toggle_status">
                                                    <input type="hidden" name="assignment_id" value="<?php echo $a['id']; ?>">
                                                    <input type="hidden" name="new_status" value="published">
                                                    <button type="submit" class="dropdown-item text-success">
                                                        <i class="fa-solid fa-paper-plane me-2"></i>Publish Assignment
                                                    </button>
                                                </form>
                                            </li>
                                        <?php endif; ?>
                                        <li>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to permanently delete this assignment?');">
                                                <input type="hidden" name="action" value="delete_assignment">
                                                <input type="hidden" name="assignment_id" value="<?php echo $a['id']; ?>">
                                                <button type="submit" class="dropdown-item text-danger">
                                                    <i class="fa-solid fa-trash me-2"></i>Delete Assignment
                                                </button>
                                            </form>
                                        </li>
                                    </ul>
                                </div>
                            </td>
                        </tr>

                        <!-- Submissions Modal for this assignment -->
                        <div class="modal fade" id="subsModal_<?php echo $a['id']; ?>" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog modal-xl modal-dialog-scrollable">
                                <div class="modal-content border-0 shadow">
                                    <div class="modal-header bg-light">
                                        <div>
                                            <h5 class="modal-title fw-bold text-dark">
                                                <i class="fa-solid fa-inbox text-primary me-2"></i>Submissions for: <?php echo e($a['title']); ?>
                                            </h5>
                                            <small class="text-muted"><?php echo e($a['class_name']); ?> • <?php echo e($a['subject_name']); ?> • Due: <?php echo date('d M Y, h:i A', $dueTimestamp); ?></small>
                                        </div>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body p-4">
                                        <?php if (empty($subs)): ?>
                                            <div class="text-center py-5 text-muted">
                                                <i class="fa-solid fa-clock-rotate-left fs-1 text-secondary opacity-50 mb-3 d-block"></i>
                                                <h5>No Student Submissions Yet</h5>
                                                <p class="mb-0">When students submit their work for this assignment, their answers, uploaded files, and grading options will appear here.</p>
                                            </div>
                                        <?php else: ?>
                                            <div class="table-responsive">
                                                <table class="table align-middle">
                                                    <thead class="table-light">
                                                        <tr>
                                                            <th>Student</th>
                                                            <th>Submitted At</th>
                                                            <th>Written Answer / Notes</th>
                                                            <th>Uploaded File</th>
                                                            <th>Status</th>
                                                            <th>Current Grade</th>
                                                            <th style="min-width: 200px;">Review & Grade</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($subs as $sub): ?>
                                                            <tr>
                                                                <td>
                                                                    <div class="fw-bold"><?php echo e($sub['student_name']); ?></div>
                                                                    <small class="text-muted"><?php echo e($sub['student_code']); ?> • <?php echo e($sub['student_email']); ?></small>
                                                                </td>
                                                                <td>
                                                                    <?php if (!empty($sub['submitted_at'])): ?>
                                                                        <div class="small fw-semibold"><?php echo date('d M Y, h:i A', strtotime($sub['submitted_at'])); ?></div>
                                                                    <?php else: ?>
                                                                        <span class="text-muted small">N/A</span>
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td>
                                                                    <?php if (!empty($sub['notes'])): ?>
                                                                        <div class="bg-light p-2 rounded small text-wrap" style="max-width: 250px; max-height: 100px; overflow-y: auto;">
                                                                            <?php echo nl2br(e($sub['notes'])); ?>
                                                                        </div>
                                                                    <?php else: ?>
                                                                        <span class="text-muted small">No written note</span>
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td>
                                                                    <?php if (!empty($sub['file_path'])): ?>
                                                                        <a href="<?php echo baseUrl() . '/' . e($sub['file_path']); ?>" target="_blank" download class="btn btn-sm btn-outline-primary">
                                                                            <i class="fa-solid fa-file-arrow-down me-1"></i>Download Work
                                                                        </a>
                                                                    <?php else: ?>
                                                                        <span class="text-muted small">No file attached</span>
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td>
                                                                    <?php if ($sub['status'] === 'graded'): ?>
                                                                        <span class="badge bg-success"><i class="fa-solid fa-check me-1"></i>Graded</span>
                                                                    <?php elseif ($sub['status'] === 'late'): ?>
                                                                        <span class="badge bg-warning"><i class="fa-solid fa-clock me-1"></i>Late</span>
                                                                    <?php else: ?>
                                                                        <span class="badge bg-info"><i class="fa-solid fa-paper-plane me-1"></i>Submitted</span>
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td>
                                                                    <?php if (!empty($sub['grade'])): ?>
                                                                        <span class="badge bg-primary fs-6"><?php echo e($sub['grade']); ?></span>
                                                                        <?php if (!empty($sub['feedback'])): ?>
                                                                            <small class="d-block text-muted mt-1 fst-italic">"<?php echo e($sub['feedback']); ?>"</small>
                                                                        <?php endif; ?>
                                                                    <?php else: ?>
                                                                        <span class="text-muted small">Ungraded</span>
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td>
                                                                    <form method="POST" class="d-flex flex-column gap-2 bg-light p-2 rounded border">
                                                                        <input type="hidden" name="action" value="grade_submission">
                                                                        <input type="hidden" name="submission_id" value="<?php echo $sub['id']; ?>">
                                                                        <div class="d-flex gap-2">
                                                                            <input type="text" name="grade" class="form-control form-control-sm" placeholder="Grade (e.g. A, 90%)" value="<?php echo e($sub['grade'] ?? ''); ?>" required style="max-width: 110px;">
                                                                            <select name="submission_status" class="form-select form-select-sm">
                                                                                <option value="graded" <?php echo ($sub['status'] === 'graded') ? 'selected' : ''; ?>>Graded</option>
                                                                                <option value="submitted" <?php echo ($sub['status'] === 'submitted') ? 'selected' : ''; ?>>Needs Review</option>
                                                                            </select>
                                                                        </div>
                                                                        <input type="text" name="feedback" class="form-control form-control-sm" placeholder="Teacher Feedback / Remarks" value="<?php echo e($sub['feedback'] ?? ''); ?>">
                                                                        <button type="submit" class="btn btn-sm btn-primary">
                                                                            <i class="fa-solid fa-floppy-disk me-1"></i>Save Grade
                                                                        </button>
                                                                    </form>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="modal-footer bg-light">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Edit Assignment Modal -->
                        <div class="modal fade" id="editModal_<?php echo $a['id']; ?>" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog modal-lg">
                                <form method="POST" enctype="multipart/form-data" class="modal-content border-0 shadow">
                                    <input type="hidden" name="action" value="edit_assignment">
                                    <input type="hidden" name="assignment_id" value="<?php echo $a['id']; ?>">
                                    <div class="modal-header bg-light">
                                        <h5 class="modal-title fw-bold text-dark"><i class="fa-solid fa-pen-to-square text-warning me-2"></i>Edit Assignment</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body p-4">
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Title <span class="text-danger">*</span></label>
                                            <input type="text" name="title" class="form-control" value="<?php echo e($a['title']); ?>" required>
                                        </div>
                                        <div class="row g-3 mb-3">
                                            <div class="col-md-6">
                                                <label class="form-label fw-bold">Class <span class="text-danger">*</span></label>
                                                <select name="class_id" class="form-select" required>
                                                    <?php foreach ($classes as $c): ?>
                                                        <option value="<?php echo $c['id']; ?>" <?php echo ((int)$a['class_id'] === (int)$c['id']) ? 'selected' : ''; ?>>
                                                            <?php echo e($c['name']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label fw-bold">Subject <span class="text-danger">*</span></label>
                                                <select name="subject_id" class="form-select" required>
                                                    <?php foreach ($subjects as $s): ?>
                                                        <option value="<?php echo $s['id']; ?>" <?php echo ((int)$a['subject_id'] === (int)$s['id']) ? 'selected' : ''; ?>>
                                                            <?php echo e($s['name']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="row g-3 mb-3">
                                            <div class="col-md-6">
                                                <label class="form-label fw-bold">Due Date & Time <span class="text-danger">*</span></label>
                                                <input type="datetime-local" name="due_date" class="form-control" value="<?php echo date('Y-m-d\TH:i', strtotime($a['due_date'])); ?>" required>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label fw-bold">Status</label>
                                                <select name="status" class="form-select">
                                                    <option value="published" <?php echo ($a['status'] === 'published') ? 'selected' : ''; ?>>Published</option>
                                                    <option value="draft" <?php echo ($a['status'] === 'draft') ? 'selected' : ''; ?>>Draft</option>
                                                    <option value="closed" <?php echo ($a['status'] === 'closed') ? 'selected' : ''; ?>>Closed</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Instructions & Details</label>
                                            <textarea name="description" class="form-control" rows="4"><?php echo e($a['description']); ?></textarea>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Replace File Attachment (Optional)</label>
                                            <input type="file" name="attachment" class="form-control" accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.txt,.zip,.png,.jpg,.jpeg">
                                            <?php if (!empty($a['attachment'])): ?>
                                                <small class="text-muted d-block mt-1">Current file: <a href="<?php echo baseUrl() . '/' . e($a['attachment']); ?>" target="_blank"><?php echo basename($a['attachment']); ?></a></small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="modal-footer bg-light">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-save me-1"></i>Save Changes</button>
                                    </div>
                                </form>
                            </div>
                        </div>

                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Create Assignment Modal -->
<div class="modal fade" id="createAssignmentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form method="POST" enctype="multipart/form-data" class="modal-content border-0 shadow">
            <input type="hidden" name="action" value="create_assignment">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-file-circle-plus me-2"></i>Create New Assignment</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <label class="form-label fw-bold">Assignment Title <span class="text-danger">*</span></label>
                    <input type="text" name="title" class="form-control" placeholder="e.g. Algebra Homework 3: Quadratic Functions" required>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Target Class <span class="text-danger">*</span></label>
                        <select name="class_id" class="form-select" required>
                            <option value="">Select Class...</option>
                            <?php foreach ($classes as $c): ?>
                                <option value="<?php echo $c['id']; ?>"><?php echo e($c['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Subject <span class="text-danger">*</span></label>
                        <select name="subject_id" class="form-select" required>
                            <option value="">Select Subject...</option>
                            <?php foreach ($subjects as $s): ?>
                                <option value="<?php echo $s['id']; ?>"><?php echo e($s['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Submission Deadline <span class="text-danger">*</span></label>
                        <input type="datetime-local" name="due_date" class="form-control" value="<?php echo date('Y-m-d\TH:i', strtotime('+7 days 23:59')); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Publishing Status</label>
                        <select name="status" class="form-select">
                            <option value="published" selected>Published (Visible to Students)</option>
                            <option value="draft">Draft (Private to Teacher)</option>
                        </select>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Instructions & Homework Prompts</label>
                    <textarea name="description" class="form-control" rows="4" placeholder="Detail the questions, instructions, or reading tasks students must solve..."></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Attach Material / Worksheet (Optional)</label>
                    <input type="file" name="attachment" class="form-control" accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.txt,.zip,.png,.jpg,.jpeg">
                    <small class="text-muted">Supported formats: PDF, Word (.docx), PowerPoint, Excel, Images, Zip (Max 25MB).</small>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-paper-plane me-1"></i>Publish Assignment</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
