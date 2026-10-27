const statuses = ['pending', 'problem', 'awaiting_visit', 'visited', 'rt_control', 'non_compliant', 'recontrol', 'report', 'invoiced', 'cancelled', 'unknown'];
const pageSizes = ['10', '25', '50', '100'];

// Store navigation preferences only, not search terms, client identities or page contents.
export function readDossierPreferences(storage, key) {
    try {
        const value = JSON.parse(storage.getItem(key));
        return {
            status: statuses.includes(value?.status) ? value.status : '',
            per_page: pageSizes.includes(value?.per_page) ? value.per_page : '10',
        };
    } catch {
        return { status: '', per_page: '10' };
    }
}

export function saveDossierPreferences(storage, key, url) {
    try {
        const status = url.searchParams.get('status') || '';
        const size = url.searchParams.get('per_page') || '10';
        storage.setItem(key, JSON.stringify({ status: statuses.includes(status) ? status : '', per_page: pageSizes.includes(size) ? size : '10' }));
    } catch { /* Browsers may deny storage; filtering must still work. */ }
}

export function preferredDossierUrl(url, preferences) {
    const preferred = new URL(url.href);
    // An explicit link always wins; restoring a preference must never change a shared filtered URL.
    if (preferred.search) return preferred;
    if (preferences.status) preferred.searchParams.set('status', preferences.status);
    if (preferences.per_page !== '10') preferred.searchParams.set('per_page', preferences.per_page);
    return preferred;
}
