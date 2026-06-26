const chatForm = document.querySelector('#chat-form');
const chatLog = document.querySelector('#chat-log');
const loginScreen = document.querySelector('.login-screen');

function applyTheme(theme, selectedTheme = theme) {
    const target = document.body;
    target.classList.remove('theme-light', 'theme-dark');
    if (theme === 'light') target.classList.add('theme-light');
    if (theme === 'dark') target.classList.add('theme-dark');
    document.querySelectorAll('[data-theme-choice]').forEach((button) => {
        button.classList.toggle('active', button.dataset.themeChoice === selectedTheme);
    });
}

const savedTheme = localStorage.getItem('diarq-theme') || localStorage.getItem('diarq-login-theme') || 'system';
const systemDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
applyTheme(savedTheme === 'system' ? (systemDark ? 'dark' : 'light') : savedTheme, savedTheme);

function bindThemeChoices() {
    document.querySelectorAll('[data-theme-choice]').forEach((button) => {
        if (button.dataset.themeBound === '1') return;
        button.dataset.themeBound = '1';
        button.addEventListener('click', () => {
            const theme = button.dataset.themeChoice;
            localStorage.setItem('diarq-theme', theme);
            localStorage.setItem('diarq-login-theme', theme);
            if (theme === 'system') {
                const systemDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
                applyTheme(systemDark ? 'dark' : 'light', 'system');
            } else {
                applyTheme(theme, theme);
            }
        });
    });
}

bindThemeChoices();

const moreTrigger = document.querySelector('.more-trigger');
const moreMenu = document.querySelector('.more-menu');
const loginMore = document.querySelector('.login-more');
const sidebar = document.querySelector('.sidebar');
const appShell = document.querySelector('.app-shell');
const sidebarToggle = document.querySelector('.sidebar-toggle');

if (loginMore) {
    Object.assign(loginMore.style, {
        position: 'fixed',
        top: '12px',
        right: '18px',
        zIndex: '9999'
    });
}

if (sidebar && appShell && sidebarToggle) {
    const mobileQuery = window.matchMedia('(max-width: 900px)');
    const sidebarBackdrop = document.querySelector('.mobile-sidebar-backdrop');

    function setMobileSidebar(open) {
        sidebar.classList.toggle('is-mobile-open', open);
        appShell.classList.toggle('sidebar-mobile-open', open);
        document.body.classList.toggle('sidebar-mobile-open', open);
        sidebarToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        sidebarToggle.setAttribute('aria-label', open ? 'Fechar menu' : 'Abrir menu');
    }

    function applyDesktopSidebarState() {
        const collapsed = localStorage.getItem('diarq-sidebar-collapsed') === '1';
        sidebar.classList.toggle('is-collapsed', collapsed);
        appShell.classList.toggle('sidebar-collapsed', collapsed);
        sidebarToggle.setAttribute('aria-expanded', (!collapsed).toString());
        sidebarToggle.setAttribute('aria-label', collapsed ? 'Expandir sidebar' : 'Recolher sidebar');
    }

    function syncSidebarMode() {
        if (mobileQuery.matches) {
            sidebar.classList.remove('is-collapsed');
            appShell.classList.remove('sidebar-collapsed');
            setMobileSidebar(false);
        } else {
            setMobileSidebar(false);
            applyDesktopSidebarState();
        }
    }

    syncSidebarMode();

    sidebarToggle.addEventListener('click', () => {
        if (mobileQuery.matches) {
            setMobileSidebar(!sidebar.classList.contains('is-mobile-open'));
            return;
        }
        const next = !sidebar.classList.contains('is-collapsed');
        sidebar.classList.toggle('is-collapsed', next);
        appShell.classList.toggle('sidebar-collapsed', next);
        localStorage.setItem('diarq-sidebar-collapsed', next ? '1' : '0');
        sidebarToggle.setAttribute('aria-expanded', (!next).toString());
        sidebarToggle.setAttribute('aria-label', next ? 'Expandir sidebar' : 'Recolher sidebar');
    });

    sidebarBackdrop?.addEventListener('click', () => setMobileSidebar(false));

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && mobileQuery.matches) setMobileSidebar(false);
    });

    sidebar.addEventListener('click', (event) => {
        if (mobileQuery.matches && event.target.closest('a.side-button')) setMobileSidebar(false);
    });

    if (typeof mobileQuery.addEventListener === 'function') {
        mobileQuery.addEventListener('change', syncSidebarMode);
    } else {
        mobileQuery.addListener(syncSidebarMode);
    }
}

