import test from 'node:test';
import assert from 'node:assert/strict';
import {
    spaceStationDestinationScope,
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


test('destination scope asks only for canonical targets that destination actually needs', () => {
    assert.deepEqual(spaceStationDestinationScope('keep_inbox'), {
        plan: false,
        task: false,
        execution: false,
    });
    assert.deepEqual(spaceStationDestinationScope('plan_resource'), {
        plan: true,
        task: false,
        execution: false,
    });
    assert.deepEqual(spaceStationDestinationScope('task_evidence'), {
        plan: true,
        task: true,
        execution: false,
    });
    assert.deepEqual(spaceStationDestinationScope('execution_request'), {
        plan: true,
        task: true,
        execution: true,
    });
});
