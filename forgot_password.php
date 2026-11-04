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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken();
    $email = trim($_POST['email'] ?? '');

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $stmt = $db->prepare('SELECT id, name, email, phone, status FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if ($user && $user['status'] === 'active') {
            // Generate 6-digit numeric OTP and secure session token
            $otp = sprintf('%06d', random_int(100000, 999999));
            $token = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', time() + 900); // 15 minutes validity

            // Invalidate older unused tokens for this email
            $db->prepare('UPDATE password_resets SET used = 1 WHERE email = :email AND used = 0')
                ->execute(['email' => $email]);

            // Save new reset record with OTP
            $insert = $db->prepare('INSERT INTO password_resets (email, token, otp_code, expires_at) VALUES (:email, :token, :otp_code, :expires_at)');
            $insert->execute([
                'email' => $email,
                'token' => $token,
                'otp_code' => $otp,
                'expires_at' => $expiresAt,
            ]);

            // 1. Dispatch real Email via SMTP
            $emailBody = buildOtpEmailHtml($user['name'], $otp, 15);
            $emailResult = sendCampusEmail(
                $user['email'],
                $user['name'],
                'CampusIQ - Your Password Reset Code is ' . $otp,
                $emailBody
            );

            // 2. Dispatch real SMS if phone exists
            if (!empty($user['phone'])) {
                sendCampusSms(
                    $user['phone'],
                    "Your CampusIQ password reset code is {$otp}. Valid for 15 minutes."
                );
            }

            // Log activity
            $db->prepare('INSERT INTO activity_logs (user_id, action, details) VALUES (:user_id, :action, :details)')
                ->execute([
                    'user_id' => $user['id'],
                    'action' => 'OTP Requested',
                    'details' => 'Password reset OTP requested. Dispatch status: ' . ($emailResult['success'] ? 'Sent' : 'Fallback: ' . $emailResult['error']),
                ]);

            // Redirect to OTP verification screen
            redirect('/verify_otp.php?token=' . urlencode($token));
        } else {
            // Even if user not found, simulate delay and redirect or message
            $error = 'No active account found with that email address.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Recovery — CampusIQ</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700;800&family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="<?php echo baseUrl(); ?>/assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/assets/css/style.css'); ?>">
</head>
<body class="auth-page">
    <div class="auth-shell">
        <div class="auth-visual">
            <div class="auth-visual-top">
                <div class="auth-seal">
                    <i class="fa-solid fa-key"></i>
                </div>
                <h1>Reset Password</h1>
                <p>Verify your email address to securely restore access to your CampusIQ account.</p>
                <ul class="auth-features-list mb-4">
                    <li>
                        <span class="feat-icon"><i class="fa-solid fa-envelope"></i></span>
                        <span>6-digit verification code sent to your Gmail</span>
                    </li>
                    <li>
                        <span class="feat-icon"><i class="fa-solid fa-clock"></i></span>
                        <span>15-minute verification window</span>
                    </li>
                    <li>
                        <span class="feat-icon"><i class="fa-solid fa-shield-halved"></i></span>
                        <span>Safe and instant password recovery</span>
                    </li>
                </ul>
            </div>
            <div class="auth-visual-footer">
                <span class="system-status-indicator">System Online</span>
                <span>CampusIQ Support</span>
            </div>
        </div>

        <div class="auth-card">
            <div class="text-center mb-4">
                <div class="brand-mark mx-auto mb-3" style="width:66px;height:66px;font-size:1.8rem;">
                    <i class="fa-solid fa-unlock-keyhole"></i>
                </div>
                <p class="auth-kicker">Account Recovery</p>
                <h3 class="fw-bold mb-1">Forgot Password</h3>
                <p class="text-muted mb-0">Enter your registered email address to receive an OTP code</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo e($error); ?></div>
            <?php endif; ?>

            <form method="POST" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo e(csrfToken()); ?>">

                <div class="auth-field mb-3">
                    <label class="form-label">Email Address</label>
                    <div class="input-with-icon">
                        <i class="fa-solid fa-envelope"></i>
                        <input type="email" class="form-control" name="email" required placeholder="name@campusiq.edu.gh" value="<?php echo e($_POST['email'] ?? ''); ?>" autofocus>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary w-100 py-2">
                    <i class="fa-solid fa-paper-plane me-2"></i>Send Verification Code
                </button>
            </form>

            <div class="mt-4 pt-3 text-center border-top">
                <p class="text-muted small mb-0">
                    Remember your password? <a href="<?php echo baseUrl(); ?>/login.php" class="auth-link fw-semibold">Sign in here</a>
                </p>
            </div>
        </div>
    </div>
</body>
</html>
