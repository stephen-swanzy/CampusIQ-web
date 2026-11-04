<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin(['student']);
$db = getDb();
$user = currentUser();

// Fetch student profile
$stmtStudent = $db->prepare('SELECT s.*, c.name as class_name FROM students s LEFT JOIN classes c ON c.id = s.class_id WHERE s.user_id = :uid LIMIT 1');
$stmtStudent->execute(['uid' => $user['id']]);
$student = $stmtStudent->fetch();

$classId = (int)($student['class_id'] ?? 0);
$studentId = (int)($student['id'] ?? 0);

$successMsg = '';
$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'submit_work') {
        $assignmentId = (int)($_POST['assignment_id'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');

        // Verify assignment exists and is published for this student's class
        $chkAssign = $db->prepare('SELECT * FROM assignments WHERE id = :id AND class_id = :cid AND status IN ("published", "closed") LIMIT 1');
        $chkAssign->execute(['id' => $assignmentId, 'cid' => $classId]);
        $assign = $chkAssign->fetch();

        if (!$assign) {
            $errorMsg = 'Assignment not found or closed for submission.';
        } else {
            $subFilePath = null;
            if (isset($_FILES['submission_file']) && $_FILES['submission_file']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = __DIR__ . '/../assets/uploads/submissions/';
                if (!is_dir($uploadDir)) {
                    @mkdir($uploadDir, 0755, true);
                }
                $fileTmp = $_FILES['submission_file']['tmp_name'];
                $fileName = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', basename($_FILES['submission_file']['name']));
                $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                $allowed = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'txt', 'zip', 'png', 'jpg', 'jpeg'];

                if (!in_array($fileExt, $allowed)) {
                    $errorMsg = 'Invalid file format. Supported: PDF, Word, Excel, PowerPoint, Text, Image, Zip.';
                } else {
                    $uniqueName = 'sub_' . $studentId . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $fileExt;
                    $targetPath = $uploadDir . $uniqueName;
                    if (move_uploaded_file($fileTmp, $targetPath)) {
                        $subFilePath = 'assets/uploads/submissions/' . $uniqueName;
                    } else {
                        $errorMsg = 'Failed to save uploaded file.';
                    }
                }
            }

            if (empty($errorMsg)) {
                if (empty($notes) && empty($subFilePath)) {
                    $errorMsg = 'Please provide an answer, text notes, or upload your homework file.';
                } else {
                    $isLate = (time() > strtotime($assign['due_date']));
                    $subStatus = $isLate ? 'late' : 'submitted';

                    // Check if existing submission exists
                    $chkExist = $db->prepare('SELECT id, file_path FROM assignment_submissions WHERE assignment_id = :aid AND student_id = :sid');
                    $chkExist->execute(['aid' => $assignmentId, 'sid' => $studentId]);
                    $existingSub = $chkExist->fetch();

                    if ($existingSub) {
                        $filePathToSave = $subFilePath ? $subFilePath : $existingSub['file_path'];
                        $updSub = $db->prepare('UPDATE assignment_submissions SET submitted_at = NOW(), file_path = :fpath, notes = :notes, status = :status WHERE id = :id');
                        $updSub->execute([
                            'fpath' => $filePathToSave,
                            'notes' => $notes,
                            'status' => $subStatus,
                            'id' => $existingSub['id']
                        ]);
                        $successMsg = 'Your assignment submission has been updated successfully!';
                    } else {
                        $insSub = $db->prepare('INSERT INTO assignment_submissions (assignment_id, student_id, submitted_at, file_path, notes, status) VALUES (:aid, :sid, NOW(), :fpath, :notes, :status)');
                        $insSub->execute([
                            'aid' => $assignmentId,
                            'sid' => $studentId,
                            'fpath' => $subFilePath,
                            'notes' => $notes,
                            'status' => $subStatus
                        ]);
                        $successMsg = 'Assignment submitted successfully!';
                    }
                }
            }
        }
    }
}

