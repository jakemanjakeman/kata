<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';

$storageFile = kataStoragePath('goals.json');
$authError = '';
$error = '';
$isAuthenticated = (bool)($_SESSION['kata_authenticated'] ?? false);
$now = new DateTimeImmutable();
$todayKey = $now->format('Y-m-d');
$isNightMode = (int)$now->format('G') > 20 || ((int)$now->format('G') === 20 && (int)$now->format('i') >= 30);

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
    $normalized['reflection']['happiness'] = is_numeric($normalized['reflection']['happiness'])
        ? max(0, min(5, (float)$normalized['reflection']['happiness']))
        : 0;

    foreach (array_keys(socialMetricOptions()) as $metricKey) {
        $followers = $normalized['reflection'][$metricKey] ?? null;
        $normalized['reflection'][$metricKey] = is_numeric($followers) ? max(0, (int)$followers) : null;
    }

    $normalized['reflection']['note'] = (string)$normalized['reflection']['note'];
    $normalized['reflection']['created_at'] = (string)$normalized['reflection']['created_at'];
    $normalized['reflection']['updated_at'] = (string)$normalized['reflection']['updated_at'];
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

function socialMetricOptions(): array
{
    return [
        'tiktok_followers' => [
            'label' => 'TikTok followers',
            'short' => 'TikTok',
            'color' => '#1f7a6d',
        ],
        'instagram_followers' => [
            'label' => 'Instagram followers',
            'short' => 'Instagram',
            'color' => '#c13584',
        ],
        'x_followers' => [
            'label' => 'X followers',
            'short' => 'X',
            'color' => '#6f7b8c',
        ],
        'linkedin_followers' => [
            'label' => 'LinkedIn followers',
            'short' => 'LinkedIn',
            'color' => '#0a66c2',
        ],
    ];
}

function loadData(string $storageFile): array
{
    if (!is_file($storageFile)) {
        return ['days' => []];
    }

    $contents = file_get_contents($storageFile);
    if ($contents === false || trim($contents) === '') {
        return ['days' => []];
    }

    $decoded = json_decode($contents, true);
    if (!is_array($decoded)) {
        return ['days' => []];
    }

    if (isset($decoded['days']) && is_array($decoded['days'])) {
        foreach ($decoded['days'] as $date => $day) {
            $decoded['days'][$date] = normalizeDay((array)$day);
        }

        return $decoded;
    }

    return ['days' => []];
}

function saveData(string $storageFile, array $data): bool
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return $json !== false && file_put_contents($storageFile, $json, LOCK_EX) !== false;
}

function redirectSelf(): never
{
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

function parseCount(string $count): ?int
{
    $digits = preg_replace('/\D+/', '', $count);
    return $digits === '' ? null : (int)$digits;
}

function formatDateHeader(string $date): string
{
    $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $date);
    return $parsed ? $parsed->format('l, F j, Y') : $date;
}

function formatSignedCount(int $count): string
{
    if ($count === 0) {
        return '0';
    }

    return ($count > 0 ? '+' : '-') . number_format(abs($count));
}

function collectSocialSeries(array $days, string $metricKey, string $range, DateTimeImmutable $today): array
{
    $series = [];
    $startDate = null;

    if ($range !== 'all' && ctype_digit($range)) {
        $startDate = $today->modify('-' . (int)$range . ' days')->format('Y-m-d');
    }

    foreach ($days as $date => $day) {
        if (!is_string($date) || ($startDate !== null && $date < $startDate)) {
            continue;
        }

        $day = normalizeDay((array)$day);
        $followers = $day['reflection'][$metricKey] ?? null;
        if ($followers === null) {
            continue;
        }

        $series[] = [
            'date' => $date,
            'value' => (int)$followers,
        ];
    }

    usort($series, function (array $a, array $b): int {
        return strcmp((string)$a['date'], (string)$b['date']);
    });

    return $series;
}

function buildChartPolyline(array $series, float $minValue, float $maxValue, int $width, int $height, int $padding): string
{
    $count = count($series);
    if ($count === 0) {
        return '';
    }

    $valueRange = $maxValue - $minValue;
    if ($valueRange <= 0) {
        $valueRange = 1.0;
    }

    $points = [];
    foreach ($series as $index => $point) {
        $x = $count === 1
            ? $width / 2
            : $padding + ($index / ($count - 1)) * ($width - ($padding * 2));
        $y = $padding + (($maxValue - (float)$point['value']) / $valueRange) * ($height - ($padding * 2));
        $points[] = number_format($x, 2, '.', '') . ',' . number_format($y, 2, '.', '');
    }

    return implode(' ', $points);
}