function closeMoreMenu() {
    if (!moreMenu || !moreTrigger) return;
    moreMenu.hidden = true;
    moreTrigger.setAttribute('aria-expanded', 'false');
}

function openMoreMenu() {
    if (!moreMenu || !moreTrigger) return;
    moreMenu.hidden = false;
    moreTrigger.setAttribute('aria-expanded', 'true');
}

if (moreTrigger && moreMenu) {
    moreTrigger.addEventListener('click', (event) => {
        event.stopPropagation();
        moreMenu.hidden ? openMoreMenu() : closeMoreMenu();
    });

    document.addEventListener('click', (event) => {
        if (!moreMenu.contains(event.target) && event.target !== moreTrigger) closeMoreMenu();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closeMoreMenu();
        if (moreMenu.hidden) return;
        if (event.key.toLowerCase() === 'r') window.location.reload();
        if (event.key.toLowerCase() === 'c') clearLoginCache();
    });

    document.querySelector('[data-menu-action="rerun"]')?.addEventListener('click', () => {
        window.location.reload();
    });

    document.querySelector('[data-menu-action="clear-cache"]')?.addEventListener('click', clearLoginCache);
}

document.querySelectorAll('[data-dismiss-alert]').forEach((button) => {
    button.addEventListener('click', () => {
        button.closest('.dismissible-alert')?.remove();
    });
});

document.querySelectorAll('form').forEach((form) => {
    form.addEventListener('submit', () => {
        const button = form.querySelector('button[type="submit"], button:not([type])');
        if (!button || button.dataset.loadingBound === '1') return;
        button.dataset.loadingBound = '1';
        button.dataset.originalText = button.textContent || '';
        button.classList.add('is-loading');
        button.disabled = true;
        button.textContent = form.dataset.loadingLabel || 'Processando...';
    });
});

document.querySelectorAll('.password-toggle').forEach((passwordToggle) => {
    passwordToggle.addEventListener('click', () => {
        const input = passwordToggle.closest('.password-field')?.querySelector('input');
        if (!input) return;
        const visible = input.type === 'text';
        input.type = visible ? 'password' : 'text';
        passwordToggle.setAttribute('aria-label', visible ? 'Mostrar senha' : 'Ocultar senha');
        passwordToggle.classList.toggle('is-visible', !visible);
    });
});

function openAcervoMovement(modal) {
    if (!modal) return;

    document.querySelectorAll('.movement-modal.is-open').forEach((openModal) => {
        if (openModal === modal) return;
        openModal.classList.remove('is-open');
        openModal.hidden = true;
    });

    if (modal.parentElement !== document.body) {
        document.body.appendChild(modal);
    }

    modal.hidden = false;
    modal.classList.add('is-open');
    document.body.classList.add('movement-modal-open');

    const focusTarget = modal.querySelector('input:not([type="hidden"]), textarea, button, a');
    focusTarget?.focus({ preventScroll: true });
}

function closeAcervoMovement(source) {
    const modal = source?.closest?.('.movement-modal') || document.querySelector('.movement-modal.is-open');
    if (!modal) return;

    modal.classList.remove('is-open');
    modal.hidden = true;

    if (!document.querySelector('.movement-modal.is-open')) {
        document.body.classList.remove('movement-modal-open');
    }
}

window.closeAcervoMovement = closeAcervoMovement;

document.querySelectorAll('[data-movement-modal-trigger]').forEach((trigger) => {
    trigger.addEventListener('click', (event) => {
        const id = trigger.hash ? trigger.hash.slice(1) : '';
        const modal = id ? document.getElementById(id) : null;
        if (!modal) return;

        event.preventDefault();
        openAcervoMovement(modal);
        history.replaceState(null, '', trigger.hash);
    });
});

