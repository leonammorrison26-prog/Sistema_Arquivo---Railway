<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/supabase.php';
require_once __DIR__ . '/import.php';

function login_user(string $login, string $senha): bool
{
    $supabaseUser = supabase_fetch_user(trim($login), $senha);
    if ($supabaseUser) {
        $user = normalize_remote_user($supabaseUser);
        mirror_user_local($user);
        $_SESSION['user'] = $user;
        sync_after_login();
        return true;
    }

    $stmt = db()->prepare('SELECT * FROM usuarios WHERE login = :login COLLATE NOCASE AND senha = :senha LIMIT 1');
    $stmt->execute([':login' => trim($login), ':senha' => $senha]);
    $user = $stmt->fetch();

    if (!$user) {
        return false;
    }

    $_SESSION['user'] = $user;
    sync_after_login();
    return true;
}

function sync_after_login(): void
{
    if (!supabase_enabled()) {
        return;
    }

    try {
        $result = supabase_sync_on_login();
        $import = import_planilhas_on_login();
        if (($result['enabled'] ?? false) === true) {
            $_SESSION['flash_success'] = 'Sincronizacao Supabase concluida: '
                . (int) ($result['acervo'] ?? 0) . ' item(ns) do acervo e '
                . (int) ($result['usuarios'] ?? 0) . ' usuario(s).';
            if (($import['enabled'] ?? false) === true) {
                $_SESSION['flash_success'] .= ' Planilhas: '
                    . (int) ($import['imported'] ?? 0) . ' linha(s) processada(s) em '
                    . (int) ($import['files'] ?? 0) . ' arquivo(s).';
            } elseif (($import['reason'] ?? '') !== '') {
                $_SESSION['flash_success'] .= ' Planilhas nao importadas: ' . $import['reason'];
            }
        }
    } catch (Throwable $e) {
        $_SESSION['flash_error'] = 'Login realizado, mas a sincronizacao com o Supabase falhou: ' . $e->getMessage();
    }
}

function normalize_remote_user(array $row): array
{
    return [
        'id' => $row['id'] ?? null,
        'nome' => $row['nome'] ?? 'Sem Nome',
        'login' => $row['login'] ?? $row['utilizador'] ?? $row['usuario'] ?? '',
        'senha' => $row['senha'] ?? '',
        'tipo_usuario' => $row['tipo_usuario'] ?? $row['tipo'] ?? 'Servidor',
        'departamento' => $row['departamento'] ?? $row['depto'] ?? 'DIARQ',
        'p_extrair_excel' => (int) ($row['p_extrair_excel'] ?? 0),
        'p_sincronizar' => (int) ($row['p_sincronizar'] ?? 0),
        'p_gerir_usuarios' => (int) ($row['p_gerir_usuarios'] ?? (strtolower((string) ($row['perfil'] ?? '')) === 'admin' ? 1 : 0)),
        'p_cadastrar_caixa' => (int) ($row['p_cadastrar_caixa'] ?? 0),
        'p_somente_pesquisa' => (int) ($row['p_somente_pesquisa'] ?? 0),
        'p_botao_editar' => (int) ($row['p_botao_editar'] ?? 0),
        'p_emprestimo' => (int) ($row['p_emprestimo'] ?? 0),
        'setores_permitidos' => $row['setores_permitidos'] ?? '',
        'TROCAR_SENHA' => (int) ($row['TROCAR_SENHA'] ?? $row['trocar_senha'] ?? 0),
    ];
}

