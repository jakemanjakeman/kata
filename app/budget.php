<?php
declare(strict_types=1);
$budgetConfig = budgetConfiguration($data, $financeScope);
$budgetCategories = budgetCategoryOptions($budgetConfig);
$budgetAmounts = $data['budgets'][$financeScope] ?? [];
$annualIncome = 0.0;
foreach ($data['incomes'] as $income) {
    $annualIncome += (float)$income['amount'] * ($income['cadence'] === 'biweekly' ? 26 : 12);
}
$annualIncome = round($annualIncome, 2);
$monthlyIncome = $annualIncome / 12;
$budgetTotal = 0.0;
foreach ($budgetCategories as $category => $label) {
    $budgetTotal += (float)($budgetAmounts[$category] ?? 0);
}
?>
<p class="detail-nav"><a href="finance.php?page=budget&amp;categories=1&amp;finance_scope=<?= $financeScope ?>">Configure categories &amp; parent buckets</a></p>
<section class="summary-panel" aria-labelledby="budget-summary-title">
    <h2 class="stage-title" id="budget-summary-title"><?= ucfirst($financeScope) ?> Annual Outlook</h2>
    <div class="summary-grid">
        <div class="metric"><span>Annual income</span><strong><?= formatMoney($annualIncome) ?></strong></div>
        <div class="metric"><span>Annual spending &amp; savings</span><strong id="budget-total"><?= formatMoney($budgetTotal) ?></strong></div>
        <div class="metric" id="budget-remaining-metric"><span id="budget-remaining-label"><?= $budgetTotal > $annualIncome ? 'Over annual budget' : 'Left to allocate this year' ?></span><strong id="budget-remaining"><?= formatMoney(abs($annualIncome - $budgetTotal)) ?></strong></div>
    </div>
    <p>A full-year plan based on your saved income: monthly pay × 12 and biweekly pay × 26.</p>
    <a href="finance.php?finance_scope=<?= $financeScope ?>&amp;income=1">Manage income</a>
    <?php if ($data['incomes'] === []): ?><p class="empty">Add your income to see how much you have available to budget.</p><?php endif; ?>
</section>
<section class="panel" aria-labelledby="budget-categories-title">
    <h2 class="stage-title" id="budget-categories-title">1. Set your annual buckets</h2>
    <p>Enter the full year's amount for each category, including holidays, birthdays, and vacations. For regular expenses, multiply your monthly estimate by 12. Include bills in these buckets; bills are not deducted separately.</p>
    <p>Existing monthly budgets are shown as annual amounts with the same monthly allowance. Leave a bucket blank to budget $0.</p>
    <?php if ($error !== ''): ?><p class="notice" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    <?php if (($_GET['saved'] ?? '') === '1' && $error === ''): ?><p class="notice" role="status">Budget saved.</p><?php endif; ?>
    <form method="post" action="finance.php?page=budget&amp;finance_scope=<?= $financeScope ?>" id="budget-form">
        <input type="hidden" name="action" value="save_budget">
        <input type="hidden" name="finance_scope" value="<?= $financeScope ?>">
        <?php foreach ($budgetConfig['buckets'] as $bucketId => $bucketName): ?>
        <?php
        $bucketCategories = array_filter($budgetConfig['categories'], static fn(array $item): bool => $item['bucket'] === $bucketId);
        $bucketTotal = array_sum(array_map(static fn(array $item): float => (float)($budgetAmounts[$item['id']] ?? 0), $bucketCategories));
        ?>
        <section data-budget-bucket>
        <h3 class="stage-title"><?= htmlspecialchars($bucketName, ENT_QUOTES, 'UTF-8') ?></h3>
        <p data-bucket-total aria-live="polite"><?= formatMoney($bucketTotal) ?> / year · <?= formatMoney($bucketTotal / 12) ?> / month · <?= number_format($budgetTotal > 0 ? $bucketTotal / $budgetTotal * 100 : 0, 1) ?>% of budget</p>
        <div class="summary-grid">
            <?php foreach ($bucketCategories as $categoryConfig): ?>
                <?php
                $category = $categoryConfig['id'];
                $label = $categoryConfig['name'];
                $value = $error !== '' ? ($_POST['budget_amounts'][$category] ?? '') : ($budgetAmounts[$category] ?? '');
                $value = is_scalar($value) ? (string)$value : '';
                ?>
                <div class="account-input">
                    <label for="budget-<?= $category ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?> ($ / year)</label>
                    <input id="budget-<?= $category ?>" name="budget_amounts[<?= $category ?>]" type="number" min="0" max="99999999999.99" step="0.01" inputmode="decimal" placeholder="0.00" value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" data-budget-amount aria-describedby="monthly-<?= $category ?>">
                    <p id="monthly-<?= $category ?>" data-monthly-amount><?= formatMoney(is_numeric($value) ? (float)$value / 12 : 0.0) ?> / month</p>
                </div>
            <?php endforeach; ?>
        </div>
        </section>
        <?php endforeach; ?>
        <p id="budget-status" role="status" aria-live="polite"></p>
        <button type="submit">Save Annual Budget</button>
    </form>
