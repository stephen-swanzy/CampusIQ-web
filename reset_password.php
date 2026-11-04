<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/security.php';

if (isLoggedIn()) {
    redirect('/index.php');
}

$db = getDb();
$error = '';
$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$validToken = false;
$resetRecord = null;

if ($token !== '') {
    $stmt = $db->prepare('SELECT * FROM password_resets WHERE token = :token AND used = 0 AND expires_at > NOW() ORDER BY id DESC LIMIT 1');
    $stmt->execute(['token' => $token]);
    $resetRecord = $stmt->fetch();

    // Verify user went through OTP step
    $verifiedToken = $_SESSION['otp_verified_token'] ?? '';
    if ($resetRecord && $verifiedToken === $token) {
        $validToken = true;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken();

    if (!$validToken) {
        $error = 'Your verification session is invalid or has expired. Please verify your OTP code again.';
    } else {
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (strlen($password) < 6) {
            $error = 'Password must be at least 6 characters long.';
        } elseif ($password !== $confirmPassword) {
            $error = 'Passwords do not match.';
        } else {
            // Find active user
            $userStmt = $db->prepare('SELECT id, name, email FROM users WHERE email = :email AND status = "active" LIMIT 1');
            $userStmt->execute(['email' => $resetRecord['email']]);
            $user = $userStmt->fetch();

            if (!$user) {
                $error = 'User account not found or is currently inactive.';
            } else {
                // Compute new peppered hash
                $newHash = campusPasswordHash($password);

                // Update password and clear any lockout
                $update = $db->prepare('UPDATE users SET password_hash = :hash, totp_failed_attempts = 0, totp_locked_until = NULL WHERE id = :id');
                $update->execute([
                    'hash' => $newHash,
                    'id' => $user['id'],
                ]);

                // Mark reset token as used
                $db->prepare('UPDATE password_resets SET used = 1 WHERE id = :id')
                    ->execute(['id' => $resetRecord['id']]);

                // Clear OTP session token
                unset($_SESSION['otp_verified_token'], $_SESSION['otp_verified_email'], $_SESSION['otp_verified_at']);

                // Log the activity
                $db->prepare('INSERT INTO activity_logs (user_id, action, details) VALUES (:user_id, :action, :details)')
                    ->execute([
                        'user_id' => $user['id'],
                        'action' => 'Password Reset Completed',
                        'details' => 'User successfully updated password using verified OTP',
                    ]);

                // Redirect to login page with success notice
                redirect('/login.php?reset=1');
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set New Password — CampusIQ</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700;800&family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="<?php echo baseUrl(); ?>/assets/css/style.css">
</head>
<body class="auth-page">
    <div class="auth-shell">
        <div class="auth-visual">
            <div class="auth-visual-top">
                <div class="auth-seal">
                    <i class="fa-solid fa-lock-open"></i>
                </div>
                <h1>Set New Password</h1>
                <p>Choose a secure, easy-to-remember new password for your CampusIQ account.</p>
                <ul class="auth-features-list mb-4">
                    <li>
                        <span class="feat-icon"><i class="fa-solid fa-check"></i></span>
                        <span>Minimum 6 characters</span>
                    </li>
                    <li>
                        <span class="feat-icon"><i class="fa-solid fa-lock"></i></span>
                        <span>Confirm matching password</span>
                    </li>
                    <li>
                        <span class="feat-icon"><i class="fa-solid fa-arrow-right-to-bracket"></i></span>
                        <span>Instant access to your portal</span>
                    </li>
                </ul>
            </div>
            <div class="auth-visual-footer">
                <span class="system-status-indicator">System Online</span>
                <span>CampusIQ Security</span>
            </div>
        </div>

        <div class="auth-card">
            <div class="text-center mb-4">
                <div class="brand-mark mx-auto mb-3" style="width:66px;height:66px;font-size:1.8rem;">
                    <i class="fa-solid fa-key"></i>
                </div>
                <p class="auth-kicker">Account Security</p>
                <h3 class="fw-bold mb-1">New Password</h3>
                <p class="text-muted mb-0">Create your new password to regain access</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo e($error); ?></div>
            <?php endif; ?>

            <?php if (!$validToken): ?>
                <div class="alert alert-warning">
                    <strong>Verification Required</strong>
                    <p class="mb-0 mt-1 small">Please enter your 6-digit verification code first before resetting your password.</p>
                </div>
                <div class="text-center mt-3">
                    <?php if ($resetRecord): ?>
                        <a href="<?php echo baseUrl(); ?>/verify_otp.php?token=<?php echo urlencode($token); ?>" class="btn btn-primary w-100 py-2">
                            Enter Verification Code
                        </a>
                    <?php else: ?>
                        <a href="<?php echo baseUrl(); ?>/forgot_password.php" class="btn btn-primary w-100 py-2">
                            Request a New Code
                        </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <form method="POST" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo e(csrfToken()); ?>">
                    <input type="hidden" name="token" value="<?php echo e($token); ?>">

                    <div class="auth-field mb-3">
                        <label class="form-label">Email Account</label>
                        <div class="input-with-icon">
                            <i class="fa-solid fa-envelope"></i>
                            <input type="email" class="form-control" value="<?php echo e($resetRecord['email']); ?>" disabled readonly>
                        </div>
                    </div>

                    <div class="auth-field mb-3">
                        <label class="form-label">New Password</label>
                        <div class="input-with-icon">
                            <i class="fa-solid fa-lock"></i>
                            <input type="password" class="form-control" name="password" required placeholder="At least 6 characters" autofocus>
                        </div>
                    </div>

                    <div class="auth-field mb-4">
                        <label class="form-label">Confirm New Password</label>
                        <div class="input-with-icon">
                            <i class="fa-solid fa-shield"></i>
                            <input type="password" class="form-control" name="confirm_password" required placeholder="Repeat new password">
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 py-2">
                        <i class="fa-solid fa-floppy-disk me-2"></i>Save New Password
                    </button>
                </form>
            <?php endif; ?>

            <div class="mt-4 pt-3 text-center border-top">
                <p class="text-muted small mb-0">
                    <a href="<?php echo baseUrl(); ?>/login.php" class="auth-link fw-semibold">
                        <i class="fa-solid fa-arrow-left me-1"></i> Back to Sign In
                    </a>
                </p>
            </div>
        </div>
    </div>
</body>
</html>
