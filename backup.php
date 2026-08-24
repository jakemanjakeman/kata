<?php
declare(strict_types=1);

function kataJsonBackupDirectory(string $dateKey): string
{
    $dataDir = defined('KATA_CONFIG')
        ? rtrim((string)KATA_CONFIG['data_dir'], "\\/")
        : __DIR__;

    return $dataDir . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . $dateKey;
}

function kataRootJsonFiles(): array
{
    $dataDir = defined('KATA_CONFIG')
        ? rtrim((string)KATA_CONFIG['data_dir'], "\\/")
        : __DIR__;
    $files = glob($dataDir . DIRECTORY_SEPARATOR . '*.json');

    return is_array($files) ? $files : [];
}

function ensureDailyJsonBackups(string $dateKey): bool
{
    $backupDirectory = kataJsonBackupDirectory($dateKey);
    if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0775, true) && !is_dir($backupDirectory)) {
        return false;
    }

    $allBackupsSucceeded = true;
    foreach (kataRootJsonFiles() as $sourceFile) {
        if (!is_file($sourceFile)) {
            continue;
        }

        $backupFile = $backupDirectory . DIRECTORY_SEPARATOR . basename($sourceFile);
        if (is_file($backupFile)) {
            continue;
        }

        if (!copy($sourceFile, $backupFile)) {
            $allBackupsSucceeded = false;
        }
    }

    return $allBackupsSucceeded;
}
