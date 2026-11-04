<?php

function campusPasswordPepper(): string
{
    $pepper = $_SERVER['CAMPUSIQ_PASSWORD_PEPPER'] ?? $_ENV['CAMPUSIQ_PASSWORD_PEPPER'] ?? getenv('CAMPUSIQ_PASSWORD_PEPPER') ?: 'a879559beb196819e28a6a99d3055b15ff7cf675a56e758cae98ea1e282f2467';
    if (!is_string($pepper) || strlen($pepper) < 32) {
        throw new RuntimeException('CAMPUSIQ_PASSWORD_PEPPER must be configured with at least 32 characters.');
    }

    return $pepper;
}

function campusEncryptionKey(): string
{
    $key = $_SERVER['CAMPUSIQ_APP_KEY'] ?? $_ENV['CAMPUSIQ_APP_KEY'] ?? getenv('CAMPUSIQ_APP_KEY') ?: '9f6d2c1bdfee08ef9a1dd0b14e7edfd9b21b67c4c2a69e867e818890f0253039';
    if (!is_string($key) || !preg_match('/^[a-f0-9]{64}$/i', $key)) {
        throw new RuntimeException('CAMPUSIQ_APP_KEY must be configured as 64 hexadecimal characters.');
    }

    return hex2bin($key);
}

function campusPasswordHash(string $password): string
{
    return password_hash(hash_hmac('sha256', $password, campusPasswordPepper()), PASSWORD_DEFAULT);
}

function campusVerifyPassword(string $password, string $storedHash): array
{
    $peppered = hash_hmac('sha256', $password, campusPasswordPepper());
    if (password_verify($peppered, $storedHash)) {
        return [true, password_needs_rehash($storedHash, PASSWORD_DEFAULT)];
    }

    if (password_verify($password, $storedHash)) {
        return [true, true];
    }

    return [false, false];
}

function campusBase32Encode(string $data): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($data) as $character) {
        $bits .= str_pad(decbin(ord($character)), 8, '0', STR_PAD_LEFT);
    }

    $encoded = '';
    foreach (str_split($bits, 5) as $chunk) {
        if (strlen($chunk) === 5) {
            $encoded .= $alphabet[bindec($chunk)];
        }
    }

    return $encoded;
}

function campusBase32Decode(string $encoded): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split(strtoupper(rtrim($encoded, '='))) as $character) {
        $value = strpos($alphabet, $character);
        if ($value === false) {
            throw new InvalidArgumentException('Invalid authenticator secret.');
        }
        $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
    }

    $decoded = '';
    foreach (str_split($bits, 8) as $chunk) {
        if (strlen($chunk) === 8) {
            $decoded .= chr(bindec($chunk));
        }
    }

    return $decoded;
}

function campusTotpCode(string $secret, ?int $timestamp = null): string
{
    $counter = intdiv($timestamp ?? time(), 30);
    $counterBytes = pack('N2', intdiv($counter, 0x100000000), $counter & 0xffffffff);
    $hash = hash_hmac('sha1', $counterBytes, campusBase32Decode($secret), true);
    $offset = ord($hash[19]) & 0x0f;
    $binary = ((ord($hash[$offset]) & 0x7f) << 24)
        | ((ord($hash[$offset + 1]) & 0xff) << 16)
        | ((ord($hash[$offset + 2]) & 0xff) << 8)
        | (ord($hash[$offset + 3]) & 0xff);

    return str_pad((string) ($binary % 1000000), 6, '0', STR_PAD_LEFT);
}

function campusVerifyTotp(string $secret, string $code): bool
{
    $code = preg_replace('/\s+/', '', $code);
    if (!is_string($code) || !preg_match('/^\d{6}$/', $code)) {
        return false;
    }

    $now = time();
    foreach ([-60, -30, 0, 30, 60] as $offset) {
        if (hash_equals(campusTotpCode($secret, $now + $offset), $code)) {
            return true;
        }
    }

    return false;
}

function campusEncryptTotpSecret(string $secret): string
{
    $nonce = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($secret, 'aes-256-gcm', campusEncryptionKey(), OPENSSL_RAW_DATA, $nonce, $tag);
    if ($ciphertext === false) {
        throw new RuntimeException('Could not protect the authenticator secret.');
    }

    return base64_encode($nonce . $tag . $ciphertext);
}

function campusDecryptTotpSecret(string $encrypted): string
{
    $data = base64_decode($encrypted, true);
    if ($data === false || strlen($data) < 29) {
        throw new RuntimeException('The saved authenticator secret is invalid.');
    }

    $secret = openssl_decrypt(substr($data, 28), 'aes-256-gcm', campusEncryptionKey(), OPENSSL_RAW_DATA, substr($data, 0, 12), substr($data, 12, 16));
    if ($secret === false) {
        throw new RuntimeException('Could not decrypt the authenticator secret. Check CAMPUSIQ_APP_KEY.');
    }

    return $secret;
}