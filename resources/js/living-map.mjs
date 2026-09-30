import {
    advanceMapTelemetryFlow,
    clearMapTelemetryFlow,
    ensureMapTelemetryFlow,
    mapClientDevice,
    mapClientSurface,
    mapTelemetryMetadata,
} from './map-telemetry.mjs';

import { fitMobileNodeBoxes } from './map-node-boxes.mjs';

const PENDING_REEVALUATION_KEY = 'canovia.map.pending-reevaluation.v1';
const SEMANTIC_TRANSITION_KEY = 'canovia.map.semantic-transition.v1';
const GLOBAL_HOME_RESET_KEY = 'canovia.map.global-home-reset.v1';
const SEMANTIC_CONTEXT_WINDOW_KEY = '__canoviaMapSemanticContextV1';

function roleGroup(role = '') {
    if (role === 'space-station') return 'now';
    if (role === 'intent-plan') return 'future';
    if (role === 'intent-execution') return 'action';
    if (role === 'intent-reflection') return 'past';
    if (role === 'intent-collaboration') return 'input';
    if (role === 'hierarchy-parent') return 'now';
    if (role === 'hierarchy-child') return 'future';
    if (role === 'context-plan') return 'now';
    if (role === 'action-primary') return 'action';
    if (role === 'satellite-1') return 'future';
    if (role === 'satellite-2') return 'action';
    if (role === 'satellite-3') return 'past';
    if (role === 'satellite-4') return 'input';
    if (role.startsWith('future')) return 'future';
    if (role.startsWith('past')) return 'past';
    if (role.startsWith('input')) return 'input';
    if (role.startsWith('action')) return 'action';
    return role === 'now' ? 'now' : 'other';
}

export function oneHopNodeIds(edges, selectedId) {
    const ids = new Set([selectedId]);

    for (const edge of edges) {
        if (edge.source === selectedId) ids.add(edge.target);
        if (edge.target === selectedId) ids.add(edge.source);
    }

    return ids;
}

function slots(group, count, { mobile = false } = {}) {
    const safeCount = Math.max(1, count);
    const centered = (index, step) => (index - (safeCount - 1) / 2) * step;

    return Array.from({ length: safeCount }, (_, index) => {
        if (mobile) {
            if (group === 'future') return { x: 50 + centered(index, 23), y: 14 };
            if (group === 'past') return { x: 50 + centered(index, 22), y: 62 };
            if (group === 'input') return { x: 18, y: 34 + centered(index, 15) };
            if (group === 'action') return { x: 82, y: 34 + centered(index, 15) };
            return { x: 50 + centered(index, 21), y: 60 };
        }

        if (group === 'future') return { x: 50 + centered(index, 25), y: 23 };
        if (group === 'past') return { x: 50 + centered(index, 24), y: 78 };
        if (group === 'input') return { x: 18, y: 50 + centered(index, 21) };
        if (group === 'action') return { x: 82, y: 50 + centered(index, 21) };
        return { x: 50 + centered(index, 23), y: 70 };
    });
}

export function mobilePersonalizedSatellitePosition(
    node,
    viewport = { width: 390, height: 700 },
) {
    const width = Math.max(1, Number(viewport?.width || 390));
    const height = Math.max(1, Number(viewport?.height || 700));
    const role = String(node?.positionRole || '');
    const sourceX = Number(node?.x ?? 50);
    const sourceY = Number(node?.y ?? 50);
    const percentToPixels = (percent) => Math.max(
        0,
        Math.min(width * 0.42, width * Math.max(0, Number(percent || 0)) / 100),
    );
    const xPercent = (pixels) => (Number(pixels) / width) * 100;
    const yPercent = (pixels) => (Number(pixels) / height) * 100;

    if (role === 'satellite-1') {
        const radius = percentToPixels(50 - sourceY);
        return { x: 50, y: Math.round((50 - yPercent(radius)) * 10) / 10 };
    }

    if (role === 'satellite-2') {
        const radius = percentToPixels(sourceX - 50);
        return { x: Math.round((50 + xPercent(radius)) * 10) / 10, y: 50 };
    }

    if (role === 'satellite-3') {
        const radius = percentToPixels(sourceY - 50);
        return { x: 50, y: Math.round((50 + yPercent(radius)) * 10) / 10 };
    }

    if (role === 'satellite-4') {
        const radius = percentToPixels(50 - sourceX);
        return { x: Math.round((50 - xPercent(radius)) * 10) / 10, y: 50 };
    }

    return null;
}

function nowSlot(selectedRole, { mobile = false } = {}) {
    const selectedGroup = roleGroup(selectedRole);

    if (mobile) {
        if (selectedGroup === 'action') return { x: 20, y: 34 };
        if (selectedGroup === 'past') return { x: 50, y: 15 };
        if (selectedGroup === 'input') return { x: 80, y: 34 };
        if (selectedGroup === 'future') return { x: 50, y: 59 };
        return { x: 50, y: 59 };
    }

    if (selectedGroup === 'action') return { x: 20, y: 50 };
    if (selectedGroup === 'past') return { x: 50, y: 22 };
    if (selectedGroup === 'input') return { x: 80, y: 50 };
    if (selectedGroup === 'future') return { x: 50, y: 76 };
    return { x: 50, y: 72 };
}

export function buildFocusLayout(nodes, edges, selectedId, { mobile = false } = {}) {
    const selected = nodes.find((node) => node.id === selectedId);
    if (!selected) return null;

    const visibleIds = oneHopNodeIds(edges, selectedId);
    const visibleNodes = nodes.filter((node) => visibleIds.has(node.id));
    const neighbors = visibleNodes.filter((node) => node.id !== selectedId);
    const groups = new Map();

    for (const node of neighbors) {
        const group = roleGroup(node.positionRole);
        if (!groups.has(group)) groups.set(group, []);
        groups.get(group).push(node);
    }

    const positions = new Map([[selectedId, mobile ? { x: 50, y: 34 } : { x: 50, y: 50 }]]);

    for (const [group, groupNodes] of groups.entries()) {
        if (group === 'now') {
            const base = nowSlot(selected.positionRole, { mobile });
            groupNodes.forEach((node, index) => {
                positions.set(node.id, {
                    x: Math.max(14, Math.min(86, base.x + (index - (groupNodes.length - 1) / 2) * 18)),
                    y: Math.max(16, Math.min(84, base.y)),
                });
            });
            continue;
        }

        const available = slots(group, groupNodes.length, { mobile });
        groupNodes.forEach((node, index) => positions.set(node.id, available[index]));
    }

    return { selectedId, visibleIds, positions };
}


export function buildMobileBaseLayout(
    nodes,
    mapLevel = 'l0',
    viewport = { width: 390, height: 700 },
) {
    const positions = new Map(
        nodes.map((node) => [node.id, { x: Number(node.x ?? 50), y: Number(node.y ?? 50) }])
    );
    const width = Math.max(1, Number(viewport?.width || 390));
    const height = Math.max(1, Number(viewport?.height || 700));
    const xPercent = (pixels) => (Number(pixels) / width) * 100;
    const yPercent = (pixels) => (Number(pixels) / height) * 100;
    const roundedPoint = (x, y) => ({
        x: Math.round(x * 10) / 10,
        y: Math.round(y * 10) / 10,
    });

    if (mapLevel === 'l0') {
        const innerRadius = Math.min(width * 0.23, 104);
        const outerRadius = Math.min(width * 0.38, 164);
        const innerX = xPercent(innerRadius);
        const innerY = yPercent(innerRadius);
        const outerX = xPercent(outerRadius);
        const outerY = yPercent(outerRadius);
        const rolePositions = {
            'space-station': { x: 50, y: 50 },
            'intent-plan': roundedPoint(50 - innerX, 50 - innerY),
            'intent-execution': roundedPoint(50 + innerX, 50 - innerY),
            'intent-reflection': roundedPoint(50 + innerX, 50 + innerY),
            'intent-collaboration': roundedPoint(50 - innerX, 50 + innerY),
            'satellite-1': roundedPoint(50, 50 - outerY),
            'satellite-2': roundedPoint(50 + outerX, 50),
            'satellite-3': roundedPoint(50, 50 + outerY),
            'satellite-4': roundedPoint(50 - outerX, 50),
        };

        for (const node of nodes) {
            const personalized = mobilePersonalizedSatellitePosition(node, viewport);
            if (personalized) {
                positions.set(node.id, personalized);
                continue;
            }

            if (rolePositions[node.positionRole]) {
                positions.set(node.id, rolePositions[node.positionRole]);
            }
        }

        return positions;
    }

    if (mapLevel === 'l3') {
        const sideOffset = Math.min(width * 0.39, 152);
        const upperOffsetX = Math.min(width * 0.25, 98);
        const upperOffsetY = Math.min(height * 0.20, 132);
        const goalOffsetY = Math.min(height * 0.35, 220);
        const lowerOffsetY = Math.min(height * 0.28, 180);
        const sideX = xPercent(sideOffset);
        const upperX = xPercent(upperOffsetX);
        const upperY = yPercent(upperOffsetY);
        const goalY = yPercent(goalOffsetY);
        const lowerY = yPercent(lowerOffsetY);
        const sideY = yPercent(Math.min(height * 0.02, 14));
        const rolePositions = {
            'future-goal': roundedPoint(50 - upperX, 50 - upperY),
            'context-plan': { x: 50, y: 50 },
            'action-primary': roundedPoint(50 + upperX, 50 - upperY),
            'future-next': roundedPoint(50, 50 - goalY),
            'now': { x: 50, y: 50 },
            'input-inbox': roundedPoint(50 - sideX, 50 + sideY),
            'action-tool': roundedPoint(50 + sideX, 50 + sideY),
            'past-evidence': roundedPoint(50, 50 + lowerY),
        };

        for (const node of nodes) {
            const position = rolePositions[node.positionRole];
            if (position) positions.set(node.id, position);
        }

        return positions;
    }

    if (mapLevel === 'l1' || mapLevel === 'l2') {
        const parent = nodes.find((node) => node.positionRole === 'hierarchy-parent');
        const children = nodes.filter((node) => node.positionRole === 'hierarchy-child');

        if (parent) {
            positions.set(parent.id, { x: 50, y: 50 });
        }

        const count = children.length;
        if (count > 0) {
            const radiusPixels = Math.min(
                width * (count <= 4 ? 0.34 : 0.37),
                count <= 4 ? 145 : 158,
            );
            const radiusX = xPercent(radiusPixels);
            const radiusY = yPercent(radiusPixels);

            children.forEach((node, index) => {
                const angle = (-90 + (360 / count) * index) * Math.PI / 180;
                positions.set(node.id, roundedPoint(
                    50 + Math.cos(angle) * radiusX,
                    50 + Math.sin(angle) * radiusY,
                ));
            });
        }
    }

    return positions;
}

export function clampMapViewTransform(
    transform,
    viewport,
    {
        minScale = 0.68,
        maxScale = 2.2,
        overscrollRatio = 0.10,
        minPanRatio = 0.04,
    } = {},
) {
    const width = Math.max(1, Number(viewport?.width || 1));
    const height = Math.max(1, Number(viewport?.height || 1));
    const scale = Math.max(minScale, Math.min(maxScale, Number(transform?.scale || 1)));
    const extraX = scale >= 1
        ? ((scale - 1) * width) / 2 + width * overscrollRatio
        : width * minPanRatio;
    const extraY = scale >= 1
        ? ((scale - 1) * height) / 2 + height * overscrollRatio
        : height * minPanRatio;

    const round = (value) => Math.round(Number(value) * 10000) / 10000;

    return {
        x: round(Math.max(-extraX, Math.min(extraX, Number(transform?.x || 0)))),
        y: round(Math.max(-extraY, Math.min(extraY, Number(transform?.y || 0)))),
        scale: round(scale),
    };
}

export function zoomMapViewAt(
    transform,
    targetScale,
    focusPoint,
    viewport,
    options = {},
) {
    const width = Math.max(1, Number(viewport?.width || 1));
    const height = Math.max(1, Number(viewport?.height || 1));
    const current = clampMapViewTransform(transform, viewport, options);
    const target = clampMapViewTransform(
        { ...current, scale: targetScale },
        viewport,
        options,
    );

    const focusX = Number(focusPoint?.x ?? width / 2) - width / 2;
    const focusY = Number(focusPoint?.y ?? height / 2) - height / 2;
    const ratio = target.scale / current.scale;

    return clampMapViewTransform({
        x: focusX - ratio * (focusX - current.x),
        y: focusY - ratio * (focusY - current.y),
        scale: target.scale,
    }, viewport, options);
}

export function createFrameBatcher(
    flush,
    {
        requestFrame = globalThis.requestAnimationFrame?.bind(globalThis),
        cancelFrame = globalThis.cancelAnimationFrame?.bind(globalThis),
    } = {},
) {
    let frameId = null;
    let pendingValue;
    const request = typeof requestFrame === 'function'
        ? requestFrame
        : (callback) => globalThis.setTimeout(callback, 16);
    const cancel = typeof cancelFrame === 'function'
        ? cancelFrame
        : (id) => globalThis.clearTimeout(id);

    const run = () => {
        frameId = null;
        const value = pendingValue;
        pendingValue = undefined;
        flush(value);
    };

    return {
        schedule(value) {
            pendingValue = value;
            if (frameId !== null) return frameId;
            frameId = request(run);
            return frameId;
        },
        flushNow() {
            if (frameId !== null) {
                cancel(frameId);
                frameId = null;
            }

            if (pendingValue === undefined) return false;
            const value = pendingValue;
            pendingValue = undefined;
            flush(value);
            return true;
        },
        cancel() {
            if (frameId !== null) {
                cancel(frameId);
            }
            frameId = null;
            pendingValue = undefined;
        },
        pending() {
            return frameId !== null;
        },
    };
}

export function mapDockHistoryState(currentState = {}, dockId = 'space-station') {
    const nextState = { ...(currentState || {}) };
    delete nextState.canoviaMapFocus;
    nextState.canoviaMapDock = dockId;

    return nextState;
}

