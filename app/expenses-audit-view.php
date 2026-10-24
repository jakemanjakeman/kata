<?php
$auditEscape = static function ($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); };
$auditUrl = financeScopeUrl('finance.php?page=audit&month=' . rawurlencode($auditMonth));
$auditAccounts = array_column($data['accounts'], 'name', 'id');
foreach ($audit['items'] ?? [] as $item) {
    if (!isset($auditAccounts[$item['account_id']])) { $auditAccounts[$item['account_id']] = $item['account_name'] . ' (previous account)'; }
}
$auditCategories = billCategoryOptions();
?>
<style>
.audit-toolbar { display:flex; flex-wrap:wrap; align-items:end; gap:12px; margin:16px 0; }
.audit-toolbar label,.audit-item label { position:static; width:auto; height:auto; overflow:visible; clip:auto; display:block; font-size:.85rem; }
.audit-toolbar label { margin-bottom:8px; }
.audit-items { display:grid; gap:12px; margin:20px 0; }
.audit-item { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:10px; align-items:end; border-bottom:1px solid var(--line); padding-bottom:16px; }
.audit-item input,.audit-item select,#audit-month { width:100%; min-width:0; min-height:48px; border:1px solid var(--line); border-radius:6px; padding:0 14px; background:var(--panel); color:var(--ink); font:inherit; outline:none; }
.audit-item input,.audit-item select { margin-top:8px; }
.audit-item input:focus,.audit-item select:focus,#audit-month:focus { border-color:var(--accent); box-shadow:0 0 0 3px var(--focus-ring); }
@media(max-width:900px) { .audit-item { grid-template-columns:repeat(2,minmax(0,1fr)); } }
@media(max-width:480px) { .audit-item { grid-template-columns:minmax(0,1fr); } }
@media print { .primary-nav,.global-focus,.toggle-pills,.audit-toolbar,.audit-history,[data-theme-toggle] { display:none!important; } .app-shell { max-width:none; padding:0; } .panel { break-inside:avoid; } }
</style>
<?php if (!$isAuthenticated): ?>
<p class="empty">Unlock Money Kata to view your audits.</p>
<?php else: ?>
<p class="subtitle">Audits are for review only. Adding, editing, or removing audit items does not change daily check-in records, account balances, recurring bills or income, or forecasts.</p>
<form class="audit-toolbar" method="get">
    <input type="hidden" name="page" value="audit"><input type="hidden" name="finance_scope" value="<?= $financeScope ?>">
    <div><label for="audit-month">Month to audit</label><input id="audit-month" type="month" name="month" value="<?= $auditEscape($auditMonth) ?>" required></div>
    <button type="submit">Open month</button>
