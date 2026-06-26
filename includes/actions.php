<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/supabase.php';

function handle_actions(): void
{
    if (($_GET['logout'] ?? '') === '1') {
        logout_user();
    }

    if (!isset($_SESSION['user'])) {
        return;
    }

    $action = $_POST['action'] ?? '';
    if (password_change_required() && $action !== 'change_password' && current_page() !== 'trocar_senha') {
        $_SESSION['flash_error'] = 'Troque sua senha para continuar usando o sistema.';
        redirect_to('trocar_senha');
    }

    if (($_GET['export'] ?? '') === 'acervo') {
        require_once __DIR__ . '/export.php';
        if (($_GET['cadastros'] ?? '') === '1') {
            export_xlsx('suad_' . date('dmY') . '.xlsx', planilha_export_rows(), 'Cadastros');
        }

        export_xlsx_template_query_mapped(
            'acervo_diarq_' . date('dmY') . '.xlsx',
            ASSETS_DIR . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'planilha_padrao_inventario.xlsx',
            '
            SELECT
                UNIDADE, CAIXA, TEMPORALIDADE, OBSERVACAO, PROCESSO, VOLUMES,
                ASSUNTO, INTERESSADO, LOCALIZACAO, RESPONSAVEL, DATA, DATA_LIMITE,
                TEXTO_GERAL, FONTE_ARQUIVO
            FROM acervo
            WHERE FONTE_ARQUIVO NOT LIKE :indicadores
            ORDER BY CAIXA, ASSUNTO
            ',
            'export_inventario_padrao_row',
            [':indicadores' => 'INDICADORES - 2026.xlsx%']
        );
    }

    if (($_GET['export'] ?? '') === 'locais_vazios') {
        require_once __DIR__ . '/export.php';
        if (trim((string) ($_GET['format'] ?? '')) === 'pdf') {
            require_once __DIR__ . '/pdf.php';
            output_locais_vazios_pdf(locais_vazios_rows());
        }

        export_xlsx('locais_vazios_' . date('dmY') . '.xlsx', locais_vazios_rows(), 'Locais Vazios');
    }

    if (($_GET['export'] ?? '') === 'mapa_vazios') {
        require_once __DIR__ . '/export.php';
        $rows = mapa_acervo_vazios_rows();
        if (trim((string) ($_GET['format'] ?? '')) === 'pdf') {
            require_once __DIR__ . '/pdf.php';
            output_mapa_acervo_vazios_pdf($rows);
        }

        export_xlsx('mapa_acervo_espacos_vazios_' . date('dmY') . '.xlsx', $rows, 'Espacos Vazios');
    }

    if (($_GET['export'] ?? '') === 'pendentes') {
        require_once __DIR__ . '/export.php';
        export_xlsx('temporalidade_pendente.xlsx', temporalidade_pendente(), 'Pendentes');
    }

    if (($_GET['export'] ?? '') === 'indicadores') {
        require_once __DIR__ . '/export.php';
        import_indicadores_planilhas(false);
        export_xlsx('indicadores_diarq_' . date('dmY') . '.xlsx', indicadores_export_rows($_GET), 'Indicadores');
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    try {
        if ($action === 'change_password') {
            change_logged_user_password(
                $_POST['senha_atual'] ?? '',
                $_POST['nova_senha'] ?? '',
                $_POST['confirmar_senha'] ?? ''
            );
            $_SESSION['flash_success'] = 'Senha alterada com sucesso.';
            redirect_to('busca');
        }

        if (password_change_required()) {
            $_SESSION['flash_error'] = 'Troque sua senha para continuar usando o sistema.';
            redirect_to('trocar_senha');
        }

        if ($action === 'sync_now') {
            if (trigger_silent_sync('manual')) {
                $_SESSION['flash_success'] = 'Sincronizacao iniciada em segundo plano. Voce pode continuar usando o sistema; os dados serao atualizados em silencio.';
                system_event('sync_manual_agendada', 'Sincronizacao manual iniciada em segundo plano');
            } else {
                $_SESSION['flash_success'] = 'Ja existe uma sincronizacao em andamento. Aguarde alguns minutos e atualize a pagina.';
            }
            redirect_to($_POST['return_page'] ?? current_page());
        }

        if ($action === 'pdf_etiqueta') {
            require_once __DIR__ . '/pdf.php';
            output_etiqueta_pdf($_POST);
        }

        if ($action === 'pdf_guia') {
            require_once __DIR__ . '/pdf.php';
            $_POST['responsavel'] = $_SESSION['user']['nome'] ?? '';
            $_POST['data'] = $_POST['data'] ?: date('d/m/Y');
            output_guia_pdf($_POST);
        }

        if ($action === 'save_acervo') {
            save_acervo();
            redirect_to_return_url($_POST['return_url'] ?? '', $_POST['return_page'] ?? 'planilha');
        }

        if ($action === 'move_acervo') {
            register_acervo_movement();
            redirect_to_return_url($_POST['return_url'] ?? '', $_POST['return_page'] ?? 'busca');
        }

        if ($action === 'save_sei_demanda') {
            save_sei_demanda();
            redirect_to($_POST['return_page'] ?? 'busca');
        }

        if ($action === 'skip_sei_demanda' && user_is_admin()) {
            skip_sei_demanda();
            redirect_to($_POST['return_page'] ?? 'rel_demanda_sei');
        }

        if ($action === 'save_manual_processos') {
            save_manual_processos();
            redirect_to('cad_processo');
        }

        if ($action === 'delete_acervo') {
            $id = $_POST['id'] ?? '';
            delete_acervo_ids([$id]);
            $_SESSION['flash_success'] = 'Cadastro excluido.';
            redirect_to('planilha');
        }

        if ($action === 'delete_acervo_bulk') {
            $ids = isset($_POST['delete_one']) && $_POST['delete_one'] !== ''
                ? [$_POST['delete_one']]
                : ($_POST['ids'] ?? []);
            $deleted = delete_acervo_ids(is_array($ids) ? $ids : []);
            $_SESSION['flash_success'] = $deleted . ' cadastro(s) excluido(s).';
            redirect_to('planilha');
        }

        if ($action === 'save_user' && user_is_admin()) {
            save_user();
            redirect_to('gestao_usuarios');
        }

        if ($action === 'delete_user' && user_is_admin()) {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $login = db()->prepare('SELECT login FROM usuarios WHERE id = :id');
                $login->execute([':id' => $id]);
                $loginValue = (string) $login->fetchColumn();
                if ($loginValue !== '' && supabase_enabled()) {
                    supabase_request('DELETE', 'usuarios', [], ['usuario' => 'eq.' . $loginValue], true);
                }
                db()->prepare('DELETE FROM usuarios WHERE id = :id AND UPPER(login) <> "ADMIN"')->execute([':id' => $id]);
            }
            redirect_to('gestao_usuarios');
        }

        if ($action === 'save_indicadores') {
            save_indicadores();
            redirect_to('indicadores_semanal');
        }

        if ($action === 'save_mapa_posicao') {
            save_mapa_posicao();
            redirect_to('mapa_acervo');
        }

        if ($action === 'save_mapa_caixa_cor') {
            save_mapa_caixa_cor();
            if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
                exit;
            }
            redirect_to('mapa_acervo');
        }

        if ($action === 'save_mapa_setor_cor') {
            save_mapa_setor_cor();
            if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
                exit;
            }
            redirect_to('mapa_acervo');
        }

        if ($action === 'delete_mapa_posicao') {
            delete_mapa_posicao();
            redirect_to('mapa_acervo');
        }

        if ($action === 'save_mapa_setor') {
            save_mapa_setor();
            redirect_to('mapa_acervo');
        }

        if ($action === 'delete_mapa_setor') {
            delete_mapa_setor();
            redirect_to('mapa_acervo');
        }
    } catch (Throwable $e) {
        $_SESSION['flash_error'] = $e->getMessage();
        if ($action === 'save_acervo') {
            redirect_to_return_url($_POST['return_url'] ?? '', $_POST['return_page'] ?? 'busca');
        }
        redirect_to($_POST['return_page'] ?? current_page());
    }
}

