<?php

function getMailConfig(): array
{
    static $config = null;
    if ($config === null) {
        $configFile = __DIR__ . '/../config/mail.php';
        $config = file_exists($configFile) ? require $configFile : [];
    }
    return $config;
}

/**
 * Send an email via SMTP or fallback to PHP mail()
 *
 * @param string $toEmail Recipient email address
 * @param string $toName Recipient display name
 * @param string $subject Email subject
 * @param string $htmlBody HTML content
 * @param string $textBody Plain-text content
 * @return array ['success' => bool, 'error' => string]
 */
function sendCampusEmail(string $toEmail, string $toName, string $subject, string $htmlBody, string $textBody = ''): array
{
    $config = getMailConfig();
    $driver = strtolower($config['driver'] ?? 'auto');
    $smtp = $config['smtp'] ?? [];
    $from = $config['from'] ?? [];
    $brevoKey = trim($config['brevo']['api_key'] ?? '');
    $resendKey = trim($config['resend']['api_key'] ?? '');

    $fromEmail = !empty($from['address']) ? $from['address'] : 'no-reply@campusiq.edu.gh';
    $fromName = !empty($from['name']) ? $from['name'] : 'CampusIQ';

    if (empty($textBody)) {
        $textBody = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $htmlBody));
    }

    // 1. Explicit Brevo API
    if ($driver === 'brevo' && !empty($brevoKey)) {
        return sendViaBrevoApi($toEmail, $toName, $subject, $htmlBody, $textBody, $brevoKey, $fromEmail, $fromName);
    }

    // 2. Explicit Resend API
    if ($driver === 'resend' && !empty($resendKey)) {
        return sendViaResendApi($toEmail, $toName, $subject, $htmlBody, $resendKey, $fromEmail, $fromName);
    }

    // 3. Auto Mode: Fast-path for hosts that block SMTP (e.g. InfinityFree)
    $isInfinityFree = !empty($_SERVER['HTTP_HOST']) && (
        stripos($_SERVER['HTTP_HOST'], 'infinityfree') !== false ||
        stripos($_SERVER['HTTP_HOST'], 'epizy') !== false ||
        stripos($_SERVER['HTTP_HOST'], 'rf.gd') !== false
    );

    if (($driver === 'auto' || $driver === 'brevo') && $isInfinityFree && !empty($brevoKey)) {
        return sendViaBrevoApi($toEmail, $toName, $subject, $htmlBody, $textBody, $brevoKey, $fromEmail, $fromName);
    }

    if (($driver === 'auto' || $driver === 'resend') && $isInfinityFree && !empty($resendKey)) {
        return sendViaResendApi($toEmail, $toName, $subject, $htmlBody, $resendKey, $fromEmail, $fromName);
    }

    // 4. Try SMTP
    if (!empty($smtp['host']) && !empty($smtp['username']) && !empty($smtp['password'])) {
        $smtpResult = sendViaSmtp($toEmail, $toName, $subject, $htmlBody, $textBody, $smtp, $fromEmail, $fromName);
        if ($smtpResult['success']) {
            return $smtpResult;
        }

        // Auto-fallback: if SMTP failed (e.g. firewall port block), try Brevo or Resend if available
        if (!empty($brevoKey)) {
            $brevoResult = sendViaBrevoApi($toEmail, $toName, $subject, $htmlBody, $textBody, $brevoKey, $fromEmail, $fromName);
            if ($brevoResult['success']) {
                return $brevoResult;
            }
        }
        if (!empty($resendKey)) {
            $resendResult = sendViaResendApi($toEmail, $toName, $subject, $htmlBody, $resendKey, $fromEmail, $fromName);
            if ($resendResult['success']) {
                return $resendResult;
            }
        }

        return $smtpResult;
    }

    // 5. If SMTP not configured, try API directly
    if (!empty($brevoKey)) {
        return sendViaBrevoApi($toEmail, $toName, $subject, $htmlBody, $textBody, $brevoKey, $fromEmail, $fromName);
    }
    if (!empty($resendKey)) {
        return sendViaResendApi($toEmail, $toName, $subject, $htmlBody, $resendKey, $fromEmail, $fromName);
    }

    // Fallback to PHP mail()
    $boundary = '=_campus_' . md5(uniqid((string) time(), true));
    $headers = [
        'MIME-Version: 1.0',
        'From: ' . encodeMimeHeader($fromName) . ' <' . $fromEmail . '>',
        'Reply-To: ' . $fromEmail,
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        'X-Mailer: CampusIQ/1.0',
    ];

    $body = "--{$boundary}\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n\r\n"
        . $textBody . "\r\n\r\n"
        . "--{$boundary}\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n\r\n"
        . $htmlBody . "\r\n\r\n"
        . "--{$boundary}--";

    $sent = @mail($toEmail, encodeMimeHeader($subject), $body, implode("\r\n", $headers));
    if ($sent) {
        return ['success' => true, 'error' => ''];
    }

    return [
        'success' => false,
        'error' => 'SMTP credentials not configured and local mail() transport failed.',
    ];
}

