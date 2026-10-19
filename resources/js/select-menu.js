const selector = 'select.gc-input:not([multiple]):not([data-native-select])';
const fold = value => String(value).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('fr');

export function selectMenuItems(select) {
    return Array.from(select.options).flatMap((option, index) => {
        const group = option.parentElement?.tagName === 'OPTGROUP' ? option.parentElement : null;
        if (option.hidden || group?.hidden) return [];
        return [{ index, label: option.label, group: group?.label || '', disabled: option.disabled || Boolean(group?.disabled) }];
    });
}

export function nextSelectOption(items, current, direction) {
    const enabled = items.filter(item => !item.disabled);
    if (!enabled.length) return -1;
    const position = enabled.findIndex(item => item.index === current);
    if (direction === 'first') return enabled[0].index;
    if (direction === 'last') return enabled.at(-1).index;
    return enabled[Math.max(0, Math.min(enabled.length - 1, position < 0 ? 0 : position + direction))].index;
}

export function searchSelectOption(items, query, current) {
    const enabled = items.filter(item => !item.disabled);
    const start = enabled.findIndex(item => item.index === current);
    const ordered = [...enabled.slice(start + 1), ...enabled.slice(0, start + 1)];
    return ordered.find(item => fold(item.label).startsWith(fold(query)))?.index ?? current;
}

export function selectMenuPosition(anchor, viewport) {
    const margin = 8, gap = 5;
    const width = Math.min(Math.max(anchor.width, 240), viewport.width - margin * 2);
    const below = viewport.height - anchor.bottom - gap - margin;
    const above = anchor.top - gap - margin;
    const upwards = below < Math.min(280, above);
    return {
        width,
        left: Math.max(margin, Math.min(anchor.left, viewport.width - width - margin)),
        maxHeight: Math.max(0, Math.min(320, upwards ? above : below)),
        top: upwards ? null : anchor.bottom + gap,
        bottom: upwards ? viewport.height - anchor.top + gap : null,
    };
}

