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
        if (is_default_password($user['senha'] ?? '')) {
            $user['TROCAR_SENHA'] = 1;
        }
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

    if (is_default_password($user['senha'] ?? '')) {
        $user['TROCAR_SENHA'] = 1;
        db()->prepare('UPDATE usuarios SET TROCAR_SENHA = 1 WHERE id = :id')->execute([':id' => $user['id']]);
    }

    $_SESSION['user'] = $user;
    sync_after_login();
    return true;
}

function is_default_password(string $senha): bool
{
    return hash_equals('123456', $senha);
}

function password_change_required(?array $user = null): bool
{
    $user ??= $_SESSION['user'] ?? null;
    if (!$user) {
        return false;
    }

    return (int) ($user['TROCAR_SENHA'] ?? 0) === 1 || is_default_password((string) ($user['senha'] ?? ''));
}

function change_logged_user_password(string $currentPassword, string $newPassword, string $confirmPassword): void
{
    $user = $_SESSION['user'] ?? null;
    if (!$user) {
        throw new RuntimeException('Sessao expirada. Faca login novamente.');
    }

    $currentPassword = trim($currentPassword);
    $newPassword = trim($newPassword);
    $confirmPassword = trim($confirmPassword);

    if (!hash_equals((string) ($user['senha'] ?? ''), $currentPassword)) {
        throw new RuntimeException('Senha atual incorreta.');
    }

    if (strlen($newPassword) < 6) {
        throw new RuntimeException('A nova senha deve ter pelo menos 6 caracteres.');
    }

    if ($newPassword !== $confirmPassword) {
        throw new RuntimeException('A confirmacao da nova senha nao confere.');
    }

    if (is_default_password($newPassword)) {
        throw new RuntimeException('Escolha uma senha diferente da senha padrao 123456.');
    }

    if (hash_equals($currentPassword, $newPassword)) {
        throw new RuntimeException('A nova senha precisa ser diferente da senha atual.');
    }

    $login = trim((string) ($user['login'] ?? ''));
    if ($login === '') {
        throw new RuntimeException('Usuario sem login definido.');
    }

    supabase_update_user_password($login, $newPassword);

    db()->prepare('UPDATE usuarios SET senha = :senha, TROCAR_SENHA = 0 WHERE login = :login COLLATE NOCASE')
        ->execute([':senha' => $newPassword, ':login' => $login]);

    $_SESSION['user']['senha'] = $newPassword;
    $_SESSION['user']['TROCAR_SENHA'] = 0;
}

function sync_after_login(): void
{
    try {
        $result = sync_app_data(false);
        $messages = [];

        if (($result['supabase']['enabled'] ?? false) === true) {
            $messages[] = 'Supabase: '
                . (int) ($result['supabase']['acervo'] ?? 0) . ' item(ns) do acervo e '
                . (int) ($result['supabase']['usuarios'] ?? 0) . ' usuario(s), '
                . (int) ($result['supabase']['indicadores'] ?? 0) . ' indicador(es).';
        }

        if (($result['planilhas']['enabled'] ?? false) === true) {
            $messages[] = 'Planilhas: '
                . (int) ($result['planilhas']['imported'] ?? 0) . ' registro(s) lido(s) de '
                . (int) ($result['planilhas']['files'] ?? 0) . ' arquivo(s).';
        } elseif (($result['planilhas']['reason'] ?? '') !== '') {
            $messages[] = 'Planilhas: ' . $result['planilhas']['reason'];
        }

        if (($result['indicadores_planilhas']['enabled'] ?? false) === true) {
            $messages[] = 'Indicadores: '
                . (int) ($result['indicadores_planilhas']['imported'] ?? 0) . ' registro(s) semanal(is) de '
                . (int) ($result['indicadores_planilhas']['files'] ?? 0) . ' arquivo(s).';
        }

        if ($messages) {
            $_SESSION['flash_success'] = 'Sincronizacao concluida. ' . implode(' ', $messages);
        }

        if (($result['planilhas']['completed'] ?? true) === false && !password_change_required()) {
            $_SESSION['flash_success'] = ($_SESSION['flash_success'] ?? 'Sincronizacao iniciada.')
                . ' Importacao parcial para evitar tempo limite; use Sincronizar novamente para continuar.';
        }
    } catch (Throwable $e) {
        $_SESSION['flash_error'] = 'Login realizado, mas a sincronizacao falhou: ' . $e->getMessage();
    }
}

