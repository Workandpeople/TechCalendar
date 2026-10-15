import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../resources/views/manager/lots/index.blade.php', import.meta.url), 'utf8');
const code = source.slice(source.indexOf('        function updateLotImportProgress('), source.indexOf('        function stopLotImportWatchers('));

for (const status of ['completed', 'failed', 'confirmed']) {
    test(`immediately displays 100 percent for ${status} instead of leaving a stale animation`, () => {
        const values = [], text = {}, stage = {};
        let started = false;
        runInNewContext(`${code}\nupdateLotImportProgress(100, 'Termine', 'Pret');`, {
            currentLotImport: { status }, lotImportActualProgress: 30, lotImportVisualProgress: 25,
            resetLotImportProgressAnimation: value => values.push(value),
            setLotImportProgressVisual: value => values.push(value),
            startLotImportProgressAnimation: () => { started = true; },
            lotImportStatus: text, lotImportStage: stage,
        });
        assert.deepEqual(values, [100]);
        assert.equal(started, false);
        assert.equal(text.textContent, 'Termine');
    });
}
