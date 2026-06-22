<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';

$checks = [];

$checks['db'] = db() instanceof PDO;
$checks['central_route'] = (function (): bool {
    $_SERVER['REQUEST_URI'] = '/central';
    unset($_GET['page']);
    return current_page() === 'central';
})();
$checks['diagnostico_route'] = (function (): bool {
    $_SERVER['REQUEST_URI'] = '/diagnostico';
    unset($_GET['page']);
    return current_page() === 'diagnostico';
})();
$checks['attention_items'] = is_array(attention_items());
$checks['map_data'] = is_array(acervo_map_data(3));
$checks['diagnostic_snapshot'] = isset(diagnostic_snapshot()['modo']);
$checks['planilha_validation'] = is_array(planilha_validation_summary());

$failed = array_keys(array_filter($checks, static fn ($ok) => !$ok));
foreach ($checks as $name => $ok) {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $name . PHP_EOL;
}

if ($failed) {
    fwrite(STDERR, 'Falhas: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo 'Smoke test concluido.' . PHP_EOL;
