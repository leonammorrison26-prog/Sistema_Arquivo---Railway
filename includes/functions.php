<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function current_page(): string
{
    return preg_replace('/[^a-z_]/', '', $_GET['page'] ?? 'busca') ?: 'busca';
}

function redirect_to(string $page = 'busca'): never
{
    header('Location: /?page=' . urlencode($page));
    exit;
}

function indicador_field_labels(): array
{
    return [
        'desarq_sei' => 'Desarquivamento de Processos/Documentos - Saida de Guia Fora (SEI)',
        'caixas_cons' => 'Caixas consultadas em pesquisa de desarquivamento',
        'retorno_desarq' => 'Retorno de Processos/Documentos Desarquivados',
        'receb_guia' => 'Recebimento de Documentos - Guia de Transferencia',
        'cx_sep_class' => 'Caixas separadas para classificacao',
        'proc_class' => 'Processos/Documentos classificados',
        'cx_sep_eliminacao' => 'Caixas separadas para eliminacao',
        'cx_listadas_eliminacao' => 'Caixas listadas para eliminacao',
        'proc_listados_eliminacao' => 'Processos/Documentos listados para eliminacao',
        'cx_inventariadas' => 'Caixas inventariadas',
        'proc_inventariados' => 'Processos/documentos inventariados',
        'docs_admin_produzidos' => 'Documentos administrativos produzidos',
        'orientacao_tecnica' => 'Orientacao tecnica / atendimentos realizados',
        'cx_remanejadas' => 'Caixas remanejadas',
        'cx_conferidas' => 'Caixas conferidas',
        'cx_substituidas' => 'Caixas substituidas/trocadas',
        'etiquetas_geradas' => 'Etiquetas geradas/impressas',
    ];
}

function user_is_admin(?array $user = null): bool
{
    $user = $user ?: ($_SESSION['user'] ?? []);
    return strtoupper((string) ($user['login'] ?? '')) === 'ADMIN' || (int) ($user['p_gerir_usuarios'] ?? 0) === 1;
}

function find_logo(): ?string
{
    $names = ['LOGO_MDS.png', 'brasao.png.png', 'brasao.png', 'LOGO_DIARQ.png', 'image_4.png', 'brasao.jpg'];
    $dirs = [BASE_DIR, ASSETS_DIR, PLANILHAS_DIR, PLANILHAS_DIR . DIRECTORY_SEPARATOR . 'assets'];

    foreach ($dirs as $dir) {
        foreach ($names as $name) {
            $path = $dir . DIRECTORY_SEPARATOR . $name;
            if (is_file($path)) {
                return $path;
            }
        }
    }

    return null;
}

function logo_data_uri(): ?string
{
    $path = find_logo();
    if (!$path) {
        return null;
    }

    $type = pathinfo($path, PATHINFO_EXTENSION);
    $mime = strtolower($type) === 'jpg' || strtolower($type) === 'jpeg' ? 'image/jpeg' : 'image/png';
    return 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($path));
}

function normalize_text(string $value): string
{
    $value = trim($value);
    return $value === '' ? '---' : $value;
}

function normalize_search_text(string $value): string
{
    $value = strtolower(trim($value));
    $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if ($converted !== false) {
        $value = $converted;
    }
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '';
    return trim(preg_replace('/\s+/', ' ', $value) ?? '');
}

function temporalidade_index(): array
{
    static $index = null;
    if (is_array($index)) {
        return $index;
    }

    $path = MANUAIS_DIR . DIRECTORY_SEPARATOR . 'temporalidade_index.json';
    if (!is_file($path)) {
        return $index = [];
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    return $index = is_array($decoded) ? $decoded : [];
}

function temporalidade_suggestion(array $row): ?array
{
    $query = normalize_search_text(implode(' ', [
        $row['ASSUNTO'] ?? '',
        $row['INTERESSADO'] ?? '',
        $row['OBSERVACAO'] ?? '',
        $row['PROCESSO'] ?? '',
    ]));

    if ($query === '') {
        return null;
    }

    $stopwords = array_flip([
        'a', 'ao', 'aos', 'as', 'da', 'das', 'de', 'do', 'dos', 'e', 'em', 'na', 'nas', 'no', 'nos',
        'o', 'os', 'ou', 'para', 'por', 'com', 'sem', 'um', 'uma', 'n', 'no', 'doc', 'docs',
        'processo', 'processos', 'documento', 'documentos', 'renovacao', 'diario', 'diarios',
    ]);
    $tokens = array_values(array_filter(
        explode(' ', $query),
        fn ($token) => strlen($token) >= 3 && !isset($stopwords[$token]) && !ctype_digit($token)
    ));

    if (!$tokens) {
        return null;
    }

    $subject = normalize_search_text((string) ($row['ASSUNTO'] ?? ''));
    $best = null;
    $bestScore = 0;

    foreach (temporalidade_index() as $entry) {
        $search = (string) ($entry['search'] ?? '');
        if ($search === '') {
            continue;
        }

        $score = 0;
        $matched = false;
        foreach ($tokens as $token) {
            if (str_contains($search, $token)) {
                $matched = true;
                $score += str_contains(normalize_search_text((string) ($entry['title'] ?? '')), $token) ? 5 : 2;
                $score += min(4, substr_count($search, $token));
            }
        }

        if (!$matched) {
            continue;
        }

        if ($subject !== '' && str_contains($search, $subject)) {
            $score += 8;
        }

        if (
            (str_contains($query, 'passagem') || str_contains($query, 'passagens') || str_contains($query, 'diaria') || str_contains($query, 'viagem'))
            && (str_starts_with((string) ($entry['code'] ?? ''), '028') || str_contains($search, 'viagens a servico'))
        ) {
            $score += 10;
        }

        $code = (string) ($entry['code'] ?? '');
        if (str_contains($code, '.')) {
            $score += 1 + substr_count($code, '.');
        }

        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $entry + ['score' => $score];
        }
    }

    return $bestScore >= 2 ? $best : null;
}