export function mapGlobalHomeHistoryState(currentState = {}) {
    const nextState = { ...(currentState || {}) };
    delete nextState.canoviaMapFocus;
    delete nextState.canoviaMapFocusDepth;
    delete nextState.canoviaMapDock;

    return nextState;
}

export function mapGlobalHomeResetRequest(requestedAt = Date.now()) {
    return {
        version: 1,
        requested_at: Math.max(0, Number(requestedAt || 0)),
    };
}

export function shouldApplyGlobalHomeReset(
    request,
    mapLevel,
    now = Date.now(),
    maxAgeMs = 10000,
) {
    if (String(mapLevel || '') !== 'l0') return false;
    if (!request || typeof request !== 'object' || Array.isArray(request)) return false;
    if (Number(request.version) !== 1) return false;

    const requestedAt = Number(request.requested_at || 0);
    const age = Number(now || 0) - requestedAt;

    return requestedAt > 0 && age >= 0 && age <= Math.max(0, Number(maxAgeMs || 0));
}

export function mapHistoryDirection(currentDepth, targetDepth) {
    const current = Math.max(0, Number(currentDepth || 0));
    const target = Math.max(0, Number(targetDepth || 0));
    if (target < current) return 'back';
    if (target > current) return 'forward';
    return 'same';
}

export function mapActionTelemetryContext(action, focusedNode = null) {
    const actionData = action?.dataset || {};
    const focusedData = focusedNode?.dataset || {};

    return {
        action_role: actionData.mapActionRole || 'secondary',
        node_type: actionData.mapNodeType || focusedData.mapNodeType || null,
        position_role: actionData.mapPositionRole || focusedData.mapPositionRole || null,
        is_primary: actionData.mapIsPrimary !== undefined
            ? actionData.mapIsPrimary === '1'
            : focusedData.mapIsPrimary === '1',
    };
}

export function mapReturnDecision({
    persisted = false,
    currentProjectionKey = '',
    previousProjectionKey = '',
} = {}) {
    if (!previousProjectionKey) return 'none';
    if (persisted) return 'revalidate';
    if (currentProjectionKey && currentProjectionKey !== previousProjectionKey) return 'updated';
    return 'clear';
}

function focusIdFromLocation(windowRef) {
    const hash = String(windowRef.location.hash || '');
    if (!hash.startsWith('#focus=')) return null;

    try {
        return decodeURIComponent(hash.slice('#focus='.length));
    } catch (_) {
        return null;
    }
}

function focusHash(nodeId) {
    return '#focus=' + encodeURIComponent(nodeId);
}

function dockIdFromLocation(windowRef) {
    const hash = String(windowRef.location.hash || '');
    if (!hash.startsWith('#dock=')) return null;

    try {
        return decodeURIComponent(hash.slice('#dock='.length));
    } catch (_) {
        return null;
    }
}

function dockHash(dockId) {
    return '#dock=' + encodeURIComponent(dockId);
}

export function mapSemanticZoomDirection(value = 'in') {
    return value === 'out' ? 'out' : 'in';
}

export function semanticZoomThresholdDirection(
    scale,
    {
        inThreshold = 1.42,
        outThreshold = 0.70,
    } = {},
) {
    const value = Number(scale || 1);
    const zoomIn = Math.max(1, Number(inThreshold || 1.42));
    const zoomOut = Math.min(1, Number(outThreshold || 0.70));

    if (value >= zoomIn) return 'in';
    if (value <= zoomOut) return 'out';

    return null;
}

export function mapLodForScale(
    scale,
    {
        overviewMax = 0.90,
        contextMax = 1.16,
        detailMax = 1.34,
    } = {},
) {
    const value = Math.max(0.01, Number(scale || 1));

    if (value < overviewMax) return 'overview';
    if (value < contextMax) return 'context';
    if (value < detailMax) return 'detail';

    return 'ready';
}

export function mapNodeCounterScale(
    scale,
    {
        damping = 0.72,
        min = 0.62,
        max = 1.32,
    } = {},
) {
    const value = Math.max(0.01, Number(scale || 1));
    const safeDamping = Math.max(0, Math.min(1, Number(damping || 0)));
    const result = 1 / Math.pow(value, safeDamping);

    return Math.round(
        Math.max(Number(min || 0.62), Math.min(Number(max || 1.32), result)) * 1000
    ) / 1000;
}

export function documentEdgeBackDecision(
    gesture,
    {
        edgeWidth = 28,
        minDistance = 72,
        maxVertical = 56,
        maxScrollLeft = 1,
    } = {},
) {
    const startX = Number(gesture?.startX);
    const startY = Number(gesture?.startY);
    const endX = Number(gesture?.endX);
    const endY = Number(gesture?.endY);
    const scrollLeft = Math.max(0, Number(gesture?.scrollLeft || 0));

    if (![startX, startY, endX, endY, scrollLeft].every(Number.isFinite)) return false;
    if (startX < 0 || startX > Math.max(0, Number(edgeWidth || 28))) return false;
    if (scrollLeft > Math.max(0, Number(maxScrollLeft || 0))) return false;

    const dx = endX - startX;
    const dy = Math.abs(endY - startY);

    return dx >= Math.max(1, Number(minDistance || 72))
        && dy <= Math.max(1, Number(maxVertical || 56))
        && dx > dy * 1.25;
}

export function documentScrollMetrics({
    scrollLeft = 0,
    clientWidth = 0,
    scrollWidth = 0,
} = {}) {
    const viewport = Math.max(0, Number(clientWidth || 0));
    const content = Math.max(viewport, Number(scrollWidth || 0));

    if (content <= 0 || viewport <= 0) {
        return {
            progress: 0,
            viewportRatio: 1,
        };
    }

    const maxScroll = Math.max(0, content - viewport);
    const progress = maxScroll > 0
        ? Math.max(0, Math.min(1, Number(scrollLeft || 0) / maxScroll))
        : 0;
    const viewportRatio = Math.max(0.08, Math.min(1, viewport / content));

    return {
        progress: Math.round(progress * 10000) / 10000,
        viewportRatio: Math.round(viewportRatio * 10000) / 10000,
    };
}

export function semanticFocusMatchesNode(
    rect,
    focus,
    {
        padding = 28,
    } = {},
) {
    if (!rect || !focus) return false;

    const left = Number(rect.left);
    const top = Number(rect.top);
    const width = Number(rect.width);
    const height = Number(rect.height);
    const x = Number(focus.x);
    const y = Number(focus.y);
    const safePadding = Math.max(0, Number(padding || 0));

    if (![left, top, width, height, x, y].every(Number.isFinite)) {
        return false;
    }

    return x >= left - safePadding
        && x <= left + width + safePadding
        && y >= top - safePadding
        && y <= top + height + safePadding;
}

export function semanticArmDecision({
    scale = 1,
    hasCandidate = false,
    currentlyArmed = false,
    stableForMs = 0,
    enterScale = 1.34,
    exitScale = 1.28,
    dwellMs = 120,
} = {}) {
    if (!hasCandidate) return false;

    const value = Math.max(0.01, Number(scale || 1));
    const enter = Math.max(1, Number(enterScale || 1.34));
    const exit = Math.min(enter, Math.max(0.01, Number(exitScale || 1.28)));

    if (currentlyArmed) {
        return value >= exit;
    }

    return value >= enter
        && Math.max(0, Number(stableForMs || 0)) >= Math.max(0, Number(dwellMs || 0));
}

export function mapWorldPointAtScreen(
    transform,
    screenPoint,
    viewport,
    options = {},
) {
    const width = Math.max(1, Number(viewport?.width || 1));
    const height = Math.max(1, Number(viewport?.height || 1));
    const current = clampMapViewTransform(transform, viewport, options);
    const screenX = Number(screenPoint?.x ?? width / 2) - width / 2;
    const screenY = Number(screenPoint?.y ?? height / 2) - height / 2;

    return {
        x: Math.round(((screenX - current.x) / current.scale) * 10000) / 10000,
        y: Math.round(((screenY - current.y) / current.scale) * 10000) / 10000,
    };
}

export function mapViewForWorldAnchor(
    worldPoint,
    targetScale,
    screenPoint,
    viewport,
    options = {},
) {
    const width = Math.max(1, Number(viewport?.width || 1));
    const height = Math.max(1, Number(viewport?.height || 1));
    const target = clampMapViewTransform(
        { x: 0, y: 0, scale: targetScale },
        viewport,
        options,
    );
    const screenX = Number(screenPoint?.x ?? width / 2) - width / 2;
    const screenY = Number(screenPoint?.y ?? height / 2) - height / 2;
    const worldX = Number(worldPoint?.x || 0);
    const worldY = Number(worldPoint?.y || 0);

    return clampMapViewTransform({
        x: screenX - worldX * target.scale,
        y: screenY - worldY * target.scale,
        scale: target.scale,
    }, viewport, options);
}

export function resolveNodeCollisions(
    items,
    viewport,
    {
        padding = 16,
        iterations = 5,
        boundsPadding = 6,
    } = {},
) {
    const width = Math.max(1, Number(viewport?.width || 1));
    const height = Math.max(1, Number(viewport?.height || 1));
    const safePadding = Math.max(0, Number(padding || 0));
    const safeBounds = Math.max(0, Number(boundsPadding || 0));
    const list = Array.isArray(items) ? items : [];

    const nodes = list
        .map((item) => ({
            id: String(item?.id || ''),
            x: (Number(item?.x || 0) / 100) * width,
            y: (Number(item?.y || 0) / 100) * height,
            width: Math.max(1, Number(item?.width || 1)),
            height: Math.max(1, Number(item?.height || 1)),
            locked: Boolean(item?.locked),
        }))
        .filter((item) => item.id !== '' && [
            item.x,
            item.y,
            item.width,
            item.height,
        ].every(Number.isFinite));

    const clampNode = (node) => {
        if (node.locked) return;

        const halfWidth = node.width / 2;
        const halfHeight = node.height / 2;
        const minX = Math.min(width / 2, halfWidth + safeBounds);
        const maxX = Math.max(width / 2, width - halfWidth - safeBounds);
        const minY = Math.min(height / 2, halfHeight + safeBounds);
        const maxY = Math.max(height / 2, height - halfHeight - safeBounds);

        node.x = Math.max(minX, Math.min(maxX, node.x));
        node.y = Math.max(minY, Math.min(maxY, node.y));
    };

    nodes.forEach(clampNode);

    const totalIterations = Math.max(1, Math.min(12, Number(iterations || 1)));

    for (let iteration = 0; iteration < totalIterations; iteration += 1) {
        let moved = false;

        for (let leftIndex = 0; leftIndex < nodes.length; leftIndex += 1) {
            for (let rightIndex = leftIndex + 1; rightIndex < nodes.length; rightIndex += 1) {
                const left = nodes[leftIndex];
                const right = nodes[rightIndex];

                const dx = right.x - left.x;
                const dy = right.y - left.y;
                const overlapX = (left.width + right.width) / 2 + safePadding - Math.abs(dx);
                const overlapY = (left.height + right.height) / 2 + safePadding - Math.abs(dy);

                if (overlapX <= 0 || overlapY <= 0 || (left.locked && right.locked)) {
                    continue;
                }

                const moveAlongX = overlapX < overlapY;
                const delta = moveAlongX ? overlapX : overlapY;
                const rawDirection = moveAlongX ? dx : dy;
                const fallbackDirection = left.id.localeCompare(right.id) <= 0 ? 1 : -1;
                const direction = rawDirection === 0 ? fallbackDirection : Math.sign(rawDirection);

                if (moveAlongX) {
                    if (left.locked) {
                        right.x += direction * delta;
                    } else if (right.locked) {
                        left.x -= direction * delta;
                    } else {
                        left.x -= direction * delta / 2;
                        right.x += direction * delta / 2;
                    }
                } else if (left.locked) {
                    right.y += direction * delta;
                } else if (right.locked) {
                    left.y -= direction * delta;
                } else {
                    left.y -= direction * delta / 2;
                    right.y += direction * delta / 2;
                }

                clampNode(left);
                clampNode(right);
                moved = true;
            }
        }

        if (!moved) break;
    }

    return Object.fromEntries(nodes.map((node) => [
        node.id,
        {
            x: Math.round((node.x / width) * 1000) / 10,
            y: Math.round((node.y / height) * 1000) / 10,
        },
    ]));
}

export function semanticZoomDestination(
    direction,
    {
        parentUrl = '',
        candidateUrl = '',
    } = {},
) {
    const normalizedDirection = mapSemanticZoomDirection(direction);
    const parent = String(parentUrl || '').trim();
    const candidate = String(candidateUrl || '').trim();

    if (normalizedDirection === 'out' && parent !== '') {
        return parent;
    }

    return candidate;
}

export function browserZoomDiverged(
    currentDevicePixelRatio,
    baselineDevicePixelRatio,
    tolerance = 0.08,
) {
    const current = Number(currentDevicePixelRatio);
    const baseline = Number(baselineDevicePixelRatio);
    const safeTolerance = Math.max(0, Number(tolerance || 0));

    if (
        !Number.isFinite(current)
        || !Number.isFinite(baseline)
        || current <= 0
        || baseline <= 0
    ) {
        return false;
    }

    return Math.abs(current / baseline - 1) > safeTolerance;
}

export function shouldCaptureMapPinch({
    ctrlKey = false,
    cancelable = true,
    mapActive = true,
    browserZoomChanged = false,
} = {}) {
    return Boolean(
        ctrlKey
        && cancelable
        && mapActive
        && !browserZoomChanged
    );
}

export function semanticMapPositionSnapshot(position = {}) {
    const x = Number(position?.x);
    const y = Number(position?.y);

    if (!Number.isFinite(x) || !Number.isFinite(y)) {
        return null;
    }

    return {
        x: Math.round(x * 10) / 10,
        y: Math.round(y * 10) / 10,
    };
}

