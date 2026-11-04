<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';

requireLogin(['super_admin','school_admin']);

$db = getDb();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_id'], $_POST['decision'])) {
    verifyCsrfToken();
    $requestId = (int) $_POST['request_id'];
    $decision = $_POST['decision'];
    $reviewedBy = (int) ($_SESSION['campusiq_user']['id'] ?? 0);

    if ($decision === 'approved' || $decision === 'resend') {
        $req = $db->prepare('SELECT * FROM access_requests WHERE id = :id LIMIT 1');
        $req->execute(['id' => $requestId]);
        $request = $req->fetch();

        if ($request) {
            $email = trim($request['email']);
            $name = trim($request['name']);
            $role = $request['role'];
            $phone = trim($request['phone'] ?? '');

            // 1. Mark request as approved
            $stmt = $db->prepare('UPDATE access_requests SET status = "approved", reviewed_by = :reviewed_by, reviewed_at = NOW() WHERE id = :id');
            $stmt->execute([
                'reviewed_by' => $reviewedBy,
                'id' => $requestId,
            ]);

            // 2. Determine password hash: use applicant's chosen password if supplied, else fallback
            $hasPrechosenPassword = !empty($request['password_hash']);
            $userPasswordHash = $hasPrechosenPassword
                ? $request['password_hash']
                : campusPasswordHash('School@123');

            // 3. Create or activate account in users table
            $check = $db->prepare('SELECT id, status FROM users WHERE email = :email LIMIT 1');
            $check->execute(['email' => $email]);
            $existingUser = $check->fetch();

            $isRealEmail = !str_ends_with(strtolower($email), '@campusiq.edu.gh');
            $totpFlag = $isRealEmail ? 1 : 0;

            if ($existingUser) {
                $userId = (int) $existingUser['id'];
                $userStmt = $db->prepare('UPDATE users SET name = :name, password_hash = :password_hash, role = :role, status = "active", phone = COALESCE(NULLIF(phone, ""), :phone), totp_enabled = :totp WHERE id = :id');
                $userStmt->execute([
                    'name' => $name,
                    'password_hash' => $userPasswordHash,
                    'role' => $role,
                    'phone' => $phone !== '' ? $phone : null,
                    'totp' => $totpFlag,
                    'id' => $userId,
                ]);
            } else {
                $userStmt = $db->prepare('INSERT INTO users (name, email, password_hash, role, status, phone, totp_enabled) VALUES (:name, :email, :password_hash, :role, "active", :phone, :totp)');
                $userStmt->execute([
                    'name' => $name,
                    'email' => $email,
                    'password_hash' => $userPasswordHash,
                    'role' => $role,
                    'phone' => $phone !== '' ? $phone : null,
                    'totp' => $totpFlag,
                ]);
                $userId = (int) $db->lastInsertId();
            }

            // 4. Ensure linked role record exists (teacher, student, parent)
            if ($role === 'teacher') {
                $tchCheck = $db->prepare('SELECT id FROM teachers WHERE user_id = :uid LIMIT 1');
                $tchCheck->execute(['uid' => $userId]);
                if (!$tchCheck->fetch()) {
                    $teacherId = 'TCH-' . str_pad((string) $userId, 3, '0', STR_PAD_LEFT);
                    $db->prepare('INSERT INTO teachers (user_id, teacher_id, designation, status) VALUES (:uid, :tid, "Teacher", "active")')
                        ->execute(['uid' => $userId, 'tid' => $teacherId]);
                }
            } elseif ($role === 'student') {
                $stuCheck = $db->prepare('SELECT id FROM students WHERE user_id = :uid LIMIT 1');
                $stuCheck->execute(['uid' => $userId]);
                if (!$stuCheck->fetch()) {
                    $studentId = 'STU-' . str_pad((string) $userId, 3, '0', STR_PAD_LEFT);
                    $classId = null;
                    if (!empty($request['class_name'])) {
                        $clsStmt = $db->prepare('SELECT id FROM classes WHERE name LIKE :cname LIMIT 1');
                        $clsStmt->execute(['cname' => '%' . trim($request['class_name']) . '%']);
                        $cls = $clsStmt->fetch();
                        if ($cls) {
                            $classId = (int) $cls['id'];
                        }
                    }
                    $db->prepare('INSERT INTO students (user_id, student_id, class_id, status) VALUES (:uid, :sid, :cid, "active")')
                        ->execute(['uid' => $userId, 'sid' => $studentId, 'cid' => $classId]);
                }
            } elseif ($role === 'parent') {
                $parCheck = $db->prepare('SELECT id FROM parents WHERE user_id = :uid LIMIT 1');
                $parCheck->execute(['uid' => $userId]);
                if (!$parCheck->fetch()) {
                    $db->prepare('INSERT INTO parents (user_id, relation, status) VALUES (:uid, "Parent", "active")')
                        ->execute(['uid' => $userId]);
                }
            }

            // 5. Invalidate older unused tokens and generate a backup activation token
            $db->prepare('UPDATE account_activations SET used = 1 WHERE (request_id = :req_id OR email = :email) AND used = 0')
                ->execute(['req_id' => $requestId, 'email' => $email]);

            $token = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', time() + (72 * 3600));

            $actStmt = $db->prepare('INSERT INTO account_activations (request_id, email, token, expires_at, used) VALUES (:request_id, :email, :token, :expires_at, 0)');
            $actStmt->execute([
                'request_id' => $requestId,
                'email' => $email,
                'token' => $token,
                'expires_at' => $expiresAt,
            ]);

            // 6. Build URLs
            $loginUrl = absoluteUrl('/login.php');
            $activationUrl = absoluteUrl('/set_password.php?token=' . urlencode($token));

            // 7. Send notification email
            $school = getSchoolSettings();
            $schoolName = $school['school_name'] ?? 'CampusIQ';

            if ($hasPrechosenPassword) {
                // User already has their password: send direct sign-in approval notification
                $subject = "Access Request Approved — {$schoolName}";
                $htmlBody = buildAccountApprovedDirectLoginEmailHtml($name, $role, $email, $loginUrl, $schoolName);
                $textBody = buildAccountApprovedDirectLoginEmailText($name, $role, $email, $loginUrl, $schoolName);
            } else {
                // Fallback for legacy requests without a pre-chosen password: send setup link
                $subject = "Access Approved: Set Your Password — {$schoolName}";
                $htmlBody = buildAccountApprovedEmailHtml($name, $role, $activationUrl, $schoolName, 72);
                $textBody = buildAccountApprovedEmailText($name, $role, $activationUrl, $schoolName, 72);
            }

            $mailRes = sendCampusEmail($email, $name, $subject, $htmlBody, $textBody);

            if ($mailRes['success']) {
                $success = ($decision === 'resend')
                    ? 'Notification email resent successfully to ' . e($email) . '.'
                    : 'Access request approved! User account activated and notification email sent to ' . e($email) . '.';
            } else {
                $mailErr = !empty($mailRes['error']) ? ' (' . $mailRes['error'] . ')' : '';
                $success = 'Access request approved and user account activated. Note: Email delivery returned' . e($mailErr) . '. User can log in directly at <a href="' . e($loginUrl) . '" target="_blank" class="fw-bold">' . e($loginUrl) . '</a>';
            }
        }
    } elseif ($decision === 'rejected') {
        $stmt = $db->prepare('UPDATE access_requests SET status = "rejected", reviewed_by = :reviewed_by, reviewed_at = NOW() WHERE id = :id');
        $stmt->execute([
            'reviewed_by' => $reviewedBy,
            'id' => $requestId,
        ]);
        $success = 'Access request rejected successfully.';
    }
}