document.addEventListener('click', (event) => {
    const closeLink = event.target.closest('.movement-modal .dialog-close, .movement-modal .movement-dialog-actions a[href^="#acervo-"]');
    if (!closeLink) return;

    event.preventDefault();
    const targetHash = closeLink.getAttribute('href');
    closeAcervoMovement(closeLink);

    if (targetHash && targetHash.startsWith('#')) {
        history.replaceState(null, '', targetHash);
        document.getElementById(targetHash.slice(1))?.scrollIntoView({ block: 'nearest' });
    }
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') closeAcervoMovement();
});

if (window.location.hash?.startsWith('#movimento-')) {
    openAcervoMovement(document.getElementById(window.location.hash.slice(1)));
}

document.querySelectorAll('.temp-code-link').forEach((button) => {
    button.addEventListener('click', () => {
        const input = button.closest('label')?.querySelector('input[name="TEMPORALIDADE"]');
        if (!input) return;
        input.value = button.dataset.tempCode || '';
        input.focus();
    });
});

document.querySelectorAll('.mapa-color-field input[type="color"]').forEach((input) => {
    const output = input.closest('.mapa-color-field')?.querySelector('b');
    const syncColor = () => {
        if (output) output.textContent = input.value.toUpperCase();
    };
    input.addEventListener('input', syncColor);
    syncColor();
});

document.querySelectorAll('[data-mapa-setor-form]').forEach((form) => {
    const input = form.querySelector('[data-mapa-setor-color]');
    const output = form.querySelector('[data-mapa-setor-color-text]');
    const sync = () => {
        if (output && input) output.textContent = input.value.toUpperCase();
    };
    input?.addEventListener('input', sync);
    form.querySelectorAll('[data-mapa-free-color]').forEach((button) => {
        button.addEventListener('click', () => {
            if (!input) return;
            input.value = button.dataset.mapaFreeColor || input.value;
            sync();
        });
    });
    sync();
});

document.querySelectorAll('[data-mapa-use-sector-color]').forEach((button) => {
    button.addEventListener('click', () => {
        const target = document.querySelector('.mapa-color-field input[name="cor_setor"]');
        if (!target) return;
        target.value = button.dataset.mapaUseSectorColor || target.value;
        target.dispatchEvent(new Event('input', { bubbles: true }));
    });
});