function mirror_user_local(array $user): void
{
    $params = [
        ':nome' => $user['nome'],
        ':login' => $user['login'],
        ':senha' => $user['senha'],
        ':tipo_usuario' => $user['tipo_usuario'],
        ':departamento' => $user['departamento'],
        ':p_extrair_excel' => $user['p_extrair_excel'],
        ':p_sincronizar' => $user['p_sincronizar'],
        ':p_gerir_usuarios' => $user['p_gerir_usuarios'],
        ':p_cadastrar_caixa' => $user['p_cadastrar_caixa'],
        ':p_somente_pesquisa' => $user['p_somente_pesquisa'],
        ':p_botao_editar' => $user['p_botao_editar'],
        ':p_emprestimo' => $user['p_emprestimo'],
        ':setores_permitidos' => $user['setores_permitidos'],
        ':TROCAR_SENHA' => $user['TROCAR_SENHA'],
    ];

    $exists = db()->prepare('SELECT id FROM usuarios WHERE login = :login COLLATE NOCASE LIMIT 1');
    $exists->execute([':login' => $user['login']]);
    $id = $exists->fetchColumn();

    if ($id) {
        $params[':id'] = $id;
        db()->prepare("
            UPDATE usuarios SET
                nome = :nome,
                login = :login,
                senha = :senha,
                tipo_usuario = :tipo_usuario,
                departamento = :departamento,
                p_extrair_excel = :p_extrair_excel,
                p_sincronizar = :p_sincronizar,
                p_gerir_usuarios = :p_gerir_usuarios,
                p_cadastrar_caixa = :p_cadastrar_caixa,
                p_somente_pesquisa = :p_somente_pesquisa,
                p_botao_editar = :p_botao_editar,
                p_emprestimo = :p_emprestimo,
                setores_permitidos = :setores_permitidos,
                TROCAR_SENHA = :TROCAR_SENHA
            WHERE id = :id
        ")->execute($params);
        return;
    }

    db()->prepare("
        INSERT INTO usuarios
            (nome, login, senha, tipo_usuario, departamento, p_extrair_excel, p_sincronizar, p_gerir_usuarios, p_cadastrar_caixa, p_somente_pesquisa, p_botao_editar, p_emprestimo, setores_permitidos, TROCAR_SENHA)
        VALUES
            (:nome, :login, :senha, :tipo_usuario, :departamento, :p_extrair_excel, :p_sincronizar, :p_gerir_usuarios, :p_cadastrar_caixa, :p_somente_pesquisa, :p_botao_editar, :p_emprestimo, :setores_permitidos, :TROCAR_SENHA)
    ")->execute($params);
}

function require_login(): void
{
    if (!isset($_SESSION['user'])) {
        render_login();
        exit;
    }
}

function logout_user(): never
{
    $_SESSION = [];
    session_destroy();
    header('Location: /');
    exit;
}

function render_login(): void
{
    $erro = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
        if (login_user($_POST['login'] ?? '', $_POST['senha'] ?? '')) {
            redirect_to('busca');
        }
        $erro = 'Credenciais invalidas';
    }

    $logoPath = ASSETS_DIR . DIRECTORY_SEPARATOR . 'LOGO_DIARQ.png';
    $logo = is_file($logoPath) ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($logoPath)) : logo_data_uri();
    ?>
    <!doctype html>
    <html lang="pt-BR">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= h(APP_NAME) ?></title>
        <link rel="stylesheet" href="/assets/app.css">
    </head>
    <body class="login-screen">
        <div class="login-more">
            <button class="more-trigger" type="button" aria-label="Abrir menu" aria-expanded="false">⋮</button>
            <div class="more-menu" hidden>
                <div class="theme-options" role="group" aria-label="Tema">
                    <button type="button" class="theme-choice active" data-theme-choice="system">
                        <span class="theme-icon">◐</span>
                        <span>System</span>
                    </button>
                    <button type="button" class="theme-choice" data-theme-choice="light">
                        <span class="theme-icon">☼</span>
                        <span>Light</span>
                    </button>
                    <button type="button" class="theme-choice" data-theme-choice="dark">
                        <span class="theme-icon">☾</span>
                        <span>Dark</span>
                    </button>
                </div>
                <button type="button" class="menu-action" data-menu-action="rerun">
                    <span>Rerun</span>
                    <kbd>R</kbd>
                </button>
                <button type="button" class="menu-action" data-menu-action="clear-cache">
                    <span>Clear cache</span>
                    <kbd>C</kbd>
                </button>
            </div>
        </div>
        <main class="login-shell">
            <div class="login-logo-panel">
                <?php if ($logo): ?>
                    <img src="<?= h($logo) ?>" class="login-logo" alt="MDS DIARQ">
                <?php else: ?>
                    <h1>DIARQ</h1>
                <?php endif; ?>
            </div>
            <section class="login-intro">
                <p class="login-title">Seja Bem-vindo</p>
                <p class="login-subtitle">Faca login para acessar o sistema</p>
            </section>
            <section class="login-card">
                <?php if ($erro): ?><div class="alert danger"><?= h($erro) ?></div><?php endif; ?>
                <form method="post" class="stack">
                    <input type="hidden" name="action" value="login">
                    <label>Usuário <input name="login" placeholder="Digite seu usuário" required autofocus></label>
                    <label>Senha
                        <span class="password-field">
                            <input name="senha" type="password" placeholder="Digite sua senha" required>
                            <button class="password-toggle" type="button" aria-label="Mostrar senha">●</button>
                        </span>
                    </label>
                    <button class="primary" type="submit">ACESSAR</button>
                </form>
            </section>
        </main>
        <script src="/assets/app.js"></script>
    </body>
    </html>
    <?php
}
