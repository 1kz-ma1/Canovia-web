import test from 'node:test';
import assert from 'node:assert/strict';

import {
    buildFocusLayout,
    buildMobileBaseLayout,
    clampMapViewTransform,
    createFrameBatcher,
    mapActionTelemetryContext,
    mapDockHistoryState,
    mapHistoryDirection,
    mapReturnDecision,
    mapSemanticZoomDirection,
    semanticContinuityTransform,
    semanticRectSnapshot,
    semanticRouteKey,
    adaptSemanticRect,
    oneHopNodeIds,
    zoomMapViewAt,
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

test('semantic route key normalizes query ordering and removes hash-only UI state', () => {
    assert.equal(
        semanticRouteKey('/map?plan=12&intent=execution&level=l3#focus=task%3A9'),
        '/map?intent=execution&level=l3&plan=12',
    );
    assert.equal(
        semanticRouteKey('/map?level=l3&intent=execution&plan=12'),
        '/map?intent=execution&level=l3&plan=12',
    );
});

test('semantic rect snapshot stores geometry without node labels or user content', () => {
    assert.deepEqual(
        semanticRectSnapshot(
            { left: 20.04, top: 30.06, width: 120.02, height: 80.08 },
            { width: 390, height: 700 },
        ),
        {
            left: 20,
            top: 30.1,
            width: 120,
            height: 80.1,
            viewport_width: 390,
            viewport_height: 700,
        },
    );
});

test('semantic rect adapts to viewport changes before continuity transform', () => {
    const adapted = adaptSemanticRect({
        left: 50,
        top: 100,
        width: 100,
        height: 80,
        viewport_width: 400,
        viewport_height: 800,
    }, {
        width: 800,
        height: 400,
    });

    assert.deepEqual(adapted, {
        left: 100,
        top: 50,
        width: 200,
        height: 40,
    });
});

test('semantic continuity moves the arriving center from the clicked node geometry', () => {
    const transform = semanticContinuityTransform(
        { left: 40, top: 120, width: 100, height: 80 },
        { left: 160, top: 260, width: 200, height: 120 },
    );

    assert.deepEqual(transform, {
        x: -170,
        y: -160,
        scale: 0.577,
    });
});

test('semantic continuity bounds extreme size ratios to avoid explosive transitions', () => {
    assert.equal(
        semanticContinuityTransform(
            { left: 0, top: 0, width: 4, height: 4 },
            { left: 0, top: 0, width: 400, height: 400 },
        ).scale,
        0.52,
    );
    assert.equal(
        semanticContinuityTransform(
            { left: 0, top: 0, width: 800, height: 800 },
            { left: 0, top: 0, width: 20, height: 20 },
        ).scale,
        1.9,
    );
});

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


test('L0 Space Station focus preserves the intent spatial grammar', () => {
    const intentNodes = [
        { id: 'intent:space-station', positionRole: 'space-station' },
        { id: 'intent:plan', positionRole: 'intent-plan' },
        { id: 'intent:execution', positionRole: 'intent-execution' },
        { id: 'intent:reflection', positionRole: 'intent-reflection' },
        { id: 'intent:collaboration', positionRole: 'intent-collaboration' },
    ];
    const intentEdges = [
        { source: 'intent:space-station', target: 'intent:plan', relation: 'routes_to' },
        { source: 'intent:space-station', target: 'intent:execution', relation: 'routes_to' },
        { source: 'intent:space-station', target: 'intent:reflection', relation: 'routes_to' },
        { source: 'intent:space-station', target: 'intent:collaboration', relation: 'routes_to' },
    ];

    const layout = buildFocusLayout(intentNodes, intentEdges, 'intent:space-station');

    assert.deepEqual(layout.positions.get('intent:space-station'), { x: 50, y: 50 });
    assert.ok(layout.positions.get('intent:plan').y < 50);
    assert.ok(layout.positions.get('intent:execution').x > 50);
    assert.ok(layout.positions.get('intent:reflection').y > 50);
    assert.ok(layout.positions.get('intent:collaboration').x < 50);
});


test('hierarchy focus keeps parent and child direction stable across L1 and L2', () => {
    const hierarchyNodes = [
        { id: 'hierarchy:intent:execution', positionRole: 'hierarchy-parent' },
        { id: 'domain:dev', positionRole: 'hierarchy-child' },
        { id: 'domain:study', positionRole: 'hierarchy-child' },
    ];
    const hierarchyEdges = [
        { source: 'hierarchy:intent:execution', target: 'domain:dev', relation: 'contains_domain' },
        { source: 'hierarchy:intent:execution', target: 'domain:study', relation: 'contains_domain' },
    ];

    const parentFocus = buildFocusLayout(
        hierarchyNodes,
        hierarchyEdges,
        'hierarchy:intent:execution',
    );

    assert.deepEqual(parentFocus.positions.get('hierarchy:intent:execution'), { x: 50, y: 50 });
    assert.ok(parentFocus.positions.get('domain:dev').y < 50);
    assert.ok(parentFocus.positions.get('domain:study').y < 50);

    const childFocus = buildFocusLayout(hierarchyNodes, hierarchyEdges, 'domain:dev');

    assert.deepEqual(childFocus.positions.get('domain:dev'), { x: 50, y: 50 });
    assert.ok(childFocus.positions.get('hierarchy:intent:execution').y > 50);
});

test('semantic zoom direction fails safe to zoom-in', () => {
    assert.equal(mapSemanticZoomDirection('in'), 'in');
    assert.equal(mapSemanticZoomDirection('out'), 'out');
    assert.equal(mapSemanticZoomDirection('something-else'), 'in');
});


test('personalized satellite focus keeps each orbit slot in its spatial direction', () => {
    const nodes = [
        { id: 'intent:space-station', positionRole: 'space-station' },
        { id: 'satellite:plan:1', positionRole: 'satellite-1' },
        { id: 'satellite:plan:2', positionRole: 'satellite-2' },
        { id: 'satellite:plan:3', positionRole: 'satellite-3' },
        { id: 'satellite:plan:4', positionRole: 'satellite-4' },
    ];
    const edges = [
        { source: 'intent:space-station', target: 'satellite:plan:1', relation: 'personalized_shortcut' },
        { source: 'intent:space-station', target: 'satellite:plan:2', relation: 'personalized_shortcut' },
        { source: 'intent:space-station', target: 'satellite:plan:3', relation: 'personalized_shortcut' },
        { source: 'intent:space-station', target: 'satellite:plan:4', relation: 'personalized_shortcut' },
    ];

    const top = buildFocusLayout(nodes, edges, 'satellite:plan:1');
    const right = buildFocusLayout(nodes, edges, 'satellite:plan:2');
    const bottom = buildFocusLayout(nodes, edges, 'satellite:plan:3');
    const left = buildFocusLayout(nodes, edges, 'satellite:plan:4');

    assert.deepEqual(top.positions.get('satellite:plan:1'), { x: 50, y: 50 });
    assert.ok(top.positions.get('intent:space-station').y > 50);
    assert.ok(right.positions.get('intent:space-station').x < 50);
    assert.ok(bottom.positions.get('intent:space-station').y < 50);
    assert.ok(left.positions.get('intent:space-station').x > 50);
});


test('mobile L0 compensates for tall viewport aspect ratio without mutating desktop coordinates', () => {
    const l0Nodes = [
        { id: 'intent:space-station', positionRole: 'space-station', x: 50, y: 50 },
        { id: 'intent:plan', positionRole: 'intent-plan', x: 24, y: 23 },
        { id: 'intent:execution', positionRole: 'intent-execution', x: 76, y: 23 },
        { id: 'intent:reflection', positionRole: 'intent-reflection', x: 76, y: 77 },
        { id: 'intent:collaboration', positionRole: 'intent-collaboration', x: 24, y: 77 },
        { id: 'satellite:1', positionRole: 'satellite-1', x: 50, y: 12 },
        { id: 'satellite:2', positionRole: 'satellite-2', x: 88, y: 50 },
        { id: 'satellite:3', positionRole: 'satellite-3', x: 50, y: 88 },
        { id: 'satellite:4', positionRole: 'satellite-4', x: 12, y: 50 },
    ];
    const viewport = { width: 390, height: 700 };

    const layout = buildMobileBaseLayout(l0Nodes, 'l0', viewport);

    assert.deepEqual(layout.get('intent:space-station'), { x: 50, y: 50 });
    assert.deepEqual(layout.get('intent:plan'), { x: 27, y: 37.2 });
    assert.deepEqual(layout.get('intent:execution'), { x: 73, y: 37.2 });
    assert.deepEqual(layout.get('intent:reflection'), { x: 73, y: 62.8 });
    assert.deepEqual(layout.get('intent:collaboration'), { x: 27, y: 62.8 });
    assert.deepEqual(layout.get('satellite:1'), { x: 50, y: 28.8 });
    assert.deepEqual(layout.get('satellite:2'), { x: 88, y: 50 });
    assert.deepEqual(layout.get('satellite:3'), { x: 50, y: 71.2 });
    assert.deepEqual(layout.get('satellite:4'), { x: 12, y: 50 });

    const plan = layout.get('intent:plan');
    const innerDx = (50 - plan.x) * viewport.width / 100;
    const innerDy = (50 - plan.y) * viewport.height / 100;
    assert.ok(Math.abs(innerDx - innerDy) < 1, 'inner ring uses equal pixel radius');

    const topSatellite = layout.get('satellite:1');
    const rightSatellite = layout.get('satellite:2');
    const outerDy = (50 - topSatellite.y) * viewport.height / 100;
    const outerDx = (rightSatellite.x - 50) * viewport.width / 100;
    assert.ok(Math.abs(outerDx - outerDy) < 1, 'outer ring uses equal pixel radius');

    assert.deepEqual(
        { x: l0Nodes[1].x, y: l0Nodes[1].y },
        { x: 24, y: 23 },
        'server/desktop attention coordinates stay immutable',
    );
});

test('mobile hierarchy uses a pixel-balanced orbit around the same semantic parent', () => {
    const hierarchyNodes = [
        { id: 'parent', positionRole: 'hierarchy-parent', x: 50, y: 50 },
        { id: 'child:1', positionRole: 'hierarchy-child', x: 50, y: 20 },
        { id: 'child:2', positionRole: 'hierarchy-child', x: 80, y: 50 },
        { id: 'child:3', positionRole: 'hierarchy-child', x: 50, y: 80 },
        { id: 'child:4', positionRole: 'hierarchy-child', x: 20, y: 50 },
    ];
    const viewport = { width: 390, height: 700 };

    const layout = buildMobileBaseLayout(hierarchyNodes, 'l2', viewport);

    assert.deepEqual(layout.get('parent'), { x: 50, y: 50 });
    assert.deepEqual(layout.get('child:1'), { x: 50, y: 31.1 });
    assert.deepEqual(layout.get('child:2'), { x: 84, y: 50 });
    assert.deepEqual(layout.get('child:3'), { x: 50, y: 68.9 });
    assert.deepEqual(layout.get('child:4'), { x: 16, y: 50 });

    const top = layout.get('child:1');
    const right = layout.get('child:2');
    const dy = (50 - top.y) * viewport.height / 100;
    const dx = (right.x - 50) * viewport.width / 100;
    assert.ok(Math.abs(dx - dy) < 1, 'hierarchy orbit is circular in rendered pixels');
});


test('mobile map transform clamps zoom and pan to a bounded universe', () => {
    assert.deepEqual(
        clampMapViewTransform(
            { x: 999, y: -999, scale: 3 },
            { width: 400, height: 800 },
        ),
        { x: 280, y: -560, scale: 2.2 },
    );

    assert.deepEqual(
        clampMapViewTransform(
            { x: 999, y: -999, scale: 0.5 },
            { width: 400, height: 800 },
        ),
        { x: 16, y: -32, scale: 0.82 },
    );

    assert.deepEqual(
        clampMapViewTransform(
            { x: 80, y: -100, scale: 1 },
            { width: 400, height: 800 },
        ),
        { x: 40, y: -80, scale: 1 },
    );
});

test('pinch zoom keeps the chosen focus point stable before applying bounds', () => {
    const next = zoomMapViewAt(
        { x: 0, y: 0, scale: 1 },
        2,
        { x: 300, y: 400 },
        { width: 400, height: 800 },
    );

    assert.deepEqual(next, { x: -100, y: 0, scale: 2 });

    const centered = zoomMapViewAt(
        { x: 0, y: 0, scale: 1 },
        1.5,
        { x: 200, y: 400 },
        { width: 400, height: 800 },
    );

    assert.deepEqual(centered, { x: 0, y: 0, scale: 1.5 });
});


test('frame batcher coalesces repeated runtime writes and flushes only the latest value', () => {
    const queued = [];
    const cancelled = [];
    const flushed = [];

    const batcher = createFrameBatcher(
        (value) => flushed.push(value),
        {
            requestFrame: (callback) => {
                queued.push(callback);
                return queued.length;
            },
            cancelFrame: (id) => cancelled.push(id),
        },
    );

    batcher.schedule({ x: 1 });
    batcher.schedule({ x: 2 });
    batcher.schedule({ x: 3 });

    assert.equal(queued.length, 1);
    assert.equal(batcher.pending(), true);
    assert.deepEqual(flushed, []);

    queued[0]();

    assert.equal(batcher.pending(), false);
    assert.deepEqual(flushed, [{ x: 3 }]);
    assert.deepEqual(cancelled, []);
});

test('frame batcher flushNow commits pending work once and cancels the queued frame', () => {
    const queued = [];
    const cancelled = [];
    const flushed = [];

    const batcher = createFrameBatcher(
        (value) => flushed.push(value),
        {
            requestFrame: (callback) => {
                queued.push(callback);
                return 44;
            },
            cancelFrame: (id) => cancelled.push(id),
        },
    );

    batcher.schedule('latest');
    assert.equal(batcher.flushNow(), true);
    assert.deepEqual(flushed, ['latest']);
    assert.deepEqual(cancelled, [44]);
    assert.equal(batcher.pending(), false);
    assert.equal(batcher.flushNow(), false);
});

test('frame batcher cancel drops pending runtime work without flushing it', () => {
    const flushed = [];
    const cancelled = [];

    const batcher = createFrameBatcher(
        (value) => flushed.push(value),
        {
            requestFrame: () => 91,
            cancelFrame: (id) => cancelled.push(id),
        },
    );

    batcher.schedule('discard-me');
    batcher.cancel();

    assert.deepEqual(cancelled, [91]);
    assert.deepEqual(flushed, []);
    assert.equal(batcher.pending(), false);
});


test('dock history removes stale focus id while preserving focus depth', () => {
    assert.deepEqual(
        mapDockHistoryState({
            canoviaMapFocus: 'task:1',
            canoviaMapFocusDepth: 2,
            unrelated: 'keep',
        }, 'space-station'),
        {
            canoviaMapFocusDepth: 2,
            unrelated: 'keep',
            canoviaMapDock: 'space-station',
        },
    );
});


test('mobile L3 uses separated semantic slots for execution nodes', () => {
    const executionNodes = [
        { id: 'goal:1', positionRole: 'future-goal', x: 50, y: 9 },
        { id: 'plan:1', positionRole: 'future-plan', x: 32, y: 25 },
        { id: 'task:1', positionRole: 'now', x: 50, y: 50 },
        { id: 'task:2', positionRole: 'future-next', x: 68, y: 25 },
        { id: 'tool:1', positionRole: 'action-tool', x: 82, y: 51 },
        { id: 'evidence:1', positionRole: 'past-evidence', x: 50, y: 79 },
        { id: 'inbox:1', positionRole: 'input-inbox', x: 18, y: 52 },
    ];
    const viewport = { width: 390, height: 700 };

    const layout = buildMobileBaseLayout(executionNodes, 'l3', viewport);

    assert.deepEqual(layout.get('goal:1'), { x: 50, y: 18.6 });
    assert.deepEqual(layout.get('plan:1'), { x: 25, y: 31.1 });
    assert.deepEqual(layout.get('task:2'), { x: 75, y: 31.1 });
    assert.deepEqual(layout.get('task:1'), { x: 50, y: 50 });
    assert.deepEqual(layout.get('inbox:1'), { x: 11, y: 52 });
    assert.deepEqual(layout.get('tool:1'), { x: 89, y: 52 });
    assert.deepEqual(layout.get('evidence:1'), { x: 50, y: 75.7 });

    const primary = layout.get('task:1');
    const tool = layout.get('tool:1');
    const inbox = layout.get('inbox:1');
    const toolDistance = Math.abs(tool.x - primary.x) * viewport.width / 100;
    const inboxDistance = Math.abs(primary.x - inbox.x) * viewport.width / 100;

    assert.ok(toolDistance >= 150, 'tool keeps a wide mobile gap from Primary Action');
    assert.ok(inboxDistance >= 150, 'inbox keeps a wide mobile gap from Primary Action');
});
