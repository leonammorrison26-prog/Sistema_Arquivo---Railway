<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
if ($path !== '/' && file_exists(__DIR__ . $path)) {
    return false;
}

require_once __DIR__ . '/includes/layout.php';

handle_actions();
require_login();

$page = current_page();
if (password_change_required() && $page !== 'trocar_senha') {
    redirect_to('trocar_senha');
}

render_header();

match ($page) {
    'central' => render_central(),
    'trocar_senha' => render_password_change(),
    'cad_caixa' => render_cad_caixa(),
    'cad_processo' => render_cad_processo(),
    'planilha' => render_planilha(),
    'gestao_usuarios' => render_usuarios(),
    'documentos' => render_documentos(),
    'indicadores_semanal' => render_indicadores(),
    'dashboard' => render_dashboard(),
    'diagnostico' => render_diagnostico(),
    'mapa_acervo' => render_mapa_acervo(),
    'preview_export' => render_export_preview(),
    'rel_temporalidade' => render_rel_temporalidade(),
    'rel_indicadores' => render_rel_indicadores(),
    'rel_demanda_sei' => render_rel_demanda_sei(),
    'assistente_openai' => render_assistente(),
    default => render_busca(),
};

render_footer();

function render_busca(): void
{
    $tabs = [
        'geral' => ['label' => 'Geral', 'icon' => 'search'],
        'rh' => ['label' => 'CGRH', 'icon' => 'rh'],
        'caixas' => ['label' => 'Caixas', 'icon' => 'boxes'],
        'processos' => ['label' => 'Processos', 'icon' => 'processos'],
    ];
    $scope = $_GET['scope'] ?? 'geral';
    $placeholders = [
        'geral' => 'Pesquisar em todo o acervo...',
        'rh' => 'Pesquisar por interessado, prontuário, assunto ou observação...',
        'caixas' => 'Pesquisar por número da caixa ou localização...',
        'processos' => 'Pesquisar por número do processo...',
    ];
    $term = trim($_GET['q'] ?? '');
    $filterInput = [
        'q' => $term,
        'caixa' => $_GET['caixa'] ?? '',
        'processo' => $_GET['processo'] ?? '',
        'interessado' => $_GET['interessado'] ?? '',
        'localizacao' => $_GET['localizacao'] ?? '',
        'temporalidade' => $_GET['temporalidade'] ?? '',
    ];
    $hasFilters = array_filter($filterInput, fn ($value) => trim((string) $value) !== '') !== [];
    $results = $hasFilters
        ? (array_filter($filterInput, fn ($value, $key) => $key !== 'q' && trim((string) $value) !== '', ARRAY_FILTER_USE_BOTH)
            ? search_acervo_filtered($filterInput)
            : search_acervo($term, $scope))
        : [];
    if (!$hasFilters) {
        render_sei_home_widget();
    }
    ?>
    <section class="panel">
        <nav class="tabs">
            <?php foreach ($tabs as $key => $tab): ?>
                <a class="<?= $scope === $key ? 'active' : '' ?>" href="/?page=busca&scope=<?= h($key) ?>"><?= app_icon($tab['icon']) ?><?= h($tab['label']) ?></a>
            <?php endforeach; ?>
        </nav>
        <form class="search-bar" method="get">
            <input type="hidden" name="page" value="busca">
            <input type="hidden" name="scope" value="<?= h($scope) ?>">
            <input name="q" value="<?= h($term) ?>" placeholder="<?= h($placeholders[$scope] ?? $placeholders['geral']) ?>">
            <button type="submit"><?= app_icon('send') ?>Buscar</button>
            <?php if ($hasFilters): ?><a class="icon-button" href="/?page=busca&scope=<?= h($scope) ?>">Nova</a><?php endif; ?>
            <details class="advanced-filters">
                <summary>Filtros</summary>
                <div class="filter-grid">
                    <label>Caixa <input name="caixa" value="<?= h($filterInput['caixa']) ?>" placeholder="Ex: 123"></label>
                    <label>Processo <input name="processo" value="<?= h($filterInput['processo']) ?>" placeholder="00000.00000/0000-00"></label>
                    <label>Interessado <input name="interessado" value="<?= h($filterInput['interessado']) ?>"></label>
                    <label>Localização <input name="localizacao" value="<?= h($filterInput['localizacao']) ?>"></label>
                    <label>Temporalidade <input name="temporalidade" value="<?= h($filterInput['temporalidade']) ?>"></label>
                </div>
            </details>
        </form>
    </section>

    <?php if (!$hasFilters): ?>
        <div class="empty-state">Digite um termo para pesquisar no acervo.</div>
    <?php elseif (!$results): ?>
        <div class="alert">Nenhum documento encontrado para este termo em todo o acervo.</div>
    <?php else: ?>
        <div class="alert success"><?= count($results) ?> item(ns) encontrado(s). Mostrando os primeiros 100 resultados.</div>
        <?php foreach ($results as $row): render_acervo_card($row, $term); endforeach; ?>
    <?php endif;
}

function render_central(): void
{
    $attention = attention_items();
    $jobs = recent_import_jobs(5);
    $events = recent_system_events(8);
    $map = acervo_map_data(5);
    ?>
    <section class="dashboard-page">
        <div class="dashboard-hero">
            <div>
                <span class="eyebrow">Central de Trabalho</span>
                <h2>O que precisa de atenção hoje?</h2>
                <p>Um painel de partida para consulta, cadastro, sincronização, diagnóstico e revisão rápida do acervo.</p>
            </div>
            <div class="dashboard-actions">
                <a class="button primary" href="/?page=busca">Consultar acervo</a>
                <a class="button" href="/?page=cad_caixa">Cadastrar caixa</a>
                <a class="button" href="/?page=assistente_openai">Falar com IA</a>
            </div>
        </div>
        <?php if (sei_terceirizados()): ?>
            <?php render_sei_queue_widget(); ?>
        <?php endif; ?>
        <div class="dashboard-grid">
            <section class="dashboard-card quality-card">
                <div class="dashboard-card-head"><div><span class="eyebrow">Atenção</span><h3>Fila de melhoria</h3></div></div>
                <?php if (!$attention): ?><div class="empty-state">Nada crítico encontrado agora.</div><?php endif; ?>
                <div class="quality-list">
                    <?php foreach ($attention as $item): ?>
                        <a class="quality-row" href="<?= h($item['href']) ?>"><span><?= h($item['label']) ?></span><strong><?= h(number_format((int) $item['value'], 0, ',', '.')) ?></strong><em>corrigir</em><i style="--w: 100%"></i></a>
                    <?php endforeach; ?>
                </div>
            </section>
            <section class="dashboard-card">
                <div class="dashboard-card-head"><div><span class="eyebrow">Importação</span><h3>Últimos jobs</h3></div><a class="mini-link" href="/?page=diagnostico">Diagnóstico</a></div>
                <div class="activity-list">
                    <?php foreach ($jobs as $job): ?><article><strong><?= h($job['status']) ?></strong><span><?= h($job['tipo']) ?> · <?= h((string) $job['total_registros']) ?> registro(s)</span><em><?= h($job['concluido_em'] ?: $job['criado_em']) ?></em></article><?php endforeach; ?>
                </div>
            </section>
            <section class="dashboard-card">
                <div class="dashboard-card-head"><div><span class="eyebrow">Mapa</span><h3>Locais com mais caixas</h3></div><a class="mini-link" href="/?page=mapa_acervo">Abrir mapa</a></div>
                <div class="rank-list compact">
                    <?php $max = max(array_map(fn ($row) => (int) $row['caixas'], $map ?: [['caixas' => 0]])); ?>
                    <?php foreach ($map as $row): ?><div class="rank-row"><span><?= h($row['localizacao']) ?></span><strong><?= h((string) $row['caixas']) ?></strong><i style="--w: <?= h((string) ($max ? max(4, round(((int) $row['caixas'] / $max) * 100)) : 0)) ?>%"></i></div><?php endforeach; ?>
                </div>
            </section>
            <section class="dashboard-card wide">
                <div class="dashboard-card-head"><div><span class="eyebrow">Timeline</span><h3>Últimos eventos</h3></div></div>
                <div class="activity-list">
                    <?php foreach ($events as $event): ?><article><strong><?= h($event['tipo']) ?></strong><span><?= h($event['mensagem']) ?></span><em><?= h($event['usuario_nome'] ?: $event['usuario_login'] ?: 'Sistema') ?> · <?= h($event['criado_em']) ?></em></article><?php endforeach; ?>
                </div>
            </section>
        </div>
    </section>
    <?php
}

function render_sei_home_widget(): void
{
    $state = sei_queue_state($_SESSION['user'] ?? []);
    $next = $state['next'];
    $last = $state['last'];
    $isAdmin = user_is_admin();
    ?>
    <section class="sei-home-card <?= $state['is_turn'] ? 'is-your-turn' : '' ?>" aria-label="Fila de atendimentos SEI">
        <span class="sei-home-title">Atendimentos SEI</span>
        <?php if (!$next): ?>
            <strong>Fila aguardando terceirizados</strong>
        <?php elseif ($state['is_turn']): ?>
            <strong>E a sua vez!</strong>
            <form method="post" class="sei-home-form">
                <input type="hidden" name="action" value="save_sei_demanda">
                <input type="hidden" name="return_page" value="busca">
                <input name="processo" inputmode="numeric" placeholder="00000.00000/0000-00" pattern="\d{5}\.\d{5}/\d{4}-\d{2}" required>
                <button class="primary" type="submit">OK</button>
            </form>
        <?php else: ?>
            <span>Próxima demanda</span>
            <strong class="sei-home-next"><?= h($next['nome'] ?: $next['login']) ?></strong>
        <?php endif; ?>

        <div class="sei-home-meta">
            <span><?= h((string) $state['position']) ?>/<?= h((string) $state['total']) ?></span>
            <?php if ($last): ?><span><?= ($last['status'] ?? 'atendido') === 'pulado' ? 'ultimo: pulado' : 'ultimo: ' . h((string) $last['processo']) ?></span><?php endif; ?>
        </div>

        <div class="sei-home-actions">
            <a href="/?page=rel_demanda_sei">Relatório</a>
            <?php if ($isAdmin && $next): ?>
                <form method="post" onsubmit="return confirm('Pular a vez deste usuario na demanda SEI?')">
                    <input type="hidden" name="action" value="skip_sei_demanda">
                    <input type="hidden" name="return_page" value="<?= h(current_page()) ?>">
                    <input type="hidden" name="usuario_login" value="<?= h((string) $next['login']) ?>">
                    <button type="submit">Pular</button>
                </form>
            <?php endif; ?>
        </div>
    </section>
    <?php
}

function render_sei_queue_widget(): void
{
    $state = sei_queue_state($_SESSION['user'] ?? []);
    $next = $state['next'];
    $last = $state['last'];
    $afterNext = $state['after_next'];
    ?>
    <section class="sei-demand-card <?= $state['is_turn'] ? 'is-your-turn' : '' ?>">
        <div class="sei-demand-orb"><?= app_icon('send') ?></div>
        <div class="sei-demand-main">
            <span class="eyebrow">Demanda SEI</span>
            <?php if (!$next): ?>
                <h2>Fila aguardando terceirizados</h2>
                <p>Cadastre usuários como terceirizados para ativar o rodízio de atendimentos.</p>
            <?php elseif ($state['is_turn']): ?>
                <h2>Pr&oacute;xima demanda do SEI &eacute; sua!</h2>
                <p>Registre o processo atendido para liberar automaticamente o pr&oacute;ximo colega da fila.</p>
                <form method="post" class="sei-demand-form">
                    <input type="hidden" name="action" value="save_sei_demanda">
                    <input type="hidden" name="return_page" value="<?= h(current_page()) ?>">
                    <input name="processo" inputmode="numeric" placeholder="00000.00000/0000-00" pattern="\d{5}\.\d{5}/\d{4}-\d{2}" required>
                    <button class="primary" type="submit">OK</button>
                </form>
            <?php else: ?>
                <h2>Pr&oacute;xima demanda do SEI</h2>
                <p class="sei-next-name"><?= h($next['nome'] ?: $next['login']) ?></p>
                <span class="sei-waiting">Sua vez chega assim que o atendimento atual for registrado.</span>
            <?php endif; ?>
        </div>
        <aside class="sei-demand-side">
            <div>
                <span>Posi&ccedil;&atilde;o</span>
                <strong><?= h((string) $state['position']) ?>/<?= h((string) $state['total']) ?></strong>
            </div>
            <div>
                <span>&Uacute;ltimo movimento</span>
                <strong><?= h((string) ($last['usuario_nome'] ?? 'Ainda sem registro')) ?></strong>
                <?php if ($last): ?>
                    <small><?= ($last['status'] ?? 'atendido') === 'pulado' ? 'Vez pulada' : h((string) $last['processo']) ?></small>
                <?php endif; ?>
            </div>
            <div>
                <span>Depois</span>
                <strong><?= h((string) ($afterNext['nome'] ?? 'Fila reinicia')) ?></strong>
            </div>
            <a class="button" href="/?page=rel_demanda_sei">Relat&oacute;rio Demanda SEI</a>
        </aside>
    </section>
    <?php
}

