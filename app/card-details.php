<?php
// Rendered by finance.php after loading the selected account.
if ($detailCard === null): ?>
    <section class="panel"><h2 class="stage-title">Card not found</h2><a href="finance.php?finance_scope=<?= $financeScope ?>&amp;cards=1">Back to credit cards</a></section>
<?php else:
    $escapeCard = static function ($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); };
    $cardForm = $detailCard;
    if ($error !== '' && ($_POST['action'] ?? '') === 'save_card_details') {
        foreach (['apr', 'credit_limit', 'annual_fee', 'statement_day', 'issuer', 'notes'] as $field) {
            $cardForm[$field] = (string)($_POST[$field] ?? '');
        }
    }
    $cardBalance = $detailStats['current_balance'] ?? null;
    $limit = $detailCard['credit_limit'];
    require_once __DIR__ . '/card-interest.php';
    $interest = estimateCardInterest($detailCard, $data['entries'], $now);
?>
<section class="panel" aria-labelledby="card-details-title">
    <p class="detail-nav"><a href="finance.php?finance_scope=<?= $financeScope ?>&amp;cards=1">All credit cards</a></p>
    <h2 class="stage-title" id="card-details-title">Account details</h2>
    <?php if ($error !== ''): ?><p class="notice" role="alert"><?= $escapeCard($error) ?></p>
    <?php elseif (($_GET['saved'] ?? '') === '1'): ?><p class="notice" role="status">Card details saved.</p><?php endif; ?>
    <div class="summary-grid">
        <div class="metric"><span>Latest balance</span><strong><?= $cardBalance === null ? 'No tally' : $escapeCard(formatMoney((float)$cardBalance)) ?></strong></div>
        <div class="metric"><span>Credit utilization</span><strong><?= $cardBalance !== null && $limit > 0 ? $escapeCard(number_format($cardBalance / $limit * 100, 1)) . '%' : 'Not available' ?></strong></div>
        <div class="metric"><span>Available credit</span><strong><?= $cardBalance !== null && $limit > 0 ? $escapeCard(formatMoney(max(0, $limit - $cardBalance))) : 'Not available' ?></strong></div>
        <div class="metric"><span>Purchase APR</span><strong><?= $detailCard['apr'] === null ? 'Not set' : $escapeCard($detailCard['apr']) . '%' ?></strong></div>
    </div>
    <p class="subtitle">Balance and utilization use your latest recorded tally. Add a credit limit to see utilization and available credit.</p>
    <form method="post" action="finance.php?account=<?= rawurlencode($detailAccountId) ?>&amp;finance_scope=<?= $financeScope ?>">
        <input type="hidden" name="finance_scope" value="<?= $financeScope ?>">
        <input type="hidden" name="action" value="save_card_details">
        <div class="account-inputs" style="margin-top: 20px">
            <div class="account-input"><label for="card-issuer">Issuer / bank</label><input id="card-issuer" name="issuer" maxlength="120" value="<?= $escapeCard($cardForm['issuer']) ?>" placeholder="e.g. Chase"></div>
            <div class="account-input"><label for="card-apr">Purchase APR (%)</label><input id="card-apr" name="apr" type="number" inputmode="decimal" min="0" max="100" step="0.001" value="<?= $escapeCard($cardForm['apr']) ?>" placeholder="e.g. 24.99"></div>
            <div class="account-input"><label for="card-limit">Credit limit ($)</label><input id="card-limit" name="credit_limit" type="number" inputmode="decimal" min="0" step="0.01" value="<?= $escapeCard($cardForm['credit_limit']) ?>" placeholder="e.g. 5000"></div>
            <div class="account-input"><label for="card-fee">Annual fee ($)</label><input id="card-fee" name="annual_fee" type="number" inputmode="decimal" min="0" step="0.01" value="<?= $escapeCard($cardForm['annual_fee']) ?>" placeholder="e.g. 0"></div>
            <div class="account-input"><label for="card-statement">Statement closing day</label><input id="card-statement" name="statement_day" type="number" inputmode="numeric" min="1" max="31" step="1" value="<?= $escapeCard($cardForm['statement_day'] ?: '') ?>" placeholder="Day of month"></div>
            <div class="account-input"><label for="card-notes">Notes</label><textarea id="card-notes" name="notes" maxlength="4000" rows="4" placeholder="Rewards, promotional APR terms, or other card information"><?= $escapeCard($cardForm['notes']) ?></textarea></div>
        </div>
        <p class="subtitle">Leave unknown values blank. Enter 0 for a zero APR or no annual fee.</p>
        <button type="submit">Save card details</button>
    </form>
    <p class="detail-nav"><a href="finance.php?finance_scope=<?= $financeScope ?>&amp;page=payment">Manage payment day and planned payments</a></p>
