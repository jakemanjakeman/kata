<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/finance-scope.php';

function checkScope(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

// Exercise the real normalization, persistence, and POST handlers against a
// temporary finance file. Do not bootstrap the app or touch personal data.
$source = file_get_contents(__DIR__ . '/../finance.php');
$start = strpos($source, 'function blankFinanceData');
$end = strpos($source, '$data = filterFinanceScope(loadFinanceData($storageFile), $financeScope);');
$functions = substr($source, $start, $end - $start);
eval(str_replace('exit;', 'throw new RuntimeException("redirect");', $functions));
$start = strpos($source, 'if ($_SERVER[\'REQUEST_METHOD\'] === \'POST\' && $isAuthenticated)');
$end = strpos($source, '$activeAccounts = activeAccounts($data[\'accounts\']);', $start);
$handler = str_replace('exit;', 'throw new RuntimeException("redirect");', substr($source, $start, $end - $start));
$storageFile = tempnam(sys_get_temp_dir(), 'kata-scope-');
$fixture = [
    'accounts' => [
        ['id' => 'personal-card', 'name' => 'Personal card', 'type' => 'debt', 'apr' => 24],
        ['id' => 'business-bank', 'name' => 'Business bank', 'type' => 'bank', 'scope' => 'business'],
    ],
    'bills' => [['id' => 'bill', 'name' => 'Card bill', 'day' => 15, 'amount' => 20, 'credit_card_id' => 'personal-card']],
    'incomes' => [['id' => 'income', 'name' => 'Business income', 'amount' => 100, 'scope' => 'business']],
    'entries' => [
        '2026-09-18' => ['balances' => ['personal-card' => 100]],
        '2026-09-19' => ['balances' => ['personal-card' => 200, 'business-bank' => 500]],
        '2026-09-20' => ['balances' => ['business-bank' => 600]],
    ],
];
function runScopePost(string $scope, array $post): array
{
    global $financeScope, $storageFile, $handler;
    $financeScope = $scope;
    $data = filterFinanceScope(loadFinanceData($storageFile), $scope);
    $error = ''; $isAuthenticated = true;
    $now = new DateTimeImmutable('2026-09-19'); $todayKey = $now->format('Y-m-d');
    $_SERVER['REQUEST_METHOD'] = 'POST'; $_SERVER['REQUEST_URI'] = '/finance.php';
    $_POST = $post; $_GET = [];
    try { eval($handler); } catch (RuntimeException $exception) {
        if ($exception->getMessage() !== 'redirect') { throw $exception; }
    }
    return [$error, loadFinanceData($storageFile)];
}
ob_start();
try {
    file_put_contents($storageFile, json_encode($fixture));
    $full = loadFinanceData($storageFile);
    $personal = filterFinanceScope($full, 'personal');
    $business = filterFinanceScope($full, 'business');
    checkScope(count($personal['accounts']) === 1 && $personal['accounts'][0]['scope'] === 'personal', 'Legacy records default to Personal');
    checkScope(count($business['incomes']) === 1 && $business['bills'] === [], 'Bills and income stay in their view');
    checkScope(!isset($personal['entries']['2026-09-20']) && !isset($business['entries']['2026-09-18']), 'Other-view tally dates do not enter charts');
    checkScope(calculateTotals($personal['accounts'], $personal['entries']['2026-09-19'])['debt'] === 200.0, 'Personal totals exclude business');
    [$error, $full] = runScopePost('personal', ['action' => 'save_entry', 'entry_date' => '2026-09-19', 'balances' => ['personal-card' => '250', 'business-bank' => '9999']]);
    checkScope($error === '' && $full['entries']['2026-09-19']['balances']['business-bank'] === 500.0, 'Personal check-in preserves business and ignores injected balance');
    [$error, $full] = runScopePost('business', ['action' => 'save_entry', 'entry_date' => '2026-09-19', 'balances' => ['business-bank' => '700']]);
    checkScope($error === '' && $full['entries']['2026-09-19']['balances']['personal-card'] === 250.0, 'Business check-in preserves personal');
    [$error, $full] = runScopePost('business', ['action' => 'add_account', 'account_name' => 'New business card', 'account_type' => 'debt']);
    checkScope($error === '' && end($full['accounts'])['scope'] === 'business', 'New account inherits view');
    [$error, $full] = runScopePost('personal', ['action' => 'delete_income', 'income_id' => 'income']);
    checkScope($error !== '' && count($full['incomes']) === 1, 'Cross-view deletes are rejected');
    [$error, $full] = runScopePost('personal', ['action' => 'save_income', 'income_id' => 'income', 'income_name' => 'Injected', 'income_amount' => '2', 'income_cadence' => 'monthly', 'income_day' => '1']);
    checkScope($error !== '' && $full['incomes'][0]['name'] === 'Business income', 'Cross-view updates cannot recreate IDs');
    [$error, $full] = runScopePost('personal', ['action' => 'move_finance_record', 'collection' => 'accounts', 'record_id' => 'personal-card', 'target_scope' => 'business']);
    checkScope($error === '' && filterFinanceScope($full, 'personal')['accounts'] === [], 'Move removes account from source');
    checkScope($full['bills'][0]['scope'] === 'business' && $full['bills'][0]['credit_card_id'] === 'personal-card', 'Linked bills move with card');
    checkScope(filterFinanceScope($full, 'business')['entries']['2026-09-18']['balances']['personal-card'] === 100.0, 'Historical balances follow moved account');
    [$error, $full] = runScopePost('personal', ['action' => 'drop_account', 'account_id' => 'personal-card']);
    checkScope($error !== '', 'Stale source-view form cannot modify moved card');
    [$error, $full] = runScopePost('business', ['action' => 'move_finance_record', 'collection' => 'bills', 'record_id' => 'bill', 'target_scope' => 'personal']);
    checkScope($error === '' && $full['bills'][0]['scope'] === 'personal' && $full['bills'][0]['credit_card_id'] === '', 'Moving bill alone clears incompatible card');
    [$error, $full] = runScopePost('business', ['action' => 'delete_income', 'income_id' => 'income']);
    checkScope($error === '' && $full['incomes'] === [] && count($full['bills']) === 1, 'Scoped delete preserves other collections');
} finally {
    unlink($storageFile);
    ob_end_clean();
}
echo "Finance scope checks passed.\n";
