<?php

declare(strict_types=1);

$port = getenv('PORT') ?: '8080';
$root = dirname(__DIR__);
$router = $root . DIRECTORY_SEPARATOR . 'router.php';
$php = PHP_BINARY ?: 'php';

$command = [
    escapeshellarg($php),
    '-d display_errors=0',
    '-d log_errors=1',
    '-d error_log=php://stderr',
    '-d memory_limit=512M',
    '-d max_execution_time=120',
    '-S 0.0.0.0:' . escapeshellarg($port),
    '-t ' . escapeshellarg($root),
    escapeshellarg($router),
];

fwrite(STDERR, sprintf(
    "[diarq] starting php server pid=%d php=%s port=%s root=%s\n",
    getmypid(),
    PHP_VERSION,
    $port,
    $root
));

passthru(implode(' ', $command), $code);

fwrite(STDERR, sprintf("[diarq] php server exited code=%d\n", (int) $code));
exit((int) $code);
