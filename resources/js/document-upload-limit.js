export function exceedsDocumentLimit(files, maxBytes) {
    return Number.isSafeInteger(maxBytes) && maxBytes > 0
        && Array.from(files || []).some(file => file.size > maxBytes);
}

export function initDocumentUploadLimits(root = document) {
    root.querySelectorAll('input[type="file"][data-document-max-bytes]').forEach(input => {
        const validate = () => {
            const invalid = exceedsDocumentLimit(input.files, Number(input.dataset.documentMaxBytes));
            input.setCustomValidity(invalid ? 'Le document ne doit pas dépasser 1 Go.' : '');
            return !invalid;
        };
        input.addEventListener('change', () => {
            if (!validate()) input.reportValidity();
        });
        input.form?.addEventListener('submit', event => {
            if (!validate()) {
                event.preventDefault();
                input.reportValidity();
            }
        });
    });
}
