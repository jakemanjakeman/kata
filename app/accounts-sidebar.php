<?php // Account navigation for the credit card overview and detail pages. ?>
<aside class="accounts-sidebar" aria-labelledby="accounts-sidebar-title">
    <h2 id="accounts-sidebar-title">Accounts</h2>
    <nav aria-label="Account navigation">
        <a href="finance.php?finance_scope=<?= $financeScope ?>&amp;cards=1"<?= $detailAccountId === '' ? ' aria-current="page"' : '' ?>>All credit cards</a>
        <?php foreach (['Credit cards' => $creditCardAccounts, 'Bank accounts' => $bankAccounts, 'Assets' => $assetAccounts, 'Other debt' => array_filter($debtAccounts, static fn(array $account): bool => !(bool)$account['credit_card']), 'Closed accounts' => $droppedAccounts] as $groupLabel => $sidebarAccounts): ?>
            <?php if ($sidebarAccounts === []) { continue; } ?>
            <h3><?= htmlspecialchars($groupLabel, ENT_QUOTES, 'UTF-8') ?></h3>
            <ul>
                <?php foreach ($sidebarAccounts as $sidebarAccount): ?>
                    <?php
                        $sidebarId = (string)$sidebarAccount['id'];
                        $sidebarIsCard = $sidebarAccount['type'] === 'debt' && (bool)$sidebarAccount['credit_card'];
                        $sidebarUrl = 'finance.php?' . http_build_query(array_merge(
                            ['finance_scope' => $financeScope],
                            $sidebarIsCard ? ['account' => $sidebarId] : ['page' => 'accounts']
                        ));
                    ?>
                    <li><a href="<?= htmlspecialchars($sidebarUrl, ENT_QUOTES, 'UTF-8') ?>"<?= $sidebarIsCard && $detailAccountId === $sidebarId ? ' aria-current="page"' : '' ?>><?= htmlspecialchars((string)$sidebarAccount['name'], ENT_QUOTES, 'UTF-8') ?></a></li>
                <?php endforeach; ?>
            </ul>
        <?php endforeach; ?>
        <?php if ($data['accounts'] === []): ?><p>No accounts yet.</p><?php endif; ?>
    </nav>
</aside>
