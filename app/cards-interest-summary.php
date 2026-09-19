<?php
require_once __DIR__ . '/card-interest.php';
$allCardInterest = summarizeCardInterest($creditCardAccounts, $data['entries'], $now);
$interestMoney = static function (?float $amount): string {
    return $amount === null ? 'Not available' : htmlspecialchars(formatMoney($amount), ENT_QUOTES, 'UTF-8');
};
?>
<section class="summary-panel" aria-labelledby="all-card-interest-title">
    <h2 class="stage-title" id="all-card-interest-title">Projected interest — all cards</h2>
    <p class="subtitle">Across all <?= (int)$allCardInterest['count'] ?> active credit cards, regardless of the chart selection below.</p>
    <div class="summary-grid">
        <div class="metric">
            <span>Interest per day<?= $allCardInterest['daily_count'] < $allCardInterest['count'] ? ' (partial)' : '' ?></span>
            <strong><?= $interestMoney($allCardInterest['daily']) ?></strong>
            <span><?= (int)$allCardInterest['daily_count'] ?> of <?= (int)$allCardInterest['count'] ?> cards included</span>
        </div>
        <div class="metric">
            <span>30 days at current balances<?= $allCardInterest['daily_count'] < $allCardInterest['count'] ? ' (partial)' : '' ?></span>
            <strong><?= $interestMoney($allCardInterest['daily'] === null ? null : $allCardInterest['daily'] * 30) ?></strong>
            <span><?= (int)$allCardInterest['daily_count'] ?> of <?= (int)$allCardInterest['count'] ?> cards included</span>
        </div>
        <div class="metric">
            <span>Combined next statement interest<?= $allCardInterest['statement_count'] < $allCardInterest['count'] ? ' (partial)' : '' ?></span>
            <strong><?= $interestMoney($allCardInterest['statement']) ?></strong>
            <span><?= (int)$allCardInterest['statement_count'] ?> of <?= (int)$allCardInterest['count'] ?> cards included</span>
        </div>
    </div>
    <?php if ($allCardInterest['daily_count'] < $allCardInterest['count'] || $allCardInterest['statement_count'] < $allCardInterest['count']): ?>
        <p class="notice">Some estimates are unavailable. Daily estimates need a saved APR and balance; statement estimates also need a closing day and balance history covering the cycle. Open a card's details below to review it.</p>
    <?php endif; ?>
    <p class="subtitle">Statement interest adds each card's current cycle estimate through its own next closing date; these dates may differ. Assumes balances accrue interest at their saved purchase APRs and stay unchanged going forward. Grace periods, promotional rates, fees, compounding, and future payments or purchases are not included.</p>
</section>
