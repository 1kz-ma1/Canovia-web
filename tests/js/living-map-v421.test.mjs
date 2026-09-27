import test from 'node:test';
import assert from 'node:assert/strict';

import {
    buildFocusLayout,
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