function collectHistoryRows(array $seriesByMetric, array $metricOptions): array
{
    $rows = [];

    foreach ($seriesByMetric as $metricKey => $series) {
        foreach ($series as $point) {
            $date = (string)$point['date'];
            if (!isset($rows[$date])) {
                $rows[$date] = [
                    'date' => $date,
                    'values' => [],
                ];
            }

            $rows[$date]['values'][$metricKey] = [
                'label' => (string)$metricOptions[$metricKey]['short'],
                'color' => (string)$metricOptions[$metricKey]['color'],
                'value' => (int)$point['value'],
            ];
        }
    }

    krsort($rows);

    return array_values($rows);
}

$data = loadData($storageFile);

[$isAuthenticated, $authError] = kataHandleUnlock($todayKey);
if ($isAuthenticated) {
    ensureDailyJsonBackups($todayKey);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAuthenticated) {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_social_entry') {
        $entryDate = (string)($_POST['entry_date'] ?? $todayKey);
        $parsedDate = DateTimeImmutable::createFromFormat('Y-m-d', $entryDate);
        $followerCounts = [];
        $hasFollowerCount = false;

        foreach (socialMetricOptions() as $metricKey => $metric) {
            $followerCounts[$metricKey] = parseCount((string)($_POST[$metricKey] ?? ''));
            if ($followerCounts[$metricKey] !== null) {
                $hasFollowerCount = true;
            }
        }

        if (!$parsedDate) {
            $error = 'Choose a valid date for the social check-in.';
        } elseif (!$hasFollowerCount) {
            $error = 'Enter at least one follower count before saving.';
        } else {
            $dateKey = $parsedDate->format('Y-m-d');
            $data['days'][$dateKey] = normalizeDay((array)($data['days'][$dateKey] ?? []));
            $alreadyCreated = (string)($data['days'][$dateKey]['reflection']['created_at'] ?? '');
            foreach ($followerCounts as $metricKey => $followers) {
                $data['days'][$dateKey]['reflection'][$metricKey] = $followers;
            }
            $data['days'][$dateKey]['reflection']['created_at'] = $alreadyCreated !== '' ? $alreadyCreated : (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
            $data['days'][$dateKey]['reflection']['updated_at'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);

            if (saveData($storageFile, $data)) {
                redirectSelf();
            }

            $error = 'Could not save the social check-in. Check that this folder is writable.';
        }
    }
}

$todayDay = normalizeDay((array)($data['days'][$todayKey] ?? []));
$metricOptions = socialMetricOptions();
$todayFollowers = [];
foreach ($metricOptions as $metricKey => $metric) {
    $todayFollowers[$metricKey] = $todayDay['reflection'][$metricKey] ?? null;
}
$rangeOptions = [
    'all' => 'All time',
    '30' => 'Last 30 days',
    '90' => 'Last 90 days',
    '180' => 'Last 6 months',
    '365' => 'Last year',
];
$chartRange = (string)($_GET['chart_range'] ?? 'all');
if (!array_key_exists($chartRange, $rangeOptions)) {
    $chartRange = 'all';
}

$metricKeys = array_keys($metricOptions);
$chartMetricsTouched = array_key_exists('chart_metrics_touched', $_GET)
    || array_key_exists('chart_metrics', $_GET)
    || array_key_exists('chart_metric', $_GET);
$requestedMetrics = [];
if (isset($_GET['chart_metrics']) && is_array($_GET['chart_metrics'])) {
    $requestedMetrics = array_values(array_filter(array_map('strval', $_GET['chart_metrics']), function (string $metricKey) use ($metricOptions): bool {
        return array_key_exists($metricKey, $metricOptions);
    }));
} elseif (isset($_GET['chart_metric']) && is_string($_GET['chart_metric']) && array_key_exists($_GET['chart_metric'], $metricOptions)) {
    $requestedMetrics = [(string)$_GET['chart_metric']];
}

