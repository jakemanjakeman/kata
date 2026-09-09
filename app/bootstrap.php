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

function kataLatestSavedFocus(array $days): string
{
    krsort($days);

    foreach ($days as $day) {
        $focus = trim((string)(((array)$day)['focus'] ?? ''));
        if ($focus !== '') {
            return $focus;
        }
    }

    return '';
}

function kataLoadGoalsDataForFocus(): array
{
    $storageFile = kataStoragePath('goals.json');
    if (!is_file($storageFile)) {
        return ['main_focus' => '', 'days' => []];
    }

    $contents = file_get_contents($storageFile);
    if ($contents === false || trim($contents) === '') {
        return ['main_focus' => '', 'days' => []];
    }

    $decoded = json_decode($contents, true);
    if (!is_array($decoded)) {
        return ['main_focus' => '', 'days' => []];
    }

    if (isset($decoded['days']) && is_array($decoded['days'])) {
        $decoded['main_focus'] = trim((string)($decoded['main_focus'] ?? ''));
        if ($decoded['main_focus'] === '') {
            $decoded['main_focus'] = kataLatestSavedFocus($decoded['days']);
        }

        return $decoded;
    }

    $days = [];
    foreach ($decoded as $date => $goals) {
        if (!is_string($date)) {
            continue;
        }

        $days[$date] = [
            'focus' => '',
            'goals' => array_values((array)$goals),
        ];
    }

    return ['main_focus' => kataLatestSavedFocus($days), 'days' => $days];
}

function kataLoadMainFocus(): string
{
    $data = kataLoadGoalsDataForFocus();

    return trim((string)($data['main_focus'] ?? ''));
}

function kataSaveMainFocus(string $focus): bool
{
    $data = kataLoadGoalsDataForFocus();
    $data['main_focus'] = trim($focus);

    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    return $json !== false && file_put_contents(kataStoragePath('goals.json'), $json, LOCK_EX) !== false;
}

function kataHandleMainFocusSave(): string
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || (string)($_POST['action'] ?? '') !== 'save_focus') {
        return '';
    }

    if (kataSaveMainFocus((string)($_POST['focus'] ?? ''))) {
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }

    return 'Could not save the focus. Check that this folder is writable.';
}

function kataGlobalFocusStyles(): string
{
    return <<<'CSS'

        .global-focus {
            max-width: 820px;
            margin: 0 0 16px;
        }

        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
        }

        .global-focus-quote {
            display: flex;
            align-items: flex-start;
            gap: 8px;
        }

        .global-focus-display {
            display: inline;
            margin: 0;
            font: italic clamp(1.05rem, 2.2vw, 1.45rem)/1.35 Georgia, "Times New Roman", serif;
            overflow-wrap: anywhere;
        }

        .global-focus-display::before {
            content: "\201C";
        }

        .global-focus-display::after {
            content: "\201D";
        }

        .global-focus-edit-button {
            flex: 0 0 auto;
            display: inline-grid;
            place-items: center;
            width: 28px;
            min-height: 28px;
            padding: 0;
            border: 1px solid var(--line);
            border-radius: 6px;
            background: transparent;
            color: var(--muted);
            font-size: 0.8rem;
        }

        .global-focus-edit-button:hover,
        .global-focus-edit-button:focus-visible {
            background: var(--hover);
            color: var(--accent-dark);
        }

        .global-focus-form.is-hidden {
            display: none;
        }

        .global-focus textarea {
            width: 100%;
            min-height: 78px;
            resize: vertical;
            border: 1px solid var(--line);
            border-radius: 6px;
            padding: 10px;
            color: var(--ink);
            font: italic 1.1rem/1.35 Georgia, "Times New Roman", serif;
            outline: none;
        }

        .global-focus textarea:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px var(--focus-ring);
        }

        .global-focus-actions {
            display: flex;
            justify-content: flex-end;
            margin-top: 8px;
        }
CSS;
}

function kataGlobalFocusScript(): string
{
    return <<<'JS'

        const globalFocusEditButton = document.querySelector('[data-global-focus-edit]');
        const globalFocusForm = document.querySelector('[data-global-focus-form]');
        const globalFocusQuote = document.querySelector('[data-global-focus-quote]');
        const globalFocusTextarea = document.querySelector('[data-global-focus-input]');

        if (globalFocusEditButton && globalFocusForm && globalFocusTextarea) {
            globalFocusEditButton.addEventListener('click', () => {
                globalFocusForm.classList.remove('is-hidden');
                if (globalFocusQuote) {
                    globalFocusQuote.hidden = true;
                }
                globalFocusTextarea.focus();
            });
        }
JS;
}

function kataRenderGlobalFocus(string $focus): string
{
    $hasFocus = trim($focus) !== '';
    $safeFocus = htmlspecialchars($focus, ENT_QUOTES, 'UTF-8');

    return '<section class="global-focus" aria-labelledby="global-focus-title">'
        . '<h2 class="sr-only" id="global-focus-title">Main Focus</h2>'
        . ($hasFocus
            ? '<div class="global-focus-quote" data-global-focus-quote>'
                . '<p class="global-focus-display">' . $safeFocus . '</p>'
                . '<button class="global-focus-edit-button" type="button" aria-label="Edit main focus" title="Edit main focus" data-global-focus-edit>&#9998;</button>'
                . '</div>'
            : '')
        . '<form class="global-focus-form' . ($hasFocus ? ' is-hidden' : '') . '" method="post" action="" data-global-focus-form>'
            . '<input type="hidden" name="action" value="save_focus">'
            . '<label for="global-focus-input">Main focus quote or saying</label>'
            . '<textarea id="global-focus-input" name="focus" maxlength="800" placeholder="Write the quote or saying you want to keep in view." data-global-focus-input>' . $safeFocus . '</textarea>'
            . '<div class="global-focus-actions"><button type="submit">Save Focus</button></div>'
        . '</form>'
    . '</section>';
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
