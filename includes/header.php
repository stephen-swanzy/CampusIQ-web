<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';

$baseUrl = baseUrl();
$settings = getSchoolSettings();
$user = currentUser();
$role = $user['role'] ?? 'guest';

function navItems(string $role, string $baseUrl): array
{
    $items = [
        'super_admin' => [
            ['label' => 'Dashboard', 'url' => $baseUrl . '/admin/index.php', 'icon' => 'fa-gauge-high'],
            ['label' => 'Students', 'url' => $baseUrl . '/admin/students.php', 'icon' => 'fa-user-graduate'],
            ['label' => 'Teachers', 'url' => $baseUrl . '/admin/teachers.php', 'icon' => 'fa-chalkboard-user'],
            ['label' => 'Classes', 'url' => $baseUrl . '/admin/classes.php', 'icon' => 'fa-school'],
            ['label' => 'Subjects', 'url' => $baseUrl . '/admin/subjects.php', 'icon' => 'fa-book'],
            ['label' => 'Attendance', 'url' => $baseUrl . '/admin/attendance.php', 'icon' => 'fa-clipboard-check'],
            ['label' => 'Fees', 'url' => $baseUrl . '/admin/fees.php', 'icon' => 'fa-wallet'],
            ['label' => 'Reports', 'url' => $baseUrl . '/admin/reports.php', 'icon' => 'fa-chart-column'],
            ['label' => 'Access Requests', 'url' => $baseUrl . '/admin/access_requests.php', 'icon' => 'fa-user-clock'],
            ['label' => 'Backups & Cloud', 'url' => $baseUrl . '/admin/backups.php', 'icon' => 'fa-cloud-arrow-up'],
            ['label' => 'Security Demo', 'url' => $baseUrl . '/admin/security_demo.php', 'icon' => 'fa-shield-halved'],
            ['label' => 'System Concerns', 'url' => $baseUrl . '/admin/system_reports.php', 'icon' => 'fa-flag'],
            ['label' => 'Settings', 'url' => $baseUrl . '/admin/settings.php', 'icon' => 'fa-gear'],
        ],
        'school_admin' => [
            ['label' => 'Dashboard', 'url' => $baseUrl . '/admin/index.php', 'icon' => 'fa-gauge-high'],
            ['label' => 'Students', 'url' => $baseUrl . '/admin/students.php', 'icon' => 'fa-user-graduate'],
            ['label' => 'Teachers', 'url' => $baseUrl . '/admin/teachers.php', 'icon' => 'fa-chalkboard-user'],
            ['label' => 'Classes', 'url' => $baseUrl . '/admin/classes.php', 'icon' => 'fa-school'],
            ['label' => 'Access Requests', 'url' => $baseUrl . '/admin/access_requests.php', 'icon' => 'fa-user-clock'],
            ['label' => 'Backups & Cloud', 'url' => $baseUrl . '/admin/backups.php', 'icon' => 'fa-cloud-arrow-up'],
            ['label' => 'Security Demo', 'url' => $baseUrl . '/admin/security_demo.php', 'icon' => 'fa-shield-halved'],
            ['label' => 'Settings', 'url' => $baseUrl . '/admin/settings.php', 'icon' => 'fa-gear'],
        ],
        'teacher' => [
            ['label' => 'Dashboard', 'url' => $baseUrl . '/teacher/index.php', 'icon' => 'fa-gauge-high'],
            ['label' => 'Attendance', 'url' => $baseUrl . '/teacher/attendance.php', 'icon' => 'fa-clipboard-check'],
            ['label' => 'Results', 'url' => $baseUrl . '/teacher/results.php', 'icon' => 'fa-square-poll-vertical'],
            ['label' => 'Assignments', 'url' => $baseUrl . '/teacher/assignments.php', 'icon' => 'fa-file-lines'],
            ['label' => 'Resources', 'url' => $baseUrl . '/teacher/resources.php', 'icon' => 'fa-folder-open'],
            ['label' => 'Communication', 'url' => $baseUrl . '/teacher/communication.php', 'icon' => 'fa-bullhorn'],
            ['label' => 'Students', 'url' => $baseUrl . '/teacher/students.php', 'icon' => 'fa-users'],
        ],
        'student' => [
            ['label' => 'Dashboard', 'url' => $baseUrl . '/student/index.php', 'icon' => 'fa-gauge-high'],
            ['label' => 'Attendance', 'url' => $baseUrl . '/student/attendance.php', 'icon' => 'fa-calendar-check'],
            ['label' => 'Results', 'url' => $baseUrl . '/student/results.php', 'icon' => 'fa-chart-line'],
            ['label' => 'Assignments', 'url' => $baseUrl . '/student/assignments.php', 'icon' => 'fa-file-lines'],
            ['label' => 'Resources', 'url' => $baseUrl . '/student/resources.php', 'icon' => 'fa-folder-open'],
            ['label' => 'Fees', 'url' => $baseUrl . '/student/fees.php', 'icon' => 'fa-wallet'],
        ],
        'parent' => [
            ['label' => 'Dashboard', 'url' => $baseUrl . '/parent/index.php', 'icon' => 'fa-gauge-high'],
            ['label' => 'Children', 'url' => $baseUrl . '/parent/children.php', 'icon' => 'fa-children'],
            ['label' => 'Attendance', 'url' => $baseUrl . '/parent/attendance.php', 'icon' => 'fa-calendar-check'],
            ['label' => 'Results', 'url' => $baseUrl . '/parent/results.php', 'icon' => 'fa-chart-line'],
            ['label' => 'Fees', 'url' => $baseUrl . '/parent/fees.php', 'icon' => 'fa-wallet'],
        ],
        'accountant' => [
            ['label' => 'Dashboard', 'url' => $baseUrl . '/accountant/index.php', 'icon' => 'fa-gauge-high'],
            ['label' => 'Invoices', 'url' => $baseUrl . '/accountant/invoices.php', 'icon' => 'fa-file-invoice-dollar'],
            ['label' => 'Payments', 'url' => $baseUrl . '/accountant/payments.php', 'icon' => 'fa-money-bill-wave'],
            ['label' => 'Fees', 'url' => $baseUrl . '/accountant/fees.php', 'icon' => 'fa-wallet'],
            ['label' => 'Reports', 'url' => $baseUrl . '/accountant/reports.php', 'icon' => 'fa-chart-column'],
        ],
    ];

    return $items[$role] ?? $items['student'];
}

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $settings['school_name'] ?? 'CampusIQ'; ?> — Institutional Management</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700;800&family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="<?php echo $baseUrl ? $baseUrl : ''; ?>/assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/style.css'); ?>">
</head>
<body>
<?php if (isLoggedIn()): 
    $currentUri = $_SERVER['REQUEST_URI'] ?? '';