</section>
<section class="panel" aria-labelledby="card-interest-title">
    <h2 class="stage-title" id="card-interest-title">Projected interest</h2>
    <p class="subtitle">A rough estimate assuming your balance is accruing interest at the saved purchase APR.</p>
    <?php if ($interest['daily'] === null): ?>
        <p class="empty"><?= $detailCard['apr'] === null ? 'Add a purchase APR above to estimate interest.' : 'Record a balance in Daily Check-In to estimate interest.' ?></p>
    <?php else: ?>
        <div class="summary-grid">
            <div class="metric"><span>Interest per day</span><strong><?= $escapeCard(formatMoney($interest['daily'])) ?></strong></div>
            <div class="metric"><span>30 days at this balance</span><strong><?= $escapeCard(formatMoney($interest['daily'] * 30)) ?></strong></div>
            <?php if ($interest['cycle'] !== null): $cycle = $interest['cycle']; ?>
                <div class="metric"><span>Estimated through today<?= $cycle['missing_days'] > 0 ? ' (partial)' : '' ?></span><strong><?= $escapeCard(formatMoney($cycle['to_date'])) ?></strong></div>
                <div class="metric"><span>Additional interest through close</span><strong><?= $escapeCard(formatMoney($cycle['remaining'])) ?></strong></div>
                <div class="metric"><span>Projected statement interest</span><strong><?= $cycle['total'] === null ? 'Incomplete history' : $escapeCard(formatMoney($cycle['total'])) ?></strong></div>
            <?php endif; ?>
        </div>
        <p class="subtitle">Based on <?= $escapeCard(formatMoney($interest['balance'])) ?> recorded <?= $escapeCard($interest['balance_date']) ?> at <?= $escapeCard($detailCard['apr']) ?>% APR. Future days assume this balance stays unchanged.</p>
        <?php if ($interest['cycle'] === null): ?>
            <p class="notice">Add a statement closing day above to see the estimate for your billing cycle.</p>
        <?php else: ?>
            <p class="subtitle">Statement cycle: <?= $escapeCard($cycle['start']) ?> through <?= $escapeCard($cycle['close']) ?>. <?= (int)$cycle['remaining_days'] ?> day<?= $cycle['remaining_days'] === 1 ? '' : 's' ?> after today until close. Today's full day is included in the estimate through today.</p>
            <?php if ($cycle['missing_days'] > 0): ?><p class="notice">No balance was available for <?= (int)$cycle['missing_days'] ?> day<?= $cycle['missing_days'] === 1 ? '' : 's' ?> at the start of this cycle. The estimate through today covers only days with a known balance.</p><?php endif; ?>
        <?php endif; ?>
        <p class="subtitle">Uses recorded daily balances, carrying the last known balance forward between tallies, and APR ÷ 365. This assumes interest is already accruing; a purchase grace period may mean no interest. Promotional rates, fees, compounding, and future payments or purchases are not included.</p>
    <?php endif; ?>
</section>
<?php endif; ?>
