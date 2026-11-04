<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/security.php';

if (isLoggedIn()) {
    redirect('/index.php');
}

$db = getDb();
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken();
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $role = trim($_POST['role'] ?? 'parent');
    $requestType = trim($_POST['request_type'] ?? 'account');
    $studentName = trim($_POST['student_name'] ?? '');
    $className = trim($_POST['class_name'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($name === '' || $email === '' || $role === '' || $password === '') {
        $error = 'Please complete all required fields, including your password.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } elseif (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        $error = 'Password must include both letters and numbers for your security.';
    } elseif ($password !== $confirmPassword) {
        $error = 'The passwords do not match. Please verify and try again.';
    } else {
        $passwordHash = campusPasswordHash($password);

        $stmt = $db->prepare('INSERT INTO access_requests (name, email, phone, role, request_type, student_name, class_name, message, password_hash, status) VALUES (:name, :email, :phone, :role, :request_type, :student_name, :class_name, :message, :password_hash, "pending")');
        $stmt->execute([
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'role' => $role,
            'request_type' => $requestType,
            'student_name' => $studentName !== '' ? $studentName : null,
            'class_name' => $className !== '' ? $className : null,
            'message' => $message,
            'password_hash' => $passwordHash,
        ]);
        $success = 'Your access request has been submitted successfully! Once approved by the school administrator, you will receive an email confirmation and can sign in with your email and password (a 6-digit verification code will be sent to your Gmail for two-step security).';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Access | CampusIQ</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="<?php echo baseUrl() ? baseUrl() : ''; ?>/assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/assets/css/style.css'); ?>">
</head>
<body class="auth-page">
    <div class="auth-shell">
        <div class="auth-visual">
            <div class="auth-visual-badge">
                <i class="fa-solid fa-shield-heart"></i>
            </div>
            <h1>Request School Access</h1>
            <p>For a safe and secure school environment, all new accounts are reviewed by the school administration before approval.</p>
            <ul>
                <li><i class="fa-solid fa-circle-check"></i> Secure, admin-approved access</li>
                <li><i class="fa-solid fa-circle-check"></i> Parent and student portal requests</li>
                <li><i class="fa-solid fa-circle-check"></i> Role-based permissions</li>
            </ul>
        </div>

        <div class="auth-card">
            <div class="text-center mb-4">
                <div class="brand-mark mx-auto mb-3" style="width:66px;height:66px;font-size:1.8rem;">
                    <i class="fa-solid fa-school"></i>
                </div>
                <p class="auth-kicker">Request access</p>
                <h3 class="fw-bold mb-1">Apply for account</h3>
                <p class="text-muted mb-0">Submit your request for review</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo e($error); ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo e($success); ?></div>
            <?php endif; ?>

            <form method="POST" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo csrfToken(); ?>">
                <div class="row g-3">
                    <div class="col-md-6 auth-field">
                        <label class="form-label">Full Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" value="<?php echo e($_POST['name'] ?? ''); ?>" placeholder="Enter your full name" required>
                    </div>
                    <div class="col-md-6 auth-field">
                        <label class="form-label">Email Address <span class="text-danger">*</span></label>
                        <input type="email" class="form-control" name="email" value="<?php echo e($_POST['email'] ?? ''); ?>" placeholder="name@example.com" required>
                    </div>
                    <div class="col-md-6 auth-field">
                        <label class="form-label">Phone Number</label>
                        <input type="text" class="form-control" name="phone" value="<?php echo e($_POST['phone'] ?? ''); ?>" placeholder="024 000 0000">
                    </div>
                    <div class="col-md-6 auth-field">
                        <label class="form-label">Role <span class="text-danger">*</span></label>
                        <select class="form-select" name="role">
                            <option value="parent" <?php echo (($_POST['role'] ?? '') === 'parent') ? 'selected' : ''; ?>>Parent</option>
                            <option value="student" <?php echo (($_POST['role'] ?? '') === 'student') ? 'selected' : ''; ?>>Student</option>
                            <option value="teacher" <?php echo (($_POST['role'] ?? '') === 'teacher') ? 'selected' : ''; ?>>Teacher</option>
                            <option value="accountant" <?php echo (($_POST['role'] ?? '') === 'accountant') ? 'selected' : ''; ?>>Accountant</option>
                            <option value="school_admin" <?php echo (($_POST['role'] ?? '') === 'school_admin') ? 'selected' : ''; ?>>School Admin</option>
                        </select>
                    </div>
                    <div class="col-md-6 auth-field">
                        <label class="form-label">Request Type</label>
                        <select class="form-select" name="request_type">
                            <option value="account">New Account</option>
                            <option value="student_portal">Student Portal Access</option>
                            <option value="parent_portal">Parent Portal Access</option>
                            <option value="teacher_account">Teacher Account</option>
                        </select>
                    </div>
                    <div class="col-md-6 auth-field">
                        <label class="form-label">Student Name (if applicable)</label>
                        <input type="text" class="form-control" name="student_name" value="<?php echo e($_POST['student_name'] ?? ''); ?>" placeholder="Student full name">
                    </div>
                    <div class="col-12 auth-field">
                        <label class="form-label">Class / Section</label>
                        <input type="text" class="form-control" name="class_name" value="<?php echo e($_POST['class_name'] ?? ''); ?>" placeholder="e.g. Basic 4A, JHS 2">
                    </div>

                    <!-- Password and Confirm Password Fields -->
                    <div class="col-md-6 auth-field">
                        <label class="form-label">Password <span class="text-danger">*</span></label>
                        <div class="password-wrapper-clean">
                            <input 
                                type="password" 
                                class="form-control auth-input-clean" 
                                name="password" 
                                id="regPassword" 
                                required 
                                placeholder="At least 8 chars (letters & numbers)"
                                autocomplete="new-password"
                            >
                            <button type="button" class="password-toggle-clean" id="togglePwd1" tabindex="-1">
                                <i class="fa-regular fa-eye" id="toggleIcon1"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-md-6 auth-field">
                        <label class="form-label">Confirm Password <span class="text-danger">*</span></label>
                        <div class="password-wrapper-clean">
                            <input 
                                type="password" 
                                class="form-control auth-input-clean" 
                                name="confirm_password" 
                                id="regConfirmPassword" 
                                required 
                                placeholder="Re-enter password"
                                autocomplete="new-password"
                            >
                            <button type="button" class="password-toggle-clean" id="togglePwd2" tabindex="-1">
                                <i class="fa-regular fa-eye" id="toggleIcon2"></i>
                            </button>
                        </div>
                    </div>

                    <div class="col-12 auth-field">
                        <label class="form-label">Message (Optional)</label>
                        <textarea class="form-control" name="message" rows="3" placeholder="Tell us why you need access to the system"><?php echo e($_POST['message'] ?? ''); ?></textarea>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary w-100 mt-4 py-2">
                    <i class="fa-solid fa-paper-plane me-2"></i>Submit Access Request
                </button>
            </form>

            <div class="mt-4 text-center">
                <a href="<?php echo baseUrl(); ?>/login.php" class="auth-link">Already have an account? Sign in</a>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            function bindToggle(inputId, btnId, iconId) {
                const inp = document.getElementById(inputId);
                const btn = document.getElementById(btnId);
                const ico = document.getElementById(iconId);
                if (inp && btn && ico) {
                    btn.addEventListener('click', function(e) {
                        e.preventDefault();
                        if (inp.type === 'password') {
                            inp.type = 'text';
                            ico.classList.remove('fa-eye');
                            ico.classList.add('fa-eye-slash');
                        } else {
                            inp.type = 'password';
                            ico.classList.remove('fa-eye-slash');
                            ico.classList.add('fa-eye');
                        }
                    });
                }
            }
            bindToggle('regPassword', 'togglePwd1', 'toggleIcon1');
            bindToggle('regConfirmPassword', 'togglePwd2', 'toggleIcon2');
        });
    </script>
</body>
</html>
