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
render_header();

match ($page) {
    'cad_caixa' => render_cad_caixa(),
    'cad_processo' => render_cad_processo(),
    'planilha' => render_planilha(),
    'gestao_usuarios' => render_usuarios(),
    'documentos' => render_documentos(),
    'indicadores_semanal' => render_indicadores(),
    'dashboard' => render_dashboard(),
    'rel_temporalidade' => render_rel_temporalidade(),
    'rel_indicadores' => render_rel_indicadores(),
    'assistente_openai' => render_assistente(),
    default => render_busca(),
};

render_footer();

function render_busca(): void
{
    $tabs = [
        'geral' => ['label' => 'Geral', 'icon' => 'search'],
        'rh' => ['label' => 'RH / Prontuarios', 'icon' => 'rh'],
        'caixas' => ['label' => 'Caixas', 'icon' => 'boxes'],
        'processos' => ['label' => 'Processos', 'icon' => 'processos'],
    ];
    $scope = $_GET['scope'] ?? 'geral';
    $placeholders = [
        'geral' => 'Pesquisar em todo o acervo...',
        'rh' => 'Pesquisar por interessado, prontuario, assunto ou observacao...',
        'caixas' => 'Pesquisar por numero da caixa ou localizacao...',
        'processos' => 'Pesquisar por numero do processo...',
    ];
    $term = trim($_GET['q'] ?? '');
    $results = $term !== '' ? search_acervo($term, $scope) : [];
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
            <?php if ($term !== ''): ?><a class="icon-button" href="/?page=busca&scope=<?= h($scope) ?>">Nova</a><?php endif; ?>
        </form>
    </section>

    <?php if ($term === ''): ?>
        <div class="empty-state">Digite um termo para pesquisar no acervo.</div>
    <?php elseif (!$results): ?>
        <div class="alert">Nenhum documento encontrado para este termo em todo o acervo.</div>
    <?php else: ?>
        <div class="alert success"><?= count($results) ?> item(ns) encontrado(s). Mostrando os primeiros 100 resultados.</div>
        <?php foreach ($results as $row): render_acervo_card($row); endforeach; ?>
    <?php endif;
}

