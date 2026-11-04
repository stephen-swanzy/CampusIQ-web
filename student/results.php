<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin(['student']);
$db = getDb();
$user = currentUser();
$settings = getSchoolSettings();

$stmtStudent = $db->prepare('SELECT s.*, c.name as class_name FROM students s LEFT JOIN classes c ON c.id = s.class_id WHERE s.user_id = :uid LIMIT 1');
$stmtStudent->execute(['uid' => $user['id']]);
$student = $stmtStudent->fetch();
$studentId = (int)($student['id'] ?? 0);

$results = [];
if ($studentId > 0) {
    $stmtRes = $db->prepare('
        SELECT r.*, e.name as exam_name, s.name as subject_name
        FROM results r
        JOIN examinations e ON e.id = r.exam_id
        JOIN subjects s ON s.id = r.subject_id
        WHERE r.student_id = :sid
        ORDER BY e.id DESC, s.name ASC
    ');
    $stmtRes->execute(['sid' => $studentId]);
    $results = $stmtRes->fetchAll();
}

// Group results by exam
$examsGrouped = [];
foreach ($results as $res) {
    $examsGrouped[$res['exam_name']][] = $res;
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-3">
    <div>
        <p class="text-primary fw-semibold mb-1"><i class="fa-solid fa-square-poll-vertical me-2"></i>Student Portal</p>
        <h2 class="fw-bold mb-0">Academic Grades & Examination Results</h2>
        <small class="text-muted">Review continuous assessment scores, final examination marks, and official grades.</small>
    </div>
    <div>
        <button type="button" class="btn btn-outline-secondary" onclick="window.print();">
            <i class="fa-solid fa-print me-2"></i>Print Terminal Report
        </button>
    </div>
</div>

<?php if (empty($examsGrouped)): ?>
    <div class="card p-5 text-center border-0 shadow-sm">
        <i class="fa-solid fa-award fs-1 text-secondary opacity-50 mb-3 d-block"></i>
        <h5>No Examination Grades Published Yet</h5>
        <p class="text-muted">Your teachers have not published any terminal grades or examination results yet.</p>
    </div>
<?php else: ?>
    <?php foreach ($examsGrouped as $examTitle => $examResults): 
        $totalScores = 0;
        $countSub = count($examResults);
        foreach ($examResults as $er) {
            $totalScores += (float)$er['total_score'];
        }
        $avgScore = ($countSub > 0) ? round($totalScores / $countSub, 1) : 0;
    ?>
        <div class="card p-0 border-0 shadow-sm overflow-hidden mb-4">
            <div class="card-header bg-white py-3 border-bottom d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <h5 class="fw-bold mb-0 text-dark"><?php echo e($examTitle); ?></h5>
                    <small class="text-muted">Student: <?php echo e($user['name']); ?> (<?php echo e($student['student_id'] ?? ''); ?>) • Class: <?php echo e($student['class_name'] ?? ''); ?></small>
                </div>
                <div class="d-flex align-items-center gap-3">
                    <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-3 py-2 fs-6">
                        Average: <strong><?php echo $avgScore; ?>%</strong>
                    </span>
                    <span class="badge bg-light text-secondary"><?php echo $countSub; ?> Subject(s)</span>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Subject</th>
                            <th>Class Score (30%)</th>
                            <th>Exam Score (70%)</th>
                            <th>Total Mark (100%)</th>
                            <th>Grade</th>
                            <th>Remark</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($examResults as $r): ?>
                            <tr>
                                <td>
                                    <strong><?php echo e($r['subject_name']); ?></strong>
                                </td>
                                <td>
                                    <?php echo number_format((float)$r['class_score'], 1); ?>
                                </td>
                                <td>
                                    <?php echo number_format((float)$r['exam_score'], 1); ?>
                                </td>
                                <td>
                                    <strong class="fs-6"><?php echo number_format((float)$r['total_score'], 1); ?></strong>
                                </td>
                                <td>
                                    <span class="badge bg-primary fs-6 px-2 py-1"><?php echo e($r['grade']); ?></span>
                                </td>
                                <td>
                                    <span class="text-muted small"><?php echo e($r['remarks'] ?: 'Satisfactory'); ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