?>
<div class="app-shell">
    <aside class="sidebar offcanvas-lg offcanvas-start" id="sidebarNav" tabindex="-1" aria-labelledby="sidebarNavLabel">
        <div class="brand-box d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center gap-2 min-w-0">
                <div class="brand-mark flex-shrink-0"><i class="fa-solid fa-graduation-cap"></i></div>
                <div class="brand-copy min-w-0">
                    <div class="brand-name text-truncate" id="sidebarNavLabel"><?php echo e($settings['school_name']); ?></div>
                    <small class="brand-tag">Academic Suite</small>
                </div>
            </div>
            <button type="button" class="btn-close btn-close-white d-lg-none ms-2" data-bs-dismiss="offcanvas" data-bs-target="#sidebarNav" aria-label="Close"></button>
        </div>

        <nav class="nav-menu">
            <?php foreach (navItems($role, $baseUrl) as $item): 
                $isActive = (parse_url($item['url'], PHP_URL_PATH) === parse_url($currentUri, PHP_URL_PATH));
            ?>
                <a href="<?php echo $item['url']; ?>" class="nav-item <?php echo $isActive ? 'active' : ''; ?>">
                    <i class="fa-solid <?php echo $item['icon']; ?>"></i>
                    <span><?php echo e($item['label']); ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="sidebar-footer">
            <div class="mb-2 px-2 d-flex align-items-center justify-content-between">
                <span class="system-status-indicator small text-light">Status: Online</span>
                <span class="badge bg-success bg-opacity-25 text-success border border-success border-opacity-25">Secure</span>
            </div>
            <button type="button" class="btn btn-sm w-100 mb-2 d-flex align-items-center justify-content-center gap-2 rounded-3 text-white" data-bs-toggle="modal" data-bs-target="#reportSystemConcernModal" style="border: 1px solid rgba(239, 68, 68, 0.4); background: rgba(239, 68, 68, 0.15);">
                <i class="fa-solid fa-flag text-danger"></i>
                <span style="font-size: 0.8rem;">Report Issue</span>
            </button>
            <a href="<?php echo $baseUrl; ?>/profile.php" class="btn btn-outline-light btn-sm w-100 mb-2"><i class="fa-solid fa-user me-2"></i>My Profile</a>
            <a href="<?php echo $baseUrl; ?>/logout.php" class="btn btn-light btn-sm w-100"><i class="fa-solid fa-right-from-bracket me-2"></i>Sign Out</a>
        </div>
    </aside>

    <div class="main-panel">
        <nav class="topbar">
            <div class="d-flex align-items-center gap-2 min-w-0">
                <button class="btn btn-sm btn-light border shadow-xs d-lg-none rounded-3 px-2 py-1 flex-shrink-0" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebarNav" aria-controls="sidebarNav" aria-label="Toggle navigation">
                    <i class="fa-solid fa-bars-staggered fs-6 text-dark"></i>
                </button>
                <div class="min-w-0">
                    <div class="workspace-kicker d-none d-sm-block">Academic Workspace</div>
                    <h4 class="page-title mb-0 text-truncate fs-6 fw-bold"><?php echo e($settings['school_name']); ?></h4>
                </div>
            </div>

            <!-- Central Search & Session info -->
            <div class="d-none d-lg-flex align-items-center gap-3">
                <span class="session-badge">
                    <i class="fa-solid fa-calendar-check me-2"></i>2025/2026 Academic Year
                </span>
                <div class="search-box">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" id="globalSearch" placeholder="Search records... (Ctrl + K)">
                    <span class="search-kbd">⌘K</span>
                </div>
            </div>

            <div class="topbar-actions">
                <!-- Live Digital Clock -->
                <div class="live-clock d-none d-md-inline-flex">
                    <i class="fa-regular fa-clock text-primary"></i>
                    <span id="headerLiveClock"></span>
                </div>

                <!-- Report System Concern Button (Available to Students, Teachers, Parents, Accountants, Admins) -->
                <button class="btn btn-sm btn-outline-danger d-inline-flex align-items-center gap-2 rounded-pill px-3 py-1 shadow-xs bg-white border-opacity-75" type="button" data-bs-toggle="modal" data-bs-target="#reportSystemConcernModal" title="Report System Concern / Suggestion">
                    <i class="fa-solid fa-flag text-danger"></i>
                    <span class="d-none d-md-inline small fw-semibold text-danger">Report Issue</span>
                </button>

                <!-- Notifications Dropdown -->
                <div class="dropdown">
                    <button class="icon-button" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Notifications">
                        <i class="fa-solid fa-bell"></i>
                        <span class="badge">3</span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0 p-0" style="width: 310px; border-radius: 16px; overflow: hidden;">
                        <li class="p-3 bg-light border-bottom d-flex justify-content-between align-items-center">
                            <span class="fw-bold small text-dark">Institutional Alerts</span>
                            <span class="badge bg-primary text-white">3 New</span>
                        </li>
                        <li class="p-3 border-bottom d-flex gap-3 align-items-start">
                            <i class="fa-solid fa-shield-halved text-success mt-1"></i>
                            <div>
                                <p class="mb-0 small fw-semibold text-dark">2FA Security Active</p>
                                <small class="text-muted">Real-time Gmail verification enabled</small>
                            </div>
                        </li>
                        <li class="p-3 border-bottom d-flex gap-3 align-items-start">
                            <i class="fa-solid fa-circle-check text-primary mt-1"></i>
                            <div>
                                <p class="mb-0 small fw-semibold text-dark">System Synchronized</p>
                                <small class="text-muted">Port 3307 database connected</small>
                            </div>
                        </li>
                        <li class="p-3 d-flex gap-3 align-items-start">
                            <i class="fa-solid fa-lock text-warning mt-1"></i>
                            <div>
                                <p class="mb-0 small fw-semibold text-dark">Cryptographic Salts</p>
                                <small class="text-muted">HMAC-SHA256 Pepper operational</small>
                            </div>
                        </li>
                    </ul>
                </div>

                <!-- User Profile Pill Dropdown -->
                <div class="dropdown">
                    <button class="profile-pill border-0 text-start bg-white" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Account Menu">
                        <?php 
                            $hdrPhoto = $user['photo'] ?? null;
                            $hasHdrPhoto = !empty($hdrPhoto) && file_exists(__DIR__ . '/../' . ltrim($hdrPhoto, '/\\'));
                        ?>
                        <?php if ($hasHdrPhoto): ?>
                            <img src="<?php echo $baseUrl . '/' . ltrim($hdrPhoto, '/\\'); ?>" alt="Avatar" class="avatar-sm rounded-circle object-fit-cover shadow-sm">
                        <?php else: ?>
                            <div class="avatar-sm">
                                <?php echo strtoupper(substr($user['name'] ?? 'U', 0, 1)); ?>
                            </div>
                        <?php endif; ?>
                        <div class="d-none d-sm-block">
                            <div class="d-flex align-items-center">
                                <span class="online-dot"></span>
                                <strong><?php echo e($user['name'] ?? 'User'); ?></strong>
                            </div>
                            <small class="text-muted"><?php echo e(userRoleLabel($role)); ?></small>
                        </div>
                        <i class="fa-solid fa-chevron-down ms-1 text-muted small d-none d-sm-inline" style="font-size: 0.72rem;"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0 p-2" style="width: 250px; border-radius: 18px; margin-top: 8px;">
                        <li class="px-3 py-2 border-bottom mb-1 bg-light rounded-3">
                            <span class="fw-bold d-block text-dark text-truncate"><?php echo e($user['name'] ?? 'User'); ?></span>
                            <small class="text-muted d-block text-truncate"><?php echo e($user['email'] ?? ''); ?></small>
                            <span class="badge bg-primary bg-opacity-10 text-primary rounded-pill mt-1" style="font-size: 0.68rem;"><?php echo e(userRoleLabel($role)); ?></span>
                        </li>
                        <li>
                            <a class="dropdown-item rounded-3 py-2 d-flex align-items-center gap-2" href="<?php echo $baseUrl; ?>/profile.php">
                                <i class="fa-solid fa-circle-user text-primary fs-6"></i>
                                <span>My Profile &amp; Photo</span>
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item rounded-3 py-2 d-flex align-items-center gap-2" href="<?php echo $baseUrl; ?>/security.php">
                                <i class="fa-solid fa-shield-halved text-success fs-6"></i>
                                <span>Security &amp; 2FA</span>
                            </a>
                        </li>
                        <li>
                            <button type="button" class="dropdown-item rounded-3 py-2 d-flex align-items-center gap-2 text-danger border-0 bg-transparent w-100 text-start" data-bs-toggle="modal" data-bs-target="#reportSystemConcernModal">
                                <i class="fa-solid fa-flag text-danger fs-6"></i>
                                <span>Report System Concern</span>
                            </button>
                        </li>
                        <li><hr class="dropdown-divider my-1"></li>
                        <li>
                            <a class="dropdown-item rounded-3 py-2 text-danger d-flex align-items-center gap-2" href="<?php echo $baseUrl; ?>/logout.php">
                                <i class="fa-solid fa-right-from-bracket fs-6"></i>
                                <span>Sign Out</span>
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </nav>

        <main class="content-area">
            <?php require_once __DIR__ . '/report_modal.php'; ?>
<?php else: ?>
<div class="auth-page">
<?php endif; ?>
