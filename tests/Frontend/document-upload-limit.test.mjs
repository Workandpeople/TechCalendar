import { test } from 'node:test';
import assert from 'node:assert/strict';
import { exceedsDocumentLimit, initDocumentUploadLimits } from '../../resources/js/document-upload-limit.js';

const limit = 1024 ** 3;

test('checks each file without reading it and accepts exactly one GiB', () => {
    assert.equal(exceedsDocumentLimit([], limit), false);
    assert.equal(exceedsDocumentLimit(null, limit), false);
    assert.equal(exceedsDocumentLimit([{ size: limit }], limit), false);
    assert.equal(exceedsDocumentLimit([{ size: limit }, { size: 12 }], limit), false);
    assert.equal(exceedsDocumentLimit([{ size: 12 }, { size: limit + 1 }], limit), true);
    for (const value of [0, NaN, -1, Infinity]) assert.equal(exceedsDocumentLimit([{ size: limit + 1 }], value), false);
});

test('shows an immediate error and blocks submission, then clears the error on a valid selection', () => {
    const handlers = {};
    const input = { dataset: { documentMaxBytes: String(limit) }, files: [{ size: limit + 1 }],
        setCustomValidity(message) { this.message = message; }, reportValidity() { this.reported = true; },
        addEventListener(name, callback) { handlers[name] = callback; },
        form: { addEventListener(name, callback) { handlers[`form:${name}`] = callback; } },
    };
    initDocumentUploadLimits({ querySelectorAll: () => [input] });
    handlers.change();
    assert.match(input.message, /1 Go/);
    assert.equal(input.reported, true);
    let prevented = false;
    handlers['form:submit']({ preventDefault() { prevented = true; } });
    assert.equal(prevented, true);
    input.files = [{ size: limit }];
    handlers.change();
    assert.equal(input.message, '');
    prevented = false;
    handlers['form:submit']({ preventDefault() { prevented = true; } });
    assert.equal(prevented, false);
    input.files = [];
    handlers.change();
    assert.equal(input.message, '');
});
