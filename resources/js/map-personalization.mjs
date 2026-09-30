export const MAP_PERSONALIZATION_SCHEMA_VERSION = 1;
export const MAP_PERSONALIZATION_STORAGE_KEY = 'canovia.map.personalization.v1';
export const MAP_PERSONALIZATION_MAX_HIDDEN = 24;

function normalizedNodeId(value) {
    const id = String(value || '').trim();
    if (!id || id.length > 160) return null;
    if (!/^[A-Za-z0-9:_-]+$/.test(id)) return null;

    return id;
}

export function normalizeMapPersonalizationState(value) {
    const hidden = Array.isArray(value?.hidden_node_ids)
        ? value.hidden_node_ids
        : [];

    const ids = [];
    const seen = new Set();

    for (const raw of hidden) {
        const id = normalizedNodeId(raw);
        if (!id || seen.has(id)) continue;
        seen.add(id);
        ids.push(id);
        if (ids.length >= MAP_PERSONALIZATION_MAX_HIDDEN) break;
    }

    return {
        version: MAP_PERSONALIZATION_SCHEMA_VERSION,
        hidden_node_ids: ids,
    };
}

export function parseMapPersonalizationState(raw) {
    if (typeof raw !== 'string' || raw.trim() === '') {
        return normalizeMapPersonalizationState(null);
    }

    try {
        const parsed = JSON.parse(raw);
        if (Number(parsed?.version) !== MAP_PERSONALIZATION_SCHEMA_VERSION) {
            return normalizeMapPersonalizationState(null);
        }

        return normalizeMapPersonalizationState(parsed);
    } catch (_) {
        return normalizeMapPersonalizationState(null);
    }
}

export function readMapPersonalizationState(storage = globalThis.localStorage) {
    try {
        return parseMapPersonalizationState(
            storage?.getItem?.(MAP_PERSONALIZATION_STORAGE_KEY) ?? '',
        );
    } catch (_) {
        return normalizeMapPersonalizationState(null);
    }
}

export function persistMapPersonalizationState(
    storage,
    value,
) {
    const state = normalizeMapPersonalizationState(value);

    try {
        storage?.setItem?.(
            MAP_PERSONALIZATION_STORAGE_KEY,
            JSON.stringify(state),
        );
    } catch (_) {}

    return state;
}

export function hiddenMapPersonalizationState(state, nodeId) {
    const normalized = normalizeMapPersonalizationState(state);
    const id = normalizedNodeId(nodeId);
    if (!id) return normalized;

    return normalizeMapPersonalizationState({
        version: MAP_PERSONALIZATION_SCHEMA_VERSION,
        hidden_node_ids: [...normalized.hidden_node_ids, id],
    });
}

export function resetMapPersonalizationState() {
    return normalizeMapPersonalizationState(null);
}

function mountCurrentMapPersonalization() {
    mountMapPersonalization();
}

export function mountMapPersonalization({
    documentRef = globalThis.document,
    windowRef = globalThis.window,
} = {}) {
    const page = documentRef?.querySelector?.('[data-canovia-map-page]');
    if (!page || !windowRef) return null;
    if (page.dataset.mapPersonalizationInitialized === '1') return null;

    const personalizedNodes = [
        ...page.querySelectorAll('[data-map-personalized-node][data-map-node-id]'),
    ];
    const status = page.querySelector('[data-map-personalization-hidden-status]');
    const count = page.querySelector('[data-map-personalization-hidden-count]');
    const storage = windowRef.localStorage;

    // L0 may currently have no promoted shortcut. Keep the reset surface alive
    // so a stale local hide preference can still be cleared.
    if (personalizedNodes.length === 0 && !status) return null;

    page.dataset.mapPersonalizationInitialized = '1';

    let state = readMapPersonalizationState(storage);

    const sync = () => {
        const hidden = new Set(state.hidden_node_ids);

        for (const node of personalizedNodes) {
            const nodeId = String(node.dataset.mapNodeId || '');
            const shouldHide = hidden.has(nodeId);

            node.classList.toggle('is-personalization-hidden', shouldHide);
            node.setAttribute('aria-hidden', shouldHide ? 'true' : 'false');

            page.querySelectorAll('[data-map-edge]').forEach((edge) => {
                const touchesNode = edge.dataset.mapEdgeSource === nodeId
                    || edge.dataset.mapEdgeTarget === nodeId;

                if (!touchesNode) return;

                edge.classList.toggle('is-personalization-hidden', shouldHide);
            });
        }

        page.querySelectorAll('[data-map-personalization-hide]').forEach((button) => {
            const nodeId = String(button.dataset.mapPersonalizationNodeId || '');
            const isHidden = hidden.has(nodeId);

            button.disabled = isHidden;
            button.textContent = isHidden
                ? 'この端末で非表示中'
                : 'この端末では非表示';
        });

        if (count) {
            count.textContent = String(hidden.size);
        }

        if (status) {
            status.hidden = hidden.size === 0;
        }

        page.dataset.mapPersonalizationHiddenCount = String(hidden.size);
    };

    const commit = (next) => {
        state = persistMapPersonalizationState(storage, next);
        sync();

        documentRef.dispatchEvent?.(new windowRef.CustomEvent(
            'canovia:map-personalization-changed',
            { detail: { state } },
        ));
    };

    const onClick = (event) => {
        const hide = event.target.closest?.('[data-map-personalization-hide]');
        if (hide && page.contains(hide)) {
            event.preventDefault();
            const nodeId = String(hide.dataset.mapPersonalizationNodeId || '');
            commit(hiddenMapPersonalizationState(state, nodeId));
            return;
        }

        const reset = event.target.closest?.('[data-map-personalization-reset]');
        if (reset && page.contains(reset)) {
            event.preventDefault();
            commit(resetMapPersonalizationState());
        }
    };

    page.addEventListener('click', onClick);
    sync();

    return {
        get state() {
            return normalizeMapPersonalizationState(state);
        },
        destroy() {
            page.removeEventListener('click', onClick);
            delete page.dataset.mapPersonalizationInitialized;
        },
    };
}

if (globalThis.document?.addEventListener) {
    globalThis.document.addEventListener('DOMContentLoaded', mountCurrentMapPersonalization);
    globalThis.document.addEventListener('canovia:page-ready', mountCurrentMapPersonalization);
}
