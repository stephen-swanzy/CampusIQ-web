<?php

// =========================================================================
// CampusIQ Adaptive Database Configuration
// =========================================================================
$isInfinityFree = !empty($_SERVER['HTTP_HOST']) && (
    stripos($_SERVER['HTTP_HOST'], 'infinityfree') !== false ||
    stripos($_SERVER['HTTP_HOST'], 'epizy') !== false ||
    stripos($_SERVER['HTTP_HOST'], 'rf.gd') !== false
);

if ($isInfinityFree) {
    // Live InfinityFree credentials (Auto-detected)
    $host = 'sql208.infinityfree.com';
    $dbPort = '3306';
    $dbName = 'if0_43138934_camusiq';
    $dbUser = 'if0_43138934';
    $dbPass = 'YsiZhlgoQ2ExY9T';
} else {
    // Environment variable resolution (Vercel / Cloud / Local)
    $host = $_SERVER['DB_HOST'] ?? $_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: '127.0.0.1';
    $isLocal = in_array($host, ['127.0.0.1', 'localhost'], true);
    $defaultPort = $isLocal ? '3306' : '3306';
    $dbPort = $_SERVER['DB_PORT'] ?? $_ENV['DB_PORT'] ?? getenv('DB_PORT') ?: $defaultPort;
    $dbName = $_SERVER['DB_NAME'] ?? $_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: 'campusiq';
    $dbUser = $_SERVER['DB_USER'] ?? $_ENV['DB_USER'] ?? getenv('DB_USER') ?: 'root';
    $dbPass = $_SERVER['DB_PASS'] ?? $_ENV['DB_PASS'] ?? getenv('DB_PASS') ?: ($isLocal ? '0557560806ak' : '');
    $dbSslCa = $_SERVER['DB_SSL_CA'] ?? $_ENV['DB_SSL_CA'] ?? getenv('DB_SSL_CA') ?: null;
}

$pdo = null;
$portsToTry = [$dbPort];
if ($isLocal && !in_array($dbPort === '3306' ? '3307' : '3306', $portsToTry, true)) {
    $portsToTry[] = ($dbPort === '3306') ? '3307' : '3306';
}

$lastError = null;
$pdoOptions = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

// If using cloud MySQL like TiDB, attach SSL bundle if present
$caPath = $dbSslCa ?: (file_exists(__DIR__ . '/../cacert.pem') ? __DIR__ . '/../cacert.pem' : null);
if ($caPath) {
    $pdoOptions[PDO::MYSQL_ATTR_SSL_CA] = $caPath;
    $pdoOptions[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
} elseif (!$isLocal && !$isInfinityFree) {
    if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
        $pdoOptions[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
    }
}

foreach ($portsToTry as $trialPort) {
    try {
        $pdo = new PDO(
            'mysql:host=' . $host . ';port=' . $trialPort . ';dbname=' . $dbName . ';charset=utf8mb4',
            $dbUser,
            $dbPass,
            $pdoOptions
        );
        break;
    } catch (PDOException $e) {
        $lastError = $e;
    }
}

if (!$pdo) {
    error_log('Database connection failed: ' . ($lastError ? $lastError->getMessage() : 'Unknown error'));
    die('CampusIQ is temporarily unavailable. Please check the database configuration and try again later.');
}

function getDb(): PDO
{
    global $pdo;
    return $pdo;
}
