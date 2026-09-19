<?php
declare(strict_types=1);

require_once __DIR__ . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'bootstrap.php';

$storageFile = kataStoragePath('finance.json');
$authError = '';
$error = '';
$isAuthenticated = (bool)($_SESSION['kata_authenticated'] ?? false);
$now = new DateTimeImmutable();
$todayKey = $now->format('Y-m-d');
$isNightMode = (int)$now->format('G') > 20 || ((int)$now->format('G') === 20 && (int)$now->format('i') >= 30);

function blankFinanceData(): array
{
    return [
        'accounts' => [],
        'entries' => [],
        'bills' => [],
        'incomes' => [],
    ];
}

function billCategoryOptions(): array
{
    return [
        'monthly_bill' => 'Monthly bill',
        'utilities' => 'Utilities',
        'shopping' => 'Shopping',
        'dining_out' => 'Dining out',
        'groceries' => 'Groceries',
        'streaming_services' => 'Streaming services',
        'gas' => 'Gas',
        'auto' => 'Auto',
        'childcare' => 'Childcare',
        'charity' => 'Charity',
        'filters' => 'Filters',
        'pets' => 'Pets',
        'property' => 'Property',
        'rent' => 'Rent',
        'uncategorized' => 'Uncategorized',
        'interest_payment' => 'Interest payment',
    ];
}

function billCategoryLabel(string $category): string
{
    $options = billCategoryOptions();

    return (string)($options[$category] ?? $options['uncategorized']);
}

function weekdayOptions(): array
{
    return [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        7 => 'Sunday',
    ];
}

function weekdayLabel(int $weekday): string
{
    $options = weekdayOptions();

    return (string)($options[$weekday] ?? 'Weekly');
}

function normalizeFinanceBill(array $bill): array
{
    $cadence = (string)($bill['cadence'] ?? 'monthly');
    $cadence = in_array($cadence, ['monthly', 'weekly'], true) ? $cadence : 'monthly';
    $day = (int)($bill['day'] ?? 0);
    $weekday = (int)($bill['weekday'] ?? 0);
    $category = (string)($bill['category'] ?? 'monthly_bill');
    $category = array_key_exists($category, billCategoryOptions()) ? $category : 'uncategorized';

    return [
        'id' => (string)($bill['id'] ?? bin2hex(random_bytes(8))),
        'name' => trim((string)($bill['name'] ?? '')),
        'cadence' => $cadence,
        'day' => $cadence === 'monthly' && $day >= 1 && $day <= 31 ? $day : 0,
        'weekday' => $cadence === 'weekly' && $weekday >= 1 && $weekday <= 7 ? $weekday : 0,
        'amount' => is_numeric($bill['amount'] ?? null) ? round(abs((float)$bill['amount']), 2) : 0.0,
        'category' => $category,
        'credit_card_id' => (string)($bill['credit_card_id'] ?? ''),
        'created_at' => (string)($bill['created_at'] ?? ''),
        'updated_at' => (string)($bill['updated_at'] ?? ''),
    ];
}

function normalizeFinanceIncome(array $income): array
{
    $cadence = (string)($income['cadence'] ?? 'monthly');
    $cadence = in_array($cadence, ['biweekly', 'monthly'], true) ? $cadence : 'monthly';
    $day = (int)($income['day'] ?? 0);

    return [
        'id' => (string)($income['id'] ?? bin2hex(random_bytes(8))),
        'name' => trim((string)($income['name'] ?? '')),
        'amount' => is_numeric($income['amount'] ?? null) ? round(abs((float)$income['amount']), 2) : 0.0,
        'cadence' => $cadence,
        'anchor_date' => $cadence === 'biweekly' ? (string)($income['anchor_date'] ?? '') : '',
        'day' => $cadence === 'monthly' && $day >= 1 && $day <= 31 ? $day : 0,
        'created_at' => (string)($income['created_at'] ?? ''),
        'updated_at' => (string)($income['updated_at'] ?? ''),
    ];
}

function normalizeFinanceAccount(array $account): array
{
    $type = (string)($account['type'] ?? 'bank');
    $type = in_array($type, ['bank', 'asset', 'debt'], true) ? $type : 'bank';
    $paymentDay = (int)($account['payment_day'] ?? 0);
    if ($paymentDay < 1 && preg_match('/^\d{4}-\d{2}-(\d{2})$/', (string)($account['payment_date'] ?? ''), $matches) === 1) {
        $paymentDay = (int)$matches[1];
    }

    return [
        'id' => (string)($account['id'] ?? bin2hex(random_bytes(8))),
        'name' => trim((string)($account['name'] ?? '')),
        'issuer' => trim((string)($account['issuer'] ?? '')),
        'notes' => (string)($account['notes'] ?? ''),
        'apr' => is_numeric($account['apr'] ?? null) ? round((float)$account['apr'], 3) : null,
        'credit_limit' => is_numeric($account['credit_limit'] ?? null) ? round((float)$account['credit_limit'], 2) : null,
        'annual_fee' => is_numeric($account['annual_fee'] ?? null) ? round((float)$account['annual_fee'], 2) : null,
        'statement_day' => (int)($account['statement_day'] ?? 0),
        'type' => $type,
        'liquid' => $type === 'bank' ? (bool)($account['liquid'] ?? true) : false,
        'credit_card' => $type === 'debt' ? (bool)($account['credit_card'] ?? true) : false,
        'payment_day' => $type === 'debt' && $paymentDay >= 1 && $paymentDay <= 31 ? $paymentDay : 0,
        'minimum_payment' => $type === 'debt' && is_numeric($account['minimum_payment'] ?? null)
            ? round(abs((float)$account['minimum_payment']), 2)
            : 0.0,
        'intended_payment' => $type === 'debt' && (bool)($account['credit_card'] ?? true) && is_numeric($account['intended_payment'] ?? null)
            ? round(abs((float)$account['intended_payment']), 2)
            : 0.0,
        'paid_payment_month' => $type === 'debt' && preg_match('/^\d{4}-\d{2}$/', (string)($account['paid_payment_month'] ?? '')) === 1
            ? (string)$account['paid_payment_month']
            : '',
        'active' => (bool)($account['active'] ?? true),
        'closed_at' => (string)($account['closed_at'] ?? ''),
        'created_at' => (string)($account['created_at'] ?? ''),
        'updated_at' => (string)($account['updated_at'] ?? ''),
    ];
}

function normalizeFinanceEntry(array $entry): array
{
    $normalized = [
        'balances' => [],
        'created_at' => (string)($entry['created_at'] ?? ''),
        'updated_at' => (string)($entry['updated_at'] ?? ''),
    ];

    foreach ((array)($entry['balances'] ?? []) as $accountId => $balance) {
        if (!is_string($accountId) || !is_numeric($balance)) {
            continue;
        }

        $normalized['balances'][$accountId] = round((float)$balance, 2);
    }

    return $normalized;
}

function loadFinanceData(string $storageFile): array
{
    if (!is_file($storageFile)) {
        return blankFinanceData();
    }

    $contents = file_get_contents($storageFile);
    if ($contents === false || trim($contents) === '') {
        return blankFinanceData();
    }

    $decoded = json_decode($contents, true);
    if (!is_array($decoded)) {
        return blankFinanceData();
    }

    $data = blankFinanceData();
    foreach ((array)($decoded['accounts'] ?? []) as $account) {
        $normalized = normalizeFinanceAccount((array)$account);
        if ($normalized['name'] !== '') {
            $data['accounts'][] = $normalized;
        }
    }

    foreach ((array)($decoded['entries'] ?? []) as $date => $entry) {
        if (!is_string($date)) {
            continue;
        }

        $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $date);
        if (!$parsed) {
            continue;
        }

        $data['entries'][$parsed->format('Y-m-d')] = normalizeFinanceEntry((array)$entry);
    }

    foreach ((array)($decoded['bills'] ?? []) as $bill) {
        $normalized = normalizeFinanceBill((array)$bill);
        $hasSchedule = (($normalized['cadence'] ?? 'monthly') === 'weekly' && (int)$normalized['weekday'] > 0)
            || (($normalized['cadence'] ?? 'monthly') === 'monthly' && (int)$normalized['day'] > 0);
        if ($normalized['name'] !== '' && $hasSchedule) {
            $data['bills'][] = $normalized;
        }
    }

    if (!array_key_exists('incomes', $decoded)) {
        $data['incomes'][] = normalizeFinanceIncome([
            'id' => 'wage-paycheck',
            'name' => 'Wage paycheck',
            'amount' => 2300.00,
            'cadence' => 'biweekly',
            'anchor_date' => '2026-06-26',
        ]);
    } else {
        foreach ((array)$decoded['incomes'] as $income) {
            $normalized = normalizeFinanceIncome((array)$income);
            if ($normalized['name'] !== '' && $normalized['amount'] > 0) {
                $data['incomes'][] = $normalized;
            }
        }
    }

    ksort($data['entries']);

    return $data;
}

function saveFinanceData(string $storageFile, array $data): bool
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return $json !== false && file_put_contents($storageFile, $json, LOCK_EX) !== false;
}

function redirectSelf(): never
{
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

function redirectFinancePage(string $page): never
{
    header('Location: finance.php?' . http_build_query(['page' => $page]));
    exit;
}

function parseMoneyAmount(string $amount): ?float
{
    $cleaned = trim(str_replace([',', '$', ' '], '', $amount));
    if ($cleaned === '') {
        return null;
    }

    if (preg_match('/^\((.+)\)$/', $cleaned, $matches) === 1) {
        $cleaned = '-' . $matches[1];
    }

    return is_numeric($cleaned) ? round((float)$cleaned, 2) : null;
}

function formatMoney(float $amount): string
{
    $prefix = $amount < 0 ? '-' : '';
    return $prefix . '$' . number_format(abs($amount), 2);
}

function formatSignedMoney(float $amount): string
{
    if ($amount === 0.0) {
        return '$0.00';
    }

    return ($amount > 0 ? '+' : '-') . '$' . number_format(abs($amount), 2);
}

function buildPieSlicePath(float $centerX, float $centerY, float $radius, float $startAngle, float $endAngle): string
{
    $startRadians = deg2rad($startAngle - 90.0);
    $endRadians = deg2rad($endAngle - 90.0);
    $startX = $centerX + ($radius * cos($startRadians));
    $startY = $centerY + ($radius * sin($startRadians));
    $endX = $centerX + ($radius * cos($endRadians));
    $endY = $centerY + ($radius * sin($endRadians));
    $largeArc = ($endAngle - $startAngle) > 180.0 ? 1 : 0;

    return sprintf(
        'M %.2f %.2f L %.2f %.2f A %.2f %.2f 0 %d 1 %.2f %.2f Z',
        $centerX,
        $centerY,
        $startX,
        $startY,
        $radius,
        $radius,
        $largeArc,
        $endX,
        $endY
    );
}

function formatDateHeader(string $date): string
{
    $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $date);
    return $parsed ? $parsed->format('l, F j, Y') : $date;
}

function formatOptionalClosedDate(string $closedAt): string
{
    if ($closedAt === '') {
        return '';
    }

    try {
        return (new DateTimeImmutable($closedAt))->format('M j, Y');
    } catch (Exception $exception) {
        return '';
    }
}

function nextMonthlyPaymentDate(int $paymentDay, DateTimeImmutable $today): ?DateTimeImmutable
{
    if ($paymentDay < 1 || $paymentDay > 31) {
        return null;
    }

    $candidateDay = min($paymentDay, (int)$today->format('t'));
    $candidate = $today->setDate((int)$today->format('Y'), (int)$today->format('m'), $candidateDay);
    if ($candidate->format('Y-m-d') < $today->format('Y-m-d')) {
        $nextMonth = $today->modify('first day of next month');
        $candidate = $nextMonth->setDate(
            (int)$nextMonth->format('Y'),
            (int)$nextMonth->format('m'),
            min($paymentDay, (int)$nextMonth->format('t'))
        );
    }

    return $candidate;
}

function nextExpectedDebtPaymentDate(array $account, DateTimeImmutable $today): ?DateTimeImmutable
{
    $paymentDay = (int)($account['payment_day'] ?? 0);
    $nextPaymentDate = nextMonthlyPaymentDate($paymentDay, $today);
    if ($nextPaymentDate !== null && debtPaymentAlreadyPaidForDate($account, $nextPaymentDate)) {
        $nextMonth = $today->modify('first day of next month');
        return nextMonthlyPaymentDate($paymentDay, $nextMonth);
    }

    return $nextPaymentDate;
}

function isEmergencyFundAccount(array $account): bool
{
    return ($account['type'] ?? '') === 'bank'
        && stripos((string)($account['name'] ?? ''), 'emergency fund') !== false;
}

function liquidMoneyForEntry(array $accounts, array $entry, bool $includeEmergencyFund): float
{
    $total = 0.0;

    foreach ($accounts as $account) {
        if (($account['type'] ?? '') !== 'bank' || !(bool)($account['liquid'] ?? true)) {
            continue;
        }
        if (!$includeEmergencyFund && isEmergencyFundAccount($account)) {
            continue;
        }

        $accountId = (string)($account['id'] ?? '');
        if (array_key_exists($accountId, $entry['balances'] ?? [])) {
            $total += (float)$entry['balances'][$accountId];
        }
    }

    return round($total, 2);
}

function projectedDebtPaymentAmount(array $account): float
{
    $minimumPayment = (float)($account['minimum_payment'] ?? 0.0);
    $intendedPayment = (float)($account['intended_payment'] ?? 0.0);

    if ((bool)($account['credit_card'] ?? true) && $intendedPayment > 0) {
        return round($intendedPayment, 2);
    }

    return round($minimumPayment, 2);
}

function debtPaymentAlreadyPaidForDate(array $account, DateTimeImmutable $date): bool
{
    return (string)($account['paid_payment_month'] ?? '') === $date->format('Y-m');
}

function incomeOccursOnDate(array $income, DateTimeImmutable $date): bool
{
    if (($income['cadence'] ?? '') === 'monthly') {
        $day = (int)($income['day'] ?? 0);
        return $day > 0 && (int)$date->format('j') === min($day, (int)$date->format('t'));
    }

    $anchorDate = (string)($income['anchor_date'] ?? '');
    $anchor = DateTimeImmutable::createFromFormat('!Y-m-d', $anchorDate);
    if (!$anchor || $anchor->format('Y-m-d') !== $anchorDate) {
        return false;
    }

    $daysFromAnchor = (int)$anchor->diff($date)->format('%r%a');

    return $daysFromAnchor >= 0 && $daysFromAnchor % 14 === 0;
}

function billOccursOnDate(array $bill, DateTimeImmutable $date): bool
{
    if (($bill['cadence'] ?? 'monthly') === 'weekly') {
        $weekday = (int)($bill['weekday'] ?? 0);
        return $weekday >= 1 && $weekday <= 7 && (int)$date->format('N') === $weekday;
    }

    $billDay = (int)($bill['day'] ?? 0);

    return $billDay > 0 && (int)$date->format('j') === min($billDay, (int)$date->format('t'));
}

function billScheduleSortValue(array $bill): int
{
    if (($bill['cadence'] ?? 'monthly') === 'weekly') {
        $weekday = (int)($bill['weekday'] ?? 0);
        return 100 + ($weekday >= 1 && $weekday <= 7 ? $weekday : 8);
    }

    $day = (int)($bill['day'] ?? 0);

    return $day > 0 ? $day : 32;
}

function billScheduleLabel(array $bill): string
{
    if (($bill['cadence'] ?? 'monthly') === 'weekly') {
        return 'Weekly ' . weekdayLabel((int)($bill['weekday'] ?? 0));
    }

    return 'Monthly day ' . (int)($bill['day'] ?? 0);
}

function monthlyBillRunRate(array $bill): float
{
    $amount = (float)($bill['amount'] ?? 0.0);
    if (($bill['cadence'] ?? 'monthly') === 'weekly') {
        return round(($amount * 52.0) / 12.0, 2);
    }

    return round($amount, 2);
}

function incomeAppearsAlreadyReceived(array $accounts, array $entries, array $income, DateTimeImmutable $expectedDate, DateTimeImmutable $latestActualDate, bool $includeEmergencyFund): bool
{
    $incomeAmount = (float)($income['amount'] ?? 0.0);
    if ($incomeAmount <= 0) {
        return false;
    }

    $tolerance = 200.0;
    $windowStart = $expectedDate->modify('-2 days')->format('Y-m-d');
    $windowEnd = $expectedDate->modify('+2 days')->format('Y-m-d');
    $latestActualKey = $latestActualDate->format('Y-m-d');
    $previousEntry = null;

    foreach ($entries as $entryDate => $entry) {
        $entryKey = (string)$entryDate;
        if ($entryKey > $latestActualKey || $entryKey > $windowEnd) {
            break;
        }

        if ($previousEntry !== null && $entryKey >= $windowStart) {
            $previousLiquid = liquidMoneyForEntry($accounts, $previousEntry, $includeEmergencyFund);
            $currentLiquid = liquidMoneyForEntry($accounts, $entry, $includeEmergencyFund);
            $increase = round($currentLiquid - $previousLiquid, 2);
            if ($increase > 0 && abs($increase - $incomeAmount) <= $tolerance) {
                return true;
            }
        }

        $previousEntry = $entry;
    }

    return false;
}

