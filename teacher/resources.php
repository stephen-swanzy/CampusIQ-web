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

    if ($action === 'upload_resource') {
        $title = trim($_POST['title'] ?? '');
        $classId = (int)($_POST['class_id'] ?? 0);
        $subjectId = (int)($_POST['subject_id'] ?? 0);
        $description = trim($_POST['description'] ?? '');

        if (empty($title) || $classId <= 0 || $subjectId <= 0) {
            $errorMsg = 'Please provide title, class, and subject for the learning material.';
        } elseif (!isset($_FILES['resource_file']) || $_FILES['resource_file']['error'] !== UPLOAD_ERR_OK) {
            $errorMsg = 'Please select a PDF, Word, or presentation file to upload.';
        } else {
            $uploadDir = __DIR__ . '/../assets/uploads/resources/';
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0755, true);
            }
            $fileTmp = $_FILES['resource_file']['tmp_name'];
            $origName = basename($_FILES['resource_file']['name']);
            $fileSize = (int)$_FILES['resource_file']['size'];
            $fileExt = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

            $allowed = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'txt', 'zip', 'png', 'jpg'];
            if (!in_array($fileExt, $allowed)) {
                $errorMsg = 'Unsupported file format. Please upload PDF, Word (.doc, .docx), PowerPoint (.ppt, .pptx), or Excel.';
            } else {
                $uniqueName = 'res_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $fileExt;
                $targetPath = $uploadDir . $uniqueName;

                if (move_uploaded_file($fileTmp, $targetPath)) {
                    $relPath = 'assets/uploads/resources/' . $uniqueName;
                    $ins = $db->prepare('INSERT INTO learning_resources (title, description, class_id, subject_id, teacher_id, file_path, file_type, file_size, status) VALUES (:title, :description, :class_id, :subject_id, :teacher_id, :file_path, :file_type, :file_size, "active")');
                    $ins->execute([
                        'title' => $title,
                        'description' => $description,
                        'class_id' => $classId,
                        'subject_id' => $subjectId,
                        'teacher_id' => $teacherId,
                        'file_path' => $relPath,
                        'file_type' => strtoupper($fileExt),
                        'file_size' => $fileSize
                    ]);
                    $successMsg = 'Learning resource uploaded successfully!';
                } else {
                    $errorMsg = 'Failed to save file to server.';
                }
            }
        }
    } elseif ($action === 'delete_resource') {
        $resId = (int)($_POST['resource_id'] ?? 0);
        $stmtFind = $db->prepare('SELECT file_path FROM learning_resources WHERE id = :id AND teacher_id = :tid');
        $stmtFind->execute(['id' => $resId, 'tid' => $teacherId]);
        $resRow = $stmtFind->fetch();

        if ($resRow) {
            $absPath = __DIR__ . '/../' . $resRow['file_path'];
            if (file_exists($absPath)) {
                @unlink($absPath);
            }
            $del = $db->prepare('DELETE FROM learning_resources WHERE id = :id AND teacher_id = :tid');
            $del->execute(['id' => $resId, 'tid' => $teacherId]);
            $successMsg = 'Resource removed successfully.';
        } else {
            $errorMsg = 'Resource not found or unauthorized.';
        }
    }
}

// Fetch classes and subjects
$classes = $db->query('SELECT id, name FROM classes ORDER BY name ASC')->fetchAll();
$subjects = $db->query('SELECT id, name FROM subjects ORDER BY name ASC')->fetchAll();

// Filters
$filterClass = (int)($_GET['class_id'] ?? 0);
$filterSubject = (int)($_GET['subject_id'] ?? 0);
$filterType = trim($_GET['file_type'] ?? '');

$sql = 'SELECT r.*, c.name as class_name, s.name as subject_name
        FROM learning_resources r
        JOIN classes c ON c.id = r.class_id
        JOIN subjects s ON s.id = r.subject_id
        WHERE r.teacher_id = :tid';
$params = ['tid' => $teacherId];

if ($filterClass > 0) {
    $sql .= ' AND r.class_id = :cid';
    $params['cid'] = $filterClass;
}
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

