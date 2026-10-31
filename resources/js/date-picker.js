export function initDatePickers(root = document) {
    root.querySelectorAll('input[type="date"][data-date-picker]').forEach(input => {
        if (input.dataset.datePickerReady) return;
        input.dataset.datePickerReady = '1';
        input.addEventListener('click', event => {
            if (event.defaultPrevented || event.button > 0 || event.ctrlKey || event.metaKey || event.altKey
                || input.disabled || input.readOnly || typeof input.showPicker !== 'function') return;
            try {
                input.showPicker();
                event.preventDefault();
            } catch {
                // Unsupported/denied pickers keep their normal native input behavior.
            }
        });
    });
}
