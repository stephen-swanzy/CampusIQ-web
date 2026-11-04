<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$db = getDb();
$user = currentUser();
$message = '';
$error = '';
$setupSecret = $_SESSION['totp_setup_secret'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken();
    $action = $_POST['action'] ?? '';

    if ($action === 'begin_setup') {
        $stmt = $db->prepare('SELECT totp_enabled FROM users WHERE id = :id');
        $stmt->execute(['id' => $user['id']]);
        if ((int) $stmt->fetchColumn() === 1) {
            $error = 'Two-factor authentication is already enabled.';
        } else {
            $_SESSION['totp_setup_secret'] = campusBase32Encode(random_bytes(20));
            $setupSecret = $_SESSION['totp_setup_secret'];
        }
    } elseif ($action === 'confirm_setup' && $setupSecret) {
        if (campusVerifyTotp($setupSecret, $_POST['code'] ?? '')) {
            $stmt = $db->prepare('UPDATE users SET totp_secret = :secret, totp_enabled = 1 WHERE id = :id');
            $stmt->execute(['secret' => campusEncryptTotpSecret($setupSecret), 'id' => $user['id']]);
            unset($_SESSION['totp_setup_secret']);
            $setupSecret = null;
            $message = 'Two-factor authentication is enabled for your account.';
        } else {
            $error = 'That code did not verify. Check your device clock and try again.';
        }
    } elseif ($action === 'disable') {
        $stmt = $db->prepare('SELECT password_hash, totp_secret, totp_enabled FROM users WHERE id = :id');
        $stmt->execute(['id' => $user['id']]);
        $account = $stmt->fetch();
        if ($account && (int) $account['totp_enabled'] === 1) {
            [$passwordValid, $upgradeHash] = campusVerifyPassword($_POST['password'] ?? '', $account['password_hash']);
            $secret = campusDecryptTotpSecret($account['totp_secret']);

            if ($passwordValid && campusVerifyTotp($secret, $_POST['code'] ?? '')) {
                $sql = $upgradeHash
                    ? 'UPDATE users SET totp_secret = NULL, totp_enabled = 0, password_hash = :password_hash WHERE id = :id'
                    : 'UPDATE users SET totp_secret = NULL, totp_enabled = 0 WHERE id = :id';
                $params = ['id' => $user['id']];
                if ($upgradeHash) {
                    $params['password_hash'] = campusPasswordHash($_POST['password']);
                }
                $db->prepare($sql)->execute($params);
                $message = 'Two-factor authentication has been disabled.';
            } else {
                $error = 'The password or authenticator code is incorrect.';
            }
        } else {
            $error = 'Two-factor authentication is not enabled.';
        }
    }
}

$stmt = $db->prepare('SELECT totp_enabled FROM users WHERE id = :id');
$stmt->execute(['id' => $user['id']]);
$totpEnabled = (int) $stmt->fetchColumn() === 1;
$issuer = rawurlencode('CampusIQ');
$accountName = rawurlencode($user['email']);
$otpauthUri = $setupSecret ? "otpauth://totp/{$issuer}:{$accountName}?secret={$setupSecret}&issuer={$issuer}&algorithm=SHA1&digits=6&period=30" : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Security | CampusIQ</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo e(baseUrl()); ?>/assets/css/style.css">
</head>
<body class="auth-page">
    <main class="container py-5" style="max-width: 680px;">
        <h1 class="h3 mb-4">Account security</h1>
        <?php if ($message): ?><div class="alert alert-success"><?php echo e($message); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>

        <section class="auth-card">
            <h2 class="h5">Two-factor authentication</h2>
            <p class="text-muted">Use an authenticator app that supports time-based one-time passwords (TOTP).</p>
            <?php if ($totpEnabled): ?>
                <p><strong>Status:</strong> Enabled</p>
                <form method="POST" class="mt-3">
                    <input type="hidden" name="csrf_token" value="<?php echo e(csrfToken()); ?>">
                    <input type="hidden" name="action" value="disable">
                    <label class="form-label" for="current-password">Current password</label>
                    <input id="current-password" class="form-control mb-3" type="password" name="password" autocomplete="current-password" required>
                    <label class="form-label" for="disable-code">Authenticator code</label>
                    <input id="disable-code" class="form-control mb-3" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" required>
                    <button class="btn btn-outline-danger" type="submit">Disable two-factor authentication</button>
                </form>
            <?php elseif ($setupSecret): ?>
                <p>In your authenticator app, add an account using this key:</p>
                <p class="font-monospace user-select-all"><strong><?php echo e($setupSecret); ?></strong></p>
                <p class="small text-muted">Account: <?php echo e($user['email']); ?>. Keep this key private. Enter the app's current code below to confirm setup.</p>
                <details class="mb-3">
                    <summary>Manual setup URI</summary>
                    <p class="small text-break user-select-all mt-2"><?php echo e($otpauthUri); ?></p>
                </details>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo e(csrfToken()); ?>">
                    <input type="hidden" name="action" value="confirm_setup">
                    <label class="form-label" for="setup-code">Authenticator code</label>
                    <input id="setup-code" class="form-control mb-3" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" required>
                    <button class="btn btn-primary" type="submit">Confirm and enable</button>
                </form>
            <?php else: ?>
                <p><strong>Status:</strong> Not enabled</p>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo e(csrfToken()); ?>">
                    <input type="hidden" name="action" value="begin_setup">
                    <button class="btn btn-primary" type="submit">Set up authenticator app</button>
                </form>
            <?php endif; ?>
            <a class="btn btn-link px-0 mt-3" href="<?php echo e(baseUrl()); ?>/index.php">Back to dashboard</a>
        </section>
    </main>
</body>
</html>