/**
 * Pure PHP SMTP Client over TLS/SSL
 */
function sendViaSmtp(
    string $toEmail,
    string $toName,
    string $subject,
    string $htmlBody,
    string $textBody,
    array $smtp,
    string $fromEmail,
    string $fromName,
    ?string $attachmentPath = null,
    ?string $attachmentFilename = null
): array {
    $host = $smtp['host'] ?? 'smtp.gmail.com';
    $primaryPort = (int) ($smtp['port'] ?? 465);
    $primaryEnc = strtolower($smtp['encryption'] ?? 'ssl');
    $username = trim($smtp['username'] ?? '');
    $password = str_replace(' ', '', trim($smtp['password'] ?? ''));
    $timeout = (int) ($smtp['timeout'] ?? 15);

    // Prepare connection configurations to try: primary first, then fallback
    $attempts = [];
    $attempts[] = ['port' => $primaryPort, 'encryption' => $primaryEnc];
    if ($primaryPort === 465 || $primaryEnc === 'ssl') {
        $attempts[] = ['port' => 587, 'encryption' => 'tls'];
    } else {
        $attempts[] = ['port' => 465, 'encryption' => 'ssl'];
    }

    $lastError = 'Unknown error';

    foreach ($attempts as $attempt) {
        $port = $attempt['port'];
        $encryption = $attempt['encryption'];

        $streamOptions = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ]
        ];
        if (file_exists('C:\\xampp\\apache\\bin\\curl-ca-bundle.crt')) {
            $streamOptions['ssl']['cafile'] = 'C:\\xampp\\apache\\bin\\curl-ca-bundle.crt';
        }
        $context = stream_context_create($streamOptions);

        $protocol = ($encryption === 'ssl') ? 'ssl://' : 'tcp://';
        $socket = @stream_socket_client(
            $protocol . $host . ':' . $port,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!$socket) {
            $lastError = "Could not connect to {$host}:{$port}: {$errstr} ({$errno})";
            continue;
        }

        stream_set_timeout($socket, $timeout);

        $read = function () use ($socket) {
            $data = '';
            while (!feof($socket)) {
                $line = fgets($socket, 515);
                if ($line === false) {
                    break;
                }
                $data .= $line;
                if (isset($line[3]) && $line[3] === ' ') {
                    break;
                }
            }
            return $data;
        };

        $write = function (string $cmd) use ($socket) {
            fwrite($socket, $cmd . "\r\n");
        };

        $initial = $read();
        if (substr($initial, 0, 3) !== '220') {
            fclose($socket);
            $lastError = 'Invalid greeting from SMTP: ' . trim($initial);
            continue;
        }

        $ehloHost = !empty($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'localhost';
        $write('EHLO ' . $ehloHost);
        $read();

        if ($encryption === 'tls' && $port !== 465) {
            $write('STARTTLS');
            $tlsResp = $read();
            if (substr($tlsResp, 0, 3) !== '220') {
                fclose($socket);
                $lastError = 'STARTTLS failed: ' . trim($tlsResp);
                continue;
            }

            $cryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT
                | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
                | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;

            if (!@stream_socket_enable_crypto($socket, true, $cryptoMethod)) {
                fclose($socket);
                $lastError = 'TLS encryption handshake failed with ' . $host;
                continue;
            }

            $write('EHLO ' . $ehloHost);
            $read();
        }

        // Authenticate
        $write('AUTH LOGIN');
        $authResp = $read();
        if (substr($authResp, 0, 3) !== '334') {
            fclose($socket);
            $lastError = 'AUTH LOGIN error: ' . trim($authResp);
            continue;
        }

        $write(base64_encode($username));
        $userResp = $read();
        if (substr($userResp, 0, 3) !== '334') {
            fclose($socket);
            $lastError = 'SMTP Username rejected: ' . trim($userResp);
            continue;
        }

        $write(base64_encode($password));
        $passResp = $read();
        if (substr($passResp, 0, 3) !== '235') {
            fclose($socket);
            $lastError = 'SMTP Password authentication failed: ' . trim($passResp);
            continue;
        }

        // Sender and recipient
        $write("MAIL FROM:<{$fromEmail}>");
        $mailFromResp = $read();
        if (substr($mailFromResp, 0, 3) !== '250') {
            fclose($socket);
            $lastError = 'MAIL FROM rejected: ' . trim($mailFromResp);
            continue;
        }

        $write("RCPT TO:<{$toEmail}>");
        $rcptResp = $read();
        if (substr($rcptResp, 0, 3) !== '250') {
            fclose($socket);
            $lastError = 'RCPT TO rejected: ' . trim($rcptResp);
            continue;
        }

        $write('DATA');
        $dataResp = $read();
        if (substr($dataResp, 0, 3) !== '354') {
            fclose($socket);
            $lastError = 'DATA command rejected: ' . trim($dataResp);
            continue;
        }

        $hasAttachment = !empty($attachmentPath) && file_exists($attachmentPath);

        if ($hasAttachment) {
            $mixedBoundary = '=_campus_mix_' . md5(uniqid((string) time(), true));
            $altBoundary = '=_campus_alt_' . md5(uniqid((string) time(), true));
            $attachName = $attachmentFilename ?: basename($attachmentPath);
            $fileBytes = file_get_contents($attachmentPath);
            $base64Data = chunk_split(base64_encode($fileBytes));

            $messageHeaders = [
                'Date: ' . date('r'),
                'To: ' . encodeMimeHeader($toName) . " <{$toEmail}>",
                'From: ' . encodeMimeHeader($fromName) . " <{$fromEmail}>",
                'Subject: ' . encodeMimeHeader($subject),
                'MIME-Version: 1.0',
                'Content-Type: multipart/mixed; boundary="' . $mixedBoundary . '"',
                'X-Mailer: CampusIQ SMTP/1.0',
            ];

            $payload = implode("\r\n", $messageHeaders) . "\r\n\r\n"
                . "--{$mixedBoundary}\r\n"
                . "Content-Type: multipart/alternative; boundary=\"{$altBoundary}\"\r\n\r\n"
                . "--{$altBoundary}\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: 8bit\r\n\r\n"
                . $textBody . "\r\n\r\n"
                . "--{$altBoundary}\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: 8bit\r\n\r\n"
                . $htmlBody . "\r\n\r\n"
                . "--{$altBoundary}--\r\n\r\n"
                . "--{$mixedBoundary}\r\n"
                . "Content-Type: application/octet-stream; name=\"" . encodeMimeHeader($attachName) . "\"\r\n"
                . "Content-Disposition: attachment; filename=\"" . encodeMimeHeader($attachName) . "\"\r\n"
                . "Content-Transfer-Encoding: base64\r\n\r\n"
                . $base64Data . "\r\n"
                . "--{$mixedBoundary}--\r\n.";
        } else {
            $boundary = '=_campus_' . md5(uniqid((string) time(), true));
            $messageHeaders = [
                'Date: ' . date('r'),
                'To: ' . encodeMimeHeader($toName) . " <{$toEmail}>",
                'From: ' . encodeMimeHeader($fromName) . " <{$fromEmail}>",
                'Subject: ' . encodeMimeHeader($subject),
                'MIME-Version: 1.0',
                'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
                'X-Mailer: CampusIQ SMTP/1.0',
            ];

            $payload = implode("\r\n", $messageHeaders) . "\r\n\r\n"
                . "--{$boundary}\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: 8bit\r\n\r\n"
                . $textBody . "\r\n\r\n"
                . "--{$boundary}\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: 8bit\r\n\r\n"
                . $htmlBody . "\r\n\r\n"
                . "--{$boundary}--\r\n.";
        }

        $write($payload);
        $sendResp = $read();
        $write('QUIT');
        fclose($socket);

        if (substr($sendResp, 0, 3) === '250') {
            return ['success' => true, 'error' => ''];
        }

        $lastError = 'Message rejected after DATA: ' . trim($sendResp);
    }

    return ['success' => false, 'error' => $lastError];
}