function render_acervo_card(array $row): void
{
    $status = trim((string) ($row['STATUS_EMPRESTIMO'] ?? ''));
    $displayStatus = $status === '' || $status === '---' ? 'DISPONIVEL' : $status;
    $tipoDoc = trim((string) ($row['PROCESSO'] ?? '')) !== '' && ($row['PROCESSO'] ?? '') !== '---' ? 'PROCESSOS' : 'DOCUMENTOS';
    $tempSuggestion = temporalidade_suggestion($row);
    ?>
    <details class="result-card">
        <summary class="result-summary">
            <span class="result-chevron">›</span>
            <span class="result-box-icon" aria-hidden="true">📦</span>
            <strong class="result-box">CX: <?= h($row['CAIXA'] ?? '---') ?></strong>
            <span class="result-doc-icon" aria-hidden="true">📄</span>
            <span class="result-subject"><?= h($row['ASSUNTO'] ?? '---') ?></span>
            <span class="result-divider">|</span>
            <em class="result-status"><?= h($displayStatus) ?></em>
        </summary>
        <form method="post" class="result-detail-form">
            <input type="hidden" name="action" value="save_acervo">
            <input type="hidden" name="return_page" value="<?= h(current_page()) ?>">
            <input type="hidden" name="ID_UNICO" value="<?= h($row['ID_UNICO'] ?? '') ?>">
            <input type="hidden" name="STATUS_EMPRESTIMO" value="<?= h($status) ?>">
            <input type="hidden" name="OBSERVACAO" value="<?= h($row['OBSERVACAO'] ?? '---') ?>">

            <span class="result-toggle-switch" aria-hidden="true"></span>

            <div class="result-field-grid">
                <label>Unidades <input name="UNIDADE" value="<?= h($row['UNIDADE'] ?? '---') ?>"></label>
                <label>N&ordm; Processos <input name="PROCESSO" value="<?= h($row['PROCESSO'] ?? '---') ?>"></label>
                <label>N&ordm; Caixas <input name="CAIXA" value="<?= h($row['CAIXA'] ?? '---') ?>"></label>
                <label>Localiza&ccedil;&atilde;o <input name="LOCALIZACAO" value="<?= h($row['LOCALIZACAO'] ?? '---') ?>"></label>
                <label>Volumes <input name="VOLUMES" value="<?= h($row['VOLUMES'] ?? '---') ?>"></label>
                <label>Tipo de Doc <input value="<?= h($tipoDoc) ?>" readonly></label>
                <label>Interessados <input name="INTERESSADO" value="<?= h($row['INTERESSADO'] ?? '---') ?>"></label>
                <label>Assuntos <input name="ASSUNTO" value="<?= h($row['ASSUNTO'] ?? '---') ?>"></label>
                <label>Respons&aacute;vel <input value="<?= h($row['RESPONSAVEL'] ?? '---') ?>" readonly></label>
                <label>N&ordm; Cod Temp
                    <input name="TEMPORALIDADE" value="<?= h($row['TEMPORALIDADE'] ?? '---') ?>">
                    <?php if ($tempSuggestion): ?>
                        <span class="temp-hint">
                            Sugest&atilde;o:
                            <button type="button" class="temp-code-link" data-temp-code="<?= h($tempSuggestion['code'] ?? '') ?>">
                                <?= h($tempSuggestion['code'] ?? '') ?>
                            </button>
                            <?= h($tempSuggestion['title'] ?? '') ?>
                            <small>p&aacute;g. <?= h((string) ($tempSuggestion['page'] ?? '')) ?></small>
                        </span>
                    <?php else: ?>
                        <span class="temp-hint temp-hint-empty">Sem sugest&atilde;o na tabela do MDS</span>
                    <?php endif; ?>
                </label>
                <label>Data <input name="DATA" value="<?= h($row['DATA'] ?? '---') ?>"></label>
                <label>Data Limite <input name="DATA_LIMITE" value="<?= h($row['DATA_LIMITE'] ?? '---') ?>"></label>
            </div>

            <div class="result-footer">
                <label class="withdrawn-field">Quem Retirou
                    <input name="QUEM_RETIROU" value="<?= h($row['QUEM_RETIROU'] ?? '---') ?>">
                </label>
                <div class="result-actions">
                    <button type="submit" name="STATUS_EMPRESTIMO" value="EMPRESTADO">&#128228; Sa&iacute;da</button>
                    <button type="submit" name="STATUS_EMPRESTIMO" value="---">&#128229; Retorno</button>
                    <button class="save-result" type="submit">&#10003; Salvar Altera&ccedil;&otilde;es</button>
                </div>
                <span class="result-modified">Modificado por: <?= h($row['ALTERADO_POR'] ?? '---') ?> em <?= h($row['ULTIMA_ALTERACAO'] ?? '---') ?></span>
            </div>
        </form>
    </details>
    <?php
}

