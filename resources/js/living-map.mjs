import {
    advanceMapTelemetryFlow,
    clearMapTelemetryFlow,
    ensureMapTelemetryFlow,
    mapClientDevice,
    mapClientSurface,
    mapTelemetryMetadata,
} from './map-telemetry.mjs';

const PENDING_REEVALUATION_KEY = 'canovia.map.pending-reevaluation.v1';
const SEMANTIC_TRANSITION_KEY = 'canovia.map.semantic-transition.v1';

function roleGroup(role = '') {
    if (role === 'space-station') return 'now';
    if (role === 'intent-plan') return 'future';
    if (role === 'intent-execution') return 'action';
    if (role === 'intent-reflection') return 'past';
    if (role === 'intent-collaboration') return 'input';
    if (role === 'hierarchy-parent') return 'now';
    if (role === 'hierarchy-child') return 'future';
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


export function buildMobileBaseLayout(nodes, mapLevel = 'l0') {
    const positions = new Map(
        nodes.map((node) => [node.id, { x: Number(node.x ?? 50), y: Number(node.y ?? 50) }])
    );

    if (mapLevel === 'l0') {
        const rolePositions = {
            'space-station': { x: 50, y: 50 },
            'intent-plan': { x: 27, y: 29 },
            'intent-execution': { x: 73, y: 29 },
            'intent-reflection': { x: 73, y: 71 },
            'intent-collaboration': { x: 27, y: 71 },
            'satellite-1': { x: 50, y: 9 },
            'satellite-2': { x: 88, y: 50 },
            'satellite-3': { x: 50, y: 91 },
            'satellite-4': { x: 12, y: 50 },
        };

        for (const node of nodes) {
            if (rolePositions[node.positionRole]) {
                positions.set(node.id, rolePositions[node.positionRole]);
            }
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
            const radiusX = count <= 4 ? 31 : 34;
            const radiusY = count <= 4 ? 37 : 40;

            children.forEach((node, index) => {
                const angle = (-90 + (360 / count) * index) * Math.PI / 180;
                positions.set(node.id, {
                    x: Math.round(50 + Math.cos(angle) * radiusX),
                    y: Math.round(50 + Math.sin(angle) * radiusY),
                });
            });
        }
    }

    return positions;
}

export function clampMapViewTransform(
    transform,
    viewport,
    {
        minScale = 0.82,
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

    return {
        x: Math.max(-extraX, Math.min(extraX, Number(transform?.x || 0))),
        y: Math.max(-extraY, Math.min(extraY, Number(transform?.y || 0))),
        scale,
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
    const closeButton = page.querySelector('[data-map-context-close]');
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
    }));

    const nodeElementById = new Map(nodeElements.map((element) => [element.dataset.mapNodeId, element]));
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
    let disposed = false;
    let basePositions = new Map(nodes.map((node) => [node.id, { x: node.x, y: node.y }]));

    const isMobileViewport = () => Boolean(windowRef.matchMedia?.('(max-width: 767px)')?.matches);
    const activePointers = new Map();
    let gesture = null;
    let suppressMapClickUntil = 0;
    let mapView = { x: 0, y: 0, scale: 1 };

    const mapViewport = () => {
        const rect = mapShell?.getBoundingClientRect?.();

        return {
            width: Math.max(1, Number(rect?.width || mapShell?.clientWidth || 1)),
            height: Math.max(1, Number(rect?.height || mapShell?.clientHeight || 1)),
        };
    };

    const syncViewControls = () => {
        const atMinimum = mapView.scale <= 0.821;
        const atMaximum = mapView.scale >= 2.199;
        if (zoomOutControl) zoomOutControl.disabled = atMinimum;
        if (zoomInControl) zoomInControl.disabled = atMaximum;
        if (viewResetControl) {
            viewResetControl.dataset.mapViewChanged = (
                Math.abs(mapView.x) > 0.5
                || Math.abs(mapView.y) > 0.5
                || Math.abs(mapView.scale - 1) > 0.005
            ) ? '1' : '0';
        }
    };

    const applyMapView = (next, { animate = false } = {}) => {
        if (!mapScene) return mapView;

        if (!isMobileViewport()) {
            mapView = { x: 0, y: 0, scale: 1 };
        } else {
            mapView = clampMapViewTransform(next, mapViewport());
        }

        windowRef.clearTimeout(viewAnimationTimer);
        mapShell?.classList.toggle('is-map-view-animating', Boolean(animate));
        if (animate) {
            viewAnimationTimer = windowRef.setTimeout(() => {
                mapShell?.classList.remove('is-map-view-animating');
            }, 210);
        }

        mapScene.style.setProperty('--map-pan-x', mapView.x + 'px');
        mapScene.style.setProperty('--map-pan-y', mapView.y + 'px');
        mapScene.style.setProperty('--map-view-scale', String(mapView.scale));
        mapShell?.classList.toggle(
            'is-map-transformed',
            Math.abs(mapView.x) > 0.5
                || Math.abs(mapView.y) > 0.5
                || Math.abs(mapView.scale - 1) > 0.005,
        );
        syncViewControls();

        return mapView;
    };

    const resetMapView = ({ animate = true } = {}) => {
        return applyMapView({ x: 0, y: 0, scale: 1 }, { animate });
    };

    const zoomMapBy = (factor, focusPoint = null, { animate = true } = {}) => {
        if (!isMobileViewport()) return mapView;

        const viewport = mapViewport();
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

            edge.element.classList.toggle('is-focus-hidden', !visible);

            const source = positions.get(edge.source) || originalPosition(edge.source);
            const target = positions.get(edge.target) || originalPosition(edge.target);
            edge.element.setAttribute('x1', String(source.x));
            edge.element.setAttribute('y1', String(source.y));
            edge.element.setAttribute('x2', String(target.x));
            edge.element.setAttribute('y2', String(target.y));
        }
    };


    const applyBaseLayout = () => {
        const mobile = isMobileViewport();
        basePositions = mobile
            ? buildMobileBaseLayout(nodes, page.dataset.mapLevel || 'l3')
            : new Map(nodes.map((node) => [node.id, { x: node.x, y: node.y }]));

        page.dataset.mapSpatialLayout = mobile ? 'mobile' : 'desktop';

        for (const node of nodes) {
            const position = basePositions.get(node.id) || { x: node.x, y: node.y };
            const element = nodeElementById.get(node.id);
            element?.style.setProperty('--map-x', String(position.x) + '%');
            element?.style.setProperty('--map-y', String(position.y) + '%');
        }

        if (!activeFocusId) {
            syncEdges(basePositions);
        }

        return basePositions;
    };

    const templateFor = (nodeId) => templates.find(
        (template) => template.dataset.mapSurfaceTemplate === nodeId
    );
    const globalTemplateFor = (dockId) => globalTemplates.find(
        (template) => template.dataset.mapGlobalSurfaceTemplate === dockId
    );

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
        const hadFocus = Boolean(activeFocusId);

        if (mobile) {
            activeFocusId = nodeId;
            page.dataset.mapFocus = nodeId;
            page.classList.add('is-map-focused', 'is-map-mobile-selection');

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
            focusHistoryDepth += 1;
            windowRef.history.pushState({
                canoviaMapFocus: nodeId,
                canoviaMapFocusDepth: focusHistoryDepth,
            }, '', focusHash(nodeId));
        }

        return true;
    };

    const openSpatialDock = (dockId, { historyMode = 'push' } = {}) => {
        const normalizedDockId = String(dockId || '').trim();
        if (!normalizedDockId || !globalTemplateFor(normalizedDockId)) return false;

        activeDockId = normalizedDockId;
        page.dataset.mapDock = normalizedDockId;

        if (!renderGlobalSurface(normalizedDockId)) {
            activeDockId = null;
            page.dataset.mapDock = '';
            return false;
        }

        if (historyMode === 'push' && windowRef.location.hash !== dockHash(normalizedDockId)) {
            windowRef.history.pushState({
                ...(windowRef.history.state || {}),
                canoviaMapDock: normalizedDockId,
            }, '', dockHash(normalizedDockId));
        }

        return true;
    };

    const removeInvalidDockHash = () => {
        if (!dockIdFromLocation(windowRef)) return;
        const nextState = { ...(windowRef.history.state || {}) };
        delete nextState.canoviaMapDock;
        windowRef.history.replaceState(nextState, '', mapUrlWithoutFocus(windowRef));
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

    const markSemanticTransition = (link) => {
        const semanticLink = link?.closest?.('[data-map-semantic-zoom]')
            ?? (link?.hasAttribute?.('data-map-semantic-zoom') ? link : null);
        if (!semanticLink) return false;

        const direction = mapSemanticZoomDirection(semanticLink.dataset.mapZoomDirection || 'in');
        writeSemanticTransition(windowRef, {
            direction,
            from_depth: Number(page.dataset.mapHierarchyDepth || 0),
            left_at: Date.now(),
        });
        page.classList.add(direction === 'out' ? 'is-semantic-departure-out' : 'is-semantic-departure-in');
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


    const pointerDistance = (left, right) => Math.hypot(
        Number(right?.x || 0) - Number(left?.x || 0),
        Number(right?.y || 0) - Number(left?.y || 0),
    );

    const pointerMidpoint = (left, right) => ({
        x: (Number(left?.x || 0) + Number(right?.x || 0)) / 2,
        y: (Number(left?.y || 0) + Number(right?.y || 0)) / 2,
    });

    const scenePoint = (clientPoint) => {
        const rect = mapShell?.getBoundingClientRect?.();

        return {
            x: Number(clientPoint?.x || 0) - Number(rect?.left || 0),
            y: Number(clientPoint?.y || 0) - Number(rect?.top || 0),
        };
    };

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
        gesture = {
            mode: 'pinch',
            startDistance: Math.max(1, pointerDistance(points[0], points[1])),
            startMidpoint: midpoint,
            startTransform: { ...mapView },
            moved: false,
        };
    };

    const onScenePointerDown = (event) => {
        if (!isMobileViewport() || !mapScene) return;
        if (event.pointerType === 'mouse' && event.button !== 0) return;

        const point = { x: event.clientX, y: event.clientY };
        activePointers.set(event.pointerId, point);
        mapScene.setPointerCapture?.(event.pointerId);

        if (activePointers.size === 1) {
            beginPanGesture(point);
        } else if (activePointers.size === 2) {
            beginPinchGesture();
        }
    };

    const onScenePointerMove = (event) => {
        if (!isMobileViewport() || !activePointers.has(event.pointerId) || !gesture) return;

        activePointers.set(event.pointerId, { x: event.clientX, y: event.clientY });

        if (activePointers.size >= 2) {
            if (gesture.mode !== 'pinch') beginPinchGesture();

            const points = [...activePointers.values()];
            const currentDistance = Math.max(1, pointerDistance(points[0], points[1]));
            const currentMidpoint = pointerMidpoint(points[0], points[1]);
            const viewport = mapViewport();
            const startFocus = scenePoint(gesture.startMidpoint);
            const currentFocus = scenePoint(currentMidpoint);
            const scale = gesture.startTransform.scale
                * (currentDistance / Math.max(1, gesture.startDistance));
            const zoomed = zoomMapViewAt(
                gesture.startTransform,
                scale,
                startFocus,
                viewport,
            );

            const next = clampMapViewTransform({
                x: zoomed.x + (currentFocus.x - startFocus.x),
                y: zoomed.y + (currentFocus.y - startFocus.y),
                scale: zoomed.scale,
            }, viewport);

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
    };

    const onSceneDoubleClick = (event) => {
        if (!isMobileViewport()) return;
        if (event.target.closest?.('[data-map-node], a, button, input, select, textarea')) return;

        event.preventDefault();
        const rect = mapShell?.getBoundingClientRect?.();
        const focus = {
            x: event.clientX - Number(rect?.left || 0),
            y: event.clientY - Number(rect?.top || 0),
        };
        const targetScale = mapView.scale > 1.18 ? 1 : Math.min(1.55, 2.2);
        applyMapView(
            zoomMapViewAt(mapView, targetScale, focus, mapViewport()),
            { animate: true },
        );
    };

    const onZoomOut = () => zoomMapBy(1 / 1.22);
    const onZoomIn = () => zoomMapBy(1.22);
    const onViewReset = () => resetMapView();

    const onViewportResize = () => {
        windowRef.clearTimeout(viewportTimer);
        viewportTimer = windowRef.setTimeout(() => {
            if (disposed) return;

            applyBaseLayout();
            applyMapView(mapView);

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
        windowRef.removeEventListener?.('resize', onViewportResize);
        mapScene?.removeEventListener('pointerdown', onScenePointerDown);
        mapScene?.removeEventListener('pointermove', onScenePointerMove);
        mapScene?.removeEventListener('pointerup', finishScenePointer);
        mapScene?.removeEventListener('pointercancel', finishScenePointer);
        mapScene?.removeEventListener('dblclick', onSceneDoubleClick);
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

        return mountLivingGoalMap({ documentRef, windowRef, fetchRef, recordBehaviorRef });
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
        markSemanticTransition(event.detail?.link);
        trackInstantMapLink(event.detail?.link);
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

        if (
            Date.now() < suppressMapClickUntil
            && event.target.closest?.('[data-map-scene]')
        ) {
            event.preventDefault();
            event.stopPropagation();
            return;
        }

        const semanticLink = event.target.closest?.('a[data-map-semantic-zoom]');
        if (semanticLink && page.contains(semanticLink)) {
            markSemanticTransition(semanticLink);
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
        applyBaseLayout();
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

    applyBaseLayout();
    applyMapView(mapView);
    windowRef.addEventListener?.('resize', onViewportResize);
    mapScene?.addEventListener('pointerdown', onScenePointerDown);
    mapScene?.addEventListener('pointermove', onScenePointerMove, { passive: false });
    mapScene?.addEventListener('pointerup', finishScenePointer);
    mapScene?.addEventListener('pointercancel', finishScenePointer);
    mapScene?.addEventListener('dblclick', onSceneDoubleClick);
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

    const initialDockId = dockIdFromLocation(windowRef);
    const initialFocusId = focusIdFromLocation(windowRef);
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
        page.classList.add(direction === 'out' ? 'is-semantic-arrival-out' : 'is-semantic-arrival-in');
        windowRef.setTimeout(() => {
            page.classList.remove('is-semantic-arrival-out', 'is-semantic-arrival-in');
        }, 420);
    } else if (semanticArrival) {
        clearSemanticTransition(windowRef);
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
