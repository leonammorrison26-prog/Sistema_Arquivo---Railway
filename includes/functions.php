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
    if (isset($_GET['page'])) {
        return preg_replace('/[^a-z_]/', '', $_GET['page']) ?: 'busca';
    }

    $path = trim((string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'), '/');
    $routes = [
        '' => 'busca',
        'busca' => 'busca',
        'central' => 'central',
        'dashboard' => 'dashboard',
        'diagnostico' => 'diagnostico',
        'mapa-acervo' => 'mapa_acervo',
        'assistente' => 'assistente_openai',
        'acervo' => 'planilha',
        'usuarios' => 'gestao_usuarios',
        'indicadores' => 'rel_indicadores',
    ];

    return $routes[$path] ?? 'busca';
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
        SELECT usuario_login, usuario_nome, processo, status, pulado_por_nome, criado_em
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

    $where = "WHERE status = 'atendido'";
    $params = [];
    if ($allowedPeriods[$period]['modifier'] !== null) {
        $where .= ' AND datetime(criado_em) >= datetime(:start)';
        $params[':start'] = $period === 'today' ? date('Y-m-d 00:00:00') : date('Y-m-d H:i:s', strtotime($allowedPeriods[$period]['modifier']));
    }

    $totalStmt = db()->prepare("SELECT COUNT(*) FROM sei_atendimentos {$where}");
    $totalStmt->execute($params);
    $total = (int) $totalStmt->fetchColumn();

    $today = (int) db()->query("SELECT COUNT(*) FROM sei_atendimentos WHERE status = 'atendido' AND date(criado_em) = date('now', 'localtime')")->fetchColumn();
    $week = (int) db()->query("SELECT COUNT(*) FROM sei_atendimentos WHERE status = 'atendido' AND datetime(criado_em) >= datetime('now', '-7 days')")->fetchColumn();
    $skipped = (int) db()->query("SELECT COUNT(*) FROM sei_atendimentos WHERE status = 'pulado'")->fetchColumn();

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
        'skipped' => $skipped,
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

function temporalidade_suggestion(array $row, string $context = ''): ?array
{
    $query = normalize_search_text(implode(' ', [
        $context,
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

    if ($scope === 'geral' && acervo_fts_available()) {
        $fts = acervo_fts_search($term, $limit);
        if ($fts) {
            return $fts;
        }
    }

    $where = implode(' OR ', array_map(fn ($col) => "$col LIKE :term", $columns));
    $stmt = db()->prepare("SELECT * FROM acervo WHERE $where ORDER BY CAIXA, ASSUNTO LIMIT :limit");
    $stmt->bindValue(':term', '%' . $term . '%', PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

function search_acervo_filtered(array $input, int $limit = 150): array
{
    $where = [];
    $params = [];
    $fields = [
        'q' => ['TEXTO_GERAL', 'UNIDADE', 'ASSUNTO', 'INTERESSADO', 'PROCESSO', 'CAIXA', 'LOCALIZACAO', 'OBSERVACAO'],
        'caixa' => ['CAIXA'],
        'processo' => ['PROCESSO'],
        'interessado' => ['INTERESSADO'],
        'localizacao' => ['LOCALIZACAO'],
        'temporalidade' => ['TEMPORALIDADE'],
    ];

    foreach ($fields as $key => $columns) {
        $value = trim((string) ($input[$key] ?? ''));
        if ($value === '') {
            continue;
        }

        $param = ':' . $key;
        $where[] = '(' . implode(' OR ', array_map(fn ($col) => "COALESCE($col, '') LIKE $param", $columns)) . ')';
        $params[$param] = '%' . $value . '%';
    }

    if (!$where) {
        return [];
    }

    $stmt = db()->prepare('SELECT * FROM acervo WHERE ' . implode(' AND ', $where) . ' ORDER BY CAIXA, ASSUNTO LIMIT :limit');
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function acervo_fts_available(): bool
{
    try {
        db()->query("SELECT name FROM sqlite_master WHERE name = 'acervo_fts'")->fetchColumn();
        return (bool) db()->query("SELECT name FROM sqlite_master WHERE name = 'acervo_fts'")->fetchColumn();
    } catch (Throwable) {
        return false;
    }
}

function acervo_fts_rebuild(): void
{
    if (!acervo_fts_available()) {
        return;
    }

    try {
        @ini_set('max_execution_time', '0');
        @set_time_limit(0);
        $pdo = db();
        $current = (int) $pdo->query('SELECT COUNT(*) FROM acervo_fts')->fetchColumn();
        $expected = (int) $pdo->query('SELECT COUNT(*) FROM acervo')->fetchColumn();
        if ($current === $expected && $current > 0) {
            return;
        }
        $pdo->exec('DELETE FROM acervo_fts');
        $pdo->exec("
            INSERT INTO acervo_fts (id_unico, texto)
            SELECT ID_UNICO, LOWER(COALESCE(TEXTO_GERAL, '') || ' ' || COALESCE(UNIDADE, '') || ' ' || COALESCE(ASSUNTO, '') || ' ' || COALESCE(INTERESSADO, '') || ' ' || COALESCE(PROCESSO, '') || ' ' || COALESCE(CAIXA, '') || ' ' || COALESCE(LOCALIZACAO, '') || ' ' || COALESCE(OBSERVACAO, ''))
            FROM acervo
        ");
    } catch (Throwable $e) {
        system_event('fts_erro', 'Falha ao reconstruir indice de busca', ['erro' => $e->getMessage()]);
    }
}

function acervo_fts_search(string $term, int $limit = 100): array
{
    acervo_fts_rebuild();
    $tokens = array_values(array_filter(preg_split('/[^a-z0-9]+/i', normalize_search_text($term)) ?: [], fn ($token) => strlen((string) $token) >= 2));
    if (!$tokens) {
        return [];
    }

    $query = implode(' ', array_map(fn ($token) => $token . '*', array_slice($tokens, 0, 8)));
    try {
        $stmt = db()->prepare("
            SELECT a.*
            FROM acervo_fts f
            JOIN acervo a ON a.ID_UNICO = f.id_unico
            WHERE acervo_fts MATCH :query
            ORDER BY rank, a.CAIXA, a.ASSUNTO
            LIMIT :limit
        ");
        $stmt->bindValue(':query', $query, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (Throwable) {
        return [];
    }
}

function system_event(string $tipo, string $mensagem, array $contexto = []): void
{
    try {
        $user = $_SESSION['user'] ?? [];
        db()->prepare("
            INSERT INTO eventos_sistema (tipo, mensagem, contexto_json, usuario_login, usuario_nome)
            VALUES (:tipo, :mensagem, :contexto, :login, :nome)
        ")->execute([
            ':tipo' => $tipo,
            ':mensagem' => $mensagem,
            ':contexto' => json_encode($contexto, JSON_UNESCAPED_UNICODE) ?: '{}',
            ':login' => (string) ($user['login'] ?? ''),
            ':nome' => (string) ($user['nome'] ?? ''),
        ]);
    } catch (Throwable) {
    }
}

function recent_system_events(int $limit = 20): array
{
    $stmt = db()->prepare('SELECT * FROM eventos_sistema ORDER BY id DESC LIMIT :limit');
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function import_job_start(string $tipo, int $totalArquivos = 0, string $mensagem = ''): int
{
    db()->prepare("
        INSERT INTO import_jobs (tipo, status, total_arquivos, mensagem, iniciado_em)
        VALUES (:tipo, 'processando', :arquivos, :mensagem, :iniciado)
    ")->execute([
        ':tipo' => $tipo,
        ':arquivos' => $totalArquivos,
        ':mensagem' => $mensagem,
        ':iniciado' => date('c'),
    ]);
    $id = (int) db()->lastInsertId();
    system_event('importacao_inicio', 'Importacao iniciada', ['job_id' => $id, 'tipo' => $tipo]);
    return $id;
}

function import_job_finish(int $id, string $status, int $totalRegistros, string $mensagem = ''): void
{
    db()->prepare("
        UPDATE import_jobs
        SET status = :status, total_registros = :registros, mensagem = :mensagem, concluido_em = :concluido
        WHERE id = :id
    ")->execute([
        ':status' => $status,
        ':registros' => $totalRegistros,
        ':mensagem' => $mensagem,
        ':concluido' => date('c'),
        ':id' => $id,
    ]);
    system_event('importacao_' . $status, 'Importacao ' . $status, ['job_id' => $id, 'registros' => $totalRegistros, 'mensagem' => $mensagem]);
}

function recent_import_jobs(int $limit = 8): array
{
    $stmt = db()->prepare('SELECT * FROM import_jobs ORDER BY id DESC LIMIT :limit');
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function attention_items(): array
{
    $pdo = db();
    $items = [];
    $checks = [
        ['sem_temporalidade', 'Caixas/itens sem temporalidade', "SELECT COUNT(*) FROM acervo WHERE TRIM(COALESCE(TEMPORALIDADE, '')) = '' OR TEMPORALIDADE = '---' OR LOWER(TEMPORALIDADE) = 'nan'", '/?page=rel_temporalidade'],
        ['sem_localizacao', 'Itens sem localizacao', "SELECT COUNT(*) FROM acervo WHERE TRIM(COALESCE(LOCALIZACAO, '')) = '' OR LOCALIZACAO = '---'", '/?page=busca&scope=caixas&q=---'],
        ['sem_assunto', 'Itens sem assunto', "SELECT COUNT(*) FROM acervo WHERE TRIM(COALESCE(ASSUNTO, '')) = '' OR ASSUNTO = '---'", '/?page=busca&scope=geral&q=---'],
        ['senha_padrao', 'Usuarios ainda com senha padrao', "SELECT COUNT(*) FROM usuarios WHERE senha = '123456' OR TROCAR_SENHA = 1", '/?page=gestao_usuarios'],
        ['importacoes_parciais', 'Importacoes pendentes/parciais', "SELECT COUNT(*) FROM import_jobs WHERE status <> 'concluido'", '/?page=diagnostico'],
    ];

    foreach ($checks as [$key, $label, $sql, $href]) {
        $value = (int) $pdo->query($sql)->fetchColumn();
        if ($value > 0) {
            $items[] = ['key' => $key, 'label' => $label, 'value' => $value, 'href' => $href];
        }
    }

    return $items;
}

function acervo_map_data(int $limit = 14): array
{
    $stmt = db()->prepare("
        SELECT COALESCE(NULLIF(TRIM(LOCALIZACAO), ''), 'Sem localizacao') AS localizacao,
               COUNT(DISTINCT CAIXA) AS caixas,
               COUNT(*) AS itens
        FROM acervo
        GROUP BY COALESCE(NULLIF(TRIM(LOCALIZACAO), ''), 'Sem localizacao')
        ORDER BY caixas DESC, itens DESC
        LIMIT :limit
    ");
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function mapa_acervo_posicoes(): array
{
    return db()->query("
        SELECT *
        FROM acervo_mapa_posicoes
        ORDER BY sala COLLATE NOCASE, tipo COLLATE NOCASE, numero COLLATE NOCASE, id
    ")->fetchAll();
}

function mapa_acervo_posicao(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    $stmt = db()->prepare('SELECT * FROM acervo_mapa_posicoes WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function mapa_acervo_por_sala(array $rows): array
{
    $salas = [];
    foreach ($rows as $row) {
        $sala = trim((string) ($row['sala'] ?? '')) ?: 'Sem sala';
        $row['capacidade_total'] = mapa_acervo_capacidade_total($row);
        $row['vagas_livres'] = max(0, $row['capacidade_total'] - (int) ($row['caixas_ocupadas'] ?? 0));
        $salas[$sala][] = $row;
    }

    return $salas;
}

function mapa_acervo_resumo(array $rows): array
{
    $resumo = [
        'salas' => 0,
        'estruturas' => count($rows),
        'estantes' => 0,
        'modulos' => 0,
        'prateleiras' => 0,
        'capacidade' => 0,
        'ocupadas' => 0,
        'livres' => 0,
    ];
    $salas = [];

    foreach ($rows as $row) {
        $salas[trim((string) ($row['sala'] ?? '')) ?: 'Sem sala'] = true;
        if (($row['tipo'] ?? '') === 'estante') {
            $resumo['estantes']++;
        } else {
            $resumo['modulos']++;
        }
        $resumo['prateleiras'] += (int) ($row['prateleiras'] ?? 0);
        $resumo['capacidade'] += mapa_acervo_capacidade_total($row);
        $resumo['ocupadas'] += (int) ($row['caixas_ocupadas'] ?? 0);
    }

    $resumo['salas'] = count($salas);
    $resumo['livres'] = max(0, $resumo['capacidade'] - $resumo['ocupadas']);
    return $resumo;
}

function mapa_acervo_capacidade_total(array $row): int
{
    return max(1, (int) ($row['prateleiras'] ?? 1)) * max(1, (int) ($row['capacidade_por_prateleira'] ?? 1));
}

function mapa_acervo_prateleiras_ocupacao(array $row): array
{
    $prateleiras = max(1, (int) ($row['prateleiras'] ?? 1));
    $capacidade = max(1, (int) ($row['capacidade_por_prateleira'] ?? 1));
    $ocupadas = max(0, (int) ($row['caixas_ocupadas'] ?? 0));
    $data = [];
    $raw = trim((string) ($row['prateleiras_ocupacao'] ?? ''));

    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $data = $decoded;
        }
    }

    $result = [];
    $sum = 0;
    for ($i = 1; $i <= $prateleiras; $i++) {
        $value = max(0, min($capacidade, (int) ($data[$i - 1] ?? 0)));
        $result[] = $value;
        $sum += $value;
    }

    if ($sum === 0 && $ocupadas > 0) {
        $remaining = $ocupadas;
        foreach ($result as $idx => $_value) {
            $value = min($capacidade, $remaining);
            $result[$idx] = $value;
            $remaining = max(0, $remaining - $value);
        }
    }

    return $result;
}

function mapa_acervo_tipo_label(string $tipo): string
{
    return $tipo === 'estante' ? 'Estante' : 'Modulo deslizante';
}

function mapa_acervo_vazios_rows(): array
{
    $rows = [];
    foreach (mapa_acervo_posicoes() as $row) {
        $capacidadeTotal = mapa_acervo_capacidade_total($row);
        $ocupadas = max(0, (int) ($row['caixas_ocupadas'] ?? 0));
        $livres = max(0, $capacidadeTotal - $ocupadas);
        if ($livres <= 0) {
            continue;
        }

        $rows[] = [
            'Sala' => (string) ($row['sala'] ?? ''),
            'Tipo' => mapa_acervo_tipo_label((string) ($row['tipo'] ?? '')),
            'Modulo' => (string) ($row['numero'] ?? ''),
            'Estante' => (string) ($row['numero_estante'] ?? ''),
            'Prateleiras' => (int) ($row['prateleiras'] ?? 0),
            'Caixas por prateleira' => (int) ($row['capacidade_por_prateleira'] ?? 0),
            'Capacidade total' => $capacidadeTotal,
            'Caixas ocupadas' => $ocupadas,
            'Espacos vazios' => $livres,
            'Observacao' => (string) ($row['observacao'] ?? ''),
        ];
    }

    return $rows;
}

function diagnostic_snapshot(): array
{
    $files = function_exists('planilha_import_files') ? planilha_import_files() : [];
    $indicadores = function_exists('indicador_planilha_files') ? indicador_planilha_files() : [];
    return [
        'modo' => app_storage_mode(),
        'railway' => app_running_on_railway(),
        'supabase' => supabase_status(),
        'db_path' => DB_PATH,
        'db_size' => is_file(DB_PATH) ? filesize(DB_PATH) : 0,
        'fts' => acervo_fts_available(),
        'planilhas' => count($files),
        'indicadores_planilhas' => count($indicadores),
        'jobs' => recent_import_jobs(6),
        'eventos' => recent_system_events(10),
    ];
}

function planilha_validation_summary(): array
{
    if (!function_exists('indicador_planilha_files')) {
        return [];
    }

    $rows = [];
    foreach (indicador_planilha_files() as $file) {
        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($file);
            $sheets = [];
            foreach ($reader->listWorksheetInfo($file) as $sheet) {
                $name = (string) ($sheet['worksheetName'] ?? '');
                if ($name === '') {
                    continue;
                }
                $sheets[] = [
                    'nome' => $name,
                    'linhas' => (int) ($sheet['totalRows'] ?? 0),
                    'colunas' => (int) ($sheet['totalColumns'] ?? 0),
                    'formato' => normalize_search_text($name) === 'total' ? 'resumo' : 'semanal',
                ];
            }
            $rows[] = ['arquivo' => basename($file), 'status' => 'ok', 'abas' => $sheets, 'erro' => ''];
        } catch (Throwable $e) {
            $rows[] = ['arquivo' => basename($file), 'status' => 'erro', 'abas' => [], 'erro' => $e->getMessage()];
        }
    }
    return $rows;
}

function acervo_totals(): array
{
    $pdo = db();
    $pastasFuncionais = (int) $pdo->query("
        SELECT COUNT(*)
        FROM acervo
        WHERE LOWER(COALESCE(ASSUNTO, '') || ' ' || COALESCE(OBSERVACAO, '') || ' ' || COALESCE(FONTE_ARQUIVO, '')) LIKE '%pasta funcional%'
           OR LOWER(COALESCE(ASSUNTO, '') || ' ' || COALESCE(OBSERVACAO, '') || ' ' || COALESCE(FONTE_ARQUIVO, '')) LIKE '%pastas funcionais%'
           OR LOWER(COALESCE(ASSUNTO, '') || ' ' || COALESCE(OBSERVACAO, '') || ' ' || COALESCE(FONTE_ARQUIVO, '')) LIKE '%pasta_funcional%'
           OR LOWER(COALESCE(ASSUNTO, '') || ' ' || COALESCE(OBSERVACAO, '') || ' ' || COALESCE(FONTE_ARQUIVO, '')) LIKE '%pastas_funcionais%'
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
        'caixas' => (int) $pdo->query("SELECT COUNT(DISTINCT TRIM(CAIXA)) FROM acervo WHERE TRIM(COALESCE(CAIXA, '')) <> '' AND UPPER(TRIM(CAIXA)) NOT IN ('---', 'NAN', 'NONE')")->fetchColumn(),
        'processos' => (int) $pdo->query("SELECT COUNT(DISTINCT TRIM(PROCESSO)) FROM acervo WHERE TRIM(COALESCE(PROCESSO, '')) <> '' AND UPPER(TRIM(PROCESSO)) NOT IN ('---', 'NAN', 'NONE')")->fetchColumn(),
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
