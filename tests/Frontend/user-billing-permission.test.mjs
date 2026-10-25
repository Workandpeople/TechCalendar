import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

for (const area of ['admin', 'manager']) {
    const source = readFileSync(new URL(`../../resources/views/${area}/users/index.blade.php`, import.meta.url), 'utf8');
    const toggleSource = source.slice(source.indexOf('const toggleBillingPermission ='), source.indexOf('const toggleTechFields ='));
    const modalSource = source.slice(source.indexOf("document.addEventListener('click', (event) => {"), source.indexOf('closeButtons.forEach('));

    test(`${area}: only Planning can receive an explicit billing grant`, () => {
        const role = { value: '1' };
        const permission = { checked: false, disabled: true };
        const document = { getElementById: (id) => id.endsWith('_role') ? role : permission };
        const toggle = runInNewContext(`${toggleSource}\ntoggleBillingPermission;`, { document });

        toggle('edit');
        assert.equal(permission.disabled, false);
        assert.equal(permission.checked, false);

        for (const disallowedRole of ['0', '2', '3']) {
            role.value = disallowedRole;
            permission.checked = true;
            toggle('edit');
            assert.equal(permission.disabled, true);
            assert.equal(permission.checked, false);

            role.value = '1';
            toggle('edit');
            assert.equal(permission.disabled, false);
            assert.equal(permission.checked, false);
        }
    });

    test(`${area}: edit hydration does not leak a billing grant between users or into creation`, () => {
        const elements = new Map();
        const document = {
            getElementById(id) {
                if (!elements.has(id)) elements.set(id, { value: '', checked: false, disabled: false });
                return elements.get(id);
            },
            addEventListener(event, callback) {
                assert.equal(event, 'click');
                callbacks.push(callback);
            },
        };
        const callbacks = [];
        const datasets = [
            { modalOpen: 'edit-user-modal', userId: '1', userRole: '1', userCanManageBilling: '1' },
            { modalOpen: 'edit-user-modal', userId: '2', userRole: '1', userCanManageBilling: '0' },
            { modalOpen: 'edit-user-modal', userId: '3', userRole: '2', userCanManageBilling: '1' },
            { modalOpen: 'create-user-modal' },
        ];
        runInNewContext(`${toggleSource}
            const toggleTechFields = (prefix) => toggleBillingPermission(prefix);
            ${modalSource}`, {
            document,
            window: {},
            parseJsonDataset: () => [],
            setCheckboxValues() {},
            syncServiceSelectAll() {},
            initMapboxAutocomplete() {},
            bindDepartmentCheckboxes() {},
            updateDepartmentMapVisibility() {},
            refreshDepartmentUi() {},
            openModal() {},
        });

        assert.equal(callbacks.length, 1);
        const click = (dataset) => callbacks[0]({ target: { closest: () => dataset ? { dataset } : null } });
        click(null);
        click(datasets[0]);
        assert.equal(document.getElementById('edit_can_manage_billing').checked, true);
        assert.equal(document.getElementById('edit_can_manage_billing').disabled, false);
        click(datasets[1]);
        assert.equal(document.getElementById('edit_can_manage_billing').checked, false);
        click(datasets[2]);
        assert.equal(document.getElementById('edit_can_manage_billing').checked, false);
        assert.equal(document.getElementById('edit_can_manage_billing').disabled, true);

        document.getElementById('create_role').value = '1';
        document.getElementById('create_can_manage_billing').checked = true;
        click(datasets[3]);
        assert.equal(document.getElementById('create_can_manage_billing').checked, false);
        assert.equal(document.getElementById('create_can_manage_billing').disabled, false);
    });
}
