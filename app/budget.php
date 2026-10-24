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
<style>
    .budget-pies { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 20px; }
    .budget-pie-card { text-align: center; padding: 16px; border: 1px solid var(--line); border-radius: 8px; background: var(--panel); }
    .budget-pie-card h2 { font-size: 18px; margin: 0 0 4px; }
    .budget-pie-share { display: block; font-size: clamp(32px, 4vw, 48px); font-weight: 800; line-height: 1.1; margin-bottom: 14px; }
    .budget-bucket-pie { width: min(100%, 160px); aspect-ratio: 1; border-radius: 50%; margin: 0 auto; background: #cbd5e1; }
    .budget-pie-empty { margin: 10px 0 0; font-size: 13px; }
    .budget-bucket-pie svg { display: block; width: 100%; height: 100%; overflow: visible; }
    .budget-pie-slice { cursor: pointer; transition: filter .12s; }
    .budget-pie-slice:hover, .budget-pie-slice:focus { filter: brightness(1.18); stroke: var(--ink); stroke-width: 2; outline: none; }
    .budget-pie-tooltip { position: fixed; z-index: 1000; pointer-events: none; max-width: min(280px, calc(100vw - 24px)); padding: 10px 14px; border: 1px solid var(--line); border-radius: 8px; background: var(--panel); color: var(--ink); box-shadow: 0 6px 24px var(--shadow); font-weight: 700; }
    @media (max-width: 600px) {
        .budget-pies { display: flex; overflow-x: auto; gap: 10px; padding-bottom: 8px; }
        .budget-pie-card { flex: 0 0 150px; padding: 12px; }
        .budget-bucket-pie { width: 120px; }
    }
</style>
<div class="budget-pies" aria-label="Parent bucket shares of the allocated budget">
    <?php foreach ($budgetConfig['buckets'] as $bucketId => $bucketName): ?>
        <?php
        $pieCategories = array_values(array_filter($budgetConfig['categories'], static fn(array $item): bool => $item['bucket'] === $bucketId));
        $pieTotal = array_sum(array_map(static fn(array $item): float => max(0, (float)($budgetAmounts[$item['id']] ?? 0)), $pieCategories));
        $pieShare = $budgetTotal > 0 ? $pieTotal / $budgetTotal * 100 : 0;
        $pieStops = []; $piePosition = 0;
        foreach ($pieCategories as $index => $item) {
            $amount = max(0, (float)($budgetAmounts[$item['id']] ?? 0));
            if ($amount <= 0 || $pieTotal <= 0) { continue; }
            $end = $piePosition + $amount / $pieTotal * 100;
            $pieStops[] = 'hsl(' . (($index * 137 + 215) % 360) . ' 65% 52%) ' . number_format($piePosition, 6, '.', '') . '% ' . number_format($end, 6, '.', '') . '%';
            $piePosition = $end;
        }
        ?>
        <section class="budget-pie-card" data-bucket-chart="<?= htmlspecialchars($bucketId, ENT_QUOTES, 'UTF-8') ?>">
            <h2><?= htmlspecialchars($bucketName, ENT_QUOTES, 'UTF-8') ?></h2>
            <strong class="budget-pie-share" data-pie-share><?= number_format($pieShare, 1) ?>%</strong>
            <div class="budget-bucket-pie" data-pie role="img" aria-label="<?= htmlspecialchars($bucketName, ENT_QUOTES, 'UTF-8') ?>: <?= number_format($pieShare, 1) ?>% of budget; slices show subcategory allocations" style="background: <?= $pieStops === [] ? '#cbd5e1' : 'conic-gradient(' . implode(', ', $pieStops) . ')' ?>"></div>
            <p class="budget-pie-empty" data-pie-empty <?= $pieTotal > 0 ? 'hidden' : '' ?>>No budget allocated</p>
        </section>
    <?php endforeach; ?>
</div>
<div class="budget-pie-tooltip" id="budget-pie-tooltip" role="tooltip" hidden></div>
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
        <section data-budget-bucket="<?= htmlspecialchars($bucketId, ENT_QUOTES, 'UTF-8') ?>">
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
    const tooltip = document.getElementById('budget-pie-tooltip');
    function showSlice(event, slice, text) {
        tooltip.textContent = text;
        tooltip.hidden = false;
        const bounds = slice.getBoundingClientRect();
        const x = event.clientX ?? (bounds.left + bounds.width / 2);
        const y = event.clientY ?? bounds.bottom;
        tooltip.style.left = Math.max(12, Math.min(x + 12, window.innerWidth - tooltip.offsetWidth - 12)) + 'px';
        tooltip.style.top = Math.max(12, Math.min(y + 12, window.innerHeight - tooltip.offsetHeight - 12)) + 'px';
    }
    const hideTooltip = () => { tooltip.hidden = true; };
    window.addEventListener('scroll', hideTooltip, true);
    window.addEventListener('resize', hideTooltip);
    document.addEventListener('keydown', event => { if (event.key === 'Escape') hideTooltip(); });
    function renderPie(card, bucket, values, total) {
        const pie = card.querySelector('[data-pie]');
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 200 200');
        pie.removeAttribute('role');
        let position = 0;
        const fields = [...bucket.querySelectorAll('[data-budget-amount]')];
        values.forEach((value, index) => {
            if (!value || !total) return;
            const end = position + value / total * Math.PI * 2;
            const slice = document.createElementNS(svg.namespaceURI, value === total ? 'circle' : 'path');
            if (value === total) {
                slice.setAttribute('cx', '100'); slice.setAttribute('cy', '100'); slice.setAttribute('r', '98');
            } else {
                slice.setAttribute('d', `M 100 100 L ${100 + 98 * Math.sin(position)} ${100 - 98 * Math.cos(position)} A 98 98 0 ${end - position > Math.PI ? 1 : 0} 1 ${100 + 98 * Math.sin(end)} ${100 - 98 * Math.cos(end)} Z`);
            }
            position = end;
            slice.setAttribute('fill', `hsl(${(index * 137 + 215) % 360} 65% 52%)`);
            slice.setAttribute('class', 'budget-pie-slice');
            slice.setAttribute('tabindex', '0');
            const label = fields[index].labels[0].textContent.replace(/ \(\$ \/ year\)$/, '');
            const text = label + ': ' + money.format(value / 100) + ' / year';
            slice.setAttribute('aria-label', text);
            slice.setAttribute('aria-describedby', 'budget-pie-tooltip');
            slice.addEventListener('pointermove', event => showSlice(event, slice, text));
            slice.addEventListener('pointerleave', hideTooltip);
            slice.addEventListener('focus', event => showSlice(event, slice, text));
            slice.addEventListener('blur', hideTooltip);
            slice.addEventListener('click', event => showSlice(event, slice, text));
            svg.appendChild(slice);
        });
        pie.replaceChildren(svg);
        pie.style.background = total > 0 ? 'transparent' : '#cbd5e1';
    }
    function updateBudget(edited = false) {
        hideTooltip();
        const total = inputs.reduce((sum, input) => sum + Math.round((Number(input.value) || 0) * 100), 0) / 100;
        const remaining = Math.round((income - total) * 100) / 100;
        form.querySelectorAll('[data-budget-bucket]').forEach(bucket => {
            const amount = [...bucket.querySelectorAll('[data-budget-amount]')].reduce((sum, input) => sum + Math.round((Number(input.value) || 0) * 100), 0) / 100;
            const share = total > 0 ? amount / total * 100 : 0;
            const card = [...document.querySelectorAll('[data-bucket-chart]')].find(card => card.dataset.bucketChart === bucket.dataset.budgetBucket);
            const values = [...bucket.querySelectorAll('[data-budget-amount]')].map(input => Math.max(0, Math.round((Number(input.value) || 0) * 100)));
            const pieTotal = values.reduce((sum, value) => sum + value, 0);
            renderPie(card, bucket, values, pieTotal);
            card.querySelector('[data-pie-share]').textContent = share.toFixed(1) + '%';
            card.querySelector('[data-pie]').setAttribute('aria-label', card.querySelector('h2').textContent + ': ' + share.toFixed(1) + '% of budget; slices show subcategory allocations');
            card.querySelector('[data-pie-empty]').hidden = pieTotal > 0;
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
