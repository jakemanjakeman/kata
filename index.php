<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';

$storageFile = kataStoragePath('goals.json');
$financeStorageFile = kataStoragePath('finance.json');
$authError = '';
$isAuthenticated = (bool)($_SESSION['kata_authenticated'] ?? false);
$now = new DateTimeImmutable();
$todayKey = $now->format('Y-m-d');
$isNightMode = (int)$now->format('G') > 20 || ((int)$now->format('G') === 20 && (int)$now->format('i') >= 30);
$isWeekday = (int)$now->format('N') <= 5;
$error = '';
$periods = $isWeekday
    ? [
        'morning' => 'Get home',
        'midday' => 'After dinner',
        'afternoon' => 'Before bed',
    ]
    : [
        'morning' => 'Morning',
        'midday' => 'Midday',
        'afternoon' => 'Afternoon',
    ];

function blankDay(): array
{
    return [
        'focus' => '',
        'reflection' => [
            'happiness' => 0,
            'tiktok_followers' => null,
            'instagram_followers' => null,
            'x_followers' => null,
            'linkedin_followers' => null,
            'note' => '',
            'created_at' => '',
            'updated_at' => '',
            'check_ins' => [],
        ],
        'goals_complete' => false,
        'goals' => [],
        'todos' => [
            'morning' => [],
            'midday' => [],
            'afternoon' => [],
        ],
    ];
}

function normalizeDay(array $day): array
{
    $normalized = blankDay();
    $normalized['focus'] = (string)($day['focus'] ?? '');
    $normalized['reflection'] = array_merge($normalized['reflection'], (array)($day['reflection'] ?? []));
    $normalized['reflection']['happiness'] = normalizeRating($normalized['reflection']['happiness']);
    foreach (socialFollowerKeys() as $followerKey) {
        $followers = $normalized['reflection'][$followerKey] ?? null;
        $normalized['reflection'][$followerKey] = is_numeric($followers) ? max(0, (int)$followers) : null;
    }
    $normalized['reflection']['note'] = (string)$normalized['reflection']['note'];
    $normalized['reflection']['created_at'] = (string)$normalized['reflection']['created_at'];
    $normalized['reflection']['updated_at'] = (string)$normalized['reflection']['updated_at'];
    $normalized['reflection']['check_ins'] = [];
    foreach ((array)($day['reflection']['check_ins'] ?? []) as $checkIn) {
        $checkIn = (array)$checkIn;
        $rating = normalizeRating($checkIn['happiness'] ?? 0);
        if ($rating <= 0) {
            continue;
        }
        $normalized['reflection']['check_ins'][] = [
            'id' => (string)($checkIn['id'] ?? ''),
            'happiness' => $rating,
            'note' => trim((string)($checkIn['note'] ?? '')),
            'created_at' => (string)($checkIn['created_at'] ?? ''),
        ];
    }
    if ($normalized['reflection']['check_ins'] === [] && $normalized['reflection']['happiness'] > 0) {
        $normalized['reflection']['check_ins'][] = [
            'id' => 'legacy',
            'happiness' => $normalized['reflection']['happiness'],
            'note' => $normalized['reflection']['note'],
            'created_at' => $normalized['reflection']['updated_at'] !== ''
                ? $normalized['reflection']['updated_at']
                : $normalized['reflection']['created_at'],
        ];
    }
    $normalized['goals_complete'] = (bool)($day['goals_complete'] ?? false);
    $normalized['goals'] = array_values((array)($day['goals'] ?? []));

    foreach (array_keys($normalized['todos']) as $period) {
        foreach ((array)($day['todos'][$period] ?? []) as $todo) {
            $todo = (array)$todo;
            $todo['complete'] = (bool)($todo['complete'] ?? false);
            $todo['completed_at'] = (string)($todo['completed_at'] ?? '');
            $normalized['todos'][$period][] = $todo;
        }
    }

    return $normalized;
}

function socialFollowerKeys(): array
{
    return [
        'tiktok_followers',
        'instagram_followers',
        'x_followers',
        'linkedin_followers',
    ];
}

function loadData(string $storageFile): array
{
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
        foreach ($decoded['days'] as $date => $day) {
            $decoded['days'][$date] = normalizeDay((array)$day);
        }

        $decoded['main_focus'] = trim((string)($decoded['main_focus'] ?? ''));
        if ($decoded['main_focus'] === '') {
            $decoded['main_focus'] = latestSavedFocus($decoded['days']);
        }

        return $decoded;
    }

    $days = [];
    foreach ($decoded as $date => $goals) {
        if (!is_string($date)) {
            continue;
        }

        $day = blankDay();
        $day['goals'] = array_values((array)$goals);
        $days[$date] = $day;
    }

    return ['main_focus' => latestSavedFocus($days), 'days' => $days];
}

function latestSavedFocus(array $days): string
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

function saveData(string $storageFile, array $data): bool
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return $json !== false && file_put_contents($storageFile, $json, LOCK_EX) !== false;
}

function formatDateHeader(string $date): string
{
    $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $date);
    return $parsed ? $parsed->format('l, F j, Y') : $date;
}

function normalizeRating($rating, float $minimum = 0.0): float
{
    $rating = is_numeric($rating) ? (float)$rating : 0.0;
    $rating = round($rating * 2) / 2;

    return max($minimum, min(5.0, $rating));
}

function formatRating(float $rating): string
{
    return number_format($rating, fmod($rating, 1.0) === 0.0 ? 0 : 1);
}

function renderStarRating(float $rating): string
{
    $stars = '';

    for ($star = 1; $star <= 5; $star++) {
        $class = 'rating-star';
        if ($rating >= $star) {
            $class .= ' is-full';
        } elseif ($rating >= $star - 0.5) {
            $class .= ' is-half';
        }

        $stars .= '<span class="' . $class . '" aria-hidden="true">&#9733;</span>';
    }

    return $stars;
}

function monthFromQuery(DateTimeImmutable $fallback): DateTimeImmutable
{
    $month = (string)($_GET['month'] ?? '');
    $parsed = DateTimeImmutable::createFromFormat('!Y-m', $month);

    return $parsed ?: $fallback->modify('first day of this month')->setTime(0, 0);
}

