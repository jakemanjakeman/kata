<?php
declare(strict_types=1);
require __DIR__ . '/../app/expenses-audit.php';
function auditCheck(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
function auditReject(callable $call): void {
    try { $call(); } catch (RuntimeException $e) { return; }
    throw new RuntimeException('Expected rejection');
}
$path = sys_get_temp_dir() . '/kata-audit-' . bin2hex(random_bytes(6)) . '.json';
$accounts = [['id' => 'bank', 'name' => 'Bank']];
$categories = ['uncategorized' => 'Uncategorized'];
$row = ['name' => 'Lunch', 'type' => 'expense', 'amount' => '10.10', 'account_id' => 'bank', 'category' => 'uncategorized'];
try {
    $averageFixture = ['personal' => [
        '2026-01' => ['items' => [['type'=>'expense','category'=>'rent','amount_cents'=>100001], ['type'=>'expense','category'=>'food','amount_cents'=>30000], ['type'=>'income','category'=>'food','amount_cents'=>900000]]],
        '2026-02' => ['items' => [['type'=>'expense','category'=>'rent','amount_cents'=>100000]]],
        '2026-03' => ['items' => []],
        '2025-12' => ['items' => [['type'=>'expense','category'=>'travel','amount_cents'=>999999]]],
    ], 'business' => ['2026-01' => ['items' => [['type'=>'expense','category'=>'travel','amount_cents'=>999999]]]]];
    $averages = expensesAuditCategoryAverages($averageFixture, 'personal', 2026);
    auditCheck($averages['months'] === 2 && count($averages['categories']) === 2, 'Averages exclude missing/empty months, other years and scopes');
    auditCheck($averages['categories'][0]['category'] === 'rent' && $averages['categories'][0]['average_cents'] === 100001, 'Most costly category is first, rounded to cents');
    auditCheck($averages['categories'][1]['average_cents'] === 15000, 'Income excluded; category absence counts as zero');
    $averageFixture['personal']['2026-04'] = ['items'=>[['type'=>'income','category'=>'food','amount_cents'=>100000]]];
    auditCheck(expensesAuditCategoryAverages($averageFixture,'personal',2026)['categories'][1]['average_cents'] === 10000, 'Income-only audited months count in the denominator');
    auditCheck(expensesAuditCategoryAverages([], 'personal', 2026) === ['months'=>0,'categories'=>[]], 'Empty averages avoid division by zero');
    $manyCategories = [];
    for ($i = 1; $i <= 7; $i++) { $manyCategories[] = ['type'=>'expense','category'=>'category'.$i,'amount_cents'=>$i * 100]; }
    $top = expensesAuditCategoryAverages(['personal'=>['2026-01'=>['items'=>$manyCategories]]], 'personal', 2026);
    auditCheck(count($top['categories']) === 5 && $top['categories'][4]['category'] === 'category3', 'Only five most costly categories shown');
    auditCheck(auditMonthValid('2026-02') && !auditMonthValid('2026-13') && !auditMonthValid('26-02'), 'Month validation');
    $items = validateAuditItems([$row, array_merge($row, ['type' => 'income', 'amount' => '100.30'])], $accounts, $categories);
    $report = expensesAuditReport($items);
    $dated = validateAuditItems([array_merge($row, ['date' => '2026-09-30'])], $accounts, $categories, [], '2026-09');
    auditCheck($dated[0]['date'] === '2026-09-30', 'Item date is retained');
    auditCheck($items[0]['date'] === '', 'Legacy undated items stay undated');
    foreach (['2026-09-31', '2026-10-01', '2026-9-01', 'not-a-date'] as $badDate) {
        auditReject(function () use ($row, $badDate, $accounts, $categories) { validateAuditItems([array_merge($row, ['date' => $badDate])], $accounts, $categories, [], '2026-09'); });
    }
    auditCheck($report['net'] === 9020 && $report['accounts']['bank']['expense'] === 1010, 'Exact cents and breakdown');
    foreach ([['amount' => '-1'], ['amount' => '1.001'], ['amount' => 'NaN'], ['account_id' => 'business'], ['category' => 'invalid'], ['type' => 'other'], ['name' => '']] as $bad) {
        auditReject(function () use ($row, $bad, $accounts, $categories) { validateAuditItems([array_merge($row, $bad)], $accounts, $categories); });
    }
    $items[0]['date'] = $dated[0]['date'];
    saveExpensesAudit($path, 'personal', '2026-09', $items, '');
    $old = loadExpensesAudits($path)['personal']['2026-09'];
    auditCheck($old['items'][0]['date'] === '2026-09-30', 'Dates survive persistence');
    saveExpensesAudit($path, 'business', '2026-09', [], '');
    saveExpensesAudit($path, 'personal', '2026-10', [], '');
    auditReject(function () use ($path, $items) { saveExpensesAudit($path, 'personal', '2026-09', $items, ''); });
    saveExpensesAudit($path, 'personal', '2026-09', [$items[0]], $old['revision']);
    $all = loadExpensesAudits($path);
    auditCheck(count($all['personal']['2026-09']['items']) === 1 && isset($all['business']['2026-09'], $all['personal']['2026-10']), 'Edits preserve other scopes and months');
    auditCheck(validateAuditItems([$row], [], $categories, $items)[0]['account_name'] === 'Bank', 'Historical accounts remain editable');
    // Render the real view with fixture data, never loading personal finance data.
    require __DIR__ . '/../app/finance-scope.php';
    function billCategoryOptions(): array { return ['uncategorized' => 'Uncategorized']; }
    function formatMoney(float $amount): string { return '$' . number_format($amount, 2); }
    $financeScope = 'personal'; $auditMonth = '2026-09'; $data = ['accounts' => $accounts];
    $isAuthenticated = true; $auditError = ''; $audits = $all;
    $audit = $all['personal']['2026-09']; $auditRevision = $audit['revision'];
    $auditRows = [array_merge($row, ['name' => '<script>alert(1)</script>'])];
    $_SESSION = ['audit_csrf' => 'fixture']; $_GET = [];
    foreach ([true, false] as $auditEditing) {
        ob_start(); require __DIR__ . '/../app/expenses-audit-view.php'; $html = ob_get_clean();
        auditCheck(strpos($html, $auditEditing ? 'Save &amp; view report' : 'Net income') !== false, 'Editor and report render');
        auditCheck(strpos($html, $auditEditing ? 'data-field="date" type="date" min="2026-09-01" max="2026-09-30"' : '<td>2026-09-30</td>') !== false, 'Editor date limits and report date render');
        auditCheck(strpos($html, '<script>alert(1)</script>') === false, 'Descriptions cannot inject scripts');
    }
    $isAuthenticated = false;
    ob_start(); require __DIR__ . '/../app/expenses-audit-view.php'; $html = ob_get_clean();
    auditCheck(strpos($html, 'Lunch') === false && strpos($html, 'Unlock Money Kata') !== false, 'Locked view hides audit details');
    $beforeMove = loadExpensesAudits($path);
    $sourceAudit = $beforeMove['personal']['2026-09'];
    auditReject(function () use ($path, $sourceAudit) { saveExpensesAudit($path, 'personal', '2026-10', [], $sourceAudit['revision'], '2026-09'); });
    auditReject(function () use ($path) { saveExpensesAudit($path, 'personal', '2026-11', [], 'stale', '2026-09'); });
    auditReject(function () use ($path, $sourceAudit) { saveExpensesAudit($path, 'personal', '2026-13', [], $sourceAudit['revision'], '2026-09'); });
    auditCheck(loadExpensesAudits($path) === $beforeMove, 'Rejected moves leave all audits untouched');
    saveExpensesAudit($path, 'personal', '2026-11', [], $sourceAudit['revision'], '2026-09');
    $afterMove = loadExpensesAudits($path);
    auditCheck(!isset($afterMove['personal']['2026-09']) && isset($afterMove['personal']['2026-11']), 'Move replaces source month with destination');
    auditCheck($afterMove['personal']['2026-11']['items'] === auditItemsForMonth($sourceAudit['items'], '2026-11'), 'Move retains items and shifts dates');
    auditCheck($afterMove['business'] === $beforeMove['business'] && $afterMove['personal']['2026-10'] === $beforeMove['personal']['2026-10'], 'Move preserves other scopes and months');
    auditCheck($afterMove['personal']['2026-11']['created_at'] === $sourceAudit['created_at'] && $afterMove['personal']['2026-11']['revision'] !== $sourceAudit['revision'], 'Move preserves creation time and refreshes revision');
    auditReject(function () use ($path, $sourceAudit) { saveExpensesAudit($path, 'personal', '2026-12', [], $sourceAudit['revision'], '2026-09'); });
    auditReject(function () use ($path, $sourceAudit) { saveExpensesAudit($path, 'personal', '2026-09', $sourceAudit['items'], $sourceAudit['revision']); });
    $dates = [['date' => '2026-01-31'], ['date' => '2026-01-15'], ['date' => ''], ['name' => 'Legacy']];
    $shifted = auditItemsForMonth($dates, '2028-02');
    auditCheck($shifted[0]['date'] === '2028-02-29' && $shifted[1]['date'] === '2028-02-15' && $shifted[2]['date'] === '' && !isset($shifted[3]['date']), 'Leap years and undated items are handled');
    auditCheck(auditItemsForMonth($dates, '2027-02')[0]['date'] === '2027-02-28', 'Shorter months clamp to last day');
    file_put_contents($path, '{broken');
    auditReject(function () use ($path) { saveExpensesAudit($path, 'personal', '2026-09', [], ''); });
    auditCheck(file_get_contents($path) === '{broken', 'Corrupt file is never overwritten');
    echo "Expenses audit tests passed.\n";
} finally {
    if (is_file($path)) { unlink($path); }
    if (is_file($path . '.lock')) { unlink($path . '.lock'); }
}