document.querySelectorAll('[data-mapa-form]').forEach((form) => {
    const typeInput = form.querySelector('[data-mapa-tipo]');
    const numberLabel = form.querySelector('[data-mapa-numero-label]');
    const numberInput = form.querySelector('[data-mapa-numero-input]');
    const looseShelfField = form.querySelector('[data-mapa-estante-field]');
    const looseShelfInput = looseShelfField?.querySelector('input');
    const sectorColorInput = form.querySelector('input[name="cor_setor"]');
    const shelfEditor = form.querySelector('[data-mapa-shelf-editor]');
    const shelfGrid = form.querySelector('[data-mapa-shelf-grid]');
    const shelvesInput = form.querySelector('[data-mapa-prateleiras]');
    const capacityInput = form.querySelector('[data-mapa-capacidade]');
    const totalInput = form.querySelector('[data-mapa-total]');
    if (!shelfEditor || !shelfGrid || !shelvesInput || !capacityInput || !totalInput) return;

    function syncStructureType() {
        const isLooseShelf = typeInput?.value === 'estante';
        if (numberLabel) numberLabel.textContent = isLooseShelf ? 'N. de Estante' : 'N. do Módulo';
        if (numberInput) numberInput.placeholder = isLooseShelf ? 'Ex: 01' : 'Ex: 01';
        if (looseShelfField) looseShelfField.hidden = isLooseShelf;
        if (looseShelfInput) looseShelfInput.disabled = isLooseShelf;
    }

    let values = [];
    let boxColors = [];
    try {
        values = JSON.parse(shelfEditor.dataset.values || '[]');
    } catch (_error) {
        values = [];
    }
    try {
        boxColors = JSON.parse(shelfEditor.dataset.colors || '[]');
    } catch (_error) {
        boxColors = [];
    }

    function currentShelfValues() {
        const inputs = Array.from(shelfGrid.querySelectorAll('input[name="prateleiras_ocupacao[]"]'));
        return inputs.map((input) => Math.max(0, Number.parseInt(input.value || '0', 10) || 0));
    }

    function currentBoxColors() {
        const rows = [];
        shelfGrid.querySelectorAll('[data-mapa-box-row]').forEach((row) => {
            const index = Number.parseInt(row.dataset.mapaBoxRow || '0', 10) || 0;
            rows[index] = Array.from(row.querySelectorAll('input[type="hidden"]')).map((input) => input.value || '');
        });
        return rows;
    }

    function syncTotal() {
        const capacity = Math.max(1, Number.parseInt(capacityInput.value || '1', 10) || 1);
        let total = 0;
        shelfGrid.querySelectorAll('input[name="prateleiras_ocupacao[]"]').forEach((input) => {
            let value = Math.max(0, Number.parseInt(input.value || '0', 10) || 0);
            if (value > capacity) value = capacity;
            total += value;
        });
        totalInput.value = total.toString();
    }

    function normalizeShelfInput(input) {
        const capacity = Math.max(1, Number.parseInt(capacityInput.value || '1', 10) || 1);
        let value = Math.max(0, Number.parseInt(input.value || '0', 10) || 0);
        if (value > capacity) value = capacity;
        input.value = value > 0 ? value.toString() : '';
        renderBoxColorRows();
        syncTotal();
    }

    function renderBoxColorRows() {
        const existingColors = currentBoxColors();
        if (existingColors.some((row) => row?.some((color) => color))) boxColors = existingColors;
        const defaultColor = sectorColorInput?.value || '#0ea5e9';
        shelfGrid.querySelectorAll('.mapa-shelf-field').forEach((label, index) => {
            const input = label.querySelector('input[name="prateleiras_ocupacao[]"]');
            const row = label.querySelector('[data-mapa-box-row]');
            if (!input || !row) return;
            const capacity = Math.max(1, Number.parseInt(capacityInput.value || '1', 10) || 1);
            const count = Math.min(capacity, Math.max(0, Number.parseInt(input.value || '0', 10) || 0));
            row.innerHTML = '';
            for (let box = 0; box < count; box += 1) {
                const savedColor = (boxColors[index]?.[box] || '').toLowerCase();
                const field = document.createElement('span');
                field.className = 'mapa-box-color-field';
                field.title = `Cor da caixa ${box + 1} na P${index + 1}`;
                field.innerHTML = `<input type="hidden" name="caixas_cores[${index}][${box}]" value="${savedColor}"><input type="color" value="${savedColor || defaultColor}" aria-label="Cor da caixa ${box + 1} na P${index + 1}">`;
                const hidden = field.querySelector('input[type="hidden"]');
                const picker = field.querySelector('input[type="color"]');
                picker.addEventListener('input', () => {
                    hidden.value = picker.value.toLowerCase();
                    field.classList.add('is-custom');
                });
                if (savedColor) field.classList.add('is-custom');
                row.appendChild(field);
            }
        });
    }

    function renderShelves() {
        const previous = currentShelfValues();
        if (previous.length) values = previous;
        const previousColors = currentBoxColors();
        if (previousColors.length) boxColors = previousColors;
        const shelves = Math.max(1, Number.parseInt(shelvesInput.value || '1', 10) || 1);
        const capacity = Math.max(1, Number.parseInt(capacityInput.value || '1', 10) || 1);
        shelfGrid.innerHTML = '';

        for (let index = 0; index < shelves; index += 1) {
            const label = document.createElement('label');
            label.className = 'mapa-shelf-field';
            const value = Math.min(capacity, Math.max(0, Number.parseInt(values[index] || '0', 10) || 0));
            label.innerHTML = `<span>P${index + 1}</span><input name="prateleiras_ocupacao[]" type="number" inputmode="numeric" min="0" max="${capacity}" placeholder="0" value="${value > 0 ? value : ''}" aria-label="Caixas na P${index + 1}"><div class="mapa-box-color-row" data-mapa-box-row="${index}" aria-label="Cores das caixas da P${index + 1}"></div>`;
            shelfGrid.appendChild(label);
        }

        shelfGrid.querySelectorAll('input[name="prateleiras_ocupacao[]"]').forEach((input) => {
            input.addEventListener('input', () => {
                renderBoxColorRows();
                syncTotal();
            });
            input.addEventListener('blur', () => normalizeShelfInput(input));
        });
        renderBoxColorRows();
        syncTotal();
    }

    shelvesInput.addEventListener('input', renderShelves);
    capacityInput.addEventListener('input', renderShelves);
    sectorColorInput?.addEventListener('input', () => {
        shelfGrid.querySelectorAll('.mapa-box-color-field:not(.is-custom) input[type="color"]').forEach((input) => {
            input.value = sectorColorInput.value;
        });
    });
    typeInput?.addEventListener('change', syncStructureType);
    syncStructureType();
    renderShelves();
});