function render_acervo_card(array $row, string $searchContext = ''): void
{
    $status = trim((string) ($row['STATUS_EMPRESTIMO'] ?? ''));
    $isOutOfArchive = $status === 'EMPRESTADO';
    $displayStatus = $isOutOfArchive ? 'EMPRESTADO' : 'DISPONIVEL';
    $canMove = user_can_move_acervo();
    $tipoDoc = trim((string) ($row['OBSERVACAO'] ?? ''));
    if ($tipoDoc === '') {
        $tipoDoc = '---';
    }
    $tempSuggestion = temporalidade_suggestion($row, $searchContext);
    ?>
    <details class="result-card" id="acervo-<?= h($row['ID_UNICO'] ?? '') ?>">
        <summary class="result-summary">
            <span class="result-chevron">â€º</span>
            <span class="result-box-icon" aria-hidden="true">ðŸ“¦</span>
            <strong class="result-box">CX: <?= h($row['CAIXA'] ?? '---') ?></strong>
            <span class="result-doc-icon" aria-hidden="true">ðŸ“„</span>
            <span class="result-subject"><?= h($row['ASSUNTO'] ?? '---') ?></span>
            <span class="result-divider">|</span>
            <em class="result-status <?= $isOutOfArchive ? 'is-out-of-archive' : '' ?>"><?= h($displayStatus) ?></em>
        </summary>
        <form method="post" class="result-detail-form" data-result-edit-form>
            <input type="hidden" name="action" value="save_acervo">
            <input type="hidden" name="return_page" value="<?= h(current_page()) ?>">
            <input type="hidden" name="return_url" value="<?= h(($_SERVER['REQUEST_URI'] ?? '/?page=busca') . '#acervo-' . ($row['ID_UNICO'] ?? '')) ?>">
            <input type="hidden" name="ID_UNICO" value="<?= h($row['ID_UNICO'] ?? '') ?>">
            <input type="hidden" name="STATUS_EMPRESTIMO" value="<?= h($status) ?>">

            <label class="result-edit-toggle">
                <input type="checkbox" data-result-edit-toggle aria-label="Habilitar alterações neste resultado">
                <span class="result-toggle-switch" aria-hidden="true"></span>
                <span>Habilitar alterações</span>
            </label>

            <div class="result-field-grid">
                <label>Unidades <input name="UNIDADE" data-result-editable value="<?= h($row['UNIDADE'] ?? '---') ?>"></label>
                <label>N&ordm; Processos <input name="PROCESSO" data-result-editable value="<?= h($row['PROCESSO'] ?? '---') ?>"></label>
                <label>N&ordm; Caixas <input name="CAIXA" data-result-editable value="<?= h($row['CAIXA'] ?? '---') ?>"></label>
                <label>Localiza&ccedil;&atilde;o <input name="LOCALIZACAO" data-result-editable value="<?= h($row['LOCALIZACAO'] ?? '---') ?>"></label>
                <label>Volumes <input name="VOLUMES" data-result-editable value="<?= h($row['VOLUMES'] ?? '---') ?>"></label>
                <label>Tipo de Doc <input name="OBSERVACAO" data-result-editable value="<?= h($tipoDoc) ?>"></label>
                <label>Interessados <input name="INTERESSADO" data-result-editable value="<?= h($row['INTERESSADO'] ?? '---') ?>"></label>
                <label>Assuntos <input name="ASSUNTO" data-result-editable value="<?= h($row['ASSUNTO'] ?? '---') ?>"></label>
                <label>Respons&aacute;vel <input value="<?= h($row['RESPONSAVEL'] ?? '---') ?>" readonly></label>
                <label>N&ordm; Cod Temp
                    <input name="TEMPORALIDADE" data-result-editable value="<?= h($row['TEMPORALIDADE'] ?? '---') ?>">
                    <?php if ($tempSuggestion): ?>
                        <span class="temp-hint">
                            Sugest&atilde;o:
                            <button type="button" class="temp-code-link" data-result-edit-control data-temp-code="<?= h($tempSuggestion['code'] ?? '') ?>">
                                <?= h($tempSuggestion['code'] ?? '') ?>
                            </button>
                            <?= h($tempSuggestion['title'] ?? '') ?>
                            <small>p&aacute;g. <?= h((string) ($tempSuggestion['page'] ?? '')) ?></small>
                        </span>
                    <?php else: ?>
                        <span class="temp-hint temp-hint-empty">Sem sugest&atilde;o na tabela do MDS</span>
                    <?php endif; ?>
                </label>
                <label>Data <input name="DATA" data-result-editable value="<?= h($row['DATA'] ?? '---') ?>"></label>
                <label>Data Limite <input name="DATA_LIMITE" data-result-editable value="<?= h($row['DATA_LIMITE'] ?? '---') ?>"></label>
            </div>

            <div class="result-footer">
                <?php if ($isOutOfArchive): ?>
                    <label class="withdrawn-field">Quem Retirou
                        <input name="QUEM_RETIROU" value="<?= h($row['QUEM_RETIROU'] ?? '---') ?>" readonly>
                    </label>
                <?php endif; ?>
                <div class="result-actions">
                    <?php if (!$isOutOfArchive && $canMove): ?>
                        <a class="result-movement-link is-active" href="#movimento-saida-<?= h($row['ID_UNICO'] ?? '') ?>" data-movement-modal-trigger>&#128228; Sa&iacute;da</a>
                    <?php else: ?>
                        <button class="result-movement-link is-disabled" type="button" disabled>&#128228; Sa&iacute;da</button>
                    <?php endif; ?>
                    <?php if ($isOutOfArchive && $canMove): ?>
                        <a class="result-movement-link is-active" href="#movimento-retorno-<?= h($row['ID_UNICO'] ?? '') ?>" data-movement-modal-trigger>&#128229; Retorno</a>
                    <?php else: ?>
                        <button class="result-movement-link is-disabled" type="button" disabled>&#128229; Retorno</button>
                    <?php endif; ?>
                    <button class="save-result" type="submit" data-result-action data-edit-only>&#10003; Salvar Altera&ccedil;&otilde;es</button>
                    <details class="result-history" data-edit-only>
                        <summary>🕒 Histórico</summary>
                        <p>Modificado por: <strong><?= h($row['ALTERADO_POR'] ?? '---') ?></strong><br>Em: <?= h($row['ULTIMA_ALTERACAO'] ?? '---') ?></p>
                    </details>
                </div>
                <span class="result-modified">Modificado por: <?= h($row['ALTERADO_POR'] ?? '---') ?> em <?= h($row['ULTIMA_ALTERACAO'] ?? '---') ?></span>
            </div>
        </form>
        <div class="movement-modal" id="movimento-<?= h($row['ID_UNICO'] ?? '') ?>" hidden>
            <form method="post" class="movement-form movement-dialog" role="dialog" aria-modal="true">
                <input type="hidden" name="action" value="move_acervo">
                <input type="hidden" name="ID_UNICO" value="<?= h($row['ID_UNICO'] ?? '') ?>">
                <input type="hidden" name="movimento" value="">
                <input type="hidden" name="return_url" value="<?= h(($_SERVER['REQUEST_URI'] ?? '/?page=busca') . '#acervo-' . ($row['ID_UNICO'] ?? '')) ?>">
                <input type="hidden" name="return_page" value="<?= h(current_page()) ?>">
                <header class="movement-dialog-header">
                    <h3>📋 Registro de Movimentação</h3>
                    <button type="button" class="dialog-close" onclick="closeAcervoMovement(this)" aria-label="Fechar">&times;</button>
                </header>
                <p class="movement-action-copy">Você está registrando a <strong data-movement-title>Saída</strong> do item:</p>
                <p class="movement-item-summary">📦 <strong>CX: <?= h($row['CAIXA'] ?? '---') ?> | <?= h($row['ASSUNTO'] ?? 'Sem assunto') ?></strong></p>
                <section class="movement-fields">
                    <label>Solicitante / Interessado <input name="solicitante" required placeholder="Quem está retirando/devolvendo?"></label>
                    <label>Setor / Unidade <input name="setor" required placeholder="Ex: CGPRO / SAA"></label>
                    <label>Data da Ação <input type="date" name="data_movimento" value="<?= date('Y-m-d') ?>" required></label>
                    <label>Observações Adicionais <textarea name="observacao" rows="4"></textarea></label>
                    <div class="movement-dialog-actions">
                        <button class="primary" type="submit">✅ Confirmar Registro</button>
                        <button type="button" onclick="closeAcervoMovement(this)">❌ Cancelar</button>
                    </div>
                </section>
            </form>
        </div>
        <?php render_acervo_movement_modal($row, 'saida'); ?>
        <?php render_acervo_movement_modal($row, 'retorno'); ?>
    </details>
    <?php
}

function render_acervo_movement_modal(array $row, string $movement): void
{
    $id = (string) ($row['ID_UNICO'] ?? '');
    $modalId = 'movimento-' . $movement . '-' . $id;
    $label = $movement === 'saida' ? 'Saída' : 'Retorno';
    ?>
    <div class="movement-modal movement-modal-target" id="<?= h($modalId) ?>" hidden>
        <form method="post" class="movement-form movement-dialog" role="dialog" aria-modal="true">
            <input type="hidden" name="action" value="move_acervo">
            <input type="hidden" name="ID_UNICO" value="<?= h($id) ?>">
            <input type="hidden" name="movimento" value="<?= h($movement) ?>">
            <input type="hidden" name="return_url" value="<?= h(($_SERVER['REQUEST_URI'] ?? '/?page=busca') . '#acervo-' . $id) ?>">
            <input type="hidden" name="return_page" value="<?= h(current_page()) ?>">
            <header class="movement-dialog-header">
                <h3>📋 Registro de Movimentação</h3>
                <a class="dialog-close" href="#acervo-<?= h($id) ?>" aria-label="Fechar">&times;</a>
            </header>
            <p class="movement-action-copy">Você está registrando a <strong><?= h($label) ?></strong> do item:</p>
            <p class="movement-item-summary">📦 <strong>CX: <?= h($row['CAIXA'] ?? '---') ?> | <?= h($row['ASSUNTO'] ?? 'Sem assunto') ?></strong></p>
            <section class="movement-fields">
                <label>Solicitante / Interessado <input name="solicitante" required placeholder="Quem está retirando/devolvendo?"></label>
                <label>Setor / Unidade <input name="setor" required placeholder="Ex: CGPRO / SAA"></label>
                <label>Data da Ação <input type="date" name="data_movimento" value="<?= date('Y-m-d') ?>" required></label>
                <label>Observações Adicionais <textarea name="observacao" rows="4"></textarea></label>
                <div class="movement-dialog-actions">
                    <button class="primary" type="submit">✅ Confirmar Registro</button>
                    <a href="#acervo-<?= h($id) ?>">❌ Cancelar</a>
                </div>
            </section>
        </form>
    </div>
    <?php
}

function label_for(string $field): string
{
    return [
        'UNIDADE' => 'Unidade',
        'ASSUNTO' => 'Assunto',
        'INTERESSADO' => 'Interessado',
        'DATA' => 'Data',
        'TEMPORALIDADE' => 'Cód. Temp',
        'CAIXA' => 'Caixa',
        'PROCESSO' => 'Processo',
        'LOCALIZACAO' => 'Localização',
        'OBSERVACAO' => 'Observação',
        'VOLUMES' => 'Volumes',
        'DATA_LIMITE' => 'Data Limite',
        'STATUS_EMPRESTIMO' => 'Status Empréstimo',
        'QUEM_RETIROU' => 'Quem Retirou',
    ][$field] ?? $field;
}

function render_cad_caixa(): void
{
    render_cadastro_acervo('cad_caixa', 'Cadastro de Acervo - DIARQ', 'Salvar Tudo no Sistema');
}

function render_cad_processo(): void
{
    ?>
    <section class="manual-page">
        <div class="manual-head">
            <div>
                <span class="eyebrow">Novo cadastro</span>
                <h2>Novo Cadastro Manual</h2>
                <p class="muted">Cadastre varios processos ou servidores dentro da mesma caixa.</p>
            </div>
            <a class="button" href="/?page=busca">Fechar</a>
        </div>

        <form method="post" class="manual-form" autocomplete="off" data-manual-form>
            <input type="hidden" name="action" value="save_manual_processos">
            <input type="hidden" name="return_page" value="cad_processo">
            <input autocomplete="false" name="hidden_autofill_guard" type="text" tabindex="-1" aria-hidden="true" class="autofill-guard">

            <section class="manual-section">
                <div class="section-heading">
                    <div>
                        <span class="eyebrow">Dados gerais da caixa</span>
                        <h3>Caixa e classificação</h3>
                    </div>
                    <span class="section-chip">Manual</span>
                </div>
                <div class="manual-grid">
                    <label>Unidade <input name="UNIDADE" value="DIARQ / MDS" autocomplete="off"></label>
                    <label>Nº de Caixa * <input name="CAIXA" required autocomplete="off"></label>
                    <label>Tipo de Documento <input name="TIPO_DOCUMENTO" autocomplete="off"></label>
                    <label>Localização <input name="LOCALIZACAO" autocomplete="off"></label>
                    <label>Data-limite <input name="DATA_LIMITE" autocomplete="off"></label>
                    <label>Data do cadastro <input name="DATA" value="<?= h(date('d/m/Y')) ?>" autocomplete="off"></label>
                    <label>Cod. Temp <input name="TEMPORALIDADE" autocomplete="off"></label>
                </div>
            </section>

            <section class="manual-section">
                <div class="section-heading">
                    <div>
                        <span class="eyebrow">Itens da caixa</span>
                        <h3>Processos / Servidores</h3>
                    </div>
                    <button type="button" class="button manual-add" data-add-manual-item>Adicionar item</button>
                </div>
                <div class="manual-items" data-manual-items>
                    <div class="manual-item" data-manual-item>
                        <div class="manual-item-title">
                            <strong>Item #1</strong>
                            <button type="button" class="small danger manual-remove" data-remove-manual-item hidden>Remover</button>
                        </div>
                        <div class="manual-grid item-grid">
                            <label>Nº de Processo ou Servidor * <input name="PROCESSO[]" required autocomplete="off"></label>
                            <label>Volumes <input name="VOLUMES[]" autocomplete="off"></label>
                            <label>Interessado <input name="INTERESSADO[]" autocomplete="off"></label>
                            <label>Assunto <input name="ASSUNTO[]" autocomplete="off"></label>
                        </div>
                    </div>
                </div>
            </section>

            <div class="manual-actions">
                <button class="primary" type="submit">Finalizar e Concluir Cadastro</button>
                <button class="button" type="reset">Limpar campos</button>
            </div>
        </form>
    </section>
    <?php
}

