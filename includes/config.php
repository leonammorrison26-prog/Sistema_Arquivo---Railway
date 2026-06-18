<?php

declare(strict_types=1);

const APP_NAME = 'Gestao de Acervos - DIARQ / MDS';

$baseDir = dirname(__DIR__);
$dataDir = getenv('RAILWAY_VOLUME_MOUNT_PATH') ?: (getenv('DATA_DIR') ?: $baseDir);

if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0775, true);
}

if (!is_writable($dataDir)) {
    $dataDir = sys_get_temp_dir();
}

define('BASE_DIR', $baseDir);
define('DATA_DIR', $dataDir);
define('DB_PATH', DATA_DIR . DIRECTORY_SEPARATOR . 'banco_diarq.db');
define('BUNDLED_DB_PATH', BASE_DIR . DIRECTORY_SEPARATOR . 'banco_diarq.db');
define('PLANILHAS_DIR', DATA_DIR . DIRECTORY_SEPARATOR . 'planilhas');
define('BUNDLED_PLANILHAS_DIR', BASE_DIR . DIRECTORY_SEPARATOR . 'planilhas');
define('ASSETS_DIR', BASE_DIR . DIRECTORY_SEPARATOR . 'assets');
define('MANUAIS_DIR', BASE_DIR . DIRECTORY_SEPARATOR . 'manuais');

$supabaseConfiguredAtBoot = (getenv('SUPABASE_URL') ?: '') !== ''
    && (
        (getenv('SUPABASE_KEY') ?: '') !== ''
        || (getenv('SUPABASE_ANON_KEY') ?: '') !== ''
        || (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: '') !== ''
        || (getenv('SUPABASE_SERVICE_KEY') ?: '') !== ''
    );

if (!$supabaseConfiguredAtBoot && !is_file(DB_PATH) && is_file(BUNDLED_DB_PATH)) {
    @copy(BUNDLED_DB_PATH, DB_PATH);
}

if (!is_dir(PLANILHAS_DIR)) {
    @mkdir(PLANILHAS_DIR, 0775, true);
}

if (is_dir(BUNDLED_PLANILHAS_DIR)) {
    sync_bundled_planilhas(BUNDLED_PLANILHAS_DIR, PLANILHAS_DIR);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function sync_bundled_planilhas(string $sourceDir, string $targetDir): void
{
    $sourceRoot = realpath($sourceDir);
    if ($sourceRoot === false) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $relativePath = substr($item->getPathname(), strlen($sourceRoot) + 1);
        if ($relativePath === false || $relativePath === '') {
            continue;
        }

        $targetPath = $targetDir . DIRECTORY_SEPARATOR . $relativePath;
        if ($item->isDir()) {
            if (!is_dir($targetPath)) {
                @mkdir($targetPath, 0775, true);
            }
            continue;
        }

        $sourcePath = $item->getPathname();
        if (realpath($targetPath) === realpath($sourcePath)) {
            continue;
        }

        $shouldCopy = !is_file($targetPath)
            || filesize($targetPath) !== filesize($sourcePath)
            || filemtime($targetPath) < filemtime($sourcePath);

        if ($shouldCopy) {
            $targetParent = dirname($targetPath);
            if (!is_dir($targetParent)) {
                @mkdir($targetParent, 0775, true);
            }
            @copy($sourcePath, $targetPath);
        }
    }
}
