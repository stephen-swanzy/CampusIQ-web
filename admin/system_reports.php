<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin(['super_admin', 'school_admin']);

$db = getDb();
$user = currentUser();
$settings = getSchoolSettings();
$successMsg = '';
$errorMsg = '';

// Handle Status & Response Updates
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verifyCsrfToken();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_report') {
        $reportId = (int)($_POST['report_id'] ?? 0);
        $newStatus = trim($_POST['status'] ?? 'pending');
        $adminResponse = trim($_POST['admin_response'] ?? '');

        $validStatuses = ['pending', 'reviewing', 'resolved', 'dismissed'];
        if (!in_array($newStatus, $validStatuses, true)) {
            $newStatus = 'pending';
        }

        if ($reportId > 0) {
            $stmt = $db->prepare('UPDATE system_reports SET 
                status = :status, 
                admin_response = :response, 
                responded_by = :admin_id, 
                responded_at = NOW() 
                WHERE id = :id');
            $stmt->execute([
                'status' => $newStatus,
                'response' => $adminResponse ?: null,
                'admin_id' => $user['id'],
                'id' => $reportId,
            ]);

            // Activity Log
            $db->prepare('INSERT INTO activity_logs (user_id, action, details) VALUES (:uid, "Updated System Report", :details)')
                ->execute([
                    'uid' => $user['id'],
                    'details' => "Updated Report #{$reportId} status to {$newStatus}"
                ]);

            $successMsg = "Report #{$reportId} updated successfully.";
        }
    } elseif ($action === 'delete_report') {
        $reportId = (int)($_POST['report_id'] ?? 0);
        if ($reportId > 0) {
            $stmt = $db->prepare('DELETE FROM system_reports WHERE id = :id');
            $stmt->execute(['id' => $reportId]);

            $successMsg = "Report #{$reportId} has been deleted.";
        }
    }
}

// Filters
$filterStatus = trim($_GET['status'] ?? 'all');
$filterType = trim($_GET['type'] ?? 'all');
$filterRole = trim($_GET['role'] ?? 'all');
$filterPriority = trim($_GET['priority'] ?? 'all');
$search = trim($_GET['q'] ?? '');

// Metrics
$totalReports = (int)$db->query('SELECT COUNT(*) FROM system_reports')->fetchColumn();
$pendingCount = (int)$db->query('SELECT COUNT(*) FROM system_reports WHERE status = "pending"')->fetchColumn();
$reviewingCount = (int)$db->query('SELECT COUNT(*) FROM system_reports WHERE status = "reviewing"')->fetchColumn();
$resolvedCount = (int)$db->query('SELECT COUNT(*) FROM system_reports WHERE status = "resolved"')->fetchColumn();

// Query Reports with filters
$query = 'SELECT sr.*, u.name as admin_responder_name 
          FROM system_reports sr 
          LEFT JOIN users u ON u.id = sr.responded_by 
          WHERE 1=1';
$params = [];

if ($filterStatus !== 'all') {
    $query .= ' AND sr.status = :f_status';
    $params['f_status'] = $filterStatus;
}
if ($filterType !== 'all') {
    $query .= ' AND sr.report_type = :f_type';
    $params['f_type'] = $filterType;
}
if ($filterRole !== 'all') {
    $query .= ' AND sr.user_role = :f_role';
    $params['f_role'] = $filterRole;
}
if ($filterPriority !== 'all') {
    $query .= ' AND sr.priority = :f_priority';
    $params['f_priority'] = $filterPriority;
}
if ($search !== '') {
    $query .= ' AND (sr.subject LIKE :search OR sr.message LIKE :search OR sr.user_name LIKE :search)';
    $params['search'] = "%{$search}%";
}