function sendCampusEmailWithAttachment(
    string $toEmail,
    string $toName,
    string $subject,
    string $htmlBody,
    string $attachmentPath,
    string $attachmentFilename,
    string $textBody = ''
): array {
    $config = getMailConfig();
    $driver = strtolower($config['driver'] ?? 'auto');
    $smtp = $config['smtp'] ?? [];
    $from = $config['from'] ?? [];
    $brevoKey = trim($config['brevo']['api_key'] ?? '');
    $resendKey = trim($config['resend']['api_key'] ?? '');

    $fromEmail = !empty($from['address']) ? $from['address'] : 'no-reply@campusiq.edu.gh';
    $fromName = !empty($from['name']) ? $from['name'] : 'CampusIQ';

    if (empty($textBody)) {
        $textBody = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $htmlBody));
    }

    // 1. Explicit Brevo API
    if ($driver === 'brevo' && !empty($brevoKey)) {
        return sendViaBrevoApi($toEmail, $toName, $subject, $htmlBody, $textBody, $brevoKey, $fromEmail, $fromName, $attachmentPath, $attachmentFilename);
    }

    // 2. Explicit Resend API
    if ($driver === 'resend' && !empty($resendKey)) {
        return sendViaResendApi($toEmail, $toName, $subject, $htmlBody, $resendKey, $fromEmail, $fromName, $attachmentPath, $attachmentFilename);
    }

    // 3. Fast-path on InfinityFree
    $isInfinityFree = !empty($_SERVER['HTTP_HOST']) && (
        stripos($_SERVER['HTTP_HOST'], 'infinityfree') !== false ||
        stripos($_SERVER['HTTP_HOST'], 'epizy') !== false ||
        stripos($_SERVER['HTTP_HOST'], 'rf.gd') !== false
    );

    if (($driver === 'auto' || $driver === 'brevo') && $isInfinityFree && !empty($brevoKey)) {
        return sendViaBrevoApi($toEmail, $toName, $subject, $htmlBody, $textBody, $brevoKey, $fromEmail, $fromName, $attachmentPath, $attachmentFilename);
    }

    // 4. Try SMTP
    if (!empty($smtp['host']) && !empty($smtp['username']) && !empty($smtp['password'])) {
        $smtpResult = sendViaSmtp($toEmail, $toName, $subject, $htmlBody, $textBody, $smtp, $fromEmail, $fromName, $attachmentPath, $attachmentFilename);
        if ($smtpResult['success']) {
            return $smtpResult;
        }

        if (!empty($brevoKey)) {
            $brevoResult = sendViaBrevoApi($toEmail, $toName, $subject, $htmlBody, $textBody, $brevoKey, $fromEmail, $fromName, $attachmentPath, $attachmentFilename);
            if ($brevoResult['success']) {
                return $brevoResult;
            }
        }
        if (!empty($resendKey)) {
            $resendResult = sendViaResendApi($toEmail, $toName, $subject, $htmlBody, $resendKey, $fromEmail, $fromName, $attachmentPath, $attachmentFilename);
            if ($resendResult['success']) {
                return $resendResult;
            }
        }

        return $smtpResult;
    }

    if (!empty($brevoKey)) {
        return sendViaBrevoApi($toEmail, $toName, $subject, $htmlBody, $textBody, $brevoKey, $fromEmail, $fromName, $attachmentPath, $attachmentFilename);
    }

    return ['success' => false, 'error' => 'SMTP or Brevo/Resend API credentials required for file attachments.'];
}

