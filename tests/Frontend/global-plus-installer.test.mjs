import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

const forms = [
    ['manager', 'resources/views/manager/lots/show.blade.php', 'physicalGlobalPlus', 'currentGlobalPlusReferences',
        'function populateGlobalPlusReferences(', 'function selectedGlobalPlusInstaller()', 'populateGlobalPlusReferences', 'option'],
    ['planner', 'resources/views/planner/book.blade.php', 'confirmationGlobalPlus', 'confirmationGlobalPlusReferences',
        'const populateConfirmationGlobalPlusReferences =', 'const loadConfirmationGlobalPlusReferences =', 'populateConfirmationGlobalPlusReferences', 'bookingGlobalPlusOption'],
];

for (const [name, file, prefix, references, start, end, populate, option] of forms) {
    const source = readFileSync(new URL(`../../${file}`, import.meta.url), 'utf8');
    const body = source.slice(source.indexOf(start), source.indexOf(end));
    for (const addressId of [901, 0]) {
        for (const inDirectory of [true, false]) {
            test(`${name}: restores ${addressId ? 'selected' : 'manual'} installer, directory present=${inDirectory}`, () => {
                const elements = {};
                const context = {
                    document: { getElementById: () => ({ textContent: '' }) },
                    [references]: null,
                    [option]: (label, value = '', selected = false) => `<option value="${value}"${selected ? ' selected' : ''}>${label}</option>`,
                };
                for (const field of ['Client', 'Submit', 'Version', 'Installer', 'Controller', 'ControllerSummary',
                    'InstallerName', 'InstallerSiren', 'InstallerAddress', 'InstallerPostalCode', 'InstallerCity',
                    'InstallerPhone', 'Title', 'SubTitle', 'Precariousness', 'SendDocuments']) {
                    const element = { value: '', disabled: false, readOnly: false };
                    Object.defineProperty(element, 'innerHTML', {
                        set(html) { this.value = html.match(/value="([^"]*)" selected/)?.[1] || ''; },
                    });
                    elements[field] = element;
                    context[prefix + field] = element;
                }
                const suffix = name === 'manager' ? 'GlobalPlus' : 'ConfirmationGlobalPlus';
                context[`update${suffix}ClientSelection`] = () => {};
                context[`default${suffix}Title`] = () => 'Lot';
                context[`default${suffix}SubTitle`] = () => 'Reference';
                context[`fill${suffix}InstallerFieldsFromSelection`] = () => {
                    throw new Error('A saved snapshot must not be overwritten by the live directory');
                };
                context.appointment = { global_plus_demand_id: '5655', installer_name: 'Wrong suggestion' };
                context.savedReferences = {
                    existing_installer: {
                        address_id: addressId, name: 'Saved installer', siren: '123456789',
                        address: '12 Rue Test', postal_code: '75002', city: 'Paris', phone: '0600000000',
                    },
                    suggested_installer_address_id: 999,
                    installers: inDirectory ? [{ address_id: 901, name: 'Changed directory entry' }] : [],
                };
                runInNewContext(`${body}; ${populate}(appointment, savedReferences);`, context);
                assert.equal(elements.Installer.value, addressId ? '901' : '');
                assert.equal(elements.Installer.disabled, true);
                assert.equal(elements.InstallerName.value, 'Saved installer');
                assert.equal(elements.InstallerSiren.value, '123456789');
                assert.equal(elements.InstallerAddress.value, '12 Rue Test');
                assert.equal(elements.InstallerPostalCode.value, '75002');
                assert.equal(elements.InstallerCity.value, 'Paris');
                assert.equal(elements.InstallerPhone.value, '0600000000');
                assert.equal(elements.InstallerName.readOnly, true);
                // Opening another dossier must not retain the prior dossier's installer or readonly state.
                context.appointment = { installer_name: 'New installer' };
                context.savedReferences = { installers: [], existing_installer: null };
                context[`fill${suffix}InstallerFieldsFromSelection`] = () => {};
                runInNewContext(`${populate}(appointment, savedReferences);`, context);
                assert.equal(elements.Installer.value, '');
                assert.equal(elements.Installer.disabled, false);
                assert.equal(elements.InstallerName.value, 'New installer');
                assert.equal(elements.InstallerName.readOnly, false);
                assert.equal(elements.InstallerCity.value, '');
            });
        }
    }
}
