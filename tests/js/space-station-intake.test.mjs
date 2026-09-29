import test from 'node:test';
import assert from 'node:assert/strict';
import {
    spaceStationNeedsExecutionDetails,
    spaceStationTaskBelongsToPlan,
} from '../../resources/js/space-station-intake.mjs';

test('execution request is the only destination that requires execution details', () => {
    assert.equal(spaceStationNeedsExecutionDetails('execution_request'), true);
    assert.equal(spaceStationNeedsExecutionDetails('task_evidence'), false);
    assert.equal(spaceStationNeedsExecutionDetails('keep_inbox'), false);
});

test('task choices are valid only inside the selected plan', () => {
    assert.equal(spaceStationTaskBelongsToPlan('12', '12'), true);
    assert.equal(spaceStationTaskBelongsToPlan(12, '12'), true);
    assert.equal(spaceStationTaskBelongsToPlan('12', '13'), false);
    assert.equal(spaceStationTaskBelongsToPlan('', '12'), false);
    assert.equal(spaceStationTaskBelongsToPlan('12', ''), false);
});
