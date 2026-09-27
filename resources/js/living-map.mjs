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

export function mountLivingGoalMap({
    documentRef = globalThis.document,
    windowRef = globalThis.window,
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
    const nodeElements = [...page.querySelectorAll('[data-map-node]')];
    const edgeElements = [...page.querySelectorAll('[data-map-edge]')];
    const templates = [...page.querySelectorAll('[data-map-surface-template]')];

    const nodes = nodeElements.map((element) => ({
        id: element.dataset.mapNodeId,
        positionRole: element.dataset.mapPositionRole || '',
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
    let activeFocusId = null;

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
        if (focusIdFromLocation(windowRef)) {
            windowRef.history.replaceState(
                windowRef.history.state,
                '',
                windowRef.location.pathname + windowRef.location.search
            );
        }
    };

    page.addEventListener('click', (event) => {
        const node = event.target.closest?.('[data-map-node]');
        if (!node || !page.contains(node)) return;
        if (!(node instanceof windowRef.HTMLAnchorElement)) return;
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        const nodeId = node.dataset.mapNodeId;
        if (!nodeId) return;

        event.preventDefault();
        openFocus(nodeId);
    });

    resetButton?.addEventListener('click', closeFocus);
    closeButton?.addEventListener('click', closeFocus);

    documentRef.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && activeFocusId) closeFocus();
    });

    windowRef.addEventListener('popstate', () => {
        const nodeId = focusIdFromLocation(windowRef);
        if (nodeId) openFocus(nodeId, { historyMode: 'none' });
        else clearFocus();
    });

    const initialFocusId = focusIdFromLocation(windowRef);
    if (initialFocusId) openFocus(initialFocusId, { historyMode: 'none' });
    else clearFocus();

    return {
        openFocus,
        clearFocus,
        get activeFocusId() {
            return activeFocusId;
        },
    };
}
