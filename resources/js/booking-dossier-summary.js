export function safeDossierSummaryUrl(value, origin) {
    if (typeof value !== 'string' || !value) return null;
    try {
        const url = new URL(value, origin);
        return url.origin === origin && !url.username && !url.password && !url.search && !url.hash
            && /^\/v2\/dossiers\/[1-9]\d*$/.test(url.pathname) ? url.href : null;
    } catch { return null; }
}

export function renderBookingDossierSummary(section, summary, browser = window) {
    if (!summary || typeof summary !== 'object' || Array.isArray(summary)) summary = null;
    section.hidden = !summary;
    section.querySelectorAll('[data-booking-summary-field]').forEach(field => {
        const key = field.dataset.bookingSummaryField;
        const value = typeof summary?.[key] === 'string' ? summary[key].trim() : '';
        field.textContent = value || 'Non renseigné';
        if (key === 'status') field.hidden = !value;
    });
    section.querySelectorAll('[data-booking-summary-optional]').forEach(row => {
        const value = summary?.[row.dataset.bookingSummaryOptional];
        row.hidden = typeof value !== 'string' || !value.trim();
    });
    const phones = section.querySelector('[data-booking-summary-phones]');
    phones.replaceChildren();
    for (const number of Array.isArray(summary?.phone_numbers) ? summary.phone_numbers : []) {
        if (typeof number?.label !== 'string' || !number.label.trim()) continue;
        const validHref = typeof number.href === 'string' && /^tel:\+?[0-9]{8,15}$/.test(number.href);
        const element = section.ownerDocument.createElement(validHref ? 'a' : 'span');
        element.textContent = number.label;
        if (validHref) element.href = number.href;
        phones.append(element);
    }
    if (!phones.childElementCount) phones.textContent = 'Non renseigné';
    const link = section.querySelector('[data-booking-summary-link]');
    const href = safeDossierSummaryUrl(summary?.dossier_url, browser.location.origin);
    link.hidden = !href;
    if (href) link.href = href;
    else link.removeAttribute('href');
}

export function initBookingDossierSummary(root = document, browser = window) {
    const section = root.querySelector('[data-booking-summary]');
    if (!section) return;
    const update = () => {
        let summary = null;
        try { summary = JSON.parse(section.dataset.bookingSummary || 'null'); } catch { /* Hide an incomplete initial context. */ }
        renderBookingDossierSummary(section, summary, browser);
    };
    section.addEventListener('techcalendar:booking-summary-updated', update);
    update();
}
