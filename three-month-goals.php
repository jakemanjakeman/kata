<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';

$storageFile = kataStoragePath('three-month-goals.json');
$error = '';
$authError = '';
$isAuthenticated = (bool)($_SESSION['kata_authenticated'] ?? false);
$now = new DateTimeImmutable();
$todayKey = $now->format('Y-m-d');
$isNightMode = (int)$now->format('G') > 20 || ((int)$now->format('G') === 20 && (int)$now->format('i') >= 30);
$defaultTargetDate = '2026-08-01';

function loadThreeMonthData(string $storageFile, string $defaultTargetDate): array
{
    if (!is_file($storageFile)) {
        return ['target_date' => $defaultTargetDate, 'goals' => []];
    }

    $contents = file_get_contents($storageFile);
    if ($contents === false || trim($contents) === '') {
        return ['target_date' => $defaultTargetDate, 'goals' => []];
    }

    $decoded = json_decode($contents, true);
    if (!is_array($decoded)) {
        return ['target_date' => $defaultTargetDate, 'goals' => []];
    }

    $decoded['target_date'] = is_string($decoded['target_date'] ?? null) && $decoded['target_date'] !== ''
        ? $decoded['target_date']
        : $defaultTargetDate;
    $decoded['goals'] = array_values((array)($decoded['goals'] ?? []));
    foreach ($decoded['goals'] as $index => $goal) {
        $goal = (array)$goal;
        $decoded['goals'][$index] = [
            'id' => (string)($goal['id'] ?? bin2hex(random_bytes(8))),
            'text' => (string)($goal['text'] ?? ''),
            'group' => in_array(($goal['group'] ?? 'main'), ['main', 'kept'], true) ? (string)($goal['group'] ?? 'main') : 'main',
            'complete' => (bool)($goal['complete'] ?? false),
            'completed_at' => (string)($goal['completed_at'] ?? ''),
            'created_at' => (string)($goal['created_at'] ?? ''),
        ];
    }

    return $decoded;
}

function saveThreeMonthData(string $storageFile, array $data): bool
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return $json !== false && file_put_contents($storageFile, $json, LOCK_EX) !== false;
}

function makeGoal(string $text): array
{
    return [
        'id' => bin2hex(random_bytes(8)),
        'text' => $text,
        'group' => 'main',
        'complete' => false,
        'completed_at' => '',
        'created_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
    ];
}

function formatTime(string $dateTime): string
{
    if ($dateTime === '') {
        return '';
    }

    try {
        return (new DateTimeImmutable($dateTime))->format('M j, g:i A');
    } catch (Exception $exception) {
        return '';
    }
}

function formatTargetDate(string $date): string
{
    $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $date);
    return $parsed ? $parsed->format('F j, Y') : $date;
}