document.querySelectorAll('[data-mapa-sala-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
        const sala = button.closest('.mapa-sala');
        const menu = sala?.querySelector('[data-mapa-modulo-menu]');
        const details = sala?.querySelector('[data-mapa-sala-details]');
        if (!menu || !details) return;
        const open = menu.hidden;
        menu.hidden = !open;
        details.hidden = true;
        sala.querySelectorAll('[data-mapa-modulo-button]').forEach((moduleButton) => {
            moduleButton.classList.remove('active');
        });
        sala.querySelectorAll('[data-mapa-modulo-card]').forEach((card) => {
            card.hidden = true;
        });
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
        button.classList.toggle('active', open);
    });
});

document.querySelectorAll('[data-mapa-modulo-button]').forEach((button) => {
    button.addEventListener('click', () => {
        const sala = button.closest('.mapa-sala');
        const details = sala?.querySelector('[data-mapa-sala-details]');
        const key = button.dataset.mapaModuloButton || '';
        if (!details || !key) return;
        sala.querySelectorAll('[data-mapa-modulo-button]').forEach((moduleButton) => {
            moduleButton.classList.toggle('active', moduleButton === button);
        });
        let visible = 0;
        sala.querySelectorAll('[data-mapa-modulo-card]').forEach((card) => {
            const match = card.dataset.mapaModuloCard === key;
            card.hidden = !match;
            if (match) visible += 1;
        });
        details.hidden = visible === 0;
    });
});

document.querySelectorAll('[data-mapa-planta-button]').forEach((button) => {
    button.addEventListener('click', () => {
        const sala = button.closest('.mapa-sala');
        const key = button.dataset.mapaPlantaButton || '';
        const menu = sala?.querySelector('[data-mapa-modulo-menu]');
        const salaToggle = sala?.querySelector('[data-mapa-sala-toggle]');
        const moduleButton = Array.from(sala?.querySelectorAll('[data-mapa-modulo-button]') || [])
            .find((item) => item.dataset.mapaModuloButton === key);
        if (!menu || !salaToggle || !moduleButton) return;
        menu.hidden = false;
        salaToggle.classList.add('active');
        salaToggle.setAttribute('aria-expanded', 'true');
        moduleButton.click();
    });
});

document.querySelectorAll('[data-mapa-card-box-color]').forEach((input) => {
    input.addEventListener('input', async () => {
        const picker = input.closest('.mapa-caixa-color-picker');
        const body = new FormData();
        body.append('action', 'save_mapa_caixa_cor');
        body.append('return_page', 'mapa_acervo');
        body.append('id', input.dataset.id || '');
        body.append('shelf', input.dataset.shelf || '0');
        body.append('box', input.dataset.box || '0');
        body.append('color', input.value);
        picker?.style.setProperty('--box-color', input.value);
        picker?.classList.add('is-saving');

        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body
            });
            if (!response.ok) throw new Error('Falha ao salvar cor');
            picker?.classList.remove('is-error');
        } catch (_error) {
            picker?.classList.add('is-error');
        } finally {
            picker?.classList.remove('is-saving');
        }
    });
});