function collectLiquidityPaymentForecast(array $accounts, array $entries, array $bills, array $incomes, DateTimeImmutable $today, bool $includeEmergencyFund = true): array
{
    $series = [];
    $startDate = $today->modify('-3 days');
    $latestKnownEntry = null;
    $projectionAnchorKey = '';
    $futureScheduledDeductions = 0.0;
    $futurePaycheckIncome = 0.0;

    foreach ($entries as $entryDate => $entry) {
        if ((string)$entryDate > $today->format('Y-m-d')) {
            break;
        }
        $projectionAnchorKey = (string)$entryDate;
    }

    if ($projectionAnchorKey !== '' && $projectionAnchorKey < $startDate->format('Y-m-d')) {
        $cursor = new DateTimeImmutable($projectionAnchorKey);
        while ($cursor->format('Y-m-d') < $startDate->format('Y-m-d')) {
            if ($cursor->format('Y-m-d') > $projectionAnchorKey) {
                foreach ($incomes as $income) {
                    if (incomeOccursOnDate($income, $cursor)) {
                        $futurePaycheckIncome += (float)($income['amount'] ?? 0.0);
                    }
                }
            }

            foreach ($accounts as $account) {
                if (($account['type'] ?? '') !== 'debt' || !(bool)($account['active'] ?? true)) {
                    continue;
                }
                $paymentDay = (int)($account['payment_day'] ?? 0);
                if ($paymentDay > 0 && (int)$cursor->format('j') === min($paymentDay, (int)$cursor->format('t')) && !debtPaymentAlreadyPaidForDate($account, $cursor)) {
                    $futureScheduledDeductions += projectedDebtPaymentAmount($account);
                }
            }

            foreach ($bills as $bill) {
                if (billOccursOnDate($bill, $cursor)) {
                    $futureScheduledDeductions += (float)($bill['amount'] ?? 0.0);
                }
            }

            $cursor = $cursor->modify('+1 day');
        }
    }

    for ($index = 0; $index < 30; $index++) {
        $date = $startDate->modify('+' . $index . ' days');
        $dateKey = $date->format('Y-m-d');

        if ($dateKey <= $today->format('Y-m-d') && isset($entries[$dateKey])) {
            $latestKnownEntry = $entries[$dateKey];
        }

        if ($latestKnownEntry === null) {
            foreach ($entries as $entryDate => $entry) {
                if ((string)$entryDate > $dateKey) {
                    break;
                }
                $latestKnownEntry = $entry;
            }
        }

        $isProjected = $projectionAnchorKey !== '' && $dateKey > $projectionAnchorKey;
        $incomeDueToday = 0.0;
        $incomeAccounts = [];
        if ($isProjected) {
            foreach ($incomes as $income) {
                if (!incomeOccursOnDate($income, $date)) {
                    continue;
                }
                $incomeAmount = (float)($income['amount'] ?? 0.0);
                if ($projectionAnchorKey !== '' && incomeAppearsAlreadyReceived($accounts, $entries, $income, $date, new DateTimeImmutable($projectionAnchorKey), $includeEmergencyFund)) {
                    continue;
                }
                if ($incomeAmount > 0) {
                    $incomeDueToday += $incomeAmount;
                    $incomeAccounts[] = [
                        'name' => (string)($income['name'] ?? 'Income'),
                        'amount' => $incomeAmount,
                    ];
                }
            }
        }
        $futurePaycheckIncome += $incomeDueToday;
        $liquidMoney = $latestKnownEntry === null
            ? null
            : liquidMoneyForEntry($accounts, $latestKnownEntry, $includeEmergencyFund);
        if ($isProjected && $liquidMoney !== null) {
            $liquidMoney = round($liquidMoney + $futurePaycheckIncome - $futureScheduledDeductions, 2);
        }
        $paymentsDue = 0.0;
        $paymentAccounts = [];

        foreach ($accounts as $account) {
            if (($account['type'] ?? '') !== 'debt' || !(bool)($account['active'] ?? true)) {
                continue;
            }

            $paymentDay = (int)($account['payment_day'] ?? 0);
            $effectiveDay = $paymentDay > 0 ? min($paymentDay, (int)$date->format('t')) : 0;
            if ((int)$date->format('j') === $effectiveDay && !debtPaymentAlreadyPaidForDate($account, $date)) {
                $paymentAmount = projectedDebtPaymentAmount($account);
                if ($paymentAmount > 0) {
                    $paymentsDue += $paymentAmount;
                    $paymentAccounts[] = [
                        'name' => (string)($account['name'] ?? 'Debt account'),
                        'amount' => $paymentAmount,
                        'type' => (bool)($account['credit_card'] ?? true) && (float)($account['intended_payment'] ?? 0.0) > 0
                            ? 'Intended card payment'
                            : 'Debt payment',
                    ];
                }
            }
        }

        foreach ($bills as $bill) {
            $billAmount = (float)($bill['amount'] ?? 0.0);
            if (billOccursOnDate($bill, $date) && $billAmount > 0) {
                $billCreditCard = accountById($accounts, (string)($bill['credit_card_id'] ?? ''));
                $billType = billCategoryLabel((string)($bill['category'] ?? 'uncategorized'));
                $paymentsDue += $billAmount;
                $paymentAccounts[] = [
                    'name' => (string)($bill['name'] ?? 'Monthly bill'),
                    'amount' => $billAmount,
                    'type' => $billCreditCard === null ? $billType : $billType . ' on ' . (string)$billCreditCard['name'],
                ];
            }
        }

        if ($projectionAnchorKey !== '' && $dateKey >= $projectionAnchorKey) {
            $futureScheduledDeductions += $paymentsDue;
        }

        $series[] = [
            'date' => $dateKey,
            'liquid' => $liquidMoney,
            'payments' => round($paymentsDue, 2),
            'payment_accounts' => $paymentAccounts,
            'is_today' => $dateKey === $today->format('Y-m-d'),
            'is_projected' => $isProjected,
            'income' => round($incomeDueToday, 2),
            'income_accounts' => $incomeAccounts,
        ];
    }

    return $series;
}

function activeAccounts(array $accounts): array
{
    return array_values(array_filter($accounts, function (array $account): bool {
        return (bool)$account['active'];
    }));
}

function inactiveAccounts(array $accounts): array
{
    return array_values(array_filter($accounts, function (array $account): bool {
        return !(bool)$account['active'];
    }));
}

function accountsByType(array $accounts, string $type): array
{
    return array_values(array_filter($accounts, function (array $account) use ($type): bool {
        return $account['type'] === $type;
    }));
}

function accountById(array $accounts, string $accountId): ?array
{
    foreach ($accounts as $account) {
        if ((string)($account['id'] ?? '') === $accountId) {
            return $account;
        }
    }

    return null;
}

function latestEntryDate(array $entries): string
{
    if ($entries === []) {
        return '';
    }

    $dates = array_keys($entries);
    rsort($dates);

    return (string)$dates[0];
}

function previousEntryDate(array $entries, string $date): string
{
    $dates = array_keys($entries);
    rsort($dates);

    foreach ($dates as $entryDate) {
        if ((string)$entryDate < $date) {
            return (string)$entryDate;
        }
    }

    return '';
}

function fallbackBalanceForAccount(array $entries, string $accountId): ?float
{
    $dates = array_keys($entries);
    rsort($dates);

    foreach ($dates as $date) {
        if (array_key_exists($accountId, $entries[$date]['balances'] ?? [])) {
            return (float)$entries[$date]['balances'][$accountId];
        }
    }

    return null;
}

function calculateTotals(array $accounts, array $entry): array
{
    $bankTotal = 0.0;
    $assetTotal = 0.0;
    $liquidBankTotal = 0.0;
    $debtTotal = 0.0;
    $creditCardDebtTotal = 0.0;

    foreach ($accounts as $account) {
        $accountId = (string)$account['id'];
        if (!array_key_exists($accountId, $entry['balances'] ?? [])) {
            continue;
        }

        $balance = (float)$entry['balances'][$accountId];
        if ($account['type'] === 'debt') {
            $debtBalance = abs($balance);
            $debtTotal += $debtBalance;
            if ((bool)($account['credit_card'] ?? true)) {
                $creditCardDebtTotal += $debtBalance;
            }
        } elseif ($account['type'] === 'asset') {
            $assetTotal += $balance;
        } else {
            $bankTotal += $balance;
            if ((bool)($account['liquid'] ?? true)) {
                $liquidBankTotal += $balance;
            }
        }
    }

    return [
        'bank' => round($bankTotal, 2),
        'asset' => round($assetTotal, 2),
        'liquid_bank' => round($liquidBankTotal, 2),
        'debt' => round($debtTotal, 2),
        'credit_card_debt' => round($creditCardDebtTotal, 2),
        'liquid_after_credit_cards' => round($liquidBankTotal - $creditCardDebtTotal, 2),
        'net' => round($bankTotal + $assetTotal - $debtTotal, 2),
    ];
}

function chartableAccounts(array $accounts, array $entries): array
{
    $accountIdsWithEntries = [];
    foreach ($entries as $entry) {
        foreach (array_keys((array)($entry['balances'] ?? [])) as $accountId) {
            $accountIdsWithEntries[(string)$accountId] = true;
        }
    }

    return array_values(array_filter($accounts, function (array $account) use ($accountIdsWithEntries): bool {
        return isset($accountIdsWithEntries[(string)($account['id'] ?? '')]);
    }));
}

function calculatedChartOptions(): array
{
    return [
        ['id' => 'total:liquid_bank', 'name' => 'Liquid money', 'type' => 'calculated', 'total_key' => 'liquid_bank'],
        ['id' => 'total:liquid_after_credit_cards', 'name' => 'Liquid after cards', 'type' => 'calculated', 'total_key' => 'liquid_after_credit_cards'],
        ['id' => 'total:net', 'name' => 'Total net worth', 'type' => 'calculated', 'total_key' => 'net'],
        ['id' => 'total:bank', 'name' => 'Total bank accounts', 'type' => 'calculated', 'total_key' => 'bank'],
        ['id' => 'total:asset', 'name' => 'Total assets', 'type' => 'calculated', 'total_key' => 'asset'],
        ['id' => 'total:debt', 'name' => 'Total debt', 'type' => 'calculated', 'total_key' => 'debt'],
        ['id' => 'total:credit_card_debt', 'name' => 'Credit cards', 'type' => 'calculated', 'total_key' => 'credit_card_debt'],
    ];
}

function chartOptions(array $accounts, array $entries): array
{
    if ($entries === []) {
        return [];
    }

    $options = calculatedChartOptions();
    foreach (chartableAccounts($accounts, $entries) as $account) {
        $options[] = [
            'id' => (string)$account['id'],
            'name' => (string)$account['name'],
            'type' => 'account',
            'account_id' => (string)$account['id'],
            'account_type' => (string)$account['type'],
        ];
    }

    return $options;
}

function chartOptionById(array $options, string $optionId): ?array
{
    foreach ($options as $option) {
        if ((string)($option['id'] ?? '') === $optionId) {
            return $option;
        }
    }

    return null;
}

