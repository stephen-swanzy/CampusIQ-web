<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin(['teacher']);
$db = getDb();
$user = currentUser();
$error = '';
$success = '';

// Get teacher ID
$tchStmt = $db->prepare('SELECT id FROM teachers WHERE user_id = :uid LIMIT 1');
$tchStmt->execute(['uid' => $user['id']]);
$teacherProfile = $tchStmt->fetch();
$teacherId = $teacherProfile['id'] ?? 0;

// Calculate grade from total score
function calculateCampusGrade(float $total): array
{
    if ($total >= 80) return ['grade' => 'A', 'remark' => 'Excellent'];
    if ($total >= 70) return ['grade' => 'B', 'remark' => 'Very Good'];
    if ($total >= 60) return ['grade' => 'C', 'remark' => 'Good'];
    if ($total >= 50) return ['grade' => 'D', 'remark' => 'Pass'];
    if ($total >= 40) return ['grade' => 'E', 'remark' => 'Fair'];
    return ['grade' => 'F', 'remark' => 'Fail'];
}

// Handle Add / Record Result POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_result') {
    verifyCsrfToken();

    $studentId = (int) ($_POST['student_id'] ?? 0);
    $subjectId = (int) ($_POST['subject_id'] ?? 0);
    $examId = !empty($_POST['exam_id']) ? (int) $_POST['exam_id'] : null;
    $classScore = (float) ($_POST['class_score'] ?? 0);
    $examScore = (float) ($_POST['exam_score'] ?? 0);
    $totalScore = $classScore + $examScore;
    $remarks = trim($_POST['remarks'] ?? '');

    if ($studentId <= 0 || $subjectId <= 0) {
        $error = 'Please select a student and subject.';
    } elseif ($classScore < 0 || $examScore < 0 || $totalScore > 100) {
        $error = 'Scores must be valid numbers and total score cannot exceed 100.';
    } else {
        // Find student's class
        $stuClassStmt = $db->prepare('SELECT class_id FROM students WHERE id = :sid LIMIT 1');
        $stuClassStmt->execute(['sid' => $studentId]);
        $classId = (int) ($stuClassStmt->fetchColumn() ?: 0);

        // Compute grade
        $eval = calculateCampusGrade($totalScore);
        $grade = $eval['grade'];
        if ($remarks === '') {
            $remarks = $eval['remark'];
        }

        // Check if a result already exists for this student, subject, and exam
        $checkStmt = $db->prepare('SELECT id FROM results WHERE student_id = :sid AND subject_id = :subid AND (exam_id = :exid OR (exam_id IS NULL AND :exid2 IS NULL)) LIMIT 1');
        $checkStmt->execute(['sid' => $studentId, 'subid' => $subjectId, 'exid' => $examId, 'exid2' => $examId]);
        $existing = $checkStmt->fetch();

        if ($existing) {
            $update = $db->prepare('UPDATE results SET class_id = :cid, class_score = :cs, exam_score = :es, total_score = :ts, grade = :gr, remarks = :rem WHERE id = :id');
            $update->execute([
                'cid' => $classId ?: null,
                'cs' => $classScore,
                'es' => $examScore,
                'ts' => $totalScore,
                'gr' => $grade,
                'rem' => $remarks,
                'id' => $existing['id'],
            ]);
            $success = "Student assessment updated successfully (Total: {$totalScore}%, Grade: {$grade})!";
        } else {
            $insert = $db->prepare('INSERT INTO results (student_id, subject_id, exam_id, class_id, class_score, exam_score, total_score, grade, remarks) VALUES (:sid, :subid, :exid, :cid, :cs, :es, :ts, :gr, :rem)');
            $insert->execute([
                'sid' => $studentId,
                'subid' => $subjectId,
                'exid' => $examId,
                'cid' => $classId ?: null,
                'cs' => $classScore,
                'es' => $examScore,
                'ts' => $totalScore,
                'gr' => $grade,
                'rem' => $remarks,
            ]);
            $success = "Student grade recorded successfully (Total: {$totalScore}%, Grade: {$grade})!";
        }

        // Log activity
        $db->prepare('INSERT INTO activity_logs (user_id, action, details) VALUES (:uid, "Grade Recorded", :details)')
            ->execute([
                'uid' => $user['id'],
                'details' => "Recorded grade {$grade} ({$totalScore}%) for student ID #{$studentId}",
            ]);
    }
}

// Fetch subjects
$subjects = $db->query('SELECT id, name, code FROM subjects WHERE status = "active" ORDER BY name ASC')->fetchAll();