document.querySelectorAll('[data-mapa-sector-color]').forEach((input) => {
    const picker = input.closest('.mapa-sector-picker');
    const applyButton = input.closest('strong')?.querySelector('[data-mapa-apply-sector-color]');

    input.addEventListener('input', () => {
        if (applyButton) applyButton.hidden = input.value === picker?.dataset.previousColor;
        picker?.classList.remove('is-error');
    });

    applyButton?.addEventListener('click', async () => {
        const card = input.closest('.mapa-estrutura');
        const color = input.value;
        const body = new FormData();
        body.append('action', 'save_mapa_setor_cor');
        body.append('return_page', 'mapa_acervo');
        body.append('id', input.dataset.id || '');
        body.append('color', color);

        picker?.classList.add('is-saving');
        applyButton.disabled = true;

        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body
            });
            if (!response.ok) throw new Error('Falha ao salvar cor');
            card?.style.setProperty('--sector', color);
            card?.querySelectorAll('.mapa-caixa-color-picker').forEach((boxPicker) => {
                boxPicker.style.removeProperty('--box-color');
                boxPicker.querySelector('input[type="color"]').value = color;
            });
            picker?.classList.remove('is-error');
            if (picker) picker.dataset.previousColor = color;
            applyButton.hidden = true;
        } catch (_error) {
            picker?.classList.add('is-error');
        } finally {
            picker?.classList.remove('is-saving');
            applyButton.disabled = false;
        }
    });
    picker?.setAttribute('data-previous-color', input.value);
});

const indicadorForm = document.querySelector('[data-indicador-form]');
if (indicadorForm) {
    const inputs = Array.from(indicadorForm.querySelectorAll('[data-indicador-input]'));
    const totalOutput = indicadorForm.querySelector('[data-indicador-total]');
    const filledOutput = indicadorForm.querySelector('[data-indicador-filled]');
    const clearButton = indicadorForm.querySelector('[data-indicador-clear]');
    const formatter = new Intl.NumberFormat('pt-BR');

    function updateIndicadorTotals() {
        let total = 0;
        let filled = 0;
        indicadorForm.querySelectorAll('.indicador-group-card').forEach((group) => {
            let groupTotal = 0;
            group.querySelectorAll('[data-indicador-input]').forEach((input) => {
                const value = Math.max(0, Number.parseInt(input.value || '0', 10) || 0);
                const field = input.closest('.indicador-field');
                field?.classList.toggle('is-filled', value > 0);
                if (value > 0) filled += 1;
                groupTotal += value;
            });
            const groupOutput = group.querySelector('[data-indicador-group-total]');
            if (groupOutput) groupOutput.textContent = formatter.format(groupTotal);
            total += groupTotal;
        });
        if (totalOutput) totalOutput.textContent = formatter.format(total);
        if (filledOutput) filledOutput.textContent = `${filled} indicador${filled === 1 ? '' : 'es'} preenchido${filled === 1 ? '' : 's'}`;
    }

    inputs.forEach((input) => {
        input.addEventListener('input', updateIndicadorTotals);
        input.addEventListener('blur', () => {
            if (input.value === '' || Number.parseInt(input.value, 10) < 0) input.value = '0';
            updateIndicadorTotals();
        });
    });

    clearButton?.addEventListener('click', () => {
        inputs.forEach((input) => {
            input.value = '0';
        });
        indicadorForm.querySelectorAll('textarea').forEach((textarea) => {
            textarea.value = '';
        });
        updateIndicadorTotals();
        inputs[0]?.focus();
    });

    updateIndicadorTotals();
}

const manualItems = document.querySelector('[data-manual-items]');
const addManualItem = document.querySelector('[data-add-manual-item]');

function renumberManualItems() {
    if (!manualItems) return;
    const items = manualItems.querySelectorAll('[data-manual-item]');
    items.forEach((item, index) => {
        const title = item.querySelector('.manual-item-title strong');
        const remove = item.querySelector('[data-remove-manual-item]');
        if (title) title.textContent = `Item #${index + 1}`;
        if (remove) remove.hidden = items.length === 1;
    });
}

