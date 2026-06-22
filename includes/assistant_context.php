<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

function assistant_context(string $message): array
{
    return [
        'manuals' => assistant_manual_search($message, 6),
        'acervo' => assistant_acervo_context($message),
        'attention' => attention_items(),
        'memory' => assistant_memory_search($message, 4),
    ];
}

function assistant_memory_search(string $message, int $limit = 4): array
{
    $tokens = assistant_tokens($message);
    if (!$tokens) {
        return [];
    }

    $where = [];
    $params = [];
    foreach (array_slice($tokens, 0, 6) as $index => $token) {
        $key = ':m' . $index;
        $where[] = "(pergunta LIKE {$key} OR resposta LIKE {$key})";
        $params[$key] = '%' . $token . '%';
    }

    $stmt = db()->prepare('SELECT pergunta, resposta, criado_em FROM assistant_memory WHERE ' . implode(' OR ', $where) . ' ORDER BY id DESC LIMIT :limit');
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function assistant_tokens(string $text): array
{
    $text = normalize_search_text($text);
    $tokens = [];

    if (preg_match_all('/bloco\s+([a-z0-9]+)/', $text, $matches)) {
        foreach ($matches[1] as $block) {
            $phrase = 'bloco ' . $block;
            if (!in_array($phrase, $tokens, true)) {
                $tokens[] = $phrase;
            }
        }
    }

    $parts = preg_split('/[^a-z0-9]+/i', $text) ?: [];
    $stop = array_flip([
        'a', 'o', 'os', 'as', 'um', 'uma', 'uns', 'umas', 'de', 'da', 'do', 'das', 'dos',
        'e', 'em', 'no', 'na', 'nos', 'nas', 'para', 'por', 'com', 'que', 'qual', 'quais',
        'quanto', 'quantos', 'quantas', 'quando', 'onde', 'foi', 'foram', 'tem', 'têm',
        'existe', 'existem', 'caixa', 'caixas', 'bloco',
    ]);

    foreach ($parts as $part) {
        $part = (string) $part;
        if (strlen($part) < 2 || isset($stop[$part])) {
            continue;
        }
        if (!in_array($part, $tokens, true)) {
            $tokens[] = $part;
        }
    }

    return $tokens;
}

function assistant_manual_search(string $message, int $limit = 6): array
{
    $indexPath = MANUAIS_DIR . DIRECTORY_SEPARATOR . 'manual_index.json';
    if (!is_file($indexPath)) {
        return [];
    }

    $tokens = assistant_tokens($message);
    if (!$tokens) {
        return [];
    }

    $records = json_decode((string) file_get_contents($indexPath), true);
    if (!is_array($records)) {
        return [];
    }

    $scored = [];
    foreach ($records as $record) {
        $text = (string) ($record['text'] ?? '');
        $haystack = normalize_search_text(($record['file'] ?? '') . ' ' . $text);
        $score = 0;
        foreach ($tokens as $token) {
            $count = substr_count($haystack, $token);
            if ($count > 0) {
                $score += 8 + min($count, 5);
            }
        }
        if ($score <= 0) {
            continue;
        }
        $record['score'] = $score;
        $record['excerpt'] = assistant_excerpt($text, $tokens);
        $scored[] = $record;
    }

    usort($scored, fn ($a, $b) => ($b['score'] <=> $a['score']));
    return array_slice($scored, 0, $limit);
}

function assistant_excerpt(string $text, array $tokens, int $radius = 260): string
{
    $normalized = normalize_search_text($text);
    $position = false;
    foreach ($tokens as $token) {
        $position = strpos($normalized, $token);
        if ($position !== false) {
            break;
        }
    }

    if ($position === false) {
        return mb_substr($text, 0, 520);
    }

    $start = max(0, $position - $radius);
    $excerpt = mb_substr($text, $start, $radius * 2);
    return ($start > 0 ? '...' : '') . trim($excerpt) . (mb_strlen($text) > $start + ($radius * 2) ? '...' : '');
}

function assistant_acervo_context(string $message): array
{
    $tokens = assistant_tokens($message);
    if (!$tokens) {
        return ['tokens' => [], 'total_registros' => 0, 'total_caixas' => 0, 'samples' => []];
    }

    $whereParts = [];
    $params = [];
    foreach ($tokens as $index => $token) {
        $key = ':t' . $index;
        $tokenClauses = [
            "COALESCE(TEXTO_GERAL, '') LIKE {$key}",
            "COALESCE(UNIDADE, '') LIKE {$key}",
            "COALESCE(ASSUNTO, '') LIKE {$key}",
            "COALESCE(INTERESSADO, '') LIKE {$key}",
            "COALESCE(CAIXA, '') LIKE {$key}",
            "COALESCE(PROCESSO, '') LIKE {$key}",
            "COALESCE(LOCALIZACAO, '') LIKE {$key}",
            "COALESCE(OBSERVACAO, '') LIKE {$key}",
        ];
        if ($token === 'arcem') {
            $tokenClauses[] = "COALESCE(TEXTO_GERAL, '') LIKE :arcem_arc";
            $tokenClauses[] = "COALESCE(TEXTO_GERAL, '') LIKE :arcem_cem";
            $params[':arcem_arc'] = '%arc%';
            $params[':arcem_cem'] = '%cem%';
        }
        $whereParts[] = "(
            " . implode(' OR ', $tokenClauses) . "
        )";
        $params[$key] = '%' . $token . '%';
    }

    $where = implode(' AND ', $whereParts);
    $summary = assistant_acervo_summary($where, $params);
    if ((int) $summary['total_registros'] === 0 && count($whereParts) > 1) {
        $where = implode(' OR ', $whereParts);
        $summary = assistant_acervo_summary($where, $params);
        $summary['modo_busca'] = 'aproximada';
    } else {
        $summary['modo_busca'] = 'todos_os_termos';
    }

    $sampleStmt = db()->prepare("
        SELECT CAIXA, PROCESSO, INTERESSADO, ASSUNTO, LOCALIZACAO, UNIDADE, DATA_LIMITE
        FROM acervo
        WHERE {$where}
        ORDER BY CAIXA
        LIMIT 8
    ");
    $sampleStmt->execute($params);
    $samples = $sampleStmt->fetchAll();

    $locStmt = db()->prepare("
        SELECT LOCALIZACAO, COUNT(DISTINCT CAIXA) AS caixas
        FROM acervo
        WHERE {$where} AND TRIM(COALESCE(LOCALIZACAO, '')) <> ''
        GROUP BY LOCALIZACAO
        ORDER BY caixas DESC
        LIMIT 6
    ");
    $locStmt->execute($params);

    return $summary + [
        'tokens' => $tokens,
        'samples' => $samples,
        'localizacoes' => $locStmt->fetchAll(),
    ];
}

function assistant_acervo_summary(string $where, array $params): array
{
    $stmt = db()->prepare("
        SELECT COUNT(*) AS total_registros, COUNT(DISTINCT CAIXA) AS total_caixas
        FROM acervo
        WHERE {$where}
    ");
    $stmt->execute($params);
    $row = $stmt->fetch() ?: [];
    return [
        'total_registros' => (int) ($row['total_registros'] ?? 0),
        'total_caixas' => (int) ($row['total_caixas'] ?? 0),
    ];
}

function assistant_context_prompt(array $context): string
{
    $lines = [];
    $manuals = $context['manuals'] ?? [];
    $acervo = $context['acervo'] ?? [];

    $lines[] = "CONTEXTO DOS MANUAIS:";
    if ($manuals) {
        foreach ($manuals as $item) {
            $lines[] = "- {$item['file']} | pagina {$item['page']} | trecho: {$item['excerpt']}";
        }
    } else {
        $lines[] = "- Nenhum trecho relevante encontrado nos manuais.";
    }

    $lines[] = "";
    $lines[] = "CONTEXTO DO ACERVO:";
    $lines[] = "- Termos usados na busca: " . implode(', ', $acervo['tokens'] ?? []);
    $lines[] = "- Modo de busca: " . ($acervo['modo_busca'] ?? 'n/a');
    $lines[] = "- Registros encontrados: " . (int) ($acervo['total_registros'] ?? 0);
    $lines[] = "- Caixas distintas encontradas: " . (int) ($acervo['total_caixas'] ?? 0);

    if (!empty($acervo['localizacoes'])) {
        $lines[] = "- Principais localizacoes:";
        foreach ($acervo['localizacoes'] as $loc) {
            $lines[] = "  * {$loc['LOCALIZACAO']}: {$loc['caixas']} caixa(s)";
        }
    }

    if (!empty($acervo['samples'])) {
        $lines[] = "- Amostras do acervo:";
        foreach ($acervo['samples'] as $row) {
            $lines[] = "  * CX {$row['CAIXA']} | {$row['PROCESSO']} | {$row['INTERESSADO']} | {$row['ASSUNTO']} | {$row['LOCALIZACAO']}";
        }
    }

    $attention = $context['attention'] ?? [];
    $lines[] = "";
    $lines[] = "PONTOS DE ATENCAO DO SISTEMA:";
    if ($attention) {
        foreach (array_slice($attention, 0, 8) as $item) {
            $lines[] = "- {$item['label']}: {$item['value']}";
        }
    } else {
        $lines[] = "- Nenhum alerta operacional critico no momento.";
    }

    $memory = $context['memory'] ?? [];
    $lines[] = "";
    $lines[] = "MEMORIA DE PERGUNTAS ANTERIORES:";
    if ($memory) {
        foreach ($memory as $item) {
            $lines[] = "- Pergunta: {$item['pergunta']} | Resposta anterior: " . mb_substr((string) $item['resposta'], 0, 260);
        }
    } else {
        $lines[] = "- Nenhuma pergunta parecida registrada.";
    }

    return implode("\n", $lines);
}

function assistant_local_reply(string $message, array $context): string
{
    $acervo = $context['acervo'] ?? [];
    $manuals = $context['manuals'] ?? [];
    $parts = [];

    if (($acervo['total_registros'] ?? 0) > 0) {
        $parts[] = 'No acervo encontrei ' . (int) $acervo['total_caixas'] . ' caixa(s) distinta(s) em ' . (int) $acervo['total_registros'] . ' registro(s) relacionados à sua pergunta.';
    }

    if ($manuals) {
        $first = $manuals[0];
        $parts[] = 'Nos manuais, o melhor trecho está em "' . $first['file'] . '", página ' . $first['page'] . ': ' . $first['excerpt'];
    }

    return $parts ? implode("\n\n", $parts) : 'Não encontrei informação suficiente nos manuais nem no acervo para responder com segurança.';
}
