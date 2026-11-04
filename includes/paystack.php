<?php

require_once __DIR__ . '/../config/app.php';

/**
 * Load Paystack configuration from config file and school_settings database table
 */
function getPaystackConfig(): array
{
    static $cachedConfig = null;
    if ($cachedConfig !== null) {
        return $cachedConfig;
    }

    $configFile = __DIR__ . '/../config/paystack.php';
    $config = file_exists($configFile) ? require $configFile : [];

    // Allow database override if configured in school_settings
    try {
        $db = getDb();
        $stmt = $db->query('SELECT paystack_public_key, paystack_secret_key, paystack_test_mode FROM school_settings ORDER BY id DESC LIMIT 1');
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            if (!empty($row['paystack_public_key'])) {
                $config['public_key'] = trim($row['paystack_public_key']);
            }
            if (!empty($row['paystack_secret_key'])) {
                $config['secret_key'] = trim($row['paystack_secret_key']);
            }
            if (isset($row['paystack_test_mode'])) {
                $config['test_mode'] = (bool) $row['paystack_test_mode'];
            }
        }
    } catch (Throwable $t) {
        // Fallback to config file if DB query fails
    }

    $cachedConfig = $config;
    return $cachedConfig;
}

/**
 * Return ONLY the public key. Safe to pass to frontend Paystack Inline popup.
 */
function paystackGetPublicKey(): string
{
    $cfg = getPaystackConfig();
    return $cfg['public_key'] ?? '';
}

/**
 * Return the secret key. Strictly kept on the server backend!
 */
function paystackGetSecretKey(): string
{
    $cfg = getPaystackConfig();
    return $cfg['secret_key'] ?? '';
}

/**
 * Check if Paystack is running in Test Mode
 */
function paystackIsTestMode(): bool
{
    $cfg = getPaystackConfig();
    return !empty($cfg['test_mode']) || str_starts_with(paystackGetPublicKey(), 'pk_test_');
}

/**
 * Verify a transaction with Paystack official API endpoint:
 * GET https://api.paystack.co/transaction/verify/{reference}
 *
 * @param string $reference Paystack transaction reference
 * @return array ['success' => bool, 'data' => array|null, 'error' => string|null]
 */
function paystackVerifyTransaction(string $reference): array
{
    $reference = trim($reference);
    if ($reference === '') {
        return ['success' => false, 'data' => null, 'error' => 'Payment reference cannot be empty.'];
    }

    $secretKey = paystackGetSecretKey();
    if (empty($secretKey) || str_contains($secretKey, 'your_paystack_secret_key_here') || str_contains($secretKey, 'PASTE_YOUR_SECRET_KEY_HERE')) {
        // In local development before real key is pasted: check if reference is a generated test reference
        if (str_starts_with($reference, 'TEST-') || str_starts_with($reference, 'DEV-')) {
            return [
                'success' => true,
                'data' => [
                    'status' => 'success',
                    'reference' => $reference,
                    'amount' => 10000, // 100 GHS in pesewas
                    'currency' => 'GHS',
                    'channel' => 'mobile_money',
                    'paid_at' => date('Y-m-d H:i:s'),
                    'gateway_response' => 'Successful (Test Simulation)',
                    'authorization' => [
                        'channel' => 'mobile_money',
                        'bank' => 'MTN Mobile Money'
                    ]
                ],
                'error' => null
            ];
        }
        return [
            'success' => false,
            'data' => null,
            'error' => 'Paystack Secret Key is not configured. Please set your secret key in Admin Settings or config/paystack.php.'
        ];
    }

    $cfg = getPaystackConfig();
    $apiUrl = rtrim($cfg['api_url'] ?? 'https://api.paystack.co', '/') . '/transaction/verify/' . rawurlencode($reference);

    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $secretKey,
        'Cache-Control: no-cache',
        'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, $cfg['timeout'] ?? 25);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $response = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlErr) {
        return ['success' => false, 'data' => null, 'error' => 'Connection to Paystack failed: ' . $curlErr];
    }

    $result = json_decode($response, true);
    if (!is_array($result) || !isset($result['status'])) {
        return ['success' => false, 'data' => null, 'error' => 'Invalid response from Paystack API (HTTP ' . $httpCode . ').'];
    }

    if (!$result['status'] || ($result['data']['status'] ?? '') !== 'success') {
        $msg = $result['message'] ?? ($result['data']['gateway_response'] ?? 'Payment verification was not successful.');
        return ['success' => false, 'data' => $result['data'] ?? null, 'error' => $msg];
    }

    return [
        'success' => true,
        'data' => $result['data'],
        'error' => null
    ];
}