</form>
<?php if ($auditError !== ''): ?><p class="error" role="alert"><?= $auditEscape($auditError) ?></p><?php endif; ?>
<?php if (isset($_GET['saved']) && $auditError === ''): ?><p class="notice" role="status">Audit saved.</p><?php endif; ?>
<?php if ($auditEditing): ?>
<section class="panel">
<h2 class="stage-title"><?= $audit ? 'Edit' : 'New' ?> audit &middot; <?= $auditEscape(date('F Y', strtotime($auditMonth . '-01'))) ?></h2>
<p class="subtitle">Enter a description and amount, then select an account and category. Use + Expense or + Income to add more items.</p>
<?php if ($auditAccounts === []): ?><p>Add an account on the <a href="<?= $auditEscape(financeScopeUrl('finance.php?page=accounts')) ?>">Accounts page</a> to record items.</p><?php endif; ?>
<form method="post" action="<?= $auditEscape($auditUrl) ?>" id="audit-form">
<input type="hidden" name="action" value="save_audit"><input type="hidden" name="finance_scope" value="<?= $financeScope ?>">
<input type="hidden" name="audit_month" value="<?= $auditEscape($auditMonth) ?>"><input type="hidden" name="audit_revision" value="<?= $auditEscape($auditRevision) ?>"><input type="hidden" name="audit_csrf" value="<?= $auditEscape($_SESSION['audit_csrf']) ?>">
<div class="audit-items" id="audit-items"></div>
<div class="audit-toolbar"><button type="button" class="secondary-button" id="audit-add-expense">+ Expense</button><button type="button" class="secondary-button" id="audit-add-income">+ Income</button><button type="submit">Save &amp; view report</button><?php if ($audit): ?><a href="<?= $auditEscape($auditUrl) ?>">Cancel</a><?php endif; ?></div>
<input type="hidden" name="audit_complete" value="1">
</form>
<template id="audit-item-template"><div class="audit-item">
<label>Type<select data-field="type"><option value="expense">Expense</option><option value="income">Income</option></select></label>
<label>Date (optional)<input data-field="date" type="date" min="<?= $auditEscape($auditMonth . '-01') ?>" max="<?= $auditEscape(date('Y-m-t', strtotime($auditMonth . '-01'))) ?>"></label>
<label>Description<input data-field="name" maxlength="240" placeholder="e.g. Grocery trip" required></label>
<label>Amount ($)<input data-field="amount" type="number" min="0.01" max="999999999.99" step="0.01" inputmode="decimal" required></label>
<label>Account<select data-field="account_id" required><option value="">Select account</option><?php foreach ($auditAccounts as $id => $name): ?><option value="<?= $auditEscape($id) ?>"><?= $auditEscape($name) ?></option><?php endforeach; ?></select></label>
<label>Category<select data-field="category"><?php foreach ($auditCategories as $id => $name): ?><option value="<?= $auditEscape($id) ?>"><?= $auditEscape($name) ?></option><?php endforeach; ?></select></label>
<button type="button" class="secondary-button" data-remove>Remove</button>
</div></template>
<noscript><p>Enable JavaScript to add and edit audit items.</p></noscript>
<script>
(() => {
    const list = document.getElementById('audit-items');
    let next = 0, dirty = false;
    function add(row = {}, focus = false) {
        const item = document.getElementById('audit-item-template').content.firstElementChild.cloneNode(true);
        item.querySelectorAll('[data-field]').forEach(input => { const field = input.dataset.field; input.name = `items[${next}][${field}]`; input.value = row[field] ?? (field === 'type' ? 'expense' : field === 'category' ? 'uncategorized' : ''); });
        item.querySelector('[data-remove]').addEventListener('click', () => { item.remove(); dirty = true; });
        next++; list.append(item);
        if (focus) { item.querySelector('[data-field="name"]').focus(); dirty = true; }
    }
    const rows = <?= json_encode($auditRows, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    rows.forEach(row => add(row));
    if (rows.length === 0) { add({type: 'expense'}); }
    document.getElementById('audit-add-expense').onclick = () => add({type:'expense'}, true);
    document.getElementById('audit-add-income').onclick = () => add({type:'income'}, true);
    document.getElementById('audit-form').addEventListener('input', () => { dirty = true; });
    document.getElementById('audit-form').addEventListener('submit', () => { dirty = false; });
    window.addEventListener('beforeunload', event => { if (dirty) { event.preventDefault(); event.returnValue = ''; } });
})();
</script>
</section>
<?php else: $report = expensesAuditReport($audit['items']); ?>
<section class="panel">
<h2 class="stage-title"><?= $auditEscape(date('F Y', strtotime($auditMonth . '-01'))) ?> &middot; <?= ucfirst($financeScope) ?> income &amp; expenses</h2>
<p class="subtitle">Last saved <?= $auditEscape(date('M j, Y g:i a', strtotime($audit['updated_at']))) ?></p>
<div class="summary-grid"><?php foreach (['income' => 'Total income', 'expense' => 'Total expenses', 'net' => 'Net income'] as $key => $label): ?><div class="metric"><span><?= $label ?></span><strong><?= $auditEscape(formatMoney($report[$key] / 100)) ?></strong></div><?php endforeach; ?></div>
<div class="audit-toolbar"><a class="secondary-button" href="<?= $auditEscape($auditUrl . '&edit=1') ?>">Edit audit</a><button type="button" onclick="window.print()">Print / Save PDF</button></div>
<?php if ($audit['items'] === []): ?><p class="empty">No items recorded. Edit this audit to add income or expenses.</p><?php else: ?>
<?php foreach (['categories' => 'Category', 'accounts' => 'Account'] as $group => $label): ?>
<h3>By <?= strtolower($label) ?></h3><div class="table-wrap"><table class="bill-table"><thead><tr><th><?= $label ?></th><th>Income</th><th>Expenses</th><th>Net</th></tr></thead><tbody>
<?php foreach ($report[$group] as $id => $totals): ?><tr><td><?= $auditEscape($group === 'categories' ? ($auditCategories[$id] ?? $id) : ($auditAccounts[$id] ?? $id)) ?></td><td><?= formatMoney($totals['income'] / 100) ?></td><td><?= formatMoney($totals['expense'] / 100) ?></td><td><?= formatMoney(($totals['income'] - $totals['expense']) / 100) ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endforeach; ?>
<h3>All items</h3><div class="table-wrap"><table class="bill-table"><thead><tr><th>Date</th><th>Description</th><th>Type</th><th>Account</th><th>Category</th><th>Amount</th></tr></thead><tbody>
<?php foreach ($audit['items'] as $item): ?><tr><td><?= $auditEscape(($item['date'] ?? '') !== '' ? $item['date'] : 'Not recorded') ?></td><td><?= $auditEscape($item['name']) ?></td><td><?= ucfirst($item['type']) ?></td><td><?= $auditEscape($auditAccounts[$item['account_id']] ?? $item['account_name']) ?></td><td><?= $auditEscape($auditCategories[$item['category']] ?? $item['category']) ?></td><td><?= formatMoney($item['amount_cents'] / 100) ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>
</section>
<?php endif; ?>
<section class="panel audit-history"><h2 class="stage-title">Saved audits &middot; <?= ucfirst($financeScope) ?></h2>
<?php $auditHistory = $audits[$financeScope] ?? []; krsort($auditHistory); ?>
<?php if (!$auditHistory): ?><p class="empty">Your saved monthly audits will appear here.</p><?php else: ?><ul><?php foreach ($auditHistory as $month => $savedAudit): ?><li><a href="<?= $auditEscape(financeScopeUrl('finance.php?page=audit&month=' . rawurlencode($month))) ?>"><?= $auditEscape(date('F Y', strtotime($month . '-01'))) ?></a></li><?php endforeach; ?></ul><?php endif; ?>
</section>
<?php endif; ?>
