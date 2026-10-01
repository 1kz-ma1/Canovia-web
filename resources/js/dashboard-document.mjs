const mountedDashboardDocuments = new WeakMap();
const activeDashboardDocuments = new Set();

export function documentFitScale({
    viewportWidth = 0,
    viewportHeight = 0,
    documentWidth = 0,
    documentHeight = 0,
    paddingX = 0,
    paddingY = 0,
    minScale = 0.1,
    maxScale = 1,
} = {}) {
    const viewportW = Math.max(0, Number(viewportWidth || 0) - Math.max(0, Number(paddingX || 0)));
    const viewportH = Math.max(0, Number(viewportHeight || 0) - Math.max(0, Number(paddingY || 0)));
    const documentW = Math.max(0, Number(documentWidth || 0));
    const documentH = Math.max(0, Number(documentHeight || 0));

    if (viewportW <= 0 || viewportH <= 0 || documentW <= 0 || documentH <= 0) return 1;

    const lower = Math.max(0.05, Number(minScale || 0.1));
    const upper = Math.max(lower, Number(maxScale || 1));
    const scale = Math.min(viewportW / documentW, viewportH / documentH, upper);

    return Math.round(Math.max(lower, scale) * 10000) / 10000;
}

export function documentZoomScale(
    currentScale,
    direction,
    {
        minScale = 0.1,
        maxScale = 1.6,
        factor = 1.25,
    } = {},
) {
    const lower = Math.max(0.05, Number(minScale || 0.1));
    const upper = Math.max(lower, Number(maxScale || 1.6));
    const current = Math.max(lower, Math.min(upper, Number(currentScale || lower)));
    const stepFactor = Math.max(1.01, Number(factor || 1.25));
    const next = direction === 'out' ? current / stepFactor : current * stepFactor;

    return Math.round(Math.max(lower, Math.min(upper, next)) * 10000) / 10000;
}

export function documentPinchScale(
    startScale,
    startDistance,
    currentDistance,
    {
        minScale = 0.1,
        maxScale = 1.6,
    } = {},
) {
    const lower = Math.max(0.05, Number(minScale || 0.1));
    const upper = Math.max(lower, Number(maxScale || 1.6));
    const baseScale = Math.max(lower, Math.min(upper, Number(startScale || lower)));
    const baseDistance = Math.max(1, Number(startDistance || 1));
    const distance = Math.max(1, Number(currentDistance || 1));

    return Math.round(Math.max(lower, Math.min(upper, baseScale * (distance / baseDistance))) * 10000) / 10000;
}

export function documentRegionFocusScale({
    viewportWidth = 0,
    viewportHeight = 0,
    regionWidth = 0,
    regionHeight = 0,
    paddingX = 72,
    paddingY = 72,
    minScale = 0.1,
    maxScale = 1.6,
} = {}) {
    return documentFitScale({
        viewportWidth,
        viewportHeight,
        documentWidth: regionWidth,
        documentHeight: regionHeight,
        paddingX,
        paddingY,
        minScale,
        maxScale,
    });
}

export function documentAnchorScroll({
    naturalX = 0,
    naturalY = 0,
    scale = 1,
    screenX = 0,
    screenY = 0,
    contentWidth = 0,
    contentHeight = 0,
    viewportWidth = 0,
    viewportHeight = 0,
} = {}) {
    const safeScale = Math.max(0.01, Number(scale || 1));
    const maxLeft = Math.max(0, Number(contentWidth || 0) * safeScale - Number(viewportWidth || 0));
    const maxTop = Math.max(0, Number(contentHeight || 0) * safeScale - Number(viewportHeight || 0));

    return {
        left: Math.round(Math.max(
            0,
            Math.min(maxLeft, Number(naturalX || 0) * safeScale - Number(screenX || 0)),
        ) * 10) / 10,
        top: Math.round(Math.max(
            0,
            Math.min(maxTop, Number(naturalY || 0) * safeScale - Number(screenY || 0)),
        ) * 10) / 10,
    };
}