/**
 * Initialize a transaction on Paystack (Server-to-Server)
 * POST https://api.paystack.co/transaction/initialize
 */
function paystackInitializeTransaction(array $params): array
{
    $secretKey = paystackGetSecretKey();
    if (empty($secretKey)) {
        return ['success' => false, 'data' => null, 'error' => 'Paystack Secret Key is not configured.'];
    }

    $cfg = getPaystackConfig();
    $apiUrl = rtrim($cfg['api_url'] ?? 'https://api.paystack.co', '/') . '/transaction/initialize';

    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $secretKey,
        'Content-Type: application/json',
        'Cache-Control: no-cache',
        'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, $cfg['timeout'] ?? 25);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $response = curl_exec($ch);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        return ['success' => false, 'data' => null, 'error' => 'Could not connect to Paystack: ' . $curlErr];
    }

    $result = json_decode($response, true);
    if (!is_array($result) || empty($result['status'])) {
        return ['success' => false, 'data' => null, 'error' => $result['message'] ?? 'Could not initialize Paystack payment.'];
    }

    return ['success' => true, 'data' => $result['data'], 'error' => null];
}

/**
 * Format Paystack channel and authorization into human-readable label
 */
function paystackFormatChannel(string $channel, ?array $auth = null): string
{
    $channel = strtolower($channel);
    $bank = $auth['bank'] ?? '';
    $brand = $auth['brand'] ?? $auth['card_type'] ?? '';

    if ($channel === 'mobile_money') {
        return !empty($bank) ? "Mobile Money ({$bank})" : 'Mobile Money (MoMo)';
    }

    if ($channel === 'card') {
        $cardBrand = !empty($brand) ? ucfirst(strtolower($brand)) : 'Bank Card';
        $last4 = !empty($auth['last4']) ? " •••• {$auth['last4']}" : '';
        return "Card ({$cardBrand}{$last4})";
    }

    if ($channel === 'bank_transfer' || $channel === 'bank') {
        return 'Bank Transfer';
    }

    return ucwords(str_replace('_', ' ', $channel));
}

/**
 * Atomically process a verified Paystack payment:
 * 1. Checks for duplicate reference in database
 * 2. Finds or creates student's open invoice
 * 3. Inserts into payments table
 * 4. Allocates payment towards unpaid fee items
 * 5. Updates invoice status if balance is settled
 * 6. Logs activity log
 * 7. Returns structured receipt data
 *
 * @param array $paystackData The 'data' array from Paystack verify response
 * @param int $studentId Target student ID
 * @param int $userId Authenticated user ID (student or parent)
 * @param string|null $customNotes Optional custom narration
 * @return array ['success' => bool, 'receipt' => array|null, 'error' => string|null]
 */
