<?php

require_once __DIR__ . '/database.php';

function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function baseUrl(): string
{
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
    $dir = str_replace('\\', '/', dirname($scriptName));

    if (preg_match('#/(admin|teacher|student|parent|accountant)$#', $dir)) {
        $dir = preg_replace('#/(admin|teacher|student|parent|accountant)$#', '', $dir);
    }

    $dir = rtrim($dir, '/');
    if ($dir === '.' || $dir === '\\' || $dir === '/' || $dir === '') {
        return '';
    }

    return $dir;
}

function getActiveLanIp(): string
{
    static $lanIp = null;
    if ($lanIp !== null) {
        return $lanIp;
    }

    // Try detecting the active outbound local IP via non-blocking UDP probe
    $sock = @fsockopen('udp://8.8.8.8', 53, $errno, $errstr, 1);
    if ($sock) {
        $name = stream_socket_get_name($sock, false);
        fclose($sock);
        if ($name) {
            $parts = explode(':', $name);
            if (!empty($parts[0]) && $parts[0] !== '0.0.0.0') {
                $lanIp = $parts[0];
                return $lanIp;
            }
        }
    }

    $lanIp = '127.0.0.1';
    return $lanIp;
}

function absoluteUrl(string $path = ''): string
{
    // Support explicit APP_URL if specified in environment or server
    $appUrl = $_SERVER['APP_URL'] ?? $_ENV['APP_URL'] ?? getenv('APP_URL') ?: '';
    if (!empty($appUrl)) {
        return rtrim($appUrl, '/') . '/' . ltrim($path, '/');
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    $protocol = $isHttps ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    // When running locally on XAMPP (localhost or 127.0.0.1), use the machine's actual
    // LAN IP so mobile phones/Safari on the same network can access links sent via email.
    if ($host === 'localhost' || $host === '127.0.0.1') {
        $lan = getActiveLanIp();
        if ($lan !== '127.0.0.1') {
            $host = $lan;
        }
    }

    $base = baseUrl();
    $targetPath = '/' . ltrim($path, '/');
    return $protocol . $host . $base . $targetPath;
}

function redirect(string $path): void
{
    $prefix = baseUrl();
    $target = $path;
    if ($path[0] === '/' && $prefix !== '') {
        $target = $prefix . $path;
    }
    header('Location: ' . $target);
    exit;
}

function getSchoolSettings(): array
{
    $db = getDb();
    $stmt = $db->query('SELECT * FROM school_settings ORDER BY id DESC LIMIT 1');
    $settings = $stmt->fetch();

    if ($settings && !empty($settings['currency'])) {
        if (str_contains($settings['currency'], '?')) {
            $settings['currency'] = 'GH₵';
        }
    }

    return $settings ?: [
        'school_name' => 'CampusIQ',
        'school_logo' => 'assets/images/logo-placeholder.svg',
        'currency' => 'GH₵',
        'address' => 'Accra, Ghana',
        'phone' => '+233 000 000 000',
        'email' => 'info@campusiq.edu.gh',
        'website' => 'https://campusiq.edu.gh',
    ];
}

function getCurrentAcademicYearId(): ?int
{
    $db = getDb();
    $stmt = $db->query('SELECT id FROM academic_years WHERE is_current = 1 LIMIT 1');
    $current = $stmt->fetch();

    return $current ? (int) $current['id'] : null;
}

function getCurrentTermId(): ?int
{
    $db = getDb();
    $stmt = $db->query('SELECT id FROM terms WHERE is_current = 1 LIMIT 1');
    $current = $stmt->fetch();

    return $current ? (int) $current['id'] : null;
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(): void
{
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        http_response_code(419);
        die('Security token validation failed. Please refresh and try again.');
    }
}