function build_texto_geral(array $row): string
{
    $parts = [];
    foreach (['UNIDADE', 'ASSUNTO', 'INTERESSADO', 'DATA', 'TEMPORALIDADE', 'CAIXA', 'PROCESSO', 'LOCALIZACAO', 'OBSERVACAO', 'VOLUMES'] as $key) {
        $parts[] = (string) ($row[$key] ?? '');
    }
    return strtolower(implode(' ', $parts));
}

function search_acervo(string $term, string $scope = 'geral', int $limit = 100): array
{
    $term = trim($term);
    if ($term === '') {
        return [];
    }

    $scopeColumns = [
        'rh' => ['INTERESSADO', 'ASSUNTO', 'OBSERVACAO'],
        'caixas' => ['CAIXA', 'LOCALIZACAO'],
        'processos' => ['PROCESSO'],
        'geral' => ['TEXTO_GERAL', 'UNIDADE', 'ASSUNTO', 'INTERESSADO', 'PROCESSO', 'CAIXA', 'LOCALIZACAO', 'OBSERVACAO'],
    ];
    $columns = $scopeColumns[$scope] ?? $scopeColumns['geral'];

    $where = implode(' OR ', array_map(fn ($col) => "$col LIKE :term", $columns));
    $stmt = db()->prepare("SELECT * FROM acervo WHERE $where ORDER BY CAIXA, ASSUNTO LIMIT :limit");
    $stmt->bindValue(':term', '%' . $term . '%', PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

function acervo_totals(): array
{
    $pdo = db();
    $pastasFuncionais = (int) $pdo->query("
        SELECT COUNT(*)
        FROM acervo
        WHERE LOWER(COALESCE(ASSUNTO, '') || ' ' || COALESCE(OBSERVACAO, '') || ' ' || COALESCE(FONTE_ARQUIVO, '')) LIKE '%pasta funcional%'
           OR LOWER(COALESCE(ASSUNTO, '') || ' ' || COALESCE(OBSERVACAO, '') || ' ' || COALESCE(FONTE_ARQUIVO, '')) LIKE '%pastas funcionais%'
    ")->fetchColumn();

    if ($pastasFuncionais === 0) {
        $pastasFuncionais = (int) $pdo->query("
            SELECT COUNT(DISTINCT INTERESSADO)
            FROM acervo
            WHERE COALESCE(INTERESSADO, '') <> ''
              AND LOWER(COALESCE(ASSUNTO, '') || ' ' || COALESCE(FONTE_ARQUIVO, '')) LIKE '%rh%'
        ")->fetchColumn();
    }

    return [
        'itens' => (int) $pdo->query('SELECT COUNT(*) FROM acervo')->fetchColumn(),
        'caixas' => (int) $pdo->query("SELECT COUNT(DISTINCT CAIXA) FROM acervo WHERE TRIM(COALESCE(CAIXA, '')) <> '' AND TRIM(CAIXA) <> '---'")->fetchColumn(),
        'processos' => (int) $pdo->query("SELECT COUNT(*) FROM acervo WHERE COALESCE(PROCESSO, '') <> '' AND PROCESSO <> '---'")->fetchColumn(),
        'pastas_funcionais' => $pastasFuncionais,
        'usuarios' => (int) $pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn(),
        'indicadores' => (int) $pdo->query('SELECT COUNT(*) FROM indicadores')->fetchColumn(),
    ];
}

function temporalidade_pendente(): array
{
    return db()->query("
        SELECT * FROM acervo
        WHERE TRIM(COALESCE(TEMPORALIDADE, '')) = ''
           OR TEMPORALIDADE = '---'
           OR LOWER(TEMPORALIDADE) = 'nan'
        ORDER BY CAIXA, ASSUNTO
        LIMIT 500
    ")->fetchAll();
}

function export_csv(string $filename, array $rows): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'wb');
    if ($rows) {
        fputcsv($out, array_keys($rows[0]), ';');
        foreach ($rows as $row) {
            fputcsv($out, $row, ';');
        }
    }
    fclose($out);
    exit;
}