function paystackProcessVerifiedPayment(array $paystackData, int $studentId, int $userId, ?string $customNotes = null): array
{
    $db = getDb();
    $settings = getSchoolSettings();
    $currencySymbol = $settings['currency'] ?? 'GH₵';

    $reference = trim($paystackData['reference'] ?? '');
    if ($reference === '') {
        return ['success' => false, 'receipt' => null, 'error' => 'Missing transaction reference.'];
    }

    // 1. DUPLICATE CHECK: Prevent double recording of the same reference
    $dupCheck = $db->prepare('SELECT id, invoice_id, amount, payment_date FROM payments WHERE reference_number = :ref1 OR paystack_reference = :ref2 LIMIT 1');
    $dupCheck->execute(['ref1' => $reference, 'ref2' => $reference]);
    $existing = $dupCheck->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        // Return existing receipt info rather than failing, but DO NOT re-insert or double-deduct
        return [
            'success' => true,
            'is_duplicate' => true,
            'message' => 'Payment reference was already verified and recorded.',
            'payment_id' => (int)$existing['id'],
            'receipt' => paystackBuildReceiptPayload((int)$existing['id'])
        ];
    }

    // Amount from Paystack is in pesewas (subunits), convert to GHS
    $amountInPesewas = (float)($paystackData['amount'] ?? 0);
    $payAmount = round($amountInPesewas / 100, 2);

    if ($payAmount <= 0) {
        return ['success' => false, 'receipt' => null, 'error' => 'Invalid payment amount received from gateway.'];
    }

    $channel = $paystackData['channel'] ?? 'online';
    $auth = $paystackData['authorization'] ?? [];
    $methodFormatted = 'Paystack ' . paystackFormatChannel($channel, $auth);
    $gatewayResponse = $paystackData['gateway_response'] ?? 'Successful';
    $paidAt = !empty($paystackData['paid_at']) ? date('Y-m-d H:i:s', strtotime($paystackData['paid_at'])) : date('Y-m-d H:i:s');

    // Fetch student info
    $stmtStudent = $db->prepare('
        SELECT s.*, u.name as student_name, u.email as student_email, c.name as class_name
        FROM students s
        JOIN users u ON u.id = s.user_id
        LEFT JOIN classes c ON c.id = s.class_id
        WHERE s.id = :sid
        LIMIT 1
    ');
    $stmtStudent->execute(['sid' => $studentId]);
    $student = $stmtStudent->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        return ['success' => false, 'receipt' => null, 'error' => 'Target student record could not be found.'];
    }

    $db->beginTransaction();

    try {
        // 2. Find or create an open invoice for the student
        $stmtInv = $db->prepare('SELECT id FROM invoices WHERE student_id = :sid AND status != "paid" ORDER BY id DESC LIMIT 1');
        $stmtInv->execute(['sid' => $studentId]);
        $inv = $stmtInv->fetch(PDO::FETCH_ASSOC);
        $invoiceId = $inv ? (int)$inv['id'] : null;

        if (!$invoiceId) {
            $invNum = 'INV-' . date('Y') . '-' . str_pad((string)$studentId, 4, '0', STR_PAD_LEFT);
            $insInv = $db->prepare('INSERT INTO invoices (student_id, invoice_number, total_amount, status) VALUES (:sid, :inv_num, :amt, "issued")');
            $insInv->execute([
                'sid' => $studentId,
                'inv_num' => $invNum,
                'amt' => $payAmount
            ]);
            $invoiceId = (int)$db->lastInsertId();
        }

        // 3. Insert into payments table
        $notes = $customNotes ?: ("Paystack Checkout - " . $student['student_name']);
        $insPay = $db->prepare('
            INSERT INTO payments (
                invoice_id, student_id, amount, currency, status,
                payment_method, channel, payment_date,
                reference_number, paystack_reference, notes, gateway_response
            ) VALUES (
                :inv_id, :sid, :amt, "GHS", "completed",
                :method, :channel, :pay_date,
                :ref, :paystack_ref, :notes, :gw_resp
            )
        ');
        $insPay->execute([
            'inv_id' => $invoiceId,
            'sid' => $studentId,
            'amt' => $payAmount,
            'method' => $methodFormatted,
            'channel' => $channel,
            'pay_date' => $paidAt,
            'ref' => $reference,
            'paystack_ref' => $reference,
            'notes' => $notes,
            'gw_resp' => $gatewayResponse
        ]);
        $paymentId = (int)$db->lastInsertId();

        // 4. Allocate payment against unpaid fee line items in chronological order
        $stmtFees = $db->prepare('SELECT id, amount, status FROM fees WHERE student_id = :sid AND status != "paid" ORDER BY due_date ASC, id ASC');
        $stmtFees->execute(['sid' => $studentId]);
        $unpaidFees = $stmtFees->fetchAll(PDO::FETCH_ASSOC);

        $runningCredit = $payAmount;
        foreach ($unpaidFees as $uf) {
            $feeAmt = (float)$uf['amount'];
            if ($runningCredit >= $feeAmt) {
                $db->prepare('UPDATE fees SET status = "paid" WHERE id = :id')->execute(['id' => $uf['id']]);
                $runningCredit -= $feeAmt;
            } elseif ($runningCredit > 0) {
                $db->prepare('UPDATE fees SET status = "partial" WHERE id = :id')->execute(['id' => $uf['id']]);
                $runningCredit = 0;
            }
        }

        // 5. Check overall student balance to update invoice status
        $totBilled = (float)$db->query("SELECT COALESCE(SUM(amount), 0) FROM fees WHERE student_id = {$studentId}")->fetchColumn();
        $totPaid = (float)$db->query("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE student_id = {$studentId} AND status = 'completed'")->fetchColumn();
        $newBalance = max(0, $totBilled - $totPaid);

        if ($newBalance <= 0) {
            $db->prepare('UPDATE invoices SET status = "paid" WHERE id = :id')->execute(['id' => $invoiceId]);
        }

        // 6. Log system activity
        $db->prepare('INSERT INTO activity_logs (user_id, action, details) VALUES (:uid, "Paystack Payment Verified", :details)')->execute([
            'uid' => $userId,
            'details' => "Verified Paystack payment of {$currencySymbol} " . number_format($payAmount, 2) . " for student {$student['student_name']} (Ref: {$reference})"
        ]);

        $db->commit();

        $receipt = [
            'payment_id' => $paymentId,
            'reference' => $reference,
            'amount' => $payAmount,
            'currency' => $currencySymbol,
            'method' => $methodFormatted,
            'channel' => $channel,
            'date' => date('d M Y, h:i A', strtotime($paidAt)),
            'student_name' => $student['student_name'],
            'student_code' => $student['student_id'] ?? 'N/A',
            'class_name' => $student['class_name'] ?? 'N/A',
            'balance' => $newBalance,
            'status' => 'Completed',
            'school_name' => $settings['school_name'] ?? 'CampusIQ',
            'school_logo' => $settings['school_logo'] ?? '',
        ];

        return [
            'success' => true,
            'is_duplicate' => false,
            'payment_id' => $paymentId,
            'receipt' => $receipt,
            'error' => null
        ];
    } catch (Throwable $e) {
        $db->rollBack();
        return [
            'success' => false,
            'receipt' => null,
            'error' => 'Database error while saving verified payment: ' . $e->getMessage()
        ];
    }
}