function sync_app_data(bool $forcePlanilhas = false): array
{
    $supabase = supabase_enabled()
        ? supabase_sync_on_login()
        : ['enabled' => false, 'usuarios' => 0, 'acervo' => 0];

    $planilhas = import_planilhas_on_login($forcePlanilhas);
    $indicadoresPlanilhas = import_indicadores_planilhas($forcePlanilhas);

    return ['supabase' => $supabase, 'planilhas' => $planilhas, 'indicadores_planilhas' => $indicadoresPlanilhas];
}

function normalize_remote_user(array $row): array
{
    $profile = parse_supabase_user_profile((string) ($row['perfil'] ?? ''));
    return [
        'id' => $row['id'] ?? null,
        'nome' => $row['nome'] ?? 'Sem Nome',
        'login' => $row['login'] ?? $row['utilizador'] ?? $row['usuario'] ?? '',
        'senha' => $row['senha'] ?? '',
        'tipo_usuario' => normalize_user_type((string) ($row['tipo_usuario'] ?? $row['tipo'] ?? $profile['tipo_usuario'])),
        'departamento' => $row['departamento'] ?? $row['depto'] ?? 'DIARQ',
        'p_extrair_excel' => (int) ($row['p_extrair_excel'] ?? 0),
        'p_sincronizar' => (int) ($row['p_sincronizar'] ?? 0),
        'p_gerir_usuarios' => (int) ($row['p_gerir_usuarios'] ?? ($profile['is_admin'] ? 1 : 0)),
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

function render_password_change(): void
{
    $erro = $_SESSION['flash_error'] ?? null;
    $success = $_SESSION['flash_success'] ?? null;
    unset($_SESSION['flash_error'], $_SESSION['flash_success']);
    $user = $_SESSION['user'] ?? [];
    ?>
    <section class="login-card password-change-card">
        <div class="section-heading">
            <div>
                <span class="eyebrow">Seguranca da conta</span>
                <h2>Troque sua senha para continuar</h2>
                <p class="muted">A senha padrao 123456 so pode ser usada no primeiro acesso.</p>
            </div>
        </div>
        <?php if ($erro): ?><div class="alert danger"><?= h($erro) ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert success"><?= h($success) ?></div><?php endif; ?>
        <form method="post" class="stack">
            <input type="hidden" name="action" value="change_password">
            <label>Usuario <input value="<?= h($user['login'] ?? '') ?>" readonly></label>
            <label>Senha atual
                <span class="password-field">
                    <input name="senha_atual" type="password" placeholder="Digite sua senha atual" required autofocus>
                    <button class="password-toggle" type="button" aria-label="Mostrar senha">●</button>
                </span>
            </label>
            <label>Nova senha
                <span class="password-field">
                    <input name="nova_senha" type="password" placeholder="Digite sua nova senha" minlength="6" required>
                    <button class="password-toggle" type="button" aria-label="Mostrar senha">●</button>
                </span>
            </label>
            <label>Confirmar nova senha
                <span class="password-field">
                    <input name="confirmar_senha" type="password" placeholder="Repita a nova senha" minlength="6" required>
                    <button class="password-toggle" type="button" aria-label="Mostrar senha">●</button>
                </span>
            </label>
            <button class="primary" type="submit">Salvar nova senha</button>
        </form>
    </section>
    <?php
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
        <title><?= h(APP_BROWSER_TITLE) ?></title>
        <link rel="icon" type="image/svg+xml" href="<?= h(APP_FAVICON_DATA_URI) ?>">
        <meta name="theme-color" content="#111827">
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