function render_cadastro_acervo(string $returnPage, string $title, string $buttonLabel): void
{
    ?>
    <section class="panel">
        <h2><?= h($title) ?></h2>
        <form method="post" class="grid-form wide">
            <input type="hidden" name="action" value="save_acervo">
            <input type="hidden" name="return_page" value="<?= h($returnPage) ?>">
            <label>Unidade <input name="UNIDADE" value="DIARQ / MDS"></label>
            <label>Caixa <input name="CAIXA" required></label>
            <label>Localização <input name="LOCALIZACAO"></label>
            <label>Data <input name="DATA" value="<?= h(date('d/m/Y')) ?>"></label>
            <label>Cod. Temp <input name="TEMPORALIDADE"></label>
            <label>Processo <input name="PROCESSO"></label>
            <label>Volumes <input name="VOLUMES"></label>
            <label>Interessado <input name="INTERESSADO"></label>
            <label class="span-2">Assunto <textarea name="ASSUNTO" rows="3"></textarea></label>
            <label class="span-2">Observação <textarea name="OBSERVACAO" rows="3"></textarea></label>
            <div class="form-actions span-2">
                <button class="primary" type="submit"><?= h($buttonLabel) ?></button>
                <a class="button" href="/?page=busca">Voltar para Busca</a>
            </div>
        </form>
    </section>
    <?php
}