$requests = $db->query('SELECT r.*, u.name as reviewer_name FROM access_requests r LEFT JOIN users u ON u.id = r.reviewed_by ORDER BY r.created_at DESC')->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <p class="text-primary fw-semibold mb-1">Security</p>
        <h2 class="fw-bold mb-0">Access Requests</h2>
    </div>
</div>

<?php if (!empty($success)): ?>
    <div class="alert alert-success d-flex align-items-center gap-2">
        <i class="fa-solid fa-circle-check fs-5"></i>
        <div><?php echo $success; ?></div>
    </div>
<?php endif; ?>

<div class="card p-4">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th>Submitted</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($requests)): ?>
                    <tr>
                        <td colspan="7">
                            <div class="empty-state">
                                <i class="fa-solid fa-user-shield fa-2x mb-3 d-block"></i>
                                No access requests found.
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($requests as $request): ?>
                        <tr>
                            <td>
                                <strong><?php echo e($request['name']); ?></strong><br>
                                <small class="text-muted"><?php echo e($request['student_name'] ?? ''); ?></small>
                            </td>
                            <td><?php echo e($request['email']); ?></td>
                            <td><?php echo e(userRoleLabel($request['role'])); ?></td>
                            <td><?php echo e($request['request_type']); ?></td>
                            <td>
                                <span class="badge-soft <?php echo $request['status'] === 'approved' ? 'badge-success' : ($request['status'] === 'rejected' ? 'badge-danger' : 'badge-warning'); ?>"><?php echo e($request['status']); ?></span>
                            </td>
                            <td><?php echo e(date('d M Y', strtotime($request['created_at']))); ?></td>
                            <td>
                                <?php if ($request['status'] === 'pending'): ?>
                                    <form method="POST" class="d-flex gap-2 align-items-center" style="margin:0;">
                                        <input type="hidden" name="csrf_token" value="<?php echo csrfToken(); ?>">
                                        <input type="hidden" name="request_id" value="<?php echo (int) $request['id']; ?>">
                                        <button type="submit" name="decision" value="approved" class="btn btn-sm btn-success">
                                            <i class="fa-solid fa-check me-1"></i>Approve
                                        </button>
                                        <button type="submit" name="decision" value="rejected" class="btn btn-sm btn-outline-danger">
                                            <i class="fa-solid fa-xmark me-1"></i>Reject
                                        </button>
                                    </form>
                                <?php elseif ($request['status'] === 'approved'): ?>
                                    <div class="d-flex align-items-center gap-2">
                                        <form method="POST" style="margin:0;">
                                            <input type="hidden" name="csrf_token" value="<?php echo csrfToken(); ?>">
                                            <input type="hidden" name="request_id" value="<?php echo (int) $request['id']; ?>">
                                            <button type="submit" name="decision" value="resend" class="btn btn-sm btn-outline-primary" title="Resend password setup email">
                                                <i class="fa-solid fa-paper-plane me-1"></i>Resend Link
                                            </button>
                                        </form>
                                        <small class="text-muted"><?php echo e($request['reviewer_name'] ?? 'Admin'); ?></small>
                                    </div>
                                <?php else: ?>
                                    <small class="text-muted"><?php echo e($request['reviewer_name'] ?? 'System'); ?></small>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
