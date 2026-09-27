import {
    advanceMapTelemetryFlow,
    clearMapTelemetryFlow,
    ensureMapTelemetryFlow,
    mapClientDevice,
    mapClientSurface,
    mapTelemetryMetadata,
} from './map-telemetry.mjs';

const PENDING_REEVALUATION_KEY = 'canovia.map.pending-reevaluation.v1';

function roleGroup(role = '') {
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

function slots(group, count) {
    const safeCount = Math.max(1, count);
    const centered = (index, step) => (index - (safeCount - 1) / 2) * step;

    return Array.from({ length: safeCount }, (_, index) => {
        if (group === 'future') return { x: 50 + centered(index, 25), y: 23 };
        if (group === 'past') return { x: 50 + centered(index, 24), y: 78 };
        if (group === 'input') return { x: 18, y: 50 + centered(index, 21) };
        if (group === 'action') return { x: 82, y: 50 + centered(index, 21) };
        return { x: 50 + centered(index, 23), y: 70 };
    });
}

function nowSlot(selectedRole) {
    const selectedGroup = roleGroup(selectedRole);
    if (selectedGroup === 'action') return { x: 20, y: 50 };
    if (selectedGroup === 'past') return { x: 50, y: 22 };
    if (selectedGroup === 'input') return { x: 80, y: 50 };
    if (selectedGroup === 'future') return { x: 50, y: 76 };
    return { x: 50, y: 72 };
}

export function buildFocusLayout(nodes, edges, selectedId) {
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

    const positions = new Map([[selectedId, { x: 50, y: 50 }]]);

    for (const [group, groupNodes] of groups.entries()) {
        if (group === 'now') {
            const base = nowSlot(selected.positionRole);
            groupNodes.forEach((node, index) => {
                positions.set(node.id, {
                    x: Math.max(14, Math.min(86, base.x + (index - (groupNodes.length - 1) / 2) * 18)),
                    y: Math.max(16, Math.min(84, base.y)),
                });
            });
            continue;
        }

        const available = slots(group, groupNodes.length);
        groupNodes.forEach((node, index) => positions.set(node.id, available[index]));
    }

    return { selectedId, visibleIds, positions };
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
    const updateStatus = page.querySelector('[data-map-update-status]');
    const mapShell = page.querySelector('[data-canovia-map]');
    const nodeElements = [...page.querySelectorAll('[data-map-node]')];
    const edgeElements = [...page.querySelectorAll('[data-map-edge]')];
    const templates = [...page.querySelectorAll('[data-map-surface-template]')];

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
    let revalidationPromise = null;
    let updateTimer = null;
    let disposed = false;

    const originalPosition = (nodeId) => {
        const node = nodes.find((candidate) => candidate.id === nodeId);
        return node ? { x: node.x, y: node.y } : { x: 50, y: 50 };
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

    const templateFor = (nodeId) => templates.find(
        (template) => template.dataset.mapSurfaceTemplate === nodeId
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

    const renderSurface = (nodeId) => {
        if (!surface || !surfaceContent || !workspace) return;

        const template = templateFor(nodeId);
        surfaceContent.replaceChildren();

        if (template?.content) {
            surfaceContent.append(template.content.cloneNode(true));
        }

        surface.setAttribute('aria-hidden', 'false');
        workspace.classList.add('is-context-open');
        resetButton?.classList.remove('hidden');
    };

    const clearFocus = () => {
        activeFocusId = null;
        page.dataset.mapFocus = '';

        const positions = new Map();
        for (const node of nodes) {
            positions.set(node.id, { x: node.x, y: node.y });
            const element = nodeElementById.get(node.id);
            element?.classList.remove('is-focus-center', 'is-focus-neighbor', 'is-focus-hidden');
            element?.removeAttribute('aria-selected');
            element?.style.removeProperty('--map-focus-x');
            element?.style.removeProperty('--map-focus-y');
        }

        syncEdges(positions);

        if (surface && workspace) {
            surface.setAttribute('aria-hidden', 'true');
            workspace.classList.remove('is-context-open');
        }
        surfaceContent?.replaceChildren();
        resetButton?.classList.add('hidden');
    };

    const removeInvalidFocusHash = () => {
        if (!focusIdFromLocation(windowRef)) return;
        windowRef.history.replaceState(windowRef.history.state, '', mapUrlWithoutFocus(windowRef));
    };

    const openFocus = (nodeId, { historyMode = 'push' } = {}) => {
        const layout = buildFocusLayout(nodes, edges, nodeId);
        if (!layout) return false;

        activeFocusId = nodeId;
        page.dataset.mapFocus = nodeId;

        for (const node of nodes) {
            const element = nodeElementById.get(node.id);
            if (!element) continue;

            const visible = layout.visibleIds.has(node.id);
            const selected = node.id === nodeId;
            const position = layout.positions.get(node.id) || { x: node.x, y: node.y };

            element.classList.toggle('is-focus-hidden', !visible);
            element.classList.toggle('is-focus-center', selected);
            element.classList.toggle('is-focus-neighbor', visible && !selected);
            element.setAttribute('aria-selected', selected ? 'true' : 'false');
            element.style.setProperty('--map-focus-x', position.x + '%');
            element.style.setProperty('--map-focus-y', position.y + '%');
        }

        syncEdges(layout.positions, layout.visibleIds, nodeId);
        renderSurface(nodeId);

        if (historyMode === 'push' && windowRef.location.hash !== focusHash(nodeId)) {
            windowRef.history.pushState({ canoviaMapFocus: nodeId }, '', focusHash(nodeId));
        }

        return true;
    };

    const closeFocus = () => {
        if (windowRef.history.state?.canoviaMapFocus && activeFocusId) {
            windowRef.history.back();
            return;
        }

        clearFocus();
        removeInvalidFocusHash();
    };

    const markPendingReevaluation = (nodeId = activeFocusId) => {
        writePending(windowRef, {
            projectionKey: page.dataset.mapProjectionKey || '',
            sourceNodeId: nodeId || null,
            leftAt: Date.now(),
        });
    };

    const destroy = () => {
        if (disposed) return;
        disposed = true;
        windowRef.clearTimeout(updateTimer);
        page.removeEventListener('click', onPageClick);
        page.removeEventListener('submit', onPageSubmit);
        resetButton?.removeEventListener('click', closeFocus);
        closeButton?.removeEventListener('click', closeFocus);
        documentRef.removeEventListener('keydown', onKeyDown);
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

    function onPageClick(event) {
        const fallback = event.target.closest?.('[data-map-home-fallback]');
        if (fallback && page.contains(fallback)) {
            trackTelemetry('map_classic_home_opened', { action_role: 'home' }, true);
            clearMapTelemetryFlow(windowRef);
            return;
        }

        const classicAction = event.target.closest?.('[data-map-classic-action]');
        if (classicAction && page.contains(classicAction)) {
            markPendingReevaluation();

            if (classicAction instanceof windowRef.HTMLAnchorElement) {
                const focusedNode = activeFocusId ? nodeElementById.get(activeFocusId) : null;
                trackTelemetry('map_classic_action_opened', {
                    action_role: classicAction.dataset.mapActionRole || 'secondary',
                    node_type: focusedNode?.dataset.mapNodeType || null,
                    position_role: focusedNode?.dataset.mapPositionRole || null,
                    is_primary: focusedNode?.dataset.mapIsPrimary === '1',
                }, true);
            }
            return;
        }

        const node = event.target.closest?.('[data-map-node]');
        if (!node || !page.contains(node)) return;
        if (!(node instanceof windowRef.HTMLAnchorElement)) return;
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
        markPendingReevaluation();
        trackTelemetry('map_companion_opened', {
            action_role: 'companion',
            node_type: focusedNode?.dataset.mapNodeType || null,
            position_role: focusedNode?.dataset.mapPositionRole || null,
            is_primary: focusedNode?.dataset.mapIsPrimary === '1',
        }, true);
    }

    function onKeyDown(event) {
        if (event.key === 'Escape' && activeFocusId) closeFocus();
    }

    function onPopState() {
        trackTelemetry('map_back_used', {}, true);
        const nodeId = focusIdFromLocation(windowRef);
        if (!nodeId) {
            clearFocus();
            return;
        }

        if (!openFocus(nodeId, { historyMode: 'none' })) {
            clearFocus();
            removeInvalidFocusHash();
        }
    }

    function onPageShow(event) {
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

    page.addEventListener('click', onPageClick);
    page.addEventListener('submit', onPageSubmit);
    resetButton?.addEventListener('click', closeFocus);
    closeButton?.addEventListener('click', closeFocus);
    documentRef.addEventListener('keydown', onKeyDown);
    windowRef.addEventListener('popstate', onPopState);
    windowRef.addEventListener('pageshow', onPageShow);

    const initialFocusId = focusIdFromLocation(windowRef);
    if (initialFocusId) {
        if (!openFocus(initialFocusId, { historyMode: 'none' })) {
            clearFocus();
            removeInvalidFocusHash();
        }
    } else {
        clearFocus();
    }

    if (page.dataset.mapReprojected === '1') {
        delete page.dataset.mapReprojected;
        clearPending(windowRef);
        showUpdatedStatus();
        trackTelemetry('map_reprojected');
    }

    return {
        openFocus,
        clearFocus,
        revalidateProjection,
        markPendingReevaluation,
        destroy,
        get activeFocusId() {
            return activeFocusId;
        },
    };
}