/**
 * Send email via Brevo (Sendinblue) Transactional REST API over HTTPS (Port 443)
 * Completely bypasses shared hosting (InfinityFree) outbound SMTP port blocks!
 */
function sendViaBrevoApi(
    string $toEmail,
    string $toName,
    string $subject,
    string $htmlBody,
    string $textBody,
    string $apiKey,
    string $fromEmail,
    string $fromName,
    ?string $attachmentPath = null,
    ?string $attachmentFilename = null
): array {
    $payload = [
        'sender' => [
            'name' => $fromName ?: 'CampusIQ',
            'email' => $fromEmail,
        ],
        'to' => [
            [
                'name' => $toName ?: $toEmail,
                'email' => $toEmail,
            ]
        ],
        'subject' => $subject,
        'htmlContent' => $htmlBody,
    ];

    if (!empty($textBody)) {
        $payload['textContent'] = $textBody;
    }

    if (!empty($attachmentPath) && file_exists($attachmentPath)) {
        $fileBytes = file_get_contents($attachmentPath);
        if ($fileBytes !== false) {
            $payload['attachment'] = [
                [
                    'name' => $attachmentFilename ?: basename($attachmentPath),
                    'content' => base64_encode($fileBytes),
                ]
            ];
        }
    }

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'accept: application/json',
            'api-key: ' . trim($apiKey),
            'content-type: application/json',
        ],
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['success' => false, 'error' => 'Brevo API connection failed: ' . $curlError];
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        return ['success' => true, 'error' => ''];
    }

    $respData = json_decode((string) $response, true);
    $errorMsg = $respData['message'] ?? ("Brevo API error (HTTP {$httpCode}): " . $response);
    return ['success' => false, 'error' => $errorMsg];
}

