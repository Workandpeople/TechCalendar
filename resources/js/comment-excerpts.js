const initialized = new WeakSet();

export function initCommentExcerpts(root = document, browser = window) {
    const excerpts = Array.from(root.querySelectorAll('[data-comment-excerpt]'));
    if (!excerpts.length || initialized.has(root)) return;
    initialized.add(root);

    const entries = excerpts.map((excerpt) => {
        const body = excerpt.querySelector('[data-excerpt-body]');
        const button = excerpt.querySelector('[data-excerpt-toggle]');
        const entry = { excerpt, body, button, expanded: false, width: body.clientWidth };
        button.addEventListener('click', () => {
            entry.expanded = !entry.expanded;
            render(entry);
        });
        return entry;
    });

    function render(entry) {
        entry.excerpt.dataset.excerptExpanded = String(entry.expanded);
        entry.button.setAttribute('aria-expanded', String(entry.expanded));
        entry.button.textContent = entry.expanded ? 'Afficher moins' : 'Afficher plus';
    }

    function refresh() {
        for (const entry of entries) {
            if (entry.body.clientWidth === 0) continue;
            const lineHeight = Number.parseFloat(browser.getComputedStyle(entry.body).lineHeight);
            if (!Number.isFinite(lineHeight) || lineHeight <= 0) continue;
            const overflows = entry.body.scrollHeight > lineHeight * 2 + 1;
            entry.button.hidden = !overflows;
            if (!overflows) entry.expanded = false;
            entry.excerpt.dataset.excerptReady = 'true';
            render(entry);
        }
    }

    let scheduled = false;
    function scheduleRefresh() {
        if (scheduled) return;
        scheduled = true;
        browser.requestAnimationFrame(() => { scheduled = false; refresh(); });
    }

    if (typeof browser.ResizeObserver === 'function') {
        const observer = new browser.ResizeObserver((changes) => {
            for (const change of changes) {
                const entry = entries.find(item => item.body === change.target);
                if (entry && change.contentRect.width !== entry.width) {
                    entry.width = change.contentRect.width;
                    scheduleRefresh();
                }
            }
        });
        entries.forEach(entry => observer.observe(entry.body));
    }
    browser.addEventListener('resize', scheduleRefresh);
    (root.ownerDocument || root).fonts?.ready.then(scheduleRefresh);
    refresh();
}
