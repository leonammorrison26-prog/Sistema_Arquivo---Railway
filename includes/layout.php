<?php

declare(strict_types=1);

require_once __DIR__ . '/actions.php';

function render_header(string $title = APP_NAME): void
{
    $user = $_SESSION['user'] ?? [];
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=UTF-8');
    }
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
    <body>
    <script>
        (() => {
            try {
                const saved = localStorage.getItem('diarq-theme') || localStorage.getItem('diarq-login-theme') || 'system';
                const dark = saved === 'system'
                    ? window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches
                    : saved === 'dark';
                document.body.classList.toggle('theme-light', !dark);
                document.body.classList.toggle('theme-dark', dark);
            } catch (error) {}
        })();
    </script>
    <div class="app-shell">
        <?php render_sidebar(); ?>
        <div class="mobile-sidebar-backdrop" aria-hidden="true"></div>
        <main class="main">
            <header class="page-title">
                <div class="title-group">
                    <div>
                        <h1><?= h(APP_NAME) ?></h1>
                    </div>
                </div>
                <section class="top-user-card" aria-label="Usuario logado">
                    <span class="top-user-avatar"><?= side_icon('user') ?></span>
                    <span class="top-user-main">
                        <strong><?= h($user['nome'] ?? 'Usuario') ?></strong>
                        <span>Setor: <?= h($user['departamento'] ?? 'DIARQ') ?> | Tipo: <?= h($user['tipo_usuario'] ?? 'Servidor') ?></span>
                    </span>
                    <span class="top-user-status" title="<?= h(supabase_status()) ?>"></span>
                </section>
                <div class="app-more">
                    <button class="more-trigger" type="button" aria-label="Abrir menu" aria-expanded="false">&#8942;</button>
                    <div class="more-menu" hidden>
                        <div class="theme-options" role="group" aria-label="Tema do sistema">
                            <button type="button" class="theme-choice active" data-theme-choice="system">
                                <span class="theme-icon">&#9680;</span>
                                <span>System</span>
                            </button>
                            <button type="button" class="theme-choice" data-theme-choice="light">
                                <span class="theme-icon">&#9788;</span>
                                <span>Light</span>
                            </button>
                            <button type="button" class="theme-choice" data-theme-choice="dark">
                                <span class="theme-icon">&#9790;</span>
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
                        <form method="post" class="menu-action-form">
                            <input type="hidden" name="action" value="sync_now">
                            <input type="hidden" name="return_page" value="<?= h(current_page()) ?>">
                            <button type="submit" class="menu-action">
                                <span>Sincronizar</span>
                            </button>
                        </form>
                        <a class="menu-action" href="/?logout=1">
                            <span>Sair / Logoff</span>
                        </a>
                    </div>
                </div>
            </header>
            <?php if (!supabase_enabled()): ?>
                <div class="alert danger dismissible-alert" role="alert">
                    <span>Supabase obrigatorio nao configurado: <?= h(supabase_status()) ?></span>
                    <button class="alert-close" type="button" aria-label="Fechar aviso" data-dismiss-alert>&times;</button>
                </div>
            <?php endif; ?>
            <?php if (!empty($_SESSION['flash_error'])): ?>
                <div class="alert danger dismissible-alert" role="alert">
                    <span><?= h($_SESSION['flash_error']) ?></span>
                    <button class="alert-close" type="button" aria-label="Fechar aviso" data-dismiss-alert>&times;</button>
                </div>
                <?php unset($_SESSION['flash_error']); ?>
            <?php endif; ?>
            <?php if (!empty($_SESSION['flash_success'])): ?>
                <div class="alert success dismissible-alert" role="status">
                    <span><?= h($_SESSION['flash_success']) ?></span>
                    <button class="alert-close" type="button" aria-label="Fechar aviso" data-dismiss-alert>&times;</button>
                </div>
                <?php unset($_SESSION['flash_success']); ?>
            <?php endif; ?>
    <?php
}