/**
 * Send email via Resend Transactional REST API over HTTPS (Port 443)
 */
function sendViaResendApi(
    string $toEmail,
    string $toName,
    string $subject,
    string $htmlBody,
    string $apiKey,
    string $fromEmail,
    string $fromName,
    ?string $attachmentPath = null,
    ?string $attachmentFilename = null
): array {
    $fromHeader = !empty($fromName) ? "{$fromName} <{$fromEmail}>" : $fromEmail;
    $payload = [
        'from' => $fromHeader,
        'to' => [$toEmail],
        'subject' => $subject,
        'html' => $htmlBody,
    ];

    if (!empty($attachmentPath) && file_exists($attachmentPath)) {
        $fileBytes = file_get_contents($attachmentPath);
        if ($fileBytes !== false) {
            $payload['attachments'] = [
                [
                    'filename' => $attachmentFilename ?: basename($attachmentPath),
                    'content' => base64_encode($fileBytes),
                ]
            ];
        }
    }

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . trim($apiKey),
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['success' => false, 'error' => 'Resend API connection failed: ' . $curlError];
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        return ['success' => true, 'error' => ''];
    }

    $respData = json_decode((string) $response, true);
    $errorMsg = $respData['message'] ?? ("Resend API error (HTTP {$httpCode}): " . $response);
    return ['success' => false, 'error' => $errorMsg];
}

function encodeMimeHeader(string $text): string
{
    if (preg_match('/[^\x20-\x7E]/', $text)) {
        return '=?UTF-8?B?' . base64_encode($text) . '?=';
    }
    return $text;
}

/**
 * Generate a beautifully styled CampusIQ HTML email template for OTP
 */
