<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/security.php';

if (isLoggedIn()) {
    redirect('/index.php');
}

$db = getDb();
$school = getSchoolSettings();
$error = '';
$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$validToken = false;
$activationRecord = null;
$requestRecord = null;

if ($token !== '') {
    $stmt = $db->prepare('SELECT * FROM account_activations WHERE token = :token ORDER BY id DESC LIMIT 1');
    $stmt->execute(['token' => $token]);
    $activationRecord = $stmt->fetch();

    if ($activationRecord) {
        if ((int) $activationRecord['used'] === 1) {
            $error = 'This activation link has already been used. If you have already chosen your password, please sign in below.';
        } elseif (strtotime($activationRecord['expires_at']) < time()) {
            $error = 'This activation link has expired (links remain active for 72 hours). Please contact your school administrator or request a fresh link.';
        } else {
            $validToken = true;

            // Fetch request details
            $reqStmt = $db->prepare('SELECT * FROM access_requests WHERE id = :id LIMIT 1');
            $reqStmt->execute(['id' => $activationRecord['request_id']]);
            $requestRecord = $reqStmt->fetch();
        }
    } else {
        $error = 'Invalid activation link. Please ensure you copied or clicked the full link from your approval email.';
    }
} else {
    $error = 'No activation token was provided. Please use the activation link from your approval email.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken();

    if (!$validToken) {
        // error already set above
    } else {
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters long.';
        } elseif (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            $error = 'Password must include both letters and numbers for your security.';
        } elseif ($password !== $confirmPassword) {
            $error = 'The passwords do not match. Please re-enter them carefully.';
        } else {
            $email = trim($activationRecord['email']);
            $name = trim($requestRecord['name'] ?? $email);
            $role = $requestRecord['role'] ?? 'parent';
            $phone = trim($requestRecord['phone'] ?? '');

            $hashedPassword = campusPasswordHash($password);

            // Check if user exists in users table
            $userStmt = $db->prepare('SELECT id, status FROM users WHERE email = :email LIMIT 1');
            $userStmt->execute(['email' => $email]);
            $existingUser = $userStmt->fetch();

            if ($existingUser) {
                $userId = (int) $existingUser['id'];
                $updateStmt = $db->prepare('UPDATE users SET password_hash = :hash, status = "active", phone = COALESCE(NULLIF(phone, ""), :phone), totp_failed_attempts = 0, totp_locked_until = NULL WHERE id = :id');
                $updateStmt->execute([
                    'hash' => $hashedPassword,
                    'phone' => $phone !== '' ? $phone : null,
                    'id' => $userId,
                ]);
            } else {
                $insertStmt = $db->prepare('INSERT INTO users (name, email, password_hash, role, status, phone) VALUES (:name, :email, :hash, :role, "active", :phone)');
                $insertStmt->execute([
                    'name' => $name,
                    'email' => $email,
                    'hash' => $hashedPassword,
                    'role' => $role,
                    'phone' => $phone !== '' ? $phone : null,
                ]);
                $userId = (int) $db->lastInsertId();
            }

            // Ensure role profile record exists
            if ($role === 'teacher') {
                $tchStmt = $db->prepare('SELECT id FROM teachers WHERE user_id = :uid LIMIT 1');
                $tchStmt->execute(['uid' => $userId]);
                if (!$tchStmt->fetch()) {
                    $teacherId = 'TCH-' . str_pad((string) $userId, 3, '0', STR_PAD_LEFT);
                    $insTch = $db->prepare('INSERT INTO teachers (user_id, teacher_id, designation, status) VALUES (:uid, :tid, "Teacher", "active")');
                    $insTch->execute(['uid' => $userId, 'tid' => $teacherId]);
                }
            } elseif ($role === 'student') {
                $stuStmt = $db->prepare('SELECT id FROM students WHERE user_id = :uid LIMIT 1');
                $stuStmt->execute(['uid' => $userId]);
                if (!$stuStmt->fetch()) {
                    $studentId = 'STU-' . str_pad((string) $userId, 3, '0', STR_PAD_LEFT);
                    $classId = null;
                    if (!empty($requestRecord['class_name'])) {
                        $clsStmt = $db->prepare('SELECT id FROM classes WHERE name LIKE :cname LIMIT 1');
                        $clsStmt->execute(['cname' => '%' . trim($requestRecord['class_name']) . '%']);
                        $cls = $clsStmt->fetch();
                        if ($cls) {
                            $classId = (int) $cls['id'];
                        }
                    }
                    $insStu = $db->prepare('INSERT INTO students (user_id, student_id, class_id, status) VALUES (:uid, :sid, :cid, "active")');
                    $insStu->execute(['uid' => $userId, 'sid' => $studentId, 'cid' => $classId]);
                }
            } elseif ($role === 'parent') {
                $parStmt = $db->prepare('SELECT id FROM parents WHERE user_id = :uid LIMIT 1');
                $parStmt->execute(['uid' => $userId]);
                if (!$parStmt->fetch()) {
                    $insPar = $db->prepare('INSERT INTO parents (user_id, relation, status) VALUES (:uid, "Parent", "active")');
                    $insPar->execute(['uid' => $userId]);
                }
            }

            // Mark activation token as used
            $db->prepare('UPDATE account_activations SET used = 1 WHERE id = :id')
                ->execute(['id' => $activationRecord['id']]);

            // Update access request status
            if ($requestRecord) {
                $db->prepare('UPDATE access_requests SET status = "approved", password_hash = :hash WHERE id = :id')
                    ->execute(['hash' => $hashedPassword, 'id' => $requestRecord['id']]);
            }

            // Log activity
            $db->prepare('INSERT INTO activity_logs (user_id, action, details) VALUES (:uid, "Account Activated", :details)')
                ->execute([
                    'uid' => $userId,
                    'details' => "Account activated and personal password set by {$email}",
                ]);

            // Redirect to login page with activation notice
            redirect('/login.php?activated=1');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set Your Password — <?php echo e($school['school_name'] ?? 'CampusIQ'); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="<?php echo baseUrl(); ?>/assets/css/style.css">
</head>
<body class="auth-page">
    <div class="auth-shell-centered">
        <!-- School Institutional Header -->
        <div class="school-header-centered">
            <div class="school-crest-centered">
                <?php if (!empty($school['school_logo']) && file_exists(__DIR__ . '/' . $school['school_logo'])): ?>
                    <img src="<?php echo baseUrl() . '/' . e($school['school_logo']); ?>" alt="<?php echo e($school['school_name']); ?>" class="school-logo-img">
                <?php else: ?>
                    <i class="fa-solid fa-graduation-cap"></i>
                <?php endif; ?>
            </div>
            <h2 class="school-name-centered"><?php echo e($school['school_name'] ?? 'ADONAI INTERNATIONAL SCHOOL'); ?></h2>
            <p class="school-motto-centered"><?php echo e($school['motto'] ?? 'Excellence Through Education'); ?></p>
        </div>

        <!-- Greeting -->
        <div class="auth-greeting text-center">
            <h3 class="auth-heading">Account Activation</h3>
            <p class="auth-subheading">Choose your personal password to access your portal</p>
        </div>

        <!-- Alert messages -->
        <?php if ($error): ?>
            <div class="auth-alert alert-danger" role="alert">
                <i class="fa-solid fa-circle-exclamation alert-icon"></i>
                <div class="alert-text"><?php echo e($error); ?></div>
            </div>
        <?php endif; ?>

        <?php if ($validToken): ?>
            <!-- User Summary Pill -->
            <div class="card p-3 mb-3 border-0" style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px;">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <div class="fw-bold text-dark" style="font-size: 0.95rem;"><?php echo e($requestRecord['name'] ?? $activationRecord['email']); ?></div>
                        <div class="text-muted small"><?php echo e($activationRecord['email']); ?></div>
                    </div>
                    <div>
                        <span class="badge bg-primary text-uppercase" style="font-size: 0.75rem; letter-spacing: 0.5px; padding: 6px 12px;">
                            <?php echo e(ucwords(str_replace('_', ' ', $requestRecord['role'] ?? 'User'))); ?>
                        </span>
                    </div>
                </div>
            </div>

            <form method="POST" novalidate id="setPasswordForm" class="auth-form-content">
                <input type="hidden" name="csrf_token" value="<?php echo e(csrfToken()); ?>">
                <input type="hidden" name="token" value="<?php echo e($token); ?>">

                <!-- Password Field -->
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="newPassword">New Password</label>
                    <div class="password-wrapper-clean">
                        <input 
                            id="newPassword" 
                            type="password" 
                            class="form-control auth-input-clean" 
                            name="password" 
                            required 
                            placeholder="At least 8 characters (letters & numbers)" 
                            autocomplete="new-password"
                            autofocus
                        >
                        <button type="button" class="password-toggle-clean" id="pwdToggle1" aria-label="Toggle password visibility" tabindex="-1">
                            <i class="fa-regular fa-eye" id="toggleIcon1"></i>
                        </button>
                    </div>
                </div>

                <!-- Confirm Password Field -->
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="confirmPassword">Confirm Password</label>
                    <div class="password-wrapper-clean">
                        <input 
                            id="confirmPassword" 
                            type="password" 
                            class="form-control auth-input-clean" 
                            name="confirm_password" 
                            required 
                            placeholder="Re-enter your password" 
                            autocomplete="new-password"
                        >
                        <button type="button" class="password-toggle-clean" id="pwdToggle2" aria-label="Toggle password visibility" tabindex="-1">
                            <i class="fa-regular fa-eye" id="toggleIcon2"></i>
                        </button>
                    </div>
                    <div class="form-text small text-muted mt-1">
                        <i class="fa-solid fa-shield-halved me-1 text-primary"></i> Must be at least 8 characters long and contain both letters and numbers.
                    </div>
                </div>

                <!-- Submit Button -->
                <button type="submit" class="btn btn-primary w-100 btn-signin-clean mt-2" id="submitBtn">
                    <span class="btn-text">Activate &amp; Save Password</span>
                </button>
            </form>
        <?php else: ?>
            <div class="text-center mt-3 mb-2">
                <a href="<?php echo baseUrl(); ?>/login.php" class="btn btn-primary w-100 btn-signin-clean py-2">
                    <i class="fa-solid fa-arrow-right-to-bracket me-2"></i>Go to Sign In
                </a>
                <div class="mt-3">
                    <a href="<?php echo baseUrl(); ?>/request_access.php" class="auth-link-subtle small">
                        Need to submit a new access request?
                    </a>
                </div>
            </div>
        <?php endif; ?>

        <!-- Footer Navigation -->
        <div class="auth-footer-clean text-center mt-4 pt-3 border-top">
            <span class="text-muted small">Already set up?</span>
            <a href="<?php echo baseUrl(); ?>/login.php" class="auth-link fw-semibold ms-1 small">
                Sign In &rarr;
            </a>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Password Toggle 1
            const p1 = document.getElementById('newPassword');
            const btn1 = document.getElementById('pwdToggle1');
            const icon1 = document.getElementById('toggleIcon1');
            if (btn1 && p1 && icon1) {
                btn1.addEventListener('click', function(e) {
                    e.preventDefault();
                    if (p1.type === 'password') {
                        p1.type = 'text';
                        icon1.classList.remove('fa-eye');
                        icon1.classList.add('fa-eye-slash');
                    } else {
                        p1.type = 'password';
                        icon1.classList.remove('fa-eye-slash');
                        icon1.classList.add('fa-eye');
                    }
                });
            }

            // Password Toggle 2
            const p2 = document.getElementById('confirmPassword');
            const btn2 = document.getElementById('pwdToggle2');
            const icon2 = document.getElementById('toggleIcon2');
            if (btn2 && p2 && icon2) {
                btn2.addEventListener('click', function(e) {
                    e.preventDefault();
                    if (p2.type === 'password') {
                        p2.type = 'text';
                        icon2.classList.remove('fa-eye');
                        icon2.classList.add('fa-eye-slash');
                    } else {
                        p2.type = 'password';
                        icon2.classList.remove('fa-eye-slash');
                        icon2.classList.add('fa-eye');
                    }
                });
            }
        });
    </script>
</body>
</html>
