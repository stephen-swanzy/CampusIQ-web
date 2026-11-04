<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin(['parent']);
$db = getDb();
$user = currentUser();

$stmtParent = $db->prepare('SELECT * FROM parents WHERE user_id = :uid LIMIT 1');
$stmtParent->execute(['uid' => $user['id']]);
$parent = $stmtParent->fetch();

$parentId = (int)($parent['id'] ?? 0);
$parentUserId = (int)$user['id'];

$children = $db->prepare('
    SELECT s.*, u.name as child_name, u.email as child_email, c.name as class_name
    FROM students s
    JOIN users u ON u.id = s.user_id
    LEFT JOIN classes c ON c.id = s.class_id
    WHERE (s.parent_id = :pid OR s.parent_id = :puid)
    ORDER BY u.name ASC
');
$children->execute(['pid' => $parentId, 'puid' => $parentUserId]);
$childrenList = $children->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-3">
    <div>
        <p class="text-primary fw-semibold mb-1"><i class="fa-solid fa-children me-2"></i>Parent Portal</p>
        <h2 class="fw-bold mb-0">My Children & Wards</h2>
        <small class="text-muted">Manage your children's enrollment profiles, academic details, and institutional status.</small>
    </div>
</div>

<div class="row g-4">
    <?php if (empty($childrenList)): ?>
        <div class="col-12">
            <div class="card p-5 text-center border-0 shadow-sm">
                <i class="fa-solid fa-user-slash fs-1 text-secondary opacity-50 mb-3 d-block"></i>
                <h5 class="fw-bold">No Children Registered</h5>
                <p class="text-muted">No student profiles are linked to your parent account yet. Please contact school administration.</p>
            </div>
        </div>
    <?php else: ?>
        <?php foreach ($childrenList as $c): ?>
            <div class="col-md-6 col-xl-4">
                <div class="card border-0 shadow-sm p-4 h-100 d-flex flex-column justify-content-between">
                    <div>
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <div class="rounded-circle bg-primary bg-opacity-10 text-primary p-3 fs-3 d-flex align-items-center justify-content-center" style="width: 56px; height: 56px;">
                                <i class="fa-solid fa-graduation-cap"></i>
                            </div>
                            <div>
                                <h5 class="fw-bold mb-0 text-dark"><?php echo e($c['child_name']); ?></h5>
                                <span class="badge bg-light text-secondary border"><?php echo e($c['student_id'] ?? 'N/A'); ?></span>
                            </div>
                        </div>

                        <div class="p-3 bg-light rounded-3 mb-3 small">
                            <div class="d-flex justify-content-between py-1 border-bottom">
                                <span class="text-muted">Enrolled Class:</span>
                                <strong><?php echo e($c['class_name'] ?? 'Not Enrolled'); ?></strong>
                            </div>
                            <div class="d-flex justify-content-between py-1 border-bottom">
                                <span class="text-muted">Gender:</span>
                                <span class="text-capitalize"><?php echo e($c['gender'] ?? 'N/A'); ?></span>
                            </div>
                            <div class="d-flex justify-content-between py-1 border-bottom">
                                <span class="text-muted">Date of Birth:</span>
                                <span><?php echo !empty($c['date_of_birth']) ? date('d M Y', strtotime($c['date_of_birth'])) : 'N/A'; ?></span>
                            </div>
                            <div class="d-flex justify-content-between py-1">
                                <span class="text-muted">Enrollment Status:</span>
                                <span class="badge bg-success-subtle text-success text-capitalize"><?php echo e($c['status'] ?? 'Active'); ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2 pt-2 border-top">
                        <a href="attendance.php?child_id=<?php echo $c['id']; ?>" class="btn btn-sm btn-outline-primary flex-fill">
                            <i class="fa-solid fa-calendar-check me-1"></i>Attendance
                        </a>
                        <a href="results.php?child_id=<?php echo $c['id']; ?>" class="btn btn-sm btn-outline-info flex-fill">
                            <i class="fa-solid fa-chart-line me-1"></i>Grades
                        </a>
                        <a href="fees.php?child_id=<?php echo $c['id']; ?>" class="btn btn-sm btn-primary flex-fill">
                            <i class="fa-solid fa-wallet me-1"></i>Fees
                        </a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