function render_planilha(): void
{
    $params = [];
    $where = planilha_filter_sql($_GET, $params);
    $perPage = 10;
    $pageNum = max(1, (int) ($_GET['p'] ?? 1));

    $responsaveis = db()->query("
        SELECT DISTINCT RESPONSAVEL
        FROM acervo
        WHERE TRIM(COALESCE(PROCESSO, '')) <> ''
          AND TRIM(COALESCE(RESPONSAVEL, '')) <> ''
        ORDER BY RESPONSAVEL
    ")->fetchAll();

    $countStmt = db()->prepare("SELECT COUNT(*) FROM acervo {$where}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($total / $perPage));
    $pageNum = min($pageNum, $totalPages);
    $offset = ($pageNum - 1) * $perPage;

    $rowsStmt = db()->prepare("
        SELECT *
        FROM acervo
        {$where}
        ORDER BY rowid DESC
        LIMIT :limit OFFSET :offset
    ");
    foreach ($params as $key => $value) {
        $rowsStmt->bindValue($key, $value);
    }
    $rowsStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $rowsStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $rowsStmt->execute();
    $rows = $rowsStmt->fetchAll();

    $selectedResp = trim((string) ($_GET['responsavel'] ?? ''));
    $selectedOrigem = trim((string) ($_GET['origem'] ?? ''));
    $term = trim((string) ($_GET['q'] ?? ''));
    $baseQuery = ['page' => 'planilha'];
    if ($selectedResp !== '') {
        $baseQuery['responsavel'] = $selectedResp;
    }
    if ($selectedOrigem !== '') {
        $baseQuery['origem'] = $selectedOrigem;
    }
    if ($term !== '') {
        $baseQuery['q'] = $term;
    }
    $exportQuery = $baseQuery + ['export' => 'acervo', 'cadastros' => '1'];
    ?>
    <section class="cadastros-page">
        <div class="cadastros-hero">
            <div>
                <span class="eyebrow">Planilha operacional</span>
                <h2>Visualização de Cadastros</h2>
                <p>Registros manuais e cadastros sem origem importada, organizados para conferência e limpeza.</p>
            </div>
            <div class="cadastros-stats">
                <div><strong><?= h((string) $total) ?></strong><span>Registros</span></div>
                <div><strong><?= h((string) $totalPages) ?></strong><span>Páginas</span></div>
            </div>
        </div>

        <form class="cadastros-filters" method="get">
            <input type="hidden" name="page" value="planilha">
            <label>Filtrar por responsável
                <select name="responsavel">
                    <option value="">Todos</option>
                    <?php foreach ($responsaveis as $resp): ?>
                        <?php $name = (string) ($resp['RESPONSAVEL'] ?? ''); ?>
                        <option value="<?= h($name) ?>" <?= $selectedResp === $name ? 'selected' : '' ?>><?= h($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Origem
                <select name="origem">
                    <option value="" <?= $selectedOrigem === '' ? 'selected' : '' ?>>Todos</option>
                    <option value="manual" <?= $selectedOrigem === 'manual' ? 'selected' : '' ?>>Cadastro manual</option>
                    <option value="importado" <?= $selectedOrigem === 'importado' ? 'selected' : '' ?>>Importados</option>
                </select>
            </label>
            <label>Pesquisar
                <input name="q" value="<?= h($term) ?>" placeholder="Caixa, processo, interessado, assunto...">
            </label>
            <div class="cadastros-filter-actions">
                <button class="primary" type="submit"><?= app_icon('send') ?>Filtrar</button>
                <a class="button" href="/?page=planilha">Limpar</a>
                <a class="button export-button" href="/?<?= h(http_build_query($exportQuery)) ?>"><?= app_icon('download') ?>Baixar Excel</a>
                <a class="button export-button" href="/?page=planilha&export=locais_vazios&format=xlsx"><?= app_icon('download') ?>Excel locais vazios</a>
                <a class="button export-button" href="/?page=planilha&export=locais_vazios&format=pdf"><?= app_icon('download') ?>PDF locais vazios</a>
            </div>
        </form>

        <form method="post" class="cadastros-table-form" data-bulk-form onsubmit="return confirm('Confirma a exclusao selecionada?')">
            <input type="hidden" name="action" value="delete_acervo_bulk">
            <?php if (!$rows): ?>
                <div class="empty-state">Nenhum registro encontrado.</div>
            <?php else: ?>
                <div class="cadastros-table-head">
                    <span>Mostrando <?= h((string) ($offset + 1)) ?>-<?= h((string) min($offset + $perPage, $total)) ?> de <?= h((string) $total) ?></span>
                    <button class="danger small" type="submit"><?= app_icon('trash') ?>Excluir selecionados</button>
                </div>
                <div class="table-wrap cadastros-table-wrap">
                    <table class="cadastros-table">
                        <thead>
                            <tr>
                                <th class="select-col"><input type="checkbox" data-select-all aria-label="Selecionar todos"></th>
                                <th>Caixa</th>
                                <th>Processo / Servidor</th>
                                <th>Volumes</th>
                                <th>Interessado</th>
                                <th>Assunto</th>
                                <th>Localização</th>
                                <th>Responsável</th>
                                <th>Data-limite</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td class="select-col"><input type="checkbox" name="ids[]" value="<?= h($row['ID_UNICO']) ?>" data-row-check aria-label="Selecionar cadastro"></td>
                                <td><strong><?= h($row['CAIXA']) ?></strong></td>
                                <td><?= h($row['PROCESSO']) ?></td>
                                <td><?= h($row['VOLUMES']) ?></td>
                                <td><?= h($row['INTERESSADO']) ?></td>
                                <td class="text-col"><?= h($row['ASSUNTO']) ?></td>
                                <td><?= h($row['LOCALIZACAO']) ?></td>
                                <td><?= h($row['RESPONSAVEL']) ?></td>
                                <td><?= h($row['DATA_LIMITE']) ?></td>
                                <td><button class="danger small" type="submit" name="delete_one" value="<?= h($row['ID_UNICO']) ?>"><?= app_icon('trash') ?>Excluir</button></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <nav class="pagination" aria-label="Paginação">
                    <?php
                    $prevQuery = $baseQuery + ['p' => max(1, $pageNum - 1)];
                    $nextQuery = $baseQuery + ['p' => min($totalPages, $pageNum + 1)];
                    ?>
                    <a class="button <?= $pageNum <= 1 ? 'disabled' : '' ?>" href="/?<?= h(http_build_query($prevQuery)) ?>">Anterior</a>
                    <span>Página <?= h((string) $pageNum) ?> de <?= h((string) $totalPages) ?></span>
                    <a class="button <?= $pageNum >= $totalPages ? 'disabled' : '' ?>" href="/?<?= h(http_build_query($nextQuery)) ?>">Próxima</a>
                </nav>
            <?php endif; ?>
        </form>
        <div class="cadastros-note">Use o filtro de origem para alternar entre todos os registros, cadastros manuais e importados.</div>
    </section>
    <?php
}

function render_usuarios(): void
{
    if (!user_is_admin()) {
        echo '<div class="alert danger">Acesso restrito ao administrador.</div>';
        return;
    }
    $users = db()->query('SELECT * FROM usuarios ORDER BY nome')->fetchAll();
    $totalUsers = count($users);
    $totalAdmins = count(array_filter($users, fn ($user) => (int) ($user['p_gerir_usuarios'] ?? 0) === 1 || strtoupper((string) ($user['login'] ?? '')) === 'ADMIN'));
    $totalSync = count(array_filter($users, fn ($user) => (int) ($user['p_sincronizar'] ?? 0) === 1));
    ?>
    <section class="users-page">
        <div class="users-hero">
            <div>
                <span class="eyebrow">Controle de acesso</span>
                <h2>Gestão de Usuários e Permissões</h2>
                <p>Cadastre colaboradores, defina perfis e mantenha permissões essenciais organizadas em um só lugar.</p>
            </div>
            <div class="users-stats">
                <div><strong><?= $totalUsers ?></strong><span>Usuários</span></div>
                <div><strong><?= $totalAdmins ?></strong><span>Admins</span></div>
                <div><strong><?= $totalSync ?></strong><span>Sync</span></div>
            </div>
        </div>

        <div class="users-grid">
            <section class="user-card user-form-card">
                <div class="section-heading">
                    <div>
                        <span class="eyebrow">Novo cadastro</span>
                        <h3>Dados do usuário</h3>
                    </div>
                    <span class="section-chip">Supabase obrigatório</span>
                </div>

                <form method="post" class="user-form">
                    <input type="hidden" name="action" value="save_user">
                    <label>Nome completo <input name="nome" placeholder="Ex: Maria Silva" required></label>
                    <label>Login <input name="login" placeholder="usuario.sobrenome" required></label>
                    <label>Senha <input name="senha" placeholder="Senha inicial" required></label>
                    <label>Tipo
                        <select name="tipo_usuario"><option>Servidor</option><option>Terceirizado</option></select>
                    </label>
                    <label class="span-2">Setor <input name="departamento" value="DIARQ"></label>

                    <div class="permission-panel span-2">
                        <div class="permission-title">
                            <strong>Permissões</strong>
                            <span>Escolha o que este usuário pode acessar</span>
                        </div>
                        <div class="permission-grid">
                            <label class="permission-item"><input type="checkbox" name="p_gerir_usuarios"><span>Gerir usuários</span></label>
                            <label class="permission-item"><input type="checkbox" name="p_sincronizar"><span>Sincronizar</span></label>
                            <label class="permission-item"><input type="checkbox" name="p_cadastrar_caixa" checked><span>Cadastrar caixa</span></label>
                            <label class="permission-item"><input type="checkbox" name="p_botao_editar" checked><span>Editar acervo</span></label>
                            <label class="permission-item"><input type="checkbox" name="p_emprestimo"><span>Empréstimo</span></label>
                            <label class="permission-item"><input type="checkbox" name="TROCAR_SENHA"><span>Trocar senha no primeiro acesso</span></label>
                        </div>
                    </div>

                    <div class="form-actions span-2">
                        <button class="primary user-submit" type="submit">Finalizar e Salvar no Banco</button>
                    </div>
                </form>
            </section>

            <section class="user-card users-list-card">
                <div class="section-heading">
                    <div>
                        <span class="eyebrow">Equipe ativa</span>
                        <h3>Usuários cadastrados</h3>
                    </div>
                    <span class="section-chip"><?= $totalUsers ?> registro(s)</span>
                </div>

                <div class="users-list">
                    <?php foreach ($users as $user): ?>
                        <?php
                            $isAdmin = (int) ($user['p_gerir_usuarios'] ?? 0) === 1 || strtoupper((string) ($user['login'] ?? '')) === 'ADMIN';
                            $syncOn = (int) ($user['p_sincronizar'] ?? 0) === 1;
                            $initial = strtoupper(substr((string) ($user['nome'] ?: $user['login'] ?: 'U'), 0, 1));
                        ?>
                        <article class="user-row">
                            <div class="avatar"><?= h($initial) ?></div>
                            <div class="user-main">
                                <strong><?= h($user['nome'] ?: 'Sem nome') ?></strong>
                                <span><?= h($user['login'] ?: 'sem-login') ?> · <?= h($user['departamento'] ?: 'DIARQ') ?></span>
                            </div>
                            <div class="user-badges">
                                <span class="badge"><?= h($user['tipo_usuario'] ?: 'Servidor') ?></span>
                                <span class="badge <?= $isAdmin ? 'on' : '' ?>"><?= $isAdmin ? 'Admin' : 'Operador' ?></span>
                                <span class="badge <?= $syncOn ? 'sync' : '' ?>"><?= $syncOn ? 'Sync' : 'Local' ?></span>
                            </div>
                            <div class="user-actions">
                                <?php if (strtoupper((string) $user['login']) !== 'ADMIN'): ?>
                                    <form method="post" onsubmit="return confirm('Excluir usuario?')">
                                        <input type="hidden" name="action" value="delete_user">
                                        <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
                                        <button class="danger small"><?= app_icon('trash') ?>Excluir</button>
                                    </form>
                                <?php else: ?>
                                    <span class="admin-lock">Protegido</span>
                                <?php endif; ?>
                            </div>
                            <?php if (strtoupper((string) $user['login']) !== 'ADMIN'): ?>
                                <details class="user-edit-details">
                                    <summary>Alterar cadastro</summary>
                                    <form method="post" class="user-edit-form">
                                        <input type="hidden" name="action" value="save_user">
                                        <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
                                        <input type="hidden" name="setores_permitidos" value="<?= h($user['setores_permitidos'] ?? '') ?>">

                                        <label>Nome completo <input name="nome" value="<?= h($user['nome'] ?? '') ?>" required></label>
                                        <label>Login <input name="login" value="<?= h($user['login'] ?? '') ?>" required></label>
                                        <label>Senha <input name="senha" value="<?= h($user['senha'] ?? '') ?>" required></label>
                                        <label>Tipo
                                            <select name="tipo_usuario">
                                                <option value="Servidor" <?= normalize_user_type((string) ($user['tipo_usuario'] ?? '')) === 'Servidor' ? 'selected' : '' ?>>Servidor</option>
                                                <option value="Terceirizado" <?= normalize_user_type((string) ($user['tipo_usuario'] ?? '')) === 'Terceirizado' ? 'selected' : '' ?>>Terceirizado</option>
                                            </select>
                                        </label>
                                        <label class="span-2">Setor <input name="departamento" value="<?= h($user['departamento'] ?? 'DIARQ') ?>"></label>

                                        <div class="permission-grid span-2">
                                            <label class="permission-item"><input type="checkbox" name="p_gerir_usuarios" <?= (int) ($user['p_gerir_usuarios'] ?? 0) === 1 ? 'checked' : '' ?>><span>Gerir usu&aacute;rios</span></label>
                                            <label class="permission-item"><input type="checkbox" name="p_sincronizar" <?= (int) ($user['p_sincronizar'] ?? 0) === 1 ? 'checked' : '' ?>><span>Sincronizar</span></label>
                                            <label class="permission-item"><input type="checkbox" name="p_cadastrar_caixa" <?= (int) ($user['p_cadastrar_caixa'] ?? 0) === 1 ? 'checked' : '' ?>><span>Cadastrar caixa</span></label>
                                            <label class="permission-item"><input type="checkbox" name="p_botao_editar" <?= (int) ($user['p_botao_editar'] ?? 0) === 1 ? 'checked' : '' ?>><span>Editar acervo</span></label>
                                            <label class="permission-item"><input type="checkbox" name="p_emprestimo" <?= (int) ($user['p_emprestimo'] ?? 0) === 1 ? 'checked' : '' ?>><span>Empr&eacute;stimo</span></label>
                                            <label class="permission-item"><input type="checkbox" name="TROCAR_SENHA" <?= (int) ($user['TROCAR_SENHA'] ?? 0) === 1 ? 'checked' : '' ?>><span>Trocar senha no primeiro acesso</span></label>
                                        </div>

                                        <div class="form-actions span-2">
                                            <button class="primary small" type="submit">Alterar cadastro</button>
                                        </div>
                                    </form>
                                </details>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>
    </section>
    <?php
}

function render_documentos(): void
{
    $doc = $_GET['doc'] ?? 'etiqueta';
    if (!in_array($doc, ['etiqueta', 'guia'], true)) {
        $doc = 'etiqueta';
    }
    ?>
    <section class="docs-page">
        <div class="users-hero docs-hero">
            <div>
                <span class="eyebrow">Gerador de documentos</span>
                <h2><?= $doc === 'guia' ? 'Guia Fora' : 'Etiqueta de Caixa' ?></h2>
                <p>Preencha os campos abaixo e gere o PDF no padrão DIARQ/MDS.</p>
            </div>
            <div class="doc-switch">
                <a class="<?= $doc === 'etiqueta' ? 'active' : '' ?>" href="/?page=documentos&doc=etiqueta">Etiqueta de Caixa</a>
                <a class="<?= $doc === 'guia' ? 'active' : '' ?>" href="/?page=documentos&doc=guia">Guia Fora</a>
            </div>
        </div>

        <?php if ($doc === 'etiqueta'): ?>
            <section class="user-card doc-card">
                <div class="section-heading">
                    <div>
                        <span class="eyebrow">Etiqueta</span>
                        <h3>Dados da caixa</h3>
                    </div>
                    <span class="section-chip">PDF</span>
                </div>
                <form method="post" class="doc-form">
                    <input type="hidden" name="action" value="pdf_etiqueta">
                    <label>Unidade <input name="unidade" value="DIARQ / MDS"></label>
                    <label>Caixa <input name="caixa" placeholder="Número da caixa" required></label>
                    <label>Data-limite <input name="data_limite" value="<?= h(date('Y')) ?>"></label>
                    <label>Localização <input name="localizacao" placeholder="Bloco / estante / prateleira"></label>
                    <label class="span-2">Assunto <textarea name="assunto" rows="5" placeholder="Descreva o assunto da caixa"></textarea></label>
                    <div class="form-actions span-2">
                        <button class="primary user-submit" type="submit">Gerar Etiqueta PDF</button>
                    </div>
                </form>
            </section>
        <?php else: ?>
            <section class="user-card doc-card">
                <div class="section-heading">
                    <div>
                        <span class="eyebrow">Guia fora</span>
                        <h3>Dados de retirada</h3>
                    </div>
                    <span class="section-chip">PDF</span>
                </div>
                <form method="post" class="doc-form">
                    <input type="hidden" name="action" value="pdf_guia">
                    <label>NUP <input name="nup" value="00000.00000/0000-00"></label>
                    <label>VOL. <input name="vol" value="0001"></label>
                    <label>Interessado (Topo) <input name="interessado_topo"></label>
                    <label>Caixa <input name="caixa"></label>
                    <label>Localização <input name="localizacao"></label>
                    <label>Destino <input name="destino"></label>
                    <label>Interessado (Corpo) <input name="interessado_corpo"></label>
                    <label>Processo SEI <input name="processo_sei"></label>
                    <label>Solicitante <input name="solicitante"></label>
                    <label>Data <input name="data" value="<?= h(date('d/m/Y')) ?>"></label>
                    <label>Respons&aacute;vel DIARQ <input name="responsavel" value="<?= h($_SESSION['user']['nome'] ?? '') ?>"></label>
                    <label class="span-2">Endereço <textarea name="endereco" rows="3"></textarea></label>
                    <div class="form-actions span-2">
                        <button class="primary user-submit" type="submit">Gerar Guia Fora PDF</button>
                    </div>
                </form>
            </section>
        <?php endif; ?>
    </section>
    <?php
}

function render_indicadores(): void
{
    $fields = indicador_field_labels();
    $groups = [
        'Desarquivamento' => ['desarq_sei', 'caixas_cons', 'retorno_desarq', 'receb_guia'],
        'Classificação e eliminação' => ['cx_sep_class', 'proc_class', 'cx_sep_eliminacao', 'cx_listadas_eliminacao', 'proc_listados_eliminacao'],
        'Inventário e controle' => ['cx_inventariadas', 'proc_inventariados', 'docs_admin_produzidos', 'orientacao_tecnica'],
        'Movimentação física' => ['cx_remanejadas', 'cx_conferidas', 'cx_substituidas', 'etiquetas_geradas'],
    ];
    ?>
    <section class="indicador-entry-page">
        <form method="post" class="indicador-entry" data-indicador-form>
            <input type="hidden" name="action" value="save_indicadores">
            <div class="indicador-entry-hero">
                <div>
                    <span class="eyebrow">Indicadores DIARQ</span>
                    <h2>Registro diário de produtividade</h2>
                    <p>Preencha os movimentos do dia. O que for salvo aqui entra automaticamente no Relatório Indicadores.</p>
                </div>
                <div class="indicador-entry-score">
                    <span>Total do dia</span>
                    <strong data-indicador-total>0</strong>
                    <small data-indicador-filled>0 indicadores preenchidos</small>
                </div>
            </div>

            <div class="indicador-entry-toolbar">
                <label class="indicador-date-card">
                    <span>Data da atividade</span>
                    <input type="date" name="data" value="<?= h(date('Y-m-d')) ?>">
                </label>
                <div class="indicador-user-card">
                    <span>Colaborador</span>
                    <strong><?= h($_SESSION['user']['nome'] ?? 'Colaborador') ?></strong>
                </div>
                <a class="button" href="/?page=rel_indicadores"><?= app_icon('dashboard') ?>Consultar relatório</a>
            </div>

            <div class="indicador-group-grid">
                <?php foreach ($groups as $group => $keys): ?>
                    <section class="indicador-group-card">
                        <div class="indicador-group-head">
                            <div>
                                <span class="eyebrow"><?= h($group) ?></span>
                                <h3><?= h((string) count($keys)) ?> campos</h3>
                            </div>
                            <strong data-indicador-group-total>0</strong>
                        </div>
                        <div class="indicador-fields">
                            <?php foreach ($keys as $name): ?>
                                <label class="indicador-field">
                                    <span><?= h($fields[$name]) ?></span>
                                    <input type="number" min="0" step="1" inputmode="numeric" name="<?= h($name) ?>" value="0" data-indicador-input>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>

            <section class="indicador-notes">
                <label>
                    <span>Outra atividade. Qual?</span>
                    <textarea name="outra_atv" rows="4" placeholder="Ex.: reunião, apoio em despacho, separação de caixas, atendimento técnico..."></textarea>
                </label>
                <label>
                    <span>Observação</span>
                    <textarea name="observacao" rows="4" placeholder="Detalhe algo importante para o relatório, pendências ou justificativas do dia."></textarea>
                </label>
            </section>

            <div class="indicador-submit-bar">
                <button class="button" type="button" data-indicador-clear>Zerar campos</button>
                <button class="primary" type="submit"><?= app_icon('send') ?>Salvar Registro Diario</button>
            </div>
        </form>
    </section>
    <?php
}

function render_dashboard(): void
{
    $totals = acervo_totals();
    $pdo = db();
    $fmt = fn ($value) => number_format((int) $value, 0, ',', '.');
    $pct = fn ($value, $total) => $total > 0 ? round(((int) $value / (int) $total) * 100, 1) : 0;
    $bar = fn ($value, $max) => $max > 0 ? max(4, min(100, round(((int) $value / (int) $max) * 100, 1))) : 0;

    $quality = $pdo->query("
        SELECT
            SUM(CASE WHEN TRIM(COALESCE(TEMPORALIDADE, '')) = '' OR TEMPORALIDADE = '---' OR LOWER(TEMPORALIDADE) = 'nan' THEN 1 ELSE 0 END) AS sem_temp,
            SUM(CASE WHEN TRIM(COALESCE(CAIXA, '')) = '' OR CAIXA = '---' THEN 1 ELSE 0 END) AS sem_caixa,
            SUM(CASE WHEN TRIM(COALESCE(LOCALIZACAO, '')) = '' OR LOCALIZACAO = '---' THEN 1 ELSE 0 END) AS sem_localizacao,
            SUM(CASE WHEN TRIM(COALESCE(INTERESSADO, '')) = '' OR INTERESSADO = '---' THEN 1 ELSE 0 END) AS sem_interessado,
            SUM(CASE WHEN TRIM(COALESCE(ASSUNTO, '')) = '' OR ASSUNTO = '---' THEN 1 ELSE 0 END) AS sem_assunto
        FROM acervo
    ")->fetch();
    $loan = $pdo->query("
        SELECT
            SUM(CASE WHEN UPPER(COALESCE(STATUS_EMPRESTIMO, '')) = 'EMPRESTADO' THEN 1 ELSE 0 END) AS emprestados,
            SUM(CASE WHEN TRIM(COALESCE(STATUS_EMPRESTIMO, '')) = '' OR STATUS_EMPRESTIMO = '---' THEN 1 ELSE 0 END) AS disponiveis
        FROM acervo
    ")->fetch();
    $manualCount = (int) $pdo->query("SELECT COUNT(*) FROM acervo WHERE FONTE_ARQUIVO = 'cadastro_manual'")->fetchColumn();
    $importCount = max(0, (int) $totals['itens'] - $manualCount);
    $topLocations = $pdo->query("
        SELECT LOCALIZACAO, COUNT(DISTINCT CAIXA) AS caixas, COUNT(*) AS itens
        FROM acervo
        WHERE TRIM(COALESCE(LOCALIZACAO, '')) <> '' AND LOCALIZACAO <> '---'
        GROUP BY LOCALIZACAO
        ORDER BY caixas DESC, itens DESC
        LIMIT 8
    ")->fetchAll();
    $topUnits = $pdo->query("
        SELECT UNIDADE, COUNT(*) AS itens, COUNT(DISTINCT CAIXA) AS caixas
        FROM acervo
        WHERE TRIM(COALESCE(UNIDADE, '')) <> '' AND UNIDADE <> '---'
        GROUP BY UNIDADE
        ORDER BY itens DESC
        LIMIT 8
    ")->fetchAll();
    $topSources = $pdo->query("
        SELECT COALESCE(NULLIF(TRIM(FONTE_ARQUIVO), ''), 'Sem origem') AS fonte, COUNT(*) AS itens
        FROM acervo
        GROUP BY COALESCE(NULLIF(TRIM(FONTE_ARQUIVO), ''), 'Sem origem')
        ORDER BY itens DESC
        LIMIT 6
    ")->fetchAll();
    $recent = $pdo->query("
        SELECT CAIXA, PROCESSO, ASSUNTO, LOCALIZACAO, ALTERADO_POR, ULTIMA_ALTERACAO
        FROM acervo
        WHERE TRIM(COALESCE(ULTIMA_ALTERACAO, '')) <> ''
        ORDER BY ULTIMA_ALTERACAO DESC
        LIMIT 6
    ")->fetchAll();
    $seiRanking = $pdo->query("
        SELECT usuario_nome, usuario_login, COUNT(*) AS total, MAX(criado_em) AS ultimo
        FROM sei_atendimentos
        WHERE status = 'atendido'
        GROUP BY usuario_login, usuario_nome
        ORDER BY total DESC, usuario_nome COLLATE NOCASE
        LIMIT 8
    ")->fetchAll();
    $maxLocation = max(array_map(fn ($row) => (int) $row['caixas'], $topLocations ?: [['caixas' => 0]]));
    $maxUnit = max(array_map(fn ($row) => (int) $row['itens'], $topUnits ?: [['itens' => 0]]));
    $maxSource = max(array_map(fn ($row) => (int) $row['itens'], $topSources ?: [['itens' => 0]]));
    $maxSei = max(array_map(fn ($row) => (int) $row['total'], $seiRanking ?: [['total' => 0]]));
    $qualityItems = [
        ['label' => 'Sem temporalidade', 'value' => (int) ($quality['sem_temp'] ?? 0), 'href' => '/?page=rel_temporalidade'],
        ['label' => 'Sem caixa', 'value' => (int) ($quality['sem_caixa'] ?? 0), 'href' => '/?page=busca&scope=geral&q=---'],
        ['label' => 'Sem localização', 'value' => (int) ($quality['sem_localizacao'] ?? 0), 'href' => '/?page=busca&scope=caixas&q=---'],
        ['label' => 'Sem interessado', 'value' => (int) ($quality['sem_interessado'] ?? 0), 'href' => '/?page=busca&scope=rh&q=---'],
        ['label' => 'Sem assunto', 'value' => (int) ($quality['sem_assunto'] ?? 0), 'href' => '/?page=busca&scope=geral&q=---'],
    ];
    ?>
    <section class="dashboard-page">
        <div class="dashboard-hero">
            <div>
                <span class="eyebrow">Painel operacional</span>
                <h2>Dashboard DIARQ</h2>
                <p>Visão rápida do acervo, qualidade dos cadastros, movimentações e pontos que precisam de atenção.</p>
            </div>
            <div class="dashboard-actions">
                <a class="button" href="/?export=acervo"><?= app_icon('download') ?>Baixar acervo</a>
                <a class="button" href="/?page=planilha">Gestão de cadastros</a>
                <a class="button primary" href="/?page=assistente_openai">Perguntar ao assistente</a>
            </div>
        </div>

        <div class="dashboard-kpis">
            <article class="kpi-card accent-blue"><span>Itens no acervo</span><strong><?= h($fmt($totals['itens'])) ?></strong><small><?= h($fmt($totals['processos'])) ?> processos identificados</small></article>
            <article class="kpi-card accent-cyan"><span>Caixas distintas</span><strong><?= h($fmt($totals['caixas'])) ?></strong><small><?= h($fmt($totals['pastas_funcionais'])) ?> pastas funcionais</small></article>
            <article class="kpi-card accent-green"><span>Disponíveis</span><strong><?= h($fmt($loan['disponiveis'] ?? 0)) ?></strong><small><?= h((string) $pct($loan['disponiveis'] ?? 0, $totals['itens'])) ?>% do acervo</small></article>
            <article class="kpi-card accent-red"><span>Emprestados</span><strong><?= h($fmt($loan['emprestados'] ?? 0)) ?></strong><small>Itens com saída registrada</small></article>
        </div>

        <div class="dashboard-grid">
            <section class="dashboard-card quality-card">
                <div class="dashboard-card-head">
                    <div><span class="eyebrow">Qualidade dos dados</span><h3>Pendências de cadastro</h3></div>
                    <a class="mini-link" href="/?page=rel_temporalidade">Ver temporalidade</a>
                </div>
                <div class="quality-list">
                    <?php foreach ($qualityItems as $item): ?>
                        <?php $percentage = $pct($item['value'], $totals['itens']); ?>
                        <a class="quality-row" href="<?= h($item['href']) ?>">
                            <span><?= h($item['label']) ?></span>
                            <strong><?= h($fmt($item['value'])) ?></strong>
                            <em><?= h((string) $percentage) ?>%</em>
                            <i style="--w: <?= h((string) max(2, min(100, $percentage))) ?>%"></i>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="dashboard-card">
                <div class="dashboard-card-head">
                    <div><span class="eyebrow">Origem</span><h3>Composição do acervo</h3></div>
                </div>
                <div class="donut-wrap">
                    <?php $manualPct = $pct($manualCount, $totals['itens']); ?>
                    <div class="donut" style="--p: <?= h((string) $manualPct) ?>%"><strong><?= h((string) $manualPct) ?>%</strong><span>manual</span></div>
                    <div class="source-split">
                        <div><span>Importados</span><strong><?= h($fmt($importCount)) ?></strong></div>
                        <div><span>Manuais</span><strong><?= h($fmt($manualCount)) ?></strong></div>
                        <div><span>Usuários</span><strong><?= h($fmt($totals['usuarios'])) ?></strong></div>
                    </div>
                </div>
            </section>

            <section class="dashboard-card sei-rank-card">
                <div class="dashboard-card-head">
                    <div><span class="eyebrow">Atendimentos SEI</span><h3>Ranking de demandas</h3></div>
                    <a class="mini-link" href="/?page=rel_demanda_sei">Ver painel</a>
                </div>
                <?php if (!$seiRanking): ?>
                    <div class="empty-state">Nenhum atendimento SEI registrado ainda.</div>
                <?php else: ?>
                    <div class="rank-list compact">
                        <?php foreach ($seiRanking as $row): ?>
                            <div class="rank-row">
                                <span><?= h((string) ($row['usuario_nome'] ?: $row['usuario_login'])) ?></span>
                                <strong><?= h($fmt($row['total'])) ?></strong>
                                <i style="--w: <?= h((string) $bar($row['total'], $maxSei)) ?>%"></i>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="dashboard-card wide">
                <div class="dashboard-card-head">
                    <div><span class="eyebrow">Localização</span><h3>Blocos e endereços com mais caixas</h3></div>
                </div>
                <div class="rank-list">
                    <?php foreach ($topLocations as $row): ?>
                        <div class="rank-row">
                            <span><?= h($row['LOCALIZACAO']) ?></span>
                            <strong><?= h($fmt($row['caixas'])) ?> caixas</strong>
                            <i style="--w: <?= h((string) $bar($row['caixas'], $maxLocation)) ?>%"></i>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="dashboard-card">
                <div class="dashboard-card-head">
                    <div><span class="eyebrow">Unidades</span><h3>Maiores conjuntos</h3></div>
                </div>
                <div class="rank-list compact">
                    <?php foreach ($topUnits as $row): ?>
                        <div class="rank-row">
                            <span><?= h($row['UNIDADE']) ?></span>
                            <strong><?= h($fmt($row['itens'])) ?></strong>
                            <i style="--w: <?= h((string) $bar($row['itens'], $maxUnit)) ?>%"></i>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="dashboard-card">
                <div class="dashboard-card-head">
                    <div><span class="eyebrow">Fontes</span><h3>Planilhas de origem</h3></div>
                </div>
                <div class="rank-list compact">
                    <?php foreach ($topSources as $row): ?>
                        <div class="rank-row">
                            <span><?= h($row['fonte']) ?></span>
                            <strong><?= h($fmt($row['itens'])) ?></strong>
                            <i style="--w: <?= h((string) $bar($row['itens'], $maxSource)) ?>%"></i>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="dashboard-card wide">
                <div class="dashboard-card-head">
                    <div><span class="eyebrow">Movimentação</span><h3>Últimas alterações</h3></div>
                </div>
                <?php if (!$recent): ?>
                    <div class="empty-state">Nenhuma alteração recente registrada.</div>
                <?php else: ?>
                    <div class="activity-list">
                        <?php foreach ($recent as $row): ?>
                            <article>
                                <strong>CX <?= h($row['CAIXA'] ?: '---') ?></strong>
                                <span><?= h($row['ASSUNTO'] ?: $row['PROCESSO'] ?: 'Sem descrição') ?></span>
                                <em><?= h($row['LOCALIZACAO'] ?: 'Sem localização') ?> · <?= h($row['ALTERADO_POR'] ?: '---') ?> · <?= h($row['ULTIMA_ALTERACAO']) ?></em>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </section>
    <?php
}

function render_diagnostico(): void
{
    $snapshot = diagnostic_snapshot();
    $validations = planilha_validation_summary();
    ?>
    <section class="dashboard-page">
        <div class="dashboard-hero">
            <div>
                <span class="eyebrow">Diagnóstico de Conexão</span>
                <h2>Status do sistema</h2>
                <p>Ambiente, banco, Supabase, jobs, eventos e validação das planilhas em um único lugar.</p>
            </div>
            <div class="dashboard-actions">
                <form method="post" data-loading-label="Sincronizando...">
                    <input type="hidden" name="action" value="sync_now">
                    <input type="hidden" name="return_page" value="diagnostico">
                    <button class="primary" type="submit">Sincronizar agora</button>
                </form>
                <a class="button" href="/?page=preview_export">Prévia de exportação</a>
            </div>
        </div>
        <div class="dashboard-kpis">
            <article class="kpi-card accent-blue"><span>Modo</span><strong><?= h($snapshot['railway'] ? 'Railway' : 'Local') ?></strong><small><?= h($snapshot['modo']) ?></small></article>
            <article class="kpi-card accent-cyan"><span>Supabase</span><strong><?= h(supabase_enabled() ? 'ON' : 'OFF') ?></strong><small><?= h($snapshot['supabase']) ?></small></article>
            <article class="kpi-card accent-green"><span>Busca FTS</span><strong><?= h($snapshot['fts'] ? 'Ativa' : 'LIKE') ?></strong><small>Índice textual inteligente</small></article>
            <article class="kpi-card accent-red"><span>Banco</span><strong><?= h(number_format((int) $snapshot['db_size'] / 1024 / 1024, 1, ',', '.')) ?> MB</strong><small><?= h($snapshot['db_path']) ?></small></article>
        </div>
        <div class="dashboard-grid">
            <section class="dashboard-card">
                <div class="dashboard-card-head"><div><span class="eyebrow">Jobs</span><h3>Importações recentes</h3></div></div>
                <div class="activity-list">
                    <?php foreach ($snapshot['jobs'] as $job): ?><article><strong><?= h($job['status']) ?></strong><span><?= h($job['tipo']) ?> · <?= h((string) $job['total_registros']) ?> registro(s)</span><em><?= h($job['mensagem']) ?> · <?= h($job['concluido_em'] ?: $job['criado_em']) ?></em></article><?php endforeach; ?>
                </div>
            </section>
            <section class="dashboard-card">
                <div class="dashboard-card-head"><div><span class="eyebrow">Eventos</span><h3>Timeline técnica</h3></div></div>
                <div class="activity-list">
                    <?php foreach ($snapshot['eventos'] as $event): ?><article><strong><?= h($event['tipo']) ?></strong><span><?= h($event['mensagem']) ?></span><em><?= h($event['criado_em']) ?></em></article><?php endforeach; ?>
                </div>
            </section>
            <section class="dashboard-card wide">
                <div class="dashboard-card-head"><div><span class="eyebrow">Planilhas</span><h3>Validação de abas</h3></div></div>
                <div class="activity-list">
                    <?php foreach ($validations as $file): ?>
                        <article>
                            <strong><?= h($file['arquivo']) ?></strong>
                            <span><?= h((string) count($file['abas'])) ?> aba(s) · <?= h($file['status']) ?></span>
                            <em><?= h(implode(', ', array_map(fn ($sheet) => $sheet['nome'] . ' (' . $sheet['formato'] . ')', array_slice($file['abas'], 0, 8)))) ?><?= count($file['abas']) > 8 ? '...' : '' ?></em>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>
    </section>
    <?php
}

function render_mapa_acervo(): void
{
    $rows = mapa_acervo_posicoes();
    $salas = mapa_acervo_por_sala($rows);
    $resumo = mapa_acervo_resumo($rows);
    $editing = mapa_acervo_posicao((int) ($_GET['edit_mapa'] ?? 0)) ?: [
        'id' => 0,
        'sala' => '',
        'tipo' => 'modulo_deslizante',
        'numero' => '',
        'numero_estante' => '',
        'prateleiras' => 10,
        'capacidade_por_prateleira' => 7,
        'caixas_ocupadas' => 0,
        'cor_setor' => '#0ea5e9',
        'observacao' => '',
    ];
    $editingOcupacao = mapa_acervo_prateleiras_ocupacao($editing);
    $editingCaixasCores = mapa_acervo_caixas_cores($editing);
    $setoresMapa = mapa_acervo_setores();
    $coresLivresMapa = mapa_acervo_cores_livres($setoresMapa);
    ?>
    <section class="mapa-acervo-page">
        <div class="dashboard-hero mapa-hero">
            <div>
                <span class="eyebrow">Mapa do Acervo</span>
                <h2>Ocupação física por sala, módulo e estante</h2>
                <p>Cadastre a capacidade real de cada estrutura e acompanhe onde ainda existe espaco para novas caixas.</p>
            </div>
            <div class="dashboard-actions">
                <a class="button export-button" href="/?page=mapa_acervo&export=mapa_vazios&format=xlsx"><?= app_icon('download') ?>Excel espaços vazios</a>
                <a class="button export-button" href="/?page=mapa_acervo&export=mapa_vazios&format=pdf"><?= app_icon('download') ?>PDF espaços vazios</a>
                <?php if ((int) ($editing['id'] ?? 0) > 0): ?><a class="button" href="/?page=mapa_acervo">Novo cadastro</a><?php endif; ?>
            </div>
        </div>

        <div class="dashboard-kpis mapa-kpis">
            <article class="kpi-card accent-blue"><span>Salas</span><strong><?= h((string) $resumo['salas']) ?></strong><small>ambientes mapeados</small></article>
            <article class="kpi-card accent-cyan"><span>Estruturas</span><strong><?= h((string) $resumo['estruturas']) ?></strong><small><?= h((string) $resumo['modulos']) ?> módulos / <?= h((string) $resumo['estantes']) ?> estantes</small></article>
            <article class="kpi-card accent-green"><span>Capacidade</span><strong><?= h(number_format((int) $resumo['capacidade'], 0, ',', '.')) ?></strong><small>caixas no total</small></article>
            <article class="kpi-card accent-red"><span>Espacos vazios</span><strong><?= h(number_format((int) $resumo['livres'], 0, ',', '.')) ?></strong><small><?= h(number_format((int) $resumo['ocupadas'], 0, ',', '.')) ?> ocupadas</small></article>
        </div>

        <?php if ($rows): ?>
            <?php render_mapa_acervo_inteligencia($rows, $resumo); ?>
        <?php endif; ?>

        <section class="mapa-editor-grid">
            <form method="post" class="panel mapa-form" autocomplete="off" data-mapa-form>
                <input type="hidden" name="action" value="save_mapa_posicao">
                <input type="hidden" name="return_page" value="mapa_acervo">
                <input type="hidden" name="id" value="<?= h((string) ($editing['id'] ?? 0)) ?>">
                <div class="section-heading">
                    <div>
                        <span class="eyebrow"><?= ((int) ($editing['id'] ?? 0) > 0) ? 'Editar posicao' : 'Nova posicao' ?></span>
                        <h3>Sala, estrutura e capacidade</h3>
                    </div>
                </div>
                <div class="mapa-form-grid">
                    <label>Sala
                        <input name="sala" value="<?= h($editing['sala'] ?? '') ?>" placeholder="Ex: 3.24" required>
                    </label>
                    <label>Tipo
                        <select name="tipo" data-mapa-tipo>
                            <option value="modulo_deslizante" <?= (($editing['tipo'] ?? '') === 'modulo_deslizante') ? 'selected' : '' ?>>Módulo deslizante</option>
                            <option value="estante" <?= (($editing['tipo'] ?? '') === 'estante') ? 'selected' : '' ?>>Estante</option>
                        </select>
                    </label>
                    <label class="mapa-number-field" data-mapa-numero-field><span data-mapa-numero-label><?= (($editing['tipo'] ?? '') === 'estante') ? 'N. de Estante' : 'N. do Módulo' ?></span>
                        <input name="numero" value="<?= h($editing['numero'] ?? '') ?>" placeholder="<?= (($editing['tipo'] ?? '') === 'estante') ? 'Ex: 01' : 'Ex: 01' ?>" required data-mapa-numero-input>
                    </label>
                    <label class="mapa-number-field" data-mapa-estante-field <?= (($editing['tipo'] ?? '') === 'estante') ? 'hidden' : '' ?>>N. da Estante
                        <input name="numero_estante" value="<?= h($editing['numero_estante'] ?? '') ?>" placeholder="Ex: E01" <?= (($editing['tipo'] ?? '') === 'estante') ? 'disabled' : '' ?>>
                    </label>
                    <label>Prateleiras
                        <input name="prateleiras" type="number" min="1" value="<?= h((string) ($editing['prateleiras'] ?? 10)) ?>" required data-mapa-prateleiras>
                    </label>
                    <label>Caixas por prateleira
                        <input name="capacidade_por_prateleira" type="number" min="1" value="<?= h((string) ($editing['capacidade_por_prateleira'] ?? 7)) ?>" required data-mapa-capacidade>
                    </label>
                    <label>Caixas ocupadas
                        <input name="caixas_ocupadas" type="number" min="0" value="<?= h((string) ($editing['caixas_ocupadas'] ?? 0)) ?>" required data-mapa-total readonly>
                    </label>
                    <label class="mapa-color-field mapa-color-field-right">Cor do setor
                        <span>
                            <input name="cor_setor" type="color" value="<?= h((string) ($editing['cor_setor'] ?? '#0ea5e9')) ?>">
                            <b><?= h((string) ($editing['cor_setor'] ?? '#0ea5e9')) ?></b>
                        </span>
                    </label>
                    <div class="wide mapa-shelf-editor" data-mapa-shelf-editor data-values="<?= h(json_encode($editingOcupacao, JSON_UNESCAPED_UNICODE)) ?>" data-colors="<?= h(json_encode($editingCaixasCores, JSON_UNESCAPED_UNICODE)) ?>">
                        <div>
                            <span>Ocupação por prateleira</span>
                            <small>Informe a quantidade e clique em cada quadradinho para mudar a cor daquela caixa.</small>
                        </div>
                        <div class="mapa-shelf-grid" data-mapa-shelf-grid></div>
                    </div>
                    <label class="wide">Observação
                        <input name="observacao" value="<?= h($editing['observacao'] ?? '') ?>" placeholder="Ex: corredor, restrição, caixas avulsas...">
                    </label>
                </div>
                <div class="result-actions">
                    <button class="primary" type="submit">Salvar no mapa</button>
                    <?php if ((int) ($editing['id'] ?? 0) > 0): ?><a class="button" href="/?page=mapa_acervo">Cancelar</a><?php endif; ?>
                </div>
            </form>

            <div class="mapa-side-stack">
                <section class="panel mapa-help">
                    <span class="eyebrow">Como usar</span>
                    <h3>Exemplo do seu caso</h3>
                    <p>Sala <strong>3.24</strong>, módulo <strong>1</strong>, com <strong>10</strong> prateleiras e <strong>7</strong> caixas por P gera <strong>70 caixas</strong>.</p>
                    <p>Depois informe a ocupação por prateleira. O mapa calcula os espaços vazios.</p>
                </section>

                <section class="panel mapa-setores-card">
                    <span class="eyebrow">Setores</span>
                    <h3>Paleta do acervo</h3>
                    <form method="post" class="mapa-setor-form" data-mapa-setor-form>
                        <input type="hidden" name="action" value="save_mapa_setor">
                        <input type="hidden" name="return_page" value="mapa_acervo">
                        <label>Setor
                            <input name="nome" placeholder="Ex: Financeiro" required>
                        </label>
                        <label>Cor
                            <span>
                                <input name="cor" type="color" value="<?= h($coresLivresMapa[0] ?? '#0ea5e9') ?>" data-mapa-setor-color>
                                <b data-mapa-setor-color-text><?= h(strtoupper($coresLivresMapa[0] ?? '#0ea5e9')) ?></b>
                            </span>
                        </label>
                        <?php if ($coresLivresMapa): ?>
                            <div class="mapa-free-colors" aria-label="Cores livres">
                                <?php foreach ($coresLivresMapa as $corLivre): ?>
                                    <button type="button" style="--sector-free: <?= h($corLivre) ?>" data-mapa-free-color="<?= h($corLivre) ?>" title="<?= h(strtoupper($corLivre)) ?>"></button>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="mapa-setor-note">Todas as cores sugeridas ja foram cadastradas. Delete um setor para liberar a cor.</p>
                        <?php endif; ?>
                        <button class="primary" type="submit">Cadastrar setor</button>
                    </form>

                    <?php if ($setoresMapa): ?>
                        <div class="mapa-setores-list">
                            <?php foreach ($setoresMapa as $setor): ?>
                                <form method="post" class="mapa-setor-row">
                                    <input type="hidden" name="action" value="delete_mapa_setor">
                                    <input type="hidden" name="return_page" value="mapa_acervo">
                                    <input type="hidden" name="id" value="<?= h((string) ($setor['id'] ?? 0)) ?>">
                                    <button type="button" class="mapa-setor-use" style="--sector-row: <?= h((string) $setor['cor']) ?>" data-mapa-use-sector-color="<?= h((string) $setor['cor']) ?>"></button>
                                    <strong><?= h((string) $setor['nome']) ?></strong>
                                    <span><?= h(strtoupper((string) $setor['cor'])) ?></span>
                                    <button type="submit">Excluir</button>
                                </form>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
        </section>

        <?php if (!$rows): ?>
            <div class="empty-state">Nenhuma estante ou módulo cadastrado ainda. Preencha o formulário acima para criar o primeiro desenho do mapa.</div>
        <?php else: ?>
            <div class="mapa-salas">
                <?php foreach ($salas as $sala => $items): ?>
                    <?php
                    $capacidadeSala = array_sum(array_map('mapa_acervo_capacidade_total', $items));
                    $ocupadasSala = array_sum(array_map(fn ($item) => (int) $item['caixas_ocupadas'], $items));
                    $livresSala = max(0, $capacidadeSala - $ocupadasSala);
                    $modulosSala = mapa_acervo_modulos_resumo($items);
                    ?>
                    <section class="mapa-sala">
                        <div class="mapa-sala-head">
                            <div>
                                <span class="eyebrow">Sala</span>
                                <button class="mapa-sala-toggle" type="button" data-mapa-sala-toggle aria-expanded="false"><?= h($sala) ?></button>
                            </div>
                            <strong><?= h((string) $livresSala) ?> espaços vazios</strong>
                            <span><?= h((string) $ocupadasSala) ?>/<?= h((string) $capacidadeSala) ?> caixas</span>
                        </div>
                        <?php render_mapa_planta_sala((string) $sala, $items); ?>
                        <div class="mapa-modulo-menu" data-mapa-modulo-menu hidden>
                            <?php foreach ($modulosSala as $modulo): ?>
                                <button type="button" data-mapa-modulo-button="<?= h($modulo['key']) ?>">
                                    <span><?= h($modulo['tipo']) ?></span>
                                    <strong><?= h($modulo['label']) ?></strong>
                                    <em><?= h((string) $modulo['total']) ?> estrutura(s)</em>
                                </button>
                            <?php endforeach; ?>
                        </div>
                        <div class="mapa-estrutura-grid" data-mapa-sala-details hidden>
                            <?php foreach ($modulosSala as $modulo): render_mapa_modulo_grupo($modulo); endforeach; ?>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
    <?php
}

function render_mapa_acervo_inteligencia(array $rows, array $resumo): void
{
    $best = null;
    $critical = [];

    foreach ($rows as $row) {
        $total = mapa_acervo_capacidade_total($row);
        $ocupadas = max(0, (int) ($row['caixas_ocupadas'] ?? 0));
        $livres = max(0, $total - $ocupadas);
        $percent = $total > 0 ? (int) round(($ocupadas / $total) * 100) : 0;

        if ($livres > 0 && (!$best || $livres > $best['livres'])) {
            $best = [
                'sala' => (string) ($row['sala'] ?? ''),
                'numero' => (string) ($row['numero'] ?? ''),
                'tipo' => mapa_acervo_tipo_label((string) ($row['tipo'] ?? '')),
                'livres' => $livres,
                'percent' => $percent,
            ];
        }

        if ($percent >= 90) {
            $critical[] = [
                'sala' => (string) ($row['sala'] ?? ''),
                'numero' => (string) ($row['numero'] ?? ''),
                'percent' => $percent,
            ];
        }
    }

    usort($critical, fn ($a, $b) => $b['percent'] <=> $a['percent']);
    $ocupacaoGeral = ((int) ($resumo['capacidade'] ?? 0)) > 0
        ? (int) round((((int) ($resumo['ocupadas'] ?? 0)) / ((int) $resumo['capacidade'])) * 100)
        : 0;
    ?>
    <section class="mapa-command-panel">
        <article>
            <span class="eyebrow">Rota sugerida</span>
            <?php if ($best): ?>
                <strong>Sala <?= h($best['sala']) ?>, <?= h($best['tipo']) ?> <?= h($best['numero']) ?></strong>
                <p>Melhor ponto para receber novas caixas agora: <?= h((string) $best['livres']) ?> espaços livres e <?= h((string) $best['percent']) ?>% de ocupação.</p>
            <?php else: ?>
                <strong>Sem vaga livre</strong>
                <p>Todas as estruturas cadastradas estão no limite. Vale planejar expansão ou remanejamento.</p>
            <?php endif; ?>
        </article>
        <article>
            <span class="eyebrow">Pressão do acervo</span>
            <strong><?= h((string) $ocupacaoGeral) ?>% ocupado</strong>
            <p><?= h(number_format((int) ($resumo['livres'] ?? 0), 0, ',', '.')) ?> espaços ainda disponíveis no mapa cadastrado.</p>
        </article>
        <article>
            <span class="eyebrow">Alerta</span>
            <?php if ($critical): ?>
                <strong><?= h((string) count($critical)) ?> estrutura(s) acima de 90%</strong>
                <p>Mais crítica: sala <?= h($critical[0]['sala']) ?>, n. <?= h($critical[0]['numero']) ?> com <?= h((string) $critical[0]['percent']) ?>%.</p>
            <?php else: ?>
                <strong>Sem gargalo crítico</strong>
                <p>Nenhuma estrutura passou de 90% de ocupação. O fluxo está respirando.</p>
            <?php endif; ?>
        </article>
    </section>
    <?php
}

function render_mapa_planta_sala(string $sala, array $items): void
{
    $modulos = mapa_acervo_modulos_resumo($items);
    ?>
    <div class="mapa-planta" aria-label="Painel visual da sala <?= h($sala) ?>">
        <div class="mapa-planta-corredor">
            <span>Entrada</span>
            <i></i>
            <span>Corredor de consulta</span>
        </div>
        <div class="mapa-planta-grid">
            <?php foreach ($modulos as $modulo): ?>
                <?php
                $total = array_sum(array_map('mapa_acervo_capacidade_total', $modulo['items']));
                $ocupadas = array_sum(array_map(fn ($item) => max(0, (int) ($item['caixas_ocupadas'] ?? 0)), $modulo['items']));
                $livres = max(0, $total - $ocupadas);
                $percent = $total > 0 ? min(100, (int) round(($ocupadas / $total) * 100)) : 0;
                $status = $percent >= 95 ? 'is-full' : ($percent >= 75 ? 'is-alert' : 'is-open');
                $corSetor = mapa_acervo_cor_setor($modulo['items'][0] ?? []);
                ?>
                <button class="mapa-planta-bloco <?= h($status) ?>"
                   type="button"
                   style="--fill: <?= h((string) $percent) ?>%; --sector: <?= h($corSetor) ?>"
                   data-mapa-planta-button="<?= h((string) $modulo['key']) ?>"
                   title="<?= h((string) $modulo['label'] . ': ' . $ocupadas . '/' . $total . ' caixas') ?>">
                    <span><?= h((string) $modulo['tipo']) ?></span>
                    <strong><?= h((string) $modulo['label']) ?></strong>
                    <em><?= h((string) $modulo['total']) ?> estrutura(s)</em>
                    <small><b></b></small>
                    <i><?= h((string) $percent) ?>% ocupado · <?= h((string) $livres) ?> livres</i>
                </button>
            <?php endforeach; ?>
        </div>
        <div class="mapa-planta-legenda">
            <span><i class="is-open"></i>Ate 74%</span>
            <span><i class="is-alert"></i>75% a 94%</span>
            <span><i class="is-full"></i>95% ou mais</span>
        </div>
    </div>
    <?php
}

function mapa_acervo_modulos_resumo(array $items): array
{
    $modulos = [];
    foreach ($items as $item) {
        $key = mapa_acervo_modulo_key($item);
        if (!isset($modulos[$key])) {
            $isModulo = (($item['tipo'] ?? '') === 'modulo_deslizante');
            $numero = trim((string) ($item['numero'] ?? ''));
            $numeroEstante = trim((string) (($item['numero_estante'] ?? '') ?: ($item['numero'] ?? '')));
            $modulos[$key] = [
                'key' => $key,
                'label' => $isModulo ? mapa_acervo_codigo_curto('M', $numero) : mapa_acervo_codigo_curto('Estante', $numeroEstante),
                'tipo' => $isModulo ? 'Módulo deslizante' : 'Estante',
                'total' => 0,
                'items' => [],
            ];
        }
        $modulos[$key]['total']++;
        $modulos[$key]['items'][] = $item;
    }

    foreach ($modulos as &$modulo) {
        usort($modulo['items'], fn ($a, $b) => mapa_acervo_ordem_estante($a) <=> mapa_acervo_ordem_estante($b));
    }
    unset($modulo);

    uasort($modulos, fn ($a, $b) => strnatcasecmp((string) $a['label'], (string) $b['label']));
    return $modulos;
}

function mapa_acervo_ordem_estante(array $item): int
{
    $value = trim((string) (($item['numero_estante'] ?? '') ?: ($item['numero'] ?? '')));
    $digits = preg_replace('/\D+/', '', $value);
    return $digits !== '' ? (int) $digits : 0;
}

function mapa_acervo_modulo_key(array $item): string
{
    if (($item['tipo'] ?? '') !== 'modulo_deslizante') {
        $numeroEstante = trim((string) (($item['numero_estante'] ?? '') ?: ($item['numero'] ?? '')));
        $numeroEstante = preg_replace('/\s+/', '', strtolower($numeroEstante));
        return 'estante-' . ($numeroEstante !== '' ? $numeroEstante : 'sem-numero');
    }

    $numero = preg_replace('/\s+/', '', strtolower(trim((string) ($item['numero'] ?? ''))));
    return 'modulo-' . ($numero !== '' ? $numero : 'sem-numero');
}

function mapa_acervo_codigo_curto(string $prefix, string $value): string
{
    $value = trim($value);
    $separator = strtolower($prefix) === 'estante' ? ' ' : '-';

    if (strtolower($prefix) === 'estante') {
        $value = preg_replace('/^estante\s*-?\s*/i', '', $value);
        $value = preg_replace('/^e\s*-?\s*/i', '', (string) $value);
        $value = trim((string) $value);
    }

    if ($value === '') {
        return $prefix . $separator . '00';
    }
    if (preg_match('/^' . preg_quote($prefix, '/') . '\s*-/i', $value)) {
        $normalized = strtoupper(str_replace(' ', '', $value));
        return $separator === ' ' ? str_replace('-', ' ', $normalized) : $normalized;
    }
    if (ctype_digit($value)) {
        return $prefix . $separator . str_pad($value, 2, '0', STR_PAD_LEFT);
    }
    return $prefix . $separator . strtoupper($value);
}

function render_mapa_modulo_grupo(array $modulo): void
{
    ?>
    <section class="mapa-modulo-grupo" data-mapa-modulo-card="<?= h((string) $modulo['key']) ?>" hidden>
        <div class="mapa-modulo-grupo-head">
            <div>
                <span><?= h((string) $modulo['tipo']) ?></span>
                <strong><?= h((string) $modulo['label']) ?></strong>
            </div>
            <em><?= h((string) $modulo['total']) ?> estante(s) agrupada(s)</em>
        </div>
        <div class="mapa-modulo-grupo-grid">
            <?php foreach ($modulo['items'] as $item): render_mapa_estrutura($item, false); endforeach; ?>
        </div>
    </section>
    <?php
}

function render_mapa_estrutura(array $item, bool $hidden = true): void
{
    $prateleiras = max(1, (int) ($item['prateleiras'] ?? 1));
    $capacidade = max(1, (int) ($item['capacidade_por_prateleira'] ?? 1));
    $ocupadas = max(0, (int) ($item['caixas_ocupadas'] ?? 0));
    $total = mapa_acervo_capacidade_total($item);
    $livres = max(0, $total - $ocupadas);
    $percent = $total > 0 ? min(100, round(($ocupadas / $total) * 100)) : 0;
    $ocupacaoPrateleiras = mapa_acervo_prateleiras_ocupacao($item);
    $caixasCores = mapa_acervo_caixas_cores($item);
    $corSetor = mapa_acervo_cor_setor($item);
    $moduloKey = mapa_acervo_modulo_key($item);
    $estanteLabel = trim((string) ($item['numero_estante'] ?? ''));
    if ($estanteLabel === '') {
        $estanteLabel = trim((string) ($item['numero'] ?? ''));
    }
    $estanteLabel = $estanteLabel !== '' ? mapa_acervo_codigo_curto('Estante', $estanteLabel) : 'Estante 00';
    ?>
    <article class="mapa-estrutura <?= h((string) ($item['tipo'] ?? '')) ?>" style="--sector: <?= h($corSetor) ?>" data-mapa-modulo-card="<?= h($moduloKey) ?>" <?= $hidden ? 'hidden' : '' ?>>
        <div class="mapa-estrutura-head">
            <div>
                <span><?= h(mapa_acervo_tipo_label((string) ($item['tipo'] ?? ''))) ?></span>
                <strong>
                    <span class="mapa-sector-picker">
                        <input type="color"
                               value="<?= h($corSetor) ?>"
                               data-mapa-sector-color
                               data-id="<?= h((string) ($item['id'] ?? 0)) ?>"
                               aria-label="Cor da estrutura <?= h($estanteLabel) ?>">
                    </span>
                    <?= h($estanteLabel) ?>
                    <button class="mapa-apply-sector-color" type="button" data-mapa-apply-sector-color hidden>Aplicar</button>
                </strong>
            </div>
            <div class="mapa-estrutura-actions">
                <a class="mini-link" href="/?page=mapa_acervo&edit_mapa=<?= h((string) ($item['id'] ?? 0)) ?>">Editar</a>
                <form method="post" onsubmit="return confirm('Excluir esta posicao do mapa?')">
                    <input type="hidden" name="action" value="delete_mapa_posicao">
                    <input type="hidden" name="return_page" value="mapa_acervo">
                    <input type="hidden" name="id" value="<?= h((string) ($item['id'] ?? 0)) ?>">
                    <button type="submit">Excluir</button>
                </form>
            </div>
        </div>
        <div class="mapa-meter" style="--w: <?= h((string) $percent) ?>%"><span></span></div>
        <div class="mapa-estrutura-stats">
            <span><?= h((string) $ocupadas) ?> ocupadas</span>
            <span><?= h((string) $livres) ?> vazias</span>
            <span><?= h((string) $total) ?> total</span>
        </div>
        <div class="mapa-prateleiras">
            <?php for ($shelf = 1; $shelf <= $prateleiras; $shelf++): ?>
                <?php
                $naPrateleira = min($capacidade, max(0, (int) ($ocupacaoPrateleiras[$shelf - 1] ?? 0)));
                ?>
                <div class="mapa-prateleira">
                    <span>P<?= h((string) $shelf) ?></span>
                    <div class="mapa-caixas" title="<?= h((string) $naPrateleira) ?> de <?= h((string) $capacidade) ?> caixas">
                        <?php if ($capacidade <= 24): ?>
                            <?php for ($box = 1; $box <= $capacidade; $box++): ?>
                                <?php
                                $boxColor = (string) ($caixasCores[$shelf - 1][$box - 1] ?? '');
                                $boxDisplayColor = $boxColor !== '' ? $boxColor : $corSetor;
                                $boxStyle = ($box <= $naPrateleira && $boxColor !== '') ? ' style="--box-color: ' . h($boxColor) . '"' : '';
                                ?>
                                <?php if ($box <= $naPrateleira): ?>
                                    <span class="mapa-caixa-color-picker filled"<?= $boxStyle ?>>
                                        <input type="color"
                                               value="<?= h($boxDisplayColor) ?>"
                                               data-mapa-card-box-color
                                               data-id="<?= h((string) ($item['id'] ?? 0)) ?>"
                                               data-shelf="<?= h((string) ($shelf - 1)) ?>"
                                               data-box="<?= h((string) ($box - 1)) ?>"
                                               aria-label="Cor da caixa <?= h((string) $box) ?> na P<?= h((string) $shelf) ?>">
                                    </span>
                                <?php else: ?>
                                    <i class="empty"></i>
                                <?php endif; ?>
                            <?php endfor; ?>
                        <?php else: ?>
                            <em style="--w: <?= h((string) round(($naPrateleira / $capacidade) * 100)) ?>%"><b></b></em>
                            <small><?= h((string) $naPrateleira) ?>/<?= h((string) $capacidade) ?></small>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endfor; ?>
        </div>
        <?php if (trim((string) ($item['observacao'] ?? '')) !== ''): ?><p class="mapa-observacao"><?= h($item['observacao']) ?></p><?php endif; ?>
    </article>
    <?php
}

function mapa_acervo_cor_setor(array $item): string
{
    $cor = trim((string) ($item['cor_setor'] ?? '#0ea5e9'));
    return preg_match('/^#[0-9a-fA-F]{6}$/', $cor) ? strtolower($cor) : '#0ea5e9';
}

function render_mapa_acervo_legacy(): void
{
    $rows = acervo_map_data(30);
    $max = max(array_map(fn ($row) => (int) $row['caixas'], $rows ?: [['caixas' => 0]]));
    ?>
    <section class="dashboard-page">
        <div class="dashboard-hero">
            <div><span class="eyebrow">Mapa do Acervo</span><h2>Distribuição por localização</h2><p>Um mapa operacional dos locais com maior concentração de caixas e itens.</p></div>
        </div>
        <div class="dashboard-grid">
            <section class="dashboard-card wide">
                <div class="rank-list">
                    <?php foreach ($rows as $row): ?>
                        <a class="rank-row" href="/?page=busca&localizacao=<?= h(urlencode($row['localizacao'])) ?>">
                            <span><?= h($row['localizacao']) ?></span>
                            <strong><?= h((string) $row['caixas']) ?> caixas · <?= h((string) $row['itens']) ?> itens</strong>
                            <i style="--w: <?= h((string) ($max ? max(4, round(((int) $row['caixas'] / $max) * 100)) : 0)) ?>%"></i>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>
    </section>
    <?php
}

function render_export_preview(): void
{
    $rows = db()->query("
        SELECT UNIDADE, CAIXA, TEMPORALIDADE, PROCESSO, ASSUNTO, INTERESSADO, LOCALIZACAO
        FROM acervo
        ORDER BY CAIXA, ASSUNTO
        LIMIT 25
    ")->fetchAll();
    $total = (int) db()->query('SELECT COUNT(*) FROM acervo')->fetchColumn();
    ?>
    <section class="panel">
        <div class="dashboard-card-head">
            <div><span class="eyebrow">Prévia de Exportação</span><h3>Relatório geral do acervo</h3></div>
            <a class="button primary" href="/?export=acervo">Baixar Excel</a>
        </div>
        <p class="muted">O arquivo completo terá <?= h(number_format($total, 0, ',', '.')) ?> registro(s). Abaixo estão os primeiros 25 para conferência.</p>
        <div class="table-wrap">
            <table class="cadastros-table">
                <thead><tr><th>Caixa</th><th>Processo</th><th>Interessado</th><th>Assunto</th><th>Localização</th><th>Temporalidade</th></tr></thead>
                <tbody>
                    <?php foreach ($rows as $row): ?><tr><td><?= h($row['CAIXA']) ?></td><td><?= h($row['PROCESSO']) ?></td><td><?= h($row['INTERESSADO']) ?></td><td><?= h($row['ASSUNTO']) ?></td><td><?= h($row['LOCALIZACAO']) ?></td><td><?= h($row['TEMPORALIDADE']) ?></td></tr><?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php
}

function render_rel_temporalidade(): void
{
    $rows = temporalidade_pendente();
    ?>
    <section class="panel">
        <div class="toolbar">
            <h2>Relatório: Itens com Temporalidade Pendente</h2>
            <a class="button" href="/?export=pendentes"><?= app_icon('download') ?>Exportar Excel</a>
        </div>
        <?php if (!$rows): ?>
            <div class="alert success">Nenhum item com temporalidade pendente encontrado.</div>
        <?php else: ?>
            <div class="alert">Foram encontrados <?= count($rows) ?> registros pendentes.</div>
            <?php foreach ($rows as $row): render_acervo_card($row); endforeach; ?>
        <?php endif; ?>
    </section>
    <?php
}

function render_rel_indicadores(): void
{
    require_once __DIR__ . '/includes/export.php';
    import_indicadores_planilhas(false);
    if ((int) db()->query('SELECT COUNT(*) FROM indicadores')->fetchColumn() === 0 && supabase_enabled()) {
        try {
            supabase_sync_indicadores();
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = 'Não foi possível sincronizar indicadores automaticamente: ' . $e->getMessage();
        }
    }
    $allRows = indicadores_report_rows();
    $rows = indicadores_report_rows($_GET);
    $colaboradores = array_values(array_unique(array_filter(array_map(fn ($row) => $row['colaborador'], $allRows))));
    sort($colaboradores);
    $periodos = array_values(array_unique(array_filter(array_map(fn ($row) => $row['data'], $allRows))));
    rsort($periodos);
    $totalAtividades = array_sum(array_map(fn ($row) => (int) $row['total'], $rows));
    $activeUsers = count(array_unique(array_filter(array_map(fn ($row) => $row['colaborador'], $rows))));
    $topIndicadores = [];
    foreach ($rows as $row) {
        foreach ($row['indicadores'] as $label => $value) {
            $topIndicadores[$label] = ($topIndicadores[$label] ?? 0) + (int) $value;
        }
    }
    arsort($topIndicadores);
    $exportQuery = $_GET + ['export' => 'indicadores'];
    ?>
    <section class="indicadores-page">
        <div class="indicadores-hero">
            <div>
                <span class="eyebrow">Painel semanal</span>
                <h2>Indicadores DIARQ</h2>
                <p>Consulta consolidada das planilhas mensais por colaborador, semana, atividade e observacoes.</p>
            </div>
            <div class="dashboard-actions">
                <a class="button export-button" href="/?<?= h(http_build_query($exportQuery)) ?>"><?= app_icon('download') ?>Exportar Excel</a>
                <form method="post">
                    <input type="hidden" name="action" value="sync_now">
                    <input type="hidden" name="return_page" value="rel_indicadores">
                    <button class="button" type="submit">Sincronizar</button>
                </form>
            </div>
        </div>

        <form class="indicadores-filters" method="get">
            <input type="hidden" name="page" value="rel_indicadores">
            <label>Colaborador
                <select name="colaborador">
                    <option value="">Todos</option>
                    <?php foreach ($colaboradores as $colaborador): ?>
                        <option value="<?= h($colaborador) ?>" <?= (($_GET['colaborador'] ?? '') === $colaborador) ? 'selected' : '' ?>><?= h($colaborador) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Semana
                <select name="periodo">
                    <option value="">Todas</option>
                    <?php foreach ($periodos as $periodo): ?>
                        <option value="<?= h($periodo) ?>" <?= (($_GET['periodo'] ?? '') === $periodo) ? 'selected' : '' ?>><?= h($periodo) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Busca
                <input name="q" value="<?= h($_GET['q'] ?? '') ?>" placeholder="atividade, observacao, indicador...">
            </label>
            <div class="cadastros-filter-actions">
                <button class="primary" type="submit">Filtrar</button>
                <a class="button" href="/?page=rel_indicadores">Limpar</a>
            </div>
        </form>

        <div class="dashboard-kpis">
            <article class="kpi-card accent-blue"><span>Registros</span><strong><?= h(number_format(count($rows), 0, ',', '.')) ?></strong><small>semanas filtradas</small></article>
            <article class="kpi-card accent-cyan"><span>Total de entregas</span><strong><?= h(number_format($totalAtividades, 0, ',', '.')) ?></strong><small>soma dos indicadores</small></article>
            <article class="kpi-card accent-green"><span>Colaboradores</span><strong><?= h((string) $activeUsers) ?></strong><small>com atividade no filtro</small></article>
            <article class="kpi-card accent-red"><span>Fontes</span><strong><?= h((string) count($periodos)) ?></strong><small>semanas disponíveis</small></article>
        </div>

        <?php if (!$rows): ?>
            <div class="empty-state">Nenhum indicador encontrado para os filtros selecionados. Clique em Sincronizar para ler as planilhas da pasta INDICADORES.</div>
        <?php else: ?>
            <section class="indicadores-grid">
                <article class="dashboard-card">
                    <div class="dashboard-card-head"><div><span class="eyebrow">Ranking</span><h3>Principais indicadores</h3></div></div>
                    <div class="rank-list compact">
                        <?php foreach (array_slice($topIndicadores, 0, 8, true) as $label => $value): ?>
                            <div class="rank-row">
                                <span><?= h($label) ?></span>
                                <strong><?= h(number_format((int) $value, 0, ',', '.')) ?></strong>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </article>

                <article class="dashboard-card wide">
                    <div class="dashboard-card-head"><div><span class="eyebrow">Consulta</span><h3>Registros semanais</h3></div></div>
                    <div class="table-wrap indicadores-table-wrap">
                        <table class="indicadores-table">
                            <thead><tr><th>Semana</th><th>Colaborador</th><th>Total</th><th>Indicadores</th><th>Atividades / Observacoes</th><th>Origem</th></tr></thead>
                            <tbody>
                            <?php foreach ($rows as $row): ?>
                                <tr>
                                    <td><?= h($row['data']) ?></td>
                                    <td><?= h($row['colaborador']) ?></td>
                                    <td><strong><?= h((string) $row['total']) ?></strong></td>
                                    <td><?= h($row['resumo']) ?></td>
                                    <td><?= h(trim($row['atividades'] . ' ' . $row['observacoes']) ?: '---') ?></td>
                                    <td><?= h($row['origem']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </article>
            </section>
        <?php endif; ?>
    </section>
    <?php
}

function render_rel_demanda_sei(): void
{
    $data = sei_report_data($_GET);
    $period = $data['period'];
    $queue = $data['queue'];
    $maxRanking = 1;
    foreach ($data['ranking'] as $row) {
        $maxRanking = max($maxRanking, (int) ($row['total'] ?? 0));
    }
    ?>
    <section class="sei-report-page">
        <div class="dashboard-hero sei-report-hero">
            <div>
                <span class="eyebrow">Relat&oacute;rio Demanda SEI</span>
                <h2>Atendimentos SEI</h2>
                <p>Rod&iacute;zio dos terceirizados, processos atendidos e produtividade do atendimento.</p>
            </div>
            <form class="sei-period-form" method="get">
                <input type="hidden" name="page" value="rel_demanda_sei">
                <label>Per&iacute;odo
                    <select name="periodo" onchange="this.form.submit()">
                        <?php foreach ($data['periods'] as $key => $info): ?>
                            <option value="<?= h($key) ?>" <?= $period === $key ? 'selected' : '' ?>><?= h($info['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </form>
        </div>

        <div class="dashboard-kpis">
            <article class="kpi-card accent-blue"><span>Atendimentos</span><strong><?= h(number_format($data['total'], 0, ',', '.')) ?></strong><small><?= h($data['periods'][$period]['label']) ?></small></article>
            <article class="kpi-card accent-cyan"><span>Hoje</span><strong><?= h((string) $data['today']) ?></strong><small>processos registrados</small></article>
            <article class="kpi-card accent-green"><span>7 dias</span><strong><?= h((string) $data['week']) ?></strong><small>ritmo recente</small></article>
            <article class="kpi-card accent-red"><span>Vezes puladas</span><strong><?= h((string) $data['skipped']) ?></strong><small>avan&ccedil;os feitos por admin</small></article>
        </div>

        <section class="sei-flow-card">
            <div>
                <span class="eyebrow">Fila atual</span>
                <h3><?= h((string) ($queue['next']['nome'] ?? 'Sem terceirizados na fila')) ?></h3>
                <p>Pr&oacute;ximo atendimento liberado pelo rod&iacute;zio alfab&eacute;tico.</p>
                <?php if (user_is_admin() && $queue['next']): ?>
                    <form method="post" class="sei-skip-form" onsubmit="return confirm('Pular a vez deste usuario na demanda SEI?')">
                        <input type="hidden" name="action" value="skip_sei_demanda">
                        <input type="hidden" name="return_page" value="rel_demanda_sei">
                        <input type="hidden" name="usuario_login" value="<?= h((string) $queue['next']['login']) ?>">
                        <button class="danger small" type="submit">Pular vez</button>
                    </form>
                <?php endif; ?>
            </div>
            <div class="sei-flow-steps">
                <?php foreach ($queue['users'] as $index => $user): ?>
                    <span class="<?= strcasecmp((string) $user['login'], (string) ($queue['next']['login'] ?? '')) === 0 ? 'active' : '' ?>">
                        <?= h((string) ($index + 1)) ?>. <?= h((string) ($user['nome'] ?: $user['login'])) ?>
                    </span>
                <?php endforeach; ?>
            </div>
        </section>

        <div class="dashboard-grid sei-report-grid">
            <article class="dashboard-card">
                <div class="dashboard-card-head"><div><span class="eyebrow">Ranking</span><h3>Atendimentos por terceirizado</h3></div></div>
                <div class="rank-list">
                    <?php if (!$data['ranking']): ?>
                        <div class="empty-state">Nenhum atendimento no per&iacute;odo.</div>
                    <?php else: ?>
                        <?php foreach ($data['ranking'] as $row): ?>
                            <?php $width = (int) round(((int) $row['total'] / $maxRanking) * 100); ?>
                            <div class="rank-row">
                                <i style="--w: <?= h((string) $width) ?>%"></i>
                                <span><?= h((string) ($row['usuario_nome'] ?: $row['usuario_login'])) ?></span>
                                <strong><?= h((string) $row['total']) ?></strong>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </article>

            <article class="dashboard-card">
                <div class="dashboard-card-head"><div><span class="eyebrow">Calend&aacute;rio</span><h3>Volume por dia</h3></div></div>
                <div class="sei-day-list">
                    <?php if (!$data['days']): ?>
                        <div class="empty-state">Sem movimenta&ccedil;&atilde;o registrada.</div>
                    <?php else: ?>
                        <?php foreach ($data['days'] as $row): ?>
                            <div>
                                <span><?= h(date('d/m/Y', strtotime((string) $row['dia']))) ?></span>
                                <strong><?= h((string) $row['total']) ?></strong>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </article>

            <article class="dashboard-card wide">
                <div class="dashboard-card-head"><div><span class="eyebrow">Hist&oacute;rico</span><h3>Processos atendidos</h3></div></div>
                <div class="table-wrap sei-report-table-wrap">
                    <table class="indicadores-table sei-report-table">
                        <thead><tr><th>Data</th><th>Atendente</th><th>Login</th><th>Processo</th></tr></thead>
                        <tbody>
                        <?php if (!$data['recent']): ?>
                            <tr><td colspan="4">Nenhum processo registrado no per&iacute;odo.</td></tr>
                        <?php else: ?>
                            <?php foreach ($data['recent'] as $row): ?>
                                <tr>
                                    <td><?= h(date('d/m/Y H:i', strtotime((string) $row['criado_em']))) ?></td>
                                    <td><?= h((string) $row['usuario_nome']) ?></td>
                                    <td><?= h((string) $row['usuario_login']) ?></td>
                                    <td><strong><?= h((string) $row['processo']) ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </article>
        </div>
    </section>
    <?php
}

function indicador_dados(string $json): array
{
    $dados = json_decode($json, true);
    return is_array($dados) ? $dados : [];
}

function indicador_total(array $dados): int
{
    if (isset($dados['indicadores']) && is_array($dados['indicadores'])) {
        return indicador_total($dados['indicadores']);
    }

    $total = 0;
    foreach ($dados as $key => $value) {
        if (in_array($key, ['data', 'outra_atv', 'observacao'], true) || !is_numeric($value)) {
            continue;
        }
        $total += (int) $value;
    }

    return $total;
}

function indicador_resumo(array $dados): string
{
    if (isset($dados['indicadores']) && is_array($dados['indicadores'])) {
        $labels = is_array($dados['labels'] ?? null) ? $dados['labels'] : [];
        $parts = [];
        foreach ($dados['indicadores'] as $key => $value) {
            if (!is_numeric($value) || (int) $value === 0) {
                continue;
            }
            $parts[] = ($labels[$key] ?? $key) . ': ' . (int) $value;
        }

        return $parts ? implode(' | ', $parts) : 'Sem valores preenchidos';
    }

    $labels = indicador_field_labels() + [
        'outra_atv' => 'Outra atividade',
        'observacao' => 'Observação',
    ];

    $parts = [];
    foreach ($labels as $key => $label) {
        $value = trim((string) ($dados[$key] ?? ''));
        if ($value === '' || $value === '0') {
            continue;
        }
        $parts[] = $label . ': ' . $value;
    }

    return $parts ? implode(' | ', $parts) : 'Sem valores preenchidos';
}

function render_assistente(): void
{
    $assistantReady = getenv('OPENAI_API_KEY') ? true : false;
    $model = getenv('OPENAI_MODEL') ?: 'gpt-4.1-mini';
    ?>
    <section class="assistant-page">
        <div class="assistant-hero">
            <div class="assistant-orb" aria-hidden="true"><?= side_icon('assistant') ?></div>
            <div>
                <span class="eyebrow">Assistente Virtual</span>
                <h2>Central Inteligente DIARQ</h2>
                <p>Use para consultar a história dos manuais, conferir acontecimentos e fazer perguntas sobre caixas, blocos e registros do acervo.</p>
            </div>
            <div class="assistant-status <?= $assistantReady ? 'online' : 'offline' ?>">
                <span></span>
                <?= $assistantReady ? 'Online' : 'Configurar API' ?>
            </div>
        </div>

        <div class="assistant-shell">
            <aside class="assistant-panel">
                <div class="assistant-panel-block">
                    <strong>Atalhos rápidos</strong>
                    <button type="button" data-chat-prompt="Quando foi a enchente da 511?">História da 511</button>
                    <button type="button" data-chat-prompt="Quantas caixas da ARCEM tem no Bloco A?">Contar caixas</button>
                    <button type="button" data-chat-prompt="Pesquise nos manuais como devo procurar documentos da SOS DOCS.">Pesquisar nos manuais</button>
                    <button type="button" data-chat-prompt="Me ajude a escrever uma resposta profissional sobre a localização de uma caixa no acervo.">Resposta profissional</button>
                </div>
                <div class="assistant-panel-block compact">
                    <span>Modelo</span>
                    <strong><?= h($model) ?></strong>
                </div>
                <button type="button" class="button assistant-clear" data-clear-chat>Limpar conversa</button>
            </aside>

            <div class="assistant-chat-card">
                <div id="chat-log" class="chat-log" aria-live="polite">
                    <div class="chat-msg assistant">
                        <strong>Assistente DIARQ</strong>
                        <span>Olá. Agora posso consultar os manuais e o banco do acervo para responder sobre acontecimentos, caixas, localizações e procedimentos.</span>
                    </div>
                </div>
                <form id="chat-form" class="chat-form">
                    <input name="message" placeholder="Digite sua mensagem..." autocomplete="off">
                    <button class="primary" type="submit">Enviar</button>
                </form>
                <?php if (!$assistantReady): ?>
                    <p class="assistant-footnote">OPENAI_API_KEY não configurada neste servidor.</p>
                <?php endif; ?>
            </div>
        </div>
    </section>
    <?php
}