function redirectSelf(): never
{
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

$data = loadThreeMonthData($storageFile, $defaultTargetDate);

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
            $error = 'Give the goal a few words before adding it.';
        } else {
            $data['goals'][] = makeGoal($goalText);

            if (saveThreeMonthData($storageFile, $data)) {
                redirectSelf();
            }

            $error = 'Could not save the goal. Check that this folder is writable.';
        }
    }

    if ($action === 'save_target_date') {
        $targetDate = (string)($_POST['target_date'] ?? '');
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $targetDate);

        if (!$parsed) {
            $error = 'Choose a valid target date.';
        } else {
            $data['target_date'] = $parsed->format('Y-m-d');

            if (saveThreeMonthData($storageFile, $data)) {
                redirectSelf();
            }

            $error = 'Could not save the target date. Check that this folder is writable.';
        }
    }

    if ($action === 'update_goal') {
        $goalId = (string)($_POST['goal_id'] ?? '');
        $goalText = trim((string)($_POST['goal_text'] ?? ''));

        if ($goalId === '' || $goalText === '') {
            $error = 'Could not update the goal. Make sure it has a few words.';
        } else {
            foreach ($data['goals'] as $index => $goal) {
                if (($goal['id'] ?? '') === $goalId) {
                    $data['goals'][$index]['text'] = $goalText;
                    break;
                }
            }

            if (saveThreeMonthData($storageFile, $data)) {
                redirectSelf();
            }

            $error = 'Could not save the goal edit. Check that this folder is writable.';
        }
    }

    if ($action === 'delete_goal') {
        $goalId = (string)($_POST['goal_id'] ?? '');

        foreach ($data['goals'] as $index => $goal) {
            if (($goal['id'] ?? '') === $goalId) {
                array_splice($data['goals'], $index, 1);
                break;
            }
        }

        if (saveThreeMonthData($storageFile, $data)) {
            redirectSelf();
        }

        $error = 'Could not delete the goal. Check that this folder is writable.';
    }

    if ($action === 'move_goal') {
        header('Content-Type: application/json');

        $goalId = (string)($_POST['goal_id'] ?? '');
        $targetGroup = (string)($_POST['target_group'] ?? '');

        if ($goalId === '' || !in_array($targetGroup, ['main', 'kept'], true)) {
            http_response_code(422);
            echo json_encode(['ok' => false]);
            exit;
        }

        foreach ($data['goals'] as $index => $goal) {
            if (($goal['id'] ?? '') === $goalId) {
                $data['goals'][$index]['group'] = $targetGroup;
                echo json_encode(['ok' => saveThreeMonthData($storageFile, $data)]);
                exit;
            }
        }

        http_response_code(404);
        echo json_encode(['ok' => false]);
        exit;
    }

    if ($action === 'toggle_goal') {
        header('Content-Type: application/json');

        $goalId = (string)($_POST['goal_id'] ?? '');
        $complete = (string)($_POST['complete'] ?? '') === '1';

        foreach ($data['goals'] as $index => $goal) {
            if (($goal['id'] ?? '') === $goalId) {
                $completedAt = $complete ? (new DateTimeImmutable())->format(DateTimeInterface::ATOM) : '';
                $data['goals'][$index]['complete'] = $complete;
                $data['goals'][$index]['completed_at'] = $completedAt;

                echo json_encode([
                    'ok' => saveThreeMonthData($storageFile, $data),
                    'completed_time' => formatTime($completedAt),
                ]);
                exit;
            }
        }

        http_response_code(404);
        echo json_encode(['ok' => false]);
        exit;
    }
}

