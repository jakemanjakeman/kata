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
    auditCheck(auditMonthValid('2026-02') && !auditMonthValid('2026-13') && !auditMonthValid('26-02'), 'Month validation');
    $items = validateAuditItems([$row, array_merge($row, ['type' => 'income', 'amount' => '100.30'])], $accounts, $categories);
    $report = expensesAuditReport($items);
    auditCheck($report['net'] === 9020 && $report['accounts']['bank']['expense'] === 1010, 'Exact cents and breakdown');
    foreach ([['amount' => '-1'], ['amount' => '1.001'], ['amount' => 'NaN'], ['account_id' => 'business'], ['category' => 'invalid'], ['type' => 'other'], ['name' => '']] as $bad) {
        auditReject(function () use ($row, $bad, $accounts, $categories) { validateAuditItems([array_merge($row, $bad)], $accounts, $categories); });
    }
    saveExpensesAudit($path, 'personal', '2026-09', $items, '');
    $old = loadExpensesAudits($path)['personal']['2026-09'];
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
        auditCheck(strpos($html, '<script>alert(1)</script>') === false, 'Descriptions cannot inject scripts');
    }
    $isAuthenticated = false;
    ob_start(); require __DIR__ . '/../app/expenses-audit-view.php'; $html = ob_get_clean();
    auditCheck(strpos($html, 'Lunch') === false && strpos($html, 'Unlock Money Kata') !== false, 'Locked view hides audit details');
    file_put_contents($path, '{broken');
    auditReject(function () use ($path) { saveExpensesAudit($path, 'personal', '2026-09', [], ''); });
    auditCheck(file_get_contents($path) === '{broken', 'Corrupt file is never overwritten');
    echo "Expenses audit tests passed.\n";
} finally {
    if (is_file($path)) { unlink($path); }
    if (is_file($path . '.lock')) { unlink($path . '.lock'); }
}
