<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function supabase_url(): string
{
    return rtrim((string) (getenv('SUPABASE_URL') ?: ''), '/');
}

function supabase_key(): string
{
    return (string) (getenv('SUPABASE_KEY') ?: getenv('SUPABASE_ANON_KEY') ?: getenv('SUPABASE_SERVICE_ROLE_KEY') ?: getenv('SUPABASE_SERVICE_KEY') ?: '');
}

function supabase_enabled(): bool
{
    return app_running_on_railway() && supabase_url() !== '' && supabase_key() !== '';
}

function supabase_status(): string
{
    if (!app_running_on_railway()) {
        return 'modo local: usando banco SQLite';
    }

    return supabase_enabled() ? 'Railway: Supabase conectado/configurado' : 'Railway: SUPABASE_URL e SUPABASE_KEY não configurados';
}

function supabase_request(string $method, string $table, array $payload = [], array $query = [], bool $mandatory = true): array
{
    if (!supabase_enabled()) {
        if ($mandatory) {
            throw new RuntimeException('Supabase obrigatório não configurado. Configure SUPABASE_URL e SUPABASE_KEY no Railway.');
        }
        return [];
    }

    $base = supabase_url();
    if (str_ends_with($base, '/rest/v1')) {
        $endpoint = $base . '/' . rawurlencode($table);
    } else {
        $endpoint = $base . '/rest/v1/' . rawurlencode($table);
    }

    if ($query) {
        $endpoint .= '?' . http_build_query($query);
    }

    $headers = [
        'apikey: ' . supabase_key(),
        'Authorization: Bearer ' . supabase_key(),
        'Content-Type: application/json',
        'Accept: application/json',
        'Prefer: return=representation,resolution=merge-duplicates',
    ];

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => $mandatory ? 8 : 2,
        CURLOPT_TIMEOUT => $mandatory ? 30 : 6,
    ]);

    if (in_array(strtoupper($method), ['POST', 'PATCH', 'PUT'], true)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    $raw = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($raw === false || $error !== '' || $status >= 400) {
        $message = $error !== '' ? $error : (string) $raw;
        if ($mandatory) {
            throw new RuntimeException('Falha Supabase (' . $status . '): ' . $message);
        }
        return [];
    }

    $decoded = json_decode((string) $raw, true);
    return is_array($decoded) ? $decoded : [];
}

function supabase_fetch_user(string $login, string $senha): ?array
{
    if (!supabase_enabled()) {
        return null;
    }

    foreach (['usuario', 'login', 'utilizador'] as $column) {
        $rows = supabase_request('GET', 'usuarios', [], [
            'select' => '*',
            $column => 'ilike.' . $login,
            'senha' => 'eq.' . $senha,
            'limit' => '1',
        ], false);

        if ($rows) {
            return $rows[0];
        }
    }

    return null;
}

function supabase_update_user_password(string $login, string $senha): void
{
    if (!supabase_enabled()) {
        throw new RuntimeException('Supabase obrigatório não configurado. Configure SUPABASE_URL e SUPABASE_KEY no Railway.');
    }

    $existingUser = supabase_request('GET', 'usuarios', [], [
        'select' => 'id,usuario',
        'usuario' => 'ilike.' . $login,
        'limit' => '1',
    ], false);

    $rows = supabase_request('PATCH', 'usuarios', ['senha' => $senha], [
        'usuario' => 'ilike.' . $login,
    ], true);

    if ($rows) {
        return;
    }

    foreach (['login', 'utilizador'] as $column) {
        $rows = supabase_request('PATCH', 'usuarios', ['senha' => $senha], [
            $column => 'ilike.' . $login,
        ], false);
        if ($rows) {
            return;
        }
    }

    if ($existingUser) {
        throw new RuntimeException('O usuário ' . $login . ' existe no Supabase, mas a tabela usuarios não permitiu UPDATE. Aplique a migration supabase/migrations/20260618120000_allow_usuarios_update.sql no Supabase de produção.');
    }

    throw new RuntimeException('Não foi possível atualizar a senha no Supabase para o usuário ' . $login . '.');
}

function supabase_upsert(string $table, array $row, string $onConflict = ''): array
{
    $query = $onConflict !== '' ? ['on_conflict' => $onConflict] : [];
    return supabase_request('POST', $table, $row, $query, true);
}