</section>
<section class="summary-panel" aria-labelledby="monthly-outlook-title">
    <h2 class="stage-title" id="monthly-outlook-title">2. Your Monthly Outlook</h2>
    <div class="summary-grid">
        <div class="metric"><span>Average monthly income</span><strong><?= formatMoney($monthlyIncome) ?></strong></div>
        <div class="metric"><span>Monthly bucket funding</span><strong id="monthly-total"><?= formatMoney($budgetTotal / 12) ?></strong></div>
        <div class="metric" id="monthly-remaining-metric"><span id="monthly-remaining-label"><?= $budgetTotal > $annualIncome ? 'Monthly shortfall' : 'Left to allocate each month' ?></span><strong id="monthly-remaining"><?= formatMoney(abs($annualIncome - $budgetTotal) / 12) ?></strong></div>
    </div>
    <p>Each annual bucket is spread across 12 months. For example, $1,200 for holidays means setting aside $100 per month.</p>
    <p>These are average monthly funding amounts. Actual paydays and event spending can vary by month; this plan does not schedule expenses or account for money already saved. Rounded category amounts may differ from the total by a few cents.</p>
</section>
<script>
(() => {
    const form = document.getElementById('budget-form');
    const inputs = [...form.querySelectorAll('[data-budget-amount]')];
    const income = <?= json_encode($annualIncome) ?>;
    const money = new Intl.NumberFormat('en-US', {style: 'currency', currency: 'USD'});
    function updateBudget(edited = false) {
        const total = inputs.reduce((sum, input) => sum + Math.round((Number(input.value) || 0) * 100), 0) / 100;
        const remaining = Math.round((income - total) * 100) / 100;
        form.querySelectorAll('[data-budget-bucket]').forEach(bucket => {
            const amount = [...bucket.querySelectorAll('[data-budget-amount]')].reduce((sum, input) => sum + Math.round((Number(input.value) || 0) * 100), 0) / 100;
            const share = total > 0 ? amount / total * 100 : 0;
            bucket.querySelector('[data-bucket-total]').textContent = money.format(amount) + ' / year · ' + money.format(amount / 12) + ' / month · ' + share.toFixed(1) + '% of budget';
        });
        inputs.forEach(input => {
            input.parentElement.querySelector('[data-monthly-amount]').textContent = money.format((Number(input.value) || 0) / 12) + ' / month';
        });
        document.getElementById('budget-total').textContent = money.format(total);
        document.getElementById('budget-remaining').textContent = money.format(Math.abs(remaining));
        document.getElementById('budget-remaining-label').textContent = remaining < 0 ? 'Over annual budget' : 'Left to allocate this year';
        document.getElementById('budget-remaining-metric').className = 'metric ' + (remaining < 0 ? 'is-net-negative' : 'is-net-positive');
        document.getElementById('monthly-total').textContent = money.format(total / 12);
        document.getElementById('monthly-remaining').textContent = money.format(Math.abs(remaining) / 12);
        document.getElementById('monthly-remaining-label').textContent = remaining < 0 ? 'Monthly shortfall' : 'Left to allocate each month';
        document.getElementById('monthly-remaining-metric').className = 'metric ' + (remaining < 0 ? 'is-net-negative' : 'is-net-positive');
        document.getElementById('budget-status').textContent = (remaining < 0 ? 'Your annual buckets exceed annual income. ' : '') + (edited ? 'You have unsaved changes.' : '');
    }
    form.addEventListener('input', () => updateBudget(true));
    updateBudget();
})();
</script>
