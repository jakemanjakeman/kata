<?php
declare(strict_types=1);

function financeRecordScope(array $record): string
{
    return ($record['scope'] ?? '') === 'business' ? 'business' : 'personal';
}

function filterFinanceScope(array $data, string $scope): array
{
    foreach (['accounts', 'bills', 'incomes'] as $collection) {
        $data[$collection] = array_values(array_filter($data[$collection] ?? [], static function (array $record) use ($scope): bool {
            return financeRecordScope($record) === $scope;
        }));
    }
    $ids = array_fill_keys(array_column($data['accounts'], 'id'), true);
    foreach ($data['entries'] as $date => &$entry) {
        $entry['balances'] = array_intersect_key($entry['balances'], $ids);
        if ($entry['balances'] === []) {
            unset($data['entries'][$date]);
        }
    }
    unset($entry);
    return $data;
}

// Merge the visible view back into the complete file, retaining other views
// and all historical balances, including balances for dropped accounts.
function mergeFinanceScope(array $full, array $visible, string $scope): array
{
    $ids = array_fill_keys(array_column(filterFinanceScope($full, $scope)['accounts'], 'id'), true);
    foreach (['accounts', 'bills', 'incomes'] as $collection) {
        $other = array_values(array_filter($full[$collection], static function (array $record) use ($scope): bool {
            return financeRecordScope($record) !== $scope;
        }));
        foreach ($visible[$collection] as &$record) {
            $record['scope'] = $record['scope'] ?? $scope;
        }
        unset($record);
        $full[$collection] = array_merge($other, $visible[$collection]);
    }
    foreach ($visible['entries'] as $date => $entry) {
        $old = $full['entries'][$date] ?? [];
        $entry['balances'] = array_replace($old['balances'] ?? [], array_intersect_key($entry['balances'], $ids));
        $entry['created_at'] = $old['created_at'] ?? $entry['created_at'];
        $full['entries'][$date] = $entry;
    }
    ksort($full['entries']);
    return $full;
}

function financeScopeUrl(string $url, ?string $scope = null): string
{
    $scope = $scope ?? ($GLOBALS['financeScope'] ?? 'personal');
    return $url . (strpos($url, '?') === false ? '?' : '&') . 'finance_scope=' . rawurlencode($scope);
}

function renderFinanceMoveForm(string $collection, string $id, string $scope): string
{
    $target = $scope === 'personal' ? 'business' : 'personal';
    return '<form method="post" action="">'
        . '<input type="hidden" name="finance_scope" value="' . $scope . '">'
        . '<input type="hidden" name="action" value="move_finance_record">'
        . '<input type="hidden" name="collection" value="' . $collection . '">'
        . '<input type="hidden" name="record_id" value="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '">'
        . '<input type="hidden" name="target_scope" value="' . $target . '">'
        . '<button class="secondary-button" type="submit">Move to ' . ucfirst($target) . '</button></form>';
}
