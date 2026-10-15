export function initSidebar(root = document, browser = window) {
    const sidebar = root.getElementById('app-sidebar');
    const toggle = root.getElementById('sidebar-toggle');
    const key = root.documentElement.dataset.sidebarCollapseStorageKey;
    if (!sidebar || !toggle || !key) return;

    const readState = () => {
        try {
            return browser.localStorage.getItem(key) === '1';
        } catch {
            return false;
        }
    };

    const applyState = (collapsed) => {
        if (collapsed && sidebar.contains(root.activeElement)) toggle.focus();
        root.documentElement.classList.toggle('sidebar-hidden', collapsed);
        toggle.setAttribute('aria-expanded', String(!collapsed));
        const label = collapsed ? 'D\u00e9plier le menu' : 'Replier le menu';
        toggle.setAttribute('aria-label', label);
        toggle.setAttribute('title', label);
        browser.requestAnimationFrame(() => {
            browser.dispatchEvent(new browser.CustomEvent('techcalendar:layout-resized'));
        });
    };

    toggle.addEventListener('click', () => {
        const collapsed = toggle.getAttribute('aria-expanded') === 'true';
        applyState(collapsed);
        try {
            browser.localStorage.setItem(key, collapsed ? '1' : '0');
        } catch {
            // Toggling remains available when storage is blocked or full.
        }
    });

    browser.addEventListener('storage', (event) => {
        if (event.key === key || event.key === null) applyState(readState());
    });
    applyState(readState());
}
