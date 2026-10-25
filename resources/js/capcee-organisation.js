export function initCapceeOrganisation(root = document) {
    const block = root.querySelector('[data-capcee-organisation]');
    if (!block) return;
    const kind = block.closest('form').elements.namedItem('kind');
    const checkbox = block.querySelector('input[type="checkbox"]');
    const sync = () => {
        block.hidden = kind.value !== 'delegataire';
        checkbox.disabled = block.hidden;
    };
    kind.addEventListener('change', sync);
    sync();
}
