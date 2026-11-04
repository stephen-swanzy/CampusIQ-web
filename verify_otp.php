<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/sms.php';

if (isLoggedIn()) {
    redirect('/index.php');
}

$db = getDb();
$error = '';
$success = '';
$token = trim($_GET['token'] ?? $_POST['token'] ?? '');

$record = null;
if ($token !== '') {
    $stmt = $db->prepare('SELECT * FROM password_resets WHERE token = :token AND used = 0 AND expires_at > NOW() ORDER BY id DESC LIMIT 1');
    $stmt->execute(['token' => $token]);
    $record = $stmt->fetch();
}

// Resend OTP action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'resend' && $record) {
    verifyCsrfToken();
    $newOtp = sprintf('%06d', random_int(100000, 999999));
    $expiresAt = date('Y-m-d H:i:s', time() + 900);

    $db->prepare('UPDATE password_resets SET otp_code = :otp, expires_at = :expires WHERE id = :id')
        ->execute(['otp' => $newOtp, 'expires' => $expiresAt, 'id' => $record['id']]);

    // Fetch user details
    $uStmt = $db->prepare('SELECT name, phone FROM users WHERE email = :email LIMIT 1');
    $uStmt->execute(['email' => $record['email']]);
    $u = $uStmt->fetch();
    $uName = $u['name'] ?? 'User';

    sendCampusEmail($record['email'], $uName, 'CampusIQ - New Password Reset Code: ' . $newOtp, buildOtpEmailHtml($uName, $newOtp, 15));
    if (!empty($u['phone'])) {
        sendCampusSms($u['phone'], "Your new CampusIQ password reset code is {$newOtp}. Valid for 15 minutes.");
    }

    $success = 'A fresh 6-digit code has been sent to your email.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $record) {
    verifyCsrfToken();
    $inputOtp = preg_replace('/\s+/', '', $_POST['code'] ?? '');

    if (!preg_match('/^\d{6}$/', $inputOtp)) {
        $error = 'Please enter the 6-digit code sent to your email.';
    } elseif ($inputOtp !== (string) $record['otp_code']) {
        $error = 'Invalid verification code. Please check your Gmail or SMS and try again.';
    } else {
        // Mark session as OTP verified
        $_SESSION['otp_verified_token'] = $token;
        $_SESSION['otp_verified_email'] = $record['email'];
        $_SESSION['otp_verified_at'] = time();

        redirect('/reset_password.php?token=' . urlencode($token));
    }
}

// Helper to mask email (e.g. swanzyak47@gmail.com -> s***7@gmail.com)
function maskEmail(string $email): string
{
    $parts = explode('@', $email);
    if (count($parts) !== 2) return $email;
    $name = $parts[0];
    $len = strlen($name);
    if ($len <= 2) {
        $masked = $name[0] . '***';
    } else {
        $masked = $name[0] . str_repeat('*', max(3, $len - 2)) . $name[$len - 1];
    }
    return $masked . '@' . $parts[1];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Code — CampusIQ</title>
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
                    <i class="fa-solid fa-envelope-circle-check"></i>
                </div>
                <div class="auth-motto-tag">
                    <i class="fa-solid fa-shield-halved"></i> Two-Way Verification
                </div>
                <h1>Security Check</h1>
                <p>Confirming your one-time code guarantees that only you can authorize sensitive operations on your account.</p>
                <ul class="auth-features-list mb-4">
                    <li>
                        <span class="feat-icon"><i class="fa-solid fa-envelope"></i></span>
                        <span>Delivered directly to your Gmail inbox</span>
                    </li>
                    <li>
                        <span class="feat-icon"><i class="fa-solid fa-stopwatch"></i></span>
                        <span>15-minute active verification window</span>
                    </li>
                    <li>
                        <span class="feat-icon"><i class="fa-solid fa-user-lock"></i></span>
                        <span>Single-use cryptographic protection</span>
                    </li>
                </ul>
            </div>
            <div class="auth-visual-footer">
                <span class="system-status-indicator">Encrypted Session</span>
                <span>CampusIQ Security</span>
            </div>
        </div>

        <div class="auth-card">
            <div class="text-center mb-4">
                <div class="brand-mark mx-auto mb-3" style="width:66px;height:66px;font-size:1.8rem;">
                    <i class="fa-solid fa-shield-halved"></i>
                </div>
                <p class="auth-kicker">Security Verification</p>
                <h3 class="fw-bold mb-1">Enter OTP Code</h3>
                <?php if ($record): ?>
                    <p class="text-muted mb-0">Sent to <strong><?php echo e(maskEmail($record['email'])); ?></strong></p>
                <?php endif; ?>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo e($error); ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo e($success); ?></div>
            <?php endif; ?>

            <?php
            $mailCfg = getMailConfig();
            $hasSmtp = !empty($mailCfg['smtp']['username']) && !empty($mailCfg['smtp']['password']);
            ?>
            <?php if (!$hasSmtp): ?>
                <div class="alert alert-warning py-2 small mb-3">
                    <i class="fa-solid fa-triangle-exclamation me-1"></i>
                    <strong>Real delivery setup:</strong> Gmail requires an App Password to deliver emails from local servers. Add your credentials to <code>config/mail.php</code>.
                </div>
            <?php endif; ?>

            <?php if (!$record): ?>
                <div class="alert alert-warning">
                    <strong>Link Expired or Invalid</strong>
                    <p class="mb-0 mt-1 small">This verification link has expired or has already been used.</p>
                </div>
                <div class="text-center mt-3">
                    <a href="<?php echo baseUrl(); ?>/forgot_password.php" class="btn btn-primary w-100 py-2">
                        Request a New Code
                    </a>
                </div>
            <?php else: ?>
                <form method="POST" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo e(csrfToken()); ?>">
                    <input type="hidden" name="token" value="<?php echo e($token); ?>">

                    <div class="auth-field mb-4">
                        <label class="form-label" for="otp-code">6-Digit Code</label>
                        <div class="input-with-icon">
                            <i class="fa-solid fa-key"></i>
                            <input id="otp-code" type="text" class="form-control text-center fw-bold fs-4" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required autofocus placeholder="------" style="letter-spacing: 6px;">
                        </div>
                        <small class="text-muted">Check your Gmail inbox and spam/junk folder.</small>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 py-2">
                        <i class="fa-solid fa-check me-2"></i>Verify Code & Continue
                    </button>
                </form>

                <div class="mt-4 text-center">
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?php echo e(csrfToken()); ?>">
                        <input type="hidden" name="token" value="<?php echo e($token); ?>">
                        <input type="hidden" name="action" value="resend">
                        <button type="submit" class="btn btn-link text-decoration-none small text-muted p-0">
                            <i class="fa-solid fa-rotate-right me-1"></i> Didn't get the code? Resend OTP
                        </button>
                    </form>
                </div>
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
