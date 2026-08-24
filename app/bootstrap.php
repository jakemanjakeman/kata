<?php
declare(strict_types=1);

function kataDefaultConfig(): array
{
    return [
        'timezone' => 'America/New_York',
        'data_dir' => dirname(__DIR__),
        'allowed_ips' => [],
        'password_hash' => '',
        'session_name' => 'kata_session',
        'secure_cookies' => false,
    ];
}

function kataLoadConfig(): array
{
    $config = kataDefaultConfig();
    $configPath = getenv('KATA_CONFIG_PATH');
    $candidates = [];
    if (is_string($configPath) && $configPath !== '') {
        $candidates[] = $configPath;
    }
    $candidates[] = dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'kata-private' . DIRECTORY_SEPARATOR . 'config.production.php';
    $candidates[] = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config.local.php';

    foreach ($candidates as $candidate) {
        if (!is_file($candidate)) {
            continue;
        }

        $loaded = require $candidate;
        if (is_array($loaded)) {
            $config = array_merge($config, $loaded);
            break;
        }
    }

    return $config;
}

function kataClientIp(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

function kataIpAllowed(array $allowedIps, string $clientIp): bool
{
    if ($allowedIps === []) {
        return true;
    }

    return in_array($clientIp, array_map('strval', $allowedIps), true);
}

function kataStoragePath(string $filename): string
{
    $dataDir = rtrim((string)KATA_CONFIG['data_dir'], "\\/");

    return $dataDir . DIRECTORY_SEPARATOR . $filename;
}

function kataVerifyPassword(string $password): bool
{
    $hash = (string)(KATA_CONFIG['password_hash'] ?? '');
    if ($hash === '') {
        return false;
    }

    return password_verify($password, $hash);
}

function kataHandleUnlock(string $todayKey): array
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || (string)($_POST['action'] ?? '') !== 'unlock_app') {
        return [(bool)($_SESSION['kata_authenticated'] ?? false), ''];
    }

    if (kataVerifyPassword((string)($_POST['password'] ?? ''))) {
        $_SESSION['kata_authenticated'] = true;
        ensureDailyJsonBackups($todayKey);
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }

    return [false, 'That password did not unlock the app.'];
}

$kataConfig = kataLoadConfig();
define('KATA_CONFIG', $kataConfig);

date_default_timezone_set((string)$kataConfig['timezone']);
header('X-Robots-Tag: noindex, nofollow', true);

if (!kataIpAllowed((array)($kataConfig['allowed_ips'] ?? []), kataClientIp())) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden';
    exit;
}

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
$secureCookies = (bool)($kataConfig['secure_cookies'] ?? false) || $isHttps;
session_name((string)($kataConfig['session_name'] ?? 'kata_session'));
if (PHP_VERSION_ID >= 70300) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secureCookies,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
} else {
    session_set_cookie_params(0, '/; samesite=Lax', '', $secureCookies, true);
}
session_start();

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backup.php';