$query .= ' ORDER BY CASE sr.status WHEN "pending" THEN 1 WHEN "reviewing" THEN 2 WHEN "resolved" THEN 3 ELSE 4 END, sr.created_at DESC';
$stmt = $db->prepare($query);
$stmt->execute($params);
$reports = $stmt->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<!-- 1. Executive Hero Header -->
<div class="dash-hero mb-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                <span class="dash-chip primary">
                    <i class="fa-solid fa-screwdriver-wrench"></i> Quality Assurance &amp; Feedback
                </span>
                <span class="dash-chip rose">
                    <i class="fa-solid fa-flag"></i> User Feedback
                </span>
                <?php if ($pendingCount > 0): ?>
                    <span class="dash-chip warning">
                        <span class="pulse-dot me-1" style="background:#f59e0b;"></span> <?php echo $pendingCount; ?> Unresolved
                    </span>
                <?php endif; ?>
            </div>
            <h1 class="dash-hero-title">System Concerns &amp; Feedback <span class="wave-emoji">🛠️</span></h1>
            <p class="dash-hero-subtitle">Review real-time bug reports, usability suggestions, and system concerns submitted by students, teachers, parents, and administrative staff to continuously improve CampusIQ.</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <button class="btn btn-outline-danger px-3 shadow-sm bg-white" data-bs-toggle="modal" data-bs-target="#reportSystemConcernModal">
                <i class="fa-solid fa-plus me-2"></i>Submit Test Concern
            </button>
            <a href="index.php" class="btn btn-primary px-3 shadow-sm">
                <i class="fa-solid fa-gauge-high me-2"></i>Admin Dashboard
            </a>
        </div>
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
        <i class="fa-solid fa-circle-exclamation fs-5 me-2"></i>
        <div><?php echo e($errorMsg); ?></div>
        <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- 2. KPI Metrics 2.0 -->
<div class="kpi-row mb-4">
    <div class="kpi-card-v2">
        <div class="kpi-header">
            <span class="kpi-lbl mb-0">Total Submissions</span>
            <div class="kpi-icon-wrap bg-soft-blue"><i class="fa-solid fa-bullhorn"></i></div>
        </div>
        <div class="kpi-val"><?php echo number_format($totalReports); ?></div>
        <span class="kpi-badge badge-soft-blue"><i class="fa-solid fa-inbox me-1"></i>All-Time Records</span>
    </div>

    <div class="kpi-card-v2">
        <div class="kpi-header">
            <span class="kpi-lbl mb-0">Pending Review</span>
            <div class="kpi-icon-wrap bg-soft-rose"><i class="fa-solid fa-clock-rotate-left"></i></div>
        </div>
        <div class="kpi-val text-danger"><?php echo number_format($pendingCount); ?></div>
        <span class="kpi-badge badge-soft-rose"><i class="fa-solid fa-circle-exclamation me-1"></i>Awaiting Triage</span>
    </div>

    <div class="kpi-card-v2">
        <div class="kpi-header">
            <span class="kpi-lbl mb-0">In Progress / Review</span>
            <div class="kpi-icon-wrap bg-soft-amber"><i class="fa-solid fa-spinner"></i></div>
        </div>
        <div class="kpi-val text-warning"><?php echo number_format($reviewingCount); ?></div>
        <span class="kpi-badge badge-soft-amber"><i class="fa-solid fa-gear me-1"></i>Under Investigation</span>
    </div>

    <div class="kpi-card-v2">
        <div class="kpi-header">
            <span class="kpi-lbl mb-0">Resolved &amp; Closed</span>
            <div class="kpi-icon-wrap bg-soft-emerald"><i class="fa-solid fa-circle-check"></i></div>
        </div>
        <div class="kpi-val text-success"><?php echo number_format($resolvedCount); ?></div>
        <span class="kpi-badge badge-soft-emerald"><i class="fa-solid fa-sparkles me-1"></i>Fixed &amp; Improved</span>
    </div>
</div>