function redirectHome(): never
{
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

function redirectToMonth(string $date): never
{
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?month=' . substr($date, 0, 7) . '#calendar-title');
    exit;
}

function makeItem(string $text): array
{
    return [
        'id' => bin2hex(random_bytes(8)),
        'text' => $text,
        'complete' => false,
        'completed_at' => '',
        'created_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
    ];
}

function formatCompletedTime(string $completedAt): string
{
    if ($completedAt === '') {
        return '';
    }

    try {
        return (new DateTimeImmutable($completedAt))->format('g:i A');
    } catch (Exception $exception) {
        return '';
    }
}

function parseFollowerCount(string $count): ?int
{
    $digits = preg_replace('/\D+/', '', $count);
    return $digits === '' ? null : (int)$digits;
}

function collectUncompletedTodos(array $days, string $todayKey): array
{
    $uncompleted = [];

    foreach ($days as $date => $day) {
        if ((string)$date >= $todayKey) {
            continue;
        }

        $day = normalizeDay((array)$day);
        foreach ($day['todos'] as $period => $todos) {
            foreach ($todos as $todo) {
                if ((bool)($todo['complete'] ?? false)) {
                    continue;
                }

                $todo['source_date'] = (string)$date;
                $todo['source_period'] = (string)$period;
                $uncompleted[] = $todo;
            }
        }
    }

    return $uncompleted;
}

function collectMonthlyStats(array $days, DateTimeImmutable $monthStart): array
{
    $monthKey = $monthStart->format('Y-m');
    $ratings = [];
    $followers = [];

    foreach ($days as $date => $day) {
        if (substr((string)$date, 0, 7) !== $monthKey) {
            continue;
        }

        $day = normalizeDay((array)$day);
        $reflection = $day['reflection'];
        $rating = normalizeRating($reflection['happiness'] ?? 0);
        $followerCount = $reflection['tiktok_followers'];

        if ($rating > 0) {
            $ratings[] = $rating;
        }

        if ($followerCount !== null) {
            $followers[(string)$date] = (int)$followerCount;
        }
    }

    ksort($followers);
    $firstFollowers = $followers === [] ? null : reset($followers);
    $lastFollowers = $followers === [] ? null : end($followers);

    return [
        'average_rating' => $ratings === [] ? null : array_sum($ratings) / count($ratings),
        'rating_days' => count($ratings),
        'follower_days' => count($followers),
        'follower_change' => $firstFollowers === null || $lastFollowers === null ? null : $lastFollowers - $firstFollowers,
    ];
}

function normalizeFinanceAccountForCalendar(array $account): array
{
    $type = (string)($account['type'] ?? 'bank');

    return [
        'id' => (string)($account['id'] ?? ''),
        'name' => trim((string)($account['name'] ?? '')),
        'type' => in_array($type, ['bank', 'debt'], true) ? $type : 'bank',
    ];
}

function normalizeFinanceEntryForCalendar(array $entry): array
{
    $normalized = ['balances' => []];

    foreach ((array)($entry['balances'] ?? []) as $accountId => $balance) {
        if (!is_string($accountId) || !is_numeric($balance)) {
            continue;
        }

        $normalized['balances'][$accountId] = round((float)$balance, 2);
    }

    return $normalized;
}

function loadFinanceCalendarData(string $storageFile): array
{
    if (!is_file($storageFile)) {
        return ['accounts' => [], 'entries' => []];
    }

    $contents = file_get_contents($storageFile);
    if ($contents === false || trim($contents) === '') {
        return ['accounts' => [], 'entries' => []];
    }

    $decoded = json_decode($contents, true);
    if (!is_array($decoded)) {
        return ['accounts' => [], 'entries' => []];
    }

    $data = ['accounts' => [], 'entries' => []];
    foreach ((array)($decoded['accounts'] ?? []) as $account) {
        $normalized = normalizeFinanceAccountForCalendar((array)$account);
        if ($normalized['id'] !== '' && $normalized['name'] !== '') {
            $data['accounts'][] = $normalized;
        }
    }

    foreach ((array)($decoded['entries'] ?? []) as $date => $entry) {
        if (!is_string($date) || DateTimeImmutable::createFromFormat('Y-m-d', $date) === false) {
            continue;
        }

        $data['entries'][$date] = normalizeFinanceEntryForCalendar((array)$entry);
    }

    ksort($data['entries']);

    return $data;
}

function calculateFinanceCalendarTotals(array $accounts, array $entry): array
{
    $bankTotal = 0.0;
    $debtTotal = 0.0;

    foreach ($accounts as $account) {
        $accountId = (string)$account['id'];
        if (!array_key_exists($accountId, $entry['balances'] ?? [])) {
            continue;
        }

        $balance = (float)$entry['balances'][$accountId];
        if ($account['type'] === 'debt') {
            $debtTotal += abs($balance);
        } else {
            $bankTotal += $balance;
        }
    }

    return [
        'bank' => round($bankTotal, 2),
        'debt' => round($debtTotal, 2),
        'net' => round($bankTotal - $debtTotal, 2),
    ];
}

function formatCalendarMoney(float $amount): string
{
    $prefix = $amount < 0 ? '-' : '';
    return $prefix . '$' . number_format(abs($amount), 2);
}

function collectMonthlyFinanceStats(array $entries, DateTimeImmutable $monthStart): array
{
    $monthKey = $monthStart->format('Y-m');
    $count = 0;

    foreach ($entries as $date => $entry) {
        if (substr((string)$date, 0, 7) === $monthKey && ($entry['balances'] ?? []) !== []) {
            $count++;
        }
    }

    return ['tally_days' => $count];
}

$data = loadData($storageFile);
$financeData = loadFinanceCalendarData($financeStorageFile);
$data['main_focus'] = trim((string)($data['main_focus'] ?? ''));
$data['days'][$todayKey] = normalizeDay((array)($data['days'][$todayKey] ?? []));
$today = &$data['days'][$todayKey];

[$isAuthenticated, $authError] = kataHandleUnlock($todayKey);
if ($isAuthenticated) {
    ensureDailyJsonBackups($todayKey);
}

$focusError = $isAuthenticated ? kataHandleMainFocusSave() : '';
if ($focusError !== '') {
    $error = $focusError;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAuthenticated) {
    $action = (string)($_POST['action'] ?? 'add_goal');

    if ($action === 'add_goal') {
        $goalText = trim((string)($_POST['goal'] ?? ''));

        if ($goalText === '') {
            $error = 'Give the thought a few words before adding it.';
        } else {
            $today['goals'][] = makeItem($goalText);

            if (saveData($storageFile, $data)) {
                redirectHome();
            }

            $error = 'Could not save the goal. Check that this folder is writable.';
        }
    }

    if ($action === 'save_check_in') {
        $happiness = normalizeRating($_POST['happiness'] ?? 0, 0.5);
        $note = trim((string)($_POST['reflection_note'] ?? ''));
        $alreadyCreated = (string)($today['reflection']['created_at'] ?? '');
        $createdAt = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);

        $today['reflection']['check_ins'][] = [
            'id' => bin2hex(random_bytes(8)),
            'happiness' => $happiness,
            'note' => $note,
            'created_at' => $createdAt,
        ];
        $today['reflection']['happiness'] = $happiness;
        $today['reflection']['note'] = $note;
        $today['reflection']['created_at'] = $alreadyCreated !== '' ? $alreadyCreated : $createdAt;
        $today['reflection']['updated_at'] = $createdAt;

        if (saveData($storageFile, $data)) {
            redirectHome();
        }

        $error = 'Could not save the check-in. Check that this folder is writable.';
    }

    if ($action === 'save_calendar_reflection') {
        $entryDate = (string)($_POST['entry_date'] ?? '');
        $parsedDate = DateTimeImmutable::createFromFormat('Y-m-d', $entryDate);

        if (!$parsedDate) {
            $error = 'Choose a valid date for the reflection.';
        } else {
            $dateKey = $parsedDate->format('Y-m-d');
            $data['days'][$dateKey] = normalizeDay((array)($data['days'][$dateKey] ?? []));

            $happiness = normalizeRating($_POST['happiness'] ?? 0, 0.5);
            $tiktokFollowers = parseFollowerCount((string)($_POST['tiktok_followers'] ?? ''));
            $note = trim((string)($_POST['reflection_note'] ?? ''));
            $alreadyCreated = (string)($data['days'][$dateKey]['reflection']['created_at'] ?? '');

            $data['days'][$dateKey]['reflection']['happiness'] = $happiness;
            $data['days'][$dateKey]['reflection']['tiktok_followers'] = $tiktokFollowers;
            $data['days'][$dateKey]['reflection']['note'] = $note;
            $data['days'][$dateKey]['reflection']['created_at'] = $alreadyCreated !== '' ? $alreadyCreated : (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
            $data['days'][$dateKey]['reflection']['updated_at'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);

            if (saveData($storageFile, $data)) {
                redirectToMonth($dateKey);
            }

            $error = 'Could not save the calendar reflection. Check that this folder is writable.';
        }
    }

    if ($action === 'complete_goals') {
        $today['goals_complete'] = true;

        if (saveData($storageFile, $data)) {
            redirectHome();
        }

        $error = 'Could not move to todos. Check that this folder is writable.';
    }

    if ($action === 'add_todo') {
        $todoText = trim((string)($_POST['todo'] ?? ''));

        if ($todoText === '') {
            $error = 'Give the todo a few words before adding it.';
        } else {
            $today['goals_complete'] = true;
            $today['todos']['morning'][] = makeItem($todoText);

            if (saveData($storageFile, $data)) {
                redirectHome();
            }

            $error = 'Could not save the todo. Check that this folder is writable.';
        }
    }

    if ($action === 'move_todo') {
        header('Content-Type: application/json');

        $todoId = (string)($_POST['todo_id'] ?? '');
        $targetPeriod = (string)($_POST['target_period'] ?? '');
        $sourceDate = (string)($_POST['source_date'] ?? $todayKey);

        if ($todoId === '' || !array_key_exists($targetPeriod, $periods) || !isset($data['days'][$sourceDate])) {
            http_response_code(422);
            echo json_encode(['ok' => false]);
            exit;
        }

        $movedTodo = null;
        $sourceDay = &$data['days'][$sourceDate];
        foreach ($sourceDay['todos'] as $period => $todos) {
            foreach ($todos as $index => $todo) {
                if (($todo['id'] ?? '') === $todoId) {
                    $movedTodo = $todo;
                    array_splice($sourceDay['todos'][$period], $index, 1);
                    break 2;
                }
            }
        }
        unset($sourceDay);

        if ($movedTodo === null) {
            http_response_code(404);
            echo json_encode(['ok' => false]);
            exit;
        }

        $today['goals_complete'] = true;
        $today['todos'][$targetPeriod][] = $movedTodo;
        echo json_encode(['ok' => saveData($storageFile, $data)]);
        exit;
    }

    if ($action === 'toggle_todo') {
        header('Content-Type: application/json');

        $todoId = (string)($_POST['todo_id'] ?? '');
        $complete = (string)($_POST['complete'] ?? '') === '1';
        $sourceDate = (string)($_POST['source_date'] ?? $todayKey);

        if ($todoId === '' || !isset($data['days'][$sourceDate])) {
            http_response_code(422);
            echo json_encode(['ok' => false]);
            exit;
        }

        $sourceDay = &$data['days'][$sourceDate];
        foreach ($sourceDay['todos'] as $period => $todos) {
            foreach ($todos as $index => $todo) {
                if (($todo['id'] ?? '') === $todoId) {
                    $completedAt = $complete ? (new DateTimeImmutable())->format(DateTimeInterface::ATOM) : '';
                    $sourceDay['todos'][$period][$index]['complete'] = $complete;
                    $sourceDay['todos'][$period][$index]['completed_at'] = $completedAt;
                    unset($sourceDay);
                    echo json_encode([
                        'ok' => saveData($storageFile, $data),
                        'completed_time' => formatCompletedTime($completedAt),
                    ]);
                    exit;
                }
            }
        }
        unset($sourceDay);

        http_response_code(404);
        echo json_encode(['ok' => false]);
        exit;
    }

    if ($action === 'delete_todo') {
        $todoId = (string)($_POST['todo_id'] ?? '');
        $sourceDate = (string)($_POST['source_date'] ?? $todayKey);
        $deletedTodo = false;

        if ($todoId === '' || !isset($data['days'][$sourceDate])) {
            $error = 'Choose an unfinished todo to remove.';
        } else {
            $sourceDay = &$data['days'][$sourceDate];
            foreach ($sourceDay['todos'] as $period => $todos) {
                foreach ($todos as $index => $todo) {
                    if (($todo['id'] ?? '') === $todoId && !(bool)($todo['complete'] ?? false)) {
                        array_splice($sourceDay['todos'][$period], $index, 1);
                        $deletedTodo = true;
                        break 2;
                    }
                }
            }
            unset($sourceDay);

            if ($deletedTodo && saveData($storageFile, $data)) {
                redirectHome();
            }

            $error = $deletedTodo
                ? 'Could not remove the todo. Check that this folder is writable.'
                : 'Only unfinished todos can be removed.';
        }
    }
}

unset($today);
krsort($data['days']);
$uncompletedTodos = collectUncompletedTodos($data['days'], $todayKey);
$calendarMonth = monthFromQuery($now);
$calendarMonthKey = $calendarMonth->format('Y-m');
$calendarPreviousMonth = $calendarMonth->modify('-1 month')->format('Y-m');
$calendarNextMonth = $calendarMonth->modify('+1 month')->format('Y-m');
$calendarDaysInMonth = (int)$calendarMonth->format('t');
$calendarStartOffset = (int)$calendarMonth->format('w');
$calendarStats = collectMonthlyStats($data['days'], $calendarMonth);
$calendarFinanceStats = collectMonthlyFinanceStats($financeData['entries'], $calendarMonth);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Kata Goals</title>
    <style>
        :root {
            color-scheme: light;
            --bg: #f5f7f4;
            --ink: #17201b;
            --muted: #68746c;
            --panel: #ffffff;
            --line: #d9e0da;
            --accent: #1f7a6d;
            --accent-dark: #155f56;
            --warning: #bd7a25;
            --danger: #9d3328;
            --soft-panel: #fbfcfa;
            --warm-panel: #fffdf8;
            --warm-line: #eadfc8;
            --hover: #eef4ef;
            --danger-soft: #f8e8e5;
            --empty-bg: rgba(255, 255, 255, 0.55);
            --overlay: rgba(245, 247, 244, 0.74);
            --shadow: rgba(24, 38, 30, 0.08);
            --lock-shadow: rgba(24, 38, 30, 0.18);
            --focus-ring: rgba(31, 122, 109, 0.16);
            --drop-ring: rgba(31, 122, 109, 0.14);
            --star-muted: #c8bca7;
            --star: #d99a22;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        body.night-mode {
            color-scheme: dark;
            --bg: #111713;
            --ink: #eef4ef;
            --muted: #a8b7ad;
            --panel: #18221c;
            --line: #314238;
            --accent: #4fb7a4;
            --accent-dark: #7bd4c5;
            --warning: #d69b45;
            --danger: #f09a90;
            --soft-panel: #151f19;
            --warm-panel: #1f2117;
            --warm-line: #4a442c;
            --hover: #22332a;
            --danger-soft: #3a2422;
            --empty-bg: rgba(24, 34, 28, 0.72);
            --overlay: rgba(17, 23, 19, 0.76);
            --shadow: rgba(0, 0, 0, 0.28);
            --lock-shadow: rgba(0, 0, 0, 0.38);
            --focus-ring: rgba(79, 183, 164, 0.2);
            --drop-ring: rgba(79, 183, 164, 0.18);
            --star-muted: #665f50;
            --star: #e0a638;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            background: var(--bg);
            color: var(--ink);
        }

        main {
            width: min(980px, calc(100% - 32px));
            margin: 0 auto;
            padding: 42px 0 56px;
        }

        .masthead {
            margin-bottom: 28px;
        }

        .primary-nav {
            display: flex;
            justify-content: flex-end;
            flex-wrap: wrap;
            margin-bottom: 22px;
        }

        .primary-menu-toggle {
            display: none;
        }

        .primary-menu-items {
            display: flex;
            justify-content: flex-end;
            gap: 16px;
            flex-wrap: wrap;
            align-items: center;
        }

        .primary-menu-items a {
            color: var(--accent-dark);
            font-weight: 800;
            text-decoration: none;
        }

        .primary-menu-items a:hover,
        .primary-menu-items a:focus-visible {
            text-decoration: underline;
        }

        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
        }

        <?= kataGlobalFocusStyles() ?>

        .theme-toggle {
            width: auto;
            min-height: 0;
            border: 1px solid var(--line);
            border-radius: 6px;
            padding: 4px 10px;
            background: transparent;
            color: var(--accent-dark);
            font-size: 0.92rem;
            font-weight: 800;
        }

        .theme-toggle:hover,
        .theme-toggle:focus-visible {
            background: var(--hover);
            color: var(--accent-dark);
        }

        h1,
        .page-title {
            margin: 0;
            font-size: clamp(2rem, 5vw, 4rem);
            line-height: 0.98;
            font-weight: 800;
            letter-spacing: 0;
        }

        .subtitle {
            max-width: 650px;
            margin: 14px 0 0;
            color: var(--muted);
            font-size: 1.05rem;
            line-height: 1.55;
        }

        .stage {
            margin-bottom: 30px;
            padding: 18px;
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 8px;
            box-shadow: 0 12px 28px var(--shadow);
        }

        .reflection {
            position: relative;
            margin-bottom: 24px;
            padding: clamp(20px, 4vw, 34px);
            background: linear-gradient(145deg, var(--warm-panel), var(--panel));
            border: 2px solid var(--warm-line);
            border-radius: 14px;
            box-shadow: 0 16px 36px var(--shadow);
        }

        .reflection-grid {
            display: grid;
            grid-template-columns: auto 1fr auto;
            gap: 16px;
            align-items: end;
            margin-top: 18px;
        }

        .stars {
            display: inline-flex;
            flex-direction: row-reverse;
            justify-content: flex-end;
            gap: 0;
            margin: 0;
            padding: 0;
            border: 0;
        }

        .stars input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        .stars label {
            position: static;
            display: block;
            width: 0.5em;
            height: 1em;
            clip: auto;
            overflow: hidden;
            color: var(--star-muted);
            cursor: pointer;
            font-size: 2.8rem;
            line-height: 1;
        }

        .stars label span {
            display: block;
            width: 1em;
            line-height: 1;
        }

        .stars label[data-half="right"] span {
            transform: translateX(-0.5em);
        }

        .stars label:hover,
        .stars label:hover ~ label,
        .stars input:checked ~ label {
            color: var(--star);
        }

        .reflection-note {
            min-width: 0;
        }

        .reflection-prompt {
            margin: 6px 0 0;
            color: var(--muted);
            font-size: 1rem;
        }

        .check-in-history {
            display: flex;
            flex-wrap: wrap;
            gap: 8px 16px;
            margin-top: 18px;
            padding-top: 14px;
            border-top: 1px solid var(--warm-line);
        }

        .check-in-history-item {
            display: flex;
            gap: 7px;
            align-items: center;
            color: var(--muted);
            font-size: 0.88rem;
        }

        .check-in-history-stars {
            color: var(--star);
            font-size: 1rem;
            line-height: 1;
        }

        .reflection-followers {
            min-width: 150px;
        }

        .reflection {
            position: relative;
        }

        .reflection-display {
            margin-top: 12px;
        }

        .reflection-stars {
            color: var(--star);
            font-size: 1.7rem;
            line-height: 1;
        }

        .rating-star {
            position: relative;
            display: inline-block;
            color: var(--star-muted);
        }

        .rating-star.is-full {
            color: var(--star);
        }

        .rating-star.is-half::before {
            content: "\2605";
            position: absolute;
            inset: 0 auto 0 0;
            width: 50%;
            overflow: hidden;
            color: var(--star);
        }

        .reflection-text {
            margin: 8px 44px 0 0;
            color: var(--ink);
            font-size: 1.05rem;
            line-height: 1.45;
            overflow-wrap: anywhere;
        }

        .reflection-meta,
        .day-stats {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin: 10px 44px 0 0;
        }

        .day-stats {
            margin: 0 0 12px;
        }

        .stat-pill {
            display: inline;
            color: var(--muted);
            font-size: 0.88rem;
            font-weight: 800;
        }

        .reflection-edit-button {
            position: absolute;
            top: 12px;
            right: 12px;
            display: inline-grid;
            place-items: center;
            width: 34px;
            min-height: 34px;
            padding: 0;
            border: 1px solid var(--line);
            background: transparent;
            color: var(--muted);
            font-size: 1rem;
        }

        .reflection-edit-button:hover,
        .reflection-edit-button:focus-visible {
            background: var(--hover);
            color: var(--accent-dark);
        }

        .reflection-form.is-hidden {
            display: none;
        }

        .stage-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 16px;
        }

        .stage-title {
            margin: 0;
            font-size: 1rem;
            color: var(--muted);
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        .check-in-title {
            margin: 0;
            font-size: clamp(1.65rem, 4vw, 2.5rem);
            line-height: 1.1;
            color: var(--ink);
            letter-spacing: -0.02em;
            text-transform: none;
        }

        .entry {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 12px;
        }

        label {
            position: absolute;
            width: 1px;
            height: 1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
        }

        input[type="text"],
        input[type="number"],
        input[type="password"] {
            width: 100%;
            min-height: 48px;
            border: 1px solid var(--line);
            border-radius: 6px;
            padding: 0 14px;
            color: var(--ink);
            font: inherit;
            outline: none;
        }

        input[type="text"]:focus,
        input[type="number"]:focus,
        input[type="password"]:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px var(--focus-ring);
        }

        button {
            min-height: 48px;
            border: 0;
            border-radius: 6px;
            padding: 0 18px;
            background: var(--accent);
            color: white;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
        }

        button:hover,
        button:focus-visible {
            background: var(--accent-dark);
        }

        .secondary-button {
            background: var(--warning);
        }

        .secondary-button:hover,
        .secondary-button:focus-visible {
            background: #9c6019;
        }

        .notice {
            margin: 12px 0 0;
            color: var(--danger);
            font-weight: 700;
        }

        .calendar {
            margin-top: 30px;
            padding: 18px;
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 8px;
            box-shadow: 0 12px 28px var(--shadow);
        }

        .calendar-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 14px;
        }

        .calendar-header h2 {
            margin: 0;
            font-size: 1.1rem;
        }

        .calendar-nav {
            display: flex;
            gap: 8px;
        }

        .calendar-nav a {
            display: inline-grid;
            place-items: center;
            width: 36px;
            min-height: 36px;
            border: 1px solid var(--line);
            border-radius: 6px;
            color: var(--accent-dark);
            font-size: 1.2rem;
            font-weight: 900;
            text-decoration: none;
        }

        .calendar-nav a:hover,
        .calendar-nav a:focus-visible {
            background: var(--hover);
        }

        .calendar-summary {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 14px;
        }

        .calendar-title-recent,
        .calendar-recent {
            display: none;
        }

        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, minmax(0, 1fr));
            gap: 6px;
        }

        .calendar-weekday {
            color: var(--muted);
            font-size: 0.75rem;
            font-weight: 900;
            text-align: center;
            text-transform: uppercase;
        }

        .calendar-day {
            min-height: 92px;
            padding: 8px;
            border: 1px solid var(--line);
            border-radius: 6px;
            background: var(--soft-panel);
        }

        .calendar-day[role="button"] {
            cursor: pointer;
        }

        .calendar-day[role="button"]:hover,
        .calendar-day[role="button"]:focus-visible {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px var(--focus-ring);
            outline: none;
        }

        .calendar-day.is-today {
            border-color: var(--accent);
            box-shadow: inset 0 0 0 1px var(--accent);
        }

        .calendar-day.is-empty {
            border-color: transparent;
            background: transparent;
        }

        .calendar-date {
            display: block;
            margin-bottom: 6px;
            color: var(--muted);
            font-size: 0.78rem;
            font-weight: 900;
        }

        .calendar-recent .calendar-date {
            color: var(--ink);
            font-size: 0.95rem;
        }

        .calendar-recent-label {
            display: block;
            margin-bottom: 8px;
            color: var(--muted);
            font-size: 0.78rem;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        .calendar-empty {
            margin: 8px 0 0;
            color: var(--muted);
            font-size: 0.9rem;
            font-weight: 700;
        }

        .calendar-metrics {
            display: grid;
            gap: 5px;
        }

        .calendar-metric {
            display: block;
            color: var(--ink);
            font-size: 0.82rem;
            font-weight: 800;
            line-height: 1.25;
        }

        .calendar-money {
            color: var(--accent-dark);
            text-decoration: none;
        }

        .calendar-money:hover,
        .calendar-money:focus-visible {
            text-decoration: underline;
        }

        .calendar-stars {
            position: relative;
            color: var(--star);
            font-size: 0.95rem;
            letter-spacing: 0;
            white-space: nowrap;
        }

        .calendar-stars[data-note]:hover::after,
        .calendar-stars[data-note]:focus-visible::after {
            content: attr(data-note);
            position: absolute;
            z-index: 5;
            left: 0;
            bottom: calc(100% + 8px);
            width: max-content;
            max-width: min(260px, 70vw);
            padding: 8px 10px;
            border: 1px solid var(--line);
            border-radius: 6px;
            background: var(--panel);
            box-shadow: 0 10px 24px var(--shadow);
            color: var(--ink);
            font-size: 0.82rem;
            font-weight: 700;
            line-height: 1.35;
            white-space: normal;
        }

        .calendar-dialog {
            width: min(520px, calc(100% - 28px));
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 0;
            background: var(--panel);
            color: var(--ink);
            box-shadow: 0 18px 42px var(--lock-shadow);
        }

        .calendar-dialog::backdrop {
            background: var(--overlay);
            backdrop-filter: blur(8px);
        }

        .calendar-dialog form {
            padding: 18px;
        }

        .dialog-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            margin-bottom: 14px;
        }

        .dialog-header h2 {
            margin: 0;
            font-size: 1.1rem;
        }

        .dialog-close {
            display: inline-grid;
            place-items: center;
            width: 34px;
            min-height: 34px;
            padding: 0;
            border: 1px solid var(--line);
            background: transparent;
            color: var(--muted);
        }

        .dialog-close:hover,
        .dialog-close:focus-visible {
            background: var(--hover);
            color: var(--accent-dark);
        }

        .dialog-fields {
            display: grid;
            gap: 12px;
        }

        .dialog-field {
            display: grid;
            gap: 8px;
        }

        .dialog-field label,
        .dialog-stars legend {
            position: static;
            width: auto;
            height: auto;
            overflow: visible;
            clip: auto;
            color: var(--muted);
            font-size: 0.88rem;
            font-weight: 800;
        }

        .dialog-stars {
            min-width: 0;
            margin: 0;
            padding: 0;
            border: 0;
        }

        .dialog-stars .stars {
            justify-content: flex-end;
        }

        .dialog-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 16px;
        }

        .timeline {
            display: grid;
            gap: 22px;
            margin-top: 30px;
        }

        .day {
            padding-top: 20px;
            border-top: 1px solid var(--line);
        }

        .day h2,
        .period h3 {
            margin: 0 0 12px;
            font-size: 1.05rem;
            color: var(--muted);
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        .plain-list {
            margin: 0;
            padding: 14px 18px 14px 34px;
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 8px;
            line-height: 1.5;
        }

        .plain-list li {
            padding: 4px 0;
            line-height: 1.45;
            overflow-wrap: anywhere;
        }

        .icon-button {
            display: inline-grid;
            place-items: center;
            width: 34px;
            min-height: 34px;
            padding: 0;
            border: 1px solid var(--line);
            background: transparent;
            color: var(--muted);
        }

        .icon-button:hover,
        .icon-button:focus-visible {
            background: var(--hover);
            color: var(--accent-dark);
        }

        .danger-button:hover,
        .danger-button:focus-visible {
            background: var(--danger-soft);
            color: var(--danger);
        }

        .planner {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
            margin-top: 16px;
        }

        .uncompleted {
            margin-top: 16px;
            padding: 14px;
            background: var(--warm-panel);
            border: 1px solid var(--warm-line);
            border-radius: 8px;
        }

        .uncompleted h3 {
            margin: 0 0 12px;
            font-size: 1.05rem;
            color: var(--muted);
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        .source-date {
            display: block;
            margin-top: 3px;
            color: var(--muted);
            font-size: 0.82rem;
            font-style: italic;
        }

        .period {
            min-height: 180px;
            padding: 14px;
            background: var(--soft-panel);
            border: 1px solid var(--line);
            border-radius: 8px;
        }

        .period.is-over {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px var(--drop-ring);
        }

        .todo-list {
            display: grid;
            gap: 10px;
            min-height: 94px;
            margin: 0;
            padding: 0;
            list-style: none;
        }

        .todo {
            display: grid;
            grid-template-columns: 22px minmax(0, 1fr) auto;
            align-items: start;
            gap: 10px;
            padding: 10px 12px;
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 6px;
            cursor: grab;
            line-height: 1.4;
            overflow-wrap: anywhere;
        }

        .todo input[type="checkbox"] {
            width: 18px;
            height: 18px;
            margin: 1px 0 0;
            accent-color: var(--accent);
            cursor: pointer;
        }

        .todo-text {
            min-width: 0;
        }

        .todo.is-complete .todo-text {
            color: var(--muted);
            text-decoration: line-through;
        }

        .todo-delete-form {
            margin: -3px 0 0;
        }

        .todo-delete-button {
            min-height: 28px;
            border: 1px solid var(--line);
            border-radius: 5px;
            padding: 0 9px;
            background: transparent;
            color: var(--danger);
            font-size: 0.78rem;
            font-weight: 800;
        }

        .todo-delete-button:hover,
        .todo-delete-button:focus-visible {
            background: var(--danger-soft);
        }

        .completed-time {
            margin-left: 8px;
            color: var(--muted);
            font-size: 0.85rem;
            font-style: italic;
            white-space: nowrap;
        }

        .todo:active {
            cursor: grabbing;
        }

        .todo.is-dragging {
            opacity: 0.45;
        }

        .empty {
            margin-top: 28px;
            padding: 18px;
            border: 1px dashed var(--line);
            border-radius: 8px;
            color: var(--muted);
            background: var(--empty-bg);
        }

        .app-shell.is-blurred {
            filter: blur(8px);
            pointer-events: none;
            user-select: none;
        }

        .lock-screen {
            position: fixed;
            inset: 0;
            z-index: 10;
            display: grid;
            place-items: center;
            padding: 24px;
            background: var(--overlay);
            backdrop-filter: blur(10px);
        }

        .lock-screen.is-hidden {
            display: none;
        }

        .lock-card {
            width: min(380px, 100%);
            padding: 20px;
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 8px;
            box-shadow: 0 18px 42px var(--lock-shadow);
        }

        .lock-card h2 {
            margin: 0 0 12px;
            font-size: 1.25rem;
        }

        .lock-card form {
            display: grid;
            gap: 12px;
        }

        @media (max-width: 760px) {
            .planner {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 620px) {
            main {
                width: min(100% - 8px, 980px);
                padding-top: 10px;
                padding-bottom: 19px;
            }

            .primary-nav {
                justify-content: flex-start;
            }

            .primary-menu-toggle {
                display: inline-flex;
                width: auto;
                min-height: 44px;
                align-items: center;
                justify-content: center;
                gap: 8px;
                border: 1px solid var(--line);
                border-radius: 8px;
                padding: 0 5px;
                background: var(--panel);
                color: var(--accent-dark);
                font-size: 1rem;
                font-weight: 800;
            }

            .primary-menu-items {
                display: none;
                width: 100%;
                flex-direction: column;
                align-items: stretch;
                gap: 8px;
                padding: 4px;
                margin-top: 10px;
                border: 1px solid var(--line);
                border-radius: 8px;
                background: var(--panel);
            }

            .primary-menu-items.is-open {
                display: flex;
            }

            .primary-menu-items a,
            .primary-menu-items .theme-toggle {
                display: flex;
                min-height: 40px;
                align-items: center;
                justify-content: center;
                border: 1px solid var(--line);
                border-radius: 6px;
                padding: 0 4px;
                background: transparent;
            }

            .primary-menu-items .theme-toggle {
                width: 100%;
            }

            .stage-header,
            .entry {
                grid-template-columns: 1fr;
            }

            .stage-header {
                align-items: stretch;
                display: grid;
            }

            button {
                width: 100%;
                padding-right: 6px;
                padding-left: 6px;
            }

            button.theme-toggle {
                width: auto;
            }

            .primary-menu-items button.theme-toggle {
                width: 100%;
            }

            .todo-delete-button {
                width: auto;
            }

            input[type="text"],
            input[type="password"],
            input[type="date"],
            textarea {
                padding-right: 5px;
                padding-left: 5px;
            }

            .panel,
            .summary-panel,
            .reflection,
            .stage,
            .period,
            .todo,
            .day,
            .empty,
            .calendar-empty,
            .lock-screen,
            .lock-card {
                padding: 6px;
            }

            .reflection-grid {
                grid-template-columns: 1fr;
            }

            .stars label {
                font-size: clamp(3.2rem, 17vw, 4.4rem);
            }

            .reflection {
                padding: 16px 10px;
            }

            .reflection .check-in-title,
            .reflection-prompt {
                text-align: center;
            }

            .reflection .stars {
                justify-self: center;
            }

            .reflection-stars {
                font-size: 3.4rem;
            }

            .calendar {
                padding: 5px;
            }

            .calendar-header {
                align-items: flex-start;
            }

            .calendar-title-full,
            .calendar-nav,
            .calendar-summary,
            .calendar-grid {
                display: none;
            }

            .calendar-title-recent {
                display: inline;
            }

            .calendar-recent {
                display: grid;
                gap: 10px;
            }

            .calendar-recent .calendar-day {
                min-height: 0;
                padding: 4px;
            }

            .calendar-weekday {
                font-size: 0.66rem;
            }

            .calendar-date {
                font-size: 0.72rem;
            }

            .calendar-recent .calendar-date {
                font-size: 0.95rem;
            }

            .calendar-metric {
                font-size: 0.68rem;
            }

            .calendar-recent .calendar-metric {
                font-size: 0.82rem;
            }

            .calendar-stars {
                font-size: 1.6rem;
            }

        }
    </style>
</head>
<body class="<?= $isNightMode ? 'night-mode' : '' ?>">
    <main class="app-shell<?= $isAuthenticated ? '' : ' is-blurred' ?>" data-app-shell>
        <?= kataRenderGlobalFocus(kataLoadMainFocus()) ?>

        <nav class="primary-nav" aria-label="Primary">
            <button class="primary-menu-toggle" type="button" aria-expanded="false" aria-controls="primary-menu" data-primary-menu-toggle>
                <span aria-hidden="true">&#9776;</span>
                <span data-primary-menu-label>Menu</span>
            </button>
            <div class="primary-menu-items" id="primary-menu" data-primary-menu>
                <a href="finance.php">Money Kata</a>
                <a href="social.php">Social Kata</a>
                <a href="three-month-goals.php">3 Month Goals</a>
                <button class="theme-toggle" type="button" data-theme-toggle aria-label="Toggle theme">Night</button>
            </div>
        </nav>

        <?php $todayView = $data['days'][$todayKey]; ?>
        <?php
            $reflection = $todayView['reflection'];
            $checkIns = array_reverse((array)($reflection['check_ins'] ?? []));
        ?>

        <header class="masthead">
            <h1 class="page-title">Daily Kata</h1>
            <p class="subtitle">
                <?= $isWeekday
                    ? 'Catch the thoughts as they arrive, then shape the evening from getting home through bedtime.'
                    : 'Catch the thoughts as they arrive, then turn the day into a simple morning, midday, and afternoon plan.'
                ?>
            </p>
        </header>

        <section class="reflection" aria-labelledby="reflection-stage">
            <h2 class="check-in-title" id="reflection-stage">How are you feeling right now?</h2>
            <p class="reflection-prompt">Take a quick pulse check. Come back and rate the day again whenever it changes.</p>
            <form class="reflection-form" method="post" action="">
                <input type="hidden" name="action" value="save_check_in">
                <div class="reflection-grid">
                    <fieldset class="stars" aria-label="Current happiness rating">
                        <?php for ($step = 10; $step >= 1; $step--): ?>
                            <?php $rating = $step / 2; ?>
                            <input id="happiness-<?= $step ?>" name="happiness" type="radio" value="<?= htmlspecialchars(formatRating($rating), ENT_QUOTES, 'UTF-8') ?>" required>
                            <label for="happiness-<?= $step ?>" data-half="<?= $step % 2 === 0 ? 'right' : 'left' ?>" title="<?= htmlspecialchars(formatRating($rating), ENT_QUOTES, 'UTF-8') ?> star<?= abs($rating - 1.0) < 0.01 ? '' : 's' ?>"><span>&#9733;</span></label>
                        <?php endfor; ?>
                    </fieldset>
                    <div class="reflection-note">
                        <label for="reflection-note">What is shaping this rating?</label>
                        <input id="reflection-note" name="reflection_note" type="text" maxlength="220" placeholder="One line about how this moment feels.">
                    </div>
                    <button type="submit">Save Check-in</button>
                </div>
            </form>
            <?php if ($checkIns !== []): ?>
                <div class="check-in-history" aria-label="Today's recent check-ins">
                    <?php foreach (array_slice($checkIns, 0, 4) as $checkIn): ?>
                        <?php $checkInRating = normalizeRating($checkIn['happiness'] ?? 0); ?>
                        <div class="check-in-history-item" title="<?= htmlspecialchars((string)($checkIn['note'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            <span class="check-in-history-stars" aria-label="<?= htmlspecialchars(formatRating($checkInRating), ENT_QUOTES, 'UTF-8') ?> out of 5"><?= renderStarRating($checkInRating) ?></span>
                            <span><?= htmlspecialchars(formatCompletedTime((string)($checkIn['created_at'] ?? '')), ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <?php if (!$todayView['goals_complete']): ?>
            <section class="stage" aria-labelledby="goals-stage">
                <div class="stage-header">
                    <h2 class="stage-title" id="goals-stage">Goal Writing</h2>
                    <?php if ($todayView['goals'] !== []): ?>
                        <form method="post" action="">
                            <input type="hidden" name="action" value="complete_goals">
                            <button class="secondary-button" type="submit">Complete Goal Writing</button>
                        </form>
                    <?php endif; ?>
                </div>

                <form class="entry" method="post" action="">
                    <input type="hidden" name="action" value="add_goal">
                    <label for="goal">Goal</label>
                    <input id="goal" name="goal" type="text" maxlength="500" placeholder="What is on your mind today?" autofocus required>
                    <button type="submit">Add Goal</button>
                </form>
            </section>
        <?php else: ?>
            <section class="stage" aria-labelledby="todos-stage">
                <div class="stage-header">
                    <h2 class="stage-title" id="todos-stage">Today's Todos</h2>
                </div>

                <form class="entry" method="post" action="">
                    <input type="hidden" name="action" value="add_todo">
                    <label for="todo">Todo</label>
                    <input id="todo" name="todo" type="text" maxlength="500" placeholder="What needs doing today?" autofocus required>
                    <button type="submit">Add Todo</button>
                </form>

                <div class="planner" aria-label="Todo planner">
                    <?php foreach ($periods as $periodKey => $periodLabel): ?>
                        <section class="period" data-period="<?= htmlspecialchars($periodKey, ENT_QUOTES, 'UTF-8') ?>">
                            <h3><?= htmlspecialchars($periodLabel, ENT_QUOTES, 'UTF-8') ?></h3>
                            <ul class="todo-list">
                                <?php foreach ($todayView['todos'][$periodKey] as $todo): ?>
                                    <?php
                                        $todoId = (string)$todo['id'];
                                        $isComplete = (bool)($todo['complete'] ?? false);
                                        $completedTime = formatCompletedTime((string)($todo['completed_at'] ?? ''));
                                    ?>
                                    <li class="todo<?= $isComplete ? ' is-complete' : '' ?>" draggable="true" data-todo-id="<?= htmlspecialchars($todoId, ENT_QUOTES, 'UTF-8') ?>" data-source-date="<?= htmlspecialchars($todayKey, ENT_QUOTES, 'UTF-8') ?>">
                                        <input
                                            type="checkbox"
                                            aria-label="Mark todo complete"
                                            <?= $isComplete ? 'checked' : '' ?>
                                        >
                                        <span>
                                            <span class="todo-text"><?= htmlspecialchars((string)($todo['text'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                                            <?php if ($isComplete && $completedTime !== ''): ?>
                                                <span class="completed-time">completed <?= htmlspecialchars($completedTime, ENT_QUOTES, 'UTF-8') ?></span>
                                            <?php endif; ?>
                                        </span>
                                        <?php if (!$isComplete): ?>
                                            <form class="todo-delete-form" method="post" action="">
                                                <input type="hidden" name="action" value="delete_todo">
                                                <input type="hidden" name="todo_id" value="<?= htmlspecialchars($todoId, ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="source_date" value="<?= htmlspecialchars($todayKey, ENT_QUOTES, 'UTF-8') ?>">
                                                <button class="todo-delete-button" type="submit">Remove</button>
                                            </form>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </section>
                    <?php endforeach; ?>
                </div>

                <?php if ($uncompletedTodos !== []): ?>
                    <section class="uncompleted" aria-labelledby="uncompleted-stage">
                        <h3 id="uncompleted-stage">Uncompleted</h3>
                        <ul class="todo-list">
                            <?php foreach ($uncompletedTodos as $todo): ?>
                                <?php
                                    $todoId = (string)$todo['id'];
                                    $sourceDate = (string)$todo['source_date'];
                                ?>
                                <li class="todo" draggable="true" data-todo-id="<?= htmlspecialchars($todoId, ENT_QUOTES, 'UTF-8') ?>" data-source-date="<?= htmlspecialchars($sourceDate, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="checkbox" aria-label="Mark todo complete">
                                    <span>
                                        <span class="todo-text"><?= htmlspecialchars((string)($todo['text'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                                        <span class="source-date"><?= htmlspecialchars(formatDateHeader($sourceDate), ENT_QUOTES, 'UTF-8') ?></span>
                                    </span>
                                    <form class="todo-delete-form" method="post" action="">
                                        <input type="hidden" name="action" value="delete_todo">
                                        <input type="hidden" name="todo_id" value="<?= htmlspecialchars($todoId, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="source_date" value="<?= htmlspecialchars($sourceDate, ENT_QUOTES, 'UTF-8') ?>">
                                        <button class="todo-delete-button" type="submit">Remove</button>
                                    </form>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </section>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <p class="notice"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>

        <section class="calendar" aria-labelledby="calendar-title">
            <div class="calendar-header">
                <h2 id="calendar-title">
                    <span class="calendar-title-full"><?= htmlspecialchars($calendarMonth->format('F Y'), ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="calendar-title-recent">Recent Days</span>
                </h2>
                <div class="calendar-nav" aria-label="Calendar navigation">
                    <a href="?month=<?= htmlspecialchars($calendarPreviousMonth, ENT_QUOTES, 'UTF-8') ?>" aria-label="Previous month">&lsaquo;</a>
                    <a href="?month=<?= htmlspecialchars($calendarNextMonth, ENT_QUOTES, 'UTF-8') ?>" aria-label="Next month">&rsaquo;</a>
                </div>
            </div>

            <div class="calendar-summary" aria-label="Monthly trend summary">
                <?php if ($calendarStats['average_rating'] !== null): ?>
                    <span class="stat-pill">Avg rating: <?= number_format((float)$calendarStats['average_rating'], 1) ?>/5</span>
                <?php endif; ?>
                <?php if ($calendarStats['follower_change'] !== null): ?>
                    <span class="stat-pill">Follower change: <?= (int)$calendarStats['follower_change'] >= 0 ? '+' : '' ?><?= number_format((int)$calendarStats['follower_change']) ?></span>
                <?php endif; ?>
                <span class="stat-pill"><?= (int)$calendarStats['rating_days'] ?> rating day<?= (int)$calendarStats['rating_days'] === 1 ? '' : 's' ?></span>
                <span class="stat-pill"><?= (int)$calendarStats['follower_days'] ?> follower check-in<?= (int)$calendarStats['follower_days'] === 1 ? '' : 's' ?></span>
                <span class="stat-pill"><?= (int)$calendarFinanceStats['tally_days'] ?> money tall<?= (int)$calendarFinanceStats['tally_days'] === 1 ? 'y' : 'ies' ?></span>
            </div>

            <div class="calendar-grid" aria-label="Daily rating, TikTok follower, and money tally calendar">
                <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $weekday): ?>
                    <div class="calendar-weekday"><?= htmlspecialchars($weekday, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endforeach; ?>

                <?php for ($empty = 0; $empty < $calendarStartOffset; $empty++): ?>
                    <div class="calendar-day is-empty" aria-hidden="true"></div>
                <?php endfor; ?>

                <?php for ($dayNumber = 1; $dayNumber <= $calendarDaysInMonth; $dayNumber++): ?>
                    <?php
                        $calendarDate = sprintf('%s-%02d', $calendarMonthKey, $dayNumber);
                        $calendarDay = normalizeDay((array)($data['days'][$calendarDate] ?? []));
                        $calendarReflection = $calendarDay['reflection'];
                        $calendarRating = normalizeRating($calendarReflection['happiness'] ?? 0);
                        $calendarFollowers = $calendarReflection['tiktok_followers'];
                        $calendarNote = trim((string)($calendarReflection['note'] ?? ''));
                        $calendarFinanceEntry = $financeData['entries'][$calendarDate] ?? null;
                        $calendarFinanceTotals = $calendarFinanceEntry !== null
                            ? calculateFinanceCalendarTotals($financeData['accounts'], $calendarFinanceEntry)
                            : null;
                        $hasCalendarInputs = $calendarRating > 0 || $calendarFollowers !== null || $calendarFinanceTotals !== null;
                    ?>
                    <div
                        class="calendar-day<?= $calendarDate === $todayKey ? ' is-today' : '' ?>"
                        role="button"
                        tabindex="0"
                        aria-label="Edit reflection for <?= htmlspecialchars(formatDateHeader($calendarDate), ENT_QUOTES, 'UTF-8') ?>"
                        data-calendar-day
                        data-date="<?= htmlspecialchars($calendarDate, ENT_QUOTES, 'UTF-8') ?>"
                        data-date-label="<?= htmlspecialchars(formatDateHeader($calendarDate), ENT_QUOTES, 'UTF-8') ?>"
                        data-rating="<?= htmlspecialchars(formatRating($calendarRating), ENT_QUOTES, 'UTF-8') ?>"
                        data-followers="<?= $calendarFollowers !== null ? htmlspecialchars((string)$calendarFollowers, ENT_QUOTES, 'UTF-8') : '' ?>"
                        data-note="<?= htmlspecialchars($calendarNote, ENT_QUOTES, 'UTF-8') ?>"
                    >
                        <span class="calendar-date"><?= $dayNumber ?></span>
                        <?php if ($hasCalendarInputs): ?>
                            <div class="calendar-metrics">
                                <?php if ($calendarRating > 0): ?>
                                    <span
                                        class="calendar-metric calendar-stars"
                                        aria-label="Rating <?= htmlspecialchars(formatRating($calendarRating), ENT_QUOTES, 'UTF-8') ?> out of 5<?= $calendarNote !== '' ? ': ' . htmlspecialchars($calendarNote, ENT_QUOTES, 'UTF-8') : '' ?>"
                                        tabindex="0"
                                        <?= $calendarNote !== '' ? 'data-note="' . htmlspecialchars($calendarNote, ENT_QUOTES, 'UTF-8') . '"' : '' ?>
                                    ><?= renderStarRating($calendarRating) ?></span>
                                <?php endif; ?>
                                <?php if ($calendarFollowers !== null): ?>
                                    <span class="calendar-metric">TikTok <?= number_format((int)$calendarFollowers) ?></span>
                                <?php endif; ?>
                                <?php if ($calendarFinanceTotals !== null): ?>
                                    <a class="calendar-metric calendar-money" href="finance.php" aria-label="Money tally net <?= htmlspecialchars(formatCalendarMoney((float)$calendarFinanceTotals['net']), ENT_QUOTES, 'UTF-8') ?>">
                                        Money <?= htmlspecialchars(formatCalendarMoney((float)$calendarFinanceTotals['net']), ENT_QUOTES, 'UTF-8') ?>
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endfor; ?>
            </div>

            <div class="calendar-recent" aria-label="Recent day ratings and money tallies">
                <?php for ($offset = 2; $offset >= 0; $offset--): ?>
                    <?php
                        $recentDate = $now->modify('-' . $offset . ' days')->format('Y-m-d');
                        $recentDay = normalizeDay((array)($data['days'][$recentDate] ?? []));
                        $recentReflection = $recentDay['reflection'];
                        $recentRating = normalizeRating($recentReflection['happiness'] ?? 0);
                        $recentFollowers = $recentReflection['tiktok_followers'];
                        $recentNote = trim((string)($recentReflection['note'] ?? ''));
                        $recentFinanceEntry = $financeData['entries'][$recentDate] ?? null;
                        $recentFinanceTotals = $recentFinanceEntry !== null
                            ? calculateFinanceCalendarTotals($financeData['accounts'], $recentFinanceEntry)
                            : null;
                        $hasRecentInputs = $recentRating > 0 || $recentFollowers !== null || $recentFinanceTotals !== null;
                    ?>
                    <div
                        class="calendar-day<?= $recentDate === $todayKey ? ' is-today' : '' ?>"
                        role="button"
                        tabindex="0"
                        aria-label="Edit reflection for <?= htmlspecialchars(formatDateHeader($recentDate), ENT_QUOTES, 'UTF-8') ?>"
                        data-calendar-day
                        data-date="<?= htmlspecialchars($recentDate, ENT_QUOTES, 'UTF-8') ?>"
                        data-date-label="<?= htmlspecialchars(formatDateHeader($recentDate), ENT_QUOTES, 'UTF-8') ?>"
                        data-rating="<?= htmlspecialchars(formatRating($recentRating), ENT_QUOTES, 'UTF-8') ?>"
                        data-followers="<?= $recentFollowers !== null ? htmlspecialchars((string)$recentFollowers, ENT_QUOTES, 'UTF-8') : '' ?>"
                        data-note="<?= htmlspecialchars($recentNote, ENT_QUOTES, 'UTF-8') ?>"
                    >
                        <span class="calendar-recent-label"><?= $offset === 0 ? 'Today' : ($offset === 1 ? 'Yesterday' : 'Two days ago') ?></span>
                        <span class="calendar-date"><?= htmlspecialchars(formatDateHeader($recentDate), ENT_QUOTES, 'UTF-8') ?></span>
                        <?php if ($hasRecentInputs): ?>
                            <div class="calendar-metrics">
                                <?php if ($recentRating > 0): ?>
                                    <span
                                        class="calendar-metric calendar-stars"
                                        aria-label="Rating <?= htmlspecialchars(formatRating($recentRating), ENT_QUOTES, 'UTF-8') ?> out of 5<?= $recentNote !== '' ? ': ' . htmlspecialchars($recentNote, ENT_QUOTES, 'UTF-8') : '' ?>"
                                        tabindex="0"
                                        <?= $recentNote !== '' ? 'data-note="' . htmlspecialchars($recentNote, ENT_QUOTES, 'UTF-8') . '"' : '' ?>
                                    ><?= renderStarRating($recentRating) ?></span>
                                <?php endif; ?>
                                <?php if ($recentFollowers !== null): ?>
                                    <span class="calendar-metric">TikTok <?= number_format((int)$recentFollowers) ?></span>
                                <?php endif; ?>
                                <?php if ($recentFinanceTotals !== null): ?>
                                    <a class="calendar-metric calendar-money" href="finance.php" aria-label="Money tally net <?= htmlspecialchars(formatCalendarMoney((float)$recentFinanceTotals['net']), ENT_QUOTES, 'UTF-8') ?>">
                                        Money <?= htmlspecialchars(formatCalendarMoney((float)$recentFinanceTotals['net']), ENT_QUOTES, 'UTF-8') ?>
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <p class="calendar-empty">No reflection saved yet.</p>
                        <?php endif; ?>
                    </div>
                <?php endfor; ?>
            </div>
        </section>

        <section class="timeline" aria-label="Daily history">
            <?php foreach ($data['days'] as $date => $day): ?>
                <?php $day = normalizeDay((array)$day); ?>
                <article class="day">
                    <h2><?= htmlspecialchars(formatDateHeader((string)$date), ENT_QUOTES, 'UTF-8') ?></h2>
                    <?php
                        $dayReflection = $day['reflection'];
                        $dayHappiness = normalizeRating($dayReflection['happiness'] ?? 0);
                        $dayFollowers = $dayReflection['tiktok_followers'];
                    ?>

                    <?php if ($dayHappiness > 0 || $dayFollowers !== null): ?>
                        <div class="day-stats" aria-label="Daily stats">
                            <?php if ($dayHappiness > 0): ?>
                                <span class="stat-pill">Rating: <?= htmlspecialchars(formatRating($dayHappiness), ENT_QUOTES, 'UTF-8') ?>/5</span>
                            <?php endif; ?>
                            <?php if ($dayFollowers !== null): ?>
                                <span class="stat-pill">TikTok followers: <?= number_format((int)$dayFollowers) ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($day['goals'] !== []): ?>
                        <ul class="plain-list">
                            <?php foreach (array_reverse($day['goals']) as $goal): ?>
                                <li><?= htmlspecialchars((string)($goal['text'] ?? ''), ENT_QUOTES, 'UTF-8') ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="empty">No goals written for this day yet.</p>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </section>
    </main>

    <dialog class="calendar-dialog" data-calendar-dialog aria-labelledby="calendar-dialog-title">
        <form method="post" action="">
            <input type="hidden" name="action" value="save_calendar_reflection">
            <input type="hidden" name="entry_date" value="" data-calendar-dialog-date>
            <div class="dialog-header">
                <h2 id="calendar-dialog-title">Edit Reflection</h2>
                <button class="dialog-close" type="button" aria-label="Close reflection dialog" data-calendar-dialog-close>&times;</button>
            </div>
            <div class="dialog-fields">
                <fieldset class="dialog-stars" aria-label="Happiness rating">
                    <legend>Rating</legend>
                    <div class="stars">
                        <?php for ($step = 10; $step >= 1; $step--): ?>
                            <?php $rating = $step / 2; ?>
                            <input id="calendar-happiness-<?= $step ?>" name="happiness" type="radio" value="<?= htmlspecialchars(formatRating($rating), ENT_QUOTES, 'UTF-8') ?>" required data-calendar-rating>
                            <label for="calendar-happiness-<?= $step ?>" data-half="<?= $step % 2 === 0 ? 'right' : 'left' ?>" title="<?= htmlspecialchars(formatRating($rating), ENT_QUOTES, 'UTF-8') ?> star<?= abs($rating - 1.0) < 0.01 ? '' : 's' ?>"><span>&#9733;</span></label>
                        <?php endfor; ?>
                    </div>
                </fieldset>
                <div class="dialog-field">
                    <label for="calendar-tiktok-followers">TikTok follower count</label>
                    <input id="calendar-tiktok-followers" name="tiktok_followers" type="text" inputmode="numeric" pattern="[0-9,]*" maxlength="12" placeholder="TikTok followers" data-calendar-followers>
                </div>
                <div class="dialog-field">
                    <label for="calendar-reflection-note">Why was or was not this a happy day?</label>
                    <input id="calendar-reflection-note" name="reflection_note" type="text" maxlength="220" placeholder="One line on why that day felt that way." data-calendar-note>
                </div>
            </div>
            <div class="dialog-actions">
                <button class="secondary-button" type="button" data-calendar-dialog-cancel>Cancel</button>
                <button type="submit">Save Day</button>
            </div>
        </form>
    </dialog>

    <section class="lock-screen<?= $isAuthenticated ? ' is-hidden' : '' ?>" aria-labelledby="lock-title" data-lock-screen>
        <div class="lock-card">
            <h2 id="lock-title">Unlock Daily Kata</h2>
            <form method="post" action="">
                <input type="hidden" name="action" value="unlock_app">
                <label for="app-password">Password</label>
                <input id="app-password" name="password" type="password" placeholder="Password" autocomplete="current-password" required data-lock-password>
                <button type="submit">Unlock</button>
            </form>
            <?php if ($authError !== ''): ?>
                <p class="notice"><?= htmlspecialchars($authError, ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>
        </div>
    </section>

    <script>
        const themeToggle = document.querySelector('[data-theme-toggle]');
        const themeOverrideKey = 'kata_theme_override';
        const themeToday = <?= json_encode($todayKey) ?>;
        const themeAutoNight = <?= $isNightMode ? 'true' : 'false' ?>;

        function setThemeMode(mode) {
            document.body.classList.toggle('night-mode', mode === 'night');
            if (themeToggle) {
                const nextMode = mode === 'night' ? 'day' : 'night';
                themeToggle.textContent = nextMode === 'night' ? 'Night' : 'Day';
                themeToggle.setAttribute('aria-label', `Switch to ${nextMode} mode`);
                themeToggle.setAttribute('title', `Switch to ${nextMode} mode`);
            }
        }

        try {
            const storedTheme = JSON.parse(window.localStorage.getItem(themeOverrideKey) || 'null');
            if (storedTheme && storedTheme.date === themeToday && ['day', 'night'].includes(storedTheme.mode)) {
                setThemeMode(storedTheme.mode);
            } else {
                window.localStorage.removeItem(themeOverrideKey);
                setThemeMode(themeAutoNight ? 'night' : 'day');
            }
        } catch (error) {
            setThemeMode(themeAutoNight ? 'night' : 'day');
        }

        themeToggle?.addEventListener('click', () => {
            const nextMode = document.body.classList.contains('night-mode') ? 'day' : 'night';
            setThemeMode(nextMode);
            window.localStorage.setItem(themeOverrideKey, JSON.stringify({ date: themeToday, mode: nextMode }));
        });

        <?= kataGlobalFocusScript() ?>

        const primaryMenuToggle = document.querySelector('[data-primary-menu-toggle]');
        const primaryMenu = document.querySelector('[data-primary-menu]');
        const primaryMenuLabel = document.querySelector('[data-primary-menu-label]');

        if (primaryMenuToggle && primaryMenu) {
            primaryMenuToggle.addEventListener('click', () => {
                const isOpen = primaryMenu.classList.toggle('is-open');
                primaryMenuToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                if (primaryMenuLabel) {
                    primaryMenuLabel.textContent = isOpen ? 'Close' : 'Menu';
                }
            });
        }

        const appShell = document.querySelector('[data-app-shell]');
        const lockScreen = document.querySelector('[data-lock-screen]');
        const lockPassword = document.querySelector('[data-lock-password]');
        const lockAfterMs = 5 * 60 * 1000;
        const appIsAuthenticated = <?= $isAuthenticated ? 'true' : 'false' ?>;
        let lockTimer = null;

        function showLockScreen() {
            appShell?.classList.add('is-blurred');
            lockScreen?.classList.remove('is-hidden');
            lockPassword?.focus();
        }

        function resetLockTimer() {
            if (!appIsAuthenticated) {
                return;
            }

            window.clearTimeout(lockTimer);
            lockTimer = window.setTimeout(showLockScreen, lockAfterMs);
        }

        if (appIsAuthenticated) {
            ['click', 'keydown', 'mousemove', 'touchstart', 'scroll'].forEach((eventName) => {
                window.addEventListener(eventName, resetLockTimer, { passive: true });
            });
            resetLockTimer();
        } else {
            showLockScreen();
        }

        const draggedClass = 'is-dragging';
        const todayKey = <?= json_encode($todayKey) ?>;
        let draggedTodo = null;
        const calendarDialog = document.querySelector('[data-calendar-dialog]');
        const calendarDialogTitle = document.querySelector('#calendar-dialog-title');
        const calendarDialogDate = document.querySelector('[data-calendar-dialog-date]');
        const calendarDialogFollowers = document.querySelector('[data-calendar-followers]');
        const calendarDialogNote = document.querySelector('[data-calendar-note]');
        const calendarDialogClose = document.querySelector('[data-calendar-dialog-close]');
        const calendarDialogCancel = document.querySelector('[data-calendar-dialog-cancel]');
        const calendarRatingInputs = document.querySelectorAll('[data-calendar-rating]');

        function openCalendarDialog(day) {
            if (!calendarDialog || !calendarDialogDate || !calendarDialogFollowers || !calendarDialogNote) {
                return;
            }

            calendarDialogDate.value = day.dataset.date || '';
            calendarDialogFollowers.value = day.dataset.followers || '';
            calendarDialogNote.value = day.dataset.note || '';

            if (calendarDialogTitle) {
                calendarDialogTitle.textContent = day.dataset.dateLabel || 'Edit Reflection';
            }

            const rating = day.dataset.rating || '';
            calendarRatingInputs.forEach((input) => {
                input.checked = input.value === rating;
            });

            if (typeof calendarDialog.showModal === 'function') {
                calendarDialog.showModal();
            } else {
                calendarDialog.setAttribute('open', '');
            }

            const checkedRating = Array.from(calendarRatingInputs).find((input) => input.checked);
            (checkedRating || calendarRatingInputs[calendarRatingInputs.length - 1] || calendarDialogNote).focus();
        }

        function closeCalendarDialog() {
            if (!calendarDialog) {
                return;
            }

            if (typeof calendarDialog.close === 'function') {
                calendarDialog.close();
            } else {
                calendarDialog.removeAttribute('open');
            }
        }

        document.querySelectorAll('[data-calendar-day]').forEach((day) => {
            day.addEventListener('click', (event) => {
                if (event.target instanceof Element && event.target.closest('a')) {
                    return;
                }

                openCalendarDialog(day);
            });

            day.addEventListener('keydown', (event) => {
                if (event.key !== 'Enter' && event.key !== ' ') {
                    return;
                }

                event.preventDefault();
                openCalendarDialog(day);
            });
        });

        calendarDialogClose?.addEventListener('click', closeCalendarDialog);
        calendarDialogCancel?.addEventListener('click', closeCalendarDialog);

        document.querySelectorAll('.todo').forEach((todo) => {
            const checkbox = todo.querySelector('input[type="checkbox"]');

            if (checkbox) {
                checkbox.addEventListener('click', (event) => {
                    event.stopPropagation();
                });

                checkbox.addEventListener('dragstart', (event) => {
                    event.preventDefault();
                    event.stopPropagation();
                });

                checkbox.addEventListener('change', async () => {
                    todo.classList.toggle('is-complete', checkbox.checked);
                    const textWrap = todo.querySelector('.todo-text')?.parentElement;

                    const formData = new FormData();
                    formData.append('action', 'toggle_todo');
                    formData.append('todo_id', todo.dataset.todoId);
                    formData.append('source_date', todo.dataset.sourceDate || todayKey);
                    formData.append('complete', checkbox.checked ? '1' : '0');

                    try {
                        const response = await fetch('', {
                            method: 'POST',
                            body: formData,
                        });

                        const result = await response.json();

                        if (!response.ok || !result.ok) {
                            window.location.reload();
                        }

                        todo.querySelector('.completed-time')?.remove();

                        if (checkbox.checked && textWrap && result.completed_time) {
                            const completedTime = document.createElement('span');
                            completedTime.className = 'completed-time';
                            completedTime.textContent = `completed ${result.completed_time}`;
                            textWrap.appendChild(completedTime);
                        }
                    } catch (error) {
                        window.location.reload();
                    }
                });
            }

            todo.addEventListener('dragstart', (event) => {
                if (event.target instanceof HTMLInputElement) {
                    event.preventDefault();
                    return;
                }

                draggedTodo = todo;
                todo.classList.add(draggedClass);
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', todo.dataset.todoId);
            });

            todo.addEventListener('dragend', () => {
                todo.classList.remove(draggedClass);
                draggedTodo = null;
            });
        });

        document.querySelectorAll('.period').forEach((period) => {
            period.addEventListener('dragover', (event) => {
                event.preventDefault();
                period.classList.add('is-over');
            });

            period.addEventListener('dragleave', () => {
                period.classList.remove('is-over');
            });

            period.addEventListener('drop', async (event) => {
                event.preventDefault();
                period.classList.remove('is-over');

                if (!draggedTodo) {
                    return;
                }

                const movedTodo = draggedTodo;
                const targetList = period.querySelector('.todo-list');
                targetList.appendChild(movedTodo);

                const formData = new FormData();
                formData.append('action', 'move_todo');
                formData.append('todo_id', movedTodo.dataset.todoId);
                formData.append('source_date', movedTodo.dataset.sourceDate || todayKey);
                formData.append('target_period', period.dataset.period);

                try {
                    const response = await fetch('', {
                        method: 'POST',
                        body: formData,
                    });

                    const result = await response.json();

                    if (!response.ok || !result.ok) {
                        window.location.reload();
                    }

                    movedTodo.dataset.sourceDate = todayKey;
                    movedTodo.querySelector('.source-date')?.remove();
                } catch (error) {
                    window.location.reload();
                }
            });
        });
    </script>
</body>
</html>