export function semanticExpansionOffset(sourcePosition, targetPosition) {
    const source = semanticMapPositionSnapshot(sourcePosition);
    const target = semanticMapPositionSnapshot(targetPosition);

    if (!source || !target) {
        return { x: 0, y: 0 };
    }

    return {
        x: Math.round((source.x - target.x) * 10) / 10,
        y: Math.round((source.y - target.y) * 10) / 10,
    };
}

export function semanticExpansionFactor({
    mobile = false,
    viewportHeight = 900,
} = {}) {
    if (mobile) return 0.72;

    const height = Math.max(1, Number(viewportHeight || 900));

    return height <= 720 ? 0.84 : 0.80;
}

export function semanticExpandedPosition(
    sourcePosition,
    targetCenterPosition,
    nodePosition,
    {
        factor = 0.62,
    } = {},
) {
    const source = semanticMapPositionSnapshot(sourcePosition);
    const center = semanticMapPositionSnapshot(targetCenterPosition);
    const node = semanticMapPositionSnapshot(nodePosition);
    const safeFactor = Math.max(0.35, Math.min(1, Number(factor || 0.62)));

    if (!source || !center || !node) {
        return node || source || center || { x: 50, y: 50 };
    }

    return {
        x: Math.round((source.x + (node.x - center.x) * safeFactor) * 10) / 10,
        y: Math.round((source.y + (node.y - center.y) * safeFactor) * 10) / 10,
    };
}

export function semanticCameraSnapshot(view = {}) {
    const x = Number(view?.x);
    const y = Number(view?.y);
    const scale = Number(view?.scale);

    if (!Number.isFinite(x) || !Number.isFinite(y) || !Number.isFinite(scale)) {
        return null;
    }

    return {
        x: Math.round(x * 10) / 10,
        y: Math.round(y * 10) / 10,
        scale: Math.round(Math.max(0.68, Math.min(2.2, scale)) * 1000) / 1000,
    };
}

export function semanticChildOrigin(anchorRect, childRect) {
    if (!anchorRect || !childRect) {
        return { x: 0, y: 0 };
    }

    const anchorX = Number(anchorRect.left || 0) + Number(anchorRect.width || 0) / 2;
    const anchorY = Number(anchorRect.top || 0) + Number(anchorRect.height || 0) / 2;
    const childX = Number(childRect.left || 0) + Number(childRect.width || 0) / 2;
    const childY = Number(childRect.top || 0) + Number(childRect.height || 0) / 2;

    return {
        x: Math.round((anchorX - childX) * 10) / 10,
        y: Math.round((anchorY - childY) * 10) / 10,
    };
}

export function semanticRouteKey(value, base = 'https://canovia.local') {
    try {
        const url = new URL(String(value || ''), base);
        const params = [...url.searchParams.entries()]
            .sort(([leftKey, leftValue], [rightKey, rightValue]) => {
                const keyOrder = leftKey.localeCompare(rightKey);
                return keyOrder !== 0 ? keyOrder : leftValue.localeCompare(rightValue);
            });
        const query = new URLSearchParams(params).toString();

        return url.pathname + (query ? '?' + query : '');
    } catch (_) {
        return '';
    }
}

export function semanticRectSnapshot(rect, viewport = {}) {
    if (!rect) return null;

    const width = Math.max(0, Number(rect.width || 0));
    const height = Math.max(0, Number(rect.height || 0));
    const viewportWidth = Math.max(1, Number(viewport.width || 0));
    const viewportHeight = Math.max(1, Number(viewport.height || 0));

    if (width < 1 || height < 1 || viewportWidth < 1 || viewportHeight < 1) {
        return null;
    }

    const left = Number(rect.left || 0);
    const top = Number(rect.top || 0);

    return {
        left: Math.round(left * 10) / 10,
        top: Math.round(top * 10) / 10,
        width: Math.round(width * 10) / 10,
        height: Math.round(height * 10) / 10,
        viewport_width: Math.round(viewportWidth * 10) / 10,
        viewport_height: Math.round(viewportHeight * 10) / 10,
    };
}

export function adaptSemanticRect(rect, viewport = {}) {
    if (!rect) return null;

    const sourceWidth = Math.max(1, Number(rect.viewport_width || viewport.width || 1));
    const sourceHeight = Math.max(1, Number(rect.viewport_height || viewport.height || 1));
    const targetWidth = Math.max(1, Number(viewport.width || sourceWidth));
    const targetHeight = Math.max(1, Number(viewport.height || sourceHeight));
    const ratioX = targetWidth / sourceWidth;
    const ratioY = targetHeight / sourceHeight;

    return {
        left: Number(rect.left || 0) * ratioX,
        top: Number(rect.top || 0) * ratioY,
        width: Math.max(1, Number(rect.width || 1) * ratioX),
        height: Math.max(1, Number(rect.height || 1) * ratioY),
    };
}

export function semanticContinuityTransform(sourceRect, targetRect) {
    if (!sourceRect || !targetRect) return null;

    const sourceWidth = Math.max(1, Number(sourceRect.width || 0));
    const sourceHeight = Math.max(1, Number(sourceRect.height || 0));
    const targetWidth = Math.max(1, Number(targetRect.width || 0));
    const targetHeight = Math.max(1, Number(targetRect.height || 0));

    const sourceCenterX = Number(sourceRect.left || 0) + sourceWidth / 2;
    const sourceCenterY = Number(sourceRect.top || 0) + sourceHeight / 2;
    const targetCenterX = Number(targetRect.left || 0) + targetWidth / 2;
    const targetCenterY = Number(targetRect.top || 0) + targetHeight / 2;
    const areaRatio = (sourceWidth * sourceHeight) / (targetWidth * targetHeight);
    const scale = Math.max(0.52, Math.min(1.9, Math.sqrt(Math.max(0.01, areaRatio))));

    const translationLimit = 2400;
    const translateX = Math.max(-translationLimit, Math.min(translationLimit, sourceCenterX - targetCenterX));
    const translateY = Math.max(-translationLimit, Math.min(translationLimit, sourceCenterY - targetCenterY));

    return {
        x: Math.round(translateX * 10) / 10,
        y: Math.round(translateY * 10) / 10,
        scale: Math.round(scale * 1000) / 1000,
    };
}

function writeSemanticTransition(windowRef, payload) {
    try {
        windowRef.sessionStorage?.setItem(SEMANTIC_TRANSITION_KEY, JSON.stringify(payload));
    } catch (_) {}
}

function readSemanticTransition(windowRef) {
    try {
        const raw = windowRef.sessionStorage?.getItem(SEMANTIC_TRANSITION_KEY);
        if (!raw) return null;
        const parsed = JSON.parse(raw);
        return parsed && typeof parsed === 'object' ? parsed : null;
    } catch (_) {
        return null;
    }
}

function clearSemanticTransition(windowRef) {
    try {
        windowRef.sessionStorage?.removeItem(SEMANTIC_TRANSITION_KEY);
    } catch (_) {}
}

export function requestGlobalHomeReset(windowRef) {
    try {
        windowRef.sessionStorage?.setItem(
            GLOBAL_HOME_RESET_KEY,
            JSON.stringify(mapGlobalHomeResetRequest(Date.now())),
        );
    } catch (_) {}
}

function consumeGlobalHomeReset(windowRef, mapLevel) {
    let request = null;

    try {
        const raw = windowRef.sessionStorage?.getItem(GLOBAL_HOME_RESET_KEY);
        request = raw ? JSON.parse(raw) : null;
    } catch (_) {
        request = null;
    }

    const now = Date.now();
    const fresh = shouldApplyGlobalHomeReset(request, 'l0', now);
    const apply = String(mapLevel || '') === 'l0' && fresh;

    // A fresh reset request must survive any intermediate deep-map remount.
    // Consume it only when L0 actually mounts; stale/invalid payloads are safe to drop.
    if (String(mapLevel || '') === 'l0' || (request && !fresh)) {
        try {
            windowRef.sessionStorage?.removeItem(GLOBAL_HOME_RESET_KEY);
        } catch (_) {}
    }

    return apply;
}

function mapUrlWithoutFocus(windowRef) {
    return windowRef.location.pathname + windowRef.location.search;
}

function readPending(windowRef) {
    try {
        const raw = windowRef.sessionStorage?.getItem(PENDING_REEVALUATION_KEY);
        if (!raw) return null;
        const parsed = JSON.parse(raw);
        return parsed && typeof parsed === 'object' ? parsed : null;
    } catch (_) {
        return null;
    }
}

function writePending(windowRef, payload) {
    try {
        windowRef.sessionStorage?.setItem(PENDING_REEVALUATION_KEY, JSON.stringify(payload));
    } catch (_) {}
}

function clearPending(windowRef) {
    try {
        windowRef.sessionStorage?.removeItem(PENDING_REEVALUATION_KEY);
    } catch (_) {}
}

