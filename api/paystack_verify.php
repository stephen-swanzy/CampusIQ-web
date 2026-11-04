<?php

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/paystack.php';

header('Content-Type: application/json; charset=utf-8');

// Require login (students, parents, accountants, admins)
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'You must be signed in to perform this action.']);
    exit;
}

$user = currentUser();
$userRole = $user['role'] ?? '';
$db = getDb();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use POST.']);
    exit;
}

// Read JSON or POST body
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$reference = trim($input['reference'] ?? '');
$targetStudentId = (int)($input['student_id'] ?? 0);
$customNotes = trim($input['notes'] ?? '');

if (empty($reference)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Payment reference is required.']);
    exit;
}

// Role-based authorization on target student
if ($userRole === 'student') {
    $stmt = $db->prepare('SELECT id FROM students WHERE user_id = :uid LIMIT 1');
    $stmt->execute(['uid' => $user['id']]);
    $myStudentId = (int)$stmt->fetchColumn();

    if ($targetStudentId > 0 && $targetStudentId !== $myStudentId) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Unauthorized student target.']);
        exit;
    }
    $targetStudentId = $myStudentId;
} elseif ($userRole === 'parent') {
    $stmtParent = $db->prepare('SELECT id FROM parents WHERE user_id = :uid LIMIT 1');
    $stmtParent->execute(['uid' => $user['id']]);
    $parentId = (int)$stmtParent->fetchColumn();

    // Check if target student belongs to this parent
    $childCheck = $db->prepare('SELECT id FROM students WHERE id = :sid AND (parent_id = :pid OR parent_id = :puid) LIMIT 1');
    $childCheck->execute([
        'sid' => $targetStudentId,
        'pid' => $parentId,
        'puid' => $user['id']
    ]);
    if (!$childCheck->fetch()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Selected student is not associated with your parent account.']);
        exit;
    }
} elseif (!in_array($userRole, ['accountant', 'school_admin', 'super_admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized portal role.']);
    exit;
}

if ($targetStudentId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Student record could not be resolved.']);
    exit;
}

// 1. Verify transaction with Paystack official API
$verifyRes = paystackVerifyTransaction($reference);

if (!$verifyRes['success']) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $verifyRes['error'] ?: 'Payment verification failed with gateway.'
    ]);
    exit;
}

$paystackData = $verifyRes['data'];

// 2. Process and record the verified transaction in database
$processRes = paystackProcessVerifiedPayment($paystackData, $targetStudentId, (int)$user['id'], $customNotes);

if (!$processRes['success']) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $processRes['error'] ?: 'Unable to record payment in school ledger.'
    ]);
    exit;
}

// 3. Return success and complete receipt payload
echo json_encode([
    'success' => true,
    'message' => !empty($processRes['is_duplicate'])
        ? 'Payment already recorded previously.'
        : 'Payment successfully verified and recorded!',
    'is_duplicate' => !empty($processRes['is_duplicate']),
    'payment_id' => $processRes['payment_id'] ?? null,
    'receipt' => $processRes['receipt'] ?? null
]);