$selectedChartMetrics = $chartMetricsTouched ? array_values(array_unique($requestedMetrics)) : $metricKeys;
$chartSeriesByMetric = [];
$chartPolylines = [];
$chartValues = [];
$chartDates = [];

foreach ($selectedChartMetrics as $metricKey) {
    $series = collectSocialSeries($data['days'], $metricKey, $chartRange, $now);
    $chartSeriesByMetric[$metricKey] = $series;

    foreach ($series as $point) {
        $chartValues[] = (float)$point['value'];
        $chartDates[] = (string)$point['date'];
    }
}

$chartMin = 0.0;
$chartMax = $chartValues === [] ? 0.0 : max($chartValues);
if ($chartValues !== [] && $chartMax < 1.0) {
    $chartMax += 1.0;
}
$chartWidth = 720;
$chartHeight = 280;
$chartPadding = 44;
$chartHasSeries = $chartValues !== [];
foreach ($chartSeriesByMetric as $metricKey => $series) {
    if ($series === []) {
        continue;
    }

    $chartPolylines[$metricKey] = buildChartPolyline($series, $chartMin, $chartMax, $chartWidth, $chartHeight, $chartPadding);
}
$chartFirstDate = $chartDates === [] ? '' : min($chartDates);
$chartLastDate = $chartDates === [] ? '' : max($chartDates);
$historyRows = collectHistoryRows($chartSeriesByMetric, $metricOptions);
$platformSummaries = [];
foreach ($metricOptions as $metricKey => $metric) {
    $metricSeries = collectSocialSeries($data['days'], (string)$metricKey, 'all', $now);
    $metricLatest = $metricSeries === [] ? null : $metricSeries[count($metricSeries) - 1];
    $metricPrevious = count($metricSeries) > 1 ? $metricSeries[count($metricSeries) - 2] : null;
    $platformSummaries[$metricKey] = [
        'label' => (string)$metric['short'],
        'latest' => $metricLatest,
        'change' => $metricLatest !== null && $metricPrevious !== null ? (int)$metricLatest['value'] - (int)$metricPrevious['value'] : null,
        'points' => count($metricSeries),
    ];
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Social Kata</title>
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
            --danger: #9d3328;
            --hover: #eef4ef;
            --danger-soft: #f8e8e5;
            --empty-bg: rgba(255, 255, 255, 0.55);
            --overlay: rgba(245, 247, 244, 0.74);
            --shadow: rgba(24, 38, 30, 0.08);
            --lock-shadow: rgba(24, 38, 30, 0.18);
            --focus-ring: rgba(31, 122, 109, 0.16);
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
            --danger: #f09a90;
            --hover: #22332a;
            --danger-soft: #3a2422;
            --empty-bg: rgba(24, 34, 28, 0.72);
            --overlay: rgba(17, 23, 19, 0.76);
            --shadow: rgba(0, 0, 0, 0.28);
            --lock-shadow: rgba(0, 0, 0, 0.38);
            --focus-ring: rgba(79, 183, 164, 0.2);
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

        a {
            color: var(--accent-dark);
            font-weight: 800;
            text-decoration: none;
        }

        a:hover,
        a:focus-visible {
            text-decoration: underline;
        }

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

        .masthead {
            margin-bottom: 28px;
        }

        h1 {
            margin: 0;
            font-size: clamp(2rem, 5vw, 4rem);
            line-height: 0.98;
            font-weight: 800;
            letter-spacing: 0;
        }

        .subtitle {
            max-width: 680px;
            margin: 14px 0 0;
            color: var(--muted);
            font-size: 1.05rem;
            line-height: 1.55;
        }

        .panel,
        .summary-panel {
            margin-bottom: 22px;
            padding: 18px;
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 8px;
            box-shadow: 0 12px 28px var(--shadow);
        }

        .stage-title {
            margin: 0 0 14px;
            font-size: 1rem;
            color: var(--muted);
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 12px;
        }

        .metric {
            padding: 14px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: var(--empty-bg);
        }

        .metric span {
            display: block;
            color: var(--muted);
            font-size: 0.8rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        .metric strong {
            display: block;
            margin-top: 8px;
            font-size: clamp(1.35rem, 4vw, 2.2rem);
            letter-spacing: 0;
            overflow-wrap: anywhere;
        }

        .is-positive strong,
        .change.is-positive {
            color: var(--accent-dark);
        }

        .is-negative strong,
        .change.is-negative {
            color: var(--danger);
        }

        .entry,
        .chart-controls {
            display: grid;
            gap: 12px;
            align-items: end;
        }

        .entry {
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
        }

        .chart-controls {
            grid-template-columns: minmax(0, 180px) auto;
            margin-bottom: 16px;
        }

        .entry button {
            align-self: end;
        }

        .metric-toggle-group {
            grid-column: 1 / -1;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
            min-width: 0;
            margin: 0;
            padding: 0;
            border: 0;
        }

        .metric-toggle-group legend {
            width: 100%;
            color: var(--muted);
            font-size: 0.88rem;
            font-weight: 800;
        }

        .metric-pill {
            position: relative;
            display: inline-flex;
            align-items: center;
            min-height: 42px;
            border: 1px solid var(--line);
            border-radius: 999px;
            padding: 0 14px 0 34px;
            background: var(--panel);
            color: var(--ink);
            font-size: 0.92rem;
            font-weight: 800;
            cursor: pointer;
            user-select: none;
        }

        .metric-pill::before {
            content: "";
            position: absolute;
            left: 14px;
            width: 10px;
            height: 10px;
            border-radius: 999px;
            background: var(--metric-color);
            opacity: 0.45;
        }

        .metric-pill input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        .metric-pill:has(input:checked) {
            border-color: var(--metric-color);
            background: var(--hover);
            box-shadow: inset 0 0 0 1px var(--metric-color);
        }

        .metric-pill:has(input:checked)::before {
            opacity: 1;
        }

        .metric-pill:focus-within {
            box-shadow: 0 0 0 3px var(--focus-ring);
        }

        .input-group {
            display: grid;
            gap: 8px;
        }

        .input-group label {
            position: static;
            width: auto;
            height: auto;
            overflow: visible;
            clip: auto;
            color: var(--muted);
            font-size: 0.88rem;
            font-weight: 800;
        }

        label {
            position: absolute;
            width: 1px;
            height: 1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
        }

        input[type="text"],
        input[type="password"],
        input[type="date"],
        select {
            width: 100%;
            min-height: 48px;
            border: 1px solid var(--line);
            border-radius: 6px;
            padding: 0 14px;
            background: var(--panel);
            color: var(--ink);
            font: inherit;
            outline: none;
        }

        input[type="text"]:focus,
        input[type="password"]:focus,
        input[type="date"]:focus,
        select:focus {
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

        .chart-wrap {
            display: grid;
            gap: 12px;
        }

        .chart-frame {
            min-height: 280px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: var(--empty-bg);
            overflow: hidden;
        }

        .line-chart {
            display: block;
            width: 100%;
            height: auto;
        }

        .chart-grid-line {
            stroke: var(--line);
            stroke-width: 1;
        }

        .chart-axis-label,
        .chart-date-label {
            fill: var(--muted);
            font-size: 0.8rem;
            font-weight: 800;
        }

        .chart-line {
            fill: none;
            stroke-width: 4;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .chart-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .chart-legend-item {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            color: var(--muted);
            font-size: 0.88rem;
            font-weight: 800;
        }

        .chart-legend-swatch {
            width: 18px;
            height: 4px;
            border-radius: 999px;
            background: var(--metric-color);
        }

        .chart-caption,
        .history-row span {
            color: var(--muted);
            font-size: 0.88rem;
        }

        .chart-caption {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            font-weight: 800;
        }

        .history-list {
            display: grid;
            gap: 8px;
            margin: 0;
            padding: 0;
            list-style: none;
        }

        .history-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 10px;
            align-items: center;
            padding: 10px 12px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: var(--panel);
        }

        .history-row strong {
            display: block;
        }

        .empty {
            margin: 0;
            padding: 18px;
            border: 1px dashed var(--line);
            border-radius: 8px;
            color: var(--muted);
            background: var(--empty-bg);
        }

        .notice {
            margin: 12px 0 0;
            color: var(--danger);
            font-weight: 700;
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

            .summary-grid,
            .entry,
            .chart-controls {
                grid-template-columns: 1fr;
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

            input[type="text"],
            input[type="password"],
            input[type="date"],
            select {
                padding-right: 5px;
                padding-left: 5px;
            }

            .panel,
            .summary-panel,
            .metric,
            .history-row,
            .empty,
            .lock-screen,
            .lock-card {
                padding: 6px;
            }
        }
    </style>
</head>
<body class="<?= $isNightMode ? 'night-mode' : '' ?>">
    <main class="app-shell<?= $isAuthenticated ? '' : ' is-blurred' ?>" data-app-shell>
        <nav class="primary-nav" aria-label="Primary">
            <button class="primary-menu-toggle" type="button" aria-expanded="false" aria-controls="primary-menu" data-primary-menu-toggle>
                <span aria-hidden="true">&#9776;</span>
                <span data-primary-menu-label>Menu</span>
            </button>
            <div class="primary-menu-items" id="primary-menu" data-primary-menu>
                <a href="index.php">Daily Kata</a>
                <a href="finance.php">Money Kata</a>
                <a href="three-month-goals.php">3 Month Goals</a>
                <button class="theme-toggle" type="button" data-theme-toggle aria-label="Toggle theme">Night</button>
            </div>
        </nav>

        <header class="masthead">
            <h1>Social Kata</h1>
            <p class="subtitle">Track social metrics with the same daily rhythm as the rest of the kata.</p>
        </header>

        <section class="summary-panel" aria-labelledby="summary-title">
            <h2 class="stage-title" id="summary-title">Social Followers</h2>
            <div class="summary-grid">
                <?php foreach ($platformSummaries as $summary): ?>
                    <?php $summaryChange = $summary['change']; ?>
                    <div class="metric <?= $summaryChange !== null && $summaryChange > 0 ? 'is-positive' : ($summaryChange !== null && $summaryChange < 0 ? 'is-negative' : '') ?>">
                        <span><?= htmlspecialchars((string)$summary['label'], ENT_QUOTES, 'UTF-8') ?></span>
                        <strong><?= $summary['latest'] !== null ? number_format((int)$summary['latest']['value']) : 'No data' ?></strong>
                        <?php if ($summaryChange !== null): ?>
                            <span>Last: <?= htmlspecialchars(formatSignedCount((int)$summaryChange), ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="panel" aria-labelledby="entry-title">
            <h2 class="stage-title" id="entry-title">Daily Social Check-In</h2>
            <form class="entry" method="post" action="">
                <input type="hidden" name="action" value="save_social_entry">
                <div class="input-group">
                    <label for="entry-date">Check-in date</label>
                    <input id="entry-date" name="entry_date" type="date" value="<?= htmlspecialchars($todayKey, ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
                <?php foreach ($metricOptions as $metricKey => $metric): ?>
                    <?php $inputId = str_replace('_', '-', (string)$metricKey); ?>
                    <?php $currentFollowers = $todayFollowers[$metricKey] ?? null; ?>
                    <div class="input-group">
                        <label for="<?= htmlspecialchars($inputId, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)$metric['label'], ENT_QUOTES, 'UTF-8') ?></label>
                        <input id="<?= htmlspecialchars($inputId, ENT_QUOTES, 'UTF-8') ?>" name="<?= htmlspecialchars((string)$metricKey, ENT_QUOTES, 'UTF-8') ?>" type="text" inputmode="numeric" pattern="[0-9,]*" maxlength="12" placeholder="<?= htmlspecialchars((string)$metric['label'], ENT_QUOTES, 'UTF-8') ?>" value="<?= $currentFollowers !== null ? htmlspecialchars(number_format((int)$currentFollowers), ENT_QUOTES, 'UTF-8') : '' ?>">
                    </div>
                <?php endforeach; ?>
                <button type="submit">Save Check-In</button>
            </form>
            <?php if ($error !== ''): ?>
                <p class="notice"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>
        </section>

        <section class="panel" aria-labelledby="chart-title">
            <h2 class="stage-title" id="chart-title">Follower Chart</h2>
            <form class="chart-controls" method="get" action="" data-chart-controls>
                <input type="hidden" name="chart_metrics_touched" value="1">
                <fieldset class="metric-toggle-group" aria-label="Chart metrics">
                    <legend>Metrics</legend>
                    <?php foreach ($metricOptions as $metricKey => $metric): ?>
                        <?php $metricId = 'chart-' . str_replace('_', '-', (string)$metricKey); ?>
                        <label class="metric-pill" for="<?= htmlspecialchars($metricId, ENT_QUOTES, 'UTF-8') ?>" style="--metric-color: <?= htmlspecialchars((string)$metric['color'], ENT_QUOTES, 'UTF-8') ?>">
                            <input id="<?= htmlspecialchars($metricId, ENT_QUOTES, 'UTF-8') ?>" name="chart_metrics[]" type="checkbox" value="<?= htmlspecialchars((string)$metricKey, ENT_QUOTES, 'UTF-8') ?>" <?= in_array((string)$metricKey, $selectedChartMetrics, true) ? 'checked' : '' ?> data-chart-metric-toggle>
                            <span><?= htmlspecialchars((string)$metric['short'], ENT_QUOTES, 'UTF-8') ?></span>
                        </label>
                    <?php endforeach; ?>
                </fieldset>
                <div class="input-group">
                    <label for="chart-range">Interval</label>
                    <select id="chart-range" name="chart_range">
                        <?php foreach ($rangeOptions as $rangeValue => $rangeLabel): ?>
                            <?php $rangeValue = (string)$rangeValue; ?>
                            <option value="<?= htmlspecialchars($rangeValue, ENT_QUOTES, 'UTF-8') ?>" <?= $chartRange === $rangeValue ? 'selected' : '' ?>>
                                <?= htmlspecialchars($rangeLabel, ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit">Apply</button>
            </form>

            <?php if ($selectedChartMetrics === []): ?>
                <p class="empty">Choose at least one metric to draw on the chart.</p>
            <?php elseif (!$chartHasSeries): ?>
                <p class="empty">No selected follower check-ins saved for this interval yet.</p>
            <?php else: ?>
                <div class="chart-wrap">
                    <div class="chart-frame">
                        <svg class="line-chart" viewBox="0 0 <?= $chartWidth ?> <?= $chartHeight ?>" role="img" aria-labelledby="social-chart-title social-chart-desc">
                            <title id="social-chart-title">Social follower chart</title>
                            <desc id="social-chart-desc"><?= htmlspecialchars($rangeOptions[$chartRange], ENT_QUOTES, 'UTF-8') ?> follower history from <?= htmlspecialchars(formatDateHeader($chartFirstDate), ENT_QUOTES, 'UTF-8') ?> to <?= htmlspecialchars(formatDateHeader($chartLastDate), ENT_QUOTES, 'UTF-8') ?>.</desc>
                            <line class="chart-grid-line" x1="<?= $chartPadding ?>" y1="<?= $chartPadding ?>" x2="<?= $chartWidth - $chartPadding ?>" y2="<?= $chartPadding ?>"></line>
                            <line class="chart-grid-line" x1="<?= $chartPadding ?>" y1="<?= $chartHeight / 2 ?>" x2="<?= $chartWidth - $chartPadding ?>" y2="<?= $chartHeight / 2 ?>"></line>
                            <line class="chart-grid-line" x1="<?= $chartPadding ?>" y1="<?= $chartHeight - $chartPadding ?>" x2="<?= $chartWidth - $chartPadding ?>" y2="<?= $chartHeight - $chartPadding ?>"></line>
                            <text class="chart-axis-label" x="8" y="<?= $chartPadding + 4 ?>"><?= number_format((int)$chartMax) ?></text>
                            <text class="chart-axis-label" x="8" y="<?= ($chartHeight / 2) + 4 ?>"><?= number_format((int)(($chartMax + $chartMin) / 2)) ?></text>
                            <text class="chart-axis-label" x="8" y="<?= $chartHeight - $chartPadding + 4 ?>"><?= number_format((int)$chartMin) ?></text>
                            <?php foreach ($chartPolylines as $metricKey => $polyline): ?>
                                <polyline class="chart-line" points="<?= htmlspecialchars($polyline, ENT_QUOTES, 'UTF-8') ?>" style="stroke: <?= htmlspecialchars((string)$metricOptions[$metricKey]['color'], ENT_QUOTES, 'UTF-8') ?>"></polyline>
                            <?php endforeach; ?>
                            <text class="chart-date-label" x="<?= $chartPadding ?>" y="<?= $chartHeight - 8 ?>"><?= htmlspecialchars((new DateTimeImmutable($chartFirstDate))->format('M j'), ENT_QUOTES, 'UTF-8') ?></text>
                            <text class="chart-date-label" x="<?= $chartWidth - $chartPadding ?>" y="<?= $chartHeight - 8 ?>" text-anchor="end"><?= htmlspecialchars((new DateTimeImmutable($chartLastDate))->format('M j'), ENT_QUOTES, 'UTF-8') ?></text>
                        </svg>
                    </div>
                    <div class="chart-legend" aria-label="Visible chart metrics">
                        <?php foreach ($chartPolylines as $metricKey => $polyline): ?>
                            <span class="chart-legend-item">
                                <span class="chart-legend-swatch" style="--metric-color: <?= htmlspecialchars((string)$metricOptions[$metricKey]['color'], ENT_QUOTES, 'UTF-8') ?>"></span>
                                <?= htmlspecialchars((string)$metricOptions[$metricKey]['short'], ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                    <div class="chart-caption" aria-label="Chart summary">
                        <?php foreach ($selectedChartMetrics as $metricKey): ?>
                            <?php $series = $chartSeriesByMetric[$metricKey] ?? []; ?>
                            <?php if ($series === []) { continue; } ?>
                            <?php $metricFirstPoint = $series[0]; ?>
                            <?php $metricLastPoint = $series[count($series) - 1]; ?>
                            <span><?= htmlspecialchars((string)$metricOptions[$metricKey]['short'], ENT_QUOTES, 'UTF-8') ?>: <?= count($series) ?> point<?= count($series) === 1 ? '' : 's' ?>, <?= number_format((int)$metricFirstPoint['value']) ?> to <?= number_format((int)$metricLastPoint['value']) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </section>

        <section class="panel" aria-labelledby="history-title">
            <h2 class="stage-title" id="history-title">Follower History</h2>
            <?php if ($selectedChartMetrics === []): ?>
                <p class="empty">Choose a metric above to see follower history.</p>
            <?php elseif ($historyRows === []): ?>
                <p class="empty">No selected follower check-ins saved yet.</p>
            <?php else: ?>
                <ul class="history-list">
                    <?php foreach (array_slice($historyRows, 0, 30) as $row): ?>
                        <li class="history-row">
                            <div>
                                <strong><?= htmlspecialchars(formatDateHeader((string)$row['date']), ENT_QUOTES, 'UTF-8') ?></strong>
                                <span>
                                    <?php $valueParts = []; ?>
                                    <?php foreach ($row['values'] as $value): ?>
                                        <?php $valueParts[] = (string)$value['label'] . ' ' . number_format((int)$value['value']); ?>
                                    <?php endforeach; ?>
                                    <?= htmlspecialchars(implode(' / ', $valueParts), ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </div>
                            <strong><?= count($row['values']) ?> metric<?= count($row['values']) === 1 ? '' : 's' ?></strong>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </main>

    <section class="lock-screen<?= $isAuthenticated ? ' is-hidden' : '' ?>" aria-labelledby="lock-title" data-lock-screen>
        <div class="lock-card">
            <h2 id="lock-title">Unlock Social Kata</h2>
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

        const chartControls = document.querySelector('[data-chart-controls]');
        document.querySelectorAll('[data-chart-metric-toggle]').forEach((toggle) => {
            toggle.addEventListener('change', () => {
                if (chartControls instanceof HTMLFormElement) {
                    chartControls.requestSubmit();
                }
            });
        });

        const appShell = document.querySelector('[data-app-shell]');
        const lockScreen = document.querySelector('[data-lock-screen]');
        const lockPassword = document.querySelector('[data-lock-password]');
        const lockAfterMs = 5 * 60 * 1000;
        const appIsAuthenticated = <?= $isAuthenticated ? 'true' : 'false' ?>;
        let lockTimer = null;

        function lockApp() {
            if (!appShell || !lockScreen) {
                return;
            }

            appShell.classList.add('is-blurred');
            lockScreen.classList.remove('is-hidden');
            if (lockPassword) {
                lockPassword.focus();
            }
        }

        function resetLockTimer() {
            if (!appIsAuthenticated) {
                return;
            }

            window.clearTimeout(lockTimer);
            lockTimer = window.setTimeout(lockApp, lockAfterMs);
        }

        ['click', 'keydown', 'mousemove', 'touchstart'].forEach((eventName) => {
            window.addEventListener(eventName, resetLockTimer, { passive: true });
        });

        resetLockTimer();
    </script>
</body>
</html>
