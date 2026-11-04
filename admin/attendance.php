<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin(['super_admin','school_admin']);
require_once __DIR__ . '/../includes/header.php';
$db = getDb();
$records = $db->query('SELECT a.*, u.name as student_name, c.name as class_name FROM attendance a JOIN students s ON s.id = a.student_id JOIN users u ON u.id = s.user_id JOIN classes c ON c.id = a.class_id ORDER BY a.date DESC LIMIT 20')->fetchAll();
?>
<div class="page-header"><div><p class="text-primary fw-semibold mb-1">Attendance</p><h2 class="fw-bold mb-0">Attendance Overview</h2></div></div>
<div class="card p-4"><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Student</th><th>Class</th><th>Date</th><th>Status</th></tr></thead><tbody><?php foreach ($records as $record): ?><tr><td><?php echo e($record['student_name']); ?></td><td><?php echo e($record['class_name']); ?></td><td><?php echo e(date('d M Y', strtotime($record['date']))); ?></td><td><span class="badge-soft <?php echo $record['status'] === 'Present' ? 'badge-success' : ($record['status'] === 'Late' ? 'badge-warning' : 'badge-danger'); ?>"><?php echo e($record['status']); ?></span></td></tr><?php endforeach; ?></tbody></table></div></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
