import test from 'node:test';
import assert from 'node:assert/strict';

import {
    buildFocusLayout,
    mapActionTelemetryContext,
    mapHistoryDirection,
    mapReturnDecision,
    oneHopNodeIds,
} from '../../resources/js/living-map.mjs';

const nodes = [
    { id: 'goal:1', positionRole: 'future-goal' },
    { id: 'plan:1', positionRole: 'future-plan' },
    { id: 'task:1', positionRole: 'now' },
    { id: 'task:2', positionRole: 'future-next' },
    { id: 'tool:ai', positionRole: 'action-tool' },
    { id: 'evidence:1', positionRole: 'past-evidence' },
    { id: 'inbox:pending', positionRole: 'input-inbox' },
];

const edges = [
    { source: 'goal:1', target: 'plan:1', relation: 'contains' },
    { source: 'plan:1', target: 'task:1', relation: 'current_action' },
    { source: 'task:1', target: 'task:2', relation: 'next' },
    { source: 'task:1', target: 'tool:ai', relation: 'executed_with' },
    { source: 'task:1', target: 'evidence:1', relation: 'produced_evidence' },
];

test('one-hop focus keeps only the selected node and direct neighbors', () => {
    assert.deepEqual(
        [...oneHopNodeIds(edges, 'task:1')].sort(),
        ['evidence:1', 'plan:1', 'task:1', 'task:2', 'tool:ai'].sort(),
    );
});

test('primary task focus keeps semantic directions around the center', () => {
    const layout = buildFocusLayout(nodes, edges, 'task:1');

    assert.deepEqual(layout.positions.get('task:1'), { x: 50, y: 50 });
    assert.ok(layout.positions.get('plan:1').y < 50);
    assert.ok(layout.positions.get('task:2').y < 50);
    assert.ok(layout.positions.get('tool:ai').x > 50);
    assert.ok(layout.positions.get('evidence:1').y > 50);
    assert.equal(layout.visibleIds.has('goal:1'), false);
});

test('tool focus moves its current task to the left instead of overlapping center', () => {
    const layout = buildFocusLayout(nodes, edges, 'tool:ai');

    assert.deepEqual(layout.positions.get('tool:ai'), { x: 50, y: 50 });
    assert.ok(layout.positions.get('task:1').x < 50);
});

test('BFCache return revalidates while a fresh page only reports an already changed projection', () => {
    assert.equal(mapReturnDecision({
        persisted: true,
        currentProjectionKey: 'before',
        previousProjectionKey: 'before',
    }), 'revalidate');

    assert.equal(mapReturnDecision({
        persisted: false,
        currentProjectionKey: 'after',
        previousProjectionKey: 'before',
    }), 'updated');

    assert.equal(mapReturnDecision({
        persisted: false,
        currentProjectionKey: 'same',
        previousProjectionKey: 'same',
    }), 'clear');

    assert.equal(mapReturnDecision({
        persisted: true,
        currentProjectionKey: 'same',
        previousProjectionKey: '',
    }), 'none');
});


test('browser forward is not counted as Map back navigation', () => {
    assert.equal(mapHistoryDirection(2, 1), 'back');
    assert.equal(mapHistoryDirection(1, 2), 'forward');
    assert.equal(mapHistoryDirection(1, 1), 'same');
});


test('mobile focus keeps selected node above the sheet while preserving semantic directions', () => {
    const layout = buildFocusLayout(nodes, edges, 'task:1', { mobile: true });

    assert.deepEqual(layout.positions.get('task:1'), { x: 50, y: 34 });
    assert.ok(layout.positions.get('plan:1').y < 34);
    assert.ok(layout.positions.get('task:2').y < 34);
    assert.ok(layout.positions.get('tool:ai').x > 50);
    assert.ok(layout.positions.get('evidence:1').y > 34);
    assert.ok(layout.positions.get('evidence:1').y <= 62);
});


test('direct Primary launch keeps Primary Task telemetry without requiring Focus first', () => {
    const action = {
        dataset: {
            mapActionRole: 'primary',
            mapNodeType: 'task',
            mapPositionRole: 'now',
            mapIsPrimary: '1',
        },
    };

    assert.deepEqual(mapActionTelemetryContext(action), {
        action_role: 'primary',
        node_type: 'task',
        position_role: 'now',
        is_primary: true,
    });
});

test('Context Surface action still inherits the focused node when action metadata is absent', () => {
    const action = { dataset: { mapActionRole: 'secondary' } };
    const focusedNode = {
        dataset: {
            mapNodeType: 'evidence',
            mapPositionRole: 'past-evidence',
            mapIsPrimary: '0',
        },
    };

    assert.deepEqual(mapActionTelemetryContext(action, focusedNode), {
        action_role: 'secondary',
        node_type: 'evidence',
        position_role: 'past-evidence',
        is_primary: false,
    });
});


test('non-primary direct navigation carries the direct telemetry role', () => {
    const action = {
        dataset: {
            mapActionRole: 'direct',
            mapNodeType: 'plan',
            mapPositionRole: 'future-plan',
            mapIsPrimary: '0',
        },
    };

    assert.deepEqual(mapActionTelemetryContext(action), {
        action_role: 'direct',
        node_type: 'plan',
        position_role: 'future-plan',
        is_primary: false,
    });
});
