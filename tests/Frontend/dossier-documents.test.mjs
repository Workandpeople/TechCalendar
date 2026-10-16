import { test } from 'node:test';
import assert from 'node:assert/strict';
import { reportActions } from '../../resources/js/dossier-documents.js';

test('report actions follow existing states instead of permitting arbitrary status jumps', () => {
    assert.deepEqual(reportActions('visited'), ['store', 'submit_control']);
    assert.deepEqual(reportActions('rt_control'), ['store', 'revise_control', 'validate_report']);
    assert.deepEqual(reportActions('rt_control', false), ['store']);
    assert.deepEqual(reportActions('recontrol'), ['store', 'validate_report']);
    for (const state of ['pending', 'awaiting_visit', 'non_compliant', 'report', 'invoiced', undefined]) {
        assert.deepEqual(reportActions(state), ['store']);
    }
});
