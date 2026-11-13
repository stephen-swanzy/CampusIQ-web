<?php

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/security.php';

if (session_status() === PHP_SESSION_NONE) {
    // Vercel serverless functions have a read-only filesystem except for /tmp
    if (is_dir('/tmp') && is_writable('/tmp')) {
        ini_set('session.save_path', '/tmp');
    }
    ini_set('session.gc_maxlifetime', '604800');
    session_set_cookie_params([
        'lifetime' => 604800,
        'path' => '/',
        'domain' => '',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https'),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

function isLoggedIn(): bool
{
    return !empty($_SESSION['campusiq_user']);
}

function currentUser(): ?array
{
    return $_SESSION['campusiq_user'] ?? null;
}

function requireLogin(array $roles = []): void
{
    if (!isLoggedIn()) {
        redirect('/login.php');
    }

    $userRole = $_SESSION['campusiq_user']['role'] ?? '';

    // If roles are restricted, allow if user has the role OR is super_admin
    if ($roles && !in_array($userRole, $roles, true) && $userRole !== 'super_admin') {
        http_response_code(403);
        $baseUrl = baseUrl();
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>403 - Permission Denied | CampusIQ</title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
            <style>
                body { background-color: #0f172a; color: #f8fafc; font-family: system-ui, -apple-system, sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 1rem; }
                .denied-card { background: #1e293b; border: 1px solid #334155; border-radius: 1rem; padding: 2.5rem; text-align: center; max-width: 460px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.4); }
                .icon-circle { width: 70px; height: 70px; background: rgba(239, 68, 68, 0.15); color: #ef4444; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 2rem; margin-bottom: 1.5rem; }
            </style>
        </head>
        <body>
            <div class="denied-card">
                <div class="icon-circle"><i class="fa-solid fa-shield-halved"></i></div>
                <h3 class="fw-bold mb-2">Access Denied (403)</h3>
                <p class="text-secondary mb-4">You do not have permission to view or manage this section. Your current role is <strong><?php echo htmlspecialchars(userRoleLabel($userRole)); ?></strong>.</p>
                <div class="d-flex justify-content-center gap-2">
                    <a href="<?php echo $baseUrl; ?>/index.php" class="btn btn-primary px-4"><i class="fa-solid fa-gauge-high me-2"></i>My Dashboard</a>
                    <a href="<?php echo $baseUrl; ?>/logout.php" class="btn btn-outline-secondary px-3"><i class="fa-solid fa-right-from-bracket me-2"></i>Sign Out</a>
                </div>
            </div>
        </body>
        </html>
        <?php
        exit;
    }

    // Generous inactivity timeout: 7 days (604,800s) to keep users seamlessly logged in
    if (!empty($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 604800)) {
        session_unset();
        session_destroy();
        redirect('/login.php');
    }

    $_SESSION['last_activity'] = time();
}

function loginUser(array $user): void
{
    session_regenerate_id(true);
    unset($_SESSION['pending_2fa_user'], $_SESSION['pending_2fa_at'], $_SESSION['pending_2fa_attempts']);
    $_SESSION['campusiq_user'] = $user;
    $_SESSION['last_activity'] = time();
}

function logoutUser(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function userRoleLabel(string $role): string
{
    $labels = [
        'super_admin' => 'Super Admin',
        'school_admin' => 'School Admin',
        'teacher' => 'Teacher',
        'student' => 'Student',
        'parent' => 'Parent',
        'accountant' => 'Accountant',
    ];

    return $labels[$role] ?? ucfirst(str_replace('_', ' ', $role));
}