function supabase_fetch_all(string $table, array $query = [], int $pageSize = 1000): array
{
    $rows = [];
    $offset = 0;

    do {
        $page = supabase_request('GET', $table, [], $query + [
            'limit' => (string) $pageSize,
            'offset' => (string) $offset,
        ], false);

        $count = count($page);
        if ($count > 0) {
            array_push($rows, ...$page);
        }
        $offset += $pageSize;
    } while ($count === $pageSize);

    return $rows;
}

function supabase_sync_on_login(): array
{
    if (!supabase_enabled()) {
        return ['enabled' => false, 'usuarios' => 0, 'acervo' => 0, 'indicadores' => 0, 'mapa_posicoes' => 0, 'mapa_setores' => 0];
    }

    $syncedUsers = supabase_sync_usuarios();
    $syncedAcervo = supabase_sync_inventario();
    $syncedIndicadores = supabase_sync_indicadores();
    $syncedMapaPosicoes = supabase_sync_mapa_posicoes();
    $syncedMapaSetores = supabase_sync_mapa_setores();

    return [
        'enabled' => true,
        'usuarios' => $syncedUsers,
        'acervo' => $syncedAcervo,
        'indicadores' => $syncedIndicadores,
        'mapa_posicoes' => $syncedMapaPosicoes,
        'mapa_setores' => $syncedMapaSetores,
    ];
}

function supabase_sync_usuarios(): int
{
    $rows = supabase_fetch_all('usuarios', ['select' => '*', 'order' => 'id.asc']);
    foreach ($rows as $row) {
        mirror_user_local(normalize_remote_user($row));
    }

    return count($rows);
}

function supabase_sync_inventario(): int
{
    $rows = supabase_fetch_all('inventario', ['select' => '*', 'order' => 'id.asc']);
    foreach ($rows as $row) {
        supabase_mirror_acervo_local(supabase_normalize_inventario_row($row));
    }

    return count($rows);
}

function supabase_sync_indicadores(): int
{
    $table = getenv('SUPABASE_INDICADORES_TABLE') ?: 'indicadores';
    $rows = supabase_fetch_all($table, ['select' => '*', 'order' => 'criado_em.desc']);
    foreach ($rows as $row) {
        mirror_indicador_local($row);
    }

    return count($rows);
}

function supabase_sync_mapa_posicoes(): int
{
    $rows = supabase_fetch_all('acervo_mapa_posicoes', ['select' => '*', 'order' => 'id.asc']);
    foreach ($rows as $row) {
        supabase_mirror_mapa_posicao_local($row);
    }

    return count($rows);
}

function supabase_sync_mapa_setores(): int
{
    $rows = supabase_fetch_all('acervo_mapa_setores', ['select' => '*', 'order' => 'id.asc']);
    foreach ($rows as $row) {
        supabase_mirror_mapa_setor_local($row);
    }

    return count($rows);
}

function supabase_json_text(mixed $value): string
{
    if (is_array($value) || is_object($value)) {
        return json_encode($value, JSON_UNESCAPED_UNICODE) ?: '[]';
    }

    $text = trim((string) $value);
    return $text !== '' ? $text : '[]';
}