function register_acervo_movement(): void
{
    if (!user_can_move_acervo()) {
        throw new RuntimeException('Seu usuário não possui permissão para registrar empréstimos.');
    }

    $id = trim((string) ($_POST['ID_UNICO'] ?? ''));
    $movement = trim((string) ($_POST['movimento'] ?? ''));
    $solicitante = normalize_text($_POST['solicitante'] ?? '');
    $setor = normalize_text($_POST['setor'] ?? '');
    $dateValue = trim((string) ($_POST['data_movimento'] ?? ''));
    if ($id === '' || !in_array($movement, ['saida', 'retorno'], true)) {
        throw new RuntimeException('Movimentação inválida.');
    }
    if ($solicitante === '' || $setor === '') {
        throw new RuntimeException('Informe o solicitante e o setor da movimentação.');
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateValue);
    if ($date === false || $date->format('Y-m-d') !== $dateValue) {
        throw new RuntimeException('Informe uma data válida para a movimentação.');
    }

    $item = db()->prepare('SELECT STATUS_EMPRESTIMO FROM acervo WHERE ID_UNICO = :id LIMIT 1');
    $item->execute([':id' => $id]);
    $current = $item->fetch();
    if (!$current) {
        throw new RuntimeException('Item do acervo não encontrado.');
    }

    $isOut = ($current['STATUS_EMPRESTIMO'] ?? '') === 'EMPRESTADO';
    if ($movement === 'saida' && $isOut) {
        throw new RuntimeException('Este item já está fora do arquivo.');
    }
    if ($movement === 'retorno' && !$isOut) {
        throw new RuntimeException('Este item já está disponível no arquivo.');
    }

    $user = $_SESSION['user'] ?? [];
    $actor = normalize_text($user['nome'] ?? '');
    $status = $movement === 'saida' ? 'EMPRESTADO' : '---';
    db()->prepare('UPDATE acervo SET STATUS_EMPRESTIMO = :status, QUEM_RETIROU = :actor, ALTERADO_POR = :actor, ULTIMA_ALTERACAO = :updated WHERE ID_UNICO = :id')
        ->execute([':status' => $status, ':actor' => $actor, ':updated' => date('d/m/Y H:i:s'), ':id' => $id]);
    db()->prepare('INSERT INTO movimentacoes_acervo (acervo_id, tipo, solicitante, setor, data_movimento, observacao, usuario_login, usuario_nome) VALUES (:id, :tipo, :solicitante, :setor, :data, :observacao, :login, :nome)')
        ->execute([
            ':id' => $id,
            ':tipo' => $movement,
            ':solicitante' => $solicitante,
            ':setor' => $setor,
            ':data' => $date->format('d/m/Y'),
            ':observacao' => normalize_text($_POST['observacao'] ?? ''),
            ':login' => normalize_text($user['login'] ?? ''),
            ':nome' => $actor,
        ]);
    system_event('acervo_' . $movement, ucfirst($movement) . ' registrada no acervo', ['id' => $id, 'solicitante' => $solicitante, 'setor' => $setor]);
    $_SESSION['flash_success'] = $movement === 'saida'
        ? 'Saída registrada: item marcado como fora do arquivo.'
        : 'Retorno registrado: item disponível no arquivo.';
}

