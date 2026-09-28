<?php
declare(strict_types=1);
$budgetCategories = billCategoryOptions() + ['savings' => 'Savings', 'other' => 'Other'];
$budgetAmounts = $data['budgets'][$financeScope] ?? [];
$monthlyIncome = 0.0;
foreach ($data['incomes'] as $income) {
    $monthlyIncome += (float)$income['amount'] * ($income['cadence'] === 'biweekly' ? 26 / 12 : 1);
}
$monthlyIncome = round($monthlyIncome, 2);
$budgetTotal = 0.0;
foreach ($budgetCategories as $category => $label) {
    $budgetTotal += (float)($budgetAmounts[$category] ?? 0);
}
?>
<section class="summary-panel" aria-labelledby="budget-summary-title">
    <h2 class="stage-title" id="budget-summary-title"><?= ucfirst($financeScope) ?> Monthly Budget</h2>
    <div class="summary-grid">
        <div class="metric"><span>Monthly income</span><strong><?= formatMoney($monthlyIncome) ?></strong></div>
        <div class="metric"><span>Planned spending &amp; savings</span><strong id="budget-total"><?= formatMoney($budgetTotal) ?></strong></div>
        <div class="metric" id="budget-remaining-metric"><span id="budget-remaining-label"><?= $budgetTotal > $monthlyIncome ? 'Over budget' : 'Left to allocate' ?></span><strong id="budget-remaining"><?= formatMoney(abs($monthlyIncome - $budgetTotal)) ?></strong></div>
    </div>
    <p>Based on your saved income sources. Biweekly pay is averaged using 26 payments per year; actual monthly income can vary.</p>
    <a href="finance.php?finance_scope=<?= $financeScope ?>&amp;income=1">Manage income</a>
    <?php if ($data['incomes'] === []): ?><p class="empty">Add your income to see how much you have available to budget.</p><?php endif; ?>
</section>
<section class="panel" aria-labelledby="budget-categories-title">
    <h2 class="stage-title" id="budget-categories-title">Monthly category amounts</h2>
    <p>Include bills and everyday spending in these amounts. Bills are not deducted separately. Leave a category blank to budget $0.</p>
    <?php if ($error !== ''): ?><p class="notice" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
    <?php if (($_GET['saved'] ?? '') === '1' && $error === ''): ?><p class="notice" role="status">Budget saved.</p><?php endif; ?>
    <form method="post" action="finance.php?page=budget&amp;finance_scope=<?= $financeScope ?>" id="budget-form">
        <input type="hidden" name="action" value="save_budget">
        <input type="hidden" name="finance_scope" value="<?= $financeScope ?>">
        <div class="summary-grid">
            <?php foreach ($budgetCategories as $category => $label): ?>
                <?php
                $value = $error !== '' ? ($_POST['budget_amounts'][$category] ?? '') : ($budgetAmounts[$category] ?? '');
                $value = is_scalar($value) ? (string)$value : '';
                ?>
                <div class="field">
                    <label for="budget-<?= $category ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?> ($)</label>
                    <input id="budget-<?= $category ?>" name="budget_amounts[<?= $category ?>]" type="number" min="0" max="999999999.99" step="0.01" inputmode="decimal" placeholder="0.00" value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" data-budget-amount>
                </div>
            <?php endforeach; ?>
        </div>
        <p id="budget-status" role="status" aria-live="polite"></p>
        <button type="submit">Save Budget</button>
    </form>
</section>
<script>
(() => {
    const form = document.getElementById('budget-form');
    const inputs = [...form.querySelectorAll('[data-budget-amount]')];
    const income = <?= json_encode($monthlyIncome) ?>;
    const money = new Intl.NumberFormat('en-US', {style: 'currency', currency: 'USD'});
    function updateBudget(edited = false) {
        const total = inputs.reduce((sum, input) => sum + Math.round((Number(input.value) || 0) * 100), 0) / 100;
        const remaining = Math.round((income - total) * 100) / 100;
        document.getElementById('budget-total').textContent = money.format(total);
        document.getElementById('budget-remaining').textContent = money.format(Math.abs(remaining));
        document.getElementById('budget-remaining-label').textContent = remaining < 0 ? 'Over budget' : 'Left to allocate';
        document.getElementById('budget-remaining-metric').className = 'metric ' + (remaining < 0 ? 'is-net-negative' : 'is-net-positive');
        document.getElementById('budget-status').textContent = (remaining < 0 ? 'Your plan exceeds monthly income. ' : '') + (edited ? 'You have unsaved changes.' : '');
    }
    form.addEventListener('input', () => updateBudget(true));
    updateBudget();
})();
</script>