if (manualItems && addManualItem) {
    addManualItem.addEventListener('click', () => {
        const first = manualItems.querySelector('[data-manual-item]');
        if (!first) return;
        const clone = first.cloneNode(true);
        clone.querySelectorAll('input').forEach((input) => {
            input.value = '';
            input.setAttribute('autocomplete', 'off');
        });
        manualItems.appendChild(clone);
        renumberManualItems();
        clone.querySelector('input')?.focus();
    });

    manualItems.addEventListener('click', (event) => {
        const button = event.target.closest('[data-remove-manual-item]');
        if (!button) return;
        const item = button.closest('[data-manual-item]');
        if (item && manualItems.querySelectorAll('[data-manual-item]').length > 1) {
            item.remove();
            renumberManualItems();
        }
    });

    renumberManualItems();
}

const bulkForm = document.querySelector('[data-bulk-form]');
if (bulkForm) {
    const selectAll = bulkForm.querySelector('[data-select-all]');
    const rowChecks = Array.from(bulkForm.querySelectorAll('[data-row-check]'));

    function refreshSelectAll() {
        if (!selectAll) return;
        const checked = rowChecks.filter((check) => check.checked).length;
        selectAll.checked = checked > 0 && checked === rowChecks.length;
        selectAll.indeterminate = checked > 0 && checked < rowChecks.length;
    }

    selectAll?.addEventListener('change', () => {
        rowChecks.forEach((check) => {
            check.checked = selectAll.checked;
        });
        refreshSelectAll();
    });

    rowChecks.forEach((check) => check.addEventListener('change', refreshSelectAll));
    refreshSelectAll();
}

function clearLoginCache() {
    sessionStorage.clear();
    const theme = localStorage.getItem('diarq-theme') || localStorage.getItem('diarq-login-theme');
    localStorage.clear();
    if (theme) {
        localStorage.setItem('diarq-theme', theme);
        localStorage.setItem('diarq-login-theme', theme);
    }
    document.cookie.split(';').forEach((cookie) => {
        const name = cookie.split('=')[0].trim();
        if (name) document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/`;
    });
    window.location.reload();
}

function addChat(role, text) {
    if (!chatLog) return;
    const div = document.createElement('div');
    div.className = `chat-msg ${role}`;
    const who = document.createElement('strong');
    who.textContent = role === 'user' ? 'Você' : 'Assistente DIARQ';
    const body = document.createElement('span');
    body.textContent = text;
    div.append(who, body);
    chatLog.appendChild(div);
    chatLog.scrollTop = chatLog.scrollHeight;
    return div;
}

if (chatForm) {
    const input = chatForm.querySelector('input[name="message"]');
    const submit = chatForm.querySelector('button[type="submit"], button:not([type])');
    const initialMessage = chatLog?.innerHTML || '';

    document.querySelectorAll('[data-chat-prompt]').forEach((button) => {
        button.addEventListener('click', () => {
            if (!input) return;
            input.value = button.dataset.chatPrompt || '';
            input.focus();
        });
    });

    document.querySelector('[data-clear-chat]')?.addEventListener('click', () => {
        if (!chatLog) return;
        chatLog.innerHTML = initialMessage;
        input?.focus();
    });

    chatForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const message = input.value.trim();
        if (!message) return;
        input.value = '';
        addChat('user', message);
        input.disabled = true;
        if (submit) submit.disabled = true;
        const typing = addChat('assistant typing', 'Pensando...');
        try {
            const response = await fetch('/api/assistant.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({message})
            });
            const data = await response.json();
            typing?.remove();
            addChat('assistant', data.reply || 'Sem resposta.');
        } catch (error) {
            typing?.remove();
            addChat('assistant', 'Não consegui consultar o assistente agora. Verifique a conexão e tente novamente.');
        } finally {
            input.disabled = false;
            if (submit) submit.disabled = false;
            input.focus();
        }
    });
}