function render_footer(): void
{
    $totals = acervo_totals();
    $showTotals = current_page() === 'busca';
    ?>
            <?php if ($showTotals): ?>
            <section class="acervo-summary">
                <div class="summary-item">
                    <span>Total de Caixas</span>
                    <strong><?= h(number_format((int) $totals['caixas'], 0, ',', '.')) ?></strong>
                </div>
                <div class="summary-item">
                    <span>Total N° Processo</span>
                    <strong><?= h(number_format((int) $totals['processos'], 0, ',', '.')) ?></strong>
                </div>
                <div class="summary-item">
                    <span>Total de Pasta Funcional</span>
                    <strong><?= h(number_format((int) $totals['pastas_funcionais'], 0, ',', '.')) ?></strong>
                </div>
            </section>
            <?php endif; ?>
        </main>
    </div>
    <script src="/assets/app.js"></script>
    </body>
    </html>
    <?php
}

function sidebar_link(string $page, string $label, string $icon = ''): void
{
    $active = current_page() === $page ? ' active' : '';
    echo '<a class="side-button' . $active . '" href="/?page=' . h($page) . '" title="' . h($label) . '">' . side_icon($icon) . '<span class="side-label">' . h($label) . '</span></a>';
}

function sidebar_external(string $href, string $label, string $icon = '', string $extra = ''): void
{
    echo '<a class="side-button ' . h($extra) . '" href="' . h($href) . '" title="' . h($label) . '">' . side_icon($icon) . '<span class="side-label">' . h($label) . '</span></a>';
}

function sidebar_summary(string $label, string $icon): void
{
    echo '<summary title="' . h($label) . '"><span class="nav-chevron">&gt;</span>' . side_icon($icon) . '<span class="side-label">' . h($label) . '</span></summary>';
}

function side_icon(string $icon): string
{
    $icon = preg_replace('/[^a-z0-9_-]/i', '', $icon);
    if ($icon === '') {
        return '';
    }

    $icons = [
        'user' => app_icon('person'),
        'home' => '&#127968;',
        'tools' => '&#128736;',
        'archive' => '&#128229;',
        'box' => app_icon('boxes'),
        'upload' => '&#128228;',
        'doc' => app_icon('processos'),
        'tag' => '&#127991;',
        'note' => '&#128221;',
        'plus' => '&#10133;',
        'chart' => '&#128202;',
        'dashboard' => app_icon('dashboard'),
        'table' => '&#128203;',
        'assistant' => '&#129302;',
        'folder' => '&#128193;',
        'rocket' => '&#128640;',
        'download' => app_icon('download'),
        'diagnostic' => '&#128736;',
        'logout' => '&#128682;',
    ];

    $content = $icons[$icon] ?? '';
    return '<span class="side-icon icon-' . h($icon) . '" aria-hidden="true">' . $content . '</span>';
}

