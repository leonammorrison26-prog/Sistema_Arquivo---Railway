<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$source = $root . DIRECTORY_SEPARATOR . 'banco_diarq.db';
$targetDir = $root . DIRECTORY_SEPARATOR . 'storage';
$target = $targetDir . DIRECTORY_SEPARATOR . 'seed_backup.sqlite';

if (!is_file($source)) {
    fwrite(STDERR, "Fonte nao encontrada: {$source}\n");
    exit(1);
}

if (!is_dir($targetDir)) {
    mkdir($targetDir, 0775, true);
}

@unlink($target);

$src = new PDO('sqlite:' . $source);
$dst = new PDO('sqlite:' . $target);
$src->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$dst->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$src->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$schemaTables = ['usuarios', 'acervo', 'indicadores', 'sei_atendimentos'];
foreach ($schemaTables as $table) {
    $schema = $src->prepare("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = :table");
    $schema->execute([':table' => $table]);
    $sql = (string) $schema->fetchColumn();
    if ($sql === '') {
        continue;
    }

    $dst->exec($sql);
    $rows = $src->query('SELECT * FROM ' . $table);
    $first = $rows->fetch();
    if (!$first) {
        continue;
    }

    $columns = array_keys($first);
    $columnList = implode(', ', array_map(static fn ($column) => '"' . str_replace('"', '""', $column) . '"', $columns));
    $placeholders = implode(', ', array_map(static fn ($column) => ':' . $column, $columns));
    $insert = $dst->prepare("INSERT INTO {$table} ({$columnList}) VALUES ({$placeholders})");

    $dst->beginTransaction();
    $insert->execute(array_combine(array_map(static fn ($column) => ':' . $column, $columns), array_values($first)));
    while ($row = $rows->fetch()) {
        $insert->execute(array_combine(array_map(static fn ($column) => ':' . $column, $columns), array_values($row)));
    }
    $dst->commit();
}

$dst->exec('VACUUM');
echo $target . PHP_EOL;
echo filesize($target) . " bytes\n";
