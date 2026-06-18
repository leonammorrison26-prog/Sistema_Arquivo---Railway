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

    if (($_GET['export'] ?? '') === 'pendentes') {
        require_once __DIR__ . '/export.php';
        export_xlsx('temporalidade_pendente.xlsx', temporalidade_pendente(), 'Pendentes');
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
            $result = sync_app_data(true);
            $_SESSION['flash_success'] = 'Sincronizacao manual concluida: '
                . (int) ($result['supabase']['acervo'] ?? 0) . ' item(ns) do Supabase, '
                . (int) ($result['supabase']['usuarios'] ?? 0) . ' usuario(s) e '
                . (int) ($result['planilhas']['imported'] ?? 0) . ' registro(s) de planilha.';
            if (($result['planilhas']['completed'] ?? true) === false) {
                $_SESSION['flash_success'] .= ' Importacao parcial para evitar tempo limite; clique em Sincronizar novamente para continuar.';
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
            redirect_to($_POST['return_page'] ?? 'planilha');
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
                if ($loginValue !== '') {
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
    } catch (Throwable $e) {
        $_SESSION['flash_error'] = $e->getMessage();
        redirect_to($_POST['return_page'] ?? current_page());
    }
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

function delete_acervo_ids(array $ids): int
{
    $ids = array_values(array_unique(array_filter(array_map(
        fn ($id) => trim((string) $id),
        $ids
    ))));

    if (!$ids) {
        throw new RuntimeException('Selecione pelo menos um cadastro.');
    }

    foreach ($ids as $id) {
        supabase_request('DELETE', 'inventario', [], ['observacao' => 'ilike.*ID_UNICO=' . $id . '*'], true);
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
    return $stmt->rowCount();
}

function save_acervo(): void
{
    $id = trim($_POST['ID_UNICO'] ?? '');
    if ($id === '') {
        $id = hash('sha256', uniqid('diarq_', true));
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
        'RESPONSAVEL' => $_SESSION['user']['nome'] ?? '',
        'DATA_LIMITE' => normalize_text($_POST['DATA_LIMITE'] ?? ''),
        'ALTERADO_POR' => $_SESSION['user']['nome'] ?? '',
        'ULTIMA_ALTERACAO' => date('d/m/Y H:i:s'),
        'STATUS_EMPRESTIMO' => normalize_text($_POST['STATUS_EMPRESTIMO'] ?? ''),
        'QUEM_RETIROU' => normalize_text($_POST['QUEM_RETIROU'] ?? ''),
        'FONTE_ARQUIVO' => 'cadastro_web_php',
    ];
    $row['TEXTO_GERAL'] = build_texto_geral($row);

    supabase_request('POST', 'inventario', supabase_acervo_payload($row), [], true);

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

function save_manual_processos(): void
{
    $caixa = trim((string) ($_POST['CAIXA'] ?? ''));
    if ($caixa === '') {
        throw new RuntimeException('Informe o numero da caixa.');
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

        supabase_request('POST', 'inventario', supabase_acervo_payload($row), [], true);
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
}

function save_indicadores(): void
{
    $dados = $_POST;
    unset($dados['action']);
    $row = [
        'colaborador' => $_SESSION['user']['nome'] ?? 'Colaborador',
        'data' => $_POST['data'] ?? date('Y-m-d'),
        'dados_json' => json_encode($dados, JSON_UNESCAPED_UNICODE),
    ];

    supabase_request('POST', getenv('SUPABASE_INDICADORES_TABLE') ?: 'indicadores', $row, [], true);

    $stmt = db()->prepare('INSERT INTO indicadores (colaborador, data, dados_json) VALUES (:colaborador, :data, :dados)');
    $stmt->execute([
        ':colaborador' => $row['colaborador'],
        ':data' => $row['data'],
        ':dados' => $row['dados_json'],
    ]);
}

function save_user(): void
{
    $id = (int) ($_POST['id'] ?? 0);
    $data = [
        ':nome' => trim($_POST['nome'] ?? ''),
        ':login' => trim($_POST['login'] ?? ''),
        ':senha' => $_POST['senha'] ?? '',
        ':tipo_usuario' => $_POST['tipo_usuario'] ?? 'Servidor',
        ':departamento' => $_POST['departamento'] ?? 'DIARQ',
        ':p_extrair_excel' => isset($_POST['p_extrair_excel']) ? 1 : 0,
        ':p_sincronizar' => isset($_POST['p_sincronizar']) ? 1 : 0,
        ':p_gerir_usuarios' => isset($_POST['p_gerir_usuarios']) ? 1 : 0,
        ':p_cadastrar_caixa' => isset($_POST['p_cadastrar_caixa']) ? 1 : 0,
        ':p_somente_pesquisa' => isset($_POST['p_somente_pesquisa']) ? 1 : 0,
        ':p_botao_editar' => isset($_POST['p_botao_editar']) ? 1 : 0,
        ':p_emprestimo' => isset($_POST['p_emprestimo']) ? 1 : 0,
        ':setores_permitidos' => $_POST['setores_permitidos'] ?? '',
        ':TROCAR_SENHA' => isset($_POST['TROCAR_SENHA']) ? 1 : 0,
    ];

    if ($id > 0) {
        supabase_upsert('usuarios', supabase_user_payload($data), 'usuario');
        $data[':id'] = $id;
        db()->prepare("
            UPDATE usuarios SET
                nome = :nome, login = :login, senha = :senha, tipo_usuario = :tipo_usuario, departamento = :departamento,
                p_extrair_excel = :p_extrair_excel, p_sincronizar = :p_sincronizar, p_gerir_usuarios = :p_gerir_usuarios,
                p_cadastrar_caixa = :p_cadastrar_caixa, p_somente_pesquisa = :p_somente_pesquisa, p_botao_editar = :p_botao_editar,
                p_emprestimo = :p_emprestimo, setores_permitidos = :setores_permitidos, TROCAR_SENHA = :TROCAR_SENHA
            WHERE id = :id
        ")->execute($data);
        return;
    }

    supabase_upsert('usuarios', supabase_user_payload($data), 'usuario');

    db()->prepare("
        INSERT INTO usuarios
            (nome, login, senha, tipo_usuario, departamento, p_extrair_excel, p_sincronizar, p_gerir_usuarios, p_cadastrar_caixa, p_somente_pesquisa, p_botao_editar, p_emprestimo, setores_permitidos, TROCAR_SENHA)
        VALUES
            (:nome, :login, :senha, :tipo_usuario, :departamento, :p_extrair_excel, :p_sincronizar, :p_gerir_usuarios, :p_cadastrar_caixa, :p_somente_pesquisa, :p_botao_editar, :p_emprestimo, :setores_permitidos, :TROCAR_SENHA)
    ")->execute($data);
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
        'perfil' => ((int) $data[':p_gerir_usuarios'] === 1) ? 'admin' : 'operador',
    ];
}