// Fetch all assignments for this student's class
$assignments = [];
if ($classId > 0 && $studentId > 0) {
    $stmt = $db->prepare('
        SELECT a.*, s.name as subject_name, u.name as teacher_name,
               sub.id as submission_id, sub.submitted_at, sub.file_path as sub_file, sub.notes as sub_notes, sub.status as sub_status, sub.grade, sub.feedback
        FROM assignments a
        JOIN subjects s ON s.id = a.subject_id
        JOIN teachers t ON t.id = a.teacher_id
        JOIN users u ON u.id = t.user_id
        LEFT JOIN assignment_submissions sub ON sub.assignment_id = a.id AND sub.student_id = :sid
        WHERE a.class_id = :cid AND a.status IN ("published", "closed")
        ORDER BY a.due_date DESC
    ');
    $stmt->execute(['cid' => $classId, 'sid' => $studentId]);
    $assignments = $stmt->fetchAll();
}

$pendingCount = count(array_filter($assignments, fn($a) => empty($a['submission_id'])));
$submittedCount = count(array_filter($assignments, fn($a) => !empty($a['submission_id'])));
$gradedCount = count(array_filter($assignments, fn($a) => !empty($a['grade'])));

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-3">
    <div>
        <p class="text-primary fw-semibold mb-1"><i class="fa-solid fa-graduation-cap me-2"></i>Student Portal</p>
        <h2 class="fw-bold mb-0">My Assignments & Homework</h2>
        <small class="text-muted">Review class assignments, submit answers online, and check your teacher's feedback and grades.</small>
    </div>
    <div>
        <span class="badge bg-primary fs-6 px-3 py-2">
            <i class="fa-solid fa-school me-1"></i>Class: <?php echo e($student['class_name'] ?? 'Not Enrolled'); ?>
        </span>
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

<!-- Metrics -->
<div class="row g-3 mb-4">
    <div class="col-sm-4">
        <div class="card p-3 border-0 shadow-sm d-flex flex-row align-items-center gap-3">
            <div class="rounded-3 p-3 bg-warning bg-opacity-10 text-warning fs-3">
                <i class="fa-solid fa-clock"></i>
            </div>
            <div>
                <span class="text-muted small fw-semibold">Pending Homework</span>
                <h4 class="fw-bold mb-0 text-dark"><?php echo $pendingCount; ?></h4>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card p-3 border-0 shadow-sm d-flex flex-row align-items-center gap-3">
            <div class="rounded-3 p-3 bg-info bg-opacity-10 text-info fs-3">
                <i class="fa-solid fa-paper-plane"></i>
            </div>
            <div>
                <span class="text-muted small fw-semibold">Submitted</span>
                <h4 class="fw-bold mb-0 text-dark"><?php echo $submittedCount; ?></h4>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card p-3 border-0 shadow-sm d-flex flex-row align-items-center gap-3">
            <div class="rounded-3 p-3 bg-success bg-opacity-10 text-success fs-3">
                <i class="fa-solid fa-circle-check"></i>
            </div>
            <div>
                <span class="text-muted small fw-semibold">Graded & Reviewed</span>
                <h4 class="fw-bold mb-0 text-dark"><?php echo $gradedCount; ?></h4>
            </div>
        </div>
    </div>
</div>

<!-- Assignments List -->
<div class="card p-0 border-0 shadow-sm overflow-hidden">
    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
        <h5 class="fw-bold mb-0 text-dark">Coursework List</h5>
        <span class="badge bg-light text-secondary"><?php echo count($assignments); ?> Assignment(s)</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="min-width: 240px;">Assignment</th>
                    <th>Subject</th>
                    <th>Instructor</th>
                    <th>Deadline</th>
                    <th>Attachment</th>
                    <th>Your Submission</th>
                    <th>Grade & Feedback</th>
                    <th class="text-end" style="min-width: 140px;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($assignments)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-5 text-muted">
                            <i class="fa-solid fa-book-bookmark fs-1 text-secondary opacity-50 mb-3 d-block"></i>
                            No coursework posted for your class yet. Check back soon!
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($assignments as $a): 
                        $dueTimestamp = strtotime($a['due_date']);
                        $isOverdue = time() > $dueTimestamp;
                        $hasSubmitted = !empty($a['submission_id']);
                    ?>
                        <tr>
                            <td>
                                <div class="fw-bold text-dark"><?php echo e($a['title']); ?></div>
                                <?php if (!empty($a['description'])): ?>
                                    <small class="text-muted d-block text-truncate" style="max-width: 280px;" title="<?php echo e($a['description']); ?>">
                                        <?php echo e($a['description']); ?>
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25">
                                    <i class="fa-solid fa-book me-1"></i><?php echo e($a['subject_name']); ?>
                                </span>
                            </td>
                            <td>
                                <div class="small fw-semibold"><?php echo e($a['teacher_name']); ?></div>
                            </td>
                            <td>
                                <div class="<?php echo ($isOverdue && !$hasSubmitted) ? 'text-danger fw-semibold' : 'text-dark'; ?>">
                                    <i class="fa-regular fa-clock me-1"></i>
                                    <?php echo date('d M Y, h:i A', $dueTimestamp); ?>
                                </div>
                                <small class="text-muted">
                                    <?php if ($isOverdue): ?>
                                        <span class="badge bg-danger-subtle text-danger">Passed</span>
                                    <?php else: ?>
                                        <span class="badge bg-success-subtle text-success">Active</span>
                                    <?php endif; ?>
                                </small>
                            </td>
                            <td>
                                <?php if (!empty($a['attachment'])): ?>
                                    <a href="<?php echo baseUrl() . '/' . e($a['attachment']); ?>" target="_blank" download class="btn btn-sm btn-outline-primary" title="Download Assignment Material">
                                        <i class="fa-solid fa-file-arrow-down me-1"></i>Worksheet
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted small">None</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($hasSubmitted): ?>
                                    <div>
                                        <?php if ($a['sub_status'] === 'graded'): ?>
                                            <span class="badge bg-success"><i class="fa-solid fa-check me-1"></i>Graded</span>
                                        <?php elseif ($a['sub_status'] === 'late'): ?>
                                            <span class="badge bg-warning"><i class="fa-solid fa-clock me-1"></i>Submitted Late</span>
                                        <?php else: ?>
                                            <span class="badge bg-info"><i class="fa-solid fa-paper-plane me-1"></i>Submitted</span>
                                        <?php endif; ?>
                                    </div>
                                    <small class="text-muted d-block mt-1">
                                        <?php echo date('d M, h:i A', strtotime($a['submitted_at'])); ?>
                                    </small>
                                    <?php if (!empty($a['sub_file'])): ?>
                                        <a href="<?php echo baseUrl() . '/' . e($a['sub_file']); ?>" target="_blank" download class="small text-primary text-decoration-none">
                                            <i class="fa-solid fa-paperclip me-1"></i>My File
                                        </a>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="badge bg-secondary-subtle text-secondary">Not Submitted</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($a['grade'])): ?>
                                    <span class="badge bg-success fs-6"><?php echo e($a['grade']); ?></span>
                                    <?php if (!empty($a['feedback'])): ?>
                                        <div class="small text-muted mt-1 fst-italic bg-light p-1 rounded" style="max-width: 200px;">
                                            "<?php echo e($a['feedback']); ?>"
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted small">Pending Review</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm <?php echo $hasSubmitted ? 'btn-outline-primary' : 'btn-primary'; ?>" data-bs-toggle="modal" data-bs-target="#submitModal_<?php echo $a['id']; ?>">
                                    <i class="fa-solid <?php echo $hasSubmitted ? 'fa-pen' : 'fa-upload'; ?> me-1"></i>
                                    <?php echo $hasSubmitted ? 'Edit / Resubmit' : 'Submit Work'; ?>
                                </button>
                            </td>
                        </tr>

                        <!-- Student Submission Modal -->
                        <div class="modal fade" id="submitModal_<?php echo $a['id']; ?>" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog modal-lg">
                                <form method="POST" enctype="multipart/form-data" class="modal-content border-0 shadow">
                                    <input type="hidden" name="action" value="submit_work">
                                    <input type="hidden" name="assignment_id" value="<?php echo $a['id']; ?>">
                                    <div class="modal-header bg-primary text-white">
                                        <div>
                                            <h5 class="modal-title fw-bold"><i class="fa-solid fa-file-arrow-up me-2"></i><?php echo e($a['title']); ?></h5>
                                            <small class="opacity-75"><?php echo e($a['subject_name']); ?> • Due: <?php echo date('d M Y, h:i A', $dueTimestamp); ?></small>
                                        </div>
                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body p-4">
                                        <!-- Instructions Box -->
                                        <div class="p-3 bg-light rounded-3 mb-4 border">
                                            <h6 class="fw-bold text-dark mb-1"><i class="fa-solid fa-circle-info text-primary me-2"></i>Teacher's Instructions</h6>
                                            <p class="text-muted small mb-2"><?php echo nl2br(e($a['description'] ?: 'Complete the assignment as instructed in class or in the attached worksheet.')); ?></p>
                                            <?php if (!empty($a['attachment'])): ?>
                                                <a href="<?php echo baseUrl() . '/' . e($a['attachment']); ?>" target="_blank" download class="btn btn-sm btn-outline-primary">
                                                    <i class="fa-solid fa-file-arrow-down me-1"></i>Download Worksheet / Questions
                                                </a>
                                            <?php endif; ?>
                                        </div>

                                        <?php if (!empty($a['grade'])): ?>
                                            <div class="alert alert-success d-flex align-items-center mb-3">
                                                <i class="fa-solid fa-award fs-3 me-3"></i>
                                                <div>
                                                    <h6 class="fw-bold mb-0">Graded: <?php echo e($a['grade']); ?></h6>
                                                    <small>Feedback: <?php echo e($a['feedback'] ?: 'Well done!'); ?></small>
                                                </div>
                                            </div>
                                        <?php endif; ?>

                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Your Written Answer / Notes</label>
                                            <textarea name="notes" class="form-control" rows="5" placeholder="Type your answers, solutions, essay, or comments here..."><?php echo e($a['sub_notes'] ?? ''); ?></textarea>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label fw-bold">Upload Homework Document / File</label>
                                            <input type="file" name="submission_file" class="form-control" accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.txt,.zip,.png,.jpg,.jpeg">
                                            <small class="text-muted">Supported formats: PDF, Word, Excel, PowerPoint, Text, Images, Zip (Max 25MB).</small>
                                            <?php if (!empty($a['sub_file'])): ?>
                                                <div class="mt-2 p-2 bg-light rounded small">
                                                    <i class="fa-solid fa-check text-success me-1"></i>Currently submitted file: 
                                                    <a href="<?php echo baseUrl() . '/' . e($a['sub_file']); ?>" target="_blank" download class="fw-bold"><?php echo basename($a['sub_file']); ?></a>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="modal-footer bg-light">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fa-solid fa-paper-plane me-1"></i><?php echo $hasSubmitted ? 'Update Submission' : 'Submit Assignment'; ?>
                                        </button>
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
