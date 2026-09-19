<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/card-interest.php';

function expectInterest(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function closeInterest(float $actual, float $expected): bool
{
    return abs($actual - $expected) < 0.000001;
}
$card = ['id' => 'card', 'apr' => 24, 'statement_day' => 30];
$entries = ['2026-08-30' => ['balances' => ['card' => 1000]]];
$estimate = estimateCardInterest($card, $entries, new DateTimeImmutable('2026-09-15 18:00:00'));
expectInterest($estimate['cycle']['start'] === '2026-08-31', 'Cycle begins after prior closing date');
expectInterest(closeInterest($estimate['cycle']['total'], 1000 * .24 / 365 * 31), 'Constant balance over full cycle');
$entries['2026-09-15'] = ['balances' => ['card' => 1500]];
$entries['2026-10-01'] = ['balances' => ['card' => 99999]];
$estimate = estimateCardInterest($card, array_reverse($entries, true), new DateTimeImmutable('2026-09-15'));
expectInterest(closeInterest($estimate['cycle']['total'], (1000 * 15 + 1500 * 16) * .24 / 365), 'Weight increases by day; sort dates and ignore future tallies');
$entries['2026-09-20'] = ['balances' => ['card' => 500]];
$estimate = estimateCardInterest($card, $entries, new DateTimeImmutable('2026-09-30'));
expectInterest($estimate['cycle']['remaining_days'] === 0, 'Closing day belongs to current cycle');
expectInterest(closeInterest($estimate['cycle']['total'], (1000 * 15 + 1500 * 5 + 500 * 11) * .24 / 365), 'Payments reduce subsequent interest');
$estimate = estimateCardInterest($card, $entries, new DateTimeImmutable('2026-10-01'));
expectInterest($estimate['cycle']['start'] === '2026-10-01', 'Cycle rolls over after close');
$card['statement_day'] = 31;
$estimate = estimateCardInterest($card, ['2028-01-31' => ['balances' => ['card' => 1000]]], new DateTimeImmutable('2028-02-20'));
expectInterest($estimate['cycle']['close'] === '2028-02-29' && $estimate['cycle']['start'] === '2028-02-01', 'Leap year month end');
$estimate = estimateCardInterest($card, ['2026-02-10' => ['balances' => ['card' => 1000]]], new DateTimeImmutable('2026-02-20'));
expectInterest($estimate['cycle']['missing_days'] === 9 && $estimate['cycle']['total'] === null, 'Missing history does not produce misleading full total');
$card['apr'] = 0;
expectInterest(estimateCardInterest($card, $entries, new DateTimeImmutable('2026-09-20'))['daily'] === 0.0, 'Zero APR is valid');
$card['apr'] = null;
expectInterest(estimateCardInterest($card, $entries, new DateTimeImmutable('2026-09-20'))['daily'] === null, 'Unknown APR is not zero');
$card['apr'] = 24;
$card['statement_day'] = 0;
$estimate = estimateCardInterest($card, $entries, new DateTimeImmutable('2026-09-20'));
expectInterest($estimate['daily'] !== null && $estimate['cycle'] === null, 'Daily cost works without closing day');
expectInterest(estimateCardInterest($card, [], new DateTimeImmutable('2026-09-20'))['daily'] === null, 'Missing balance');
$cards = [
    ['id' => 'a', 'apr' => 24, 'statement_day' => 30],
    ['id' => 'b', 'apr' => 12, 'statement_day' => 20],
    ['id' => 'zero', 'apr' => 0, 'statement_day' => 20],
    ['id' => 'unknown', 'apr' => null, 'statement_day' => 20],
    ['id' => 'partial', 'apr' => 24, 'statement_day' => 30],
];
$history = [
    '2026-08-01' => ['balances' => ['a' => 1000, 'b' => 2000, 'zero' => 500, 'unknown' => 100]],
    '2026-09-10' => ['balances' => ['partial' => 100]],
];
$today = new DateTimeImmutable('2026-09-15');
$summary = summarizeCardInterest($cards, $history, $today);
expectInterest($summary['count'] === 5 && $summary['daily_count'] === 4 && $summary['statement_count'] === 3, 'Coverage distinguishes unknown APR, zero APR, and incomplete history');
expectInterest(closeInterest($summary['daily'], (1000 * .24 + 2000 * .12 + 100 * .24) / 365), 'Combined daily interest');
$expected = estimateCardInterest($cards[0], $history, $today)['cycle']['total'] + estimateCardInterest($cards[1], $history, $today)['cycle']['total'];
expectInterest(closeInterest($summary['statement'], $expected), 'Combine each card at its own closing date');
$summary = summarizeCardInterest([$cards[3]], $history, $today);
expectInterest($summary['daily'] === null && $summary['statement'] === null, 'All unknown totals stay unavailable');
$summary = summarizeCardInterest([$cards[2]], $history, $today);
expectInterest($summary['daily'] === 0.0 && $summary['statement'] === 0.0, 'Known zero totals remain zero');
expectInterest(summarizeCardInterest([], [], $today)['count'] === 0, 'Empty summary');
echo "Card interest checks passed.\n";
