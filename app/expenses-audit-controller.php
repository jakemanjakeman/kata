<?php
require_once __DIR__ . '/expenses-audit.php';
$auditPath = kataStoragePath('expenses-audits.json');
$auditMonth = (string)($_POST['audit_month'] ?? $_GET['month'] ?? $now->format('Y-m'));
$auditError = '';
$audits = [];
if (!auditMonthValid($auditMonth)) { $auditMonth = $now->format('Y-m'); $auditError = 'Choose a valid month.'; }
if ($isAuthenticated) {
    try { $audits = loadExpensesAudits($auditPath); }
    catch (RuntimeException $exception) { $auditError = $exception->getMessage(); }
}
$audit = $audits[$financeScope][$auditMonth] ?? null;
$auditEditing = $audit === null || isset($_GET['edit']);
$auditRevision = $audit['revision'] ?? '';
$auditRows = [];
foreach ($audit['items'] ?? [] as $item) {
    $item['amount'] = number_format($item['amount_cents'] / 100, 2, '.', '');
    $auditRows[] = $item;
}
if (!isset($_SESSION['audit_csrf'])) { $_SESSION['audit_csrf'] = bin2hex(random_bytes(32)); }
if ($isAuthenticated && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_audit') {
    $auditEditing = true;
    $auditRows = is_array($_POST['items'] ?? null) ? array_values($_POST['items']) : [];
    $auditRevision = (string)($_POST['audit_revision'] ?? '');
    try {
        if ($auditError !== '') { throw new RuntimeException($auditError); }
        if (!hash_equals($_SESSION['audit_csrf'], (string)($_POST['audit_csrf'] ?? ''))) { throw new RuntimeException('Your session changed. Reload the page before saving.'); }
        if (($_POST['audit_complete'] ?? '') !== '1') { throw new RuntimeException('The submitted audit was incomplete. Reduce the number of items and try again.'); }
        $items = validateAuditItems($auditRows, $data['accounts'], billCategoryOptions(), $audit['items'] ?? [], $auditMonth);
        saveExpensesAudit($auditPath, $financeScope, $auditMonth, $items, $auditRevision);
        header('Location: ' . financeScopeUrl('finance.php?page=audit&month=' . rawurlencode($auditMonth) . '&saved=1'));
        exit;
    } catch (RuntimeException $exception) { $auditError = $exception->getMessage(); }
}