function buildOtpEmailHtml(string $name, string $otpCode, int $expiresMinutes = 15, string $purpose = 'Sign-In Verification'): string
{
    $isReset = stripos($purpose, 'reset') !== false;
    $heading = $isReset ? 'Password Reset Verification' : 'Sign-In Verification Code';
    $message = $isReset
        ? 'We received a request to reset your password. Use the verification code below to complete your request:'
        : 'A sign-in attempt was made for your CampusIQ account. Use the 6-digit verification code below to sign in:';

    return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>CampusIQ Verification Code</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f6f9; font-family: 'Segoe UI', Arial, sans-serif; color: #333333;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #f4f6f9; padding: 40px 10px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" style="max-width: 540px; background-color: #ffffff; border-radius: 12px; box-shadow: 0 4px 16px rgba(0,0,0,0.06); overflow: hidden;">
                    <!-- Header -->
                    <tr>
                        <td style="background: linear-gradient(135deg, #1e3a8a, #2563eb); padding: 32px 30px; text-align: center;">
                            <h1 style="color: #ffffff; margin: 0; font-size: 24px; font-weight: 700; letter-spacing: 0.5px;">CampusIQ</h1>
                            <p style="color: #bfdbfe; margin: 6px 0 0 0; font-size: 14px;">School Management System</p>
                        </td>
                    </tr>
                    <!-- Content -->
                    <tr>
                        <td style="padding: 35px 35px 25px 35px;">
                            <h2 style="margin: 0 0 16px 0; font-size: 20px; color: #1e293b;">{$heading}</h2>
                            <p style="font-size: 15px; line-height: 1.6; color: #475569; margin: 0 0 24px 0;">
                                Hello <strong>{$name}</strong>,<br>
                                {$message}
                            </p>
                            <!-- OTP Box -->
                            <div style="background-color: #f8fafc; border: 2px dashed #cbd5e1; border-radius: 10px; padding: 20px; text-align: center; margin: 0 0 24px 0;">
                                <div style="font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; color: #64748b; margin-bottom: 8px;">Your 6-Digit Code</div>
                                <div style="font-size: 34px; font-weight: 800; letter-spacing: 8px; color: #2563eb; font-family: monospace;">{$otpCode}</div>
                            </div>
                            <p style="font-size: 13px; color: #64748b; line-height: 1.5; margin: 0 0 20px 0;">
                                This code is valid for <strong>{$expiresMinutes} minutes</strong>. If you did not make this request, please change your password or contact your school administrator immediately.
                            </p>
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #f8fafc; border-top: 1px solid #e2e8f0; padding: 20px 30px; text-align: center; font-size: 12px; color: #94a3b8;">
                            &copy; CampusIQ &bull; Secure School Management Platform
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
}

/**
 * Generate a beautifully styled HTML email template for account request approval
 */
function buildAccountApprovedEmailHtml(string $name, string $role, string $activationUrl, string $schoolName = 'CampusIQ', int $expiresHours = 72): string
{
    $roleTitle = ucwords(str_replace('_', ' ', $role));
    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $safeSchool = htmlspecialchars($schoolName, ENT_QUOTES, 'UTF-8');
    $safeUrl = htmlspecialchars($activationUrl, ENT_QUOTES, 'UTF-8');

    return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Account Request Approved — {$safeSchool}</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f6f9; font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, Roboto, Helvetica, Arial, sans-serif; color: #1e293b; line-height: 1.6;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #f4f6f9; padding: 40px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" style="max-width: 560px; background-color: #ffffff; border-radius: 14px; box-shadow: 0 10px 25px rgba(15,23,42,0.08); overflow: hidden; border: 1px solid #e2e8f0;">
                    <!-- Header -->
                    <tr>
                        <td style="background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%); padding: 36px 32px; text-align: center;">
                            <div style="display: inline-block; background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.25); border-radius: 50px; padding: 6px 16px; margin-bottom: 14px;">
                                <span style="color: #ffffff; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px;">Access Granted</span>
                            </div>
                            <h1 style="color: #ffffff; margin: 0; font-size: 26px; font-weight: 700; letter-spacing: -0.5px;">{$safeSchool}</h1>
                            <p style="color: #bfdbfe; margin: 6px 0 0 0; font-size: 14px; font-weight: 500;">School Management System</p>
                        </td>
                    </tr>
                    <!-- Main Body -->
                    <tr>
                        <td style="padding: 36px 32px 28px 32px;">
                            <h2 style="margin: 0 0 16px 0; font-size: 20px; font-weight: 700; color: #0f172a;">Your Access Request Has Been Approved</h2>
                            <p style="font-size: 15px; color: #475569; margin: 0 0 20px 0;">
                                Hello <strong>{$safeName}</strong>,<br>
                                Good news! The school administration has reviewed and approved your access request for the <strong>{$safeSchool}</strong> portal.
                            </p>

                            <!-- Role info box -->
                            <div style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-left: 4px solid #2563eb; border-radius: 8px; padding: 16px 20px; margin: 0 0 26px 0;">
                                <div style="font-size: 12px; font-weight: 600; text-transform: uppercase; color: #64748b; letter-spacing: 0.5px; margin-bottom: 4px;">Assigned Portal Role</div>
                                <div style="font-size: 16px; font-weight: 700; color: #1e293b;">{$roleTitle}</div>
                            </div>

                            <p style="font-size: 14px; color: #475569; margin: 0 0 24px 0;">
                                To complete your account activation, please click the button below to set your own personal password. Once created, you can sign in directly with your email and new password.
                            </p>

                            <!-- Action Button -->
                            <div style="text-align: center; margin: 30px 0;">
                                <a href="{$safeUrl}" target="_blank" style="background: linear-gradient(135deg, #2563eb, #1d4ed8); color: #ffffff; text-decoration: none; padding: 15px 34px; border-radius: 8px; font-size: 15px; font-weight: 700; display: inline-block; letter-spacing: 0.3px; box-shadow: 0 4px 14px rgba(37,99,235,0.35);">
                                    Set Password &amp; Activate Account &rarr;
                                </a>
                            </div>

                            <div style="background-color: #fefce8; border: 1px solid #fef08a; border-radius: 8px; padding: 12px 16px; margin: 24px 0 20px 0;">
                                <p style="font-size: 12.5px; color: #854d0e; margin: 0; line-height: 1.5;">
                                    <strong>Security Notice:</strong> This activation link is unique to you and will expire in <strong>{$expiresHours} hours</strong>. If you did not apply for an account, please disregard this email.
                                </p>
                            </div>

                            <!-- Plain Link Fallback -->
                            <div style="margin-top: 24px; padding-top: 18px; border-top: 1px solid #f1f5f9;">
                                <p style="font-size: 12px; color: #94a3b8; margin: 0 0 6px 0;">
                                    If the button doesn't work, copy and paste this link into your browser:
                                </p>
                                <p style="font-size: 12px; word-break: break-all; margin: 0; color: #2563eb;">
                                    <a href="{$safeUrl}" style="color: #2563eb; text-decoration: underline;">{$safeUrl}</a>
                                </p>
                            </div>
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #f8fafc; border-top: 1px solid #e2e8f0; padding: 22px 32px; text-align: center; font-size: 12px; color: #94a3b8;">
                            &copy; {$safeSchool} &bull; Powered by CampusIQ School Management System
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
}