function supabase_mirror_mapa_posicao_local(array $row): void
{
    $data = [
        ':id' => (int) ($row['id'] ?? 0),
        ':sala' => (string) ($row['sala'] ?? ''),
        ':tipo' => (string) ($row['tipo'] ?? 'modulo_deslizante'),
        ':numero' => (string) ($row['numero'] ?? ''),
        ':numero_estante' => (string) ($row['numero_estante'] ?? ''),
        ':prateleiras' => max(1, (int) ($row['prateleiras'] ?? 1)),
        ':capacidade_por_prateleira' => max(1, (int) ($row['capacidade_por_prateleira'] ?? 1)),
        ':caixas_ocupadas' => max(0, (int) ($row['caixas_ocupadas'] ?? 0)),
        ':prateleiras_ocupacao' => supabase_json_text($row['prateleiras_ocupacao'] ?? []),
        ':caixas_cores' => supabase_json_text($row['caixas_cores'] ?? []),
        ':cor_setor' => (string) ($row['cor_setor'] ?? '#0ea5e9'),
        ':observacao' => (string) ($row['observacao'] ?? ''),
        ':criado_em' => (string) ($row['criado_em'] ?? date('Y-m-d H:i:s')),
        ':atualizado_em' => (string) ($row['atualizado_em'] ?? date('Y-m-d H:i:s')),
    ];

    if ($data[':id'] <= 0) {
        return;
    }

    $exists = db()->prepare('SELECT COUNT(*) FROM acervo_mapa_posicoes WHERE id = :id');
    $exists->execute([':id' => $data[':id']]);

    if ((int) $exists->fetchColumn() > 0) {
        db()->prepare('
            UPDATE acervo_mapa_posicoes SET
                sala = :sala,
                tipo = :tipo,
                numero = :numero,
                numero_estante = :numero_estante,
                prateleiras = :prateleiras,
                capacidade_por_prateleira = :capacidade_por_prateleira,
                caixas_ocupadas = :caixas_ocupadas,
                prateleiras_ocupacao = :prateleiras_ocupacao,
                caixas_cores = :caixas_cores,
                cor_setor = :cor_setor,
                observacao = :observacao,
                criado_em = :criado_em,
                atualizado_em = :atualizado_em
            WHERE id = :id
        ')->execute($data);
        return;
    }

    db()->prepare('
        INSERT INTO acervo_mapa_posicoes
            (id, sala, tipo, numero, numero_estante, prateleiras, capacidade_por_prateleira, caixas_ocupadas, prateleiras_ocupacao, caixas_cores, cor_setor, observacao, criado_em, atualizado_em)
        VALUES
            (:id, :sala, :tipo, :numero, :numero_estante, :prateleiras, :capacidade_por_prateleira, :caixas_ocupadas, :prateleiras_ocupacao, :caixas_cores, :cor_setor, :observacao, :criado_em, :atualizado_em)
    ')->execute($data);
}

function supabase_mirror_mapa_setor_local(array $row): void
{
    $data = [
        ':id' => (int) ($row['id'] ?? 0),
        ':nome' => (string) ($row['nome'] ?? ''),
        ':cor' => strtolower((string) ($row['cor'] ?? '#0ea5e9')),
        ':criado_em' => (string) ($row['criado_em'] ?? date('Y-m-d H:i:s')),
    ];

    if ($data[':id'] <= 0) {
        return;
    }

    $exists = db()->prepare('SELECT COUNT(*) FROM acervo_mapa_setores WHERE id = :id');
    $exists->execute([':id' => $data[':id']]);

    if ((int) $exists->fetchColumn() > 0) {
        db()->prepare('
            UPDATE acervo_mapa_setores
               SET nome = :nome,
                   cor = :cor,
                   criado_em = :criado_em
             WHERE id = :id
        ')->execute($data);
        return;
    }

    db()->prepare('
        INSERT INTO acervo_mapa_setores (id, nome, cor, criado_em)
        VALUES (:id, :nome, :cor, :criado_em)
    ')->execute($data);
}

function mirror_indicador_local(array $row): void
{
    $data = (string) ($row['data'] ?? '');
    $colaborador = (string) ($row['colaborador'] ?? '');
    $dados = (string) ($row['dados_json'] ?? '{}');
    $criadoEm = (string) ($row['criado_em'] ?? date('c'));

    $exists = db()->prepare('SELECT id FROM indicadores WHERE data = :data AND colaborador = :colaborador AND dados_json = :dados LIMIT 1');
    $exists->execute([':data' => $data, ':colaborador' => $colaborador, ':dados' => $dados]);
    if ($exists->fetchColumn()) {
        return;
    }

    db()->prepare('INSERT INTO indicadores (colaborador, data, dados_json, criado_em) VALUES (:colaborador, :data, :dados, :criado_em)')
        ->execute([':colaborador' => $colaborador, ':data' => $data, ':dados' => $dados, ':criado_em' => $criadoEm]);
}

function supabase_normalize_inventario_row(array $row): array
{
    $observacao = (string) ($row['observacao'] ?? '');
    $id = supabase_extract_id_unico($observacao);
    if ($id === '') {
        $id = 'supabase_' . hash('sha256', (string) ($row['id'] ?? json_encode($row)));
    }

    $local = [
        'ID_UNICO' => $id,
        'UNIDADE' => normalize_text((string) ($row['unidade'] ?? '')),
        'ASSUNTO' => normalize_text((string) ($row['assuntos'] ?? '')),
        'INTERESSADO' => normalize_text((string) ($row['interessados'] ?? '')),
        'DATA' => normalize_text((string) ($row['data'] ?? '')),
        'TEMPORALIDADE' => normalize_text((string) ($row['n_cod_temp'] ?? '')),
        'CAIXA' => normalize_text((string) ($row['n_caixas'] ?? '')),
        'PROCESSO' => normalize_text((string) ($row['n_processos'] ?? $row['tipo_documento'] ?? '')),
        'LOCALIZACAO' => normalize_text((string) ($row['localizacao'] ?? '')),
        'OBSERVACAO' => normalize_text(trim(preg_replace('/\s*ID_UNICO=[^\s]+/i', '', $observacao) ?? $observacao)),
        'VOLUMES' => normalize_text((string) ($row['volumes'] ?? '')),
        'RESPONSAVEL' => normalize_text((string) ($row['responsavel'] ?? '')),
        'DATA_LIMITE' => '---',
        'ALTERADO_POR' => 'Supabase',
        'ULTIMA_ALTERACAO' => normalize_text((string) ($row['data_cadastro'] ?? '')),
        'STATUS_EMPRESTIMO' => '---',
        'QUEM_RETIROU' => '---',
        'FONTE_ARQUIVO' => 'supabase',
    ];
    $local['TEXTO_GERAL'] = build_texto_geral($local);

    return $local;
}

function supabase_extract_id_unico(string $observacao): string
{
    if (preg_match('/ID_UNICO=([A-Za-z0-9_-]+)/', $observacao, $matches)) {
        return $matches[1];
    }

    return '';
}

function supabase_mirror_acervo_local(array $row): void
{
    $exists = db()->prepare('SELECT COUNT(*) FROM acervo WHERE ID_UNICO = :id');
    $exists->execute([':id' => $row['ID_UNICO']]);

    if ((int) $exists->fetchColumn() > 0) {
        db()->prepare("
            UPDATE acervo SET
                UNIDADE = :UNIDADE,
                ASSUNTO = :ASSUNTO,
                INTERESSADO = :INTERESSADO,
                DATA = :DATA,
                TEMPORALIDADE = :TEMPORALIDADE,
                CAIXA = :CAIXA,
                PROCESSO = :PROCESSO,
                LOCALIZACAO = :LOCALIZACAO,
                OBSERVACAO = :OBSERVACAO,
                VOLUMES = :VOLUMES,
                RESPONSAVEL = :RESPONSAVEL,
                DATA_LIMITE = :DATA_LIMITE,
                ALTERADO_POR = :ALTERADO_POR,
                ULTIMA_ALTERACAO = :ULTIMA_ALTERACAO,
                STATUS_EMPRESTIMO = :STATUS_EMPRESTIMO,
                QUEM_RETIROU = :QUEM_RETIROU,
                FONTE_ARQUIVO = :FONTE_ARQUIVO,
                TEXTO_GERAL = :TEXTO_GERAL
            WHERE ID_UNICO = :ID_UNICO
        ")->execute($row);
        return;
    }

    db()->prepare("
        INSERT INTO acervo
            (ID_UNICO, UNIDADE, ASSUNTO, INTERESSADO, DATA, TEMPORALIDADE, CAIXA, PROCESSO, LOCALIZACAO, OBSERVACAO, VOLUMES, RESPONSAVEL, DATA_LIMITE, ALTERADO_POR, ULTIMA_ALTERACAO, STATUS_EMPRESTIMO, QUEM_RETIROU, FONTE_ARQUIVO, TEXTO_GERAL)
        VALUES
            (:ID_UNICO, :UNIDADE, :ASSUNTO, :INTERESSADO, :DATA, :TEMPORALIDADE, :CAIXA, :PROCESSO, :LOCALIZACAO, :OBSERVACAO, :VOLUMES, :RESPONSAVEL, :DATA_LIMITE, :ALTERADO_POR, :ULTIMA_ALTERACAO, :STATUS_EMPRESTIMO, :QUEM_RETIROU, :FONTE_ARQUIVO, :TEXTO_GERAL)
    ")->execute($row);
}