// Fetch examinations
$examinations = $db->query('SELECT id, name FROM examinations ORDER BY id DESC')->fetchAll();

// Fetch classes
$classes = $db->query('SELECT id, name FROM classes WHERE status = "active" ORDER BY name ASC')->fetchAll();

// Fetch all active students with class names
$students = $db->query('SELECT s.id, u.name, s.student_id as student_code, c.name as class_name 
    FROM students s 
    JOIN users u ON u.id = s.user_id 
    LEFT JOIN classes c ON c.id = s.class_id 
    WHERE s.status = "active" 
    ORDER BY u.name ASC')->fetchAll();

// Search & filter
$filterClass = (int) ($_GET['filter_class'] ?? 0);
$filterSubject = (int) ($_GET['filter_subject'] ?? 0);

$where = [];
$params = [];
if ($filterClass > 0) {
    $where[] = 'r.class_id = :fc';
    $params['fc'] = $filterClass;
}
if ($filterSubject > 0) {
    $where[] = 'r.subject_id = :fs';
    $params['fs'] = $filterSubject;
}
$whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

$resultsQuery = $db->prepare('SELECT r.*, s.student_id as student_code, u.name as student_name, subj.name as subject_name, c.name as class_name, e.name as exam_name 
    FROM results r 
    JOIN students s ON s.id = r.student_id 
    JOIN users u ON u.id = s.user_id 
    JOIN subjects subj ON subj.id = r.subject_id 
    LEFT JOIN classes c ON c.id = r.class_id 
    LEFT JOIN examinations e ON e.id = r.exam_id 
    ' . $whereSql . ' 
    ORDER BY r.created_at DESC LIMIT 50');
$resultsQuery->execute($params);
$results = $resultsQuery->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <p class="text-primary fw-semibold mb-1">Academic Assessment</p>
        <h2 class="fw-bold mb-0">Student Grades &amp; Results</h2>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addResultModal">
        <i class="fa-solid fa-plus me-2"></i>Record Student Grade
    </button>
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

<!-- Filter Bar -->
<div class="card p-3 mb-4">
    <form method="GET" class="row g-3 align-items-end">
        <div class="col-md-5">
            <label class="form-label small text-muted mb-1">Filter by Class</label>
            <select class="form-select" name="filter_class">
                <option value="">-- All Classes --</option>
                <?php foreach ($classes as $c): ?>
                    <option value="<?php echo (int) $c['id']; ?>" <?php echo $filterClass === (int) $c['id'] ? 'selected' : ''; ?>>
                        <?php echo e($c['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-5">
            <label class="form-label small text-muted mb-1">Filter by Subject</label>
            <select class="form-select" name="filter_subject">
                <option value="">-- All Subjects --</option>
                <?php foreach ($subjects as $s): ?>
                    <option value="<?php echo (int) $s['id']; ?>" <?php echo $filterSubject === (int) $s['id'] ? 'selected' : ''; ?>>
                        <?php echo e($s['name']); ?> (<?php echo e($s['code']); ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-primary w-100"><i class="fa-solid fa-filter me-1"></i> Filter</button>
        </div>
    </form>
</div>

<!-- Results Table -->
<div class="card p-4">
    <div class="table-responsive">
        <table class="table align-middle">
            <thead class="table-light">
                <tr>
                    <th>Student</th>
                    <th>Class</th>
                    <th>Subject</th>
                    <th>Assessment / Exam</th>
                    <th>Class (40%)</th>
                    <th>Exam (60%)</th>
                    <th>Total</th>
                    <th>Grade</th>
                    <th>Remarks</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($results)): ?>
                    <tr>
                        <td colspan="9">
                            <div class="empty-state text-center py-4">
                                <i class="fa-solid fa-square-poll-vertical fa-2x mb-3 text-muted d-block"></i>
                                No result records found. Click "Record Student Grade" above to add marks.
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($results as $result): 
                        $badgeClass = match($result['grade']) {
                            'A' => 'badge-success',
                            'B' => 'badge-info',
                            'C' => 'badge-primary',
                            'D' => 'badge-warning',
                            default => 'badge-danger',
                        };
                    ?>
                        <tr>
                            <td>
                                <strong><?php echo e($result['student_name']); ?></strong><br>
                                <small class="text-muted"><?php echo e($result['student_code']); ?></small>
                            </td>
                            <td><?php echo e($result['class_name'] ?? 'General'); ?></td>
                            <td><strong><?php echo e($result['subject_name']); ?></strong></td>
                            <td><?php echo e($result['exam_name'] ?? 'Regular Assessment'); ?></td>
                            <td><?php echo number_format((float)$result['class_score'], 1); ?></td>
                            <td><?php echo number_format((float)$result['exam_score'], 1); ?></td>
                            <td><strong class="text-dark"><?php echo number_format((float)$result['total_score'], 1); ?>%</strong></td>
                            <td><span class="badge-soft <?php echo $badgeClass; ?> fs-6"><?php echo e($result['grade']); ?></span></td>
                            <td><small class="text-muted"><?php echo e($result['remarks'] ?? '-'); ?></small></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Record Grade Modal -->
<div class="modal fade" id="addResultModal" tabindex="-1" aria-labelledby="addResultModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow" style="border-radius: 16px;">
            <div class="modal-header border-0 pb-0">
                <div>
                    <h5 class="modal-title fw-bold" id="addResultModalLabel">Record Student Grade</h5>
                    <small class="text-muted">Enter class and exam scores for student assessment</small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" class="p-3">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo csrfToken(); ?>">
                    <input type="hidden" name="action" value="save_result">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Select Student <span class="text-danger">*</span></label>
                            <select class="form-select" name="student_id" required>
                                <option value="">-- Choose Student --</option>
                                <?php foreach ($students as $stu): ?>
                                    <option value="<?php echo (int) $stu['id']; ?>">
                                        <?php echo e($stu['name']); ?> (<?php echo e($stu['student_code']); ?><?php echo !empty($stu['class_name']) ? ' - ' . e($stu['class_name']) : ''; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Subject / Course <span class="text-danger">*</span></label>
                            <select class="form-select" name="subject_id" required>
                                <option value="">-- Choose Subject --</option>
                                <?php foreach ($subjects as $s): ?>
                                    <option value="<?php echo (int) $s['id']; ?>">
                                        <?php echo e($s['name']); ?> (<?php echo e($s['code']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label fw-semibold">Assessment / Examination Type</label>
                            <select class="form-select" name="exam_id">
                                <option value="">Regular Class Assessment / Term Work</option>
                                <?php foreach ($examinations as $exam): ?>
                                    <option value="<?php echo (int) $exam['id']; ?>"><?php echo e($exam['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Scores with Real-Time Total Calculation -->
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Class Score (Max 40)</label>
                            <input type="number" step="0.5" min="0" max="40" class="form-control score-calc" name="class_score" id="classScoreInput" required placeholder="0.0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Exam Score (Max 60)</label>
                            <input type="number" step="0.5" min="0" max="60" class="form-control score-calc" name="exam_score" id="examScoreInput" required placeholder="0.0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Computed Total &amp; Grade</label>
                            <div class="input-group">
                                <input type="text" class="form-control fw-bold bg-light" id="previewTotal" readonly placeholder="0.0%">
                                <span class="input-group-text fw-bold bg-primary text-white" id="previewGrade">-</span>
                            </div>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold">Remarks (Optional)</label>
                            <input type="text" class="form-control" name="remarks" id="remarksInput" placeholder="e.g. Excellent progress, Needs improvement in problem solving">
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="fa-solid fa-floppy-disk me-1"></i> Save Grade
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const cs = document.getElementById('classScoreInput');
    const es = document.getElementById('examScoreInput');
    const tot = document.getElementById('previewTotal');
    const grd = document.getElementById('previewGrade');
    const rem = document.getElementById('remarksInput');

    function updateGrade() {
        const cVal = parseFloat(cs.value) || 0;
        const eVal = parseFloat(es.value) || 0;
        const total = cVal + eVal;
        tot.value = total.toFixed(1) + '%';

        let grade = 'F';
        let defaultRemark = 'Needs improvement';
        if (total >= 80) { grade = 'A'; defaultRemark = 'Excellent performance'; }
        else if (total >= 70) { grade = 'B'; defaultRemark = 'Very good work'; }
        else if (total >= 60) { grade = 'C'; defaultRemark = 'Good effort'; }
        else if (total >= 50) { grade = 'D'; defaultRemark = 'Satisfactory'; }
        else if (total >= 40) { grade = 'E'; defaultRemark = 'Fair'; }

        grd.textContent = grade;
        if (rem && (rem.value === '' || rem.dataset.auto === '1')) {
            rem.value = defaultRemark;
            rem.dataset.auto = '1';
        }
    }

    if (cs && es) {
        cs.addEventListener('input', updateGrade);
        es.addEventListener('input', updateGrade);
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
