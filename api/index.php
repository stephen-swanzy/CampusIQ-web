<?php
// Vercel Serverless Master Front-Controller for CampusIQ
// Handles routing, clean URLs, and serving requests dynamically

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uri = '/' . ltrim($uri, '/');

// 1. Serve static files directly if requested
$localPath = __DIR__ . $uri;
if ($uri !== '/' && file_exists($localPath) && !is_dir($localPath)) {
    $ext = strtolower(pathinfo($localPath, PATHINFO_EXTENSION));
    $mimeTypes = [
        'css'  => 'text/css',
        'js'   => 'application/javascript',
        'json' => 'application/json',
        'svg'  => 'image/svg+xml',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'ico'  => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2'=> 'font/woff2',
        'ttf'  => 'font/ttf',
    ];

    if (isset($mimeTypes[$ext])) {
        header('Content-Type: ' . $mimeTypes[$ext]);
        header('Cache-Control: public, max-age=86400');
        readfile($localPath);
        exit;
    }
}

// 2. Route resolution
$targetScript = null;

if ($uri === '/' || $uri === '/index' || $uri === '/index.php') {
    $targetScript = __DIR__ . '/index.php';
} elseif (preg_match('#^/(login|logout|profile|security|setup_admin|set_password|verify_otp|forgot_password|reset_password|request_access)(\.php)?$#', $uri, $matches)) {
    $targetScript = __DIR__ . '/' . $matches[1] . '.php';
} elseif (file_exists($localPath . '.php')) {
    $targetScript = $localPath . '.php';
} elseif (file_exists($localPath) && is_file($localPath) && str_ends_with($localPath, '.php')) {
    $targetScript = $localPath;
} elseif (is_dir($localPath) && file_exists($localPath . '/index.php')) {
    $targetScript = $localPath . '/index.php';
}

// 3. Execute the target PHP script
if ($targetScript && file_exists($targetScript)) {
    // Preserve current working directory for relative includes
    chdir(dirname($targetScript));
    require $targetScript;
    exit;
}

// 4. Default 404 Fallback
http_response_code(404);
header('Content-Type: text/html; charset=UTF-8');
echo '<!DOCTYPE html><html><head><title>404 Not Found - CampusIQ</title></head>';
echo '<body style="font-family:sans-serif;text-align:center;padding:50px;">';
echo '<h2>Page Not Found</h2>';
echo '<p>The requested page could not be found.</p>';
echo '<a href="/" style="display:inline-block;padding:10px 20px;background:#2563eb;color:#fff;text-decoration:none;border-radius:6px;">Go to CampusIQ Home</a>';
echo '</body></html>';
exit;