function collectChartSeries(array $accounts, array $entries, array $option, string $range, DateTimeImmutable $today): array
{
    $series = [];
    $startDate = null;

    if ($range !== 'all' && ctype_digit($range)) {
        $startDate = $today->modify('-' . (int)$range . ' days')->format('Y-m-d');
    }

    foreach ($entries as $date => $entry) {
        if ($startDate !== null && (string)$date < $startDate) {
            continue;
        }

        if (($option['type'] ?? '') === 'calculated') {
            $totals = calculateTotals($accounts, $entry);
            $totalKey = (string)($option['total_key'] ?? '');
            if (!array_key_exists($totalKey, $totals)) {
                continue;
            }

            $value = (float)$totals[$totalKey];
        } else {
            $accountId = (string)($option['account_id'] ?? '');
            if (!array_key_exists($accountId, $entry['balances'] ?? [])) {
                continue;
            }

            $value = (float)$entry['balances'][$accountId];
        }

        $series[] = [
            'date' => (string)$date,
            'value' => $value,
        ];
    }

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

function creditCardAccounts(array $accounts): array
{
    return array_values(array_filter($accounts, function (array $account): bool {
        return $account['type'] === 'debt' && (bool)($account['credit_card'] ?? true);
    }));
}

function collectAccountDailyBalances(array $entries, string $accountId, DateTimeImmutable $today, int $days): array
{
    $series = [];
    $startDate = $today->modify('-' . ($days - 1) . ' days');
    $startKey = $startDate->format('Y-m-d');
    $lastValue = null;

    foreach ($entries as $date => $entry) {
        if ((string)$date >= $startKey) {
            break;
        }

        if (array_key_exists($accountId, $entry['balances'] ?? [])) {
            $lastValue = abs((float)$entry['balances'][$accountId]);
        }
    }

    for ($index = 0; $index < $days; $index++) {
        $date = $startDate->modify('+' . $index . ' days')->format('Y-m-d');
        $recorded = false;

        if (array_key_exists($accountId, $entries[$date]['balances'] ?? [])) {
            $lastValue = abs((float)$entries[$date]['balances'][$accountId]);
            $recorded = true;
        }

        $series[] = [
            'date' => $date,
            'value' => $lastValue,
            'recorded' => $recorded,
        ];
    }

    return $series;
}

function accountSpendStats(array $series): array
{
    $savedValues = [];
    $spendTotal = 0.0;
    $paymentTotal = 0.0;
    $intervals = 0;
    $previousValue = null;

    foreach ($series as $point) {
        if ($point['value'] === null) {
            continue;
        }

        $value = (float)$point['value'];
        $savedValues[] = $value;

        if ($previousValue !== null) {
            $intervals++;
            $increase = $value - $previousValue;
            if ($increase > 0) {
                $spendTotal += $increase;
            } elseif ($increase < 0) {
                $paymentTotal += abs($increase);
            }
        }

        $previousValue = $value;
    }

    return [
        'balance_days' => count($savedValues),
        'recorded_days' => count(array_filter($series, function (array $point): bool {
            return (bool)($point['recorded'] ?? false);
        })),
        'intervals' => $intervals,
        'spend_total' => round($spendTotal, 2),
        'payment_total' => round($paymentTotal, 2),
        'average_daily_spend' => $intervals > 0 ? round($spendTotal / $intervals, 2) : 0.0,
        'average_daily_payments' => $intervals > 0 ? round($paymentTotal / $intervals, 2) : 0.0,
        'payment_deficit' => round($spendTotal - $paymentTotal, 2),
        'current_balance' => $savedValues === [] ? null : round((float)$savedValues[count($savedValues) - 1], 2),
        'high_balance' => $savedValues === [] ? null : round(max($savedValues), 2),
        'low_balance' => $savedValues === [] ? null : round(min($savedValues), 2),
    ];
}

function creditCardSpendingSinceLastPayment(array $entries, string $accountId): float
{
    $lastPaymentDate = '';
    $previousValue = null;

    foreach ($entries as $date => $entry) {
        if (!array_key_exists($accountId, $entry['balances'] ?? [])) {
            continue;
        }

        $value = abs((float)$entry['balances'][$accountId]);
        if ($previousValue !== null && $value < $previousValue) {
            $lastPaymentDate = (string)$date;
        }

        $previousValue = $value;
    }

    if ($lastPaymentDate === '') {
        return 0.0;
    }

    $spending = 0.0;
    $previousValue = null;

    foreach ($entries as $date => $entry) {
        if ((string)$date < $lastPaymentDate || !array_key_exists($accountId, $entry['balances'] ?? [])) {
            continue;
        }

        $value = abs((float)$entry['balances'][$accountId]);
        if ($previousValue !== null && $value > $previousValue) {
            $spending += $value - $previousValue;
        }

        $previousValue = $value;
    }

    return round($spending, 2);
}

function collectCombinedAccountDailyBalances(array $entries, array $accounts, DateTimeImmutable $today, int $days): array
{
    $combined = [];

    foreach ($accounts as $account) {
        $accountSeries = collectAccountDailyBalances($entries, (string)$account['id'], $today, $days);

        foreach ($accountSeries as $index => $point) {
            if (!isset($combined[$index])) {
                $combined[$index] = [
                    'date' => (string)$point['date'],
                    'value' => null,
                    'recorded' => false,
                ];
            }

            if ($point['value'] !== null) {
                $combined[$index]['value'] = ($combined[$index]['value'] ?? 0.0) + (float)$point['value'];
            }

            if ((bool)($point['recorded'] ?? false)) {
                $combined[$index]['recorded'] = true;
            }
        }
    }

    return array_values($combined);
}

function buildCreditCardToggleUrl(array $selectedIds, string $toggleId, string $range): string
{
    $nextIds = array_values(array_filter($selectedIds, function (string $selectedId) use ($toggleId): bool {
        return $selectedId !== $toggleId;
    }));

    $query = [
        'cards' => '1',
        'cc_range' => $range,
    ];
    if (count($nextIds) === count($selectedIds)) {
        $nextIds[] = $toggleId;
    }

    if ($nextIds !== []) {
        $query['cc'] = $nextIds;
    } else {
        $query['none'] = '1';
    }

    return 'finance.php?' . http_build_query($query);
}

function buildBillSortUrl(string $sortKey, string $currentSort, string $currentDirection): string
{
    $nextDirection = $sortKey === $currentSort && $currentDirection === 'asc' ? 'desc' : 'asc';

    return 'finance.php?' . http_build_query([
        'bills' => '1',
        'bill_sort' => $sortKey,
        'bill_dir' => $nextDirection,
    ]);
}

$data = loadFinanceData($storageFile);

[$isAuthenticated, $authError] = kataHandleUnlock($todayKey);
if ($isAuthenticated) {
    ensureDailyJsonBackups($todayKey);
}

$focusError = $isAuthenticated ? kataHandleMainFocusSave() : '';
if ($focusError !== '') {
    $error = $focusError;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAuthenticated) {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_card_details') {
        $cardId = (string)($_GET['account'] ?? '');
        $card = accountById(creditCardAccounts($data['accounts']), $cardId);
        $changes = [];
        if ($card === null) {
            $error = 'Credit card not found.';
        }
        foreach (['apr' => 'APR', 'credit_limit' => 'Credit limit', 'annual_fee' => 'Annual fee'] as $field => $label) {
            $raw = trim((string)($_POST[$field] ?? ''));
            if ($raw !== '' && (!is_numeric($raw) || !is_finite((float)$raw) || (float)$raw < 0 || ($field === 'apr' && (float)$raw > 100))) {
                $error = $label . ' must be a nonnegative number' . ($field === 'apr' ? ' between 0 and 100.' : '.');
            }
            $changes[$field] = $raw === '' ? null : round((float)$raw, $field === 'apr' ? 3 : 2);
        }
        $day = trim((string)($_POST['statement_day'] ?? ''));
        if ($day !== '' && (!ctype_digit($day) || (int)$day < 1 || (int)$day > 31)) {
            $error = 'Statement closing day must be between 1 and 31.';
        }
        $changes['statement_day'] = $day === '' ? 0 : (int)$day;
        $changes['issuer'] = trim((string)($_POST['issuer'] ?? ''));
        $changes['notes'] = trim((string)($_POST['notes'] ?? ''));
        if (strlen($changes['issuer']) > 120 || strlen($changes['notes']) > 4000) {
            $error = 'Keep the issuer under 120 bytes and notes under 4,000 bytes.';
        }
        if ($error === '') {
            foreach ($data['accounts'] as &$account) {
                if ($account['id'] === $cardId) {
                    $account = array_merge($account, $changes, ['updated_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM)]);
                }
            }
            unset($account);
            if (saveFinanceData($storageFile, $data)) {
                header('Location: finance.php?' . http_build_query(['account' => $cardId, 'saved' => '1']));
                exit;
            }
            $error = 'Could not save card details. Check that this folder is writable.';
        }
    }

    if ($action === 'add_account') {
        $name = trim((string)($_POST['account_name'] ?? ''));
        $type = (string)($_POST['account_type'] ?? 'bank');
        $liquid = (string)($_POST['account_liquid'] ?? '1') === '1';
        $creditCard = (string)($_POST['account_credit_card'] ?? '1') === '1';

        if ($name === '') {
            $error = 'Give the account a name before adding it.';
        } elseif (!in_array($type, ['bank', 'asset', 'debt'], true)) {
            $error = 'Choose whether this is a bank account, asset, or debt account.';
        } else {
            $data['accounts'][] = [
                'id' => bin2hex(random_bytes(8)),
                'name' => $name,
                'type' => $type,
                'liquid' => $type === 'bank' ? $liquid : false,
                'credit_card' => $type === 'debt' ? $creditCard : false,
                'payment_day' => 0,
                'minimum_payment' => 0.0,
                'intended_payment' => 0.0,
                'paid_payment_month' => '',
                'active' => true,
                'created_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
                'updated_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            ];

            if (saveFinanceData($storageFile, $data)) {
                redirectFinancePage('accounts');
            }

            $error = 'Could not save the account. Check that this folder is writable.';
        }
    }

    if ($action === 'drop_account' || $action === 'restore_account') {
        $accountId = (string)($_POST['account_id'] ?? '');
        foreach ($data['accounts'] as $index => $account) {
            if (($account['id'] ?? '') === $accountId) {
                $isRestoring = $action === 'restore_account';
                $timestamp = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
                $data['accounts'][$index]['active'] = $isRestoring;
                $data['accounts'][$index]['closed_at'] = $isRestoring ? '' : $timestamp;
                $data['accounts'][$index]['updated_at'] = $timestamp;
                break;
            }
        }

        if (saveFinanceData($storageFile, $data)) {
            redirectFinancePage('accounts');
        }

        $error = 'Could not update the account. Check that this folder is writable.';
    }

    if ($action === 'toggle_liquidity') {
        $accountId = (string)($_POST['account_id'] ?? '');
        foreach ($data['accounts'] as $index => $account) {
            if (($account['id'] ?? '') === $accountId && ($account['type'] ?? '') === 'bank') {
                $data['accounts'][$index]['liquid'] = !(bool)($account['liquid'] ?? true);
                $data['accounts'][$index]['updated_at'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
                break;
            }
        }

        if (saveFinanceData($storageFile, $data)) {
            redirectFinancePage('accounts');
        }

        $error = 'Could not update liquidity. Check that this folder is writable.';
    }

    if ($action === 'toggle_credit_card') {
        $accountId = (string)($_POST['account_id'] ?? '');
        foreach ($data['accounts'] as $index => $account) {
            if (($account['id'] ?? '') === $accountId && ($account['type'] ?? '') === 'debt') {
                $data['accounts'][$index]['credit_card'] = !(bool)($account['credit_card'] ?? true);
                $data['accounts'][$index]['updated_at'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
                break;
            }
        }

        if (saveFinanceData($storageFile, $data)) {
            redirectFinancePage('accounts');
        }

        $error = 'Could not update debt category. Check that this folder is writable.';
    }

    if ($action === 'save_payment_plan') {
        $accountId = (string)($_POST['account_id'] ?? '');
        $paymentDay = (int)($_POST['payment_day'] ?? 0);
        $minimumPayment = parseMoneyAmount((string)($_POST['minimum_payment'] ?? ''));
        $intendedPaymentInput = trim((string)($_POST['intended_payment'] ?? ''));
        $intendedPayment = $intendedPaymentInput === '' ? 0.0 : parseMoneyAmount($intendedPaymentInput);
        $paidThisMonth = (string)($_POST['paid_this_month'] ?? '0') === '1';
        $accountIndex = null;

        foreach ($data['accounts'] as $index => $account) {
            if (($account['id'] ?? '') === $accountId && ($account['type'] ?? '') === 'debt' && (bool)($account['active'] ?? true)) {
                $accountIndex = $index;
                break;
            }
        }

        if ($accountIndex === null) {
            $error = 'Choose an active debt account.';
        } elseif ($paymentDay < 1 || $paymentDay > 31) {
            $error = 'Choose a payment day from 1 through 31.';
        } elseif ($minimumPayment === null || $minimumPayment < 0) {
            $error = 'Enter a valid minimum payment.';
        } elseif ($intendedPayment === null || $intendedPayment < 0) {
            $error = 'Enter a valid intended payment, or leave it blank.';
        } else {
            $data['accounts'][$accountIndex]['payment_day'] = $paymentDay;
            unset($data['accounts'][$accountIndex]['payment_date']);
            $data['accounts'][$accountIndex]['minimum_payment'] = round($minimumPayment, 2);
            $data['accounts'][$accountIndex]['intended_payment'] = (bool)($data['accounts'][$accountIndex]['credit_card'] ?? true)
                ? round((float)$intendedPayment, 2)
                : 0.0;
            $data['accounts'][$accountIndex]['paid_payment_month'] = $paidThisMonth ? $now->format('Y-m') : '';
            $data['accounts'][$accountIndex]['updated_at'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);

            if (saveFinanceData($storageFile, $data)) {
                redirectFinancePage('payment');
            }

            $error = 'Could not save the payment plan. Check that this folder is writable.';
        }
    }

    if ($action === 'add_bill') {
        $billName = trim((string)($_POST['bill_name'] ?? ''));
        $billCadence = (string)($_POST['bill_cadence'] ?? 'monthly');
        $billDay = (int)($_POST['bill_day'] ?? 0);
        $billWeekday = (int)($_POST['bill_weekday'] ?? 0);
        $billAmount = parseMoneyAmount((string)($_POST['bill_amount'] ?? ''));
        $billCategory = (string)($_POST['bill_category'] ?? 'uncategorized');
        $billCreditCardId = (string)($_POST['bill_credit_card_id'] ?? '');
        $billCreditCardAccounts = creditCardAccounts(activeAccounts($data['accounts']));

        if ($billName === '') {
            $error = 'Give the bill a name.';
        } elseif (!in_array($billCadence, ['monthly', 'weekly'], true)) {
            $error = 'Choose a bill schedule.';
        } elseif (!array_key_exists($billCategory, billCategoryOptions())) {
            $error = 'Choose a bill category.';
        } elseif ($billCadence === 'monthly' && ($billDay < 1 || $billDay > 31)) {
            $error = 'Choose a bill day from 1 through 31.';
        } elseif ($billCadence === 'weekly' && ($billWeekday < 1 || $billWeekday > 7)) {
            $error = 'Choose a weekday for that bill.';
        } elseif ($billAmount === null || $billAmount <= 0) {
            $error = 'Enter a bill amount greater than zero.';
        } elseif ($billCreditCardId !== '' && accountById($billCreditCardAccounts, $billCreditCardId) === null) {
            $error = 'Choose an active credit card for that bill.';
        } else {
            $timestamp = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
            $data['bills'][] = [
                'id' => bin2hex(random_bytes(8)),
                'name' => $billName,
                'cadence' => $billCadence,
                'day' => $billCadence === 'monthly' ? $billDay : 0,
                'weekday' => $billCadence === 'weekly' ? $billWeekday : 0,
                'amount' => round(abs($billAmount), 2),
                'category' => $billCategory,
                'credit_card_id' => $billCreditCardId,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];

            if (saveFinanceData($storageFile, $data)) {
                header('Location: finance.php?bills=1');
                exit;
            }

            $error = 'Could not save the bill. Check that this folder is writable.';
        }
    }

    if ($action === 'save_bill_card') {
        $billId = (string)($_POST['bill_id'] ?? '');
        $billCadence = (string)($_POST['bill_cadence'] ?? 'monthly');
        $billDay = (int)($_POST['bill_day'] ?? 0);
        $billWeekday = (int)($_POST['bill_weekday'] ?? 0);
        $billAmount = parseMoneyAmount((string)($_POST['bill_amount'] ?? ''));
        $billCategory = (string)($_POST['bill_category'] ?? 'uncategorized');
        $billCreditCardId = (string)($_POST['bill_credit_card_id'] ?? '');
        $billCreditCardAccounts = creditCardAccounts(activeAccounts($data['accounts']));
        $billIndex = null;

        foreach ($data['bills'] as $index => $bill) {
            if ((string)($bill['id'] ?? '') === $billId) {
                $billIndex = $index;
                break;
            }
        }

        if ($billIndex === null) {
            $error = 'Choose a saved bill to update.';
        } elseif (!in_array($billCadence, ['monthly', 'weekly'], true)) {
            $error = 'Choose a bill schedule.';
        } elseif ($billCadence === 'monthly' && ($billDay < 1 || $billDay > 31)) {
            $error = 'Choose a bill day from 1 through 31.';
        } elseif ($billCadence === 'weekly' && ($billWeekday < 1 || $billWeekday > 7)) {
            $error = 'Choose a weekday for that bill.';
        } elseif ($billAmount === null || $billAmount <= 0) {
            $error = 'Enter a bill amount greater than zero.';
        } elseif (!array_key_exists($billCategory, billCategoryOptions())) {
            $error = 'Choose a bill category.';
        } elseif ($billCreditCardId !== '' && accountById($billCreditCardAccounts, $billCreditCardId) === null) {
            $error = 'Choose an active credit card for that bill.';
        } else {
            $data['bills'][$billIndex]['cadence'] = $billCadence;
            $data['bills'][$billIndex]['day'] = $billCadence === 'monthly' ? $billDay : 0;
            $data['bills'][$billIndex]['weekday'] = $billCadence === 'weekly' ? $billWeekday : 0;
            $data['bills'][$billIndex]['amount'] = round(abs($billAmount), 2);
            $data['bills'][$billIndex]['category'] = $billCategory;
            $data['bills'][$billIndex]['credit_card_id'] = $billCreditCardId;
            $data['bills'][$billIndex]['updated_at'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);

            if (saveFinanceData($storageFile, $data)) {
                header('Location: finance.php?bills=1');
                exit;
            }

            $error = 'Could not update the bill card. Check that this folder is writable.';
        }
    }

    if ($action === 'delete_bill') {
        $billId = (string)($_POST['bill_id'] ?? '');
        $data['bills'] = array_values(array_filter($data['bills'], function (array $bill) use ($billId): bool {
            return (string)($bill['id'] ?? '') !== $billId;
        }));

        if (saveFinanceData($storageFile, $data)) {
            header('Location: finance.php?bills=1');
            exit;
        }

        $error = 'Could not remove the bill. Check that this folder is writable.';
    }

    if ($action === 'save_income') {
        $incomeId = trim((string)($_POST['income_id'] ?? ''));
        $incomeName = trim((string)($_POST['income_name'] ?? ''));
        $incomeAmount = parseMoneyAmount((string)($_POST['income_amount'] ?? ''));
        $incomeCadence = (string)($_POST['income_cadence'] ?? '');
        $incomeAnchorDate = trim((string)($_POST['income_anchor_date'] ?? ''));
        $incomeDay = (int)($_POST['income_day'] ?? 0);
        $parsedAnchor = $incomeAnchorDate === '' ? false : DateTimeImmutable::createFromFormat('!Y-m-d', $incomeAnchorDate);

        if ($incomeName === '') {
            $error = 'Give the income a name.';
        } elseif ($incomeAmount === null || $incomeAmount <= 0) {
            $error = 'Enter an income amount greater than zero.';
        } elseif (!in_array($incomeCadence, ['biweekly', 'monthly'], true)) {
            $error = 'Choose an income schedule.';
        } elseif ($incomeCadence === 'biweekly' && (!$parsedAnchor || $parsedAnchor->format('Y-m-d') !== $incomeAnchorDate)) {
            $error = 'Choose a valid payday to anchor the biweekly schedule.';
        } elseif ($incomeCadence === 'monthly' && ($incomeDay < 1 || $incomeDay > 31)) {
            $error = 'Choose a monthly income day from 1 through 31.';
        } else {
            $timestamp = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
            $savedIncome = [
                'id' => $incomeId !== '' ? $incomeId : bin2hex(random_bytes(8)),
                'name' => $incomeName,
                'amount' => round(abs($incomeAmount), 2),
                'cadence' => $incomeCadence,
                'anchor_date' => $incomeCadence === 'biweekly' ? $incomeAnchorDate : '',
                'day' => $incomeCadence === 'monthly' ? $incomeDay : 0,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
            $updated = false;

            foreach ($data['incomes'] as $index => $income) {
                if ((string)($income['id'] ?? '') === $incomeId && $incomeId !== '') {
                    $savedIncome['created_at'] = (string)($income['created_at'] ?? $timestamp);
                    $data['incomes'][$index] = $savedIncome;
                    $updated = true;
                    break;
                }
            }
            if (!$updated) {
                $data['incomes'][] = $savedIncome;
            }

            if (saveFinanceData($storageFile, $data)) {
                header('Location: finance.php?income=1');
                exit;
            }

            $error = 'Could not save the income. Check that this folder is writable.';
        }
    }

    if ($action === 'delete_income') {
        $incomeId = (string)($_POST['income_id'] ?? '');
        $data['incomes'] = array_values(array_filter($data['incomes'], function (array $income) use ($incomeId): bool {
            return (string)($income['id'] ?? '') !== $incomeId;
        }));

        if (saveFinanceData($storageFile, $data)) {
            header('Location: finance.php?income=1');
            exit;
        }

        $error = 'Could not remove the income. Check that this folder is writable.';
    }

    if ($action === 'save_entry') {
        $entryDate = (string)($_POST['entry_date'] ?? $todayKey);
        $parsedDate = DateTimeImmutable::createFromFormat('Y-m-d', $entryDate);
        $balances = [];

        if (!$parsedDate) {
            $error = 'Choose a valid date for the tally.';
        } else {
            foreach (activeAccounts($data['accounts']) as $account) {
                $accountId = (string)$account['id'];
                $postedAmount = (string)(($_POST['balances'][$accountId] ?? ''));
                $amount = parseMoneyAmount($postedAmount);

                if ($amount === null) {
                    $error = 'Enter a dollar value for every active account.';
                    break;
                }

                $balances[$accountId] = round(abs($amount), 2);
            }
        }

        if ($error === '') {
            $dateKey = $parsedDate->format('Y-m-d');
            $alreadyCreated = (string)($data['entries'][$dateKey]['created_at'] ?? '');
            $data['entries'][$dateKey] = [
                'balances' => $balances,
                'created_at' => $alreadyCreated !== '' ? $alreadyCreated : (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
                'updated_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            ];

            if (saveFinanceData($storageFile, $data)) {
                redirectSelf();
            }

            $error = 'Could not save the tally. Check that this folder is writable.';
        }
    }
}

$activeAccounts = activeAccounts($data['accounts']);
$bankAccounts = accountsByType($activeAccounts, 'bank');
$assetAccounts = accountsByType($activeAccounts, 'asset');
$debtAccounts = accountsByType($activeAccounts, 'debt');
$creditCardAccounts = creditCardAccounts($activeAccounts);
$droppedAccounts = inactiveAccounts($data['accounts']);
$billCategoryOptions = billCategoryOptions();
$monthlyBillTotal = 0.0;
$interestPaymentTotal = 0.0;
$assignedMonthlyBillTotal = 0.0;
$unassignedMonthlyBillTotal = 0.0;
foreach ($data['bills'] as $bill) {
    $billAmount = monthlyBillRunRate($bill);
    $monthlyBillTotal += $billAmount;
    if ((string)($bill['category'] ?? 'monthly_bill') === 'interest_payment') {
        $interestPaymentTotal += $billAmount;
    }
    if ((string)($bill['credit_card_id'] ?? '') !== '') {
        $assignedMonthlyBillTotal += $billAmount;
    } else {
        $unassignedMonthlyBillTotal += $billAmount;
    }
}
$monthlyBillTotal = round($monthlyBillTotal, 2);
$interestPaymentTotal = round($interestPaymentTotal, 2);
$assignedMonthlyBillTotal = round($assignedMonthlyBillTotal, 2);
$unassignedMonthlyBillTotal = round($unassignedMonthlyBillTotal, 2);
$billCategoryBreakdownColors = ['#2563eb', '#d97706', '#16a34a', '#dc2626', '#7c3aed', '#0891b2', '#be185d', '#4d7c0f', '#ea580c', '#475569', '#9333ea', '#0f766e'];
$billCategoryBreakdown = [];
foreach ($data['bills'] as $bill) {
    $billAmount = monthlyBillRunRate($bill);
    if ($billAmount <= 0.0) {
        continue;
    }

    $category = (string)($bill['category'] ?? 'uncategorized');
    if (!array_key_exists($category, $billCategoryOptions)) {
        $category = 'uncategorized';
    }
    if (!isset($billCategoryBreakdown[$category])) {
        $billCategoryBreakdown[$category] = [
            'category' => $category,
            'name' => billCategoryLabel($category),
            'amount' => 0.0,
            'count' => 0,
        ];
    }

    $billCategoryBreakdown[$category]['amount'] += $billAmount;
    $billCategoryBreakdown[$category]['count']++;
}
$billCategoryBreakdown = array_values($billCategoryBreakdown);
usort($billCategoryBreakdown, function (array $left, array $right): int {
    $comparison = (float)($right['amount'] ?? 0.0) <=> (float)($left['amount'] ?? 0.0);
    if ($comparison === 0) {
        $comparison = strcasecmp((string)($left['name'] ?? ''), (string)($right['name'] ?? ''));
    }

    return $comparison;
});
foreach ($billCategoryBreakdown as $index => $slice) {
    $billCategoryBreakdown[$index]['amount'] = round((float)$slice['amount'], 2);
    $billCategoryBreakdown[$index]['percent'] = $monthlyBillTotal > 0.0
        ? (((float)$slice['amount'] / $monthlyBillTotal) * 100.0)
        : 0.0;
    $billCategoryBreakdown[$index]['color'] = $billCategoryBreakdownColors[$index % count($billCategoryBreakdownColors)];
}
$billSort = (string)($_GET['bill_sort'] ?? 'day');
if (!in_array($billSort, ['day', 'amount'], true)) {
    $billSort = 'day';
}
$billSortDirection = (string)($_GET['bill_dir'] ?? 'asc');
if (!in_array($billSortDirection, ['asc', 'desc'], true)) {
    $billSortDirection = 'asc';
}
$sortedBills = $data['bills'];
usort($sortedBills, function (array $left, array $right) use ($billSort, $billSortDirection): int {
    if ($billSort === 'amount') {
        $comparison = monthlyBillRunRate($left) <=> monthlyBillRunRate($right);
    } else {
        $comparison = billScheduleSortValue($left) <=> billScheduleSortValue($right);
    }

    if ($comparison === 0) {
        $comparison = strcasecmp((string)($left['name'] ?? ''), (string)($right['name'] ?? ''));
    }

    return $billSortDirection === 'desc' ? -$comparison : $comparison;
});
$todayEntry = $data['entries'][$todayKey] ?? ['balances' => [], 'created_at' => '', 'updated_at' => ''];
$latestDate = isset($data['entries'][$todayKey]) ? $todayKey : latestEntryDate($data['entries']);
$latestEntry = $latestDate !== '' ? $data['entries'][$latestDate] : ['balances' => [], 'created_at' => '', 'updated_at' => ''];
$latestTotals = calculateTotals($data['accounts'], $latestEntry);
$creditCardBreakdownColors = ['#2563eb', '#dc2626', '#7c3aed', '#d97706', '#0891b2', '#be185d', '#4d7c0f', '#475569'];
$creditCardDebtBreakdown = [];
$creditCardDebtTotal = 0.0;
foreach ($creditCardAccounts as $account) {
    $accountId = (string)($account['id'] ?? '');
    if (!array_key_exists($accountId, $latestEntry['balances'] ?? [])) {
        continue;
    }

    $balance = (float)$latestEntry['balances'][$accountId];
    if ($balance <= 0.0) {
        continue;
    }

    $creditCardDebtTotal += $balance;
    $creditCardDebtBreakdown[] = [
        'name' => (string)($account['name'] ?? 'Credit card'),
        'balance' => round($balance, 2),
        'new_spending' => min(round($balance, 2), creditCardSpendingSinceLastPayment($data['entries'], $accountId)),
    ];
}
usort($creditCardDebtBreakdown, function (array $left, array $right): int {
    $comparison = (float)($right['balance'] ?? 0.0) <=> (float)($left['balance'] ?? 0.0);
    if ($comparison === 0) {
        $comparison = strcasecmp((string)($left['name'] ?? ''), (string)($right['name'] ?? ''));
    }

    return $comparison;
});
foreach ($creditCardDebtBreakdown as $index => $card) {
    $creditCardDebtBreakdown[$index]['share_percent'] = $creditCardDebtTotal > 0.0
        ? (((float)$card['balance'] / $creditCardDebtTotal) * 100.0)
        : 0.0;
    $creditCardDebtBreakdown[$index]['bar_percent'] = (float)($creditCardDebtBreakdown[0]['balance'] ?? 0.0) > 0.0
        ? (((float)$card['balance'] / (float)$creditCardDebtBreakdown[0]['balance']) * 100.0)
        : 0.0;
    $creditCardDebtBreakdown[$index]['new_spending_percent'] = (float)($card['balance'] ?? 0.0) > 0.0
        ? (((float)($card['new_spending'] ?? 0.0) / (float)$card['balance']) * 100.0)
        : 0.0;
    $creditCardDebtBreakdown[$index]['color'] = $creditCardBreakdownColors[$index % count($creditCardBreakdownColors)];
}
$assetBreakdownColors = ['#2f855a', '#2563eb', '#d97706', '#7c3aed', '#dc2626', '#0891b2', '#4d7c0f', '#be185d'];
$assetBreakdown = [];
$assetBreakdownTotal = 0.0;
foreach (array_merge($bankAccounts, $assetAccounts) as $account) {
    $accountId = (string)($account['id'] ?? '');
    if (!array_key_exists($accountId, $latestEntry['balances'] ?? [])) {
        continue;
    }

    $balance = (float)$latestEntry['balances'][$accountId];
    if ($balance <= 0.0) {
        continue;
    }

    $assetBreakdownTotal += $balance;
    $assetBreakdown[] = [
        'name' => (string)($account['name'] ?? 'Bank account'),
        'type' => (string)($account['type'] ?? 'bank'),
        'balance' => round($balance, 2),
    ];
}
foreach ($assetBreakdown as $index => $slice) {
    $assetBreakdown[$index]['percent'] = $assetBreakdownTotal > 0.0
        ? (((float)$slice['balance'] / $assetBreakdownTotal) * 100.0)
        : 0.0;
    $assetBreakdown[$index]['color'] = $assetBreakdownColors[$index % count($assetBreakdownColors)];
}
$previousDate = $latestDate !== '' ? previousEntryDate($data['entries'], $latestDate) : '';
$previousTotals = $previousDate !== '' ? calculateTotals($data['accounts'], $data['entries'][$previousDate]) : null;
$dayOverDayChange = $previousTotals === null ? null : round((float)$latestTotals['net'] - (float)$previousTotals['net'], 2);
$includeEmergencyFund = (string)($_GET['include_emergency'] ?? '0') === '1';
$emergencyFundAccounts = array_values(array_filter($activeAccounts, 'isEmergencyFundAccount'));
$liquidityForecast = collectLiquidityPaymentForecast($data['accounts'], $data['entries'], $data['bills'], $data['incomes'], $now, $includeEmergencyFund);
$liquidityForecastMax = max(array_merge([1.0], array_map(function (array $point): float {
    return max(0.0, (float)($point['liquid'] ?? 0.0));
}, $liquidityForecast)));
$liquidityForecastPaymentMax = max(array_merge([0.0], array_map(function (array $point): float {
    return (float)$point['payments'];
}, $liquidityForecast)));
$liquidityForecastWidth = 1920;
$liquidityForecastHeight = 500;
$liquidityForecastPaddingLeft = 72;
$liquidityForecastPaddingRight = 18;
$liquidityForecastPaddingTop = 34;
$liquidityForecastPaddingBottom = 64;
$liquidityForecastPlotHeight = $liquidityForecastHeight - $liquidityForecastPaddingTop - $liquidityForecastPaddingBottom;
$liquidityForecastBaseline = $liquidityForecastPaddingTop + ($liquidityForecastPlotHeight / 2);
$liquidityForecastPositiveHeight = $liquidityForecastBaseline - $liquidityForecastPaddingTop;
$liquidityForecastNegativeHeight = $liquidityForecastHeight - $liquidityForecastPaddingBottom - $liquidityForecastBaseline;
$liquidityForecastTickSize = 1000.0;
$liquidityForecastScaleMax = max($liquidityForecastTickSize, ceil(max($liquidityForecastMax, $liquidityForecastPaymentMax) / $liquidityForecastTickSize) * $liquidityForecastTickSize);
$liquidityForecastTicks = [];
for ($tick = $liquidityForecastScaleMax; $tick >= 0.0; $tick -= $liquidityForecastTickSize) {
    $liquidityForecastTicks[] = $tick;
}
$liquidityForecastPlotWidth = $liquidityForecastWidth - $liquidityForecastPaddingLeft - $liquidityForecastPaddingRight;
$liquidityForecastSlotWidth = $liquidityForecastPlotWidth / count($liquidityForecast);
$liquidityForecastBarWidth = min(22.0, ($liquidityForecastSlotWidth - 8) / 2);
$chartOptions = chartOptions($data['accounts'], $data['entries']);
$chartAccountId = (string)($_GET['chart_account'] ?? '');
if ($chartAccountId === '' || chartOptionById($chartOptions, $chartAccountId) === null) {
    $chartAccountId = $chartOptions !== [] ? (string)$chartOptions[0]['id'] : '';
}
$chartOption = $chartAccountId !== '' ? chartOptionById($chartOptions, $chartAccountId) : null;
$chartLabel = (string)($chartOption['name'] ?? 'Account');
$chartRangeOptions = [
    'all' => 'All time',
    '30' => 'Last 30 days',
    '90' => 'Last 90 days',
    '180' => 'Last 6 months',
    '365' => 'Last year',
];
$chartRange = (string)($_GET['chart_range'] ?? 'all');
if (!array_key_exists($chartRange, $chartRangeOptions)) {
    $chartRange = 'all';
}
$chartSeries = $chartOption !== null ? collectChartSeries($data['accounts'], $data['entries'], $chartOption, $chartRange, $now) : [];
$chartValues = array_map(function (array $point): float {
    return (float)$point['value'];
}, $chartSeries);
$chartMin = $chartValues === [] ? 0.0 : min(0.0, min($chartValues));
$chartMax = $chartValues === [] ? 0.0 : max(0.0, max($chartValues));
if ($chartValues !== [] && abs($chartMax - $chartMin) < 0.01) {
    if ($chartMax <= 0.0) {
        $chartMin -= 1.0;
    } else {
        $chartMax += 1.0;
    }
}
$chartWidth = 720;
$chartHeight = 280;
$chartPadding = 34;
$chartPolyline = buildChartPolyline($chartSeries, $chartMin, $chartMax, $chartWidth, $chartHeight, $chartPadding);
$chartFirstPoint = $chartSeries[0] ?? null;
$chartLastPoint = $chartSeries === [] ? null : $chartSeries[count($chartSeries) - 1];
$paymentPlanUpcomingAccounts = [];
$paymentPlanLaterAccounts = [];
$paymentPlanOtherDebtAccounts = [];
foreach ($debtAccounts as $account) {
    $nextPaymentDate = nextExpectedDebtPaymentDate($account, $now);
    $isPaidThisMonth = (string)($account['paid_payment_month'] ?? '') === $now->format('Y-m');
    $isUpcomingThisMonth = $nextPaymentDate !== null
        && $nextPaymentDate->format('Y-m') === $now->format('Y-m')
        && !$isPaidThisMonth;
    $daysUntilPayment = $nextPaymentDate === null ? PHP_INT_MAX : (int)$now->diff($nextPaymentDate)->format('%r%a');
    $account['_next_payment_date'] = $nextPaymentDate;
    $account['_days_until_payment'] = $daysUntilPayment;
    $account['_is_paid_this_month'] = $isPaidThisMonth;
    $account['_is_upcoming_this_month'] = $isUpcomingThisMonth;

    if (!(bool)($account['credit_card'] ?? true)) {
        $paymentPlanOtherDebtAccounts[] = $account;
    } elseif ($isUpcomingThisMonth) {
        $paymentPlanUpcomingAccounts[] = $account;
    } else {
        $paymentPlanLaterAccounts[] = $account;
    }
}
$sortPaymentPlanAccounts = function (array $left, array $right): int {
    $comparison = (int)($left['_days_until_payment'] ?? PHP_INT_MAX) <=> (int)($right['_days_until_payment'] ?? PHP_INT_MAX);
    if ($comparison === 0) {
        $comparison = strcasecmp((string)($left['name'] ?? ''), (string)($right['name'] ?? ''));
    }

    return $comparison;
};
usort($paymentPlanUpcomingAccounts, $sortPaymentPlanAccounts);
usort($paymentPlanLaterAccounts, $sortPaymentPlanAccounts);
usort($paymentPlanOtherDebtAccounts, $sortPaymentPlanAccounts);
$detailAccountId = (string)($_GET['account'] ?? '');
$detailCard = accountById(creditCardAccounts($data['accounts']), $detailAccountId);
$isCreditCardView = (string)($_GET['cards'] ?? '') === '1' || $detailAccountId !== '';
$isIncomeView = (string)($_GET['income'] ?? '') === '1' && !$isCreditCardView;
$isBillsView = (string)($_GET['bills'] ?? '') === '1' && !$isCreditCardView && !$isIncomeView;
$moneyPage = (string)($_GET['page'] ?? 'dashboard');
if ($moneyPage === 'settings') {
    $moneyPage = 'payment';
}
if (!in_array($moneyPage, ['dashboard', 'daily', 'payment', 'accounts'], true)) {
    $moneyPage = 'dashboard';
}
$isDailyCheckInView = $moneyPage === 'daily' && !$isCreditCardView && !$isIncomeView && !$isBillsView;
$isPaymentPlanView = $moneyPage === 'payment' && !$isCreditCardView && !$isIncomeView && !$isBillsView;
$isAccountsView = $moneyPage === 'accounts' && !$isCreditCardView && !$isIncomeView && !$isBillsView;
$isMoneyDashboardView = !$isCreditCardView && !$isIncomeView && !$isBillsView && !$isDailyCheckInView && !$isPaymentPlanView && !$isAccountsView;
$pageTitle = 'Money Kata';
$pageSubtitle = 'Watch the full money picture from your latest tally.';
if ($isCreditCardView) {
    $pageTitle = 'Credit Cards';
    $pageSubtitle = 'See selected card balances together across the interval you choose.';
} elseif ($isIncomeView) {
    $pageTitle = 'Income';
    $pageSubtitle = 'Manage recurring income used by your liquidity forecast.';
} elseif ($isBillsView) {
    $pageTitle = 'Monthly Bills';
    $pageSubtitle = 'Track the bills that draft from your accounts each month.';
} elseif ($isDailyCheckInView) {
    $pageTitle = 'Daily Check-In';
    $pageSubtitle = 'Log today\'s bank balances and debts in one focused pass.';
} elseif ($isPaymentPlanView) {
    $pageTitle = 'Payment Plan';
    $pageSubtitle = 'Plan card and debt payments against your liquidity runway.';
} elseif ($isAccountsView) {
    $pageTitle = 'Accounts';
    $pageSubtitle = 'Manage accounts, availability, and credit card status.';
}
$postedSelectedCreditCardIds = $_GET['cc'] ?? [];
if ($detailCard !== null) {
    $pageTitle = (string)$detailCard['name'];
    $pageSubtitle = 'Card details and balance history.';
    $postedSelectedCreditCardIds = [$detailAccountId];
}
if (!is_array($postedSelectedCreditCardIds)) {
    $postedSelectedCreditCardIds = [$postedSelectedCreditCardIds];
}
$selectedCreditCardIds = array_values(array_filter(array_map('strval', $postedSelectedCreditCardIds), function (string $accountId) use ($creditCardAccounts): bool {
    return accountById($creditCardAccounts, $accountId) !== null;
}));
if ($selectedCreditCardIds === [] && $detailAccountId !== '' && accountById($creditCardAccounts, $detailAccountId) !== null) {
    $selectedCreditCardIds = [$detailAccountId];
}
$isEmptyCreditCardSelection = (string)($_GET['none'] ?? '') === '1';
if ($selectedCreditCardIds === [] && !$isEmptyCreditCardSelection) {
    $selectedCreditCardIds = array_map(function (array $account): string {
        return (string)$account['id'];
    }, $creditCardAccounts);
}
$selectedCreditCardAccounts = array_values(array_filter($creditCardAccounts, function (array $account) use ($selectedCreditCardIds): bool {
    return in_array((string)$account['id'], $selectedCreditCardIds, true);
}));
if ($detailCard !== null) {
    $selectedCreditCardIds = [$detailAccountId];
    $selectedCreditCardAccounts = [$detailCard];
    $isEmptyCreditCardSelection = false;
}
$creditCardRangeOptions = [
    '30' => 'Last 30 days',
    '90' => 'Last 90 days',
    '180' => 'Last 6 months',
    '365' => 'Last year',
];
$creditCardRange = (string)($_GET['cc_range'] ?? '30');
if (!array_key_exists($creditCardRange, $creditCardRangeOptions)) {
    $creditCardRange = '30';
}
$creditCardRangeDays = (int)$creditCardRange;
$creditCardRangeLabel = (string)$creditCardRangeOptions[$creditCardRange];
$detailSeries = $isCreditCardView ? collectCombinedAccountDailyBalances($data['entries'], $selectedCreditCardAccounts, $now, $creditCardRangeDays) : [];
$detailStats = $isCreditCardView ? accountSpendStats($detailSeries) : [];
$detailValues = array_values(array_filter(array_map(function (array $point) {
    return $point['value'];
}, $detailSeries), function ($value): bool {
    return $value !== null;
}));
$detailMax = $detailValues === [] ? 1.0 : max($detailValues);
if ($detailMax < 1.0) {
    $detailMax = 1.0;
}
$detailChartWidth = 900;
$detailChartHeight = 320;
$detailChartPaddingTop = 28;
$detailChartPaddingRight = 18;
$detailChartPaddingBottom = 54;
$detailChartPaddingLeft = 76;
$detailPlotWidth = $detailChartWidth - $detailChartPaddingLeft - $detailChartPaddingRight;
$detailPlotHeight = $detailChartHeight - $detailChartPaddingTop - $detailChartPaddingBottom;
$detailSlotWidth = count($detailSeries) > 0 ? ($detailPlotWidth / count($detailSeries)) : 0.0;
$detailBarGap = min(4.0, max(1.0, $detailSlotWidth * 0.2));
$detailBarWidth = $detailSlotWidth > 0.0 ? max(1.5, $detailSlotWidth - $detailBarGap) : 0.0;
$detailDateLabelStep = max(1, (int)ceil(count($detailSeries) / 8));
$selectedCreditCardCount = count($selectedCreditCardAccounts);
$accountPaymentStats = [];
foreach ($creditCardAccounts as $creditCardAccount) {
    $accountId = (string)$creditCardAccount['id'];
    $accountPaymentStats[$accountId] = accountSpendStats(
        collectAccountDailyBalances($data['entries'], $accountId, $now, $creditCardRangeDays)
    );
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Money Kata</title>
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

        main.is-dashboard {
            width: min(1440px, calc(100% - 32px));
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
            width: 100%;
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

        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
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

        .money-subnav {
            display: flex;
            width: 100%;
            justify-content: flex-end;
            flex-wrap: wrap;
            gap: 8px;
            margin: -6px 0 0;
        }

        .money-subnav a {
            display: inline-flex;
            min-height: 36px;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--line);
            border-radius: 999px;
            padding: 0 13px;
            background: var(--panel);
            color: var(--muted);
            font-size: 0.88rem;
            font-weight: 800;
            text-decoration: none;
        }

        .money-subnav a:hover,
        .money-subnav a:focus-visible {
            background: var(--hover);
            color: var(--accent-dark);
        }

        .money-subnav a.is-selected {
            border-color: var(--accent);
            background: var(--accent);
            color: #ffffff;
        }

        .money-subnav a.is-selected:hover,
        .money-subnav a.is-selected:focus-visible {
            background: var(--accent-dark);
            color: #ffffff;
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

        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 22px;
            align-items: start;
        }

        .dashboard-grid > .panel,
        .dashboard-grid > .summary-panel {
            margin-bottom: 0;
        }

        .dashboard-grid > .liquidity-hero {
            grid-column: 1 / -1;
            order: -1;
            padding: 22px;
        }

        .liquidity-hero .stage-title {
            font-size: 1.08rem;
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

        .metric.is-net-positive strong {
            color: var(--accent-dark);
        }

        .metric.is-net-negative strong {
            color: var(--danger);
        }

        .metric.is-change-positive strong {
            color: var(--accent-dark);
        }

        .metric.is-change-negative strong {
            color: var(--danger);
        }

        .form-grid {
            display: grid;
            gap: 12px;
        }

        .chart-controls {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 180px auto;
            gap: 12px;
            align-items: end;
            margin-bottom: 16px;
        }

        .chart-toggle {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 16px;
            padding: 10px 12px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: var(--empty-bg);
        }

        .chart-toggle label {
            position: static;
            display: inline-flex;
            width: auto;
            height: auto;
            align-items: center;
            gap: 9px;
            overflow: visible;
            clip: auto;
            color: var(--ink);
            font-weight: 800;
        }

        .chart-toggle input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: var(--accent);
        }

        .chart-toggle button {
            min-height: 38px;
        }

        .chart-control {
            display: grid;
            gap: 8px;
        }

        .chart-control label {
            position: static;
            width: auto;
            height: auto;
            overflow: visible;
            clip: auto;
            color: var(--muted);
            font-size: 0.88rem;
            font-weight: 800;
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
            stroke: var(--accent);
            stroke-width: 4;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .chart-point {
            fill: var(--accent-dark);
        }

        .chart-caption {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .pie-layout {
            display: grid;
            grid-template-columns: minmax(180px, 240px) minmax(0, 1fr);
            gap: 16px;
            align-items: center;
            padding: 14px;
        }

        .pie-chart {
            display: block;
            width: 100%;
            height: auto;
            max-width: 240px;
            justify-self: center;
        }

        .pie-slice {
            stroke: var(--panel);
            stroke-width: 1.5;
        }

        .pie-legend {
            display: grid;
            gap: 0;
            margin: 0;
            padding: 0;
            list-style: none;
        }

        .pie-legend-item {
            display: grid;
            grid-template-columns: 14px minmax(0, 1fr) auto;
            gap: 12px;
            align-items: center;
            min-height: 38px;
            border-bottom: 1px solid var(--line);
            padding: 8px 0;
            color: var(--text);
            font-size: 0.92rem;
            font-weight: 800;
        }

        .pie-legend-item:first-child {
            border-top: 1px solid var(--line);
        }

        .pie-legend-swatch {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: var(--slice-color);
        }

        .pie-legend-name {
            overflow-wrap: anywhere;
        }

        .pie-legend-type {
            color: var(--muted);
            font-size: 0.78rem;
            font-weight: 800;
            text-transform: uppercase;
        }

        .pie-legend-value {
            color: var(--muted);
            text-align: right;
            white-space: nowrap;
        }

        .credit-card-bars {
            display: grid;
            gap: 12px;
            padding: 18px;
        }

        .credit-card-bar-row {
            display: grid;
            grid-template-columns: minmax(130px, 190px) minmax(0, 1fr) auto;
            gap: 10px;
            align-items: center;
        }

        .credit-card-bar-name {
            color: var(--ink);
            font-size: 0.9rem;
            font-weight: 800;
            overflow-wrap: anywhere;
        }

        .credit-card-bar-track {
            display: block;
            width: 100%;
            height: 28px;
            overflow: hidden;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: var(--empty-bg);
        }

        .credit-card-bar-fill {
            display: block;
            position: relative;
            height: 100%;
            min-width: 2px;
            overflow: hidden;
            border-radius: 7px;
            background: var(--segment-color);
        }

        .credit-card-bar-new {
            position: absolute;
            top: 0;
            right: 0;
            bottom: 0;
            min-width: 2px;
            background: rgba(255, 255, 255, 0.5);
        }

        .credit-card-bar-value {
            color: var(--muted);
            font-size: 0.88rem;
            font-weight: 800;
            white-space: nowrap;
        }

        .stat-pill {
            display: inline-flex;
            align-items: center;
            min-height: 32px;
            border: 1px solid var(--line);
            border-radius: 999px;
            padding: 0 12px;
            background: var(--empty-bg);
            color: var(--muted);
            font-size: 0.86rem;
            font-weight: 800;
        }

        .detail-nav {
            margin: 0 0 18px;
        }

        .toggle-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 16px;
        }

        .pill-toggle {
            display: inline-flex;
            min-height: 36px;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--line);
            border-radius: 999px;
            padding: 0 13px;
            background: var(--panel);
            color: var(--muted);
            font-size: 0.88rem;
            font-weight: 800;
            text-decoration: none;
        }

        .pill-toggle.is-selected {
            border-color: var(--accent);
            background: var(--accent);
            color: #ffffff;
        }

        .pill-toggle:hover,
        .pill-toggle:focus-visible {
            background: var(--hover);
            color: var(--accent-dark);
            text-decoration: none;
        }

        .pill-toggle.is-selected:hover,
        .pill-toggle.is-selected:focus-visible {
            background: var(--accent-dark);
            color: #ffffff;
        }

        .bar-chart {
            display: block;
            width: 100%;
            height: auto;
        }

        .bar-chart-bar {
            fill: var(--accent);
        }

        .bar-chart-missing {
            fill: var(--line);
            opacity: 0.58;
        }

        .bar-chart-tick {
            stroke: var(--line);
            stroke-width: 1;
        }

        .bar-chart-axis {
            stroke: var(--muted);
            stroke-width: 1.5;
        }

        .bar-chart-label {
            fill: var(--muted);
            font-size: 0.76rem;
            font-weight: 800;
        }

        .liquidity-bar {
            fill: var(--accent);
        }

        .payment-bar {
            fill: var(--danger);
        }

        .forecast-today {
            stroke: var(--accent-dark);
            stroke-width: 2;
            stroke-dasharray: 4 4;
        }

        .link-button {
            display: inline-flex;
            min-height: 34px;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--line);
            border-radius: 6px;
            padding: 0 12px;
            background: transparent;
            color: var(--accent-dark);
            font-size: 0.88rem;
            font-weight: 800;
            text-decoration: none;
        }

        .link-button:hover,
        .link-button:focus-visible {
            background: var(--hover);
            text-decoration: none;
        }

        .entry {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 12px;
            align-items: end;
        }

        .account-inputs {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .account-input {
            display: grid;
            gap: 8px;
        }

        .account-input label,
        .visible-label {
            position: static;
            width: auto;
            height: auto;
            overflow: visible;
            clip: auto;
            color: var(--muted);
            font-size: 0.88rem;
            font-weight: 800;
        }

        .payment-plan-list {
            display: grid;
            gap: 18px;
        }

        .payment-plan-group {
            display: grid;
            gap: 12px;
        }

        .payment-plan-group h3 {
            margin: 0;
            color: var(--muted);
            font-size: 0.86rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        .payment-plan {
            display: grid;
            grid-template-columns: minmax(150px, 1.1fr) repeat(auto-fit, minmax(132px, 1fr));
            gap: 12px;
            align-items: end;
            padding: 14px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: var(--empty-bg);
        }

        .payment-plan > * {
            min-width: 0;
        }

        .payment-plan > button {
            justify-self: end;
            min-width: 132px;
        }

        .payment-account strong,
        .payment-account span {
            display: block;
        }

        .payment-account span {
            margin-top: 5px;
            color: var(--muted);
            font-size: 0.88rem;
        }

        .payment-target {
            display: grid;
            gap: 6px;
            min-height: 48px;
            align-content: center;
        }

        .payment-target span {
            color: var(--muted);
            font-size: 0.82rem;
            font-weight: 800;
        }

        .payment-paid-toggle {
            position: static;
            width: auto;
            height: auto;
            overflow: visible;
            clip: auto;
            display: flex;
            min-height: 48px;
            align-items: center;
            gap: 8px;
            color: var(--muted);
            font-size: 0.86rem;
            font-weight: 800;
        }

        .payment-paid-toggle input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: var(--accent);
        }

        .payment-next {
            color: var(--muted);
            font-size: 0.82rem;
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

        .secondary-button {
            border: 1px solid var(--line);
            background: transparent;
            color: var(--accent-dark);
        }

        .secondary-button:hover,
        .secondary-button:focus-visible {
            background: var(--hover);
        }

        .danger-button {
            border: 1px solid var(--line);
            background: transparent;
            color: var(--danger);
        }

        .danger-button:hover,
        .danger-button:focus-visible {
            background: var(--danger-soft);
        }

        .account-groups {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
            margin-top: 18px;
        }

        .account-group {
            padding: 14px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: var(--empty-bg);
        }

        .dropped-accounts {
            margin-top: 14px;
        }

        .account-group h3 {
            margin: 0 0 10px;
            font-size: 0.92rem;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        .account-list {
            display: grid;
            gap: 8px;
            margin: 0;
            padding: 0;
            list-style: none;
        }

        .account-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 10px;
            align-items: center;
            padding: 10px 12px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: var(--panel);
        }

        .account-row span {
            color: var(--muted);
            font-size: 0.88rem;
        }

        .account-row form {
            margin: 0;
        }

        .account-actions {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            flex-wrap: wrap;
        }

        .bill-card-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(132px, 1fr));
            gap: 8px;
            align-items: center;
        }

        .bill-card-form select,
        .bill-card-form input[type="text"] {
            min-height: 34px;
            padding: 0 10px;
            font-size: 0.88rem;
        }

        .table-wrap {
            width: 100%;
            overflow-x: auto;
        }

        .bill-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 820px;
        }

        .bill-table th,
        .bill-table td {
            border-bottom: 1px solid var(--line);
            padding: 10px 8px;
            text-align: left;
            vertical-align: middle;
        }

        .bill-table th {
            color: var(--muted);
            font-size: 0.78rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        .bill-table tbody tr:hover {
            background: var(--empty-bg);
        }

        .bill-table .amount-cell,
        .bill-table .day-cell {
            white-space: nowrap;
        }

        .bill-table .actions-cell {
            width: 1%;
            white-space: nowrap;
        }

        .bill-table form {
            margin: 0;
        }

        .bill-table .bill-card-form {
            grid-template-columns: minmax(132px, 170px) minmax(150px, 210px) auto;
        }

        .bill-table button {
            min-height: 34px;
            padding: 0 12px;
            font-size: 0.88rem;
        }

        .sort-link {
            color: var(--accent-dark);
            text-decoration: none;
        }

        .sort-link:hover,
        .sort-link:focus-visible {
            text-decoration: underline;
        }

        .account-row button {
            min-height: 34px;
            padding: 0 12px;
            font-size: 0.88rem;
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

        @media (max-width: 1100px) {
            main.is-dashboard {
                width: min(980px, calc(100% - 32px));
            }

            .dashboard-grid {
                grid-template-columns: 1fr;
            }
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

            .money-subnav {
                display: flex;
                flex-direction: column;
                align-items: stretch;
                gap: 8px;
                padding: 0;
                margin: 0;
                border: 0;
                background: transparent;
            }

            .money-subnav a {
                border-radius: 6px;
            }

            .summary-grid,
            .entry,
            .chart-controls,
            .payment-plan,
            .pie-layout,
            .bill-card-form,
            .account-inputs,
            .account-groups {
                grid-template-columns: 1fr;
            }

            button {
                width: 100%;
                padding-right: 6px;
                padding-left: 6px;
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
            .liquidity-hero,
            .bill-card-form,
            .account-row,
            .payment-plan,
            .payment-target,
            .empty,
            .lock-screen,
            .lock-card {
                padding: 6px;
            }

            .liquidity-hero .chart-frame {
                display: block;
                max-width: 100%;
                overflow-x: scroll;
                overflow-y: hidden;
                -webkit-overflow-scrolling: touch;
                overscroll-behavior-inline: contain;
                touch-action: pan-x pan-y;
            }

            .liquidity-hero .chart-frame .bar-chart {
                width: 1920px !important;
                min-width: 1920px;
                max-width: none !important;
                flex: 0 0 1920px;
            }

            button.theme-toggle {
                width: auto;
            }
        }
    </style>
</head>
<body class="<?= $isNightMode ? 'night-mode' : '' ?>">
    <main class="app-shell<?= $isMoneyDashboardView ? ' is-dashboard' : '' ?><?= $isAuthenticated ? '' : ' is-blurred' ?>" data-app-shell>
        <?= kataRenderGlobalFocus(kataLoadMainFocus()) ?>

        <nav class="primary-nav" aria-label="Primary">
            <button class="primary-menu-toggle" type="button" aria-expanded="false" aria-controls="primary-menu" data-primary-menu-toggle>
                <span aria-hidden="true">&#9776;</span>
                <span data-primary-menu-label>Menu</span>
            </button>
            <div class="primary-menu-items" id="primary-menu" data-primary-menu>
                <a href="index.php">Daily Kata</a>
                <a href="social.php">Social Kata</a>
                <a href="three-month-goals.php">3 Month Goals</a>
                <a href="finance.php">Money Kata</a>
                <button class="theme-toggle" type="button" data-theme-toggle aria-label="Toggle theme">Night</button>
                <div class="money-subnav" id="money-subnav" aria-label="Money Kata sections" data-money-subnav>
                    <a class="<?= !$isCreditCardView && !$isIncomeView && !$isBillsView && $moneyPage === 'dashboard' ? 'is-selected' : '' ?>" href="finance.php">Dashboard</a>
                    <a class="<?= $isDailyCheckInView ? 'is-selected' : '' ?>" href="finance.php?page=daily">Daily Check-In</a>
                    <a class="<?= $isPaymentPlanView ? 'is-selected' : '' ?>" href="finance.php?page=payment">Payment Plan</a>
                    <a class="<?= $isAccountsView ? 'is-selected' : '' ?>" href="finance.php?page=accounts">Accounts</a>
                    <a class="<?= $isBillsView ? 'is-selected' : '' ?>" href="finance.php?bills=1">Bills</a>
                    <a class="<?= $isIncomeView ? 'is-selected' : '' ?>" href="finance.php?income=1">Income</a>
                    <a class="<?= $isCreditCardView ? 'is-selected' : '' ?>" href="finance.php?cards=1">Credit Cards</a>
                </div>
            </div>
        </nav>

        <header class="masthead">
            <h1><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
            <p class="subtitle"><?= htmlspecialchars($pageSubtitle, ENT_QUOTES, 'UTF-8') ?></p>
        </header>

        <?php if ($isCreditCardView): ?>
            <p class="detail-nav"><a href="finance.php">Back to Money Kata</a></p>

            <?php if ($detailAccountId !== ''): ?>
                <?php require __DIR__ . '/app/card-details.php'; ?>
            <?php endif; ?>

            <?php if (($creditCardAccounts === [] && $detailCard === null) || ($detailAccountId !== '' && $detailCard === null)): ?>
                <section class="panel" aria-labelledby="account-summary-title">
                    <h2 class="stage-title" id="account-summary-title">Credit Cards</h2>
                    <p class="empty">No active credit card accounts are configured yet.</p>
                </section>
            <?php else: ?>
            <?php if ($detailCard === null): ?>
            <?php require __DIR__ . '/app/cards-interest-summary.php'; ?>
            <section class="panel" aria-labelledby="card-selector-title">
                <h2 class="stage-title" id="card-selector-title">Cards Included</h2>
                <div class="toggle-pills" aria-label="Credit card account toggles">
                    <?php foreach ($creditCardAccounts as $account): ?>
                        <?php
                            $accountId = (string)$account['id'];
                            $isSelected = in_array($accountId, $selectedCreditCardIds, true);
                        ?>
                        <a class="pill-toggle<?= $isSelected ? ' is-selected' : '' ?>" href="<?= htmlspecialchars(buildCreditCardToggleUrl($selectedCreditCardIds, $accountId, $creditCardRange), ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars((string)$account['name'], ENT_QUOTES, 'UTF-8') ?>
                        </a>
                    <?php endforeach; ?>
                </div>
                <div class="chart-caption" aria-label="Selected card summary">
                    <span class="stat-pill"><?= $selectedCreditCardCount ?> selected</span>
                    <span class="stat-pill">Tap a pill to include or hide it</span>
                </div>
                <div class="chart-caption">
                    <?php foreach ($creditCardAccounts as $card): ?>
                        <a class="link-button" href="finance.php?account=<?= rawurlencode((string)$card['id']) ?>"><?= htmlspecialchars((string)$card['name'], ENT_QUOTES, 'UTF-8') ?> details</a>
                    <?php endforeach; ?>
                </div>
            </section>

            <?php endif; ?>
            <section class="summary-panel" aria-labelledby="account-summary-title">
                <h2 class="stage-title" id="account-summary-title"><?= $detailCard !== null ? 'Card balance history' : 'Selected Card Stats' ?></h2>
                <div class="summary-grid">
                    <div class="metric">
                        <span>Total balance</span>
                        <strong><?= $detailStats['current_balance'] !== null ? htmlspecialchars(formatMoney((float)$detailStats['current_balance']), ENT_QUOTES, 'UTF-8') : 'No tally' ?></strong>
                    </div>
                    <div class="metric">
                        <span>Average daily spend</span>
                        <strong><?= htmlspecialchars(formatMoney((float)$detailStats['average_daily_spend']), ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>
                    <div class="metric">
                        <span><?= htmlspecialchars($creditCardRangeLabel, ENT_QUOTES, 'UTF-8') ?> spend</span>
                        <strong><?= htmlspecialchars(formatMoney((float)$detailStats['spend_total']), ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>
                    <div class="metric">
                        <span>Average daily payments</span>
                        <strong><?= htmlspecialchars(formatMoney((float)$detailStats['average_daily_payments']), ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>
                    <div class="metric">
                        <span><?= htmlspecialchars($creditCardRangeLabel, ENT_QUOTES, 'UTF-8') ?> payments</span>
                        <strong><?= htmlspecialchars(formatMoney((float)$detailStats['payment_total']), ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>
                    <div class="metric <?= (float)$detailStats['payment_deficit'] <= 0 ? 'is-net-positive' : 'is-net-negative' ?>">
                        <span>Payment deficit</span>
                        <strong><?= htmlspecialchars(formatSignedMoney((float)$detailStats['payment_deficit']), ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>
                    <div class="metric">
                        <span>High balance</span>
                        <strong><?= $detailStats['high_balance'] !== null ? htmlspecialchars(formatMoney((float)$detailStats['high_balance']), ENT_QUOTES, 'UTF-8') : 'No tally' ?></strong>
                    </div>
                    <div class="metric">
                        <span>Low balance</span>
                        <strong><?= $detailStats['low_balance'] !== null ? htmlspecialchars(formatMoney((float)$detailStats['low_balance']), ENT_QUOTES, 'UTF-8') : 'No tally' ?></strong>
                    </div>
                    <div class="metric">
                        <span>Balance days</span>
                        <strong><?= (int)$detailStats['balance_days'] ?>/<?= $creditCardRangeDays ?></strong>
                    </div>
                </div>
            </section>

            <section class="panel" aria-labelledby="account-balance-title">
                <h2 class="stage-title" id="account-balance-title"><?= htmlspecialchars($creditCardRangeLabel, ENT_QUOTES, 'UTF-8') ?></h2>
                <form class="chart-controls" method="get" action="">
                    <input type="hidden" name="cards" value="1">
                    <?php if ($detailCard !== null): ?><input type="hidden" name="account" value="<?= htmlspecialchars($detailAccountId, ENT_QUOTES, 'UTF-8') ?>"><?php endif; ?>
                    <?php foreach ($selectedCreditCardIds as $selectedCreditCardId): ?>
                        <input type="hidden" name="cc[]" value="<?= htmlspecialchars((string)$selectedCreditCardId, ENT_QUOTES, 'UTF-8') ?>">
                    <?php endforeach; ?>
                    <?php if ($isEmptyCreditCardSelection): ?>
                        <input type="hidden" name="none" value="1">
                    <?php endif; ?>
                    <div class="chart-control">
                        <label for="cc-range">Interval</label>
                        <select id="cc-range" name="cc_range">
                            <?php foreach ($creditCardRangeOptions as $rangeValue => $rangeLabel): ?>
                                <?php $rangeValue = (string)$rangeValue; ?>
                                <option value="<?= htmlspecialchars($rangeValue, ENT_QUOTES, 'UTF-8') ?>" <?= $creditCardRange === $rangeValue ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($rangeLabel, ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button class="secondary-button" type="submit">Update Chart</button>
                </form>
                <?php if ($selectedCreditCardCount === 0): ?>
                    <p class="empty">Select at least one credit card to chart balances.</p>
                <?php elseif ($detailStats['balance_days'] === 0): ?>
                    <p class="empty">No saved balance is available for the selected credit cards yet.</p>
                <?php else: ?>
                    <div class="chart-wrap">
                        <div class="chart-frame">
                            <svg class="bar-chart" viewBox="0 0 <?= $detailChartWidth ?> <?= $detailChartHeight ?>" role="img" aria-labelledby="account-bar-title account-bar-desc">
                                <title id="account-bar-title">Selected credit cards <?= htmlspecialchars(strtolower($creditCardRangeLabel), ENT_QUOTES, 'UTF-8') ?> balance chart</title>
                                <desc id="account-bar-desc">Daily balance bars for <?= htmlspecialchars(strtolower($creditCardRangeLabel), ENT_QUOTES, 'UTF-8') ?>. Missing tallies reuse the most recent saved balance.</desc>
                                <line class="bar-chart-tick" x1="<?= $detailChartPaddingLeft ?>" y1="<?= $detailChartPaddingTop ?>" x2="<?= $detailChartWidth - $detailChartPaddingRight ?>" y2="<?= $detailChartPaddingTop ?>"></line>
                                <line class="bar-chart-tick" x1="<?= $detailChartPaddingLeft ?>" y1="<?= $detailChartPaddingTop + ($detailPlotHeight / 2) ?>" x2="<?= $detailChartWidth - $detailChartPaddingRight ?>" y2="<?= $detailChartPaddingTop + ($detailPlotHeight / 2) ?>"></line>
                                <line class="bar-chart-axis" x1="<?= $detailChartPaddingLeft ?>" y1="<?= $detailChartPaddingTop + $detailPlotHeight ?>" x2="<?= $detailChartWidth - $detailChartPaddingRight ?>" y2="<?= $detailChartPaddingTop + $detailPlotHeight ?>"></line>
                                <text class="bar-chart-label" x="10" y="<?= $detailChartPaddingTop + 4 ?>"><?= htmlspecialchars(formatMoney((float)$detailMax), ENT_QUOTES, 'UTF-8') ?></text>
                                <text class="bar-chart-label" x="10" y="<?= $detailChartPaddingTop + ($detailPlotHeight / 2) + 4 ?>"><?= htmlspecialchars(formatMoney((float)($detailMax / 2)), ENT_QUOTES, 'UTF-8') ?></text>
                                <text class="bar-chart-label" x="10" y="<?= $detailChartPaddingTop + $detailPlotHeight + 4 ?>">$0.00</text>
                                <?php foreach ($detailSeries as $index => $point): ?>
                                    <?php
                                        $x = $detailChartPaddingLeft + ($index * ($detailPlotWidth / count($detailSeries))) + ($detailBarGap / 2);
                                        $tickX = $detailChartPaddingLeft + ($index * ($detailPlotWidth / (count($detailSeries) - 1)));
                                        $value = $point['value'];
                                        $barHeight = $value === null ? 0 : max(2, ((float)$value / $detailMax) * $detailPlotHeight);
                                        $barY = $detailChartPaddingTop + $detailPlotHeight - $barHeight;
                                        $parsedDate = new DateTimeImmutable((string)$point['date']);
                                    ?>
                                    <line class="bar-chart-tick" x1="<?= number_format($tickX, 2, '.', '') ?>" y1="<?= $detailChartPaddingTop + $detailPlotHeight ?>" x2="<?= number_format($tickX, 2, '.', '') ?>" y2="<?= $detailChartPaddingTop + $detailPlotHeight + 6 ?>"></line>
                                    <?php if ($value === null): ?>
                                        <rect class="bar-chart-missing" x="<?= number_format($x, 2, '.', '') ?>" y="<?= $detailChartPaddingTop + $detailPlotHeight - 2 ?>" width="<?= number_format($detailBarWidth, 2, '.', '') ?>" height="2"></rect>
                                    <?php else: ?>
                                        <rect class="bar-chart-bar" x="<?= number_format($x, 2, '.', '') ?>" y="<?= number_format($barY, 2, '.', '') ?>" width="<?= number_format($detailBarWidth, 2, '.', '') ?>" height="<?= number_format($barHeight, 2, '.', '') ?>">
                                            <title><?= htmlspecialchars($parsedDate->format('M j') . ': ' . formatMoney((float)$value), ENT_QUOTES, 'UTF-8') ?></title>
                                        </rect>
                                    <?php endif; ?>
                                    <?php if ($index % $detailDateLabelStep === 0 || $index === count($detailSeries) - 1): ?>
                                        <text class="bar-chart-label" x="<?= number_format($tickX, 2, '.', '') ?>" y="<?= $detailChartHeight - 18 ?>" text-anchor="middle"><?= htmlspecialchars($parsedDate->format('M j'), ENT_QUOTES, 'UTF-8') ?></text>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </svg>
                        </div>
                        <div class="chart-caption" aria-label="Account detail chart summary">
                            <span class="stat-pill">Average daily spend: <?= htmlspecialchars(formatMoney((float)$detailStats['average_daily_spend']), ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="stat-pill">Average daily payments: <?= htmlspecialchars(formatMoney((float)$detailStats['average_daily_payments']), ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="stat-pill">Deficit: <?= htmlspecialchars(formatSignedMoney((float)$detailStats['payment_deficit']), ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="stat-pill"><?= (int)$detailStats['recorded_days'] ?> recorded day<?= (int)$detailStats['recorded_days'] === 1 ? '' : 's' ?></span>
                            <span class="stat-pill">Spend only counts balance increases</span>
                            <span class="stat-pill">Payments count balance decreases</span>
                        </div>
                    </div>
                <?php endif; ?>
            </section>
            <?php endif; ?>
        <?php elseif ($isIncomeView): ?>
            <p class="detail-nav"><a href="finance.php">Back to Money Kata</a></p>

            <section class="panel" aria-labelledby="add-income-title">
                <h2 class="stage-title" id="add-income-title">Add Income</h2>
                <form class="entry" method="post" action="">
                    <input type="hidden" name="action" value="save_income">
                    <div class="account-input">
                        <label for="income-name">Income name</label>
                        <input id="income-name" name="income_name" type="text" maxlength="80" placeholder="Monthly income" required>
                    </div>
                    <div class="account-input">
                        <label for="income-amount">Amount</label>
                        <input id="income-amount" name="income_amount" type="text" inputmode="decimal" placeholder="$0.00" required>
                    </div>
                    <div class="account-input">
                        <label for="income-cadence">Schedule</label>
                        <select id="income-cadence" name="income_cadence" required>
                            <option value="biweekly">Every two weeks</option>
                            <option value="monthly">Monthly</option>
                        </select>
                    </div>
                    <div class="account-input">
                        <label for="income-anchor-date">Biweekly payday</label>
                        <input id="income-anchor-date" name="income_anchor_date" type="date">
                    </div>
                    <div class="account-input">
                        <label for="income-day">Monthly day</label>
                        <select id="income-day" name="income_day">
                            <option value="">Choose day</option>
                            <?php for ($day = 1; $day <= 31; $day++): ?>
                                <option value="<?= $day ?>"><?= $day ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <button type="submit">Add Income</button>
                </form>
                <?php if ($error !== ''): ?>
                    <p class="notice"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>
            </section>

            <section class="panel" aria-labelledby="income-sources-title">
                <h2 class="stage-title" id="income-sources-title">Income Sources</h2>
                <?php if ($data['incomes'] === []): ?>
                    <p class="empty">No recurring income yet.</p>
                <?php else: ?>
                    <div class="payment-plan-list">
                        <?php foreach ($data['incomes'] as $income): ?>
                            <?php $incomeId = (string)$income['id']; ?>
                            <div class="account-group">
                                <form class="entry" method="post" action="">
                                    <input type="hidden" name="action" value="save_income">
                                    <input type="hidden" name="income_id" value="<?= htmlspecialchars($incomeId, ENT_QUOTES, 'UTF-8') ?>">
                                    <div class="account-input">
                                        <label for="income-name-<?= htmlspecialchars($incomeId, ENT_QUOTES, 'UTF-8') ?>">Income name</label>
                                        <input id="income-name-<?= htmlspecialchars($incomeId, ENT_QUOTES, 'UTF-8') ?>" name="income_name" type="text" maxlength="80" value="<?= htmlspecialchars((string)$income['name'], ENT_QUOTES, 'UTF-8') ?>" required>
                                    </div>
                                    <div class="account-input">
                                        <label for="income-amount-<?= htmlspecialchars($incomeId, ENT_QUOTES, 'UTF-8') ?>">Amount</label>
                                        <input id="income-amount-<?= htmlspecialchars($incomeId, ENT_QUOTES, 'UTF-8') ?>" name="income_amount" type="text" inputmode="decimal" value="<?= htmlspecialchars(number_format((float)$income['amount'], 2), ENT_QUOTES, 'UTF-8') ?>" required>
                                    </div>
                                    <div class="account-input">
                                        <label for="income-cadence-<?= htmlspecialchars($incomeId, ENT_QUOTES, 'UTF-8') ?>">Schedule</label>
                                        <select id="income-cadence-<?= htmlspecialchars($incomeId, ENT_QUOTES, 'UTF-8') ?>" name="income_cadence" required>
                                            <option value="biweekly" <?= $income['cadence'] === 'biweekly' ? 'selected' : '' ?>>Every two weeks</option>
                                            <option value="monthly" <?= $income['cadence'] === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                                        </select>
                                    </div>
                                    <div class="account-input">
                                        <label for="income-anchor-<?= htmlspecialchars($incomeId, ENT_QUOTES, 'UTF-8') ?>">Biweekly payday</label>
                                        <input id="income-anchor-<?= htmlspecialchars($incomeId, ENT_QUOTES, 'UTF-8') ?>" name="income_anchor_date" type="date" value="<?= htmlspecialchars((string)$income['anchor_date'], ENT_QUOTES, 'UTF-8') ?>">
                                    </div>
                                    <div class="account-input">
                                        <label for="income-day-<?= htmlspecialchars($incomeId, ENT_QUOTES, 'UTF-8') ?>">Monthly day</label>
                                        <select id="income-day-<?= htmlspecialchars($incomeId, ENT_QUOTES, 'UTF-8') ?>" name="income_day">
                                            <option value="">Choose day</option>
                                            <?php for ($day = 1; $day <= 31; $day++): ?>
                                                <option value="<?= $day ?>" <?= (int)$income['day'] === $day ? 'selected' : '' ?>><?= $day ?></option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                    <button type="submit">Save</button>
                                </form>
                                <form method="post" action="" style="margin-top: 10px;">
                                    <input type="hidden" name="action" value="delete_income">
                                    <input type="hidden" name="income_id" value="<?= htmlspecialchars($incomeId, ENT_QUOTES, 'UTF-8') ?>">
                                    <button class="danger-button" type="submit">Remove</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php elseif ($isBillsView): ?>
            <p class="detail-nav"><a href="finance.php">Back to Money Kata</a></p>

            <section class="summary-panel" aria-labelledby="bill-stats-title">
                <h2 class="stage-title" id="bill-stats-title">Bill Stats</h2>
                <div class="summary-grid">
                    <div class="metric">
                        <span>Monthly bill run rate</span>
                        <strong><?= htmlspecialchars(formatMoney($monthlyBillTotal), ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>
                    <div class="metric">
                        <span>Bill count</span>
                        <strong><?= count($data['bills']) ?></strong>
                    </div>
                    <div class="metric">
                        <span>Interest payments</span>
                        <strong><?= htmlspecialchars(formatMoney($interestPaymentTotal), ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>
                    <div class="metric">
                        <span>Assigned to cards</span>
                        <strong><?= htmlspecialchars(formatMoney($assignedMonthlyBillTotal), ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>
                    <div class="metric">
                        <span>Unassigned</span>
                        <strong><?= htmlspecialchars(formatMoney($unassignedMonthlyBillTotal), ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>
                </div>
            </section>

            <section class="panel" aria-labelledby="add-bill-title">
                <h2 class="stage-title" id="add-bill-title">Add Bill</h2>
                <form class="entry" method="post" action="">
                    <input type="hidden" name="action" value="add_bill">
                    <div class="account-input">
                        <label for="bill-name">Bill name</label>
                        <input id="bill-name" name="bill_name" type="text" maxlength="80" placeholder="Internet" required>
                    </div>
                    <div class="account-input">
                        <label for="bill-cadence">Schedule</label>
                        <select id="bill-cadence" name="bill_cadence" required>
                            <option value="monthly">Monthly</option>
                            <option value="weekly">Weekly</option>
                        </select>
                    </div>
                    <div class="account-input">
                        <label for="bill-day">Monthly day</label>
                        <select id="bill-day" name="bill_day">
                            <option value="">Choose day</option>
                            <?php for ($day = 1; $day <= 31; $day++): ?>
                                <option value="<?= $day ?>"><?= $day ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="account-input">
                        <label for="bill-weekday">Weekly day</label>
                        <select id="bill-weekday" name="bill_weekday">
                            <option value="">Choose weekday</option>
                            <?php foreach (weekdayOptions() as $weekdayValue => $weekdayLabel): ?>
                                <option value="<?= $weekdayValue ?>"><?= htmlspecialchars($weekdayLabel, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="account-input">
                        <label for="bill-amount">Amount</label>
                        <input id="bill-amount" name="bill_amount" type="text" inputmode="decimal" placeholder="$0.00" required>
                    </div>
                    <div class="account-input">
                        <label for="bill-category">Category</label>
                        <select id="bill-category" name="bill_category" required>
                            <?php foreach ($billCategoryOptions as $categoryValue => $categoryLabel): ?>
                                <option value="<?= htmlspecialchars((string)$categoryValue, ENT_QUOTES, 'UTF-8') ?>" <?= $categoryValue === 'uncategorized' ? 'selected' : '' ?>><?= htmlspecialchars((string)$categoryLabel, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="account-input">
                        <label for="bill-credit-card">Paid with</label>
                        <select id="bill-credit-card" name="bill_credit_card_id">
                            <option value="">Not assigned</option>
                            <?php foreach ($creditCardAccounts as $account): ?>
                                <option value="<?= htmlspecialchars((string)$account['id'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)$account['name'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit">Add Bill</button>
                </form>
                <?php if ($error !== ''): ?>
                    <p class="notice"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>
            </section>

            <section class="panel" aria-labelledby="monthly-bills-title">
                <h2 class="stage-title" id="monthly-bills-title">Your Bills</h2>
                <?php if ($data['bills'] === []): ?>
                    <p class="empty">No bills yet.</p>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="bill-table">
                            <thead>
                                <tr>
                                    <th scope="col">Bill</th>
                                    <th class="day-cell" scope="col">
                                        <a class="sort-link" href="<?= htmlspecialchars(buildBillSortUrl('day', $billSort, $billSortDirection), ENT_QUOTES, 'UTF-8') ?>">Schedule<?= $billSort === 'day' ? ($billSortDirection === 'asc' ? ' &uarr;' : ' &darr;') : '' ?></a>
                                    </th>
                                    <th class="amount-cell" scope="col">
                                        <a class="sort-link" href="<?= htmlspecialchars(buildBillSortUrl('amount', $billSort, $billSortDirection), ENT_QUOTES, 'UTF-8') ?>">Amount<?= $billSort === 'amount' ? ($billSortDirection === 'asc' ? ' &uarr;' : ' &darr;') : '' ?></a>
                                    </th>
                                    <th scope="col">Category</th>
                                    <th scope="col">Paid With</th>
                                    <th class="actions-cell" scope="col">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($sortedBills as $bill): ?>
                                    <?php
                                        $billId = (string)$bill['id'];
                                        $billCreditCardId = (string)($bill['credit_card_id'] ?? '');
                                        $billCategory = (string)($bill['category'] ?? 'monthly_bill');
                                        $billCadence = (string)($bill['cadence'] ?? 'monthly');
                                    ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars((string)$bill['name'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                                        <td class="day-cell"><?= htmlspecialchars(billScheduleLabel($bill), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td class="amount-cell"><?= htmlspecialchars(formatMoney((float)$bill['amount']), ENT_QUOTES, 'UTF-8') ?><?= $billCadence === 'weekly' ? ' / week' : '' ?></td>
                                        <td colspan="2">
                                            <form class="bill-card-form" method="post" action="">
                                                <input type="hidden" name="action" value="save_bill_card">
                                                <input type="hidden" name="bill_id" value="<?= htmlspecialchars($billId, ENT_QUOTES, 'UTF-8') ?>">
                                                <label for="bill-cadence-<?= htmlspecialchars($billId, ENT_QUOTES, 'UTF-8') ?>">Schedule</label>
                                                <select id="bill-cadence-<?= htmlspecialchars($billId, ENT_QUOTES, 'UTF-8') ?>" name="bill_cadence">
                                                    <option value="monthly" <?= $billCadence === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                                                    <option value="weekly" <?= $billCadence === 'weekly' ? 'selected' : '' ?>>Weekly</option>
                                                </select>
                                                <label for="bill-day-<?= htmlspecialchars($billId, ENT_QUOTES, 'UTF-8') ?>">Monthly day</label>
                                                <select id="bill-day-<?= htmlspecialchars($billId, ENT_QUOTES, 'UTF-8') ?>" name="bill_day">
                                                    <option value="">Choose day</option>
                                                    <?php for ($day = 1; $day <= 31; $day++): ?>
                                                        <option value="<?= $day ?>" <?= (int)($bill['day'] ?? 0) === $day ? 'selected' : '' ?>><?= $day ?></option>
                                                    <?php endfor; ?>
                                                </select>
                                                <label for="bill-weekday-<?= htmlspecialchars($billId, ENT_QUOTES, 'UTF-8') ?>">Weekly day</label>
                                                <select id="bill-weekday-<?= htmlspecialchars($billId, ENT_QUOTES, 'UTF-8') ?>" name="bill_weekday">
                                                    <option value="">Choose weekday</option>
                                                    <?php foreach (weekdayOptions() as $weekdayValue => $weekdayName): ?>
                                                        <option value="<?= $weekdayValue ?>" <?= (int)($bill['weekday'] ?? 0) === $weekdayValue ? 'selected' : '' ?>><?= htmlspecialchars($weekdayName, ENT_QUOTES, 'UTF-8') ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <label for="bill-amount-<?= htmlspecialchars($billId, ENT_QUOTES, 'UTF-8') ?>">Amount</label>
                                                <input id="bill-amount-<?= htmlspecialchars($billId, ENT_QUOTES, 'UTF-8') ?>" name="bill_amount" type="text" inputmode="decimal" value="<?= htmlspecialchars(number_format((float)($bill['amount'] ?? 0.0), 2), ENT_QUOTES, 'UTF-8') ?>" required>
                                                <label for="bill-category-<?= htmlspecialchars($billId, ENT_QUOTES, 'UTF-8') ?>">Category</label>
                                                <select id="bill-category-<?= htmlspecialchars($billId, ENT_QUOTES, 'UTF-8') ?>" name="bill_category">
                                                    <?php foreach ($billCategoryOptions as $categoryValue => $categoryLabel): ?>
                                                        <option value="<?= htmlspecialchars((string)$categoryValue, ENT_QUOTES, 'UTF-8') ?>" <?= $billCategory === $categoryValue ? 'selected' : '' ?>><?= htmlspecialchars((string)$categoryLabel, ENT_QUOTES, 'UTF-8') ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <label for="bill-card-<?= htmlspecialchars($billId, ENT_QUOTES, 'UTF-8') ?>">Paid with</label>
                                                <select id="bill-card-<?= htmlspecialchars($billId, ENT_QUOTES, 'UTF-8') ?>" name="bill_credit_card_id">
                                                    <option value="">Not assigned</option>
                                                    <?php foreach ($creditCardAccounts as $account): ?>
                                                        <?php $accountId = (string)$account['id']; ?>
                                                        <option value="<?= htmlspecialchars($accountId, ENT_QUOTES, 'UTF-8') ?>" <?= $billCreditCardId === $accountId ? 'selected' : '' ?>><?= htmlspecialchars((string)$account['name'], ENT_QUOTES, 'UTF-8') ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button class="secondary-button" type="submit">Save</button>
                                            </form>
                                        </td>
                                        <td class="actions-cell">
                                            <form method="post" action="">
                                                <input type="hidden" name="action" value="delete_bill">
                                                <input type="hidden" name="bill_id" value="<?= htmlspecialchars($billId, ENT_QUOTES, 'UTF-8') ?>">
                                                <button class="danger-button" type="submit">Remove</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        <?php elseif ($isMoneyDashboardView): ?>

        <div class="dashboard-grid">
        <section class="summary-panel" aria-labelledby="summary-title">
            <h2 class="stage-title" id="summary-title"><?= $latestDate !== '' ? htmlspecialchars(formatDateHeader($latestDate), ENT_QUOTES, 'UTF-8') : 'Current Tally' ?></h2>
            <div class="summary-grid">
                <div class="metric">
                    <span>Bank accounts</span>
                    <strong><?= htmlspecialchars(formatMoney((float)$latestTotals['bank']), ENT_QUOTES, 'UTF-8') ?></strong>
                </div>
                <div class="metric is-net-positive">
                    <span>Available money</span>
                    <strong><?= htmlspecialchars(formatMoney((float)$latestTotals['liquid_bank']), ENT_QUOTES, 'UTF-8') ?></strong>
                </div>
                <div class="metric">
                    <span>Assets</span>
                    <strong><?= htmlspecialchars(formatMoney((float)$latestTotals['asset']), ENT_QUOTES, 'UTF-8') ?></strong>
                </div>
                <div class="metric">
                    <span>Credit cards</span>
                    <strong><?= htmlspecialchars(formatMoney((float)$latestTotals['credit_card_debt']), ENT_QUOTES, 'UTF-8') ?></strong>
                </div>
                <div class="metric <?= (float)$latestTotals['liquid_after_credit_cards'] >= 0 ? 'is-net-positive' : 'is-net-negative' ?>">
                    <span>Liquid after cards</span>
                    <strong><?= htmlspecialchars(formatMoney((float)$latestTotals['liquid_after_credit_cards']), ENT_QUOTES, 'UTF-8') ?></strong>
                </div>
                <div class="metric">
                    <span>Debt accounts</span>
                    <strong><?= htmlspecialchars(formatMoney((float)$latestTotals['debt']), ENT_QUOTES, 'UTF-8') ?></strong>
                </div>
                <div class="metric <?= (float)$latestTotals['net'] >= 0 ? 'is-net-positive' : 'is-net-negative' ?>">
                    <span>Total tally</span>
                    <strong><?= htmlspecialchars(formatMoney((float)$latestTotals['net']), ENT_QUOTES, 'UTF-8') ?></strong>
                </div>
                <div class="metric <?= $dayOverDayChange !== null && (float)$dayOverDayChange > 0 ? 'is-change-positive' : ($dayOverDayChange !== null && (float)$dayOverDayChange < 0 ? 'is-change-negative' : '') ?>">
                    <span>Day over day</span>
                    <strong><?= $dayOverDayChange !== null ? htmlspecialchars(formatSignedMoney((float)$dayOverDayChange), ENT_QUOTES, 'UTF-8') : 'No prior tally' ?></strong>
                </div>
            </div>
        </section>

        <section class="panel" aria-labelledby="credit-card-bars-title">
            <h2 class="stage-title" id="credit-card-bars-title">Credit Card Balances</h2>
            <?php if ($creditCardDebtBreakdown === []): ?>
                <p class="empty">No credit card balances in the latest tally.</p>
            <?php else: ?>
                <div class="chart-wrap">
                    <div class="chart-frame credit-card-bars" role="img" aria-label="Credit card balances sorted from highest to lowest, totaling <?= htmlspecialchars(formatMoney($creditCardDebtTotal), ENT_QUOTES, 'UTF-8') ?>">
                        <?php foreach ($creditCardDebtBreakdown as $card): ?>
                            <div class="credit-card-bar-row">
                                <span class="credit-card-bar-name"><?= htmlspecialchars((string)$card['name'], ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="credit-card-bar-track">
                                    <span class="credit-card-bar-fill" style="--segment-color: <?= htmlspecialchars((string)$card['color'], ENT_QUOTES, 'UTF-8') ?>; width: <?= number_format((float)$card['bar_percent'], 4, '.', '') ?>%;">
                                        <?php if ((float)($card['new_spending'] ?? 0.0) > 0.0): ?>
                                            <span class="credit-card-bar-new" style="width: <?= number_format((float)$card['new_spending_percent'], 4, '.', '') ?>%;"></span>
                                        <?php endif; ?>
                                    </span>
                                </span>
                                <span class="credit-card-bar-value"><?= htmlspecialchars(formatMoney((float)$card['balance']), ENT_QUOTES, 'UTF-8') ?> / New <?= htmlspecialchars(formatMoney((float)($card['new_spending'] ?? 0.0)), ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="chart-caption" aria-label="Credit card balance summary">
                        <span class="stat-pill"><?= count($creditCardDebtBreakdown) ?> card<?= count($creditCardDebtBreakdown) === 1 ? '' : 's' ?></span>
                        <span class="stat-pill">Total: <?= htmlspecialchars(formatMoney($creditCardDebtTotal), ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="stat-pill">Light: new spending since last payment</span>
                    </div>
                </div>
            <?php endif; ?>
        </section>

        <section class="panel" aria-labelledby="asset-breakdown-title">
            <h2 class="stage-title" id="asset-breakdown-title">Asset Breakdown</h2>
            <?php if ($assetBreakdown === []): ?>
                <p class="empty">Save positive bank or asset values to see your breakdown.</p>
            <?php else: ?>
                <div class="chart-wrap">
                    <div class="chart-frame">
                        <div class="pie-layout">
                            <svg class="pie-chart" viewBox="0 0 320 320" role="img" aria-labelledby="asset-pie-title asset-pie-desc">
                                <title id="asset-pie-title">Asset breakdown</title>
                                <desc id="asset-pie-desc">Pie chart showing each bank and asset account's share of total positive asset value.</desc>
                                <?php if (count($assetBreakdown) === 1): ?>
                                    <circle class="pie-slice" cx="160" cy="160" r="128" fill="<?= htmlspecialchars((string)$assetBreakdown[0]['color'], ENT_QUOTES, 'UTF-8') ?>">
                                        <title><?= htmlspecialchars((string)$assetBreakdown[0]['name'] . ': ' . formatMoney((float)$assetBreakdown[0]['balance']) . ' / 100.0%', ENT_QUOTES, 'UTF-8') ?></title>
                                    </circle>
                                <?php else: ?>
                                    <?php $sliceStart = 0.0; ?>
                                    <?php foreach ($assetBreakdown as $index => $slice): ?>
                                        <?php
                                            $sliceEnd = $index === count($assetBreakdown) - 1
                                                ? 360.0
                                                : $sliceStart + (((float)$slice['balance'] / $assetBreakdownTotal) * 360.0);
                                            $slicePath = buildPieSlicePath(160.0, 160.0, 128.0, $sliceStart, $sliceEnd);
                                        ?>
                                        <path class="pie-slice" d="<?= htmlspecialchars($slicePath, ENT_QUOTES, 'UTF-8') ?>" fill="<?= htmlspecialchars((string)$slice['color'], ENT_QUOTES, 'UTF-8') ?>">
                                            <title><?= htmlspecialchars((string)$slice['name'] . ': ' . formatMoney((float)$slice['balance']) . ' / ' . number_format((float)$slice['percent'], 1) . '%', ENT_QUOTES, 'UTF-8') ?></title>
                                        </path>
                                        <?php $sliceStart = $sliceEnd; ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </svg>
                            <ul class="pie-legend" aria-label="Asset breakdown legend">
                                <?php foreach ($assetBreakdown as $slice): ?>
                                    <li class="pie-legend-item">
                                        <span class="pie-legend-swatch" style="--slice-color: <?= htmlspecialchars((string)$slice['color'], ENT_QUOTES, 'UTF-8') ?>"></span>
                                        <span class="pie-legend-name"><?= htmlspecialchars((string)$slice['name'], ENT_QUOTES, 'UTF-8') ?> <span class="pie-legend-type"><?= ($slice['type'] ?? '') === 'asset' ? 'Asset' : 'Bank' ?></span></span>
                                        <span class="pie-legend-value"><?= htmlspecialchars(formatMoney((float)$slice['balance']), ENT_QUOTES, 'UTF-8') ?> / <?= number_format((float)$slice['percent'], 1) ?>%</span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                    <div class="chart-caption" aria-label="Asset breakdown summary">
                        <span class="stat-pill"><?= count($assetBreakdown) ?> account<?= count($assetBreakdown) === 1 ? '' : 's' ?></span>
                        <span class="stat-pill">Total: <?= htmlspecialchars(formatMoney($assetBreakdownTotal), ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                </div>
            <?php endif; ?>
        </section>

        <section class="panel" aria-labelledby="bill-category-breakdown-title">
            <h2 class="stage-title" id="bill-category-breakdown-title">Bill Category Breakdown</h2>
            <?php if ($billCategoryBreakdown === []): ?>
                <p class="empty">Add categorized bills to see your spending breakdown.</p>
            <?php else: ?>
                <div class="chart-wrap">
                    <div class="chart-frame">
                        <div class="pie-layout">
                            <svg class="pie-chart" viewBox="0 0 320 320" role="img" aria-labelledby="bill-category-pie-title bill-category-pie-desc">
                                <title id="bill-category-pie-title">Bill category spending breakdown</title>
                                <desc id="bill-category-pie-desc">Pie chart showing each bill category's share of total monthly bill spending.</desc>
                                <?php if (count($billCategoryBreakdown) === 1): ?>
                                    <circle class="pie-slice" cx="160" cy="160" r="128" fill="<?= htmlspecialchars((string)$billCategoryBreakdown[0]['color'], ENT_QUOTES, 'UTF-8') ?>">
                                        <title><?= htmlspecialchars((string)$billCategoryBreakdown[0]['name'] . ': ' . formatMoney((float)$billCategoryBreakdown[0]['amount']) . ' / 100.0%', ENT_QUOTES, 'UTF-8') ?></title>
                                    </circle>
                                <?php else: ?>
                                    <?php $sliceStart = 0.0; ?>
                                    <?php foreach ($billCategoryBreakdown as $index => $slice): ?>
                                        <?php
                                            $sliceEnd = $index === count($billCategoryBreakdown) - 1
                                                ? 360.0
                                                : $sliceStart + (((float)$slice['amount'] / $monthlyBillTotal) * 360.0);
                                            $slicePath = buildPieSlicePath(160.0, 160.0, 128.0, $sliceStart, $sliceEnd);
                                        ?>
                                        <path class="pie-slice" d="<?= htmlspecialchars($slicePath, ENT_QUOTES, 'UTF-8') ?>" fill="<?= htmlspecialchars((string)$slice['color'], ENT_QUOTES, 'UTF-8') ?>">
                                            <title><?= htmlspecialchars((string)$slice['name'] . ': ' . formatMoney((float)$slice['amount']) . ' / ' . number_format((float)$slice['percent'], 1) . '%', ENT_QUOTES, 'UTF-8') ?></title>
                                        </path>
                                        <?php $sliceStart = $sliceEnd; ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </svg>
                            <ul class="pie-legend" aria-label="Bill category breakdown legend">
                                <?php foreach ($billCategoryBreakdown as $slice): ?>
                                    <li class="pie-legend-item">
                                        <span class="pie-legend-swatch" style="--slice-color: <?= htmlspecialchars((string)$slice['color'], ENT_QUOTES, 'UTF-8') ?>"></span>
                                        <span class="pie-legend-name"><?= htmlspecialchars((string)$slice['name'], ENT_QUOTES, 'UTF-8') ?> <span class="pie-legend-type"><?= (int)$slice['count'] ?> bill<?= (int)$slice['count'] === 1 ? '' : 's' ?></span></span>
                                        <span class="pie-legend-value"><?= htmlspecialchars(formatMoney((float)$slice['amount']), ENT_QUOTES, 'UTF-8') ?> / <?= number_format((float)$slice['percent'], 1) ?>%</span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                    <div class="chart-caption" aria-label="Bill category breakdown summary">
                        <span class="stat-pill"><?= count($billCategoryBreakdown) ?> categor<?= count($billCategoryBreakdown) === 1 ? 'y' : 'ies' ?></span>
                        <span class="stat-pill">Monthly run rate: <?= htmlspecialchars(formatMoney($monthlyBillTotal), ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                </div>
            <?php endif; ?>
        </section>

        <section class="panel liquidity-hero" aria-labelledby="liquidity-forecast-title">
            <h2 class="stage-title" id="liquidity-forecast-title">30-Day Liquidity</h2>
            <?php if ($emergencyFundAccounts !== []): ?>
                <form class="chart-toggle" method="get" action="">
                    <input type="hidden" name="include_emergency" value="0">
                    <label for="include-emergency-fund">
                        <input id="include-emergency-fund" name="include_emergency" type="checkbox" value="1" <?= $includeEmergencyFund ? 'checked' : '' ?>>
                        Include emergency fund
                    </label>
                    <button class="secondary-button" type="submit">Update Chart</button>
                </form>
            <?php endif; ?>
            <?php if ($latestDate === ''): ?>
                <p class="empty">Save a daily tally to begin the liquidity forecast.</p>
            <?php else: ?>
                <div class="chart-wrap">
                    <div class="chart-frame">
                        <svg class="bar-chart" viewBox="0 0 <?= $liquidityForecastWidth ?> <?= $liquidityForecastHeight ?>" role="img" aria-labelledby="liquidity-chart-title liquidity-chart-desc">
                            <title id="liquidity-chart-title">30-day liquid money and payment schedule</title>
                            <desc id="liquidity-chart-desc">Green bars show total liquid money for recent actual days and projected upcoming days. Red bars below zero show scheduled debt payments and monthly bills.</desc>
                            <?php foreach ($liquidityForecastTicks as $tickValue): ?>
                                <?php
                                    $tickY = $liquidityForecastBaseline - (($tickValue / $liquidityForecastScaleMax) * $liquidityForecastPositiveHeight);
                                    $tickClass = abs($tickValue) < 0.01 ? 'bar-chart-axis' : 'chart-grid-line';
                                ?>
                                <line class="<?= $tickClass ?>" x1="<?= $liquidityForecastPaddingLeft ?>" y1="<?= number_format($tickY, 2, '.', '') ?>" x2="<?= $liquidityForecastWidth - $liquidityForecastPaddingRight ?>" y2="<?= number_format($tickY, 2, '.', '') ?>"></line>
                                <text class="bar-chart-label" x="8" y="<?= number_format($tickY + 4, 2, '.', '') ?>"><?= $tickValue > 0 ? htmlspecialchars(formatMoney($tickValue), ENT_QUOTES, 'UTF-8') : '$0' ?></text>
                            <?php endforeach; ?>
                            <line class="chart-grid-line" x1="<?= $liquidityForecastPaddingLeft ?>" y1="<?= $liquidityForecastHeight - $liquidityForecastPaddingBottom ?>" x2="<?= $liquidityForecastWidth - $liquidityForecastPaddingRight ?>" y2="<?= $liquidityForecastHeight - $liquidityForecastPaddingBottom ?>"></line>
                            <?php $negativeMidY = $liquidityForecastBaseline + ($liquidityForecastNegativeHeight / 2); ?>
                            <line class="chart-grid-line" x1="<?= $liquidityForecastPaddingLeft ?>" y1="<?= number_format($negativeMidY, 2, '.', '') ?>" x2="<?= $liquidityForecastWidth - $liquidityForecastPaddingRight ?>" y2="<?= number_format($negativeMidY, 2, '.', '') ?>"></line>
                            <text class="bar-chart-label" x="8" y="<?= number_format($negativeMidY + 4, 2, '.', '') ?>">-<?= htmlspecialchars(formatMoney($liquidityForecastScaleMax / 2), ENT_QUOTES, 'UTF-8') ?></text>
                            <text class="bar-chart-label" x="8" y="<?= $liquidityForecastHeight - $liquidityForecastPaddingBottom + 4 ?>">-<?= htmlspecialchars(formatMoney($liquidityForecastScaleMax), ENT_QUOTES, 'UTF-8') ?></text>
                            <?php foreach ($liquidityForecast as $index => $point): ?>
                                <?php
                                    $slotX = $liquidityForecastPaddingLeft + ($index * $liquidityForecastSlotWidth);
                                    $centerX = $slotX + ($liquidityForecastSlotWidth / 2);
                                    $liquidHeight = $point['liquid'] === null ? 0.0 : ((float)$point['liquid'] / $liquidityForecastScaleMax) * $liquidityForecastPositiveHeight;
                                    $paymentHeight = ((float)$point['payments'] / $liquidityForecastScaleMax) * $liquidityForecastNegativeHeight;
                                    $labelDate = new DateTimeImmutable((string)$point['date']);
                                    $paymentTooltipLines = array_map(function (array $payment): string {
                                        return (string)($payment['type'] ?? 'Payment') . ' - ' . (string)$payment['name'] . ': ' . formatMoney((float)$payment['amount']);
                                    }, (array)($point['payment_accounts'] ?? []));
                                    $paymentTooltip = $labelDate->format('M j') . ' payments due: ' . formatMoney((float)$point['payments']);
                                    if ($paymentTooltipLines !== []) {
                                        $paymentTooltip .= "\n" . implode("\n", $paymentTooltipLines);
                                    }
                                    $incomeTooltipLines = array_map(function (array $income): string {
                                        return (string)$income['name'] . ': ' . formatMoney((float)$income['amount']);
                                    }, (array)($point['income_accounts'] ?? []));
                                    $liquidTooltip = $labelDate->format('M j')
                                        . ((bool)($point['is_projected'] ?? false) ? ' projected liquid money: ' : ' liquid money: ')
                                        . formatMoney((float)$point['liquid']);
                                    if ($incomeTooltipLines !== []) {
                                        $liquidTooltip .= "\nIncome received:\n" . implode("\n", $incomeTooltipLines);
                                    }
                                    if (!$includeEmergencyFund) {
                                        $liquidTooltip .= "\nEmergency fund excluded";
                                    }
                                ?>
                                <?php if ((bool)$point['is_today']): ?>
                                    <line class="forecast-today" x1="<?= number_format($centerX, 2, '.', '') ?>" y1="<?= $liquidityForecastPaddingTop ?>" x2="<?= number_format($centerX, 2, '.', '') ?>" y2="<?= $liquidityForecastHeight - $liquidityForecastPaddingBottom ?>"></line>
                                <?php endif; ?>
                                <?php if ($liquidHeight > 0): ?>
                                    <rect class="liquidity-bar" x="<?= number_format($centerX - $liquidityForecastBarWidth - 1, 2, '.', '') ?>" y="<?= number_format($liquidityForecastBaseline - $liquidHeight, 2, '.', '') ?>" width="<?= number_format($liquidityForecastBarWidth, 2, '.', '') ?>" height="<?= number_format($liquidHeight, 2, '.', '') ?>">
                                        <title><?= htmlspecialchars($liquidTooltip, ENT_QUOTES, 'UTF-8') ?></title>
                                    </rect>
                                <?php endif; ?>
                                <?php if ($paymentHeight > 0): ?>
                                    <rect class="payment-bar" x="<?= number_format($centerX + 1, 2, '.', '') ?>" y="<?= $liquidityForecastBaseline ?>" width="<?= number_format($liquidityForecastBarWidth, 2, '.', '') ?>" height="<?= number_format($paymentHeight, 2, '.', '') ?>">
                                        <title><?= htmlspecialchars($paymentTooltip, ENT_QUOTES, 'UTF-8') ?></title>
                                    </rect>
                                <?php endif; ?>
                                <text class="bar-chart-label" x="<?= number_format($centerX, 2, '.', '') ?>" y="<?= $liquidityForecastHeight - 20 ?>" text-anchor="middle"><?= htmlspecialchars($labelDate->format('j'), ENT_QUOTES, 'UTF-8') ?></text>
                            <?php endforeach; ?>
                        </svg>
                    </div>
                    <div class="chart-caption" aria-label="Liquidity chart legend">
                        <span class="stat-pill">Green: actual and projected liquid money</span>
                        <span class="stat-pill">Red: payments and bills due</span>
                        <span class="stat-pill">Dashed line: today</span>
                        <span class="stat-pill">Emergency fund: <?= $includeEmergencyFund ? 'included' : 'excluded' ?></span>
                        <span class="stat-pill"><?= count($data['incomes']) ?> recurring income source<?= count($data['incomes']) === 1 ? '' : 's' ?></span>
                    </div>
                </div>
            <?php endif; ?>
        </section>

        <section class="panel" aria-labelledby="chart-title">
            <h2 class="stage-title" id="chart-title">Account Chart</h2>
            <?php if ($chartOptions === []): ?>
                <p class="empty">Save at least one daily tally before charting an account.</p>
            <?php else: ?>
                <form class="chart-controls" method="get" action="">
                    <div class="chart-control">
                        <label for="chart-account">Account</label>
                        <select id="chart-account" name="chart_account">
                            <optgroup label="Calculated totals">
                                <?php foreach ($chartOptions as $option): ?>
                                    <?php if (($option['type'] ?? '') !== 'calculated') { continue; } ?>
                                    <option value="<?= htmlspecialchars((string)$option['id'], ENT_QUOTES, 'UTF-8') ?>" <?= (string)$option['id'] === $chartAccountId ? 'selected' : '' ?>>
                                        <?= htmlspecialchars((string)$option['name'], ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="Accounts">
                                <?php foreach ($chartOptions as $option): ?>
                                    <?php if (($option['type'] ?? '') !== 'account') { continue; } ?>
                                    <option value="<?= htmlspecialchars((string)$option['id'], ENT_QUOTES, 'UTF-8') ?>" <?= (string)$option['id'] === $chartAccountId ? 'selected' : '' ?>>
                                        <?= htmlspecialchars((string)$option['name'], ENT_QUOTES, 'UTF-8') ?><?= ($option['account_type'] ?? '') === 'debt' ? ' (debt)' : (($option['account_type'] ?? '') === 'asset' ? ' (asset)' : '') ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        </select>
                    </div>
                    <div class="chart-control">
                        <label for="chart-range">Interval</label>
                        <select id="chart-range" name="chart_range">
                            <?php foreach ($chartRangeOptions as $rangeValue => $rangeLabel): ?>
                                <?php $rangeValue = (string)$rangeValue; ?>
                                <option value="<?= htmlspecialchars($rangeValue, ENT_QUOTES, 'UTF-8') ?>" <?= $chartRange === $rangeValue ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($rangeLabel, ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit">Apply</button>
                </form>

                <?php if ($chartSeries === []): ?>
                    <p class="empty">No saved balances for this account in the selected interval.</p>
                <?php else: ?>
                    <div class="chart-wrap">
                        <div class="chart-frame">
                            <svg class="line-chart" viewBox="0 0 <?= $chartWidth ?> <?= $chartHeight ?>" role="img" aria-labelledby="account-chart-title account-chart-desc">
                                <title id="account-chart-title"><?= htmlspecialchars($chartLabel, ENT_QUOTES, 'UTF-8') ?> chart</title>
                                <desc id="account-chart-desc"><?= htmlspecialchars($chartRangeOptions[$chartRange], ENT_QUOTES, 'UTF-8') ?> balance history from <?= htmlspecialchars(formatDateHeader((string)$chartFirstPoint['date']), ENT_QUOTES, 'UTF-8') ?> to <?= htmlspecialchars(formatDateHeader((string)$chartLastPoint['date']), ENT_QUOTES, 'UTF-8') ?>.</desc>
                                <line class="chart-grid-line" x1="<?= $chartPadding ?>" y1="<?= $chartPadding ?>" x2="<?= $chartWidth - $chartPadding ?>" y2="<?= $chartPadding ?>"></line>
                                <line class="chart-grid-line" x1="<?= $chartPadding ?>" y1="<?= $chartHeight / 2 ?>" x2="<?= $chartWidth - $chartPadding ?>" y2="<?= $chartHeight / 2 ?>"></line>
                                <line class="chart-grid-line" x1="<?= $chartPadding ?>" y1="<?= $chartHeight - $chartPadding ?>" x2="<?= $chartWidth - $chartPadding ?>" y2="<?= $chartHeight - $chartPadding ?>"></line>
                                <text class="chart-axis-label" x="8" y="<?= $chartPadding + 4 ?>"><?= htmlspecialchars(formatMoney((float)$chartMax), ENT_QUOTES, 'UTF-8') ?></text>
                                <text class="chart-axis-label" x="8" y="<?= ($chartHeight / 2) + 4 ?>"><?= htmlspecialchars(formatMoney((float)(($chartMax + $chartMin) / 2)), ENT_QUOTES, 'UTF-8') ?></text>
                                <text class="chart-axis-label" x="8" y="<?= $chartHeight - $chartPadding + 4 ?>"><?= htmlspecialchars(formatMoney((float)$chartMin), ENT_QUOTES, 'UTF-8') ?></text>
                                <polyline class="chart-line" points="<?= htmlspecialchars($chartPolyline, ENT_QUOTES, 'UTF-8') ?>"></polyline>
                                <text class="chart-date-label" x="<?= $chartPadding ?>" y="<?= $chartHeight - 8 ?>"><?= htmlspecialchars((new DateTimeImmutable((string)$chartFirstPoint['date']))->format('M j'), ENT_QUOTES, 'UTF-8') ?></text>
                                <text class="chart-date-label" x="<?= $chartWidth - $chartPadding ?>" y="<?= $chartHeight - 8 ?>" text-anchor="end"><?= htmlspecialchars((new DateTimeImmutable((string)$chartLastPoint['date']))->format('M j'), ENT_QUOTES, 'UTF-8') ?></text>
                            </svg>
                        </div>
                        <div class="chart-caption" aria-label="Chart summary">
                            <span class="stat-pill"><?= count($chartSeries) ?> point<?= count($chartSeries) === 1 ? '' : 's' ?></span>
                            <span class="stat-pill">First: <?= htmlspecialchars(formatMoney((float)$chartFirstPoint['value']), ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="stat-pill">Latest: <?= htmlspecialchars(formatMoney((float)$chartLastPoint['value']), ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>
        </div>

        <?php elseif ($isDailyCheckInView): ?>

        <section class="panel" aria-labelledby="entry-title">
            <h2 class="stage-title" id="entry-title">Daily Account Check-In</h2>
            <?php if ($activeAccounts === []): ?>
                <p class="empty">Add at least one bank or debt account before entering a daily tally.</p>
            <?php else: ?>
                <form class="form-grid" method="post" action="">
                    <input type="hidden" name="action" value="save_entry">
                    <div class="account-input">
                        <label for="entry-date">Tally date</label>
                        <input id="entry-date" name="entry_date" type="date" value="<?= htmlspecialchars($todayKey, ENT_QUOTES, 'UTF-8') ?>" required>
                    </div>

                    <?php if ($bankAccounts !== []): ?>
                        <div>
                            <h3 class="stage-title">Bank Accounts</h3>
                            <div class="account-inputs">
                                <?php foreach ($bankAccounts as $account): ?>
                                    <?php
                                        $accountId = (string)$account['id'];
                                        $storedBalance = $todayEntry['balances'][$accountId] ?? fallbackBalanceForAccount($data['entries'], $accountId);
                                    ?>
                                    <div class="account-input">
                                        <label for="balance-<?= htmlspecialchars($accountId, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)$account['name'], ENT_QUOTES, 'UTF-8') ?></label>
                                        <input id="balance-<?= htmlspecialchars($accountId, ENT_QUOTES, 'UTF-8') ?>" name="balances[<?= htmlspecialchars($accountId, ENT_QUOTES, 'UTF-8') ?>]" type="text" inputmode="decimal" placeholder="$0.00" value="<?= $storedBalance !== null ? htmlspecialchars(number_format((float)$storedBalance, 2), ENT_QUOTES, 'UTF-8') : '' ?>" required>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($assetAccounts !== []): ?>
                        <div>
                            <h3 class="stage-title">Assets</h3>
                            <div class="account-inputs">
                                <?php foreach ($assetAccounts as $account): ?>
                                    <?php
                                        $accountId = (string)$account['id'];
                                        $storedBalance = $todayEntry['balances'][$accountId] ?? fallbackBalanceForAccount($data['entries'], $accountId);
                                    ?>
                                    <div class="account-input">
                                        <label for="balance-<?= htmlspecialchars($accountId, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)$account['name'], ENT_QUOTES, 'UTF-8') ?></label>
                                        <input id="balance-<?= htmlspecialchars($accountId, ENT_QUOTES, 'UTF-8') ?>" name="balances[<?= htmlspecialchars($accountId, ENT_QUOTES, 'UTF-8') ?>]" type="text" inputmode="decimal" placeholder="Asset value" value="<?= $storedBalance !== null ? htmlspecialchars(number_format((float)$storedBalance, 2), ENT_QUOTES, 'UTF-8') : '' ?>" required>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($debtAccounts !== []): ?>
                        <div>
                            <h3 class="stage-title">Debt Accounts</h3>
                            <div class="account-inputs">
                                <?php foreach ($debtAccounts as $account): ?>
                                    <?php
                                        $accountId = (string)$account['id'];
                                        $storedBalance = $todayEntry['balances'][$accountId] ?? fallbackBalanceForAccount($data['entries'], $accountId);
                                    ?>
                                    <div class="account-input">
                                        <label for="balance-<?= htmlspecialchars($accountId, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)$account['name'], ENT_QUOTES, 'UTF-8') ?></label>
                                        <input id="balance-<?= htmlspecialchars($accountId, ENT_QUOTES, 'UTF-8') ?>" name="balances[<?= htmlspecialchars($accountId, ENT_QUOTES, 'UTF-8') ?>]" type="text" inputmode="decimal" placeholder="Amount owed" value="<?= $storedBalance !== null ? htmlspecialchars(number_format((float)$storedBalance, 2), ENT_QUOTES, 'UTF-8') : '' ?>" required>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <button type="submit">Save Daily Tally</button>
                </form>
            <?php endif; ?>
            <?php if ($error !== ''): ?>
                <p class="notice"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>
        </section>

        <?php elseif ($isPaymentPlanView): ?>

        <?php if ($error !== ''): ?>
            <p class="notice"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>

        <section class="panel" aria-labelledby="payments-title">
            <h2 class="stage-title" id="payments-title">Payment Plan</h2>
            <?php if ($debtAccounts === []): ?>
                <p class="empty">Add a debt account to start tracking payment dates and minimums.</p>
            <?php else: ?>
                <div class="payment-plan-list">
                    <?php
                        $paymentPlanGroups = [
                            ['title' => 'Credit Cards Still Upcoming This Month', 'accounts' => $paymentPlanUpcomingAccounts],
                            ['title' => 'Credit Cards Already Paid or Later', 'accounts' => $paymentPlanLaterAccounts],
                            ['title' => 'Other Debt Accounts', 'accounts' => $paymentPlanOtherDebtAccounts],
                        ];
                    ?>
                    <?php foreach ($paymentPlanGroups as $paymentPlanGroup): ?>
                        <?php if ($paymentPlanGroup['accounts'] === []) { continue; } ?>
                        <div class="payment-plan-group">
                            <h3><?= htmlspecialchars((string)$paymentPlanGroup['title'], ENT_QUOTES, 'UTF-8') ?></h3>
                            <?php foreach ($paymentPlanGroup['accounts'] as $account): ?>
                        <?php
                            $accountId = (string)$account['id'];
                            $isCreditCard = (bool)($account['credit_card'] ?? true);
                            $spendTarget = $isCreditCard ? (float)($accountPaymentStats[$accountId]['spend_total'] ?? 0.0) : null;
                            $paymentDay = (int)($account['payment_day'] ?? 0);
                            $nextPaymentDate = $account['_next_payment_date'] ?? nextMonthlyPaymentDate($paymentDay, $now);
                            $isPaidThisMonth = (bool)($account['_is_paid_this_month'] ?? false);
                        ?>
                        <form class="payment-plan" method="post" action="">
                            <input type="hidden" name="action" value="save_payment_plan">
                            <input type="hidden" name="account_id" value="<?= htmlspecialchars($accountId, ENT_QUOTES, 'UTF-8') ?>">
                            <div class="payment-account">
                                <strong><?= htmlspecialchars((string)$account['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <span><?= $isCreditCard ? 'Credit card' : 'Debt account' ?></span>
                            </div>
                            <div class="account-input">
                                <label for="payment-day-<?= htmlspecialchars($accountId, ENT_QUOTES, 'UTF-8') ?>">Payment day each month</label>
                                <select id="payment-day-<?= htmlspecialchars($accountId, ENT_QUOTES, 'UTF-8') ?>" name="payment_day" required>
                                    <option value="">Choose day</option>
                                    <?php for ($day = 1; $day <= 31; $day++): ?>
                                        <option value="<?= $day ?>" <?= $paymentDay === $day ? 'selected' : '' ?>><?= $day ?></option>
                                    <?php endfor; ?>
                                </select>
                                <?php if ($nextPaymentDate !== null): ?>
                                    <span class="payment-next">Next: <?= htmlspecialchars($nextPaymentDate->format('M j, Y'), ENT_QUOTES, 'UTF-8') ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="account-input">
                                <label for="minimum-payment-<?= htmlspecialchars($accountId, ENT_QUOTES, 'UTF-8') ?>">Minimum payment</label>
                                <input id="minimum-payment-<?= htmlspecialchars($accountId, ENT_QUOTES, 'UTF-8') ?>" name="minimum_payment" type="text" inputmode="decimal" placeholder="$0.00" value="<?= htmlspecialchars(number_format((float)($account['minimum_payment'] ?? 0.0), 2), ENT_QUOTES, 'UTF-8') ?>" required>
                            </div>
                            <?php if ($isCreditCard): ?>
                                <div class="account-input">
                                    <label for="intended-payment-<?= htmlspecialchars($accountId, ENT_QUOTES, 'UTF-8') ?>">Intended payment</label>
                                    <input id="intended-payment-<?= htmlspecialchars($accountId, ENT_QUOTES, 'UTF-8') ?>" name="intended_payment" type="text" inputmode="decimal" placeholder="Use minimum" value="<?= (float)($account['intended_payment'] ?? 0.0) > 0 ? htmlspecialchars(number_format((float)$account['intended_payment'], 2), ENT_QUOTES, 'UTF-8') : '' ?>">
                                </div>
                            <?php else: ?>
                                <input type="hidden" name="intended_payment" value="">
                                <div class="payment-target">
                                    <span>Intended payment</span>
                                    <strong>Credit cards only</strong>
                                </div>
                            <?php endif; ?>
                            <label class="payment-paid-toggle" for="paid-this-month-<?= htmlspecialchars($accountId, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="paid_this_month" value="0">
                                <input id="paid-this-month-<?= htmlspecialchars($accountId, ENT_QUOTES, 'UTF-8') ?>" name="paid_this_month" type="checkbox" value="1" <?= $isPaidThisMonth ? 'checked' : '' ?>>
                                <span>Paid for <?= htmlspecialchars($now->format('M Y'), ENT_QUOTES, 'UTF-8') ?></span>
                            </label>
                            <div class="payment-target">
                                <?php if ($isCreditCard): ?>
                                    <span>Cover 30-day spending</span>
                                    <strong><?= htmlspecialchars(formatMoney((float)$spendTarget), ENT_QUOTES, 'UTF-8') ?></strong>
                                <?php else: ?>
                                    <span>30-day spending target</span>
                                    <strong>Not applicable</strong>
                                <?php endif; ?>
                            </div>
                            <button type="submit">Save Payment</button>
                        </form>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <?php elseif ($isAccountsView): ?>

        <?php if ($error !== ''): ?>
            <p class="notice"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>

        <section class="panel" aria-labelledby="configure-title">
            <h2 class="stage-title" id="configure-title">Configure Accounts</h2>
            <form class="entry" method="post" action="">
                <input type="hidden" name="action" value="add_account">
                <label for="account-name">Account name</label>
                <input id="account-name" name="account_name" type="text" maxlength="80" placeholder="Account name" required>
                <label for="account-type">Account type</label>
                <select id="account-type" name="account_type" required>
                    <option value="bank">Bank</option>
                    <option value="asset">Asset</option>
                    <option value="debt">Debt</option>
                </select>
                <label for="account-liquid">Availability</label>
                <select id="account-liquid" name="account_liquid" required>
                    <option value="1">Liquid</option>
                    <option value="0">Not liquid</option>
                </select>
                <label for="account-credit-card">Debt category</label>
                <select id="account-credit-card" name="account_credit_card" required>
                    <option value="1">Credit card</option>
                    <option value="0">Not credit card</option>
                </select>
                <button type="submit">Add Account</button>
            </form>

            <div class="account-groups">
                <section class="account-group" aria-labelledby="bank-list-title">
                    <h3 id="bank-list-title">Bank Accounts</h3>
                    <?php if ($bankAccounts === []): ?>
                        <p class="empty">No bank accounts yet.</p>
                    <?php else: ?>
                        <ul class="account-list">
                            <?php foreach ($bankAccounts as $account): ?>
                                <li class="account-row">
                                    <div>
                                        <strong><?= htmlspecialchars((string)$account['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <span>Bank / <?= (bool)($account['liquid'] ?? true) ? 'Liquid' : 'Not liquid' ?></span>
                                    </div>
                                    <div class="account-actions">
                                        <form method="post" action="">
                                            <input type="hidden" name="action" value="toggle_liquidity">
                                            <input type="hidden" name="account_id" value="<?= htmlspecialchars((string)$account['id'], ENT_QUOTES, 'UTF-8') ?>">
                                            <button class="secondary-button" type="submit"><?= (bool)($account['liquid'] ?? true) ? 'Mark not liquid' : 'Mark liquid' ?></button>
                                        </form>
                                        <form method="post" action="">
                                            <input type="hidden" name="action" value="drop_account">
                                            <input type="hidden" name="account_id" value="<?= htmlspecialchars((string)$account['id'], ENT_QUOTES, 'UTF-8') ?>">
                                            <button class="danger-button" type="submit">Close</button>
                                        </form>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>

                <section class="account-group" aria-labelledby="asset-list-title">
                    <h3 id="asset-list-title">Assets</h3>
                    <?php if ($assetAccounts === []): ?>
                        <p class="empty">No assets yet.</p>
                    <?php else: ?>
                        <ul class="account-list">
                            <?php foreach ($assetAccounts as $account): ?>
                                <li class="account-row">
                                    <div>
                                        <strong><?= htmlspecialchars((string)$account['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <span>Asset / Counts toward net worth</span>
                                    </div>
                                    <div class="account-actions">
                                        <form method="post" action="">
                                            <input type="hidden" name="action" value="drop_account">
                                            <input type="hidden" name="account_id" value="<?= htmlspecialchars((string)$account['id'], ENT_QUOTES, 'UTF-8') ?>">
                                            <button class="danger-button" type="submit">Close</button>
                                        </form>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>

                <section class="account-group" aria-labelledby="debt-list-title">
                    <h3 id="debt-list-title">Debt Accounts</h3>
                    <?php if ($debtAccounts === []): ?>
                        <p class="empty">No debt accounts yet.</p>
                    <?php else: ?>
                        <ul class="account-list">
                            <?php foreach ($debtAccounts as $account): ?>
                                <li class="account-row">
                                    <div>
                                        <strong><?= htmlspecialchars((string)$account['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <span>Liability / <?= (bool)($account['credit_card'] ?? true) ? 'Credit card' : 'Not credit card' ?></span>
                                    </div>
                                    <div class="account-actions">
                                        <?php if ((bool)($account['credit_card'] ?? true)): ?>
                                            <a class="link-button" href="finance.php?account=<?= rawurlencode((string)$account['id']) ?>">Details</a>
                                        <?php endif; ?>
                                        <form method="post" action="">
                                            <input type="hidden" name="action" value="toggle_credit_card">
                                            <input type="hidden" name="account_id" value="<?= htmlspecialchars((string)$account['id'], ENT_QUOTES, 'UTF-8') ?>">
                                            <button class="secondary-button" type="submit"><?= (bool)($account['credit_card'] ?? true) ? 'Mark not card' : 'Mark credit card' ?></button>
                                        </form>
                                        <form method="post" action="">
                                            <input type="hidden" name="action" value="drop_account">
                                            <input type="hidden" name="account_id" value="<?= htmlspecialchars((string)$account['id'], ENT_QUOTES, 'UTF-8') ?>">
                                            <button class="danger-button" type="submit">Close</button>
                                        </form>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>
            </div>

            <?php if ($droppedAccounts !== []): ?>
                <div class="account-group dropped-accounts">
                    <h3>Closed Accounts</h3>
                    <ul class="account-list">
                        <?php foreach ($droppedAccounts as $account): ?>
                            <?php $closedDateLabel = formatOptionalClosedDate((string)($account['closed_at'] ?? '')); ?>
                            <li class="account-row">
                                <div>
                                    <strong><?= htmlspecialchars((string)$account['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                    <span><?= $account['type'] === 'debt' ? ((bool)($account['credit_card'] ?? true) ? 'Debt / Credit card' : 'Debt / Not credit card') : ($account['type'] === 'asset' ? 'Asset / Counts toward net worth' : ((bool)($account['liquid'] ?? true) ? 'Bank / Liquid' : 'Bank / Not liquid')) ?><?= $closedDateLabel !== '' ? ' / Closed ' . htmlspecialchars($closedDateLabel, ENT_QUOTES, 'UTF-8') : '' ?></span>
                                </div>
                                <form method="post" action="">
                                    <input type="hidden" name="action" value="restore_account">
                                    <input type="hidden" name="account_id" value="<?= htmlspecialchars((string)$account['id'], ENT_QUOTES, 'UTF-8') ?>">
                                    <button class="secondary-button" type="submit">Restore</button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </section>

        <?php endif; ?>
    </main>

    <section class="lock-screen<?= $isAuthenticated ? ' is-hidden' : '' ?>" aria-labelledby="lock-title" data-lock-screen>
        <div class="lock-card">
            <h2 id="lock-title">Unlock Money Kata</h2>
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

        document.querySelectorAll('form').forEach((form) => {
            const cadenceSelect = form.querySelector('select[name="bill_cadence"]');
            const monthlyDaySelect = form.querySelector('select[name="bill_day"]');
            const weeklyDaySelect = form.querySelector('select[name="bill_weekday"]');
            if (!cadenceSelect || !monthlyDaySelect || !weeklyDaySelect) {
                return;
            }

            const syncBillScheduleFields = () => {
                const isWeekly = cadenceSelect.value === 'weekly';
                monthlyDaySelect.disabled = isWeekly;
                monthlyDaySelect.required = !isWeekly;
                weeklyDaySelect.disabled = !isWeekly;
                weeklyDaySelect.required = isWeekly;
            };

            cadenceSelect.addEventListener('change', syncBillScheduleFields);
            syncBillScheduleFields();
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