function app_icon(string $icon): string
{
    $attrs = 'width="16" height="16" fill="currentColor" aria-hidden="true" focusable="false"';
    return match ($icon) {
        'trash' => '<svg xmlns="http://www.w3.org/2000/svg" ' . $attrs . ' class="bi bi-trash3 app-svg-icon" viewBox="0 0 16 16"><path d="M6.5 1h3a.5.5 0 0 1 .5.5v1H6v-1a.5.5 0 0 1 .5-.5M11 2.5v-1A1.5 1.5 0 0 0 9.5 0h-3A1.5 1.5 0 0 0 5 1.5v1H1.5a.5.5 0 0 0 0 1h.538l.853 10.66A2 2 0 0 0 4.885 16h6.23a2 2 0 0 0 1.994-1.84l.853-10.66h.538a.5.5 0 0 0 0-1zm1.958 1-.846 10.58a1 1 0 0 1-.997.92h-6.23a1 1 0 0 1-.997-.92L3.042 3.5zm-7.487 1a.5.5 0 0 1 .528.47l.5 8.5a.5.5 0 0 1-.998.06L5 5.03a.5.5 0 0 1 .47-.53Zm5.058 0a.5.5 0 0 1 .47.53l-.5 8.5a.5.5 0 1 1-.998-.06l.5-8.5a.5.5 0 0 1 .528-.47M8 4.5a.5.5 0 0 1 .5.5v8.5a.5.5 0 0 1-1 0V5a.5.5 0 0 1 .5-.5"/></svg>',
        'send' => '<svg xmlns="http://www.w3.org/2000/svg" ' . $attrs . ' class="bi bi-send app-svg-icon" viewBox="0 0 16 16"><path d="M15.854.146a.5.5 0 0 1 .11.54l-5.819 14.547a.75.75 0 0 1-1.329.124l-3.178-4.995L.643 7.184a.75.75 0 0 1 .124-1.33L15.314.037a.5.5 0 0 1 .54.11ZM6.636 10.07l2.761 4.338L14.13 2.576zm6.787-8.201L1.591 6.602l4.339 2.76z"/></svg>',
        'search' => '<svg xmlns="http://www.w3.org/2000/svg" ' . $attrs . ' class="bi bi-search app-svg-icon" viewBox="0 0 16 16"><path d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398h-.001q.044.06.098.115l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85a1 1 0 0 0-.115-.1zM12 6.5a5.5 5.5 0 1 1-11 0 5.5 5.5 0 0 1 11 0"/></svg>',
        'person' => '<svg xmlns="http://www.w3.org/2000/svg" ' . $attrs . ' class="bi bi-person-circle app-svg-icon" viewBox="0 0 16 16"><path d="M11 6a3 3 0 1 1-6 0 3 3 0 0 1 6 0"/><path fill-rule="evenodd" d="M0 8a8 8 0 1 1 16 0A8 8 0 0 1 0 8m8-7a7 7 0 0 0-5.468 11.37C3.242 11.226 4.805 10 8 10s4.757 1.225 5.468 2.37A7 7 0 0 0 8 1"/></svg>',
        'rh' => '<svg xmlns="http://www.w3.org/2000/svg" ' . $attrs . ' class="bi bi-file-earmark-person-fill app-svg-icon" viewBox="0 0 16 16"><path d="M9.293 0H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V4.707A1 1 0 0 0 13.707 4L10 .293A1 1 0 0 0 9.293 0M9.5 3.5v-2l3 3h-2a1 1 0 0 1-1-1M11 8a3 3 0 1 1-6 0 3 3 0 0 1 6 0m2 5.755V14a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-.245S4 12 8 12s5 1.755 5 1.755"/></svg>',
        'processos' => '<svg xmlns="http://www.w3.org/2000/svg" ' . $attrs . ' class="bi bi-file-earmark-ppt-fill app-svg-icon" viewBox="0 0 16 16"><path d="M8.188 10H7V6.5h1.188a1.75 1.75 0 1 1 0 3.5"/><path d="M4 0h5.293A1 1 0 0 1 10 .293L13.707 4a1 1 0 0 1 .293.707V14a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V2a2 2 0 0 1 2-2m5.5 1.5v2a1 1 0 0 0 1 1h2zM7 5.5a1 1 0 0 0-1 1V13a.5.5 0 0 0 1 0v-2h1.188a2.75 2.75 0 0 0 0-5.5z"/></svg>',
        'boxes' => '<svg xmlns="http://www.w3.org/2000/svg" ' . $attrs . ' class="bi bi-boxes app-svg-icon" viewBox="0 0 16 16"><path d="M7.752.066a.5.5 0 0 1 .496 0l3.75 2.143a.5.5 0 0 1 .252.434v3.995l3.498 2A.5.5 0 0 1 16 9.07v4.286a.5.5 0 0 1-.252.434l-3.75 2.143a.5.5 0 0 1-.496 0l-3.502-2-3.502 2.001a.5.5 0 0 1-.496 0l-3.75-2.143A.5.5 0 0 1 0 13.357V9.071a.5.5 0 0 1 .252-.434L3.75 6.638V2.643a.5.5 0 0 1 .252-.434zM4.25 7.504 1.508 9.071l2.742 1.567 2.742-1.567zM7.5 9.933l-2.75 1.571v3.134l2.75-1.571zm1 3.134 2.75 1.571v-3.134L8.5 9.933zm.508-3.996 2.742 1.567 2.742-1.567-2.742-1.567zm2.242-2.433V3.504L8.5 5.076V8.21zM7.5 8.21V5.076L4.75 3.504v3.134zM5.258 2.643 8 4.21l2.742-1.567L8 1.076zM15 9.933l-2.75 1.571v3.134L15 13.067zM3.75 14.638v-3.134L1 9.933v3.134z"/></svg>',
        'dashboard' => '<svg xmlns="http://www.w3.org/2000/svg" ' . $attrs . ' class="bi bi-graph-down-arrow app-svg-icon" viewBox="0 0 16 16"><path fill-rule="evenodd" d="M0 0h1v15h15v1H0zm10 11.5a.5.5 0 0 0 .5.5h4a.5.5 0 0 0 .5-.5v-4a.5.5 0 0 0-1 0v2.6l-3.613-4.417a.5.5 0 0 0-.74-.037L7.06 8.233 3.404 3.206a.5.5 0 0 0-.808.588l4 5.5a.5.5 0 0 0 .758.06l2.609-2.61L13.445 11H10.5a.5.5 0 0 0-.5.5"/></svg>',
        'download' => '<svg xmlns="http://www.w3.org/2000/svg" ' . $attrs . ' class="bi bi-download app-svg-icon" viewBox="0 0 16 16"><path d="M.5 9.9a.5.5 0 0 1 .5.5v2.5a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-2.5a.5.5 0 0 1 1 0v2.5a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2v-2.5a.5.5 0 0 1 .5-.5"/><path d="M7.646 11.854a.5.5 0 0 0 .708 0l3-3a.5.5 0 0 0-.708-.708L8.5 10.293V1.5a.5.5 0 0 0-1 0v8.793L5.354 8.146a.5.5 0 1 0-.708.708z"/></svg>',
        default => '',
    };
}

