<?php
declare(strict_types=1);

function summarizeCardInterest(array $cards, array $entries, DateTimeImmutable $today): array
{
    $summary = ['count' => count($cards), 'daily_count' => 0, 'statement_count' => 0, 'daily' => null, 'statement' => null];
    foreach ($cards as $card) {
        $estimate = estimateCardInterest($card, $entries, $today);
        if ($estimate['daily'] !== null) {
            $summary['daily'] = ($summary['daily'] ?? 0.0) + $estimate['daily'];
            $summary['daily_count']++;
        }
        $statement = $estimate['cycle']['total'] ?? null;
        if ($statement !== null) {
            $summary['statement'] = ($summary['statement'] ?? 0.0) + $statement;
            $summary['statement_count']++;
        }
    }
    return $summary;
}

function estimateCardInterest(array $card, array $entries, DateTimeImmutable $today): array
{
    $today = $today->setTime(0, 0);
    $todayKey = $today->format('Y-m-d');
    $balances = [];
    ksort($entries);
    foreach ($entries as $date => $entry) {
        $value = $entry['balances'][$card['id']] ?? null;
        if ((string)$date <= $todayKey && is_numeric($value)) {
            $balances[(string)$date] = abs((float)$value);
        }
    }
    $balance = $balances === [] ? null : (float)end($balances);
    $apr = is_numeric($card['apr'] ?? null) ? (float)$card['apr'] : null;
    $rate = $apr !== null ? $apr / 100 / 365 : null;
    $result = [
        'balance' => $balance,
        'balance_date' => $balances === [] ? null : array_key_last($balances),
        'daily' => $balance !== null && $rate !== null ? $balance * $rate : null,
        'cycle' => null,
    ];
    $day = (int)($card['statement_day'] ?? 0);
    if ($day < 1 || $day > 31 || $balance === null || $rate === null) {
        return $result;
    }
    // A closing day beyond the month's length falls on its last day.
    $closeInMonth = static function (DateTimeImmutable $month) use ($day): DateTimeImmutable {
        return $month->setDate((int)$month->format('Y'), (int)$month->format('n'), min($day, (int)$month->format('t')));
    };
    $month = $today->modify('first day of this month');
    $close = $closeInMonth($month);
    if ($close < $today) {
        $month = $month->modify('+1 month');
        $close = $closeInMonth($month);
    }
    $start = $closeInMonth($month->modify('-1 month'))->modify('+1 day');
    $knownBalance = null;
    foreach ($balances as $date => $value) {
        if ($date < $start->format('Y-m-d')) {
            $knownBalance = $value;
        }
    }
    $toDate = 0.0;
    $missingDays = 0;
    for ($date = $start; $date <= $today; $date = $date->modify('+1 day')) {
        $key = $date->format('Y-m-d');
        if (array_key_exists($key, $balances)) {
            $knownBalance = $balances[$key];
        }
        if ($knownBalance === null) {
            $missingDays++;
        } else {
            $toDate += $knownBalance * $rate;
        }
    }
    $remainingDays = (int)$today->diff($close)->days;
    $remaining = $balance * $rate * $remainingDays;
    $result['cycle'] = [
        'start' => $start->format('Y-m-d'),
        'close' => $close->format('Y-m-d'),
        'remaining_days' => $remainingDays,
        'missing_days' => $missingDays,
        'to_date' => $toDate,
        'remaining' => $remaining,
        'total' => $missingDays === 0 ? $toDate + $remaining : null,
    ];
    return $result;
}
