<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$runtimeDir = $root . DIRECTORY_SEPARATOR . '.backup-runtime';

if ((getenv('SUPABASE_URL') ?: '') === '' || ((getenv('SUPABASE_KEY') ?: '') === '' && (getenv('SUPABASE_ANON_KEY') ?: '') === '' && (getenv('SUPABASE_SERVICE_ROLE_KEY') ?: '') === '' && (getenv('SUPABASE_SERVICE_KEY') ?: '') === '')) {
    fwrite(STDERR, "SUPABASE_URL e SUPABASE_KEY/SUPABASE_ANON_KEY precisam estar configurados.\n");
    exit(1);
}

remove_dir($runtimeDir);
mkdir($runtimeDir, 0775, true);

putenv('DATA_DIR=' . $runtimeDir);
putenv('RAILWAY_ENVIRONMENT=backup');
$_ENV['DATA_DIR'] = $runtimeDir;
$_ENV['RAILWAY_ENVIRONMENT'] = 'backup';

require_once $root . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'auth.php';

echo 'Banco temporario: ' . DB_PATH . PHP_EOL;
echo 'Seed atual: ' . (is_file(SEED_DB_PATH) ? SEED_DB_PATH : 'nenhum') . PHP_EOL;

$result = sync_app_data(true);
echo 'Sincronizacao: ' . json_encode($result, JSON_UNESCAPED_UNICODE) . PHP_EOL;

$target = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'seed_backup.sqlite';
$command = escapeshellarg(PHP_BINARY ?: 'php')
    . ' ' . escapeshellarg($root . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'build_seed_backup.php')
    . ' ' . escapeshellarg(DB_PATH)
    . ' ' . escapeshellarg($target);

passthru($command, $code);
remove_dir($runtimeDir);
exit((int) $code);

function remove_dir(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($items as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }

    @rmdir($path);
}
