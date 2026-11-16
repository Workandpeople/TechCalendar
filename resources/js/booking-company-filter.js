const text = value => typeof value === 'string' ? value.trim().replace(/\s+/g, ' ') : '';
const normalize = value => text(value).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('fr');
const collator = new Intl.Collator('fr', { sensitivity: 'base', numeric: true });
const unknown = '__without_company__';
const companyKey = label => label ? `name:${normalize(label)}` : unknown;

export function companyLabel(row) {
    return text(row.company_name) || text(row.client_company_name) || text(row.delegataire_company_name);
}

export function companyOptions(rows) {
    const groups = new Map();
    for (const row of rows) {
        const label = companyLabel(row), key = companyKey(label);
        if (!groups.has(key)) groups.set(key, { key, label: label || 'Société non renseignée', count: 0 });
        groups.get(key).count++;
    }
    return [...groups.values()].sort((a, b) => (a.key === unknown) - (b.key === unknown) || collator.compare(a.label, b.label));
}

export function filterBookingCompanies(rows, { query = '', company = '', sort = 'recent' } = {}) {
    const terms = normalize(query).split(' ').filter(Boolean);
    const selected = company === unknown ? unknown : normalize(company);
    const matches = rows.map((row, index) => ({ row, index, company: normalize(companyLabel(row)) }));
    const result = matches.filter(item => {
        if (selected && companyKey(companyLabel(item.row)) !== selected) return false;
        const row = item.row;
        const haystack = normalize([row.last_name, row.first_name, companyLabel(row), row.service?.type || row.service_type,
            row.service?.name || row.service_display_name || row.service_name].filter(value => typeof value === 'string').join(' '));
        return terms.every(term => haystack.includes(term));
    });
    if (sort === 'company_asc' || sort === 'company_desc') {
        result.sort((a, b) => Number(!a.company) - Number(!b.company)
            || (sort === 'company_desc' ? -1 : 1) * collator.compare(a.company, b.company)
            || collator.compare(`${text(a.row.last_name)} ${text(a.row.first_name)}`, `${text(b.row.last_name)} ${text(b.row.first_name)}`)
            || a.index - b.index);
    }
    return result.map(item => item.row);
}

export function renderCompanyOptions(select, rows) {
    const selected = select.value, previousLabel = select.selectedOptions?.[0]?.dataset.companyLabel || selected;
    const options = companyOptions(rows);
    if (selected && !options.some(option => option.key === selected)) options.push({ key: selected, label: previousLabel, count: 0 });
    const option = (value, label, name = label) => {
        const node = select.ownerDocument.createElement('option');
        node.value = value; node.textContent = label; node.dataset.companyLabel = name;
        return node;
    };
    select.replaceChildren(option('', 'Toutes les sociétés'), ...options.map(item => option(item.key, `${item.label} (${item.count})`, item.label)));
    select.value = selected || '';
}
