<?php

function getSmsConfig(): array
{
    static $config = null;
    if ($config === null) {
        $mailConfig = require __DIR__ . '/../config/mail.php';
        $config = $mailConfig['sms'] ?? [];
    }
    return $config;
}

/**
 * Send SMS notification via configured gateway (mNotify, Hubtel, Arkesel, Twilio)
 *
 * @param string $phone Recipient phone number (e.g. +233557560806 or 0557560806)
 * @param string $message Text message
 * @return array ['success' => bool, 'error' => string]
 */
function sendCampusSms(string $phone, string $message): array
{
    $config = getSmsConfig();

    if (empty($config['enabled']) || (empty($config['api_key']) && empty($config['client_id']))) {
        return ['success' => false, 'error' => 'SMS gateway is not enabled or API key is missing in config/mail.php.'];
    }

    $provider = strtolower($config['provider'] ?? 'mnotify');
    $apiKey = $config['api_key'] ?? '';
    $senderId = $config['sender_id'] ?? 'CampusIQ';

    // Normalize Ghana numbers (e.g. 0557560806 or +233557560806 -> 233557560806)
    $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
    if (str_starts_with($cleanPhone, '0') && strlen($cleanPhone) === 10) {
        $cleanPhone = '233' . substr($cleanPhone, 1);
    } elseif (str_starts_with($cleanPhone, '2330') && strlen($cleanPhone) === 13) {
        $cleanPhone = '233' . substr($cleanPhone, 4);
    }

    if (strlen($cleanPhone) < 9) {
        return ['success' => false, 'error' => 'Invalid phone number: ' . $phone];
    }

    try {
        if ($provider === 'mnotify') {
            $url = 'https://api.mnotify.com/api/sms/quick?key=' . urlencode($apiKey);
            $payload = [
                'recipient' => [$cleanPhone],
                'sender' => $senderId,
                'message' => $message,
                'is_schedule' => false,
            ];
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $resp = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            if ($err) {
                return ['success' => false, 'error' => "cURL error: {$err}"];
            }

            $json = json_decode($resp, true);
            $ok = ($httpCode >= 200 && $httpCode < 300) && (!isset($json['status']) || $json['status'] === 'success' || $json['status'] === 200);
            return ['success' => $ok, 'error' => $ok ? '' : ($json['message'] ?? $resp)];
        }

        if ($provider === 'arkesel') {
            $url = 'https://sms.arkesel.com/api/v2/sms/send';
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode([
                    'sender' => $senderId,
                    'message' => $message,
                    'recipients' => [$cleanPhone],
                ]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'api-key: ' . $apiKey,
                ],
                CURLOPT_TIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $resp = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            if ($err) {
                return ['success' => false, 'error' => "cURL error: {$err}"];
            }

            $json = json_decode($resp, true);
            $ok = ($httpCode >= 200 && $httpCode < 300) && (!isset($json['status']) || $json['status'] === 'success');
            return ['success' => $ok, 'error' => $ok ? '' : ($json['message'] ?? $resp)];
        }

        if ($provider === 'hubtel') {
            $clientId = $config['client_id'] ?? $apiKey;
            $clientSecret = $config['client_secret'] ?? '';
            $url = 'https://sms.hubtel.com/v1/messages/send';
            $payload = [
                'From' => $senderId,
                'To' => $cleanPhone,
                'Content' => $message,
            ];
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_USERPWD => "{$clientId}:{$clientSecret}",
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $resp = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            if ($err) {
                return ['success' => false, 'error' => "cURL error: {$err}"];
            }

            return ['success' => $httpCode >= 200 && $httpCode < 300, 'error' => $resp];
        }

        return ['success' => false, 'error' => "Unsupported SMS provider: {$provider}"];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Format phone for privacy display (e.g. +233 557 560806 -> +233 55***0806)
 */
function maskPhone(string $phone): string
{
    $clean = preg_replace('/[^0-9+]/', '', $phone);
    $len = strlen($clean);
    if ($len <= 5) return $clean;
    $start = substr($clean, 0, min(7, $len - 4));
    $end = substr($clean, -4);
    return $start . '***' . $end;
}
