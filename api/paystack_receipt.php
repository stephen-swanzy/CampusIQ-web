<?php

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/paystack.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required.']);
    exit;
}

$paymentId = (int)($_GET['payment_id'] ?? $_POST['payment_id'] ?? 0);
if ($paymentId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Payment ID is required.']);
    exit;
}

$user = currentUser();
$userRole = $user['role'] ?? '';
$db = getDb();

// Fetch payment to check ownership
$payStmt = $db->prepare('SELECT p.*, s.user_id as student_user_id, s.parent_id FROM payments p JOIN students s ON s.id = p.student_id WHERE p.id = :pid LIMIT 1');
$payStmt->execute(['pid' => $paymentId]);
$payRow = $payStmt->fetch(PDO::FETCH_ASSOC);

if (!$payRow) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Payment receipt not found.']);
    exit;
}

// Ownership check
if ($userRole === 'student') {
    if ((int)$payRow['student_user_id'] !== (int)$user['id']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied to this receipt.']);
        exit;
    }
} elseif ($userRole === 'parent') {
    $parentStmt = $db->prepare('SELECT id FROM parents WHERE user_id = :uid LIMIT 1');
    $parentStmt->execute(['uid' => $user['id']]);
    $myParentId = (int)$parentStmt->fetchColumn();

    if ((int)$payRow['parent_id'] !== $myParentId && (int)$payRow['parent_id'] !== (int)$user['id']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Access denied to this receipt.']);
        exit;
    }
}

$receipt = paystackBuildReceiptPayload($paymentId);
if (!$receipt) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Unable to generate receipt payload.']);
    exit;
}

echo json_encode([
    'success' => true,
    'receipt' => $receipt
]);
