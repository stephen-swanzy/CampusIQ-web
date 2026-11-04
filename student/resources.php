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

// Filters
$filterSubject = (int)($_GET['subject_id'] ?? 0);
$filterType = trim($_GET['file_type'] ?? '');

$resources = [];
$subjects = [];

if ($classId > 0) {
    $subjects = $db->query('SELECT id, name FROM subjects ORDER BY name ASC')->fetchAll();

    $sql = 'SELECT r.*, s.name as subject_name, u.name as teacher_name
            FROM learning_resources r
            JOIN subjects s ON s.id = r.subject_id
            JOIN teachers t ON t.id = r.teacher_id
            JOIN users u ON u.id = t.user_id
            WHERE r.class_id = :cid AND r.status = "active"';
    $params = ['cid' => $classId];

    if ($filterSubject > 0) {
        $sql .= ' AND r.subject_id = :sid';
        $params['sid'] = $filterSubject;
    }
    if (!empty($filterType)) {
        $sql .= ' AND r.file_type = :ftype';
        $params['ftype'] = strtoupper($filterType);
    }
    $sql .= ' ORDER BY r.created_at DESC';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $resources = $stmt->fetchAll();
}

function formatFileSize(?int $bytes): string {
    if (!$bytes) return '0 KB';
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1) . ' MB';
    }
    return number_format($bytes / 1024, 0) . ' KB';
}

function getFileIcon(string $type): string {
    $t = strtoupper($type);
    if ($t === 'PDF') return 'fa-file-pdf text-danger';
    if (in_array($t, ['DOC', 'DOCX'])) return 'fa-file-word text-primary';
    if (in_array($t, ['PPT', 'PPTX'])) return 'fa-file-powerpoint text-warning';
    if (in_array($t, ['XLS', 'XLSX'])) return 'fa-file-excel text-success';
    return 'fa-file-lines text-secondary';
}

$totalCount = count($resources);
$pdfCount = count(array_filter($resources, fn($r) => $r['file_type'] === 'PDF'));
$wordCount = count(array_filter($resources, fn($r) => in_array($r['file_type'], ['DOC', 'DOCX'])));

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-3">
    <div>
        <p class="text-primary fw-semibold mb-1"><i class="fa-solid fa-graduation-cap me-2"></i>Student Portal</p>
        <h2 class="fw-bold mb-0">Learning Resources & Library</h2>
        <small class="text-muted">Access reading materials, lecture notes, study slides, and handouts uploaded by your teachers.</small>
    </div>
    <div>
        <span class="badge bg-primary fs-6 px-3 py-2">
            <i class="fa-solid fa-school me-1"></i>Class: <?php echo e($student['class_name'] ?? 'Not Enrolled'); ?>
        </span>
    </div>
</div>

<!-- Stats Row -->
<div class="row g-3 mb-4">
    <div class="col-sm-4">
        <div class="card p-3 border-0 shadow-sm d-flex flex-row align-items-center gap-3">
            <div class="rounded-3 p-3 bg-primary bg-opacity-10 text-primary fs-3">
                <i class="fa-solid fa-book-bookmark"></i>
            </div>
            <div>
                <span class="text-muted small fw-semibold">Available Resources</span>
                <h4 class="fw-bold mb-0 text-dark"><?php echo $totalCount; ?></h4>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card p-3 border-0 shadow-sm d-flex flex-row align-items-center gap-3">
            <div class="rounded-3 p-3 bg-danger bg-opacity-10 text-danger fs-3">
                <i class="fa-solid fa-file-pdf"></i>
            </div>
            <div>
                <span class="text-muted small fw-semibold">PDF Documents</span>
                <h4 class="fw-bold mb-0 text-dark"><?php echo $pdfCount; ?></h4>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card p-3 border-0 shadow-sm d-flex flex-row align-items-center gap-3">
            <div class="rounded-3 p-3 bg-info bg-opacity-10 text-info fs-3">
                <i class="fa-solid fa-file-word"></i>
            </div>
            <div>
                <span class="text-muted small fw-semibold">Word / Slides</span>
                <h4 class="fw-bold mb-0 text-dark"><?php echo $wordCount; ?></h4>
            </div>
        </div>
    </div>
</div>

<!-- Filter Bar -->
<div class="card p-3 mb-4 border-0 shadow-sm">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-6">
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
        <div class="col-md-4">
            <label class="form-label small fw-bold text-muted mb-1">File Format</label>
            <select name="file_type" class="form-select form-select-sm">
                <option value="">All Formats</option>
                <option value="PDF" <?php echo $filterType === 'PDF' ? 'selected' : ''; ?>>PDF Documents</option>
                <option value="DOCX" <?php echo in_array($filterType, ['DOC', 'DOCX']) ? 'selected' : ''; ?>>Word (DOC/DOCX)</option>
                <option value="PPTX" <?php echo in_array($filterType, ['PPT', 'PPTX']) ? 'selected' : ''; ?>>PowerPoint</option>
            </select>
        </div>
        <div class="col-md-2 d-flex gap-2">
            <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fa-solid fa-filter me-1"></i>Filter</button>
            <a href="resources.php" class="btn btn-light btn-sm"><i class="fa-solid fa-rotate-left"></i></a>
        </div>
    </form>
</div>

<!-- Resources Table -->
<div class="card p-0 border-0 shadow-sm overflow-hidden">
    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
        <h5 class="fw-bold mb-0 text-dark">Course Materials for Your Class</h5>
        <span class="badge bg-light text-secondary"><?php echo count($resources); ?> Item(s)</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="min-width: 260px;">Resource Title</th>
                    <th>Subject</th>
                    <th>Teacher</th>
                    <th>Format</th>
                    <th>File Size</th>
                    <th>Date Added</th>
                    <th class="text-end" style="min-width: 140px;">Download</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($resources)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-5 text-muted">
                            <i class="fa-solid fa-folder-open fs-1 text-secondary opacity-50 mb-3 d-block"></i>
                            No learning resources uploaded for your class yet. Check back soon!
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($resources as $res): ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-3">
                                    <div class="fs-2">
                                        <i class="fa-solid <?php echo getFileIcon($res['file_type']); ?>"></i>
                                    </div>
                                    <div>
                                        <div class="fw-bold text-dark"><?php echo e($res['title']); ?></div>
                                        <?php if (!empty($res['description'])): ?>
                                            <small class="text-muted d-block text-truncate" style="max-width: 300px;" title="<?php echo e($res['description']); ?>">
                                                <?php echo e($res['description']); ?>
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25">
                                    <i class="fa-solid fa-book me-1"></i><?php echo e($res['subject_name']); ?>
                                </span>
                            </td>
                            <td>
                                <div class="small fw-semibold"><?php echo e($res['teacher_name']); ?></div>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border fw-semibold">
                                    <?php echo e($res['file_type']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="small text-muted"><?php echo formatFileSize($res['file_size']); ?></span>
                            </td>
                            <td>
                                <span class="small text-dark"><?php echo date('d M Y', strtotime($res['created_at'])); ?></span>
                            </td>
                            <td class="text-end">
                                <a href="<?php echo baseUrl() . '/' . e($res['file_path']); ?>" target="_blank" download class="btn btn-sm btn-primary" title="Download Material">
                                    <i class="fa-solid fa-file-arrow-down me-1"></i>Download
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
