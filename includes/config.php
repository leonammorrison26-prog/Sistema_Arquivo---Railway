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
define('ASSETS_DIR', BASE_DIR . DIRECTORY_SEPARATOR . 'assets');
define('MANUAIS_DIR', BASE_DIR . DIRECTORY_SEPARATOR . 'manuais');

if (!is_file(DB_PATH) && is_file(BUNDLED_DB_PATH)) {
    @copy(BUNDLED_DB_PATH, DB_PATH);
}

if (!is_dir(PLANILHAS_DIR)) {
    @mkdir(PLANILHAS_DIR, 0775, true);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