/**
 * Build receipt data payload from an existing payment ID
 */
function paystackBuildReceiptPayload(int $paymentId): ?array
{
    $db = getDb();
    $settings = getSchoolSettings();
    $currencySymbol = $settings['currency'] ?? 'GH₵';

    $stmt = $db->prepare('
        SELECT p.*, s.student_id as student_code, u.name as student_name, c.name as class_name
        FROM payments p
        JOIN students s ON s.id = p.student_id
        JOIN users u ON u.id = s.user_id
        LEFT JOIN classes c ON c.id = s.class_id
        WHERE p.id = :pid
        LIMIT 1
    ');
    $stmt->execute(['pid' => $paymentId]);
    $pay = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$pay) {
        return null;
    }

    $sid = (int)$pay['student_id'];
    $totBilled = (float)$db->query("SELECT COALESCE(SUM(amount), 0) FROM fees WHERE student_id = {$sid}")->fetchColumn();
    $totPaid = (float)$db->query("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE student_id = {$sid} AND status = 'completed'")->fetchColumn();
    $balance = max(0, $totBilled - $totPaid);

    return [
        'payment_id' => (int)$pay['id'],
        'reference' => $pay['reference_number'] ?: ($pay['paystack_reference'] ?: 'N/A'),
        'amount' => (float)$pay['amount'],
        'currency' => $currencySymbol,
        'method' => $pay['payment_method'] ?? 'Online Payment',
        'channel' => $pay['channel'] ?? 'card',
        'date' => date('d M Y, h:i A', strtotime($pay['payment_date'])),
        'student_name' => $pay['student_name'],
        'student_code' => $pay['student_code'],
        'class_name' => $pay['class_name'] ?? 'N/A',
        'balance' => $balance,
        'status' => ucfirst($pay['status'] ?? 'Completed'),
        'school_name' => $settings['school_name'] ?? 'CampusIQ',
        'school_logo' => $settings['school_logo'] ?? '',
    ];
}