function planilha_filter_sql(array $input, array &$params): string
{
    $where = [
        "TRIM(COALESCE(PROCESSO, '')) <> ''",
    ];

    $origem = trim((string) ($input['origem'] ?? ''));
    if ($origem === 'manual') {
        $where[] = "(FONTE_ARQUIVO = 'cadastro_manual' OR FONTE_ARQUIVO IS NULL OR FONTE_ARQUIVO = '')";
    } elseif ($origem === 'importado') {
        $where[] = "FONTE_ARQUIVO IS NOT NULL AND FONTE_ARQUIVO <> '' AND FONTE_ARQUIVO <> 'cadastro_manual'";
    }

    $responsavel = trim((string) ($input['responsavel'] ?? ''));
    if ($responsavel !== '' && $responsavel !== 'Todos') {
        $where[] = 'RESPONSAVEL = :responsavel';
        $params[':responsavel'] = $responsavel;
    }

    $term = trim((string) ($input['q'] ?? ''));
    if ($term !== '') {
        $where[] = "(
            CAIXA LIKE :term OR PROCESSO LIKE :term OR INTERESSADO LIKE :term OR
            ASSUNTO LIKE :term OR UNIDADE LIKE :term OR LOCALIZACAO LIKE :term OR
            RESPONSAVEL LIKE :term OR TEMPORALIDADE LIKE :term
        )";
        $params[':term'] = '%' . $term . '%';
    }

    return ' WHERE ' . implode(' AND ', $where);
}

function save_sei_demanda(): void
{
    $user = $_SESSION['user'] ?? [];
    if (!user_is_terceirizado($user)) {
        throw new RuntimeException('A fila de demanda SEI e exclusiva para terceirizados.');
    }

    $state = sei_queue_state($user);
    if (!$state['is_turn']) {
        $nextName = (string) ($state['next']['nome'] ?? 'proximo atendente');
        throw new RuntimeException('Ainda não é sua vez. Próximo atendimento: ' . $nextName . '.');
    }

    $process = normalize_sei_process((string) ($_POST['processo'] ?? ''));
    db()->prepare("
        INSERT INTO sei_atendimentos (usuario_id, usuario_login, usuario_nome, processo, status, criado_em)
        VALUES (:usuario_id, :usuario_login, :usuario_nome, :processo, 'atendido', :criado_em)
    ")->execute([
        ':usuario_id' => (int) ($user['id'] ?? 0),
        ':usuario_login' => (string) ($user['login'] ?? ''),
        ':usuario_nome' => (string) ($user['nome'] ?? ''),
        ':processo' => $process,
        ':criado_em' => date('Y-m-d H:i:s'),
    ]);

    $nextState = sei_queue_state($user);
    $_SESSION['flash_success'] = 'Atendimento SEI registrado. Proxima demanda: '
        . (string) ($nextState['next']['nome'] ?? 'fila atualizada') . '.';
}

function skip_sei_demanda(): void
{
    $admin = $_SESSION['user'] ?? [];
    $state = sei_queue_state($admin);
    $next = $state['next'] ?? null;
    if (!$next) {
        throw new RuntimeException('Não há terceirizado na fila para pular.');
    }

    $expectedLogin = trim((string) ($_POST['usuario_login'] ?? ''));
    if ($expectedLogin === '' || strcasecmp($expectedLogin, (string) ($next['login'] ?? '')) !== 0) {
        throw new RuntimeException('A fila mudou. Atualize a pagina antes de pular a vez.');
    }

    db()->prepare("
        INSERT INTO sei_atendimentos
            (usuario_id, usuario_login, usuario_nome, processo, status, pulado_por_login, pulado_por_nome, criado_em)
        VALUES
            (:usuario_id, :usuario_login, :usuario_nome, 'PULADO', 'pulado', :pulado_por_login, :pulado_por_nome, :criado_em)
    ")->execute([
        ':usuario_id' => (int) ($next['id'] ?? 0),
        ':usuario_login' => (string) ($next['login'] ?? ''),
        ':usuario_nome' => (string) ($next['nome'] ?? ''),
        ':pulado_por_login' => (string) ($admin['login'] ?? ''),
        ':pulado_por_nome' => (string) ($admin['nome'] ?? ''),
        ':criado_em' => date('Y-m-d H:i:s'),
    ]);

    $newState = sei_queue_state($admin);
    $_SESSION['flash_success'] = 'Vez de ' . (string) ($next['nome'] ?: $next['login'])
        . ' pulada. Proxima demanda: ' . (string) ($newState['next']['nome'] ?? 'fila atualizada') . '.';
}