/**
 * Plain text fallback for account approval email
 */
function buildAccountApprovedEmailText(string $name, string $role, string $activationUrl, string $schoolName = 'CampusIQ', int $expiresHours = 72): string
{
    $roleTitle = ucwords(str_replace('_', ' ', $role));
    return "Hello {$name},\n\n"
        . "Good news! The school administration has approved your access request for {$schoolName} as a {$roleTitle}.\n\n"
        . "To set your own personal password and activate your account, please visit the following link within {$expiresHours} hours:\n\n"
        . "{$activationUrl}\n\n"
        . "Once your password is set, you can immediately log in with your email and password.\n\n"
        . "If you did not submit this request, please ignore this email.\n\n"
        . "Best regards,\n"
        . "{$schoolName} Administration\n";
}

/**
 * Generate a beautifully styled HTML email template when applicant already chose password during request
 */
function buildAccountApprovedDirectLoginEmailHtml(string $name, string $role, string $email, string $loginUrl, string $schoolName = 'CampusIQ'): string
{
    $roleTitle = ucwords(str_replace('_', ' ', $role));
    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $safeSchool = htmlspecialchars($schoolName, ENT_QUOTES, 'UTF-8');
    $safeEmail = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
    $safeUrl = htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8');

    return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Account Approved — {$safeSchool}</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f6f9; font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, Roboto, Helvetica, Arial, sans-serif; color: #1e293b; line-height: 1.6;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #f4f6f9; padding: 40px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" style="max-width: 560px; background-color: #ffffff; border-radius: 14px; box-shadow: 0 10px 25px rgba(15,23,42,0.08); overflow: hidden; border: 1px solid #e2e8f0;">
                    <!-- Header -->
                    <tr>
                        <td style="background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%); padding: 36px 32px; text-align: center;">
                            <div style="display: inline-block; background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.25); border-radius: 50px; padding: 6px 16px; margin-bottom: 14px;">
                                <span style="color: #ffffff; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px;">Account Approved</span>
                            </div>
                            <h1 style="color: #ffffff; margin: 0; font-size: 26px; font-weight: 700; letter-spacing: -0.5px;">{$safeSchool}</h1>
                            <p style="color: #bfdbfe; margin: 6px 0 0 0; font-size: 14px; font-weight: 500;">School Management System</p>
                        </td>
                    </tr>
                    <!-- Main Body -->
                    <tr>
                        <td style="padding: 36px 32px 28px 32px;">
                            <h2 style="margin: 0 0 16px 0; font-size: 20px; font-weight: 700; color: #0f172a;">Welcome to CampusIQ!</h2>
                            <p style="font-size: 15px; color: #475569; margin: 0 0 20px 0;">
                                Hello <strong>{$safeName}</strong>,<br>
                                Your access request for the <strong>{$safeSchool}</strong> portal has been <strong>approved</strong> by the school administration. Your account is now active!
                            </p>

                            <!-- Role & Login Info box -->
                            <div style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-left: 4px solid #16a34a; border-radius: 8px; padding: 18px 20px; margin: 0 0 26px 0;">
                                <div style="font-size: 12px; font-weight: 600; text-transform: uppercase; color: #64748b; letter-spacing: 0.5px; margin-bottom: 6px;">Your Account Details</div>
                                <div style="font-size: 14px; color: #334155; margin-bottom: 4px;"><strong>Portal Role:</strong> {$roleTitle}</div>
                                <div style="font-size: 14px; color: #334155; margin-bottom: 4px;"><strong>Username / Email:</strong> {$safeEmail}</div>
                                <div style="font-size: 14px; color: #16a34a; font-weight: 600; margin-bottom: 8px;"><strong>Password:</strong> The password you chose during registration</div>
                                <div style="font-size: 13px; color: #1e40af; background-color: #eff6ff; border-radius: 6px; padding: 10px 12px; margin-top: 8px;">
                                    <strong><i style="font-style:normal;">🔒</i> Two-Step Security:</strong> When you sign in with your Gmail, a 6-digit verification code (OTP) will be sent to your Gmail inbox (or Authenticator app) for safe access.
                                </div>
                            </div>

                            <p style="font-size: 14px; color: #475569; margin: 0 0 24px 0;">
                                You can now sign in directly using your email and password. Click the button below to go straight to your school sign in page:
                            </p>

                            <!-- Action Button -->
                            <div style="text-align: center; margin: 30px 0;">
                                <a href="{$safeUrl}" target="_blank" style="background: linear-gradient(135deg, #16a34a, #15803d); color: #ffffff; text-decoration: none; padding: 15px 34px; border-radius: 8px; font-size: 15px; font-weight: 700; display: inline-block; letter-spacing: 0.3px; box-shadow: 0 4px 14px rgba(22,163,74,0.35);">
                                    Sign In to Your Dashboard &rarr;
                                </a>
                            </div>

                            <p style="font-size: 12.5px; color: #64748b; line-height: 1.5; margin: 24px 0 0 0;">
                                Need to sign in later? Access your portal anytime at: <br>
                                <a href="{$safeUrl}" style="color: #2563eb; text-decoration: underline;">{$safeUrl}</a>
                            </p>
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #f8fafc; border-top: 1px solid #e2e8f0; padding: 22px 32px; text-align: center; font-size: 12px; color: #94a3b8;">
                            &copy; {$safeSchool} &bull; Powered by CampusIQ School Management System
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
}

/**
 * Plain text fallback for direct login approval email
 */
function buildAccountApprovedDirectLoginEmailText(string $name, string $role, string $email, string $loginUrl, string $schoolName = 'CampusIQ'): string
{
    $roleTitle = ucwords(str_replace('_', ' ', $role));
    return "Hello {$name},\n\n"
        . "Your access request for {$schoolName} as {$roleTitle} has been approved by the school administration!\n\n"
        . "Your account is now active. You can log in immediately using:\n"
        . "- Email: {$email}\n"
        . "- Password: The password you chose during registration\n"
        . "- Two-Step Security: When signing in, a 6-digit verification code (OTP) will be sent to your Gmail inbox (or Authenticator app) for safe access.\n\n"
        . "Sign In URL:\n"
        . "{$loginUrl}\n\n"
        . "Best regards,\n"
        . "{$schoolName} Administration\n";
}

