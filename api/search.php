<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

$term = $_GET['q'] ?? '';
$scope = $_GET['scope'] ?? 'geral';

echo json_encode([
    'items' => search_acervo($term, $scope),
], JSON_UNESCAPED_UNICODE);

