<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';

// Ensure user is logged in
if (!isLoggedIn()) {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'You must be logged in to submit a system report.']);
        exit;
    }
    redirect('/login.php');
}

$user = currentUser();
$db = getDb();
$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($isAjax) {
        header('Content-Type: application/json');
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
        exit;
    }
    redirect('/index.php');
}

// Verify CSRF
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    if ($isAjax) {
        header('Content-Type: application/json');
        http_response_code(419);
        echo json_encode(['success' => false, 'error' => 'Security token expired. Please refresh the page and try again.']);
        exit;
    }
    $_SESSION['system_report_error'] = 'Security token expired. Please try again.';
    redirect($_SERVER['HTTP_REFERER'] ?? '/index.php');
}

$reportType = trim($_POST['report_type'] ?? 'bug');
$validTypes = ['bug', 'feature_request', 'usability', 'performance', 'general'];
if (!in_array($reportType, $validTypes, true)) {
    $reportType = 'bug';
}

$priority = trim($_POST['priority'] ?? 'medium');
$validPriorities = ['low', 'medium', 'high', 'critical'];
if (!in_array($priority, $validPriorities, true)) {
    $priority = 'medium';
}

$subject = trim($_POST['subject'] ?? '');
$message = trim($_POST['message'] ?? '');
$pageUrl = trim($_POST['page_url'] ?? '');

if (empty($subject) || mb_strlen($subject) < 3) {
    $err = 'Please provide a clear title/subject (at least 3 characters).';
    if ($isAjax) {
        header('Content-Type: application/json');
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => $err]);
        exit;
    }
    $_SESSION['system_report_error'] = $err;
    redirect($_SERVER['HTTP_REFERER'] ?? '/index.php');
}

if (empty($message) || mb_strlen($message) < 5) {
    $err = 'Please provide details or a description of the issue/suggestion (at least 5 characters).';
    if ($isAjax) {
        header('Content-Type: application/json');
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => $err]);
        exit;
    }
    $_SESSION['system_report_error'] = $err;
    redirect($_SERVER['HTTP_REFERER'] ?? '/index.php');
}

try {
    $stmt = $db->prepare('INSERT INTO system_reports 
        (user_id, user_name, user_role, user_email, report_type, subject, message, priority, page_url, status) 
        VALUES (:user_id, :user_name, :user_role, :user_email, :report_type, :subject, :message, :priority, :page_url, "pending")');
    
    $stmt->execute([
        'user_id' => $user['id'] ?? null,
        'user_name' => $user['name'] ?? 'User',
        'user_role' => $user['role'] ?? 'user',
        'user_email' => $user['email'] ?? null,
        'report_type' => $reportType,
        'subject' => mb_substr($subject, 0, 255),
        'message' => $message,
        'priority' => $priority,
        'page_url' => mb_substr($pageUrl, 0, 500),
    ]);

    $reportId = (int)$db->lastInsertId();

    // Log in activity logs
    if (!empty($user['id'])) {
        $db->prepare('INSERT INTO activity_logs (user_id, action, details) VALUES (:uid, "System Concern Reported", :details)')
            ->execute([
                'uid' => $user['id'],
                'details' => "Report #{$reportId} ({$reportType}): {$subject}"
            ]);
    }

    $successMsg = 'Thank you! Your feedback/concern has been recorded and submitted to the Super Administrator.';

    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'report_id' => $reportId,
            'message' => $successMsg
        ]);
        exit;
    }

    $_SESSION['system_report_success'] = $successMsg;
    redirect($_SERVER['HTTP_REFERER'] ?? '/index.php');

} catch (Throwable $e) {
    error_log('Failed to save system report: ' . $e->getMessage());
    $err = 'Could not save your report due to a server error. Please try again.';
    if ($isAjax) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $err]);
        exit;
    }
    $_SESSION['system_report_error'] = $err;
    redirect($_SERVER['HTTP_REFERER'] ?? '/index.php');
}