export function mountLivingGoalMap({
    documentRef = globalThis.document,
    windowRef = globalThis.window,
    fetchRef = globalThis.fetch,
    recordBehaviorRef = null,
} = {}) {
    const page = documentRef?.querySelector?.('[data-canovia-map-page]');
    if (!page || !windowRef) return null;
    if (page.dataset.mapFocusInitialized === '1') return null;
    page.dataset.mapFocusInitialized = '1';

    const workspace = page.querySelector('[data-map-workspace]');
    const surface = page.querySelector('[data-map-context-surface]');
    const surfaceContent = page.querySelector('[data-map-context-content]');
    const resetButton = page.querySelector('[data-map-focus-reset]');
    const expandButton = page.querySelector('[data-map-context-expand]');
    const expandLabel = page.querySelector('[data-map-context-expand-label]');
    const updateStatus = page.querySelector('[data-map-update-status]');
    const mapShell = page.querySelector('[data-canovia-map]');
    const mapScene = page.querySelector('[data-map-scene]');
    const zoomOutControl = page.querySelector('[data-map-zoom-out]');
    const zoomInControl = page.querySelector('[data-map-zoom-in]');
    const viewResetControl = page.querySelector('[data-map-view-reset]');
    const nodeElements = [...page.querySelectorAll('[data-map-node]')];
    const edgeElements = [...page.querySelectorAll('[data-map-edge]')];
    const templates = [...page.querySelectorAll('[data-map-surface-template]')];
    const globalTemplates = [...page.querySelectorAll('[data-map-global-surface-template]')];
    const spatialDock = page.querySelector('[data-map-spatial-dock]');
    const documentScrolls = [...page.querySelectorAll('[data-map-document-scroll]')];
    const planDocumentScroll = page.querySelector('[data-map-plan-workspace] [data-map-document-scroll]');
    const detailDocumentScroll = surface?.querySelector?.('[data-map-document-scroll]') || null;
    const detailDocumentTitle = surface?.querySelector?.('[data-map-document-title]') || null;

    const nodes = nodeElements.map((element) => ({
        id: element.dataset.mapNodeId,
        nodeType: element.dataset.mapNodeType || '',
        positionRole: element.dataset.mapPositionRole || '',
        isPrimary: element.dataset.mapIsPrimary === '1',
        x: Number(element.dataset.mapX || 50),
        y: Number(element.dataset.mapY || 50),
    }));

    const edges = edgeElements.map((element) => ({
        source: element.dataset.mapEdgeSource,
        target: element.dataset.mapEdgeTarget,
        relation: element.dataset.mapEdgeRelation || '',
        element,
        renderedVisibility: null,
        renderedGeometry: '',
    }));

    const nodeElementById = new Map(nodeElements.map((element) => [element.dataset.mapNodeId, element]));
    const templateByNodeId = new Map(
        templates.map((template) => [template.dataset.mapSurfaceTemplate, template])
    );
    const globalTemplateByDockId = new Map(
        globalTemplates.map((template) => [template.dataset.mapGlobalSurfaceTemplate, template])
    );
    const primaryNode = nodes.find((node) => node.isPrimary) || null;
    const telemetryStart = ensureMapTelemetryFlow(windowRef);
    const trackTelemetry = (eventType, extra = {}, advanceStep = false) => {
        if (typeof recordBehaviorRef !== 'function') return;

        const nowMs = Date.now();
        const flow = advanceStep
            ? advanceMapTelemetryFlow(windowRef, nowMs, 1)
            : ensureMapTelemetryFlow(windowRef, nowMs).flow;

        recordBehaviorRef(page, eventType, {
            metadata: mapTelemetryMetadata(flow, {
                surface: mapClientSurface(windowRef),
                device: mapClientDevice(windowRef),
                ...extra,
            }, nowMs),
        });
    };

    if (telemetryStart.isNew) {
        trackTelemetry('map_viewed');
    }

    let activeFocusId = null;
    let activeDockId = null;
    let focusHistoryDepth = Math.max(0, Number(windowRef.history.state?.canoviaMapFocusDepth || 0));
    let revalidationPromise = null;
    let updateTimer = null;
    let viewportTimer = null;
    let viewAnimationTimer = null;
    let semanticZoomTimer = null;
    let semanticZoomNavigating = false;
    let disposed = false;
    let basePositions = new Map(nodes.map((node) => [node.id, { x: node.x, y: node.y }]));

    const isMobileViewport = () => Boolean(windowRef.matchMedia?.('(max-width: 767px)')?.matches);
    const activePointers = new Map();
    let gesture = null;
    let suppressMapClickUntil = 0;
    let mapView = { x: 0, y: 0, scale: 1 };
    let semanticExpansionLayoutState = null;
    const browserZoomBaselineDpr = Math.max(0.1, Number(windowRef.devicePixelRatio || 1));
    let browserZoomChanged = false;
    let viewportCache = {
        left: 0,
        top: 0,
        width: Math.max(1, Number(mapShell?.clientWidth || 1)),
        height: Math.max(1, Number(mapShell?.clientHeight || 1)),
    };
    let renderedMapViewSignature = '';
    let renderedTransformed = null;
    let renderedControlSignature = '';
    let renderedLod = '';
    let semanticArmedNodeId = null;
    let semanticArmCandidateId = null;
    let semanticArmCandidateSince = 0;
    let documentBackGesture = null;

    const documentParentUrl = String(page.dataset.mapParentUrl || '');
    const planDocumentMode = Boolean(
        page.classList.contains('is-plan-document-mode')
        || page.querySelector?.('[data-map-plan-workspace][data-map-document-viewport]')
    );
    const standaloneDocumentNavigation = Boolean(
        windowRef.matchMedia?.('(display-mode: standalone)')?.matches
        || windowRef.navigator?.standalone === true
    );
    const documentModeActive = () => page.classList.contains('is-document-mode');
    const activeDocumentScroll = () => page.classList.contains('is-detail-document-mode')
        ? detailDocumentScroll
        : planDocumentScroll;

    const setDetailDocumentMode = (active) => {
        const detailActive = Boolean(active);
        page.classList.toggle('is-detail-document-mode', detailActive);
        page.classList.toggle('is-document-mode', planDocumentMode || detailActive);
        surface?.classList?.toggle('is-dashboard-document', detailActive);
    };

    const syncDocumentPosition = (scrollElement) => {
        if (!scrollElement) return;

        const viewport = scrollElement.closest?.('[data-map-document-viewport]');
        const indicator = viewport?.querySelector?.('[data-map-document-position]');
        if (!indicator) return;

        const metrics = documentScrollMetrics({
            scrollLeft: scrollElement.scrollLeft,
            clientWidth: scrollElement.clientWidth,
            scrollWidth: scrollElement.scrollWidth,
        });
        const thumbWidth = metrics.viewportRatio * 100;
        const thumbLeft = metrics.progress * (1 - metrics.viewportRatio) * 100;

        indicator.style.setProperty('--document-thumb-width', thumbWidth.toFixed(2)+'%');
        indicator.style.setProperty('--document-thumb-left', thumbLeft.toFixed(2)+'%');
        indicator.classList.toggle('is-static', metrics.viewportRatio >= 0.995);
    };

    const syncAllDocumentPositions = () => {
        for (const scrollElement of documentScrolls) {
            syncDocumentPosition(scrollElement);
        }
    };

    const resetDocumentScroll = (scrollElement) => {
        if (!scrollElement) return;

        if (typeof scrollElement.scrollTo === 'function') {
            scrollElement.scrollTo({ left: 0, top: 0, behavior: 'auto' });
        } else {
            scrollElement.scrollLeft = 0;
            scrollElement.scrollTop = 0;
        }

        syncDocumentPosition(scrollElement);
    };

    const syncDetailDocumentTitle = (nodeId) => {
        if (!detailDocumentTitle) return;

        const sourceTitle = surfaceContent?.querySelector?.('[data-map-document-title-source]');
        const nodeLabel = nodeElementById.get(nodeId)
            ?.querySelector?.('.canovia-map-node-label')
            ?.textContent;
        const title = String(sourceTitle?.textContent || nodeLabel || '詳細').trim();

        detailDocumentTitle.textContent = title || '詳細';
    };

    const onDocumentScroll = (event) => {
        syncDocumentPosition(event.currentTarget);
    };

    const refreshMapViewport = () => {
        const rect = mapShell?.getBoundingClientRect?.();

        viewportCache = {
            left: Number(rect?.left || 0),
            top: Number(rect?.top || 0),
            width: Math.max(1, Number(rect?.width || mapShell?.clientWidth || 1)),
            height: Math.max(1, Number(rect?.height || mapShell?.clientHeight || 1)),
        };

        return viewportCache;
    };

    const currentMapViewport = () => ({
        width: viewportCache.width,
        height: viewportCache.height,
    });

    const syncBrowserZoomState = () => {
        browserZoomChanged = browserZoomDiverged(
            windowRef.devicePixelRatio,
            browserZoomBaselineDpr,
        );

        page.dataset.mapBrowserZoom = browserZoomChanged ? 'external' : 'baseline';

        return browserZoomChanged;
    };

    const clearSemanticArm = ({ resetCandidate = true } = {}) => {
        if (semanticArmedNodeId) {
            nodeElementById.get(semanticArmedNodeId)?.classList.remove('is-semantic-armed');
        }

        semanticArmedNodeId = null;
        delete page.dataset.mapSemanticArmedNode;

        if (resetCandidate) {
            semanticArmCandidateId = null;
            semanticArmCandidateSince = 0;
        }
    };

    const syncMapLod = (view) => {
        const lod = mapLodForScale(view.scale);

        if (lod !== renderedLod) {
            renderedLod = lod;
            page.dataset.mapLod = lod;
        }

        const counterScale = isMobileViewport()
            ? 1
            : mapNodeCounterScale(view.scale);

        mapScene?.style?.setProperty?.('--map-node-counter-scale', String(counterScale));

        if (
            semanticArmedNodeId
            && !semanticArmDecision({
                scale: view.scale,
                hasCandidate: true,
                currentlyArmed: true,
            })
        ) {
            clearSemanticArm();
        }

        return lod;
    };

    const syncViewControls = (view) => {
        const atMinimum = view.scale <= 0.681;
        const atMaximum = view.scale >= 2.199;
        const changed = (
            Math.abs(view.x) > 0.5
            || Math.abs(view.y) > 0.5
            || Math.abs(view.scale - 1) > 0.005
        );
        const signature = [atMinimum ? 1 : 0, atMaximum ? 1 : 0, changed ? 1 : 0].join(':');

        if (signature === renderedControlSignature) return;
        renderedControlSignature = signature;

        if (zoomOutControl) zoomOutControl.disabled = atMinimum;
        if (zoomInControl) zoomInControl.disabled = atMaximum;
        if (viewResetControl) {
            viewResetControl.dataset.mapViewChanged = changed ? '1' : '0';
        }
    };

    const commitMapView = (payload) => {
        if (!payload || !mapScene || disposed) return;

        const view = payload.view;
        const animate = Boolean(payload.animate);
        const signature = [view.x, view.y, view.scale].join(':');

        if (animate) {
            windowRef.clearTimeout(viewAnimationTimer);
            mapShell?.classList.add('is-map-view-animating');
            viewAnimationTimer = windowRef.setTimeout(() => {
                mapShell?.classList.remove('is-map-view-animating');
            }, 210);
        } else if (mapShell?.classList.contains('is-map-view-animating')) {
            windowRef.clearTimeout(viewAnimationTimer);
            mapShell.classList.remove('is-map-view-animating');
        }

        if (signature !== renderedMapViewSignature) {
            renderedMapViewSignature = signature;
            mapScene.style.transform = 'translate3d('
                + view.x + 'px, '
                + view.y + 'px, 0) scale('
                + view.scale + ')';
        }

        const transformed = (
            Math.abs(view.x) > 0.5
            || Math.abs(view.y) > 0.5
            || Math.abs(view.scale - 1) > 0.005
        );

        if (renderedTransformed !== transformed) {
            renderedTransformed = transformed;
            mapShell?.classList.toggle('is-map-transformed', transformed);
        }

        syncMapLod(view);
        syncViewControls(view);
    };

    const mapViewBatcher = createFrameBatcher(commitMapView, {
        requestFrame: windowRef.requestAnimationFrame?.bind(windowRef),
        cancelFrame: windowRef.cancelAnimationFrame?.bind(windowRef),
    });

    const applyMapView = (next, { animate = false, immediate = false } = {}) => {
        if (!mapScene) return mapView;

        mapView = clampMapViewTransform(next, currentMapViewport());

        mapViewBatcher.schedule({
            view: { ...mapView },
            animate,
        });

        if (immediate) {
            mapViewBatcher.flushNow();
        }

        return mapView;
    };

    const resetMapView = ({ animate = true } = {}) => {
        return applyMapView({ x: 0, y: 0, scale: 1 }, { animate });
    };

    const zoomMapBy = (factor, focusPoint = null, { animate = true } = {}) => {
        const viewport = currentMapViewport();
        const point = focusPoint || {
            x: viewport.width / 2,
            y: viewport.height / 2,
        };
        const next = zoomMapViewAt(
            mapView,
            mapView.scale * factor,
            point,
            viewport,
        );

        return applyMapView(next, { animate });
    };

    const originalPosition = (nodeId) => {
        return basePositions.get(nodeId) || { x: 50, y: 50 };
    };

    const syncEdges = (positions, visibleIds = null, selectedId = null) => {
        for (const edge of edges) {
            const directlyRelated = !selectedId || edge.source === selectedId || edge.target === selectedId;
            const visible = !visibleIds
                || (visibleIds.has(edge.source) && visibleIds.has(edge.target) && directlyRelated);

            if (edge.renderedVisibility !== visible) {
                edge.renderedVisibility = visible;
                edge.element.classList.toggle('is-focus-hidden', !visible);
            }

            const source = positions.get(edge.source) || originalPosition(edge.source);
            const target = positions.get(edge.target) || originalPosition(edge.target);
            const geometry = [source.x, source.y, target.x, target.y].join(':');

            if (edge.renderedGeometry === geometry) continue;
            edge.renderedGeometry = geometry;
            edge.element.setAttribute('x1', String(source.x));
            edge.element.setAttribute('y1', String(source.y));
            edge.element.setAttribute('x2', String(target.x));
            edge.element.setAttribute('y2', String(target.y));
        }
    };


    const applyBaseLayout = () => {
        const mobile = isMobileViewport();
        basePositions = mobile
            ? buildMobileBaseLayout(
                nodes,
                page.dataset.mapLevel || 'l3',
                currentMapViewport(),
            )
            : new Map(nodes.map((node) => [node.id, { x: node.x, y: node.y }]));

        if (mobile) {
            basePositions = fitMobileNodeBoxes(basePositions, nodeElements.map(element => ({
                id: element.dataset.mapNodeId,
                width: element.offsetWidth,
                height: element.offsetHeight,
            })), currentMapViewport(), nodeElements.find(element => element.dataset.mapIsCenter === '1')?.dataset.mapNodeId);
        }

        if (semanticExpansionLayoutState) {
            const anchorPosition = basePositions.get(semanticExpansionLayoutState.anchorId);

            if (anchorPosition) {
                basePositions = new Map(
                    [...basePositions.entries()].map(([nodeId, position]) => [
                        nodeId,
                        semanticExpandedPosition(
                            semanticExpansionLayoutState.sourcePosition,
                            anchorPosition,
                            position,
                            {
                                factor: semanticExpansionLayoutState.factor,
                            },
                        ),
                    ]),
                );

                const counterScale = mobile ? 1 : mapNodeCounterScale(mapView.scale);
                const resolved = resolveNodeCollisions(
                    nodes.map((node) => {
                        const position = basePositions.get(node.id) || { x: node.x, y: node.y };
                        const element = nodeElementById.get(node.id);

                        return {
                            id: node.id,
                            x: position.x,
                            y: position.y,
                            width: Math.max(1, Number(element?.offsetWidth || 1) * counterScale),
                            height: Math.max(1, Number(element?.offsetHeight || 1) * counterScale),
                            locked: node.id === semanticExpansionLayoutState.anchorId,
                        };
                    }),
                    currentMapViewport(),
                    {
                        padding: mobile ? 10 : 18,
                        iterations: 6,
                        boundsPadding: mobile ? 4 : 8,
                    },
                );

                basePositions = new Map(
                    [...basePositions.entries()].map(([nodeId, position]) => [
                        nodeId,
                        resolved[nodeId] || position,
                    ]),
                );
            }
        }

        const spatialLayout = mobile ? 'mobile' : 'desktop';
        if (page.dataset.mapSpatialLayout !== spatialLayout) {
            page.dataset.mapSpatialLayout = spatialLayout;
        }

        for (const node of nodes) {
            const position = basePositions.get(node.id) || { x: node.x, y: node.y };
            const signature = position.x + ':' + position.y;
            if (node.renderedBasePosition === signature) continue;

            node.renderedBasePosition = signature;
            const element = nodeElementById.get(node.id);
            element?.style.setProperty('--map-x', String(position.x) + '%');
            element?.style.setProperty('--map-y', String(position.y) + '%');
        }

        if (!activeFocusId) {
            syncEdges(basePositions);
        }

        return basePositions;
    };

    const templateFor = (nodeId) => templateByNodeId.get(nodeId);
    const globalTemplateFor = (dockId) => globalTemplateByDockId.get(dockId);

    const showUpdatedStatus = () => {
        if (disposed) return;

        updateStatus?.classList.remove('hidden');
        mapShell?.classList.add('is-reprojected');

        windowRef.clearTimeout(updateTimer);
        updateTimer = windowRef.setTimeout(() => {
            updateStatus?.classList.add('hidden');
            mapShell?.classList.remove('is-reprojected');
        }, 2600);
    };

    const setSurfaceExpanded = (expanded) => {
        const isExpanded = Boolean(expanded);
        surface?.classList.toggle('is-expanded', isExpanded);
        expandButton?.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
        if (expandLabel) {
            expandLabel.textContent = isExpanded ? '縮める' : '広げる';
        }
    };

    const renderSurface = (nodeId) => {
        if (!surface || !surfaceContent || !workspace) return;

        const template = templateFor(nodeId);
        surfaceContent.replaceChildren();

        if (template?.content) {
            surfaceContent.append(template.content.cloneNode(true));
        }

        const presentationKind = nodeElementById.get(nodeId)?.dataset?.mapPresentationKind || '';
        const isLeafDocument = presentationKind === 'leaf';

        syncDetailDocumentTitle(nodeId);
        setDetailDocumentMode(isLeafDocument);
        resetDocumentScroll(detailDocumentScroll);
        windowRef.setTimeout(() => {
            if (!disposed) syncDocumentPosition(detailDocumentScroll);
        }, 0);

        setSurfaceExpanded(false);
        surface.setAttribute('aria-hidden', 'false');
        workspace.classList.add('is-context-open');
        resetButton?.classList.remove('hidden');
    };

    const renderGlobalSurface = (dockId) => {
        if (!surface || !surfaceContent || !workspace) return false;

        const template = globalTemplateFor(dockId);
        if (!template?.content) return false;

        surfaceContent.replaceChildren();
        surfaceContent.append(template.content.cloneNode(true));
        syncDetailDocumentTitle(null);
        setDetailDocumentMode(false);
        resetDocumentScroll(detailDocumentScroll);
        setSurfaceExpanded(false);
        surface.setAttribute('aria-hidden', 'false');
        workspace.classList.add('is-context-open', 'is-spatial-dock-open');
        spatialDock?.setAttribute('aria-expanded', 'true');
        resetButton?.classList.remove('hidden');

        return true;
    };

    const hideSurface = () => {
        if (surface && workspace) {
            setSurfaceExpanded(false);
            surface.setAttribute('aria-hidden', 'true');
            workspace.classList.remove('is-context-open', 'is-spatial-dock-open');
        }
        surfaceContent?.replaceChildren();
        setDetailDocumentMode(false);
        if (detailDocumentTitle) detailDocumentTitle.textContent = '詳細';
        resetButton?.classList.add('hidden');
    };

    const clearSpatialDock = () => {
        activeDockId = null;
        page.dataset.mapDock = '';
        spatialDock?.setAttribute('aria-expanded', 'false');
        hideSurface();
    };

    const clearFocus = () => {
        activeFocusId = null;
        page.dataset.mapFocus = '';
        page.classList.remove('is-map-focused', 'is-map-mobile-selection');

        const positions = applyBaseLayout();
        for (const node of nodes) {
            const element = nodeElementById.get(node.id);
            element?.classList.remove('is-focus-center', 'is-focus-neighbor', 'is-focus-hidden', 'is-map-selected');
            element?.removeAttribute('aria-selected');
            element?.removeAttribute('aria-expanded');
            element?.style.removeProperty('--map-focus-x');
            element?.style.removeProperty('--map-focus-y');
        }

        syncEdges(positions);

        hideSurface();
    };

    const removeInvalidFocusHash = () => {
        if (!focusIdFromLocation(windowRef)) return;
        const nextState = { ...(windowRef.history.state || {}) };
        delete nextState.canoviaMapFocus;
        delete nextState.canoviaMapFocusDepth;
        focusHistoryDepth = 0;
        windowRef.history.replaceState(nextState, '', mapUrlWithoutFocus(windowRef));
    };

    const openFocus = (nodeId, { historyMode = 'push' } = {}) => {
        if (activeDockId) {
            clearSpatialDock();
        }

        const mobile = isMobileViewport();
        const previousFocusId = activeFocusId;
        const hadFocus = Boolean(previousFocusId);

        if (mobile) {
            if (
                previousFocusId === nodeId
                && page.classList.contains('is-map-mobile-selection')
                && workspace?.classList.contains('is-context-open')
            ) {
                return true;
            }

            const alreadyMobileSelection = page.classList.contains('is-map-mobile-selection');
            activeFocusId = nodeId;
            page.dataset.mapFocus = nodeId;
            page.classList.add('is-map-focused', 'is-map-mobile-selection');

            if (alreadyMobileSelection) {
                if (previousFocusId && previousFocusId !== nodeId) {
                    const previousElement = nodeElementById.get(previousFocusId);
                    previousElement?.classList.remove('is-map-selected');
                    previousElement?.setAttribute('aria-selected', 'false');
                    previousElement?.setAttribute('aria-expanded', 'false');
                }

                const selectedElement = nodeElementById.get(nodeId);
                selectedElement?.classList.add('is-map-selected');
                selectedElement?.setAttribute('aria-selected', 'true');
                selectedElement?.setAttribute('aria-expanded', 'true');
            } else {
                for (const node of nodes) {
                    const element = nodeElementById.get(node.id);
                    if (!element) continue;

                    const selected = node.id === nodeId;
                    element.classList.remove('is-focus-center', 'is-focus-neighbor', 'is-focus-hidden');
                    element.classList.toggle('is-map-selected', selected);
                    element.setAttribute('aria-selected', selected ? 'true' : 'false');
                    element.setAttribute('aria-expanded', selected ? 'true' : 'false');
                    element.style.removeProperty('--map-focus-x');
                    element.style.removeProperty('--map-focus-y');
                }

                syncEdges(basePositions);
            }

            renderSurface(nodeId);

            if (historyMode === 'push' && windowRef.location.hash !== focusHash(nodeId)) {
                if (hadFocus && windowRef.history.state?.canoviaMapFocus) {
                    windowRef.history.replaceState({
                        ...(windowRef.history.state || {}),
                        canoviaMapFocus: nodeId,
                        canoviaMapFocusDepth: focusHistoryDepth,
                    }, '', focusHash(nodeId));
                } else {
                    focusHistoryDepth += 1;
                    windowRef.history.pushState({
                        canoviaMapFocus: nodeId,
                        canoviaMapFocusDepth: focusHistoryDepth,
                    }, '', focusHash(nodeId));
                }
            }

            return true;
        }

        const layout = buildFocusLayout(nodes, edges, nodeId, { mobile: false });
        if (!layout) return false;

        activeFocusId = nodeId;
        page.dataset.mapFocus = nodeId;
        page.classList.add('is-map-focused');
        page.classList.remove('is-map-mobile-selection');

        for (const node of nodes) {
            const element = nodeElementById.get(node.id);
            if (!element) continue;

            element.classList.remove('is-map-selected');
            const visible = layout.visibleIds.has(node.id);
            const selected = node.id === nodeId;
            const position = layout.positions.get(node.id) || { x: node.x, y: node.y };

            element.classList.toggle('is-focus-hidden', !visible);
            element.classList.toggle('is-focus-center', selected);
            element.classList.toggle('is-focus-neighbor', visible && !selected);
            element.setAttribute('aria-selected', selected ? 'true' : 'false');
            element.setAttribute('aria-expanded', selected ? 'true' : 'false');
            element.style.setProperty('--map-focus-x', position.x + '%');
            element.style.setProperty('--map-focus-y', position.y + '%');
        }

        syncEdges(layout.positions, layout.visibleIds, nodeId);
        renderSurface(nodeId);

        if (historyMode === 'push' && windowRef.location.hash !== focusHash(nodeId)) {
            if (hadFocus && windowRef.history.state?.canoviaMapFocus) {
                windowRef.history.replaceState({
                    ...(windowRef.history.state || {}),
                    canoviaMapFocus: nodeId,
                    canoviaMapFocusDepth: focusHistoryDepth,
                }, '', focusHash(nodeId));
            } else {
                focusHistoryDepth += 1;
                windowRef.history.pushState({
                    canoviaMapFocus: nodeId,
                    canoviaMapFocusDepth: focusHistoryDepth,
                }, '', focusHash(nodeId));
            }
        }

        return true;
    };

    const openSpatialDock = (dockId, { historyMode = 'push' } = {}) => {
        const normalizedDockId = String(dockId || '').trim();
        if (!normalizedDockId || !globalTemplateFor(normalizedDockId)) return false;

        if (activeFocusId) {
            clearFocus();
        }

        activeDockId = normalizedDockId;
        page.dataset.mapDock = normalizedDockId;

        if (!renderGlobalSurface(normalizedDockId)) {
            activeDockId = null;
            page.dataset.mapDock = '';
            return false;
        }

        if (historyMode === 'push' && windowRef.location.hash !== dockHash(normalizedDockId)) {
            const nextState = mapDockHistoryState(
                windowRef.history.state || {},
                normalizedDockId,
            );

            windowRef.history.pushState(
                nextState,
                '',
                dockHash(normalizedDockId),
            );
        }

        return true;
    };

    const removeInvalidDockHash = () => {
        if (!dockIdFromLocation(windowRef)) return;
        const nextState = { ...(windowRef.history.state || {}) };
        delete nextState.canoviaMapDock;
        windowRef.history.replaceState(nextState, '', mapUrlWithoutFocus(windowRef));
    };

    const resetGlobalHomeContext = () => {
        requestGlobalHomeReset(windowRef);
        clearSpatialDock();
        clearFocus();
        resetMapView({ animate: false });
        focusHistoryDepth = 0;

        windowRef.history.replaceState(
            mapGlobalHomeHistoryState(windowRef.history.state || {}),
            '',
            mapUrlWithoutFocus(windowRef),
        );
    };

    const closeContext = () => {
        if (activeDockId) {
            const shouldGoBack = Boolean(windowRef.history.state?.canoviaMapDock);
            clearSpatialDock();

            if (shouldGoBack) {
                windowRef.history.back();
                return;
            }

            removeInvalidDockHash();
            return;
        }

        if (activeFocusId) {
            const shouldGoBack = Boolean(windowRef.history.state?.canoviaMapFocus);
            clearFocus();

            if (shouldGoBack) {
                windowRef.history.back();
                return;
            }

            removeInvalidFocusHash();
            return;
        }

        hideSurface();
    };

    const markPendingReevaluation = (nodeId = activeFocusId) => {
        writePending(windowRef, {
            projectionKey: page.dataset.mapProjectionKey || '',
            sourceNodeId: nodeId || null,
            leftAt: Date.now(),
        });
    };

    const renderedNodePosition = (element) => {
        if (!element) return null;

        const styleX = Number.parseFloat(element.style?.getPropertyValue?.('--map-x') || '');
        const styleY = Number.parseFloat(element.style?.getPropertyValue?.('--map-y') || '');

        return semanticMapPositionSnapshot({
            x: Number.isFinite(styleX) ? styleX : Number(element.dataset?.mapX || 50),
            y: Number.isFinite(styleY) ? styleY : Number(element.dataset?.mapY || 50),
        });
    };

    const cloneSemanticContextLayer = (sourceNode) => {
        if (!mapScene || !sourceNode || !documentRef?.createElement) return null;

        const layer = documentRef.createElement('div');
        layer.className = 'canovia-map-semantic-context-layer';
        layer.setAttribute('data-map-semantic-context-layer', '');
        layer.setAttribute('aria-hidden', 'true');

        const edgeSvg = edgeElements[0]?.closest?.('.canovia-map-edges') || null;
        if (edgeSvg) {
            const edgeClone = edgeSvg.cloneNode(true);
            edgeClone.classList.add('canovia-map-semantic-context-edges');
            edgeClone.querySelectorAll?.('[data-map-edge]')?.forEach?.((edge) => {
                edge.removeAttribute('data-map-edge');
                edge.setAttribute('data-map-context-edge', '');
            });
            layer.append(edgeClone);
        }

        for (const element of nodeElements) {
            if (element === sourceNode || element.classList.contains('is-focus-hidden')) {
                continue;
            }

            const clone = element.cloneNode(true);
            clone.removeAttribute('data-map-node');
            clone.setAttribute('data-map-context-node', '');
            clone.classList.add('is-semantic-context-node');
            clone.classList.remove(
                'is-map-center',
                'is-map-selected',
                'is-focus-center',
                'is-focus-neighbor',
                'is-semantic-departure-anchor',
                'is-semantic-continuity-anchor',
            );
            clone.removeAttribute('aria-current');
            clone.removeAttribute('aria-selected');
            clone.removeAttribute('aria-expanded');

            clone.querySelectorAll?.('a, button, input, select, textarea')?.forEach?.((control) => {
                control.removeAttribute?.('href');
                control.removeAttribute?.('data-map-semantic-zoom');
                control.removeAttribute?.('data-map-direct-navigation');
                control.removeAttribute?.('data-map-node-focus');
                control.setAttribute?.('tabindex', '-1');
                control.setAttribute?.('aria-hidden', 'true');
                if ('disabled' in control) {
                    control.disabled = true;
                }
            });

            layer.append(clone);
        }

        return layer;
    };

    const rememberSemanticExpansionContext = (sourceNode) => {
        const sourcePosition = renderedNodePosition(sourceNode);
        const layer = cloneSemanticContextLayer(sourceNode);

        if (!sourcePosition || !layer) {
            windowRef[SEMANTIC_CONTEXT_WINDOW_KEY] = null;
            return null;
        }

        const snapshot = {
            createdAt: Date.now(),
            fromDepth: Number(page.dataset.mapHierarchyDepth || 0),
            sourceNodeRef: sourceNode.dataset?.mapNodeId || null,
            sourcePosition,
            layer,
        };

        windowRef[SEMANTIC_CONTEXT_WINDOW_KEY] = snapshot;

        return snapshot;
    };

    const consumeSemanticExpansionContext = (arrival) => {
        const snapshot = windowRef[SEMANTIC_CONTEXT_WINDOW_KEY] || null;
        windowRef[SEMANTIC_CONTEXT_WINDOW_KEY] = null;

        if (!snapshot || Date.now() - Number(snapshot.createdAt || 0) > 10000) {
            return null;
        }

        if (
            String(arrival?.source_node_ref || '') !== ''
            && String(snapshot.sourceNodeRef || '') !== String(arrival.source_node_ref || '')
        ) {
            return null;
        }

        return snapshot;
    };

    const shiftProjectionAroundSemanticAnchor = (sourcePosition) => {
        const anchor = page.querySelector?.('[data-map-node][data-map-is-center="1"]')
            || nodeElements[0]
            || null;
        const anchorId = anchor?.dataset?.mapNodeId || null;

        if (!anchor || !anchorId) {
            return null;
        }

        semanticExpansionLayoutState = {
            anchorId,
            sourcePosition: semanticMapPositionSnapshot(sourcePosition) || { x: 50, y: 50 },
            factor: semanticExpansionFactor({
                mobile: isMobileViewport(),
                viewportHeight: currentMapViewport().height,
            }),
        };
        applyBaseLayout();

        return anchor;
    };

    const prepareSemanticChildrenFromAnchor = (anchor) => {
        if (!anchor) return;

        const anchorRect = anchor.getBoundingClientRect?.();
        const scale = Math.max(0.68, Number(mapView.scale || 1));

        anchor.classList.add(
            'is-semantic-expansion-anchor',
            'is-semantic-expanded-parent',
        );

        for (const element of nodeElements) {
            if (element === anchor) continue;

            const childRect = element.getBoundingClientRect?.();
            const origin = semanticChildOrigin(anchorRect, childRect);

            element.style.setProperty('--semantic-child-origin-x', (origin.x / scale).toFixed(1) + 'px');
            element.style.setProperty('--semantic-child-origin-y', (origin.y / scale).toFixed(1) + 'px');
            element.classList.add(
                'is-semantic-expansion-child',
                'is-semantic-expanded-child',
            );
        }
    };

    const markSemanticTransition = (link) => {
        const semanticLink = link?.closest?.('[data-map-semantic-zoom]')
            ?? (link?.hasAttribute?.('data-map-semantic-zoom') ? link : null);
        if (!semanticLink) return false;

        if (page.dataset.mapSemanticTransitionMarked === '1') {
            return true;
        }

        const direction = mapSemanticZoomDirection(semanticLink.dataset.mapZoomDirection || 'in');
        const clickedNode = semanticLink.closest?.('[data-map-node]');
        const centerNode = page.querySelector?.('[data-map-node][data-map-is-center="1"]');
        const sourceNode = clickedNode || (direction === 'out' ? centerNode : null);
        const sourceRect = sourceNode?.getBoundingClientRect?.();
        const viewport = {
            width: Number(windowRef.innerWidth || 0),
            height: Number(windowRef.innerHeight || 0),
        };
        const snapshot = semanticRectSnapshot(sourceRect, viewport);

        const sourcePosition = renderedNodePosition(sourceNode);
        const camera = semanticCameraSnapshot(mapView);

        if (direction === 'in' && sourceNode) {
            rememberSemanticExpansionContext(sourceNode);
        } else {
            windowRef[SEMANTIC_CONTEXT_WINDOW_KEY] = null;
        }

        writeSemanticTransition(windowRef, {
            direction,
            from_depth: Number(page.dataset.mapHierarchyDepth || 0),
            from_route: semanticRouteKey(windowRef.location.href, windowRef.location.href),
            source_node_ref: sourceNode?.dataset?.mapNodeId || null,
            source_rect: snapshot,
            source_map_position: sourcePosition,
            camera,
            left_at: Date.now(),
        });

        page.dataset.mapSemanticTransitionMarked = '1';
        page.classList.add(direction === 'out' ? 'is-semantic-departure-out' : 'is-semantic-departure-in');

        if (sourceNode && snapshot) {
            // V49.2: opening a semantic box must not throw it toward the screen
            // center. Keep the selected node spatially anchored and only signal
            // that its contents are about to expand/collapse in place.
            sourceNode.style.setProperty('--semantic-departure-x', '0px');
            sourceNode.style.setProperty('--semantic-departure-y', '0px');
            sourceNode.style.setProperty('--semantic-departure-scale', direction === 'in' ? '1.045' : '0.965');
            sourceNode.classList.add('is-semantic-departure-anchor');
        }

        windowRef.setTimeout(() => {
            if (disposed) return;
            delete page.dataset.mapSemanticTransitionMarked;
            page.classList.remove('is-semantic-departure-in', 'is-semantic-departure-out');
            sourceNode?.classList?.remove('is-semantic-departure-anchor');
            sourceNode?.style?.removeProperty?.('--semantic-departure-x');
            sourceNode?.style?.removeProperty?.('--semantic-departure-y');
            sourceNode?.style?.removeProperty?.('--semantic-departure-scale');
        }, 1200);

        return true;
    };

    const isMapLevelNavigation = (link) => {
        if (!(link instanceof windowRef.HTMLAnchorElement)) return false;

        try {
            const target = new URL(link.href, windowRef.location.href);
            return target.origin === windowRef.location.origin
                && target.pathname === windowRef.location.pathname
                && target.pathname === '/map';
        } catch (_) {
            return false;
        }
    };

    const isNonMutatingNavigation = (link) => {
        return isMapLevelNavigation(link)
            || link?.dataset?.mapActionRole === 'external_tool';
    };

    const semanticTargetLink = (
        direction,
        clientPoint = null,
        {
            requireProximity = false,
        } = {},
    ) => {
        if (!mapScene) return null;

        const normalizedDirection = mapSemanticZoomDirection(direction);
        const links = [...mapScene.querySelectorAll(
            'a[data-map-semantic-zoom][data-map-zoom-direction="' + normalizedDirection + '"]'
        )].filter((link) => {
            const node = link.closest?.('[data-map-node]');
            if (!node || node.classList.contains('is-focus-hidden')) return false;
            if (
                normalizedDirection === 'in'
                && node.dataset?.mapSemanticCapability !== 'expand'
            ) {
                return false;
            }
            const rect = node.getBoundingClientRect?.();

            return rect && rect.width > 0 && rect.height > 0;
        });

        if (links.length === 0) return null;

        if (normalizedDirection === 'out') {
            const centerNode = page.querySelector?.('[data-map-node][data-map-is-center="1"]');
            const centerLink = centerNode?.querySelector?.(
                'a[data-map-semantic-zoom][data-map-zoom-direction="out"]'
            );

            return centerLink || links[0];
        }

        const shellRect = mapShell?.getBoundingClientRect?.();
        const focus = clientPoint || {
            x: shellRect ? shellRect.left + shellRect.width / 2 : Number(windowRef.innerWidth || 0) / 2,
            y: shellRect ? shellRect.top + shellRect.height / 2 : Number(windowRef.innerHeight || 0) / 2,
        };

        return links
            .map((link) => {
                const rect = link.closest('[data-map-node]').getBoundingClientRect();
                const matches = !requireProximity || semanticFocusMatchesNode(rect, focus);

                return {
                    link,
                    matches,
                    distance: Math.hypot(
                        rect.left + rect.width / 2 - Number(focus.x || 0),
                        rect.top + rect.height / 2 - Number(focus.y || 0),
                    ),
                };
            })
            .filter((candidate) => candidate.matches)
            .sort((left, right) => left.distance - right.distance)[0]?.link || null;
    };

    const syncSemanticArm = (clientPoint = null, nowMs = Date.now()) => {
        if (isMobileViewport() || !clientPoint) {
            clearSemanticArm();
            return null;
        }

        const link = semanticTargetLink('in', clientPoint, { requireProximity: true });
        const node = link?.closest?.('[data-map-node]') || null;
        const nodeId = node?.dataset?.mapNodeId || null;

        if (!nodeId) {
            clearSemanticArm();
            return null;
        }

        if (semanticArmCandidateId !== nodeId) {
            clearSemanticArm();
            semanticArmCandidateId = nodeId;
            semanticArmCandidateSince = Number(nowMs || Date.now());
        }

        const stableForMs = Math.max(
            0,
            Number(nowMs || Date.now()) - Number(semanticArmCandidateSince || 0),
        );
        const currentlyArmed = semanticArmedNodeId === nodeId;
        const shouldArm = semanticArmDecision({
            scale: mapView.scale,
            hasCandidate: true,
            currentlyArmed,
            stableForMs,
        });

        if (!shouldArm) {
            if (currentlyArmed) {
                clearSemanticArm();
                semanticArmCandidateId = nodeId;
                semanticArmCandidateSince = Number(nowMs || Date.now());
            }

            return null;
        }

        if (!currentlyArmed) {
            if (semanticArmedNodeId && semanticArmedNodeId !== nodeId) {
                nodeElementById.get(semanticArmedNodeId)?.classList.remove('is-semantic-armed');
            }

            semanticArmedNodeId = nodeId;
            node.classList.add('is-semantic-armed');
            page.dataset.mapSemanticArmedNode = nodeId;
        }

        return link || null;
    };

    const commitSemanticZoom = (
        direction,
        clientPoint = null,
        {
            requireProximity = false,
            requiredNodeId = null,
        } = {},
    ) => {
        if (semanticZoomNavigating || disposed) return false;

        const normalizedDirection = mapSemanticZoomDirection(direction);
        const link = semanticTargetLink(normalizedDirection, clientPoint, {
            requireProximity: normalizedDirection === 'in' && requireProximity,
        });
        const linkNodeId = link?.closest?.('[data-map-node]')?.dataset?.mapNodeId || null;
        if (
            normalizedDirection === 'in'
            && requiredNodeId
            && linkNodeId !== requiredNodeId
        ) {
            return false;
        }

        const targetUrl = semanticZoomDestination(normalizedDirection, {
            parentUrl: page.dataset.mapParentUrl || '',
            candidateUrl: link?.href || '',
        });

        if (!targetUrl) return false;

        semanticZoomNavigating = true;
        windowRef.clearTimeout(semanticZoomTimer);
        semanticZoomTimer = null;

        if (link instanceof windowRef.HTMLAnchorElement) {
            markSemanticTransition(link);
            trackInstantMapLink(link);
        } else if (normalizedDirection === 'out') {
            const centerNode = page.querySelector?.('[data-map-node][data-map-is-center="1"]');
            const sourceRect = centerNode?.getBoundingClientRect?.();
            const viewport = {
                width: Number(windowRef.innerWidth || 0),
                height: Number(windowRef.innerHeight || 0),
            };

            windowRef[SEMANTIC_CONTEXT_WINDOW_KEY] = null;

            writeSemanticTransition(windowRef, {
                direction: 'out',
                from_depth: Number(page.dataset.mapHierarchyDepth || 0),
                from_route: semanticRouteKey(windowRef.location.href, windowRef.location.href),
                source_node_ref: centerNode?.dataset?.mapNodeId || null,
                source_rect: semanticRectSnapshot(sourceRect, viewport),
                source_map_position: renderedNodePosition(centerNode),
                camera: semanticCameraSnapshot(mapView),
                left_at: Date.now(),
            });

            trackTelemetry('map_classic_action_opened', {
                action_role: 'zoom',
                node_type: centerNode?.dataset?.mapNodeType || null,
                position_role: centerNode?.dataset?.mapPositionRole || null,
                is_primary: centerNode?.dataset?.mapIsPrimary === '1',
            }, true);
        }

        windowRef.setTimeout(() => {
            if (disposed) return;

            const instant = windowRef.CanoviaInstantNavigation;
            if (instant && typeof instant.navigate === 'function') {
                void instant.navigate(targetUrl, {
                    historyMode: 'push',
                    scroll: false,
                    fallback: true,
                });
                return;
            }

            windowRef.location.assign(targetUrl);
        }, 90);

        return true;
    };

    const scheduleSemanticZoom = (
        clientPoint = null,
        delay = 85,
        {
            requireProximity = false,
            inThreshold = 1.42,
            requiredNodeId = null,
        } = {},
    ) => {
        const direction = semanticZoomThresholdDirection(mapView.scale, {
            inThreshold,
            outThreshold: 0.70,
        });

        windowRef.clearTimeout(semanticZoomTimer);
        semanticZoomTimer = null;

        if (!direction || semanticZoomNavigating) return false;
        if (direction === 'in' && requiredNodeId && semanticArmedNodeId !== requiredNodeId) {
            return false;
        }

        semanticZoomTimer = windowRef.setTimeout(() => {
            semanticZoomTimer = null;
            const committed = commitSemanticZoom(direction, clientPoint, {
                requireProximity,
                requiredNodeId: direction === 'in' ? requiredNodeId : null,
            });

            // L0 has no parent. Do not leave the whole navigation map crushed
            // at the minimum camera scale when zoom-out has nowhere to go.
            if (!committed && direction === 'out') {
                resetMapView({ animate: true });
            }
        }, Math.max(0, Number(delay || 0)));

        return true;
    };

    const pointerDistance = (left, right) => Math.hypot(
        Number(right?.x || 0) - Number(left?.x || 0),
        Number(right?.y || 0) - Number(left?.y || 0),
    );

    const pointerMidpoint = (left, right) => ({
        x: (Number(left?.x || 0) + Number(right?.x || 0)) / 2,
        y: (Number(left?.y || 0) + Number(right?.y || 0)) / 2,
    });

    const scenePoint = (clientPoint) => ({
        x: Number(clientPoint?.x || 0) - viewportCache.left,
        y: Number(clientPoint?.y || 0) - viewportCache.top,
    });

    const beginPanGesture = (point) => {
        gesture = {
            mode: 'pan',
            startPoint: { ...point },
            startTransform: { ...mapView },
            moved: false,
        };
    };

    const beginPinchGesture = () => {
        const points = [...activePointers.values()];
        if (points.length < 2) return;

        const midpoint = pointerMidpoint(points[0], points[1]);
        const viewport = currentMapViewport();
        const startFocus = scenePoint(midpoint);

        gesture = {
            mode: 'pinch',
            startDistance: Math.max(1, pointerDistance(points[0], points[1])),
            startMidpoint: midpoint,
            lastMidpoint: midpoint,
            startTransform: { ...mapView },
            worldAnchor: mapWorldPointAtScreen(mapView, startFocus, viewport),
            moved: false,
        };
    };

    const onScenePointerDown = (event) => {
        if (!mapScene) return;
        const touchLike = event.pointerType === 'touch' || event.pointerType === 'pen';
        if (!touchLike && !isMobileViewport()) return;
        if (event.pointerType === 'mouse' && event.button !== 0) return;

        if (activePointers.size === 0) {
            mapViewBatcher.flushNow();
            windowRef.clearTimeout(viewAnimationTimer);
            mapShell?.classList.remove('is-map-view-animating');
            refreshMapViewport();
            mapShell?.classList.add('is-map-gesture-active');
            page.classList.add('is-map-gesture-active');
        }

        const point = { x: event.clientX, y: event.clientY };
        activePointers.set(event.pointerId, point);
        // Keep a leaf tap targeted at its detail link. Capturing on the scene
        // retargets the subsequent click and silently drops the selection.
        const captureTarget = event.target.closest?.('[data-map-node-focus]') || mapScene;
        captureTarget.setPointerCapture?.(event.pointerId);

        if (activePointers.size === 1) {
            beginPanGesture(point);
        } else if (activePointers.size === 2) {
            beginPinchGesture();
        }
    };

    const onScenePointerMove = (event) => {
        if (!activePointers.has(event.pointerId) || !gesture) return;

        activePointers.set(event.pointerId, { x: event.clientX, y: event.clientY });

        if (activePointers.size >= 2) {
            if (gesture.mode !== 'pinch') beginPinchGesture();

            const points = [...activePointers.values()];
            const currentDistance = Math.max(1, pointerDistance(points[0], points[1]));
            const currentMidpoint = pointerMidpoint(points[0], points[1]);
            gesture.lastMidpoint = currentMidpoint;
            const viewport = currentMapViewport();
            const currentFocus = scenePoint(currentMidpoint);
            const scale = gesture.startTransform.scale
                * (currentDistance / Math.max(1, gesture.startDistance));
            const next = mapViewForWorldAnchor(
                gesture.worldAnchor,
                scale,
                currentFocus,
                viewport,
            );

            gesture.moved = true;
            event.preventDefault();
            applyMapView(next);
            return;
        }

        if (gesture.mode !== 'pan') {
            const point = activePointers.get(event.pointerId);
            beginPanGesture(point);
        }

        const dx = event.clientX - gesture.startPoint.x;
        const dy = event.clientY - gesture.startPoint.y;
        if (!gesture.moved && Math.hypot(dx, dy) < 5) return;

        gesture.moved = true;
        event.preventDefault();
        applyMapView({
            x: gesture.startTransform.x + dx,
            y: gesture.startTransform.y + dy,
            scale: gesture.startTransform.scale,
        });
    };

    const finishScenePointer = (event) => {
        if (!activePointers.has(event.pointerId)) return;

        const moved = Boolean(gesture?.moved);
        const finishedMode = gesture?.mode || null;
        const semanticFocus = gesture?.lastMidpoint || gesture?.startMidpoint || gesture?.startPoint || null;
        activePointers.delete(event.pointerId);
        try {
            mapScene?.releasePointerCapture?.(event.pointerId);
        } catch (_) {}

        if (moved) {
            suppressMapClickUntil = Date.now() + 280;
        }

        if (activePointers.size >= 2) {
            beginPinchGesture();
            return;
        }

        if (activePointers.size === 1) {
            beginPanGesture([...activePointers.values()][0]);
            return;
        }

        gesture = null;
        mapViewBatcher.flushNow();
        mapShell?.classList.remove('is-map-gesture-active');
        page.classList.remove('is-map-gesture-active');

        if (moved && finishedMode === 'pinch') {
            scheduleSemanticZoom(semanticFocus, 55, {
                // Keep the already-good mobile/PWA gesture contract intact.
                requireProximity: false,
            });
        }
    };

    const onMapPinchCapture = (event) => {
        if (!mapShell || disposed) return;

        const target = event.target;
        const mapActive = Boolean(target && mapShell.contains?.(target));
        const gestureControl = Boolean(target?.closest?.('[data-map-gesture-controls]'));

        if (gestureControl) return;

        if (shouldCaptureMapPinch({
            ctrlKey: event.ctrlKey,
            cancelable: event.cancelable !== false,
            mapActive,
            browserZoomChanged,
        })) {
            event.preventDefault();
        }
    };

    const onSceneWheel = (event) => {
        if (!mapScene || semanticZoomNavigating) return;
        if (event.target.closest?.('[data-map-gesture-controls]')) return;

        const insideScene = Boolean(event.target.closest?.('[data-map-scene]'));
        const insideWorkspacePalette = Boolean(event.target.closest?.(
            '[data-map-collaboration-workspace], [data-map-plan-workspace]'
        ));

        refreshMapViewport();

        if (event.ctrlKey) {
            // If the browser page itself already changed zoom, stop consuming
            // pinch until the user returns to the mount-time browser zoom.
            // This prevents the Map from trapping the recovery gesture.
            if (browserZoomChanged || event.cancelable === false) return;

            event.preventDefault();

            const focusClient = { x: event.clientX, y: event.clientY };
            const focus = scenePoint(focusClient);
            const delta = Math.max(-60, Math.min(60, Number(event.deltaY || 0)));
            const factor = Math.exp(-delta * 0.01);
            const viewport = currentMapViewport();
            const worldAnchor = mapWorldPointAtScreen(mapView, focus, viewport);
            const next = mapViewForWorldAnchor(
                worldAnchor,
                mapView.scale * factor,
                focus,
                viewport,
            );

            applyMapView(next);
            const mobile = isMobileViewport();
            const armedLink = syncSemanticArm(focusClient);

            if (mobile || armedLink) {
                scheduleSemanticZoom(focusClient, 90, {
                    requireProximity: !mobile,
                    inThreshold: mobile ? 1.42 : 1.46,
                    requiredNodeId: mobile
                        ? null
                        : (armedLink?.closest?.('[data-map-node]')?.dataset?.mapNodeId || null),
                });
            }

            return;
        }

        if (event.target.closest?.('input, select, textarea')) return;

        // Workspace cards keep ordinary scrolling. Outside cards, precision
        // touchpad two-finger scrolling pans the map camera.
        if (insideWorkspacePalette || !insideScene) return;

        if (Math.abs(Number(event.deltaX || 0)) > 0 || Math.abs(Number(event.deltaY || 0)) > 0) {
            event.preventDefault();
            clearSemanticArm();
            applyMapView({
                x: mapView.x - Number(event.deltaX || 0),
                y: mapView.y - Number(event.deltaY || 0),
                scale: mapView.scale,
            });
        }
    };

    const onSceneDoubleClick = (event) => {
        if (event.target.closest?.('[data-map-node], a, button, input, select, textarea')) return;

        event.preventDefault();
        refreshMapViewport();
        const focus = scenePoint({ x: event.clientX, y: event.clientY });
        const targetScale = mapView.scale > 1.18 ? 1 : Math.min(1.55, 2.2);
        applyMapView(
            zoomMapViewAt(mapView, targetScale, focus, currentMapViewport()),
            { animate: true },
        );
    };

    const onZoomOut = () => {
        clearSemanticArm();
        return zoomMapBy(1 / 1.28);
    };
    const onZoomIn = () => {
        clearSemanticArm();
        return zoomMapBy(1.28);
    };
    const onViewReset = () => resetMapView();

    const onDocumentBackPointerDown = (event) => {
        const scrollElement = activeDocumentScroll();
        const scrollLeft = Math.max(0, Number(scrollElement?.scrollLeft || 0));

        if (
            !standaloneDocumentNavigation
            || !documentParentUrl
            || !documentModeActive()
            || event.pointerType !== 'touch'
            || event.isPrimary === false
            || Number(event.clientX || 0) > 28
            || scrollLeft > 1
        ) {
            documentBackGesture = null;
            return;
        }

        documentBackGesture = {
            pointerId: event.pointerId,
            startX: Number(event.clientX || 0),
            startY: Number(event.clientY || 0),
            scrollLeft,
        };
    };

    const onDocumentBackPointerUp = (event) => {
        const gesture = documentBackGesture;
        documentBackGesture = null;

        if (!gesture || gesture.pointerId !== event.pointerId) return;

        if (!documentEdgeBackDecision({
            ...gesture,
            endX: Number(event.clientX || 0),
            endY: Number(event.clientY || 0),
        })) {
            return;
        }

        const instant = windowRef.CanoviaInstantNavigation;
        if (instant && typeof instant.navigate === 'function') {
            void instant.navigate(documentParentUrl, {
                historyMode: 'replace',
                scroll: false,
                fallback: true,
            });
            return;
        }

        windowRef.location.assign(documentParentUrl);
    };

    const onDocumentBackPointerCancel = () => {
        documentBackGesture = null;
    };

    const onViewportResize = () => {
        windowRef.clearTimeout(viewportTimer);
        viewportTimer = windowRef.setTimeout(() => {
            if (disposed) return;

            syncBrowserZoomState();
            refreshMapViewport();
            applyBaseLayout();
            applyMapView(mapView, { immediate: true });
            syncAllDocumentPositions();

            if (activeFocusId) {
                openFocus(activeFocusId, { historyMode: 'none' });
            }
        }, 90);
    };

    const destroy = () => {
        if (disposed) return;
        disposed = true;
        windowRef.clearTimeout(updateTimer);
        windowRef.clearTimeout(viewportTimer);
        windowRef.clearTimeout(viewAnimationTimer);
        windowRef.clearTimeout(semanticZoomTimer);
        semanticZoomTimer = null;
        mapViewBatcher.cancel();
        mapShell?.classList.remove('is-map-gesture-active', 'is-map-view-animating');
        page.classList.remove('is-map-gesture-active');
        clearSemanticArm();
        windowRef.removeEventListener?.('resize', onViewportResize);
        mapScene?.removeEventListener('pointerdown', onScenePointerDown);
        mapScene?.removeEventListener('pointermove', onScenePointerMove);
        mapScene?.removeEventListener('pointerup', finishScenePointer);
        mapScene?.removeEventListener('pointercancel', finishScenePointer);
        documentRef.removeEventListener('wheel', onMapPinchCapture, { capture: true });
        mapShell?.removeEventListener('wheel', onSceneWheel);
        mapScene?.removeEventListener('dblclick', onSceneDoubleClick);
        page.removeEventListener('pointerdown', onDocumentBackPointerDown);
        page.removeEventListener('pointerup', onDocumentBackPointerUp);
        page.removeEventListener('pointercancel', onDocumentBackPointerCancel);
        for (const scrollElement of documentScrolls) {
            scrollElement.removeEventListener('scroll', onDocumentScroll);
        }
        zoomOutControl?.removeEventListener('click', onZoomOut);
        zoomInControl?.removeEventListener('click', onZoomIn);
        viewResetControl?.removeEventListener('click', onViewReset);
        page.removeEventListener('click', onPageClick);
        page.removeEventListener('submit', onPageSubmit);
        resetButton?.removeEventListener('click', closeContext);
        documentRef.removeEventListener('keydown', onKeyDown);
        documentRef.removeEventListener('canovia:before-instant-navigation', onBeforeInstantNavigation);
        documentRef.removeEventListener('canovia:before-page-replace', onBeforePageReplace);
        windowRef.removeEventListener('popstate', onPopState);
        windowRef.removeEventListener('pageshow', onPageShow);
        delete page.dataset.mapFocusInitialized;
    };

    const replaceWithFreshPage = (nextPage) => {
        const imported = documentRef.importNode
            ? documentRef.importNode(nextPage, true)
            : nextPage.cloneNode(true);

        imported.dataset.mapReprojected = '1';
        destroy();
        page.replaceWith(imported);

        const mounted = mountLivingGoalMap({
            documentRef,
            windowRef,
            fetchRef,
            recordBehaviorRef,
        });

        documentRef.dispatchEvent?.(new windowRef.CustomEvent(
            'canovia:map-reprojected',
            {
                detail: {
                    projectionKey: imported.dataset.mapProjectionKey || '',
                },
            },
        ));

        return mounted;
    };

    const revalidateProjection = async () => {
        if (revalidationPromise || disposed || typeof fetchRef !== 'function') {
            return revalidationPromise;
        }

        revalidationPromise = fetchRef(mapUrlWithoutFocus(windowRef), {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                Accept: 'text/html',
                'X-Canovia-Map-Revalidate': '1',
            },
        }).then(async (response) => {
            if (!response.ok) throw new Error('map-reevaluation-unavailable');

            const html = await response.text();
            const parser = new windowRef.DOMParser();
            const nextDocument = parser.parseFromString(html, 'text/html');
            const nextPage = nextDocument.querySelector('[data-canovia-map-page]');
            if (!nextPage) throw new Error('map-reevaluation-payload-missing');

            const currentKey = page.dataset.mapProjectionKey || '';
            const nextKey = nextPage.dataset.mapProjectionKey || '';
            clearPending(windowRef);

            if (!nextKey || nextKey === currentKey) {
                return false;
            }

            replaceWithFreshPage(nextPage);
            return true;
        }).catch(() => {
            return false;
        }).finally(() => {
            revalidationPromise = null;
        });

        return revalidationPromise;
    };

    function trackInstantMapLink(link) {
        if (!link || !page.contains(link)) return false;

        const fallback = link.closest?.('[data-map-home-fallback]');
        if (fallback) {
            trackTelemetry('map_classic_home_opened', { action_role: 'home' }, true);
            clearMapTelemetryFlow(windowRef);
            return true;
        }

        const directNavigation = link.closest?.('[data-map-direct-navigation]');
        if (directNavigation) {
            if (!isNonMutatingNavigation(directNavigation)) {
                markPendingReevaluation(directNavigation.dataset.mapNodeId || activeFocusId);
            }
            trackTelemetry(
                'map_classic_action_opened',
                mapActionTelemetryContext(directNavigation),
                true,
            );
            return true;
        }

        const classicAction = link.closest?.('[data-map-classic-action]');
        if (classicAction) {
            const focusedNode = activeFocusId ? nodeElementById.get(activeFocusId) : null;
            if (!isNonMutatingNavigation(classicAction)) {
                markPendingReevaluation(classicAction.dataset.mapNodeId || activeFocusId);
            }
            trackTelemetry(
                'map_classic_action_opened',
                mapActionTelemetryContext(classicAction, focusedNode),
                true,
            );
            return true;
        }

        return false;
    }

    function onBeforeInstantNavigation(event) {
        const link = event.detail?.link;
        markSemanticTransition(link);

        if (link?.closest?.('[data-map-global-home]')) {
            resetGlobalHomeContext();
        }

        trackInstantMapLink(link);
    }

    function onBeforePageReplace() {
        destroy();
    }

    function onPageClick(event) {
        const closeControl = event.target.closest?.('[data-map-context-close]');
        if (closeControl && page.contains(closeControl)) {
            event.preventDefault();
            closeContext();
            return;
        }

        const expandControl = event.target.closest?.('[data-map-context-expand]');
        if (expandControl && page.contains(expandControl)) {
            event.preventDefault();
            onExpandToggle();
            return;
        }

        const sceneTarget = event.target.closest?.('[data-map-scene]');
        const interactiveSceneTarget = event.target.closest?.(
            '[data-map-node], [data-map-gesture-controls], [data-map-spatial-dock], a, button, input, select, textarea'
        );

        if (
            Date.now() < suppressMapClickUntil
            && sceneTarget
            && !interactiveSceneTarget
        ) {
            event.preventDefault();
            event.stopPropagation();
            return;
        }

        const semanticLink = event.target.closest?.('a[data-map-semantic-zoom]');
        const isPlainSemanticClick = event.button === 0
            && !event.metaKey
            && !event.ctrlKey
            && !event.shiftKey
            && !event.altKey;
        if (semanticLink && page.contains(semanticLink) && isPlainSemanticClick) {
            markSemanticTransition(semanticLink);
        }

        const globalHomeLink = event.target.closest?.('a[data-map-global-home]');
        if (globalHomeLink && page.contains(globalHomeLink) && isPlainSemanticClick) {
            resetGlobalHomeContext();
        }

        const dockControl = event.target.closest?.('[data-map-spatial-dock]');
        if (dockControl && page.contains(dockControl)) {
            event.preventDefault();
            const dockId = dockControl.dataset.mapDockId || 'space-station';
            trackTelemetry('map_node_focused', {
                node_type: 'space_station',
                position_role: 'spatial-dock',
                is_primary: false,
            }, true);
            openSpatialDock(dockId);
            return;
        }

        const directOpen = event.target.closest?.('[data-map-direct-open]');
        if (directOpen && page.contains(directOpen)) {
            const directLink = directOpen.closest?.('a[data-map-direct-navigation]');
            if (directLink instanceof windowRef.HTMLAnchorElement) {
                if (!isNonMutatingNavigation(directLink)) {
                    markPendingReevaluation(directLink.dataset.mapNodeId || activeFocusId);
                }
                trackTelemetry(
                    'map_classic_action_opened',
                    mapActionTelemetryContext(directLink),
                    true,
                );
            }
            return;
        }

        const primaryShortcut = event.target.closest?.('[data-map-primary-focus]');
        if (primaryShortcut && page.contains(primaryShortcut) && primaryNode) {
            event.preventDefault();
            const nodeElement = nodeElementById.get(primaryNode.id);
            trackTelemetry('map_node_focused', {
                node_type: nodeElement?.dataset.mapNodeType || primaryNode.nodeType || null,
                position_role: nodeElement?.dataset.mapPositionRole || primaryNode.positionRole || null,
                is_primary: true,
            }, true);
            openFocus(primaryNode.id);
            return;
        }

        const fallback = event.target.closest?.('[data-map-home-fallback]');
        if (fallback && page.contains(fallback)) {
            trackTelemetry('map_classic_home_opened', { action_role: 'home' }, true);
            clearMapTelemetryFlow(windowRef);
            return;
        }

        const classicAction = event.target.closest?.('[data-map-classic-action]');
        if (classicAction && page.contains(classicAction)) {
            const focusedNode = activeFocusId ? nodeElementById.get(activeFocusId) : null;
            if (!isNonMutatingNavigation(classicAction)) {
                markPendingReevaluation(classicAction.dataset.mapNodeId || activeFocusId);
            }

            if (classicAction instanceof windowRef.HTMLAnchorElement) {
                trackTelemetry(
                    'map_classic_action_opened',
                    mapActionTelemetryContext(classicAction, focusedNode),
                    true,
                );
            }
            return;
        }

        const focusLink = event.target.closest?.('[data-map-node-focus]');
        const node = focusLink?.closest?.('[data-map-node]');
        if (!focusLink || !node || !page.contains(node)) return;
        if (!(focusLink instanceof windowRef.HTMLAnchorElement)) return;
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        const nodeId = node.dataset.mapNodeId;
        if (!nodeId) return;

        event.preventDefault();
        trackTelemetry('map_node_focused', {
            node_type: node.dataset.mapNodeType || null,
            position_role: node.dataset.mapPositionRole || null,
            is_primary: node.dataset.mapIsPrimary === '1',
        }, true);
        openFocus(nodeId);
    }

    function onPageSubmit(event) {
        const form = event.target?.closest?.('form[data-map-companion-form]');
        if (!form || !page.contains(form)) return;

        const focusedNode = activeFocusId ? nodeElementById.get(activeFocusId) : null;
        markPendingReevaluation(activeDockId ? 'dock:space-station' : activeFocusId);
        trackTelemetry('map_companion_opened', {
            action_role: 'companion',
            node_type: activeDockId ? 'space_station' : (focusedNode?.dataset.mapNodeType || null),
            position_role: activeDockId ? 'spatial-dock' : (focusedNode?.dataset.mapPositionRole || null),
            is_primary: activeDockId ? false : focusedNode?.dataset.mapIsPrimary === '1',
        }, true);
    }

    function onExpandToggle() {
        const expanded = surface?.classList.contains('is-expanded') ?? false;
        setSurfaceExpanded(!expanded);
    }

    function onKeyDown(event) {
        if (event.key === 'Escape' && (activeFocusId || activeDockId)) closeContext();
    }

    function onPopState(event) {
        const targetDepth = Math.max(0, Number(event?.state?.canoviaMapFocusDepth || 0));
        if (mapHistoryDirection(focusHistoryDepth, targetDepth) === 'back') {
            trackTelemetry('map_back_used', {}, true);
        }
        focusHistoryDepth = targetDepth;

        const dockId = dockIdFromLocation(windowRef);
        if (dockId) {
            if (!openSpatialDock(dockId, { historyMode: 'none' })) {
                clearSpatialDock();
                removeInvalidDockHash();
            }
            return;
        }

        const nodeId = focusIdFromLocation(windowRef);
        if (!nodeId) {
            clearSpatialDock();
            clearFocus();
            return;
        }

        if (!openFocus(nodeId, { historyMode: 'none' })) {
            clearFocus();
            removeInvalidFocusHash();
        }
    }

    function onPageShow(event) {
        refreshMapViewport();
        applyBaseLayout();
        applyMapView(mapView, { immediate: true });
        const pending = readPending(windowRef);
        if (!pending) return;

        const decision = mapReturnDecision({
            persisted: Boolean(event.persisted),
            currentProjectionKey: page.dataset.mapProjectionKey || '',
            previousProjectionKey: String(pending.projectionKey || ''),
        });

        if (decision === 'revalidate') {
            void revalidateProjection();
            return;
        }

        clearPending(windowRef);
        if (decision === 'updated') showUpdatedStatus();
    }

    syncBrowserZoomState();
    refreshMapViewport();
    applyBaseLayout();
    applyMapView(mapView, { immediate: true });
    windowRef.addEventListener?.('resize', onViewportResize);
    mapScene?.addEventListener('pointerdown', onScenePointerDown);
    mapScene?.addEventListener('pointermove', onScenePointerMove, { passive: false });
    mapScene?.addEventListener('pointerup', finishScenePointer);
    mapScene?.addEventListener('pointercancel', finishScenePointer);
    documentRef.addEventListener('wheel', onMapPinchCapture, { passive: false, capture: true });
    mapShell?.addEventListener('wheel', onSceneWheel, { passive: false });
    mapScene?.addEventListener('dblclick', onSceneDoubleClick);
    page.addEventListener('pointerdown', onDocumentBackPointerDown);
    page.addEventListener('pointerup', onDocumentBackPointerUp);
    page.addEventListener('pointercancel', onDocumentBackPointerCancel);
    for (const scrollElement of documentScrolls) {
        scrollElement.addEventListener('scroll', onDocumentScroll, { passive: true });
    }
    syncAllDocumentPositions();
    zoomOutControl?.addEventListener('click', onZoomOut);
    zoomInControl?.addEventListener('click', onZoomIn);
    viewResetControl?.addEventListener('click', onViewReset);

    page.addEventListener('click', onPageClick);
    page.addEventListener('submit', onPageSubmit);
    resetButton?.addEventListener('click', closeContext);

    documentRef.addEventListener('keydown', onKeyDown);
    documentRef.addEventListener('canovia:before-instant-navigation', onBeforeInstantNavigation);
    documentRef.addEventListener('canovia:before-page-replace', onBeforePageReplace);
    windowRef.addEventListener('popstate', onPopState);
    windowRef.addEventListener('pageshow', onPageShow);

    const forceGlobalHomeReset = consumeGlobalHomeReset(
        windowRef,
        page.dataset.mapLevel || '',
    );

    if (forceGlobalHomeReset) {
        windowRef[SEMANTIC_CONTEXT_WINDOW_KEY] = null;
        focusHistoryDepth = 0;
        windowRef.history.replaceState(
            mapGlobalHomeHistoryState(windowRef.history.state || {}),
            '',
            mapUrlWithoutFocus(windowRef),
        );
        clearSpatialDock();
        clearFocus();
        resetMapView({ animate: false });
    }

    const initialDockId = forceGlobalHomeReset ? null : dockIdFromLocation(windowRef);
    const initialFocusId = forceGlobalHomeReset ? null : focusIdFromLocation(windowRef);
    if (initialDockId) {
        if (!openSpatialDock(initialDockId, { historyMode: 'none' })) {
            clearSpatialDock();
            removeInvalidDockHash();
        }
    } else if (initialFocusId) {
        if (!openFocus(initialFocusId, { historyMode: 'none' })) {
            clearFocus();
            removeInvalidFocusHash();
        }
    } else {
        clearSpatialDock();
        clearFocus();
    }

    const semanticArrival = readSemanticTransition(windowRef);
    if (semanticArrival && Date.now() - Number(semanticArrival.left_at || 0) < 10000) {
        clearSemanticTransition(windowRef);
        const direction = mapSemanticZoomDirection(semanticArrival.direction);
        const reducedMotion = Boolean(windowRef.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches);
        const camera = semanticCameraSnapshot(semanticArrival.camera);
        const semanticContext = direction === 'in'
            ? consumeSemanticExpansionContext(semanticArrival)
            : null;
        const sourcePosition = semanticMapPositionSnapshot(
            semanticArrival.source_map_position || semanticContext?.sourcePosition || {},
        );

        if (camera) {
            applyMapView(camera, { immediate: true });
        }

        let arrivalAnchor = null;

        if (direction === 'in') {
            if (sourcePosition) {
                arrivalAnchor = shiftProjectionAroundSemanticAnchor(sourcePosition);
            }

            arrivalAnchor = arrivalAnchor
                || page.querySelector?.('[data-map-node][data-map-is-center="1"]')
                || nodeElements[0]
                || null;

            if (semanticContext?.layer && mapScene) {
                mapScene.prepend(semanticContext.layer);
                page.classList.add('has-semantic-context-layer');
            }

            if (arrivalAnchor) {
                prepareSemanticChildrenFromAnchor(arrivalAnchor);
                page.classList.add('is-semantic-expansion-arrival');

                windowRef.setTimeout(() => {
                    arrivalAnchor?.classList?.remove('is-semantic-expansion-anchor');

                    for (const element of nodeElements) {
                        element.classList.remove('is-semantic-expansion-child');
                        element.style.removeProperty('--semantic-child-origin-x');
                        element.style.removeProperty('--semantic-child-origin-y');
                    }

                    page.classList.remove('is-semantic-expansion-arrival');
                }, reducedMotion ? 0 : 560);
            }
        } else {
            const fromRoute = String(semanticArrival.from_route || '');
            if (fromRoute) {
                arrivalAnchor = nodeElements.find((element) => {
                    const semanticLink = element.querySelector?.('a[data-map-semantic-zoom]');
                    if (!semanticLink?.href) return false;

                    return semanticRouteKey(semanticLink.href, windowRef.location.href) === fromRoute;
                }) || null;
            }

            const sourceRect = adaptSemanticRect(semanticArrival.source_rect, {
                width: Number(windowRef.innerWidth || 0),
                height: Number(windowRef.innerHeight || 0),
            });
            const targetRect = arrivalAnchor?.getBoundingClientRect?.();
            const continuity = !reducedMotion
                ? semanticContinuityTransform(sourceRect, targetRect)
                : null;

            if (arrivalAnchor && continuity) {
                arrivalAnchor.style.setProperty('--semantic-anchor-x', continuity.x.toFixed(1) + 'px');
                arrivalAnchor.style.setProperty('--semantic-anchor-y', continuity.y.toFixed(1) + 'px');
                arrivalAnchor.style.setProperty('--semantic-anchor-scale', String(continuity.scale));
                arrivalAnchor.classList.add('is-semantic-continuity-anchor');
                page.classList.add(
                    'is-semantic-continuity-arrival',
                    'is-semantic-continuity-out',
                );

                windowRef.setTimeout(() => {
                    arrivalAnchor?.classList?.remove('is-semantic-continuity-anchor');
                    arrivalAnchor?.style?.removeProperty?.('--semantic-anchor-x');
                    arrivalAnchor?.style?.removeProperty?.('--semantic-anchor-y');
                    arrivalAnchor?.style?.removeProperty?.('--semantic-anchor-scale');
                    page.classList.remove(
                        'is-semantic-continuity-arrival',
                        'is-semantic-continuity-out',
                    );
                }, 520);
            } else {
                page.classList.add('is-semantic-arrival-out');
                windowRef.setTimeout(() => {
                    page.classList.remove('is-semantic-arrival-out');
                }, 420);
            }
        }
    } else if (semanticArrival) {
        clearSemanticTransition(windowRef);
        windowRef[SEMANTIC_CONTEXT_WINDOW_KEY] = null;
    }

    if (page.dataset.mapReprojected === '1') {
        delete page.dataset.mapReprojected;
        clearPending(windowRef);
        showUpdatedStatus();
        trackTelemetry('map_reprojected');
    } else {
        const instantRoot = page.closest?.('[data-canovia-page]');
        if (readPending(windowRef) && instantRoot?.dataset.canoviaInstantRendered === '1') {
            void revalidateProjection();
        }
    }

    return {
        openFocus,
        openSpatialDock,
        clearFocus,
        clearSpatialDock,
        revalidateProjection,
        markPendingReevaluation,
        destroy,
        get activeFocusId() {
            return activeFocusId;
        },
        get activeDockId() {
            return activeDockId;
        },
    };
}
