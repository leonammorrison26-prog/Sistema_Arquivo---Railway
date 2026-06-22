<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$reason = $argv[1] ?? 'manual';
$lockPath = DATA_DIR . DIRECTORY_SEPARATOR . 'silent-sync.lock';
$lock = fopen($lockPath, 'c');
if ($lock === false) {
    fwrite(STDERR, "[diarq] silent sync skipped: cannot open lock\n");
    exit(0);
}

if (!flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "[diarq] silent sync skipped: already running\n");
    fclose($lock);
    exit(0);
}

ftruncate($lock, 0);
fwrite($lock, 'pid=' . getmypid() . ' started=' . date('c') . ' reason=' . $reason . PHP_EOL);
fflush($lock);

$started = microtime(true);
fwrite(STDERR, '[diarq] silent sync started reason=' . $reason . PHP_EOL);

try {
    $result = sync_app_data(true);
    system_event('sync_silenciosa', 'Sincronizacao silenciosa concluida', $result);
    fwrite(STDERR, '[diarq] silent sync finished seconds=' . round(microtime(true) - $started, 2) . PHP_EOL);
} catch (Throwable $e) {
    system_event('sync_silenciosa_erro', 'Sincronizacao silenciosa falhou', ['erro' => $e->getMessage()]);
    fwrite(STDERR, '[diarq] silent sync failed: ' . $e->getMessage() . PHP_EOL);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
    @unlink($lockPath);
}
