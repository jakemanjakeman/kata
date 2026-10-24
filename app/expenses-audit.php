<?php
declare(strict_types=1);

function auditMonthValid(string $month): bool
{
    return preg_match('/^[1-9][0-9]{3}-(0[1-9]|1[0-2])$/D', $month) === 1;
}

function loadExpensesAudits(string $path): array
{
    if (!is_file($path)) { return []; }
    $raw = file_get_contents($path);
    $decoded = $raw === false ? null : json_decode($raw, true);
    if (!is_array($decoded)) { throw new RuntimeException('Could not read saved audits. The file has not been changed.'); }
    return $decoded;
}

function validateAuditItems(array $rows, array $accounts, array $categories, array $previous = []): array
{
    if (count($rows) > 1000) { throw new RuntimeException('Keep each audit to 1,000 items or fewer.'); }
    $accountNames = array_column($accounts, 'name', 'id');
    foreach ($previous as $item) {
        if (!isset($accountNames[$item['account_id']])) {
            $accountNames[$item['account_id']] = $item['account_name'];
        }
    }
    $items = [];
    foreach ($rows as $index => $row) {
        if (!is_array($row)) { throw new RuntimeException('Invalid audit item.'); }
        $name = trim((string)($row['name'] ?? ''));
        $amount = trim((string)($row['amount'] ?? ''));
        $type = (string)($row['type'] ?? 'expense');
        $account = (string)($row['account_id'] ?? '');
        $category = (string)($row['category'] ?? 'uncategorized');
        if ($name === '' && $amount === '' && $account === '' && $category === 'uncategorized') { continue; }
        if ($name === '' || strlen($name) > 240 || !in_array($type, ['income', 'expense'], true)
            || !preg_match('/^[0-9]{1,9}(\.[0-9]{1,2})?$/D', $amount) || (float)$amount <= 0
            || !isset($accountNames[$account]) || !isset($categories[$category])) {
            throw new RuntimeException('Each item needs a description (up to 240 characters), income or expense type, a positive amount with at most two decimal places, an account, and a category.');
        }
        $items[] = ['name' => $name, 'type' => $type, 'amount_cents' => (int)round((float)$amount * 100),
            'account_id' => $account, 'account_name' => $accountNames[$account], 'category' => $category];
    }
    return $items;
}

function saveExpensesAudit(string $path, string $scope, string $month, array $items, string $revision): void
{
    if (!in_array($scope, ['personal', 'business'], true) || !auditMonthValid($month)) {
        throw new RuntimeException('Choose a valid audit month.');
    }
    $lock = fopen($path . '.lock', 'c');
    if (!$lock) { throw new RuntimeException('Could not lock audits for saving.'); }
    $temporary = null;
    try {
        if (!flock($lock, LOCK_EX)) { throw new RuntimeException('Could not lock audits for saving.'); }
        $all = loadExpensesAudits($path);
        $old = $all[$scope][$month] ?? [];
        if (($old['revision'] ?? '') !== $revision) {
            throw new RuntimeException('This month was saved in another tab. Open its latest report before editing again. Your submitted items remain below.');
        }
        $all[$scope][$month] = ['items' => $items, 'revision' => bin2hex(random_bytes(16)),
            'created_at' => $old['created_at'] ?? date(DATE_ATOM), 'updated_at' => date(DATE_ATOM)];
        $json = json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $temporary = tempnam(dirname($path), 'audit-');
        if ($json === false || $temporary === false || file_put_contents($temporary, $json) === false || !rename($temporary, $path)) {
            throw new RuntimeException('Could not save the audit. Check that the data folder is writable.');
        }
    } finally {
        if (is_string($temporary) && is_file($temporary)) { unlink($temporary); }
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function expensesAuditReport(array $items): array
{
    $report = ['income' => 0, 'expense' => 0, 'net' => 0, 'categories' => [], 'accounts' => []];
    foreach ($items as $item) {
        $type = $item['type'];
        $amount = $item['amount_cents'];
        $report[$type] += $amount;
        foreach (['categories' => $item['category'], 'accounts' => $item['account_id']] as $group => $key) {
            if (!isset($report[$group][$key])) { $report[$group][$key] = ['income' => 0, 'expense' => 0]; }
            $report[$group][$key][$type] += $amount;
        }
    }
    $report['net'] = $report['income'] - $report['expense'];
    return $report;
}
