<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function h(mixed $value): string
{
    if (is_array($value)) {
        $value = implode(', ', array_map(static function (mixed $item): string {
            if (is_array($item) || is_object($item)) {
                return json_encode($item, JSON_UNESCAPED_UNICODE) ?: '';
            }
            return (string) $item;
        }, $value));
    } elseif (is_object($value)) {
        $value = method_exists($value, '__toString') ? (string) $value : (json_encode($value, JSON_UNESCAPED_UNICODE) ?: '');
    }

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

function normalize_user_type(string $type): string
{
    $type = trim($type);
    $normalized = strtolower(strtr($type, [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a',
        'é' => 'e', 'ê' => 'e',
        'í' => 'i',
        'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
        'ú' => 'u',
        'ç' => 'c',
    ]));

    return str_contains($normalized, 'terceir') || str_contains($normalized, 'tercer')
        ? 'Terceirizado'
        : 'Servidor';
}

function supabase_user_profile(int $isAdmin, string $type): string
{
    $profile = $isAdmin === 1 ? 'admin' : 'operador';
    return $profile . '|' . normalize_user_type($type);
}

function parse_supabase_user_profile(string $profile): array
{
    $parts = array_map('trim', explode('|', $profile, 2));
    $role = strtolower($parts[0] ?? '');
    $type = $parts[1] ?? '';

    return [
        'is_admin' => $role === 'admin',
        'tipo_usuario' => $type !== '' ? normalize_user_type($type) : 'Servidor',
    ];
}

function user_is_terceirizado(?array $user = null): bool
{
    $user = $user ?: ($_SESSION['user'] ?? []);
    return normalize_user_type((string) ($user['tipo_usuario'] ?? 'Servidor')) === 'Terceirizado';
}

function sei_terceirizados(): array
{
    $stmt = db()->query("
        SELECT id, nome, login
        FROM usuarios
        WHERE tipo_usuario = 'Terceirizado'
          AND UPPER(login) <> 'ADMIN'
        ORDER BY nome COLLATE NOCASE, login COLLATE NOCASE
    ");

    return $stmt->fetchAll();
}

function sei_queue_state(?array $currentUser = null): array
{
    $currentUser ??= $_SESSION['user'] ?? [];
    $users = sei_terceirizados();
    $total = count($users);

    $last = db()->query("
        SELECT usuario_login, usuario_nome, processo, criado_em
        FROM sei_atendimentos
        ORDER BY datetime(criado_em) DESC, id DESC
        LIMIT 1
    ")->fetch() ?: null;

    if ($total === 0) {
        return [
            'users' => [],
            'next' => null,
            'after_next' => null,
            'last' => $last,
            'is_turn' => false,
            'position' => 0,
            'total' => 0,
        ];
    }

    $lastIndex = -1;
    if ($last) {
        foreach ($users as $index => $user) {
            if (strcasecmp((string) $user['login'], (string) $last['usuario_login']) === 0) {
                $lastIndex = $index;
                break;
            }
        }
    }

    $nextIndex = ($lastIndex + 1) % $total;
    $afterNextIndex = ($nextIndex + 1) % $total;
    $next = $users[$nextIndex];
    $currentLogin = (string) ($currentUser['login'] ?? '');

    return [
        'users' => $users,
        'next' => $next,
        'after_next' => $users[$afterNextIndex] ?? null,
        'last' => $last,
        'is_turn' => $currentLogin !== '' && strcasecmp($currentLogin, (string) $next['login']) === 0,
        'position' => $nextIndex + 1,
        'total' => $total,
    ];
}

function normalize_sei_process(string $process): string
{
    $process = trim($process);
    if ($process === '') {
        throw new RuntimeException('Informe o numero do processo atendido.');
    }

    if (!preg_match('/^\d{5}\.\d{5}\/\d{4}-\d{2}$/', $process)) {
        throw new RuntimeException('Use o formato 00000.00000/0000-00.');
    }

    return $process;
}

function sei_report_data(array $input = []): array
{
    $period = preg_replace('/[^a-z0-9_-]/i', '', (string) ($input['periodo'] ?? '30d')) ?: '30d';
    $allowedPeriods = [
        'today' => ['label' => 'Hoje', 'modifier' => 'start of day'],
        '7d' => ['label' => 'Ultimos 7 dias', 'modifier' => '-7 days'],
        '30d' => ['label' => 'Ultimos 30 dias', 'modifier' => '-30 days'],
        'all' => ['label' => 'Todo o historico', 'modifier' => null],
    ];
    if (!isset($allowedPeriods[$period])) {
        $period = '30d';
    }

    $where = '';
    $params = [];
    if ($allowedPeriods[$period]['modifier'] !== null) {
        $where = 'WHERE datetime(criado_em) >= datetime(:start)';
        $params[':start'] = $period === 'today' ? date('Y-m-d 00:00:00') : date('Y-m-d H:i:s', strtotime($allowedPeriods[$period]['modifier']));
    }

    $totalStmt = db()->prepare("SELECT COUNT(*) FROM sei_atendimentos {$where}");
    $totalStmt->execute($params);
    $total = (int) $totalStmt->fetchColumn();

    $today = (int) db()->query("SELECT COUNT(*) FROM sei_atendimentos WHERE date(criado_em) = date('now', 'localtime')")->fetchColumn();
    $week = (int) db()->query("SELECT COUNT(*) FROM sei_atendimentos WHERE datetime(criado_em) >= datetime('now', '-7 days')")->fetchColumn();

    $attendantsStmt = db()->prepare("SELECT COUNT(DISTINCT usuario_login) FROM sei_atendimentos {$where}");
    $attendantsStmt->execute($params);
    $attendants = (int) $attendantsStmt->fetchColumn();

    $rankingStmt = db()->prepare("
        SELECT usuario_nome, usuario_login, COUNT(*) total, MAX(criado_em) ultimo
        FROM sei_atendimentos
        {$where}
        GROUP BY usuario_login, usuario_nome
        ORDER BY total DESC, usuario_nome COLLATE NOCASE
        LIMIT 10
    ");
    $rankingStmt->execute($params);

    $recentStmt = db()->prepare("
        SELECT usuario_nome, usuario_login, processo, criado_em
        FROM sei_atendimentos
        {$where}
        ORDER BY datetime(criado_em) DESC, id DESC
        LIMIT 80
    ");
    $recentStmt->execute($params);

    $daysStmt = db()->prepare("
        SELECT date(criado_em) dia, COUNT(*) total
        FROM sei_atendimentos
        {$where}
        GROUP BY date(criado_em)
        ORDER BY dia DESC
        LIMIT 14
    ");
    $daysStmt->execute($params);

    return [
        'period' => $period,
        'periods' => $allowedPeriods,
        'total' => $total,
        'today' => $today,
        'week' => $week,
        'attendants' => $attendants,
        'queue' => sei_queue_state(),
        'ranking' => $rankingStmt->fetchAll(),
        'recent' => $recentStmt->fetchAll(),
        'days' => $daysStmt->fetchAll(),
    ];
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