function label_for(string $field): string
{
    return [
        'UNIDADE' => 'Unidade',
        'ASSUNTO' => 'Assunto',
        'INTERESSADO' => 'Interessado',
        'DATA' => 'Data',
        'TEMPORALIDADE' => 'Cod. Temp',
        'CAIXA' => 'Caixa',
        'PROCESSO' => 'Processo',
        'LOCALIZACAO' => 'Localizacao',
        'OBSERVACAO' => 'Observacao',
        'VOLUMES' => 'Volumes',
        'DATA_LIMITE' => 'Data Limite',
        'STATUS_EMPRESTIMO' => 'Status Emprestimo',
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
                        <h3>Caixa e classificacao</h3>
                    </div>
                    <span class="section-chip">Manual</span>
                </div>
                <div class="manual-grid">
                    <label>Unidade <input name="UNIDADE" value="DIARQ / MDS" autocomplete="off"></label>
                    <label>Nº de Caixa * <input name="CAIXA" required autocomplete="off"></label>
                    <label>Tipo de Documento <input name="TIPO_DOCUMENTO" autocomplete="off"></label>
                    <label>Localizacao <input name="LOCALIZACAO" autocomplete="off"></label>
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
            <label>Localizacao <input name="LOCALIZACAO"></label>
            <label>Data <input name="DATA" value="<?= h(date('d/m/Y')) ?>"></label>
            <label>Cod. Temp <input name="TEMPORALIDADE"></label>
            <label>Processo <input name="PROCESSO"></label>
            <label>Volumes <input name="VOLUMES"></label>
            <label>Interessado <input name="INTERESSADO"></label>
            <label class="span-2">Assunto <textarea name="ASSUNTO" rows="3"></textarea></label>
            <label class="span-2">Observacao <textarea name="OBSERVACAO" rows="3"></textarea></label>
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
                    <label>Caixa <input name="caixa" placeholder="Numero da caixa" required></label>
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
    $fields = [
        'desarq_sei' => 'Desarquivamento de Proc./Doc. - Saida de Guia Fora (SEI)',
        'caixas_cons' => 'Caixas consultadas em pesquisa de desarquivamento',
        'retorno_desarq' => 'Retorno de Processos/Documentos Desarquivados',
        'receb_guia' => 'Recebimento de Documentos - Guia de Transferencia',
        'cx_sep_class' => 'Caixas separadas para classificacao',
        'proc_class' => 'Processos/Documentos classificados',
        'cx_enderecadas' => 'Caixas enderecadas',
        'cx_higienizadas' => 'Caixas higienizadas',
        'etiquetas_geradas' => 'Etiquetas geradas/impressas',
    ];
    ?>
    <section class="panel">
        <h2>Registro de Indicadores Diarios</h2>
        <p class="muted">Colaborador logado: <?= h($_SESSION['user']['nome'] ?? '') ?></p>
        <form method="post" class="grid-form wide">
            <input type="hidden" name="action" value="save_indicadores">
            <label>Data da Atividade <input type="date" name="data" value="<?= h(date('Y-m-d')) ?>"></label>
            <?php foreach ($fields as $name => $label): ?>
                <label><?= h($label) ?><input type="number" min="0" step="1" name="<?= h($name) ?>" value="0"></label>
            <?php endforeach; ?>
            <label class="span-2">Outra atividade. Qual?<textarea name="outra_atv" rows="3"></textarea></label>
            <div class="form-actions span-2"><button class="primary">Salvar Registro Diario</button></div>
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
    $maxLocation = max(array_map(fn ($row) => (int) $row['caixas'], $topLocations ?: [['caixas' => 0]]));
    $maxUnit = max(array_map(fn ($row) => (int) $row['itens'], $topUnits ?: [['itens' => 0]]));
    $maxSource = max(array_map(fn ($row) => (int) $row['itens'], $topSources ?: [['itens' => 0]]));
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

function render_rel_temporalidade(): void
{
    $rows = temporalidade_pendente();
    ?>
    <section class="panel">
        <div class="toolbar">
            <h2>Relatorio: Itens com Temporalidade Pendente</h2>
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
    $rows = db()->query('SELECT * FROM indicadores ORDER BY criado_em DESC LIMIT 300')->fetchAll();
    ?>
    <section class="panel">
        <h2>Exportar e Consultar Indicadores</h2>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Data</th><th>Colaborador</th><th>Dados</th><th>Criado em</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr><td><?= h($row['data']) ?></td><td><?= h($row['colaborador']) ?></td><td><?= h($row['dados_json']) ?></td><td><?= h($row['criado_em']) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <?php
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
