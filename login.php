<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/mailer.php';

if (isLoggedIn()) {
    redirect('/index.php');
}

$error = '';
$success = '';
$db = getDb();
$school = getSchoolSettings();
$mailConfig = getMailConfig();
$twoFaMode = $mailConfig['2fa_mode'] ?? 'email';
$hasSmtp = !empty($mailConfig['smtp']['username']) && !empty($mailConfig['smtp']['password']);

// Clean phone for WhatsApp desk link
$schoolPhone = !empty($school['phone']) ? $school['phone'] : '+233 302 000 000';
$cleanPhone = preg_replace('/[^0-9]/', '', $schoolPhone);
if (str_starts_with($cleanPhone, '0')) {
    $cleanPhone = '233' . substr($cleanPhone, 1);
}
if (empty($cleanPhone)) {
    $cleanPhone = '233302000000';
}
$whatsappUrl = "https://wa.me/{$cleanPhone}?text=" . urlencode("Hello, I need assistance logging into my CampusIQ account for " . ($school['school_name'] ?? 'the school') . ".");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken();

    // 0. Cancel 2FA action
    if (!empty($_SESSION['pending_2fa_user']) && isset($_POST['action']) && $_POST['action'] === 'cancel_2fa') {
        unset($_SESSION['pending_2fa_user'], $_SESSION['pending_2fa_at']);
        redirect('/login.php');
    }

    // 1. Resend Code action
    if (!empty($_SESSION['pending_2fa_user']) && isset($_POST['action']) && $_POST['action'] === 'resend_code') {
        $pending = $_SESSION['pending_2fa_user'];
        $targetEmail = $pending['email'] ?? $pending['user']['email'] ?? '';

        if (!empty($targetEmail)) {
            $otpCode = sprintf('%06d', random_int(100000, 999999));
            $expiresAt = date('Y-m-d H:i:s', time() + 900); // 15-minute validity

            $db->prepare('UPDATE users SET email_otp = :code, email_otp_expires_at = :expires WHERE id = :id')
                ->execute(['code' => $otpCode, 'expires' => $expiresAt, 'id' => $pending['user']['id']]);

            $_SESSION['pending_2fa_otp'] = $otpCode;

            $mailRes = sendCampusEmail(
                $targetEmail,
                $pending['user']['name'],
                'CampusIQ Sign-In Verification Code: ' . $otpCode,
                buildOtpEmailHtml($pending['user']['name'], $otpCode, 15, 'Sign-In Verification')
            );

            $_SESSION['pending_2fa_at'] = time();
            if ($mailRes['success']) {
                $success = 'A fresh 6-digit code has been sent to your Gmail (' . e($targetEmail) . ').';
            } else {
                $error = 'Could not send verification email: ' . ($mailRes['error'] ?: 'Please check your mail configuration.');
            }
        }
    }
    // 2. Verifying 2FA Code (Google Authenticator TOTP or Email OTP)
    elseif (!empty($_SESSION['pending_2fa_user'])) {
        $pending = $_SESSION['pending_2fa_user'];

        if (time() - (int) ($_SESSION['pending_2fa_at'] ?? 0) > 900) {
            unset($_SESSION['pending_2fa_user'], $_SESSION['pending_2fa_at'], $_SESSION['pending_2fa_otp']);
            $error = 'The verification step expired. Please sign in again.';
        } else {
            $attemptStmt = $db->prepare('SELECT email_otp, email_otp_expires_at, totp_failed_attempts, totp_locked_until, totp_secret FROM users WHERE id = :id');
            $attemptStmt->execute(['id' => $pending['user']['id']]);
            $attemptState = $attemptStmt->fetch();

            if (!$attemptState || (!empty($attemptState['totp_locked_until']) && strtotime($attemptState['totp_locked_until']) > time())) {
                unset($_SESSION['pending_2fa_user'], $_SESSION['pending_2fa_at'], $_SESSION['pending_2fa_otp']);
                $error = 'Too many verification attempts. Please wait five minutes and sign in again.';
            } else {
                $isVerified = false;
                // Clean input code: strip ALL non-digits (handles spaces, dashes, non-breaking spaces)
                $inputCode = preg_replace('/[^0-9]/', '', $_POST['code'] ?? '');
                $methodLabel = 'Two-Factor Authentication';

                // Check 1: Authenticator app TOTP code
                $secret = $pending['secret'] ?? null;
                if (!$secret && !empty($attemptState['totp_secret'])) {
                    try {
                        $secret = campusDecryptTotpSecret($attemptState['totp_secret']);
                    } catch (Throwable $t) {
                        $secret = null;
                    }
                }
                if ($secret && campusVerifyTotp($secret, $inputCode)) {
                    $isVerified = true;
                    $methodLabel = 'Google Authenticator';
                }

                // Check 2: Email OTP code (compare against DB and Session with grace window)
                if (!$isVerified && !empty($inputCode) && strlen($inputCode) === 6) {
                    $dbOtp = (string) ($attemptState['email_otp'] ?? '');
                    $sessionOtp = (string) ($_SESSION['pending_2fa_otp'] ?? '');
                    $isOtpMatch = ($dbOtp !== '' && $inputCode === $dbOtp) || ($sessionOtp !== '' && $inputCode === $sessionOtp);

                    if ($isOtpMatch) {
                        $expTimestamp = !empty($attemptState['email_otp_expires_at']) ? strtotime($attemptState['email_otp_expires_at']) : 0;
                        if ($expTimestamp === 0 || $expTimestamp >= (time() - 300)) {
                            $isVerified = true;
                            $methodLabel = 'Gmail OTP';
                        }
                    }
                }

                if ($isVerified) {
                    $db->prepare('UPDATE users SET email_otp = NULL, email_otp_expires_at = NULL, totp_failed_attempts = 0, totp_locked_until = NULL WHERE id = :id')
                        ->execute(['id' => $pending['user']['id']]);

                    unset($_SESSION['pending_2fa_otp']);
                    $sessionUser = $pending['user'];
                    loginUser($sessionUser);

                    $db->prepare('INSERT INTO activity_logs (user_id, action, details) VALUES (:user_id, :action, :details)')
                        ->execute([
                            'user_id' => $sessionUser['id'],
                            'action' => 'Login',
                            'details' => ucfirst($sessionUser['role']) . ' logged into CampusIQ via ' . $methodLabel,
                        ]);

                    $targetDashboard = match ($sessionUser['role'] ?? '') {
                        'super_admin', 'school_admin' => '/admin/index.php',
                        'teacher' => '/teacher/index.php',
                        'student' => '/student/index.php',
                        'parent' => '/parent/index.php',
                        'accountant' => '/accountant/index.php',
                        default => '/index.php',
                    };
                    redirect($targetDashboard);
                } else {
                    $failedAttempts = (int) ($attemptState['totp_failed_attempts'] ?? 0) + 1;
                    $lockedUntil = $failedAttempts >= 5 ? date('Y-m-d H:i:s', time() + 300) : null;
                    $db->prepare('UPDATE users SET totp_failed_attempts = :attempts, totp_locked_until = :locked_until WHERE id = :id')
                        ->execute([
                            'attempts' => $failedAttempts >= 5 ? 0 : $failedAttempts,
                            'locked_until' => $lockedUntil,
                            'id' => $pending['user']['id'],
                        ]);

                    if ($lockedUntil !== null) {
                        unset($_SESSION['pending_2fa_user'], $_SESSION['pending_2fa_at'], $_SESSION['pending_2fa_otp']);
                        $error = 'Too many verification attempts. Please wait five minutes and sign in again.';
                    } else {
                        $attemptsLeft = 5 - $failedAttempts;
                        $error = "Invalid verification code. Please check your Authenticator app or Gmail inbox. ({$attemptsLeft} attempt" . ($attemptsLeft === 1 ? '' : 's') . " left)";
                    }
                }
            }
        }
    }
    // 3. Initial Sign-In (Email or Username)
    else {
        $identity = trim($_POST['identity'] ?? $_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $selectedRole = trim($_POST['portal_role'] ?? 'all');

        if (empty($identity) || empty($password)) {
            $error = 'Please enter your email or username and password.';
        } else {
            $stmt = $db->prepare('
                SELECT u.* 
                FROM users u 
                LEFT JOIN students s ON s.user_id = u.id 
                LEFT JOIN teachers t ON t.user_id = u.id 
                WHERE u.email = :ident1 
                   OR u.name = :ident2 
                   OR u.phone = :ident3 
                   OR s.student_id = :ident4 
                   OR t.teacher_id = :ident5 
                LIMIT 1
            ');
            $stmt->execute([
                'ident1' => $identity,
                'ident2' => $identity,
                'ident3' => $identity,
                'ident4' => $identity,
                'ident5' => $identity,
            ]);
            $user = $stmt->fetch();

            [$passwordValid, $upgradeHash] = $user
                ? campusVerifyPassword($password, $user['password_hash'])
                : [false, false];

            if ($user && $passwordValid) {
                if ($user['status'] === 'pending') {
                    $error = 'Your account is still awaiting approval from the school administration.';
                } elseif ($user['status'] !== 'active') {
                    $error = 'Your account is currently inactive. Please contact the school administrator.';
                } else {
                    if ($upgradeHash) {
                        $updateHash = $db->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
                        $updateHash->execute(['password_hash' => campusPasswordHash($password), 'id' => $user['id']]);
                    }

                    $sessionUser = [
                        'id' => (int) $user['id'],
                        'name' => $user['name'],
                        'email' => $user['email'],
                        'role' => $user['role'],
                        'status' => $user['status'],
                        'photo' => $user['photo'],
                        'phone' => $user['phone'] ?? '',
                    ];

                    session_regenerate_id(true);

                    // Enforce 2-Step Authentication (Email OTP) for EVERY user on the system
                    $hasTotp = !empty($user['totp_enabled']) && !empty($user['totp_secret']);
                    $hasEmail = !empty($userEmailClean) && filter_var($userEmailClean, FILTER_VALIDATE_EMAIL);
                    $needs2Fa = true; // Mandatory 2FA for all users

                    if ($needs2Fa) {
                        $decryptedSecret = null;
                        if ($hasTotp) {
                            try {
                                $decryptedSecret = campusDecryptTotpSecret($user['totp_secret']);
                            } catch (Throwable $e) {
                                $decryptedSecret = null;
                            }
                        }

                        // Generate and send an Email OTP to EVERY user
                        if ($hasEmail) {
                            // If an unexpired OTP was already generated recently, keep it to avoid race condition
                            $existingOtp = $user['email_otp'] ?? null;
                            $existingExp = !empty($user['email_otp_expires_at']) ? strtotime($user['email_otp_expires_at']) : 0;

                            if (!empty($existingOtp) && $existingExp > (time() + 300)) {
                                $otpCode = (string) $existingOtp;
                            } else {
                                $otpCode = sprintf('%06d', random_int(100000, 999999));
                                $expiresAt = date('Y-m-d H:i:s', time() + 900); // 15-minute validity

                                $db->prepare('UPDATE users SET email_otp = :code, email_otp_expires_at = :expires, totp_failed_attempts = 0, totp_locked_until = NULL WHERE id = :id')
                                    ->execute(['code' => $otpCode, 'expires' => $expiresAt, 'id' => $user['id']]);

                                $mailRes = sendCampusEmail(
                                    $user['email'],
                                    $user['name'],
                                    'CampusIQ Sign-In Verification Code: ' . $otpCode,
                                    buildOtpEmailHtml($user['name'], $otpCode, 15, 'Sign-In Verification')
                                );

                                if (!$mailRes['success']) {
                                    $error = 'Could not send verification email: ' . ($mailRes['error'] ?: 'Please check your mail configuration.');
                                } else {
                                    $success = 'A 6-digit verification code has been sent to your Gmail (' . e($user['email']) . ').';
                                }
                            }

                            $_SESSION['pending_2fa_otp'] = $otpCode;
                        } else {
                            $db->prepare('UPDATE users SET totp_failed_attempts = 0, totp_locked_until = NULL WHERE id = :id')
                                ->execute(['id' => $user['id']]);
                        }

                        $_SESSION['pending_2fa_user'] = [
                            'user' => $sessionUser,
                            'has_totp' => $hasTotp,
                            'secret' => $decryptedSecret,
                            'email' => $user['email'],
                            'has_real_email' => $hasEmail,
                        ];
                        $_SESSION['pending_2fa_at'] = time();

                        if (empty($error) && $hasEmail && empty($user['totp_secret'])) {
                            $success = 'A 6-digit verification code has been sent to your Gmail (' . e($user['email']) . ').';
                        }
                    } else {
                        // Direct login without 2FA
                        loginUser($sessionUser);
                        $db->prepare('INSERT INTO activity_logs (user_id, action, details) VALUES (:user_id, :action, :details)')
                            ->execute([
                                'user_id' => $sessionUser['id'],
                                'action' => 'Login',
                                'details' => ucfirst($sessionUser['role']) . ' logged in',
                            ]);
                        $targetDashboard = match ($sessionUser['role'] ?? '') {
                            'super_admin', 'school_admin' => '/admin/index.php',
                            'teacher' => '/teacher/index.php',
                            'student' => '/student/index.php',
                            'parent' => '/parent/index.php',
                            'accountant' => '/accountant/index.php',
                            default => '/index.php',
                        };
                        redirect($targetDashboard);
                    }
                }
            } else {
                $error = 'Invalid email or password.';
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
    <title><?php echo e($school['school_name'] ?? 'CampusIQ'); ?> — Academic Portal</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="<?php echo baseUrl(); ?>/assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/assets/css/style.css'); ?>">
</head>
<body class="auth-page">
    <!-- Sleek Centered Institutional Card -->
    <div class="auth-shell-centered">
        <!-- 1. School Institutional Branding -->
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

        <!-- 2. Role Selector Tabs (Admin/Staff, Student, Parent) -->
        <?php if (empty($_SESSION['pending_2fa_user'])): ?>
            <div class="portal-role-nav" role="tablist">
                <button type="button" class="portal-role-btn active" data-role="all" data-hint="Email or username">All Portals</button>
                <button type="button" class="portal-role-btn" data-role="staff" data-hint="Staff email or username">Staff</button>
                <button type="button" class="portal-role-btn" data-role="student" data-hint="Student ID or email">Student</button>
                <button type="button" class="portal-role-btn" data-role="parent" data-hint="Parent email or phone">Parent</button>
            </div>
        <?php endif; ?>

        <!-- 3. Form Greeting -->
        <div class="auth-greeting text-center">
            <?php if (!empty($_SESSION['pending_2fa_user'])): ?>
                <?php $p = $_SESSION['pending_2fa_user']; ?>
                <h3 class="auth-heading">Two-Step Verification</h3>
                <p class="auth-subheading">
                    <?php if (!empty($p['has_totp']) && !empty($p['has_real_email'])): ?>
                        Enter the code from <strong>Google Authenticator</strong> or your Gmail (<strong><?php echo e($p['email']); ?></strong>)
                    <?php elseif (!empty($p['has_totp'])): ?>
                        Enter the 6-digit code from your <strong>Authenticator App</strong>
                    <?php else: ?>
                        Enter the 6-digit code sent to <strong><?php echo e($p['email'] ?? 'your email'); ?></strong>
                    <?php endif; ?>
                </p>
            <?php else: ?>
                <h3 class="auth-heading" id="portalGreetingTitle">Welcome Back</h3>
                <p class="auth-subheading" id="portalGreetingSub">Sign in to your school dashboard</p>
            <?php endif; ?>
        </div>

        <!-- Error & Success Alerts -->
        <?php if ($error): ?>
            <div class="auth-alert alert-danger" role="alert">
                <i class="fa-solid fa-circle-exclamation alert-icon"></i>
                <div class="alert-text"><?php echo e($error); ?></div>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="auth-alert alert-success" role="alert">
                <i class="fa-solid fa-circle-check alert-icon"></i>
                <div class="alert-text"><?php echo e($success); ?></div>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['activated'])): ?>
            <div class="auth-alert alert-success" role="alert">
                <i class="fa-solid fa-circle-check alert-icon"></i>
                <div class="alert-text">Your account has been activated successfully! Please sign in with your email and new password.</div>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['reset'])): ?>
            <div class="auth-alert alert-success" role="alert">
                <i class="fa-solid fa-circle-check alert-icon"></i>
                <div class="alert-text">Your password was reset successfully. Please sign in with your new password.</div>
            </div>
        <?php endif; ?>

        <form method="POST" novalidate id="loginForm" class="auth-form-content">
            <input type="hidden" name="csrf_token" value="<?php echo e(csrfToken()); ?>">
            <input type="hidden" name="portal_role" id="portalRoleInput" value="all">

            <?php if (!empty($_SESSION['pending_2fa_user'])): ?>
                <div class="otp-box-wrapper mb-3">
                    <label class="form-label text-dark fw-bold mb-2" for="code">Enter 6-Digit Code</label>
                    <input id="code" type="text" class="form-control text-center fw-bold fs-3 auth-input-clean" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required autofocus placeholder="------" style="letter-spacing: 10px; font-family: monospace;">
                    <div class="d-flex justify-content-center align-items-center mt-3">
                        <span class="otp-timer-badge" id="otpTimerBox">
                            <i class="fa-regular fa-clock"></i>
                            <span>Expires in: </span>
                            <strong id="otpCountdown">10:00</strong>
                        </span>
                    </div>
                    <small class="text-muted d-block mt-2">
                        <?php if (!empty($_SESSION['pending_2fa_user']['has_totp'])): ?>
                            Works with Google Authenticator or your Gmail code.
                        <?php else: ?>
                            Check your Gmail inbox and spam folder.
                        <?php endif; ?>
                    </small>
                </div>

                <button type="submit" class="btn btn-primary w-100 btn-signin-clean mb-2" id="submitBtn">
                    <span class="btn-text">Verify & Continue</span>
                    <span class="btn-loading d-none"><span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span> Verifying...</span>
                </button>
            <?php else: ?>
                <!-- Clean Email / Username Field (No symbol clutter) -->
                <div class="mb-3">
                    <label class="form-label fw-semibold" for="loginIdentity" id="identityLabel">Email or Username</label>
                    <input 
                        id="loginIdentity" 
                        type="text" 
                        class="form-control auth-input-clean" 
                        name="identity" 
                        required 
                        placeholder="Enter email or username" 
                        autofocus 
                        value="<?php echo e($_POST['identity'] ?? $_POST['email'] ?? ''); ?>"
                        autocomplete="username"
                    >
                </div>

                <!-- Clean Password Field with Minimalist Toggle -->
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <label class="form-label fw-semibold mb-0" for="loginPassword">Password</label>
                        <a href="<?php echo baseUrl(); ?>/forgot_password.php" class="auth-link-subtle">Forgot password?</a>
                    </div>
                    <div class="password-wrapper-clean">
                        <input 
                            id="loginPassword" 
                            type="password" 
                            class="form-control auth-input-clean" 
                            name="password" 
                            required 
                            placeholder="Enter password"
                            autocomplete="current-password"
                        >
                        <button type="button" class="password-toggle-clean" id="passwordToggleBtn" aria-label="Toggle password visibility" tabindex="-1">
                            <i class="fa-regular fa-eye" id="toggleIcon"></i>
                        </button>
                    </div>
                </div>

                <!-- Remember Me & Need Help Trigger -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <label class="custom-checkbox">
                        <input type="checkbox" name="remember" id="rememberMe" checked>
                        <span class="custom-checkbox-text">Remember me</span>
                    </label>
                    <button type="button" class="btn-help-link" data-bs-toggle="modal" data-bs-target="#supportModal">
                        Need Help?
                    </button>
                </div>

                <!-- Clean Sign In Button -->
                <button type="submit" class="btn btn-primary w-100 btn-signin-clean" id="submitBtn">
                    <span class="btn-text">Sign In</span>
                    <span class="btn-loading d-none"><span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span> Signing in...</span>
                </button>
            <?php endif; ?>
        </form>

        <?php if (!empty($_SESSION['pending_2fa_user'])): ?>
            <div class="mt-3 d-flex justify-content-center align-items-center gap-3">
                <?php if (!empty($_SESSION['pending_2fa_user']['has_real_email'])): ?>
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?php echo e(csrfToken()); ?>">
                        <input type="hidden" name="action" value="resend_code">
                        <button type="submit" class="btn btn-link text-decoration-none small text-muted p-0" id="resendBtn">
                            <i class="fa-solid fa-rotate-right me-1"></i> Resend Email Code
                        </button>
                    </form>
                    <span class="text-muted small">&bull;</span>
                <?php endif; ?>
                <form method="POST" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?php echo e(csrfToken()); ?>">
                    <input type="hidden" name="action" value="cancel_2fa">
                    <button type="submit" class="btn btn-link text-decoration-none small text-danger p-0">
                        <i class="fa-solid fa-arrow-left me-1"></i> Back to Sign In
                    </button>
                </form>
            </div>
        <?php endif; ?>

        <!-- Clean Card Footer -->
        <div class="auth-card-footer-clean text-center mt-4 pt-3 border-top">
            <p class="small text-muted mb-2">
                Need an account? 
                <a href="<?php echo baseUrl(); ?>/request_access.php" class="auth-action-link">Request Access</a>
            </p>
            <div class="powered-by-clean">
                <span>Powered by <strong>CampusIQ</strong></span>
            </div>
        </div>
    </div>

    <!-- 4. School Support Drawer / Modal -->
    <div class="modal fade" id="supportModal" tabindex="-1" aria-labelledby="supportModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 20px;">
                <div class="modal-header border-0 pb-0">
                    <div>
                        <h5 class="modal-title fw-bold" id="supportModalLabel">School Help & Support Desk</h5>
                        <small class="text-muted"><?php echo e($school['school_name'] ?? 'Adonai International School'); ?> Administration</small>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <p class="small text-muted mb-3">If you are experiencing issues signing in or need your login credentials, contact the school administration directly:</p>

                    <div class="list-group list-group-flush mb-3">
                        <!-- Direct Phone -->
                        <a href="tel:<?php echo e($cleanPhone); ?>" class="list-group-item list-group-item-action d-flex align-items-center gap-3 py-3 border-0 rounded-3 mb-2 bg-light">
                            <div class="bg-primary bg-opacity-10 text-primary p-2 rounded-3 fs-5">
                                <i class="fa-solid fa-phone"></i>
                            </div>
                            <div class="flex-grow-1">
                                <strong class="d-block text-dark">Direct Phone Line</strong>
                                <span class="small text-muted"><?php echo e($schoolPhone); ?></span>
                            </div>
                            <i class="fa-solid fa-arrow-up-right-from-square text-muted small"></i>
                        </a>

                        <!-- WhatsApp Desk -->
                        <a href="<?php echo e($whatsappUrl); ?>" target="_blank" rel="noopener noreferrer" class="list-group-item list-group-item-action d-flex align-items-center gap-3 py-3 border-0 rounded-3 mb-2 bg-light">
                            <div class="bg-success bg-opacity-10 text-success p-2 rounded-3 fs-5">
                                <i class="fa-brands fa-whatsapp"></i>
                            </div>
                            <div class="flex-grow-1">
                                <strong class="d-block text-dark">WhatsApp Support Desk</strong>
                                <span class="small text-muted">Instant chat assistance with school admin</span>
                            </div>
                            <i class="fa-solid fa-arrow-up-right-from-square text-muted small"></i>
                        </a>

                        <!-- Email Support -->
                        <a href="mailto:<?php echo e($school['email'] ?? 'info@campusiq.edu.gh'); ?>" class="list-group-item list-group-item-action d-flex align-items-center gap-3 py-3 border-0 rounded-3 mb-2 bg-light">
                            <div class="bg-info bg-opacity-10 text-info p-2 rounded-3 fs-5">
                                <i class="fa-solid fa-envelope"></i>
                            </div>
                            <div class="flex-grow-1">
                                <strong class="d-block text-dark">Official Email</strong>
                                <span class="small text-muted"><?php echo e($school['email'] ?? 'info@campusiq.edu.gh'); ?></span>
                            </div>
                            <i class="fa-solid fa-arrow-up-right-from-square text-muted small"></i>
                        </a>

                        <!-- Physical Campus Location -->
                        <div class="list-group-item d-flex align-items-center gap-3 py-3 border-0 rounded-3 bg-light">
                            <div class="bg-secondary bg-opacity-10 text-secondary p-2 rounded-3 fs-5">
                                <i class="fa-solid fa-location-dot"></i>
                            </div>
                            <div class="flex-grow-1">
                                <strong class="d-block text-dark">Campus Administration Office</strong>
                                <span class="small text-muted"><?php echo e($school['address'] ?? 'Accra, Ghana'); ?> &bull; Mon - Fri, 8:00 AM - 4:30 PM</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-light w-100 rounded-3" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Small Page Footer -->
    <div class="auth-footer-help">
        <p class="mb-0">
            &copy; <?php echo date('Y'); ?> CampusIQ. All rights reserved.
        </p>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?php echo baseUrl(); ?>/assets/js/app.js"></script>
    <script>
    // Role Selector dynamic handler
    document.addEventListener('DOMContentLoaded', function () {
        const roleBtns = document.querySelectorAll('.portal-role-btn');
        const roleInput = document.getElementById('portalRoleInput');
        const identityInput = document.getElementById('loginIdentity');
        const identityLabel = document.getElementById('identityLabel');
        const greetingTitle = document.getElementById('portalGreetingTitle');
        const greetingSub = document.getElementById('portalGreetingSub');

        roleBtns.forEach(btn => {
            btn.addEventListener('click', function () {
                roleBtns.forEach(b => b.classList.remove('active'));
                this.classList.add('active');

                const role = this.getAttribute('data-role');
                const hint = this.getAttribute('data-hint');

                if (roleInput) roleInput.value = role;
                if (identityInput) identityInput.placeholder = hint;

                if (role === 'staff') {
                    if (identityLabel) identityLabel.textContent = 'Staff Email or Username';
                    if (greetingTitle) greetingTitle.textContent = 'Staff & Admin Portal';
                    if (greetingSub) greetingSub.textContent = 'Sign in to access academic administration';
                } else if (role === 'student') {
                    if (identityLabel) identityLabel.textContent = 'Student ID or Email';
                    if (greetingTitle) greetingTitle.textContent = 'Student Portal';
                    if (greetingSub) greetingSub.textContent = 'Sign in to check grades and assignments';
                } else if (role === 'parent') {
                    if (identityLabel) identityLabel.textContent = 'Parent Phone or Email';
                    if (greetingTitle) greetingTitle.textContent = 'Parent Portal';
                    if (greetingSub) greetingSub.textContent = 'Sign in to track student progress & fees';
                } else {
                    if (identityLabel) identityLabel.textContent = 'Email or Username';
                    if (greetingTitle) greetingTitle.textContent = 'Welcome Back';
                    if (greetingSub) greetingSub.textContent = 'Sign in to your school dashboard';
                }
            });
        });
    });
    </script>
</body>
</html>
