export const MAP_DATA_LAYER_SCHEMA_VERSION = 1;
export const MAP_DATA_LAYER_STORAGE_KEY = 'canovia.map.data-layers.v1';

export const MAP_DATA_LAYER_KEYS = Object.freeze([
    'progress',
    'deadline',
    'status',
    'evidence',
    'dependency',
    'priority',
]);

function uniqueKnownLayers(values, available) {
    const allowed = new Set(
        (Array.isArray(available) ? available : MAP_DATA_LAYER_KEYS)
            .filter((layer) => MAP_DATA_LAYER_KEYS.includes(layer))
    );

    return [...new Set(Array.isArray(values) ? values : [])]
        .filter((layer) => typeof layer === 'string' && allowed.has(layer));
}

export function normalizeMapDataLayerState(
    value,
    available = MAP_DATA_LAYER_KEYS,
    defaultEnabled = [],
) {
    const fallback = {
        version: MAP_DATA_LAYER_SCHEMA_VERSION,
        enabled: uniqueKnownLayers(defaultEnabled, available),
    };

    if (!value || typeof value !== 'object' || Array.isArray(value)) {
        return fallback;
    }

    if (Number(value.version) !== MAP_DATA_LAYER_SCHEMA_VERSION || !Array.isArray(value.enabled)) {
        return fallback;
    }

    return {
        version: MAP_DATA_LAYER_SCHEMA_VERSION,
        enabled: uniqueKnownLayers(value.enabled, available),
    };
}

export function parseMapDataLayerState(
    raw,
    available = MAP_DATA_LAYER_KEYS,
    defaultEnabled = [],
) {
    if (typeof raw !== 'string' || raw.trim() === '') {
        return normalizeMapDataLayerState(null, available, defaultEnabled);
    }

    try {
        return normalizeMapDataLayerState(JSON.parse(raw), available, defaultEnabled);
    } catch (_) {
        return normalizeMapDataLayerState(null, available, defaultEnabled);
    }
}

export function toggleMapDataLayer(
    state,
    layer,
    enabled,
    available = MAP_DATA_LAYER_KEYS,
    defaultEnabled = [],
) {
    const normalized = normalizeMapDataLayerState(state, available, defaultEnabled);
    if (!uniqueKnownLayers([layer], available).includes(layer)) {
        return normalized;
    }

    const next = new Set(normalized.enabled);
    if (enabled) next.add(layer);
    else next.delete(layer);

    return {
        version: MAP_DATA_LAYER_SCHEMA_VERSION,
        enabled: MAP_DATA_LAYER_KEYS.filter((candidate) => next.has(candidate)),
    };
}

function csv(value) {
    return String(value || '')
        .split(',')
        .map((item) => item.trim())
        .filter(Boolean);
}

function readState(storage, available, defaults) {
    try {
        return parseMapDataLayerState(
            storage?.getItem?.(MAP_DATA_LAYER_STORAGE_KEY) ?? null,
            available,
            defaults,
        );
    } catch (_) {
        return normalizeMapDataLayerState(null, available, defaults);
    }
}

function persistState(storage, state) {
    try {
        storage?.setItem?.(MAP_DATA_LAYER_STORAGE_KEY, JSON.stringify(state));
    } catch (_) {}
}

export function applyMapDataLayerState(page, control, state, available) {
    if (!page) return;

    const enabled = new Set(state.enabled);

    for (const layer of MAP_DATA_LAYER_KEYS) {
        const attribute = 'data-map-layer-' + layer;
        if (!available.includes(layer)) {
            page.removeAttribute(attribute);
            continue;
        }

        page.setAttribute(attribute, enabled.has(layer) ? '1' : '0');
    }

    page.setAttribute('data-map-data-layer-state-version', String(MAP_DATA_LAYER_SCHEMA_VERSION));

    control?.querySelectorAll?.('[data-map-layer-toggle]')?.forEach((input) => {
        const layer = input.value;
        input.checked = enabled.has(layer);
    });

    const count = control?.querySelector?.('[data-map-data-layer-count]');
    if (count) {
        count.textContent = enabled.size > 0 ? String(enabled.size) : '';
        count.hidden = enabled.size === 0;
    }

    const summary = control?.querySelector?.('[data-map-data-layer-summary]');
    if (summary) {
        const suffix = enabled.size > 0 ? ' · ' + enabled.size + '件ON' : ' · 追加表示なし';
        summary.setAttribute('aria-label', '表示情報' + suffix);
    }
}

export function mountMapDataLayers({
    documentRef = globalThis.document,
    windowRef = globalThis.window,
} = {}) {
    const page = documentRef?.querySelector?.('[data-canovia-map-page]');
    const control = page?.querySelector?.('[data-map-data-layer-control]');

    if (!page || !control || !windowRef) return null;
    if (control.dataset.mapDataLayersInitialized === '1') return null;

    const available = uniqueKnownLayers(csv(control.dataset.mapDataLayerAvailable), MAP_DATA_LAYER_KEYS);
    const defaults = uniqueKnownLayers(csv(control.dataset.mapDataLayerDefault), available);

    if (available.length === 0) return null;

    control.dataset.mapDataLayersInitialized = '1';

    let state = readState(windowRef.localStorage, available, defaults);
    applyMapDataLayerState(page, control, state, available);

    const onChange = (event) => {
        const input = event.target?.closest?.('[data-map-layer-toggle]');
        if (!input || !control.contains(input)) return;

        state = toggleMapDataLayer(
            state,
            input.value,
            Boolean(input.checked),
            available,
            defaults,
        );
        persistState(windowRef.localStorage, state);
        applyMapDataLayerState(page, control, state, available);
    };

    control.addEventListener('change', onChange);

    return {
        get state() {
            return { ...state, enabled: [...state.enabled] };
        },
        destroy() {
            control.removeEventListener('change', onChange);
            delete control.dataset.mapDataLayersInitialized;
        },
    };
}

function mountCurrentMapDataLayers() {
    mountMapDataLayers();
}

if (globalThis.document?.addEventListener) {
    globalThis.document.addEventListener('DOMContentLoaded', mountCurrentMapDataLayers);
    globalThis.document.addEventListener('canovia:page-ready', mountCurrentMapDataLayers);
}
