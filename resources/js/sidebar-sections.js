export function initSidebarSections(root = document, browser = window) {
    const key = root.querySelector('[data-sidebar-storage-key]')?.dataset.sidebarStorageKey;
    const buttons = [...root.querySelectorAll('[data-sidebar-section-toggle]')];
    if (!key || buttons.length === 0) return;

    const readState = () => {
        try {
            const value = JSON.parse(browser.localStorage.getItem(key));
            return value && typeof value === 'object' && !Array.isArray(value) ? value : {};
        } catch {
            return {};
        }
    };
    let state = readState();

    const applyState = () => {
        buttons.forEach((button) => {
            const expanded = state[button.dataset.sidebarSectionToggle] !== false;
            button.setAttribute('aria-expanded', String(expanded));
            const panel = root.getElementById(button.getAttribute('aria-controls'));
            if (panel) panel.hidden = !expanded;
        });
    };

    buttons.forEach((button) => {
        button.addEventListener('click', () => {
            state[button.dataset.sidebarSectionToggle] = button.getAttribute('aria-expanded') !== 'true';
            applyState();
            try {
                browser.localStorage.setItem(key, JSON.stringify(state));
            } catch {
                // The menu remains usable when storage is blocked or full.
            }
        });
    });
    browser.addEventListener('storage', (event) => {
        if (event.key === key || event.key === null) {
            state = readState();
            applyState();
        }
    });
    applyState();
}
