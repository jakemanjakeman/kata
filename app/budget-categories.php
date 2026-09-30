<?php
declare(strict_types=1);
$config = budgetConfiguration($data, $financeScope);
$escape = static fn($value): string => htmlspecialchars(is_scalar($value) ? (string)$value : '', ENT_QUOTES, 'UTF-8');
$posted = $error !== '' && ($_POST['action'] ?? '') === 'save_budget_config';
?>
<p class="detail-nav"><a href="finance.php?page=budget&amp;finance_scope=<?= $financeScope ?>">Back to budget</a></p>
<section class="panel" aria-labelledby="category-settings-title">
    <h2 class="stage-title" id="category-settings-title"><?= ucfirst($financeScope) ?> Budget Categories</h2>
    <p>Rename categories and assign them to parent buckets. Your saved budget amounts stay with each category.</p>
    <?php if ($error !== ''): ?><p class="notice" role="alert"><?= $escape($error) ?></p><?php endif; ?>
    <?php if (($_GET['saved'] ?? '') === '1' && $error === ''): ?><p class="notice" role="status">Category settings saved.</p><?php endif; ?>
    <form method="post" action="finance.php?page=budget&amp;categories=1&amp;finance_scope=<?= $financeScope ?>" class="form-grid">
        <input type="hidden" name="action" value="save_budget_config">
        <input type="hidden" name="finance_scope" value="<?= $financeScope ?>">
        <h3 class="stage-title">Parent buckets</h3>
        <div class="summary-grid">
            <?php foreach ($config['buckets'] as $id => $name): ?>
                <div class="account-input">
                    <label for="bucket-<?= $escape($id) ?>">Bucket name</label>
                    <input id="bucket-<?= $escape($id) ?>" name="bucket_names[<?= $escape($id) ?>]" value="<?= $escape($posted ? ($_POST['bucket_names'][$id] ?? '') : $name) ?>" maxlength="100" required>
                </div>
            <?php endforeach; ?>
            <div class="account-input">
                <label for="new-bucket">Add parent bucket</label>
                <input id="new-bucket" name="new_bucket" maxlength="100" placeholder="New bucket name" value="<?= $escape($posted ? ($_POST['new_bucket'] ?? '') : '') ?>">
            </div>
        </div>
        <p>Save a new parent bucket before assigning categories to it.</p>
        <h3 class="stage-title">Categories</h3>
        <?php foreach ($config['categories'] as $category): ?>
            <?php $id = $category['id']; $selected = $posted ? ($_POST['category_buckets'][$id] ?? '') : $category['bucket']; ?>
            <div class="summary-grid">
                <div class="account-input">
                    <label for="category-<?= $escape($id) ?>">Category name</label>
                    <input id="category-<?= $escape($id) ?>" name="category_names[<?= $escape($id) ?>]" value="<?= $escape($posted ? ($_POST['category_names'][$id] ?? '') : $category['name']) ?>" maxlength="100" required>
                </div>
                <div class="account-input">
                    <label for="parent-<?= $escape($id) ?>">Parent bucket for <?= $escape($category['name']) ?></label>
                    <select id="parent-<?= $escape($id) ?>" name="category_buckets[<?= $escape($id) ?>]">
                        <?php foreach ($config['buckets'] as $bucket => $name): ?><option value="<?= $escape($bucket) ?>" <?= $selected === $bucket ? 'selected' : '' ?>><?= $escape($name) ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
        <?php endforeach; ?>
        <h3 class="stage-title">Add category</h3>
        <div class="summary-grid">
            <div class="account-input"><label for="new-category">New category name</label><input id="new-category" name="new_category" maxlength="100" value="<?= $escape($posted ? ($_POST['new_category'] ?? '') : '') ?>"></div>
            <div class="account-input">
                <label for="new-category-bucket">Parent bucket</label>
                <select id="new-category-bucket" name="new_category_bucket">
                    <?php foreach ($config['buckets'] as $bucket => $name): ?><option value="<?= $escape($bucket) ?>" <?= $posted && ($_POST['new_category_bucket'] ?? '') === $bucket ? 'selected' : '' ?>><?= $escape($name) ?></option><?php endforeach; ?>
                </select>
            </div>
        </div>
        <button type="submit">Save Category Settings</button>
    </form>
</section>