<!-- 3. Filter Bar & Search -->
<div class="card p-3 mb-4 shadow-sm border-0">
    <form method="GET" class="row g-2 align-items-center">
        <div class="col-lg-3 col-md-6">
            <div class="input-group">
                <span class="input-group-text bg-white border-end-0"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
                <input type="text" name="q" class="form-control border-start-0" placeholder="Search subject, details, or user..." value="<?php echo e($search); ?>">
            </div>
        </div>

        <div class="col-lg-2 col-md-3 col-6">
            <select name="status" class="form-select" onchange="this.form.submit()">
                <option value="all" <?php echo $filterStatus === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                <option value="pending" <?php echo $filterStatus === 'pending' ? 'selected' : ''; ?>>Pending (<?php echo $pendingCount; ?>)</option>
                <option value="reviewing" <?php echo $filterStatus === 'reviewing' ? 'selected' : ''; ?>>In Review</option>
                <option value="resolved" <?php echo $filterStatus === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                <option value="dismissed" <?php echo $filterStatus === 'dismissed' ? 'selected' : ''; ?>>Dismissed</option>
            </select>
        </div>

        <div class="col-lg-2 col-md-3 col-6">
            <select name="type" class="form-select" onchange="this.form.submit()">
                <option value="all" <?php echo $filterType === 'all' ? 'selected' : ''; ?>>All Categories</option>
                <option value="bug" <?php echo $filterType === 'bug' ? 'selected' : ''; ?>>🐛 Bugs / Errors</option>
                <option value="feature_request" <?php echo $filterType === 'feature_request' ? 'selected' : ''; ?>>💡 Feature Ideas</option>
                <option value="usability" <?php echo $filterType === 'usability' ? 'selected' : ''; ?>>🎨 Usability</option>
                <option value="performance" <?php echo $filterType === 'performance' ? 'selected' : ''; ?>>⚡ Performance</option>
                <option value="general" <?php echo $filterType === 'general' ? 'selected' : ''; ?>>💬 General</option>
            </select>
        </div>

        <div class="col-lg-2 col-md-3 col-6">
            <select name="role" class="form-select" onchange="this.form.submit()">
                <option value="all" <?php echo $filterRole === 'all' ? 'selected' : ''; ?>>All Roles</option>
                <option value="student" <?php echo $filterRole === 'student' ? 'selected' : ''; ?>>Student</option>
                <option value="teacher" <?php echo $filterRole === 'teacher' ? 'selected' : ''; ?>>Teacher</option>
                <option value="parent" <?php echo $filterRole === 'parent' ? 'selected' : ''; ?>>Parent</option>
                <option value="accountant" <?php echo $filterRole === 'accountant' ? 'selected' : ''; ?>>Accountant</option>
                <option value="school_admin" <?php echo $filterRole === 'school_admin' ? 'selected' : ''; ?>>School Admin</option>
                <option value="super_admin" <?php echo $filterRole === 'super_admin' ? 'selected' : ''; ?>>Super Admin</option>
            </select>
        </div>

        <div class="col-lg-2 col-md-3 col-6">
            <select name="priority" class="form-select" onchange="this.form.submit()">
                <option value="all" <?php echo $filterPriority === 'all' ? 'selected' : ''; ?>>All Priorities</option>
                <option value="critical" <?php echo $filterPriority === 'critical' ? 'selected' : ''; ?>>🔴 Critical</option>
                <option value="high" <?php echo $filterPriority === 'high' ? 'selected' : ''; ?>>🟠 High</option>
                <option value="medium" <?php echo $filterPriority === 'medium' ? 'selected' : ''; ?>>🟡 Normal</option>
                <option value="low" <?php echo $filterPriority === 'low' ? 'selected' : ''; ?>>🟢 Low</option>
            </select>
        </div>

        <div class="col-lg-1 col-md-12 d-flex gap-2">
            <button type="submit" class="btn btn-primary w-100"><i class="fa-solid fa-filter"></i></button>
            <?php if ($filterStatus !== 'all' || $filterType !== 'all' || $filterRole !== 'all' || $filterPriority !== 'all' || $search !== ''): ?>
                <a href="system_reports.php" class="btn btn-light" title="Reset Filters"><i class="fa-solid fa-xmark"></i></a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- 4. Reports Table / Feed -->