function cameraPadding(scroll, windowRef) {
    const style = windowRef.getComputedStyle?.(scroll);
    if (!style) return { x: 0, y: 0 };

    return {
        x: (Number.parseFloat(style.paddingLeft) || 0) + (Number.parseFloat(style.paddingRight) || 0),
        y: (Number.parseFloat(style.paddingTop) || 0) + (Number.parseFloat(style.paddingBottom) || 0),
    };
}

function pointerDistance(a, b) {
    return Math.hypot(Number(b?.x || 0) - Number(a?.x || 0), Number(b?.y || 0) - Number(a?.y || 0));
}

function pointerMidpoint(a, b) {
    return {
        x: (Number(a?.x || 0) + Number(b?.x || 0)) / 2,
        y: (Number(a?.y || 0) + Number(b?.y || 0)) / 2,
    };
}

function mountDashboardDocument(root, windowRef = window) {
    if (!root || mountedDashboardDocuments.has(root)) return mountedDashboardDocuments.get(root) || null;
    if (root.dataset.dashboardDocumentOwner === 'map') return null;

    const scroll = root.querySelector('[data-dashboard-document-scroll]');
    const stage = scroll?.querySelector('[data-dashboard-document-stage]');
    const canvas = stage?.querySelector('[data-dashboard-document-canvas]');
    if (!scroll || !stage || !canvas) return null;

    const state = {
        root,
        scroll,
        stage,
        canvas,
        scale: 1,
        fitScale: 1,
        naturalWidth: 0,
        naturalHeight: 0,
        pointers: new Map(),
        pinch: null,
        disposed: false,
    };

    const measure = () => {
        const width = Math.max(0, Number(canvas.offsetWidth || canvas.scrollWidth || 0));
        const height = Math.max(0, Number(canvas.offsetHeight || canvas.scrollHeight || 0));
        const viewportWidth = Math.max(0, Number(scroll.clientWidth || 0));
        const viewportHeight = Math.max(0, Number(scroll.clientHeight || 0));
        if (!width || !height || !viewportWidth || !viewportHeight) return null;

        const padding = cameraPadding(scroll, windowRef);
        state.naturalWidth = width;
        state.naturalHeight = height;
        state.fitScale = documentFitScale({
            viewportWidth,
            viewportHeight,
            documentWidth: width,
            documentHeight: height,
            paddingX: padding.x,
            paddingY: padding.y,
            minScale: 0.1,
            maxScale: 1,
        });

        return { width, height, viewportWidth, viewportHeight };
    };

    const syncControls = () => {
        const label = root.querySelector('[data-dashboard-document-zoom-label]');
        const zoomOut = root.querySelector('[data-dashboard-document-zoom-out]');
        const zoomIn = root.querySelector('[data-dashboard-document-zoom-in]');
        const fit = root.querySelector('[data-dashboard-document-fit]');
        const isFit = Math.abs(state.scale - state.fitScale) <= 0.005;

        if (label) label.textContent = Math.round(state.scale * 100)+'%';
        if (zoomOut) zoomOut.disabled = state.scale <= state.fitScale + 0.005;
        if (zoomIn) zoomIn.disabled = state.scale >= 1.595;
        if (fit) {
            fit.classList.toggle('is-active', isFit);
            fit.setAttribute('aria-pressed', isFit ? 'true' : 'false');
        }

        root.dataset.dashboardDocumentScale = state.scale.toFixed(4);
        root.dataset.dashboardDocumentFitScale = state.fitScale.toFixed(4);
        root.dataset.dashboardDocumentCameraMode = isFit ? 'fit' : 'manual';
    };

    const applyScale = (
        requestedScale,
        {
            anchor = null,
            resetScroll = false,
            preserveCenter = true,
        } = {},
    ) => {
        const measurement = measure();
        if (!measurement) return false;

        const previousScale = Math.max(0.01, state.scale || state.fitScale);
        const centerRatioX = (
            scroll.scrollLeft + measurement.viewportWidth / 2
        ) / Math.max(1, measurement.width * previousScale);
        const centerRatioY = (
            scroll.scrollTop + measurement.viewportHeight / 2
        ) / Math.max(1, measurement.height * previousScale);

        state.scale = Math.round(
            Math.max(state.fitScale, Math.min(1.6, Number(requestedScale || state.fitScale))) * 10000,
        ) / 10000;

        stage.style.setProperty('--dashboard-document-stage-width', (measurement.width * state.scale).toFixed(2)+'px');
        stage.style.setProperty('--dashboard-document-stage-height', (measurement.height * state.scale).toFixed(2)+'px');
        canvas.style.setProperty('--dashboard-document-scale', state.scale.toFixed(4));
        root.classList.add('is-dashboard-document-camera-ready');

        if (resetScroll || Math.abs(state.scale - state.fitScale) <= 0.005) {
            scroll.scrollLeft = 0;
            scroll.scrollTop = 0;
        } else if (anchor) {
            const next = documentAnchorScroll({
                naturalX: anchor.naturalX,
                naturalY: anchor.naturalY,
                scale: state.scale,
                screenX: anchor.screenX,
                screenY: anchor.screenY,
                contentWidth: measurement.width,
                contentHeight: measurement.height,
                viewportWidth: measurement.viewportWidth,
                viewportHeight: measurement.viewportHeight,
            });
            scroll.scrollLeft = next.left;
            scroll.scrollTop = next.top;
        } else if (preserveCenter) {
            const maxLeft = Math.max(0, measurement.width * state.scale - measurement.viewportWidth);
            const maxTop = Math.max(0, measurement.height * state.scale - measurement.viewportHeight);
            scroll.scrollLeft = Math.max(
                0,
                Math.min(maxLeft, centerRatioX * measurement.width * state.scale - measurement.viewportWidth / 2),
            );
            scroll.scrollTop = Math.max(
                0,
                Math.min(maxTop, centerRatioY * measurement.height * state.scale - measurement.viewportHeight / 2),
            );
        }

        syncControls();
        return true;
    };

    const fit = () => {
        const measurement = measure();
        if (!measurement) return false;
        return applyScale(state.fitScale, { resetScroll: true, preserveCenter: false });
    };

    const zoomAt = (targetScale, clientX, clientY) => {
        const measurement = measure();
        const rect = scroll.getBoundingClientRect?.();
        if (!measurement || !rect) return false;

        const screenX = Number(clientX || 0) - Number(rect.left || 0);
        const screenY = Number(clientY || 0) - Number(rect.top || 0);
        const currentScale = Math.max(0.01, state.scale || state.fitScale);

        return applyScale(targetScale, {
            preserveCenter: false,
            anchor: {
                naturalX: (Number(scroll.scrollLeft || 0) + screenX) / currentScale,
                naturalY: (Number(scroll.scrollTop || 0) + screenY) / currentScale,
                screenX,
                screenY,
            },
        });
    };

    const step = (direction) => {
        const measurement = measure();
        if (!measurement) return;
        const next = documentZoomScale(state.scale, direction, {
            minScale: state.fitScale,
            maxScale: 1.6,
            factor: 1.25,
        });
        applyScale(next, {
            resetScroll: Math.abs(next - state.fitScale) <= 0.005,
        });
    };

    const onClick = (event) => {
        if (event.target.closest('[data-dashboard-document-fit]')) {
            event.preventDefault();
            fit();
        } else if (event.target.closest('[data-dashboard-document-zoom-out]')) {
            event.preventDefault();
            step('out');
        } else if (event.target.closest('[data-dashboard-document-zoom-in]')) {
            event.preventDefault();
            step('in');
        }
    };

    const onWheel = (event) => {
        if (!event.ctrlKey || event.cancelable === false) return;
        event.preventDefault();
        event.stopPropagation();

        const factor = Math.exp(-Math.max(-60, Math.min(60, Number(event.deltaY || 0))) * 0.01);
        zoomAt(
            Math.max(state.fitScale, Math.min(1.6, state.scale * factor)),
            event.clientX,
            event.clientY,
        );
    };

    const onPointerDown = (event) => {
        if (event.pointerType !== 'touch') return;
        state.pointers.set(event.pointerId, {
            id: event.pointerId,
            x: Number(event.clientX || 0),
            y: Number(event.clientY || 0),
        });

        if (state.pointers.size === 2) {
            const [a, b] = [...state.pointers.values()];
            const distance = pointerDistance(a, b);
            const midpoint = pointerMidpoint(a, b);
            const rect = scroll.getBoundingClientRect?.();
            if (!rect || distance < 8) return;

            const screenX = midpoint.x - rect.left;
            const screenY = midpoint.y - rect.top;
            const currentScale = Math.max(0.01, state.scale || state.fitScale);
            state.pinch = {
                startScale: currentScale,
                startDistance: distance,
                naturalX: (scroll.scrollLeft + screenX) / currentScale,
                naturalY: (scroll.scrollTop + screenY) / currentScale,
            };
            root.classList.add('is-dashboard-document-pinching');
        }
    };

    const onPointerMove = (event) => {
        const point = state.pointers.get(event.pointerId);
        if (!point) return;
        point.x = Number(event.clientX || 0);
        point.y = Number(event.clientY || 0);

        if (!state.pinch || state.pointers.size < 2) return;
        event.preventDefault();
        event.stopPropagation();

        const [a, b] = [...state.pointers.values()].slice(0, 2);
        const midpoint = pointerMidpoint(a, b);
        const rect = scroll.getBoundingClientRect?.();
        if (!rect) return;

        const scale = documentPinchScale(
            state.pinch.startScale,
            state.pinch.startDistance,
            pointerDistance(a, b),
            { minScale: state.fitScale, maxScale: 1.6 },
        );

        applyScale(scale, {
            preserveCenter: false,
            anchor: {
                naturalX: state.pinch.naturalX,
                naturalY: state.pinch.naturalY,
                screenX: midpoint.x - rect.left,
                screenY: midpoint.y - rect.top,
            },
        });
    };

    const finishPointer = (event) => {
        state.pointers.delete(event.pointerId);
        if (state.pointers.size < 2) {
            state.pinch = null;
            root.classList.remove('is-dashboard-document-pinching');
        }
    };

    const onDoubleClick = (event) => {
        if (event.target.closest('a, button, input, select, textarea')) return;
        event.preventDefault();
        const next = documentZoomScale(state.scale, 'in', {
            minScale: state.fitScale,
            maxScale: 1.6,
            factor: 1.25,
        });
        zoomAt(next, event.clientX, event.clientY);
    };

    const refresh = () => {
        if (state.disposed) return;
        const wasFit = Math.abs(state.scale - state.fitScale) <= 0.005;
        const measurement = measure();
        if (!measurement) return;
        if (wasFit) fit();
        else applyScale(state.scale);
    };

    const destroy = () => {
        if (state.disposed) return;
        state.disposed = true;
        root.removeEventListener('click', onClick);
        scroll.removeEventListener('wheel', onWheel);
        scroll.removeEventListener('pointerdown', onPointerDown);
        scroll.removeEventListener('pointermove', onPointerMove);
        scroll.removeEventListener('pointerup', finishPointer);
        scroll.removeEventListener('pointercancel', finishPointer);
        scroll.removeEventListener('dblclick', onDoubleClick);
        windowRef.removeEventListener('resize', refresh);
        mountedDashboardDocuments.delete(root);
        activeDashboardDocuments.delete(state);
    };

    root.addEventListener('click', onClick);
    scroll.addEventListener('wheel', onWheel, { passive: false });
    scroll.addEventListener('pointerdown', onPointerDown);
    scroll.addEventListener('pointermove', onPointerMove, { passive: false });
    scroll.addEventListener('pointerup', finishPointer);
    scroll.addEventListener('pointercancel', finishPointer);
    scroll.addEventListener('dblclick', onDoubleClick);
    windowRef.addEventListener('resize', refresh, { passive: true });

    state.fit = fit;
    state.refresh = refresh;
    state.destroy = destroy;
    mountedDashboardDocuments.set(root, state);
    activeDashboardDocuments.add(state);

    windowRef.requestAnimationFrame?.(() => {
        if (!state.disposed) fit();
    });

    return state;
}

export function mountDashboardDocuments(root = document, { windowRef = window } = {}) {
    for (const state of [...activeDashboardDocuments]) {
        if (!state.root?.isConnected) state.destroy?.();
    }

    const documents = root.matches?.('[data-dashboard-document]')
        ? [root]
        : [...root.querySelectorAll?.('[data-dashboard-document]') || []];

    return documents
        .map((documentRoot) => mountDashboardDocument(documentRoot, windowRef))
        .filter(Boolean);
}