// Keep the real select visible: values, labels, validation, reset and AJAX assignments
// remain native. Only its popup is replaced, so there is no mirrored form state.
export function initSelectMenus(root = document, browser = window) {
    if (!root.body || root.body.dataset.selectMenusReady) return;
    root.body.dataset.selectMenusReady = 'true';
    let active = null, menu = null, highlighted = -1, previousAttributes = null;
    let items = [], search = '', searchAt = 0, sequence = 0, observer = null;

    const eligible = node => node?.matches?.(selector) && node.size <= 1 && !node.matches(':disabled');
    const close = () => {
        if (!active) return;
        observer?.disconnect();
        for (const [name, value] of previousAttributes) {
            if (value === null) active.removeAttribute(name);
            else active.setAttribute(name, value);
        }
        menu.remove();
        active = menu = observer = null;
        items = [];
        search = '';
    };
    const highlight = index => {
        highlighted = index;
        menu.querySelectorAll('[role="option"]').forEach(option => {
            const current = Number(option.dataset.index) === index;
            option.dataset.highlighted = String(current);
            if (current) {
                active.setAttribute('aria-activedescendant', option.id);
                option.scrollIntoView({ block: 'nearest' });
            }
        });
        if (index < 0) active.removeAttribute('aria-activedescendant');
    };
    const place = () => {
        const anchor = active.getBoundingClientRect();
        if (!anchor.width || !anchor.height || !eligible(active) || !active.isConnected) return close();
        const position = selectMenuPosition(anchor, { width: root.documentElement.clientWidth, height: browser.innerHeight });
        if (position.maxHeight < 36) return close();
        Object.assign(menu.style, {
            width: `${position.width}px`, left: `${position.left}px`, maxHeight: `${position.maxHeight}px`,
            top: position.top === null ? 'auto' : `${position.top}px`,
            bottom: position.bottom === null ? 'auto' : `${position.bottom}px`,
        });
    };
    const render = () => {
        if (!active || !eligible(active) || !active.isConnected) return close();
        items = selectMenuItems(active);
        menu.replaceChildren();
        let previousGroup = null, groupContainer = menu;
        for (const item of items) {
            if (item.group !== previousGroup) {
                groupContainer = menu;
                if (item.group) {
                    groupContainer = root.createElement('div');
                    groupContainer.setAttribute('role', 'group');
                    groupContainer.setAttribute('aria-label', item.group);
                    const title = root.createElement('div');
                    title.className = 'gc-select-menu-group';
                    title.setAttribute('aria-hidden', 'true');
                    title.textContent = item.group;
                    groupContainer.append(title);
                    menu.append(groupContainer);
                }
                previousGroup = item.group;
            }
            const option = root.createElement('div');
            option.className = 'gc-select-menu-option';
            option.id = `${menu.id}-option-${item.index}`;
            option.dataset.index = String(item.index);
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', String(item.index === active.selectedIndex));
            option.setAttribute('aria-disabled', String(item.disabled));
            option.textContent = item.label || 'Aucun choix';
            groupContainer.append(option);
        }
        if (!items.length) {
            const empty = root.createElement('div');
            empty.className = 'gc-select-menu-empty';
            empty.textContent = 'Aucune option disponible';
            menu.append(empty);
        }
        place();
        if (active) highlight(items.some(item => item.index === highlighted && !item.disabled)
            ? highlighted : nextSelectOption(items, active.selectedIndex, 0));
    };
    const open = select => {
        close();
        if (!eligible(select)) return;
        active = select;
        previousAttributes = ['aria-expanded', 'aria-controls', 'aria-activedescendant'].map(name => [name, select.getAttribute(name)]);
        menu = root.createElement('div');
        menu.id = `gc-select-menu-${++sequence}`;
        menu.className = 'gc-select-menu';
        menu.setAttribute('role', 'listbox');
        const label = select.getAttribute('aria-label') || Array.from(select.labels || []).map(item => item.textContent.trim()).join(' ');
        menu.setAttribute('aria-label', label || 'Options');
        const style = browser.getComputedStyle(select);
        menu.style.setProperty('--select-accent', style.getPropertyValue('--workspace-ink').trim() || '#2459a8');
        menu.style.setProperty('--select-soft', style.getPropertyValue('--workspace-soft').trim() || '#edf4ff');
        select.setAttribute('aria-expanded', 'true');
        select.setAttribute('aria-controls', menu.id);
        // A modal dialog makes the rest of the document inert, including a popup appended to body.
        (select.closest('dialog[open]') || root.body).append(menu);
        highlighted = select.selectedIndex;
        render();
        if (!active) return;
        select.focus({ preventScroll: true });
        // AJAX can replace an option list, disable the field or remove its modal/table.
        observer = new browser.MutationObserver(records => {
            if (!active) return;
            if (!active.isConnected || !eligible(active) || !active.getClientRects().length) return close();
            if (records.some(record => active.contains(record.target))) render();
        });
        observer.observe(root.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['disabled', 'hidden', 'class', 'label', 'selected', 'open'], characterData: true });
    };
    const choose = index => {
        if (!active || !eligible(active)) return close();
        const item = selectMenuItems(active).find(item => item.index === index && !item.disabled);
        if (!item) return;
        const select = active;
        const changed = select.selectedIndex !== index;
        select.selectedIndex = index;
        close();
        if (changed) {
            select.dispatchEvent(new browser.Event('input', { bubbles: true }));
            select.dispatchEvent(new browser.Event('change', { bubbles: true }));
        }
    };

    root.addEventListener('pointerdown', event => {
        if (event.button !== 0) return;
        if (menu?.contains(event.target)) {
            // Leave touch scrolling available, but keep mouse focus on the combobox.
            if (event.pointerType !== 'touch') event.preventDefault();
            return;
        }
        const select = event.target.closest?.(selector);
        if (eligible(select)) {
            event.preventDefault();
            if (select === active) close();
            else open(select);
        } else close();
    }, true);
    root.addEventListener('click', event => {
        if (menu?.contains(event.target)) {
            const option = event.target.closest('[role="option"]');
            event.preventDefault();
            if (option) choose(Number(option.dataset.index));
            return;
        }
        const select = event.target.closest?.(selector);
        if (eligible(select)) {
            event.preventDefault();
            // Assistive technology may send a click without pointerdown.
            if (event.detail === 0 && !active) open(select);
        }
    }, true);
    root.addEventListener('keydown', event => {
        const select = event.target;
        if (!eligible(select) || event.isComposing || event.ctrlKey || event.metaKey) return;
        if (event.key === 'Escape' && active === select) {
            event.preventDefault();
            event.stopImmediatePropagation();
            close();
            return;
        }
        if (event.key === 'Tab') {
            if (active === select) { choose(highlighted); close(); }
            return;
        }
        const navigation = { ArrowDown: 1, ArrowUp: -1, Home: 'first', End: 'last', PageDown: 10, PageUp: -10 };
        const printable = event.key.length === 1 && !event.altKey;
        if (!(event.key in navigation) && !['Enter', ' '].includes(event.key) && !printable) return;
        event.preventDefault();
        const wasOpen = active === select;
        if (!wasOpen) open(select);
        if (!active) return;
        if (Date.now() - searchAt > 700) search = '';
        if (event.key === 'Enter' || (event.key === ' ' && !search)) {
            if (wasOpen) choose(highlighted);
        } else if (event.key in navigation) {
            search = '';
            highlight(nextSelectOption(items, highlighted, navigation[event.key]));
        } else if (printable) {
            const now = Date.now();
            search = now - searchAt > 700 ? event.key : search + event.key;
            searchAt = now;
            const repeated = [...search].every(character => character === search[0]);
            highlight(searchSelectOption(items, repeated ? search[0] : search, highlighted));
        }
    }, true);
    root.addEventListener('focusin', event => { if (active && event.target !== active && !menu.contains(event.target)) close(); });
    root.addEventListener('change', event => { if (event.target === active) close(); });
    root.addEventListener('reset', close, true);
    root.addEventListener('close', close, true);
    root.addEventListener('scroll', event => { if (active && !menu.contains(event.target)) close(); }, true);
    browser.addEventListener('resize', close);
    browser.visualViewport?.addEventListener('resize', close);
    return { close };
}
