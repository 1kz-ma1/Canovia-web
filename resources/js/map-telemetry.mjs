const FLOW_KEY = 'canovia.map.telemetry-flow.v1';
const MAX_FLOW_AGE_MS = 30 * 60 * 1000;

function storage(windowRef) {
    try {
        return windowRef?.sessionStorage || null;
    } catch (_) {
        return null;
    }
}

function uuid(windowRef) {
    if (windowRef?.crypto?.randomUUID) return windowRef.crypto.randomUUID();

    const bytes = new Uint8Array(16);
    if (windowRef?.crypto?.getRandomValues) windowRef.crypto.getRandomValues(bytes);
    else bytes.forEach((_, index) => { bytes[index] = Math.floor(Math.random() * 256); });
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = [...bytes].map((value) => value.toString(16).padStart(2, '0')).join('');
    return hex.slice(0, 8) + '-' + hex.slice(8, 12) + '-' + hex.slice(12, 16) + '-' + hex.slice(16, 20) + '-' + hex.slice(20);
}

function validFlow(flow, nowMs) {
    return Boolean(
        flow
        && typeof flow.id === 'string'
        && /^[0-9a-f-]{36}$/i.test(flow.id)
        && Number.isFinite(Number(flow.startedAt))
        && nowMs >= Number(flow.startedAt)
        && nowMs - Number(flow.startedAt) <= MAX_FLOW_AGE_MS
    );
}

export function readMapTelemetryFlow(windowRef = globalThis.window, nowMs = Date.now()) {
    try {
        const raw = storage(windowRef)?.getItem(FLOW_KEY);
        if (!raw) return null;
        const flow = JSON.parse(raw);
        if (!validFlow(flow, nowMs)) {
            storage(windowRef)?.removeItem(FLOW_KEY);
            return null;
        }
        return {
            id: flow.id,
            startedAt: Number(flow.startedAt),
            lastActivityAt: Number(flow.lastActivityAt || flow.startedAt),
            stepCount: Math.max(0, Number(flow.stepCount || 0)),
        };
    } catch (_) {
        return null;
    }
}

function writeFlow(windowRef, flow) {
    try {
        storage(windowRef)?.setItem(FLOW_KEY, JSON.stringify(flow));
    } catch (_) {}
    return flow;
}

export function ensureMapTelemetryFlow(windowRef = globalThis.window, nowMs = Date.now()) {
    const existing = readMapTelemetryFlow(windowRef, nowMs);
    if (existing) return { flow: existing, isNew: false };

    const flow = {
        id: uuid(windowRef),
        startedAt: nowMs,
        lastActivityAt: nowMs,
        stepCount: 0,
    };
    writeFlow(windowRef, flow);

    return { flow, isNew: true };
}

export function advanceMapTelemetryFlow(windowRef = globalThis.window, nowMs = Date.now(), amount = 1) {
    const { flow } = ensureMapTelemetryFlow(windowRef, nowMs);
    const next = {
        ...flow,
        lastActivityAt: nowMs,
        stepCount: Math.min(100, Math.max(0, Number(flow.stepCount || 0) + Math.max(0, amount))),
    };
    return writeFlow(windowRef, next);
}

export function clearMapTelemetryFlow(windowRef = globalThis.window) {
    try {
        storage(windowRef)?.removeItem(FLOW_KEY);
    } catch (_) {}
}

export function mapClientSurface(windowRef = globalThis.window) {
    return windowRef?.matchMedia?.('(display-mode: standalone)').matches || windowRef?.navigator?.standalone === true
        ? 'pwa'
        : 'web';
}

export function mapClientDevice(windowRef = globalThis.window) {
    return /iPhone|iPad|iPod|Android|Mobile/i.test(windowRef?.navigator?.userAgent || '')
        ? 'mobile'
        : 'desktop';
}

export function mapTelemetryMetadata(flow, extra = {}, nowMs = Date.now()) {
    return {
        flow_id: flow.id,
        elapsed_ms: Math.max(0, Math.min(3600000, nowMs - Number(flow.startedAt))),
        step_count: Math.max(0, Math.min(100, Number(flow.stepCount || 0))),
        ...extra,
    };
}

function upsertHidden(form, name, value) {
    let input = form.querySelector('input[name="' + name + '"]');
    if (!input) {
        input = form.ownerDocument.createElement('input');
        input.type = 'hidden';
        input.name = name;
        form.appendChild(input);
    }
    input.value = String(value);
}

export function attachMapTelemetryToWorkStartForm(form, windowRef = globalThis.window, nowMs = Date.now()) {
    if (!form?.matches?.('[data-work-start-form]')) return null;
    if (form.dataset.mapTelemetryAttached === '1') return null;

    const current = readMapTelemetryFlow(windowRef, nowMs);
    if (!current) return null;

    const flow = advanceMapTelemetryFlow(windowRef, nowMs, 1);
    const elapsedMs = Math.max(0, Math.min(3600000, nowMs - flow.startedAt));

    upsertHidden(form, 'map_flow_id', flow.id);
    upsertHidden(form, 'map_flow_elapsed_ms', elapsedMs);
    upsertHidden(form, 'map_flow_step_count', flow.stepCount);
    form.dataset.mapTelemetryAttached = '1';
    clearMapTelemetryFlow(windowRef);

    return { flowId: flow.id, elapsedMs, stepCount: flow.stepCount };
}

export function advanceMapTelemetryForClassicNavigation(link, windowRef = globalThis.window, nowMs = Date.now()) {
    if (!link?.href || windowRef?.location?.pathname === '/map') return null;

    let url;
    try {
        url = new URL(link.href, windowRef.location.href);
    } catch (_) {
        return null;
    }

    if (url.origin !== windowRef.location.origin) return null;
    if (!readMapTelemetryFlow(windowRef, nowMs)) return null;

    return advanceMapTelemetryFlow(windowRef, nowMs, 1);
}