$targetDate = (string)$data['target_date'];
$mainGoals = [];
$keptGoals = [];
foreach ($data['goals'] as $goal) {
    if (($goal['group'] ?? 'main') === 'kept') {
        $keptGoals[] = $goal;
    } else {
        $mainGoals[] = $goal;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>3 Month Goals</title>
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
            --drop-ring: rgba(31, 122, 109, 0.14);
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
            --drop-ring: rgba(79, 183, 164, 0.18);
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
            width: min(920px, calc(100% - 32px));
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

        .panel {
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
        input[type="password"],
        input[type="date"] {
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
        input[type="password"]:focus,
        input[type="date"]:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px var(--focus-ring);
        }

        .target-date {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 18px;
        }

        .target-date strong {
            font-size: 1.05rem;
        }

        .target-date form {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .target-date input[type="date"] {
            width: auto;
            min-width: 168px;
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

        .notice {
            margin: 12px 0 0;
            color: var(--danger);
            font-weight: 700;
        }

        .goal-board {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(280px, 0.6fr);
            gap: 16px;
            margin-top: 26px;
        }

        .goal-zone {
            min-height: 180px;
            padding: 14px;
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 8px;
        }

        .goal-zone.is-over {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px var(--drop-ring);
        }

        .goal-zone h2 {
            margin: 0 0 12px;
            font-size: 1.05rem;
            color: var(--muted);
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        .goals {
            display: grid;
            gap: 10px;
            min-height: 104px;
            margin: 0;
            padding: 0;
            list-style: none;
        }

        .goal {
            display: grid;
            grid-template-columns: 22px 1fr auto;
            gap: 10px;
            align-items: start;
            padding: 12px 14px;
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 8px;
            overflow-wrap: anywhere;
            cursor: grab;
        }

        .goal:active {
            cursor: grabbing;
        }

        .goal.is-dragging {
            opacity: 0.45;
        }

        .goal input[type="checkbox"] {
            width: 18px;
            height: 18px;
            margin: 1px 0 0;
            accent-color: var(--accent);
            cursor: pointer;
        }

        .goal.is-complete .goal-text {
            color: var(--muted);
            text-decoration: line-through;
        }

        .completed-time {
            margin-left: 8px;
            color: var(--muted);
            font-size: 0.85rem;
            font-style: italic;
            white-space: nowrap;
        }

        .goal-actions {
            display: flex;
            gap: 6px;
        }

        .goal-edit-form {
            display: grid;
            grid-template-columns: 1fr auto auto;
            gap: 8px;
            margin-top: 10px;
            grid-column: 1 / -1;
        }

        .is-hidden {
            display: none;
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

        .empty {
            margin: 0;
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

        @media (max-width: 620px) {
            main {
                width: min(100% - 8px, 920px);
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

            .entry {
                grid-template-columns: 1fr;
            }

            .goal,
            .goal-edit-form {
                grid-template-columns: 1fr;
            }

            .goal-board {
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
            input[type="date"] {
                padding-right: 5px;
                padding-left: 5px;
            }

            .panel,
            .goal-zone,
            .goal,
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
        <?= kataRenderGlobalFocus(kataLoadMainFocus()) ?>

        <nav class="primary-nav" aria-label="Primary">
            <button class="primary-menu-toggle" type="button" aria-expanded="false" aria-controls="primary-menu" data-primary-menu-toggle>
                <span aria-hidden="true">&#9776;</span>
                <span data-primary-menu-label>Menu</span>
            </button>
            <div class="primary-menu-items" id="primary-menu" data-primary-menu>
                <a href="index.php">Daily Kata</a>
                <a href="finance.php">Money Kata</a>
                <a href="social.php">Social Kata</a>
                <button class="theme-toggle" type="button" data-theme-toggle aria-label="Toggle theme">Night</button>
            </div>
        </nav>

        <header class="masthead">
            <h1>3 Month Goals</h1>
            <p class="subtitle">Keep the longer horizon visible without crowding the daily practice.</p>
            <div class="target-date">
                <strong>Target Date: <?= htmlspecialchars(formatTargetDate($targetDate), ENT_QUOTES, 'UTF-8') ?></strong>
                <form method="post" action="">
                    <input type="hidden" name="action" value="save_target_date">
                    <label for="target-date">Target date</label>
                    <input id="target-date" name="target_date" type="date" value="<?= htmlspecialchars($targetDate, ENT_QUOTES, 'UTF-8') ?>" required>
                    <button type="submit">Save Date</button>
                </form>
            </div>
        </header>

        <section class="panel" aria-labelledby="goal-entry">
            <h2 class="stage-title" id="goal-entry">Add Goal</h2>
            <form class="entry" method="post" action="">
                <input type="hidden" name="action" value="add_goal">
                <label for="goal">3 month goal</label>
                <input id="goal" name="goal" type="text" maxlength="500" placeholder="What should be true three months from now?" autofocus required>
                <button type="submit">Add Goal</button>
            </form>
        </section>

        <?php if ($error !== ''): ?>
            <p class="notice"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>

        <section class="goal-board" aria-label="Goal groups">
            <?php foreach ([
                'main' => ['title' => 'Main Focus', 'goals' => $mainGoals, 'empty' => 'No main focus goals yet.'],
                'kept' => ['title' => 'Keep, But Not the Cut', 'goals' => $keptGoals, 'empty' => 'Drag goals here when you want to keep them for later.'],
            ] as $groupKey => $group): ?>
                <section class="goal-zone" data-goal-group="<?= htmlspecialchars($groupKey, ENT_QUOTES, 'UTF-8') ?>">
                    <h2><?= htmlspecialchars($group['title'], ENT_QUOTES, 'UTF-8') ?></h2>
                    <?php if ($group['goals'] === []): ?>
                        <p class="empty"><?= htmlspecialchars($group['empty'], ENT_QUOTES, 'UTF-8') ?></p>
                    <?php endif; ?>
                    <ul class="goals">
                        <?php foreach (array_reverse($group['goals']) as $goal): ?>
                            <?php
                                $goalId = (string)$goal['id'];
                                $isComplete = (bool)$goal['complete'];
                                $completedTime = formatTime((string)$goal['completed_at']);
                            ?>
                            <li class="goal<?= $isComplete ? ' is-complete' : '' ?>" draggable="true" data-goal-id="<?= htmlspecialchars($goalId, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="checkbox" aria-label="Mark goal complete" <?= $isComplete ? 'checked' : '' ?>>
                                <span data-goal-text-wrap>
                                    <span class="goal-text"><?= htmlspecialchars((string)$goal['text'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php if ($isComplete && $completedTime !== ''): ?>
                                        <span class="completed-time">completed <?= htmlspecialchars($completedTime, ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php endif; ?>
                                </span>
                                <div class="goal-actions">
                                    <button class="icon-button" type="button" aria-label="Edit goal" title="Edit goal" data-goal-edit>&#9998;</button>
                                    <form method="post" action="">
                                        <input type="hidden" name="action" value="delete_goal">
                                        <input type="hidden" name="goal_id" value="<?= htmlspecialchars($goalId, ENT_QUOTES, 'UTF-8') ?>">
                                        <button class="icon-button danger-button" type="submit" aria-label="Delete goal" title="Delete goal">&#10005;</button>
                                    </form>
                                </div>
                                <form class="goal-edit-form is-hidden" method="post" action="" data-goal-form>
                                    <input type="hidden" name="action" value="update_goal">
                                    <input type="hidden" name="goal_id" value="<?= htmlspecialchars($goalId, ENT_QUOTES, 'UTF-8') ?>">
                                    <label for="goal-edit-<?= htmlspecialchars($goalId, ENT_QUOTES, 'UTF-8') ?>">Edit goal</label>
                                    <input id="goal-edit-<?= htmlspecialchars($goalId, ENT_QUOTES, 'UTF-8') ?>" name="goal_text" type="text" maxlength="500" value="<?= htmlspecialchars((string)$goal['text'], ENT_QUOTES, 'UTF-8') ?>" required>
                                    <button type="submit">Save</button>
                                    <button class="icon-button" type="button" aria-label="Cancel edit" title="Cancel edit" data-goal-cancel>&#10005;</button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endforeach; ?>
        </section>
    </main>

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

        let draggedGoal = null;

        document.querySelectorAll('.goal').forEach((goal) => {
            const editButton = goal.querySelector('[data-goal-edit]');
            const editForm = goal.querySelector('[data-goal-form]');
            const cancelButton = goal.querySelector('[data-goal-cancel]');
            const input = editForm?.querySelector('input[name="goal_text"]');

            editButton?.addEventListener('click', () => {
                editForm?.classList.remove('is-hidden');
                input?.focus();
                input?.select();
            });

            cancelButton?.addEventListener('click', () => {
                editForm?.classList.add('is-hidden');
            });

            goal.addEventListener('dragstart', (event) => {
                if (event.target instanceof HTMLInputElement || event.target instanceof HTMLButtonElement) {
                    event.preventDefault();
                    return;
                }

                draggedGoal = goal;
                goal.classList.add('is-dragging');
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', goal.dataset.goalId);
            });

            goal.addEventListener('dragend', () => {
                goal.classList.remove('is-dragging');
                draggedGoal = null;
            });
        });

        document.querySelectorAll('.goal').forEach((goal) => {
            const checkbox = goal.querySelector('input[type="checkbox"]');

            if (!checkbox) {
                return;
            }

            checkbox.addEventListener('change', async () => {
                goal.classList.toggle('is-complete', checkbox.checked);
                const textWrap = goal.querySelector('.goal-text')?.parentElement;

                const formData = new FormData();
                formData.append('action', 'toggle_goal');
                formData.append('goal_id', goal.dataset.goalId);
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

                    goal.querySelector('.completed-time')?.remove();

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
        });

        document.querySelectorAll('.goal-zone').forEach((zone) => {
            zone.addEventListener('dragover', (event) => {
                event.preventDefault();
                zone.classList.add('is-over');
            });

            zone.addEventListener('dragleave', () => {
                zone.classList.remove('is-over');
            });

            zone.addEventListener('drop', async (event) => {
                event.preventDefault();
                zone.classList.remove('is-over');

                if (!draggedGoal) {
                    return;
                }

                const movedGoal = draggedGoal;
                zone.querySelector('.goals')?.appendChild(movedGoal);
                zone.querySelector('.empty')?.remove();

                const formData = new FormData();
                formData.append('action', 'move_goal');
                formData.append('goal_id', movedGoal.dataset.goalId);
                formData.append('target_group', zone.dataset.goalGroup);

                try {
                    const response = await fetch('', {
                        method: 'POST',
                        body: formData,
                    });
                    const result = await response.json();

                    if (!response.ok || !result.ok) {
                        window.location.reload();
                    }
                } catch (error) {
                    window.location.reload();
                }
            });
        });
    </script>
</body>
</html>