<div class="card p-0 border-0 shadow-sm overflow-hidden mb-4">
    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
        <div>
            <h5 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-list-check text-primary me-2"></i>Submitted System Concerns</h5>
            <small class="text-muted">Showing <?php echo count($reports); ?> report(s) based on your filters</small>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width: 70px;">ID</th>
                    <th>Submitter</th>
                    <th>Category</th>
                    <th>Priority</th>
                    <th>Subject &amp; Preview</th>
                    <th>Status</th>
                    <th>Submitted</th>
                    <th class="text-end" style="width: 130px;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($reports)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-5 text-muted">
                            <i class="fa-solid fa-circle-check fs-2 text-success opacity-50 mb-2 d-block"></i>
                            <strong>No system concerns found.</strong>
                            <p class="small mb-0">All reports have been addressed or no submissions match the current filters.</p>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($reports as $r): 
                        $badgePriorityClass = match($r['priority']) {
                            'critical' => 'bg-danger text-white',
                            'high' => 'bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25',
                            'medium' => 'bg-warning bg-opacity-15 text-warning border border-warning border-opacity-25',
                            'low' => 'bg-success bg-opacity-10 text-success border border-success border-opacity-25',
                            default => 'bg-secondary bg-opacity-10 text-secondary'
                        };

                        $badgeStatusClass = match($r['status']) {
                            'pending' => 'bg-danger text-white',
                            'reviewing' => 'bg-warning text-dark',
                            'resolved' => 'bg-success text-white',
                            'dismissed' => 'bg-secondary text-white',
                            default => 'bg-light text-dark'
                        };

                        $typeIcon = match($r['report_type']) {
                            'bug' => '🐛 Bug',
                            'feature_request' => '💡 Feature',
                            'usability' => '🎨 Usability',
                            'performance' => '⚡ Speed',
                            'general' => '💬 Feedback',
                            default => '💬 General'
                        };
                    ?>
                        <tr>
                            <td><span class="badge bg-light text-muted fw-mono">#<?php echo $r['id']; ?></span></td>
                            <td>
                                <div>
                                    <strong class="text-dark d-block"><?php echo e($r['user_name']); ?></strong>
                                    <span class="badge bg-primary bg-opacity-10 text-primary rounded-pill small" style="font-size: 0.68rem;">
                                        <?php echo e(userRoleLabel($r['user_role'])); ?>
                                    </span>
                                    <?php if (!empty($r['user_email'])): ?>
                                        <small class="text-muted d-block" style="font-size: 0.72rem;"><?php echo e($r['user_email']); ?></small>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border px-2 py-1"><?php echo $typeIcon; ?></span>
                            </td>
                            <td>
                                <span class="badge rounded-pill px-2 py-1 <?php echo $badgePriorityClass; ?>">
                                    <?php echo ucfirst($r['priority']); ?>
                                </span>
                            </td>
                            <td style="max-width: 320px;">
                                <strong class="text-dark d-block text-truncate"><?php echo e($r['subject']); ?></strong>
                                <small class="text-muted d-block text-truncate"><?php echo e($r['message']); ?></small>
                                <?php if (!empty($r['admin_response'])): ?>
                                    <small class="text-success d-block text-truncate mt-1"><i class="fa-solid fa-reply me-1"></i><?php echo e($r['admin_response']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge rounded-pill px-2 py-1 <?php echo $badgeStatusClass; ?>">
                                    <?php echo ucfirst($r['status']); ?>
                                </span>
                            </td>
                            <td>
                                <small class="text-muted d-block"><?php echo date('M d, Y', strtotime($r['created_at'])); ?></small>
                                <small class="text-muted" style="font-size: 0.7rem;"><?php echo date('H:i', strtotime($r['created_at'])); ?></small>
                            </td>
                            <td class="text-end">
                                <button type="button" 
                                        class="btn btn-sm btn-outline-primary rounded-pill px-3" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#viewReportModal<?php echo $r['id']; ?>">
                                    <i class="fa-solid fa-eye me-1"></i>Manage
                                </button>
                            </td>
                        </tr>

                        <!-- Modal for viewing & updating report -->
                        <div class="modal fade" id="viewReportModal<?php echo $r['id']; ?>" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog modal-dialog-centered modal-lg">
                                <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
                                    <div class="modal-header border-0 bg-light p-4 pb-3">
                                        <div>
                                            <span class="badge bg-primary bg-opacity-10 text-primary rounded-pill mb-1">Report #<?php echo $r['id']; ?></span>
                                            <h5 class="modal-title fw-bold text-dark mb-0"><?php echo e($r['subject']); ?></h5>
                                        </div>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>

                                    <form method="POST" class="p-4 pt-2">
                                        <input type="hidden" name="csrf_token" value="<?php echo csrfToken(); ?>">
                                        <input type="hidden" name="action" value="update_report">
                                        <input type="hidden" name="report_id" value="<?php echo $r['id']; ?>">

                                        <!-- Meta Pill -->
                                        <div class="row g-2 mb-3 p-3 bg-light rounded-3">
                                            <div class="col-sm-6">
                                                <small class="text-muted d-block">Submitted by:</small>
                                                <strong><?php echo e($r['user_name']); ?></strong> (<?php echo e(userRoleLabel($r['user_role'])); ?>)
                                                <?php if (!empty($r['user_email'])): ?>
                                                    <div><small class="text-primary"><?php echo e($r['user_email']); ?></small></div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="col-sm-6">
                                                <small class="text-muted d-block">Submitted At &amp; Location:</small>
                                                <div><?php echo date('M d, Y @ H:i:s', strtotime($r['created_at'])); ?></div>
                                                <?php if (!empty($r['page_url'])): ?>
                                                    <small class="text-muted text-truncate d-block" title="<?php echo e($r['page_url']); ?>">
                                                        URL: <a href="<?php echo e($r['page_url']); ?>" target="_blank" class="text-decoration-none"><?php echo e(basename($r['page_url'])); ?> <i class="fa-solid fa-arrow-up-right-from-square small"></i></a>
                                                    </small>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <!-- Full Message -->
                                        <div class="mb-3">
                                            <label class="form-label fw-bold text-dark small">Report Message / Concern Details:</label>
                                            <div class="p-3 bg-white border rounded-3" style="white-space: pre-wrap; font-size: 0.92rem;"><?php echo e($r['message']); ?></div>
                                        </div>

                                        <!-- Status & Resolution -->
                                        <div class="row g-3 mb-3">
                                            <div class="col-md-6">
                                                <label class="form-label fw-bold text-dark small">Update Status:</label>
                                                <select name="status" class="form-select rounded-3">
                                                    <option value="pending" <?php echo $r['status'] === 'pending' ? 'selected' : ''; ?>>🔴 Pending (Awaiting Action)</option>
                                                    <option value="reviewing" <?php echo $r['status'] === 'reviewing' ? 'selected' : ''; ?>>🟡 Under Review (Investigating)</option>
                                                    <option value="resolved" <?php echo $r['status'] === 'resolved' ? 'selected' : ''; ?>>🟢 Resolved (Fixed / Implemented)</option>
                                                    <option value="dismissed" <?php echo $r['status'] === 'dismissed' ? 'selected' : ''; ?>>⚪ Dismissed (Not Applicable)</option>
                                                </select>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label fw-bold text-dark small">Priority Level:</label>
                                                <div>
                                                    <span class="badge rounded-pill px-3 py-2 <?php echo $badgePriorityClass; ?>">
                                                        <?php echo strtoupper($r['priority']); ?> Priority
                                                    </span>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="mb-4">
                                            <label class="form-label fw-bold text-dark small">Super Admin Response / Resolution Notes:</label>
                                            <textarea name="admin_response" class="form-control rounded-3" rows="3" placeholder="e.g. Fixed table padding on mobile, or Scheduled for next release update..."><?php echo e($r['admin_response'] ?? ''); ?></textarea>
                                        </div>

                                        <div class="modal-footer border-0 p-0 d-flex justify-content-between align-items-center">
                                            <!-- Delete option -->
                                            <button type="submit" 
                                                    name="action" 
                                                    value="delete_report" 
                                                    class="btn btn-outline-danger btn-sm"
                                                    onclick="return confirm('Are you sure you want to permanently delete this report?');">
                                                <i class="fa-solid fa-trash me-1"></i>Delete
                                            </button>
                                            <div class="d-flex gap-2">
                                                <button type="button" class="btn btn-light rounded-3 px-3" data-bs-dismiss="modal">Close</button>
                                                <button type="submit" class="btn btn-primary rounded-3 px-4 shadow-sm">
                                                    <i class="fa-solid fa-floppy-disk me-1"></i>Save Changes
                                                </button>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