function planilha_export_rows(): array
{
    $params = [];
    $where = planilha_filter_sql($_GET, $params);
    $stmt = db()->prepare("
        SELECT
            UNIDADE AS unidade,
            CAIXA AS caixa,
            OBSERVACAO AS tipo_doc,
            PROCESSO AS n_processo,
            VOLUMES AS volumes,
            ASSUNTO AS assunto,
            INTERESSADO AS interessado,
            LOCALIZACAO AS localizacao,
            RESPONSAVEL AS usuario,
            DATA_LIMITE AS data_limite,
            DATA AS data_cadastro,
            TEMPORALIDADE AS cod_temp,
            FONTE_ARQUIVO AS origem
        FROM acervo
        {$where}
        ORDER BY rowid DESC
    ");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function locais_vazios_rows(): array
{
    $stmt = db()->prepare("
        SELECT
            UNIDADE AS unidade,
            CAIXA AS caixa,
            PROCESSO AS n_processo,
            ASSUNTO AS assunto,
            INTERESSADO AS interessado,
            LOCALIZACAO AS localizacao,
            FONTE_ARQUIVO AS origem
        FROM acervo
        WHERE TRIM(COALESCE(LOCALIZACAO, '')) = '' OR LOCALIZACAO = '---'
        ORDER BY CAIXA, PROCESSO
    ");
    $stmt->execute();
    return $stmt->fetchAll();
}

function delete_acervo_ids(array $ids): int
{
    $ids = array_values(array_unique(array_filter(array_map(
        fn ($id) => trim((string) $id),
        $ids
    ))));

    if (!$ids) {
        throw new RuntimeException('Selecione pelo menos um cadastro.');
    }

    if (supabase_enabled()) {
        foreach ($ids as $id) {
            supabase_request('DELETE', 'inventario', [], ['observacao' => 'ilike.*ID_UNICO=' . $id . '*'], true);
        }
    }

    $placeholders = [];
    $params = [];
    foreach ($ids as $index => $id) {
        $key = ':id' . $index;
        $placeholders[] = $key;
        $params[$key] = $id;
    }

    $stmt = db()->prepare('DELETE FROM acervo WHERE ID_UNICO IN (' . implode(',', $placeholders) . ')');
    $stmt->execute($params);
    system_event('acervo_exclusao', 'Cadastro(s) excluido(s)', ['ids' => $ids, 'total' => $stmt->rowCount()]);
    acervo_fts_rebuild();
    return $stmt->rowCount();
}

function save_acervo(): void
{
    $id = trim($_POST['ID_UNICO'] ?? '');
    if ($id === '') {
        $id = hash('sha256', uniqid('diarq_', true));
    }

    $existing = null;
    $existingStmt = db()->prepare('SELECT RESPONSAVEL, FONTE_ARQUIVO, STATUS_EMPRESTIMO, QUEM_RETIROU FROM acervo WHERE ID_UNICO = :id LIMIT 1');
    $existingStmt->execute([':id' => $id]);
    $existing = $existingStmt->fetch() ?: null;

    $statusEmprestimo = normalize_text($_POST['STATUS_EMPRESTIMO'] ?? '');
    $movimento = $_POST['movimento'] ?? '';
    if ($movimento !== '' && !user_can_move_acervo()) {
        throw new RuntimeException('Seu usuário não possui permissão para registrar empréstimos.');
    }
    if ($movimento === 'saida') {
        if (($existing['STATUS_EMPRESTIMO'] ?? '') === 'EMPRESTADO') {
            throw new RuntimeException('Este item já está fora do arquivo.');
        }
        $statusEmprestimo = 'EMPRESTADO';
    } elseif ($movimento === 'retorno') {
        if (($existing['STATUS_EMPRESTIMO'] ?? '') !== 'EMPRESTADO') {
            throw new RuntimeException('Este item já está disponível no arquivo.');
        }
        $statusEmprestimo = '---';
    }

    $row = [
        'ID_UNICO' => $id,
        'UNIDADE' => normalize_text($_POST['UNIDADE'] ?? ''),
        'ASSUNTO' => normalize_text($_POST['ASSUNTO'] ?? ''),
        'INTERESSADO' => normalize_text($_POST['INTERESSADO'] ?? ''),
        'DATA' => normalize_text($_POST['DATA'] ?? ''),
        'TEMPORALIDADE' => normalize_text($_POST['TEMPORALIDADE'] ?? ''),
        'CAIXA' => normalize_text($_POST['CAIXA'] ?? ''),
        'PROCESSO' => normalize_text($_POST['PROCESSO'] ?? ''),
        'LOCALIZACAO' => normalize_text($_POST['LOCALIZACAO'] ?? ''),
        'OBSERVACAO' => normalize_text($_POST['OBSERVACAO'] ?? ''),
        'VOLUMES' => normalize_text($_POST['VOLUMES'] ?? ''),
        'RESPONSAVEL' => normalize_text($existing['RESPONSAVEL'] ?? ($_SESSION['user']['nome'] ?? '')),
        'DATA_LIMITE' => normalize_text($_POST['DATA_LIMITE'] ?? ''),
        'ALTERADO_POR' => $_SESSION['user']['nome'] ?? '',
        'ULTIMA_ALTERACAO' => date('d/m/Y H:i:s'),
        'STATUS_EMPRESTIMO' => $statusEmprestimo,
        'QUEM_RETIROU' => $movimento !== ''
            ? normalize_text($_SESSION['user']['nome'] ?? '')
            : normalize_text($existing['QUEM_RETIROU'] ?? ($_POST['QUEM_RETIROU'] ?? '')),
        'FONTE_ARQUIVO' => normalize_text($existing['FONTE_ARQUIVO'] ?? 'cadastro_web_php'),
    ];
    $row['TEXTO_GERAL'] = build_texto_geral($row);

    if (supabase_enabled()) {
        supabase_request('POST', 'inventario', supabase_acervo_payload($row), [], true);
    }

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
        system_event('acervo_atualizado', 'Cadastro de acervo atualizado', ['id' => $row['ID_UNICO'], 'caixa' => $row['CAIXA'], 'processo' => $row['PROCESSO']]);
        acervo_fts_rebuild();
        return;
    }

    db()->prepare("
        INSERT INTO acervo
            (ID_UNICO, UNIDADE, ASSUNTO, INTERESSADO, DATA, TEMPORALIDADE, CAIXA, PROCESSO, LOCALIZACAO, OBSERVACAO, VOLUMES, RESPONSAVEL, DATA_LIMITE, ALTERADO_POR, ULTIMA_ALTERACAO, STATUS_EMPRESTIMO, QUEM_RETIROU, FONTE_ARQUIVO, TEXTO_GERAL)
        VALUES
            (:ID_UNICO, :UNIDADE, :ASSUNTO, :INTERESSADO, :DATA, :TEMPORALIDADE, :CAIXA, :PROCESSO, :LOCALIZACAO, :OBSERVACAO, :VOLUMES, :RESPONSAVEL, :DATA_LIMITE, :ALTERADO_POR, :ULTIMA_ALTERACAO, :STATUS_EMPRESTIMO, :QUEM_RETIROU, :FONTE_ARQUIVO, :TEXTO_GERAL)
    ")->execute($row);
    system_event('acervo_criado', 'Cadastro de acervo criado', ['id' => $row['ID_UNICO'], 'caixa' => $row['CAIXA'], 'processo' => $row['PROCESSO']]);
    acervo_fts_rebuild();
}

function save_manual_processos(): void
{
    $caixa = trim((string) ($_POST['CAIXA'] ?? ''));
    if ($caixa === '') {
        throw new RuntimeException('Informe o número da caixa.');
    }

    $processos = $_POST['PROCESSO'] ?? [];
    $volumes = $_POST['VOLUMES'] ?? [];
    $interessados = $_POST['INTERESSADO'] ?? [];
    $assuntos = $_POST['ASSUNTO'] ?? [];
    if (!is_array($processos)) {
        throw new RuntimeException('Nenhum processo informado.');
    }

    $saved = 0;
    foreach ($processos as $index => $processo) {
        $processo = trim((string) $processo);
        if ($processo === '') {
            continue;
        }

        $row = [
            'ID_UNICO' => hash('sha256', uniqid('manual_', true) . $caixa . $processo . $index),
            'UNIDADE' => normalize_text($_POST['UNIDADE'] ?? ''),
            'ASSUNTO' => normalize_text((string) ($assuntos[$index] ?? '')),
            'INTERESSADO' => normalize_text((string) ($interessados[$index] ?? '')),
            'DATA' => normalize_text($_POST['DATA'] ?? date('d/m/Y')),
            'TEMPORALIDADE' => normalize_text($_POST['TEMPORALIDADE'] ?? ''),
            'CAIXA' => normalize_text($caixa),
            'PROCESSO' => normalize_text($processo),
            'LOCALIZACAO' => normalize_text($_POST['LOCALIZACAO'] ?? ''),
            'OBSERVACAO' => normalize_text('Cadastro manual' . (trim((string) ($_POST['TIPO_DOCUMENTO'] ?? '')) !== '' ? ' - ' . trim((string) ($_POST['TIPO_DOCUMENTO'] ?? '')) : '')),
            'VOLUMES' => normalize_text((string) ($volumes[$index] ?? '')),
            'RESPONSAVEL' => $_SESSION['user']['nome'] ?? '',
            'DATA_LIMITE' => normalize_text($_POST['DATA_LIMITE'] ?? ''),
            'ALTERADO_POR' => $_SESSION['user']['nome'] ?? '',
            'ULTIMA_ALTERACAO' => date('d/m/Y H:i:s'),
            'STATUS_EMPRESTIMO' => '---',
            'QUEM_RETIROU' => '---',
            'FONTE_ARQUIVO' => 'cadastro_manual',
        ];
        $row['TEXTO_GERAL'] = build_texto_geral($row);

        if (supabase_enabled()) {
            supabase_request('POST', 'inventario', supabase_acervo_payload($row), [], true);
        }
        db()->prepare("
            INSERT INTO acervo
                (ID_UNICO, UNIDADE, ASSUNTO, INTERESSADO, DATA, TEMPORALIDADE, CAIXA, PROCESSO, LOCALIZACAO, OBSERVACAO, VOLUMES, RESPONSAVEL, DATA_LIMITE, ALTERADO_POR, ULTIMA_ALTERACAO, STATUS_EMPRESTIMO, QUEM_RETIROU, FONTE_ARQUIVO, TEXTO_GERAL)
            VALUES
                (:ID_UNICO, :UNIDADE, :ASSUNTO, :INTERESSADO, :DATA, :TEMPORALIDADE, :CAIXA, :PROCESSO, :LOCALIZACAO, :OBSERVACAO, :VOLUMES, :RESPONSAVEL, :DATA_LIMITE, :ALTERADO_POR, :ULTIMA_ALTERACAO, :STATUS_EMPRESTIMO, :QUEM_RETIROU, :FONTE_ARQUIVO, :TEXTO_GERAL)
        ")->execute($row);
        $saved++;
    }

    if ($saved === 0) {
        throw new RuntimeException('Adicione pelo menos um processo ou servidor.');
    }

    $_SESSION['flash_success'] = 'Itens cadastrados na Caixa ' . $caixa . '.';
    system_event('acervo_cadastro_manual', 'Itens cadastrados manualmente', ['caixa' => $caixa, 'total' => $saved]);
    acervo_fts_rebuild();
}

function save_indicadores(): void
{
    $fields = indicador_field_labels();
    $indicadores = [];
    $labels = [];
    foreach ($fields as $key => $label) {
        $value = max(0, (int) ($_POST[$key] ?? 0));
        $indicadores[$key] = $value;
        $labels[$key] = $label;
    }

    $data = $_POST['data'] ?? date('Y-m-d');
    $outraAtividade = trim((string) ($_POST['outra_atv'] ?? ''));
    $observacao = trim((string) ($_POST['observacao'] ?? ''));
    $dados = [
        'origem' => 'sistema_indicadores',
        'indicadores' => $indicadores,
        'labels' => $labels,
        'outra_atv' => $outraAtividade,
        'observacao' => $observacao,
        'dias' => [
            $data => [
                'indicadores' => array_filter($indicadores, static fn ($value) => (int) $value !== 0),
                'outra_atividade' => $outraAtividade !== '' ? [$outraAtividade] : [],
                'observacao' => $observacao !== '' ? [$observacao] : [],
            ],
        ],
    ];

    $row = [
        'colaborador' => $_SESSION['user']['nome'] ?? 'Colaborador',
        'data' => $data,
        'dados_json' => json_encode($dados, JSON_UNESCAPED_UNICODE),
    ];

    if (supabase_enabled()) {
        supabase_request('POST', getenv('SUPABASE_INDICADORES_TABLE') ?: 'indicadores', $row, [], true);
    }

    $stmt = db()->prepare('INSERT INTO indicadores (colaborador, data, dados_json) VALUES (:colaborador, :data, :dados)');
    $stmt->execute([
        ':colaborador' => $row['colaborador'],
        ':data' => $row['data'],
        ':dados' => $row['dados_json'],
    ]);

    $_SESSION['flash_success'] = 'Registro diario de indicadores salvo com sucesso.';
    system_event('indicadores_salvo', 'Registro diario de indicadores salvo', ['colaborador' => $row['colaborador'], 'data' => $row['data']]);
}

function save_mapa_posicao(): void
{
    $id = max(0, (int) ($_POST['id'] ?? 0));
    $sala = trim((string) ($_POST['sala'] ?? ''));
    $tipo = trim((string) ($_POST['tipo'] ?? 'modulo_deslizante'));
    $numero = trim((string) ($_POST['numero'] ?? ''));
    $numeroEstante = trim((string) ($_POST['numero_estante'] ?? ''));
    $prateleiras = max(1, (int) ($_POST['prateleiras'] ?? 1));
    $capacidade = max(1, (int) ($_POST['capacidade_por_prateleira'] ?? 1));
    $caixas = max(0, (int) ($_POST['caixas_ocupadas'] ?? 0));
    $prateleirasInput = is_array($_POST['prateleiras_ocupacao'] ?? null) ? $_POST['prateleiras_ocupacao'] : [];
    $coresInput = is_array($_POST['caixas_cores'] ?? null) ? $_POST['caixas_cores'] : [];
    $corSetor = trim((string) ($_POST['cor_setor'] ?? '#0ea5e9'));
    $observacao = trim((string) ($_POST['observacao'] ?? ''));

    if ($sala === '') {
        throw new RuntimeException('Informe a sala.');
    }
    if (!in_array($tipo, ['modulo_deslizante', 'estante'], true)) {
        throw new RuntimeException('Tipo de estrutura invalido.');
    }
    if ($numero === '') {
        throw new RuntimeException('Informe o número da estante ou módulo.');
    }
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $corSetor)) {
        $corSetor = '#0ea5e9';
    }

    $total = $prateleiras * $capacidade;
    $ocupacaoPrateleiras = [];
    $caixasCores = [];
    $somaPrateleiras = 0;
    for ($i = 1; $i <= $prateleiras; $i++) {
        $value = max(0, (int) ($prateleirasInput[$i - 1] ?? 0));
        if ($value > $capacidade) {
            throw new RuntimeException('A P' . $i . ' não pode passar de ' . $capacidade . ' caixas.');
        }
        $ocupacaoPrateleiras[] = $value;
        $caixasCores[$i - 1] = [];
        for ($box = 0; $box < $capacidade; $box++) {
            $color = strtolower(trim((string) ($coresInput[$i - 1][$box] ?? '')));
            $caixasCores[$i - 1][$box] = preg_match('/^#[0-9a-f]{6}$/', $color) ? $color : '';
        }
        $somaPrateleiras += $value;
    }

    if ($somaPrateleiras > 0 || $prateleirasInput !== []) {
        $caixas = $somaPrateleiras;
    }

    if ($caixas > $total) {
        throw new RuntimeException('As caixas ocupadas não podem passar da capacidade total de ' . $total . '.');
    }

    $params = [
        ':sala' => $sala,
        ':tipo' => $tipo,
        ':numero' => $numero,
        ':numero_estante' => $numeroEstante,
        ':prateleiras' => $prateleiras,
        ':capacidade_por_prateleira' => $capacidade,
        ':caixas_ocupadas' => $caixas,
        ':prateleiras_ocupacao' => json_encode($ocupacaoPrateleiras, JSON_UNESCAPED_UNICODE),
        ':caixas_cores' => json_encode($caixasCores, JSON_UNESCAPED_UNICODE),
        ':cor_setor' => strtolower($corSetor),
        ':observacao' => $observacao,
        ':atualizado_em' => date('Y-m-d H:i:s'),
    ];

    if ($id > 0) {
        $params[':id'] = $id;
        db()->prepare("
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
                atualizado_em = :atualizado_em
            WHERE id = :id
        ")->execute($params);
        $_SESSION['flash_success'] = 'Posicao do mapa atualizada.';
        system_event('mapa_acervo_atualizado', 'Posicao fisica atualizada', ['id' => $id, 'sala' => $sala, 'numero' => $numero]);
        return;
    }

    db()->prepare("
        INSERT INTO acervo_mapa_posicoes
            (sala, tipo, numero, numero_estante, prateleiras, capacidade_por_prateleira, caixas_ocupadas, prateleiras_ocupacao, caixas_cores, cor_setor, observacao, criado_em, atualizado_em)
        VALUES
            (:sala, :tipo, :numero, :numero_estante, :prateleiras, :capacidade_por_prateleira, :caixas_ocupadas, :prateleiras_ocupacao, :caixas_cores, :cor_setor, :observacao, :atualizado_em, :atualizado_em)
    ")->execute($params);
    $_SESSION['flash_success'] = 'Posicao adicionada ao mapa.';
    system_event('mapa_acervo_criado', 'Posicao fisica criada', ['sala' => $sala, 'numero' => $numero]);
}

function delete_mapa_posicao(): void
{
    $id = max(0, (int) ($_POST['id'] ?? 0));
    if ($id <= 0) {
        throw new RuntimeException('Posição não encontrada.');
    }

    db()->prepare('DELETE FROM acervo_mapa_posicoes WHERE id = :id')->execute([':id' => $id]);
    $_SESSION['flash_success'] = 'Posicao removida do mapa.';
    system_event('mapa_acervo_excluido', 'Posicao fisica excluida', ['id' => $id]);
}

function save_mapa_caixa_cor(): void
{
    $id = max(0, (int) ($_POST['id'] ?? 0));
    $shelf = max(0, (int) ($_POST['shelf'] ?? 0));
    $box = max(0, (int) ($_POST['box'] ?? 0));
    $color = strtolower(trim((string) ($_POST['color'] ?? '')));

    if ($id <= 0) {
        throw new RuntimeException('Caixa invalida para colorir.');
    }
    if (!preg_match('/^#[0-9a-f]{6}$/', $color)) {
        throw new RuntimeException('Cor invalida.');
    }

    $stmt = db()->prepare('SELECT * FROM acervo_mapa_posicoes WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('Posição do mapa não encontrada.');
    }

    $prateleiras = max(1, (int) ($row['prateleiras'] ?? 1));
    $capacidade = max(1, (int) ($row['capacidade_por_prateleira'] ?? 1));
    if ($shelf >= $prateleiras || $box >= $capacidade) {
        throw new RuntimeException('Caixa fora da capacidade cadastrada.');
    }

    $cores = mapa_acervo_caixas_cores($row);
    $cores[$shelf][$box] = $color;

    db()->prepare('
        UPDATE acervo_mapa_posicoes
           SET caixas_cores = :caixas_cores,
               atualizado_em = :atualizado_em
         WHERE id = :id
    ')->execute([
        ':caixas_cores' => json_encode($cores, JSON_UNESCAPED_UNICODE),
        ':atualizado_em' => date('Y-m-d H:i:s'),
        ':id' => $id,
    ]);
}

function save_mapa_setor_cor(): void
{
    $id = max(0, (int) ($_POST['id'] ?? 0));
    $color = strtolower(trim((string) ($_POST['color'] ?? '')));

    if ($id <= 0) {
        throw new RuntimeException('Estrutura invalida para colorir.');
    }
    if (!preg_match('/^#[0-9a-f]{6}$/', $color)) {
        throw new RuntimeException('Cor invalida.');
    }

    $stmt = db()->prepare('SELECT id FROM acervo_mapa_posicoes WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if (!$stmt->fetchColumn()) {
        throw new RuntimeException('Posição do mapa não encontrada.');
    }

    db()->prepare('
        UPDATE acervo_mapa_posicoes
           SET cor_setor = :cor_setor,
               caixas_cores = :caixas_cores,
               atualizado_em = :atualizado_em
         WHERE id = :id
    ')->execute([
        ':cor_setor' => $color,
        ':caixas_cores' => '[]',
        ':atualizado_em' => date('Y-m-d H:i:s'),
        ':id' => $id,
    ]);
}

function save_mapa_setor(): void
{
    $nome = trim((string) ($_POST['nome'] ?? ''));
    $cor = strtolower(trim((string) ($_POST['cor'] ?? '')));

    if ($nome === '') {
        throw new RuntimeException('Informe o nome do setor.');
    }
    if (!preg_match('/^#[0-9a-f]{6}$/', $cor)) {
        throw new RuntimeException('Informe uma cor valida para o setor.');
    }

    $stmt = db()->prepare('SELECT COUNT(*) FROM acervo_mapa_setores WHERE lower(cor) = lower(:cor)');
    $stmt->execute([':cor' => $cor]);
    if ((int) $stmt->fetchColumn() > 0) {
        throw new RuntimeException('Essa cor ja esta cadastrada em outro setor.');
    }

    db()->prepare('
        INSERT INTO acervo_mapa_setores (nome, cor, criado_em)
        VALUES (:nome, :cor, :criado_em)
    ')->execute([
        ':nome' => $nome,
        ':cor' => $cor,
        ':criado_em' => date('Y-m-d H:i:s'),
    ]);

    $_SESSION['flash_success'] = 'Setor cadastrado na paleta.';
}

function delete_mapa_setor(): void
{
    $id = max(0, (int) ($_POST['id'] ?? 0));
    if ($id <= 0) {
        throw new RuntimeException('Setor não encontrado.');
    }

    db()->prepare('DELETE FROM acervo_mapa_setores WHERE id = :id')->execute([':id' => $id]);
    $_SESSION['flash_success'] = 'Setor removido da paleta.';
}

function save_user(): void
{
    $id = (int) ($_POST['id'] ?? 0);
    $currentUser = [];
    if ($id > 0) {
        $stmt = db()->prepare('SELECT * FROM usuarios WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $currentUser = $stmt->fetch() ?: [];
        if (!$currentUser) {
            throw new RuntimeException('Usuário não encontrado.');
        }
        if (strtoupper((string) ($currentUser['login'] ?? '')) === 'ADMIN') {
            throw new RuntimeException('O usuário ADMIN não pode ser alterado por aqui.');
        }
    }

    $data = [
        ':nome' => trim($_POST['nome'] ?? ''),
        ':login' => trim($_POST['login'] ?? ''),
        ':senha' => $_POST['senha'] ?? '',
        ':tipo_usuario' => normalize_user_type($_POST['tipo_usuario'] ?? 'Servidor'),
        ':departamento' => $_POST['departamento'] ?? 'DIARQ',
        ':p_extrair_excel' => isset($_POST['p_extrair_excel']) ? 1 : (int) ($currentUser['p_extrair_excel'] ?? 0),
        ':p_sincronizar' => isset($_POST['p_sincronizar']) ? 1 : 0,
        ':p_gerir_usuarios' => isset($_POST['p_gerir_usuarios']) ? 1 : 0,
        ':p_cadastrar_caixa' => isset($_POST['p_cadastrar_caixa']) ? 1 : 0,
        ':p_somente_pesquisa' => isset($_POST['p_somente_pesquisa']) ? 1 : (int) ($currentUser['p_somente_pesquisa'] ?? 0),
        ':p_botao_editar' => isset($_POST['p_botao_editar']) ? 1 : 0,
        ':p_emprestimo' => isset($_POST['p_emprestimo']) ? 1 : 0,
        ':setores_permitidos' => $_POST['setores_permitidos'] ?? ($currentUser['setores_permitidos'] ?? ''),
        ':TROCAR_SENHA' => isset($_POST['TROCAR_SENHA']) ? 1 : 0,
    ];

    if ($id > 0) {
        if (supabase_enabled()) {
            supabase_upsert('usuarios', supabase_user_payload($data), 'usuario');
        }
        $oldLogin = trim((string) ($currentUser['login'] ?? ''));
        if ($oldLogin !== '' && strcasecmp($oldLogin, (string) $data[':login']) !== 0 && supabase_enabled()) {
            supabase_request('DELETE', 'usuarios', [], ['usuario' => 'eq.' . $oldLogin], false);
        }
        $data[':id'] = $id;
        db()->prepare("
            UPDATE usuarios SET
                nome = :nome, login = :login, senha = :senha, tipo_usuario = :tipo_usuario, departamento = :departamento,
                p_extrair_excel = :p_extrair_excel, p_sincronizar = :p_sincronizar, p_gerir_usuarios = :p_gerir_usuarios,
                p_cadastrar_caixa = :p_cadastrar_caixa, p_somente_pesquisa = :p_somente_pesquisa, p_botao_editar = :p_botao_editar,
                p_emprestimo = :p_emprestimo, setores_permitidos = :setores_permitidos, TROCAR_SENHA = :TROCAR_SENHA
            WHERE id = :id
        ")->execute($data);
        if ((int) ($_SESSION['user']['id'] ?? 0) === $id) {
            $_SESSION['user'] = array_merge($_SESSION['user'], [
                'nome' => $data[':nome'],
                'login' => $data[':login'],
                'senha' => $data[':senha'],
                'tipo_usuario' => $data[':tipo_usuario'],
                'departamento' => $data[':departamento'],
                'p_extrair_excel' => $data[':p_extrair_excel'],
                'p_sincronizar' => $data[':p_sincronizar'],
                'p_gerir_usuarios' => $data[':p_gerir_usuarios'],
                'p_cadastrar_caixa' => $data[':p_cadastrar_caixa'],
                'p_somente_pesquisa' => $data[':p_somente_pesquisa'],
                'p_botao_editar' => $data[':p_botao_editar'],
                'p_emprestimo' => $data[':p_emprestimo'],
                'setores_permitidos' => $data[':setores_permitidos'],
                'TROCAR_SENHA' => $data[':TROCAR_SENHA'],
            ]);
        }
        $_SESSION['flash_success'] = 'Cadastro de usuario atualizado.';
        system_event('usuario_atualizado', 'Usuario atualizado', ['login' => $data[':login']]);
        return;
    }

    if (supabase_enabled()) {
        supabase_upsert('usuarios', supabase_user_payload($data), 'usuario');
    }

    db()->prepare("
        INSERT INTO usuarios
            (nome, login, senha, tipo_usuario, departamento, p_extrair_excel, p_sincronizar, p_gerir_usuarios, p_cadastrar_caixa, p_somente_pesquisa, p_botao_editar, p_emprestimo, setores_permitidos, TROCAR_SENHA)
        VALUES
            (:nome, :login, :senha, :tipo_usuario, :departamento, :p_extrair_excel, :p_sincronizar, :p_gerir_usuarios, :p_cadastrar_caixa, :p_somente_pesquisa, :p_botao_editar, :p_emprestimo, :setores_permitidos, :TROCAR_SENHA)
    ")->execute($data);
    system_event('usuario_criado', 'Usuario criado', ['login' => $data[':login']]);
}

function supabase_acervo_payload(array $row): array
{
    return [
        'unidade' => $row['UNIDADE'] ?? '',
        'n_caixas' => $row['CAIXA'] ?? '',
        'n_cod_temp' => $row['TEMPORALIDADE'] ?? '',
        'tipo_documento' => $row['PROCESSO'] ?? '',
        'n_processos' => $row['PROCESSO'] ?? '',
        'volumes' => $row['VOLUMES'] ?? '',
        'assuntos' => $row['ASSUNTO'] ?? '',
        'interessados' => $row['INTERESSADO'] ?? '',
        'localizacao' => $row['LOCALIZACAO'] ?? '',
        'responsavel' => $row['RESPONSAVEL'] ?? '',
        'observacao' => trim(($row['OBSERVACAO'] ?? '') . ' ID_UNICO=' . ($row['ID_UNICO'] ?? '')),
        'data' => $row['DATA'] ?? '',
        'data_cadastro' => date('Y-m-d H:i:s'),
    ];
}

function supabase_user_payload(array $data): array
{
    return [
        'nome' => $data[':nome'],
        'usuario' => $data[':login'],
        'senha' => $data[':senha'],
        'perfil' => supabase_user_profile((int) $data[':p_gerir_usuarios'], (string) ($data[':tipo_usuario'] ?? 'Servidor')),
    ];
}
