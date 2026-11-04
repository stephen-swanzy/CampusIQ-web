<?php

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/paystack.php';

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$input = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';
$secretKey = paystackGetSecretKey();

if (empty($secretKey)) {
    http_response_code(400);
    exit('Paystack secret key not configured.');
}

// Validate HMAC SHA512 signature
$expectedSignature = hash_hmac('sha512', $input, $secretKey);
if (!hash_equals($expectedSignature, $signature)) {
    http_response_code(401);
    exit('Invalid signature.');
}

$event = json_decode($input, true);
if (!is_array($event) || empty($event['event'])) {
    http_response_code(400);
    exit('Invalid event payload.');
}

// Process charge.success event
if ($event['event'] === 'charge.success') {
    $data = $event['data'] ?? [];
    $studentId = (int)($data['metadata']['student_id'] ?? 0);
    $userId = (int)($data['metadata']['user_id'] ?? 0);

    if ($studentId > 0 && !empty($data['reference'])) {
        paystackProcessVerifiedPayment($data, $studentId, $userId, 'Paystack Webhook Confirmation');
    }
}

http_response_code(200);
echo json_encode(['status' => 'success']);
