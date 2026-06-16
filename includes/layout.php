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
        <title><?= h($title) ?></title>
        <link rel="stylesheet" href="/assets/app.css">
    </head>
    <body>
    <div class="app-shell">
        <?php render_sidebar(); ?>
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
                    </div>
                </div>
            </header>
            <?php if (!supabase_enabled()): ?>
                <div class="alert danger">Supabase obrigatorio nao configurado: <?= h(supabase_status()) ?></div>
            <?php endif; ?>
            <?php if (!empty($_SESSION['flash_error'])): ?>
                <div class="alert danger"><?= h($_SESSION['flash_error']) ?></div>
                <?php unset($_SESSION['flash_error']); ?>
            <?php endif; ?>
            <?php if (!empty($_SESSION['flash_success'])): ?>
                <div class="alert success"><?= h($_SESSION['flash_success']) ?></div>
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
        'user' => '&#128100;',
        'home' => '&#127968;',
        'tools' => '&#128736;',
        'archive' => '&#128229;',
        'box' => '&#128230;',
        'upload' => '&#128228;',
        'doc' => '&#128196;',
        'tag' => '&#127991;',
        'note' => '&#128221;',
        'plus' => '&#10133;',
        'chart' => '&#128202;',
        'table' => '&#128203;',
        'assistant' => '&#129302;',
        'folder' => '&#128193;',
        'rocket' => '&#128640;',
        'diagnostic' => '&#128736;',
        'logout' => '&#128682;',
    ];

    $content = $icons[$icon] ?? '';
    return '<span class="side-icon icon-' . h($icon) . '" aria-hidden="true">' . $content . '</span>';
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
                <a class="side-button" href="/configurar_diarq.bat" download title="Configurar Acesso à Rede"><?= side_icon('rocket') ?><span class="side-label">Configurar Acesso à Rede (Rodar uma vez)</span></a>
            <?php endif; ?>

            <details class="nav-group">
                <?php sidebar_summary('Gerar Relatórios', 'chart'); ?>
                <div class="nav-submenu">
                    <a class="side-button sub-button" href="/?export=acervo" title="Relatório Geral do Acervo"><?= side_icon('table') ?><span class="side-label">Relatório Geral do Acervo</span></a>
                    <?php sidebar_link('rel_indicadores', 'Relatório Indicadores', 'chart'); ?>
                    <?php sidebar_link('dashboard', 'Dashboard', 'chart'); ?>
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
