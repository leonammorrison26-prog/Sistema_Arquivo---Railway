<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 45000');

    migrate_db($pdo);

    return $pdo;
}

function migrate_db(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS usuarios (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nome TEXT NOT NULL DEFAULT '',
            login TEXT NOT NULL UNIQUE,
            senha TEXT NOT NULL DEFAULT '',
            tipo_usuario TEXT NOT NULL DEFAULT 'Servidor',
            departamento TEXT NOT NULL DEFAULT 'DIARQ',
            p_extrair_excel INTEGER NOT NULL DEFAULT 0,
            p_sincronizar INTEGER NOT NULL DEFAULT 0,
            p_gerir_usuarios INTEGER NOT NULL DEFAULT 0,
            p_cadastrar_caixa INTEGER NOT NULL DEFAULT 0,
            p_somente_pesquisa INTEGER NOT NULL DEFAULT 0,
            p_botao_editar INTEGER NOT NULL DEFAULT 0,
            p_emprestimo INTEGER NOT NULL DEFAULT 0,
            setores_permitidos TEXT NOT NULL DEFAULT '',
            TROCAR_SENHA INTEGER NOT NULL DEFAULT 0,
            criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    ensure_columns($pdo, 'usuarios', [
        'nome' => "TEXT NOT NULL DEFAULT ''",
        'login' => "TEXT NOT NULL DEFAULT ''",
        'senha' => "TEXT NOT NULL DEFAULT ''",
        'tipo_usuario' => "TEXT NOT NULL DEFAULT 'Servidor'",
        'departamento' => "TEXT NOT NULL DEFAULT 'DIARQ'",
        'p_extrair_excel' => "INTEGER NOT NULL DEFAULT 0",
        'p_sincronizar' => "INTEGER NOT NULL DEFAULT 0",
        'p_gerir_usuarios' => "INTEGER NOT NULL DEFAULT 0",
        'p_cadastrar_caixa' => "INTEGER NOT NULL DEFAULT 0",
        'p_somente_pesquisa' => "INTEGER NOT NULL DEFAULT 0",
        'p_botao_editar' => "INTEGER NOT NULL DEFAULT 0",
        'p_emprestimo' => "INTEGER NOT NULL DEFAULT 0",
        'setores_permitidos' => "TEXT NOT NULL DEFAULT ''",
        'TROCAR_SENHA' => "INTEGER NOT NULL DEFAULT 0",
        'criado_em' => "TEXT NOT NULL DEFAULT ''",
    ]);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS acervo (
            ID_UNICO TEXT PRIMARY KEY,
            UNIDADE TEXT DEFAULT '',
            ASSUNTO TEXT DEFAULT '',
            INTERESSADO TEXT DEFAULT '',
            DATA TEXT DEFAULT '',
            TEMPORALIDADE TEXT DEFAULT '',
            CAIXA TEXT DEFAULT '',
            PROCESSO TEXT DEFAULT '',
            LOCALIZACAO TEXT DEFAULT '',
            OBSERVACAO TEXT DEFAULT '',
            VOLUMES TEXT DEFAULT '',
            RESPONSAVEL TEXT DEFAULT '',
            DATA_LIMITE TEXT DEFAULT '',
            ALTERADO_POR TEXT DEFAULT '',
            ULTIMA_ALTERACAO TEXT DEFAULT '',
            STATUS_EMPRESTIMO TEXT DEFAULT '',
            QUEM_RETIROU TEXT DEFAULT '',
            FONTE_ARQUIVO TEXT DEFAULT 'cadastro_web',
            TEXTO_GERAL TEXT DEFAULT ''
        )
    ");

    ensure_columns($pdo, 'acervo', [
        'UNIDADE' => "TEXT DEFAULT ''",
        'ASSUNTO' => "TEXT DEFAULT ''",
        'INTERESSADO' => "TEXT DEFAULT ''",
        'DATA' => "TEXT DEFAULT ''",
        'TEMPORALIDADE' => "TEXT DEFAULT ''",
        'CAIXA' => "TEXT DEFAULT ''",
        'PROCESSO' => "TEXT DEFAULT ''",
        'LOCALIZACAO' => "TEXT DEFAULT ''",
        'OBSERVACAO' => "TEXT DEFAULT ''",
        'VOLUMES' => "TEXT DEFAULT ''",
        'RESPONSAVEL' => "TEXT DEFAULT ''",
        'DATA_LIMITE' => "TEXT DEFAULT ''",
        'ALTERADO_POR' => "TEXT DEFAULT ''",
        'ULTIMA_ALTERACAO' => "TEXT DEFAULT ''",
        'STATUS_EMPRESTIMO' => "TEXT DEFAULT ''",
        'QUEM_RETIROU' => "TEXT DEFAULT ''",
        'FONTE_ARQUIVO' => "TEXT DEFAULT 'cadastro_web'",
        'TEXTO_GERAL' => "TEXT DEFAULT ''",
    ]);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS indicadores (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            colaborador TEXT NOT NULL,
            data TEXT NOT NULL,
            dados_json TEXT NOT NULL,
            criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    ensure_columns($pdo, 'indicadores', [
        'colaborador' => "TEXT NOT NULL DEFAULT ''",
        'data' => "TEXT NOT NULL DEFAULT ''",
        'dados_json' => "TEXT NOT NULL DEFAULT '{}'",
        'criado_em' => "TEXT NOT NULL DEFAULT ''",
    ]);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sei_atendimentos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            usuario_id INTEGER NOT NULL DEFAULT 0,
            usuario_login TEXT NOT NULL DEFAULT '',
            usuario_nome TEXT NOT NULL DEFAULT '',
            processo TEXT NOT NULL DEFAULT '',
            criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    ensure_columns($pdo, 'sei_atendimentos', [
        'usuario_id' => "INTEGER NOT NULL DEFAULT 0",
        'usuario_login' => "TEXT NOT NULL DEFAULT ''",
        'usuario_nome' => "TEXT NOT NULL DEFAULT ''",
        'processo' => "TEXT NOT NULL DEFAULT ''",
        'status' => "TEXT NOT NULL DEFAULT 'atendido'",
        'pulado_por_login' => "TEXT NOT NULL DEFAULT ''",
        'pulado_por_nome' => "TEXT NOT NULL DEFAULT ''",
        'criado_em' => "TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP",
    ]);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS eventos_sistema (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tipo TEXT NOT NULL DEFAULT 'info',
            mensagem TEXT NOT NULL DEFAULT '',
            contexto_json TEXT NOT NULL DEFAULT '{}',
            usuario_login TEXT NOT NULL DEFAULT '',
            usuario_nome TEXT NOT NULL DEFAULT '',
            criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS import_jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tipo TEXT NOT NULL DEFAULT 'planilhas',
            status TEXT NOT NULL DEFAULT 'pendente',
            total_arquivos INTEGER NOT NULL DEFAULT 0,
            total_registros INTEGER NOT NULL DEFAULT 0,
            mensagem TEXT NOT NULL DEFAULT '',
            criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            iniciado_em TEXT NOT NULL DEFAULT '',
            concluido_em TEXT NOT NULL DEFAULT ''
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS assistant_memory (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            pergunta TEXT NOT NULL DEFAULT '',
            resposta TEXT NOT NULL DEFAULT '',
            contexto_json TEXT NOT NULL DEFAULT '{}',
            criado_em TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_acervo_caixa ON acervo (CAIXA)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_acervo_processo ON acervo (PROCESSO)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_acervo_assunto ON acervo (ASSUNTO)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_acervo_interessado ON acervo (INTERESSADO)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_acervo_texto ON acervo (TEXTO_GERAL)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sei_atendimentos_criado ON sei_atendimentos (criado_em)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sei_atendimentos_usuario ON sei_atendimentos (usuario_login)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_eventos_sistema_criado ON eventos_sistema (criado_em)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_eventos_sistema_tipo ON eventos_sistema (tipo)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_import_jobs_status ON import_jobs (status)');

    try {
        $pdo->exec('CREATE VIRTUAL TABLE IF NOT EXISTS acervo_fts USING fts5(id_unico UNINDEXED, texto)');
    } catch (Throwable) {
        // FTS5 pode nao estar disponivel em alguns builds de SQLite.
    }

    $exists = (int) $pdo->query("SELECT COUNT(*) FROM usuarios WHERE UPPER(login) = 'ADMIN'")->fetchColumn();
    if ($exists === 0) {
        $stmt = $pdo->prepare("
            INSERT INTO usuarios
                (nome, login, senha, tipo_usuario, departamento, p_extrair_excel, p_sincronizar, p_gerir_usuarios, p_cadastrar_caixa, p_botao_editar, p_emprestimo)
            VALUES
                ('Administrador', 'ADMIN', '123456', 'Servidor', 'DIARQ', 1, 1, 1, 1, 1, 1)
        ");
        $stmt->execute();
    }
}

function ensure_columns(PDO $pdo, string $table, array $columns): void
{
    $existing = [];
    $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
    foreach ($stmt->fetchAll() as $row) {
        $existing[$row['name']] = true;
    }

    foreach ($columns as $name => $definition) {
        if (!isset($existing[$name])) {
            $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $name . ' ' . $definition);
        }
    }
}
