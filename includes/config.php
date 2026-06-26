<?php

declare(strict_types=1);

const APP_NAME = 'Gestão de Acervos - DIARQ / MDS';
const APP_BROWSER_TITLE = 'MDS - DIARQ';
const APP_FAVICON_DATA_URI = 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 64 64%22%3E%3Crect width=%2264%22 height=%2264%22 rx=%2214%22 fill=%22%23111827%22/%3E%3Cpath d=%22M10 19a7 7 0 0 1 7-7h10l6 7h14a7 7 0 0 1 7 7v19a7 7 0 0 1-7 7H17a7 7 0 0 1-7-7z%22 fill=%22%23fbbf24%22/%3E%3Cpath d=%22M10 27h44v18a7 7 0 0 1-7 7H17a7 7 0 0 1-7-7z%22 fill=%22%23f59e0b%22/%3E%3C/svg%3E';

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
define('SEED_DB_PATH', BASE_DIR . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'seed_backup.sqlite');
define('PLANILHAS_DIR', DATA_DIR . DIRECTORY_SEPARATOR . 'planilhas');
define('INDICADORES_PLANILHAS_DIR', PLANILHAS_DIR . DIRECTORY_SEPARATOR . 'INDICADORES');
define('BUNDLED_PLANILHAS_DIR', BASE_DIR . DIRECTORY_SEPARATOR . 'planilhas');
define('ASSETS_DIR', BASE_DIR . DIRECTORY_SEPARATOR . 'assets');
define('MANUAIS_DIR', BASE_DIR . DIRECTORY_SEPARATOR . 'manuais');

function app_running_on_railway(): bool
{
    foreach (['RAILWAY_ENVIRONMENT', 'RAILWAY_ENVIRONMENT_NAME', 'RAILWAY_PROJECT_ID', 'RAILWAY_SERVICE_ID', 'RAILWAY_VOLUME_MOUNT_PATH'] as $name) {
        if ((getenv($name) ?: '') !== '') {
            return true;
        }
    }

    return false;
}

function app_storage_mode(): string
{
    return app_running_on_railway() ? 'railway_supabase' : 'local_sqlite';
}

$supabaseConfiguredAtBoot = app_running_on_railway()
    && (getenv('SUPABASE_URL') ?: '') !== ''
    && (
        (getenv('SUPABASE_KEY') ?: '') !== ''
        || (getenv('SUPABASE_ANON_KEY') ?: '') !== ''
        || (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: '') !== ''
        || (getenv('SUPABASE_SERVICE_KEY') ?: '') !== ''
    );

if (!is_file(DB_PATH)) {
    if (is_file(SEED_DB_PATH)) {
        @copy(SEED_DB_PATH, DB_PATH);
    } elseif (is_file(BUNDLED_DB_PATH)) {
        @copy(BUNDLED_DB_PATH, DB_PATH);
    }
}

if (!is_dir(PLANILHAS_DIR)) {
    @mkdir(PLANILHAS_DIR, 0775, true);
}

if (is_dir(BUNDLED_PLANILHAS_DIR)) {
    sync_bundled_planilhas(BUNDLED_PLANILHAS_DIR, PLANILHAS_DIR);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    $sessionDir = DATA_DIR . DIRECTORY_SEPARATOR . 'sessions';
    if (!is_dir($sessionDir)) {
        @mkdir($sessionDir, 0775, true);
    }
    if (is_dir($sessionDir) && is_writable($sessionDir)) {
        session_save_path($sessionDir);
    }
    session_name('DIARQSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
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