// Stats
$totalResources = count($resources);
$pdfCount = count(array_filter($resources, fn($r) => $r['file_type'] === 'PDF'));
$wordCount = count(array_filter($resources, fn($r) => in_array($r['file_type'], ['DOC', 'DOCX'])));

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

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-3">
    <div>
        <p class="text-primary fw-semibold mb-1"><i class="fa-solid fa-folder-open me-2"></i>Academic Materials</p>
        <h2 class="fw-bold mb-0">Learning Resources</h2>
        <small class="text-muted">Upload and share course notes, slides, handouts, and textbooks (PDF, Word, PPT) with your classes.</small>
    </div>
    <div>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#uploadResourceModal">
            <i class="fa-solid fa-cloud-arrow-up me-2"></i>Upload Resource
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
    <div class="col-sm-4">
        <div class="card p-3 border-0 shadow-sm d-flex flex-row align-items-center gap-3">
            <div class="rounded-3 p-3 bg-primary bg-opacity-10 text-primary fs-3">
                <i class="fa-solid fa-folder-tree"></i>
            </div>
            <div>
                <span class="text-muted small fw-semibold">Total Resources</span>
                <h4 class="fw-bold mb-0 text-dark"><?php echo $totalResources; ?></h4>
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
                <span class="text-muted small fw-semibold">Word & Presentations</span>
                <h4 class="fw-bold mb-0 text-dark"><?php echo $wordCount; ?></h4>
            </div>
        </div>
    </div>
</div>

<!-- Filter Bar -->
<div class="card p-3 mb-4 border-0 shadow-sm">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-4">
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
            <label class="form-label small fw-bold text-muted mb-1">Format</label>
            <select name="file_type" class="form-select form-select-sm">
                <option value="">All Formats</option>
                <option value="PDF" <?php echo $filterType === 'PDF' ? 'selected' : ''; ?>>PDF</option>
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
        <h5 class="fw-bold mb-0 text-dark">Uploaded Materials</h5>
        <span class="badge bg-light text-secondary"><?php echo count($resources); ?> Item(s)</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="min-width: 250px;">Document Title</th>
                    <th>Target Class</th>
                    <th>Subject</th>
                    <th>Format</th>
                    <th>File Size</th>
                    <th>Uploaded Date</th>
                    <th class="text-end" style="min-width: 150px;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($resources)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-5 text-muted">
                            <i class="fa-solid fa-folder-open fs-1 text-secondary opacity-50 mb-3 d-block"></i>
                            No learning resources uploaded yet. Click <strong>"Upload Resource"</strong> to share study materials.
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
                                            <small class="text-muted d-block text-truncate" style="max-width: 260px;" title="<?php echo e($res['description']); ?>">
                                                <?php echo e($res['description']); ?>
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="badge bg-secondary bg-opacity-10 text-secondary border">
                                    <i class="fa-solid fa-school me-1"></i><?php echo e($res['class_name']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge bg-primary bg-opacity-10 text-primary border">
                                    <i class="fa-solid fa-book me-1"></i><?php echo e($res['subject_name']); ?>
                                </span>
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
                                <span class="small text-dark"><?php echo date('d M Y, h:i A', strtotime($res['created_at'])); ?></span>
                            </td>
                            <td class="text-end">
                                <div class="d-inline-flex gap-2">
                                    <a href="<?php echo baseUrl() . '/' . e($res['file_path']); ?>" target="_blank" download class="btn btn-sm btn-outline-primary" title="Download Material">
                                        <i class="fa-solid fa-download me-1"></i>Download
                                    </a>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to remove this resource?');">
                                        <input type="hidden" name="action" value="delete_resource">
                                        <input type="hidden" name="resource_id" value="<?php echo $res['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Upload Resource Modal -->
<div class="modal fade" id="uploadResourceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form method="POST" enctype="multipart/form-data" class="modal-content border-0 shadow">
            <input type="hidden" name="action" value="upload_resource">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-cloud-arrow-up me-2"></i>Upload Learning Resource</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <label class="form-label fw-bold">Resource Title <span class="text-danger">*</span></label>
                    <input type="text" name="title" class="form-control" placeholder="e.g. Chapter 4: Photosynthesis & Respiration Study Guide" required>
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
                <div class="mb-3">
                    <label class="form-label fw-bold">Brief Description / Notes for Students</label>
                    <textarea name="description" class="form-control" rows="3" placeholder="Explain what this material covers and how students should prepare..."></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Select File (PDF, Word, Presentation) <span class="text-danger">*</span></label>
                    <input type="file" name="resource_file" class="form-control" accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.txt,.zip" required>
                    <small class="text-muted">Allowed types: PDF (.pdf), Microsoft Word (.doc, .docx), PowerPoint (.ppt, .pptx), Excel (.xls, .xlsx). Max file size: 50MB.</small>
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-cloud-arrow-up me-1"></i>Upload & Publish</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