function diarq_network_configured(): bool
{
    return is_file('C:\\VBScript\\abrir_diarq.vbs');
}

function render_sidebar(): void
{
    $user = $_SESSION['user'] ?? [];
    $logo = logo_data_uri();
    $seiLogoPath = ASSETS_DIR . DIRECTORY_SEPARATOR . 'LOGO_SEI-MDS.png';
    $seiLogo = is_file($seiLogoPath) ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($seiLogoPath)) : '';
    ?>
    <aside class="sidebar" aria-label="Sidebar de navegação">
        <button class="sidebar-toggle" type="button" aria-label="Recolher sidebar" title="Abrir ou recolher sidebar">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-list" viewBox="0 0 16 16" aria-hidden="true" focusable="false">
                <path fill-rule="evenodd" d="M2.5 12a.5.5 0 0 1 .5-.5h10a.5.5 0 0 1 0 1H3a.5.5 0 0 1-.5-.5m0-4a.5.5 0 0 1 .5-.5h10a.5.5 0 0 1 0 1H3a.5.5 0 0 1-.5-.5m0-4a.5.5 0 0 1 .5-.5h10a.5.5 0 0 1 0 1H3a.5.5 0 0 1-.5-.5"/>
            </svg>
        </button>
        <div class="sidebar-scroll">
            <?php if ($logo): ?>
                <a class="side-logo-link" href="https://www.gov.br/mds/pt-br" target="_blank"><img src="<?= h($logo) ?>" class="side-logo" alt="MDS"></a>
            <?php else: ?>
                <div class="brand">DIARQ</div>
            <?php endif; ?>
            <?php sidebar_link('busca', 'Voltar ao Inicio', 'home'); ?>

            <div class="side-separator"></div>

            <h3><?= side_icon('tools') ?> MENU DE SERVIÇOS</h3>

            <details class="nav-group">
                <?php sidebar_summary('Arquivamento / Desarq.', 'archive'); ?>
                <div class="nav-submenu">
                    <a class="side-button sub-button" href="#" title="Registrar Arquivamento"><?= side_icon('box') ?><span class="side-label">Registrar Arquivamento</span></a>
                    <a class="side-button sub-button" href="#" title="Registrar Desarquivamento"><?= side_icon('upload') ?><span class="side-label">Registrar Desarquivamento</span></a>
                </div>
            </details>

            <details class="nav-group">
                <?php sidebar_summary('Gerar Documentos', 'doc'); ?>
                <div class="nav-submenu">
                    <a class="side-button sub-button" href="/?page=documentos&doc=etiqueta" title="Etiqueta de Caixa"><?= side_icon('tag') ?><span class="side-label">Etiqueta de Caixa</span></a>
                    <a class="side-button sub-button" href="/?page=documentos&doc=guia" title="Guia Fora"><?= side_icon('note') ?><span class="side-label">Guia Fora</span></a>
                </div>
            </details>

            <details class="nav-group">
                <?php sidebar_summary('Cadastrar', 'plus'); ?>
                <div class="nav-submenu">
                    <?php sidebar_link('cad_caixa', 'Caixas', 'box'); ?>
                    <?php if (user_is_admin()): ?><?php sidebar_link('gestao_usuarios', 'Usuarios', 'user'); ?><?php endif; ?>
                    <?php sidebar_link('indicadores_semanal', 'Indicadores semanal', 'chart'); ?>
                    <?php sidebar_link('cad_processo', 'Processos', 'doc'); ?>
                    <?php sidebar_link('planilha', 'Gestao de Cadastros', 'table'); ?>
                </div>
            </details>

            <div class="side-separator"></div>

            <h3><?= side_icon('assistant') ?> Assistente Virtual</h3>
            <?php sidebar_link('assistente_openai', 'Falar com Assistente', 'assistant'); ?>

            <div class="side-separator"></div>

            <h3>Acesso ao SEI & Pasta Compart</h3>
            <a class="side-button" href="https://sei.mds.gov.br/sip/login.php?sigla_orgao_sistema=MC&sigla_sistema=SEI&infra_url=L3NlaS8=" target="_blank" title="Abrir SEI - MDS">
                <?php if ($seiLogo): ?>
                    <img class="sei-logo" src="<?= h($seiLogo) ?>" alt="SEI MDS">
                <?php else: ?>
                    <span class="sei-badge">sei!</span>
                <?php endif; ?>
                <span class="side-label">Abrir SEI - MDS</span>
            </a>
            <?php sidebar_external('diarq://', 'Abrir Pasta Compart - Diarq', 'folder'); ?>
            <?php if (!diarq_network_configured()): ?>
                <a class="side-button" href="/configurar_diarq.bat" download title="Configurar Acesso à Rede"><?= side_icon('download') ?><span class="side-label">Configurar Acesso à Rede (Rodar uma vez)</span></a>
            <?php endif; ?>

            <details class="nav-group">
                <?php sidebar_summary('Gerar Relatórios', 'chart'); ?>
                <div class="nav-submenu">
                    <a class="side-button sub-button" href="/?export=acervo" title="Relatório Geral do Acervo"><?= side_icon('download') ?><span class="side-label">Relatório Geral do Acervo</span></a>
                    <?php sidebar_link('rel_indicadores', 'Relatório Indicadores', 'chart'); ?>
                    <?php sidebar_link('rel_demanda_sei', 'Relatorio Demanda SEI', 'chart'); ?>
                    <?php sidebar_link('dashboard', 'Dashboard', 'dashboard'); ?>
                </div>
            </details>

            <?php if (user_is_admin() || (int) ($user['p_sincronizar'] ?? 0) === 1): ?>
                <details class="nav-group">
                    <?php sidebar_summary('Diagnóstico de Conexão', 'diagnostic'); ?>
                    <div class="nav-submenu">
                        <?php sidebar_link('rel_temporalidade', 'Itens sem Temporalidade', 'table'); ?>
                        <div class="side-note">Supabase: <?= h(supabase_status()) ?></div>
                        <div class="side-note">Banco: <?= h(DB_PATH) ?></div>
                    </div>
                </details>
            <?php endif; ?>

            <div class="side-separator"></div>
            <a class="side-button logout" href="/?logout=1" title="Sair / Logoff"><?= side_icon('logout') ?><span class="side-label">Sair / Logoff</span></a>
        </div>
    </aside>
    <?php
}
