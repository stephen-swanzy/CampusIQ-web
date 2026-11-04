<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin(['parent']);
$db = getDb();
$user = currentUser();
$settings = getSchoolSettings();

$stmtParent = $db->prepare('SELECT * FROM parents WHERE user_id = :uid LIMIT 1');
$stmtParent->execute(['uid' => $user['id']]);
$parent = $stmtParent->fetch();

$parentId = (int)($parent['id'] ?? 0);
$parentUserId = (int)$user['id'];

// Fetch children
$childrenStmt = $db->prepare('
    SELECT s.*, u.name as child_name, c.name as class_name
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

$activeChild = null;
foreach ($children as $c) {
    if ((int)$c['id'] === $selectedChildId) {
        $activeChild = $c;
        break;
    }
}

$results = [];
if ($selectedChildId > 0) {
    $stmtRes = $db->prepare('
        SELECT r.*, e.name as exam_name, s.name as subject_name
        FROM results r
        JOIN examinations e ON e.id = r.exam_id
        JOIN subjects s ON s.id = r.subject_id
        WHERE r.student_id = :sid
        ORDER BY e.id DESC, s.name ASC
    ');
    $stmtRes->execute(['sid' => $selectedChildId]);
    $results = $stmtRes->fetchAll();
}

$examsGrouped = [];
foreach ($results as $res) {
    $examsGrouped[$res['exam_name']][] = $res;
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-3">
    <div>
        <p class="text-primary fw-semibold mb-1"><i class="fa-solid fa-chart-line me-2"></i>Parent Portal</p>
        <h2 class="fw-bold mb-0">Academic Performance & Report Cards</h2>
        <small class="text-muted">Review examination scores, subject grades, and teacher remarks for your children.</small>
    </div>
    <div>
        <button type="button" class="btn btn-outline-secondary" onclick="window.print();">
            <i class="fa-solid fa-print me-2"></i>Print Terminal Report
        </button>
    </div>
</div>

<?php if (empty($children)): ?>
    <div class="card p-5 text-center border-0 shadow-sm">
        <i class="fa-solid fa-user-slash fs-1 text-secondary opacity-50 mb-3 d-block"></i>
        <h5>No Linked Students Found</h5>
        <p class="text-muted">Please contact the school office to link your parent account to your children.</p>
    </div>
<?php else: ?>

    <!-- Children Selector -->
    <div class="card p-3 mb-4 border-0 shadow-sm">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <span class="fw-bold text-dark"><i class="fa-solid fa-user-graduate text-primary me-2"></i>Select Ward:</span>
            <div class="d-flex flex-wrap gap-2">
                <?php foreach ($children as $c): 
                    $cid = (int)$c['id'];
                    $isSelected = ($cid === $selectedChildId);
                ?>
                    <a href="results.php?child_id=<?php echo $cid; ?>" class="btn <?php echo $isSelected ? 'btn-primary' : 'btn-outline-secondary'; ?>">
                        <i class="fa-solid fa-user me-1"></i><?php echo e($c['child_name']); ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <?php if (empty($examsGrouped)): ?>
        <div class="card p-5 text-center border-0 shadow-sm">
            <i class="fa-solid fa-award fs-1 text-secondary opacity-50 mb-3 d-block"></i>
            <h5>No Results Published Yet for <?php echo e($activeChild['child_name'] ?? 'Child'); ?></h5>
            <p class="text-muted">Examination marks and terminal grades have not been uploaded for this student yet.</p>
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
                        <small class="text-muted">Ward: <?php echo e($activeChild['child_name'] ?? ''); ?> (<?php echo e($activeChild['student_id'] ?? ''); ?>) • Class: <?php echo e($activeChild['class_name'] ?? ''); ?></small>
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

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
