import {
    MAP_DATA_LAYER_KEYS,
    normalizeMapDataLayerState,
    persistMapDataLayerState,
    readMapDataLayerState,
} from './map-data-layers.mjs';
import { requestGlobalHomeReset } from './living-map.mjs';

export const MAP_PAGE_SCHEMA_VERSION = 1;
export const MAP_PAGE_STORAGE_KEY = 'canovia.map.pages.v1';
export const MAP_PAGE_ACTIVE_KEY = 'canovia.map.pages.active.v1';
export const MAP_PAGE_MAX_CUSTOM = 8;

const MAP_PAGE_QUERY_KEYS = Object.freeze([
    'level',
    'intent',
    'domain',
    'plan',
    'collab_context',
    'reflection_context',
]);

export function normalizeMapPageName(value) {
    return String(value || '')
        .replace(/\s+/g, ' ')
        .trim()
        .slice(0, 40);
}

export function normalizeMapPageRoute(value, base = 'https://canovia.local') {
    try {
        const url = new URL(String(value || ''), base);
        if (url.pathname !== '/map') return '/map';

        const query = new URLSearchParams();
        for (const key of MAP_PAGE_QUERY_KEYS) {
            const item = url.searchParams.get(key);
            if (item !== null && item !== '') query.set(key, item);
        }

        const serialized = query.toString();
        return '/map' + (serialized ? '?' + serialized : '');
    } catch (_) {
        return '/map';
    }
}

export function createMapPagePreset({
    id,
    name,
    route,
    layers,
    createdAt = Date.now(),
} = {}) {
    const normalizedName = normalizeMapPageName(name);
    const normalizedId = String(id || '').trim();

    if (!normalizedId || !normalizedName) return null;

    return {
        id: normalizedId,
        name: normalizedName,
        route: normalizeMapPageRoute(route),
        layers: normalizeMapDataLayerState(layers, MAP_DATA_LAYER_KEYS, []),
        created_at: Math.max(0, Number(createdAt || 0)),
    };
}

export function normalizeMapPageState(value, maxCustom = MAP_PAGE_MAX_CUSTOM) {
    const rawPages = Array.isArray(value?.pages) ? value.pages : [];
    const pages = [];
    const seen = new Set();

    for (const raw of rawPages) {
        const page = createMapPagePreset(raw || {});
        if (!page || seen.has(page.id)) continue;
        seen.add(page.id);
        pages.push(page);
        if (pages.length >= Math.max(0, Number(maxCustom || MAP_PAGE_MAX_CUSTOM))) break;
    }

    return {
        version: MAP_PAGE_SCHEMA_VERSION,
        pages,
    };
}

export function parseMapPageState(raw, maxCustom = MAP_PAGE_MAX_CUSTOM) {
    if (typeof raw !== 'string' || raw.trim() === '') {
        return normalizeMapPageState(null, maxCustom);
    }

    try {
        const parsed = JSON.parse(raw);
        if (Number(parsed?.version) !== MAP_PAGE_SCHEMA_VERSION) {
            return normalizeMapPageState(null, maxCustom);
        }
        return normalizeMapPageState(parsed, maxCustom);
    } catch (_) {
        return normalizeMapPageState(null, maxCustom);
    }
}

export function moveMapPage(state, pageId, direction) {
    const normalized = normalizeMapPageState(state);
    const index = normalized.pages.findIndex((page) => page.id === pageId);
    const delta = Number(direction) < 0 ? -1 : 1;
    const target = index + delta;

    if (index < 0 || target < 0 || target >= normalized.pages.length) {
        return normalized;
    }

    const pages = [...normalized.pages];
    [pages[index], pages[target]] = [pages[target], pages[index]];

    return {
        version: MAP_PAGE_SCHEMA_VERSION,
        pages,
    };
}

export function removeMapPage(state, pageId) {
    const normalized = normalizeMapPageState(state);

    return {
        version: MAP_PAGE_SCHEMA_VERSION,
        pages: normalized.pages.filter((page) => page.id !== pageId),
    };
}

export function resolveMapPageActiveId({
    currentRoute,
    storedActiveId,
    customPages = [],
    builtinPages = [],
} = {}) {
    const route = normalizeMapPageRoute(currentRoute);
    const custom = Array.isArray(customPages) ? customPages : [];
    const builtins = Array.isArray(builtinPages) ? builtinPages : [];
    const stored = String(storedActiveId || '');

    const storedCustom = custom.find((page) => page.id === stored);
    if (storedCustom && storedCustom.route === route) return storedCustom.id;

    const storedBuiltin = builtins.find((page) => page.id === stored);
    if (storedBuiltin && storedBuiltin.route === route) return storedBuiltin.id;

    const exactCustom = custom.find((page) => page.route === route);
    if (exactCustom) return exactCustom.id;

    const exactBuiltin = builtins.find((page) => page.route === route);
    if (exactBuiltin) return exactBuiltin.id;

    return storedCustom?.id || storedBuiltin?.id || null;
}

function readState(storage, maxCustom) {
    try {
        return parseMapPageState(storage?.getItem?.(MAP_PAGE_STORAGE_KEY) ?? '', maxCustom);
    } catch (_) {
        return normalizeMapPageState(null, maxCustom);
    }
}

function persistState(storage, state, maxCustom) {
    const normalized = normalizeMapPageState(state, maxCustom);

    try {
        storage?.setItem?.(MAP_PAGE_STORAGE_KEY, JSON.stringify(normalized));
    } catch (_) {}

    return normalized;
}

function readActiveId(storage) {
    try {
        return String(storage?.getItem?.(MAP_PAGE_ACTIVE_KEY) || '');
    } catch (_) {
        return '';
    }
}

function persistActiveId(storage, pageId) {
    try {
        if (pageId) storage?.setItem?.(MAP_PAGE_ACTIVE_KEY, String(pageId));
        else storage?.removeItem?.(MAP_PAGE_ACTIVE_KEY);
    } catch (_) {}
}

function newPageId(windowRef) {
    const uuid = windowRef?.crypto?.randomUUID?.();
    if (uuid) return 'page:' + uuid;

    return 'page:' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 9);
}

function currentRoute(control, windowRef) {
    return normalizeMapPageRoute(
        control.closest?.('[data-map-surface-controls]')?.dataset.mapCurrentRoute
            || windowRef?.location?.href
            || '/map',
        windowRef?.location?.origin || 'https://canovia.local',
    );
}

function currentLabel(control) {
    return normalizeMapPageName(
        control.closest?.('[data-map-surface-controls]')?.dataset.mapCurrentLabel
            || 'Mapページ',
    ) || 'Mapページ';
}

function builtinPages(control, windowRef) {
    return [...control.querySelectorAll('[data-map-page-open][data-map-page-id^="builtin:"]')]
        .map((button) => ({
            id: String(button.dataset.mapPageId || ''),
            name: normalizeMapPageName(button.dataset.mapPageName),
            route: normalizeMapPageRoute(
                button.dataset.mapPageRoute,
                windowRef?.location?.origin || 'https://canovia.local',
            ),
            globalHome: button.dataset.mapPageGlobalHome === '1',
            element: button,
        }))
        .filter((page) => page.id && page.name);
}

function dispatchLayerState(documentRef, state) {
    documentRef.dispatchEvent(new CustomEvent('canovia:map-data-layers-apply', {
        detail: { state },
    }));
}

function navigateToPage(windowRef, route) {
    const instant = windowRef?.CanoviaInstantNavigation;

    if (instant?.navigate) {
        void instant.navigate(route, { historyMode: 'push', scroll: true });
        return;
    }

    windowRef?.location?.assign?.(route);
}

function renderCustomPages({
    control,
    list,
    state,
    activeId,
    documentRef,
}) {
    if (!list) return;

    list.replaceChildren();

    state.pages.forEach((page, index) => {
        const row = documentRef.createElement('div');
        row.className = 'canovia-map-page-custom-row';
        row.dataset.mapPageCustomId = page.id;

        const open = documentRef.createElement('button');
        open.type = 'button';
        open.className = 'canovia-map-page-item is-custom';
        open.dataset.mapPageOpen = '';
        open.dataset.mapPageId = page.id;
        open.setAttribute('aria-current', page.id === activeId ? 'page' : 'false');
        if (page.id === activeId) open.classList.add('is-active');

        const mark = documentRef.createElement('span');
        mark.className = 'canovia-map-page-item-mark';
        mark.setAttribute('aria-hidden', 'true');

        const copy = documentRef.createElement('span');
        copy.className = 'canovia-map-page-item-copy';

        const strong = documentRef.createElement('strong');
        strong.textContent = page.name;

        const small = documentRef.createElement('small');
        small.textContent = '保存したMap';

        copy.append(strong, small);
        open.append(mark, copy);

        const actions = documentRef.createElement('span');
        actions.className = 'canovia-map-page-item-actions';

        const up = documentRef.createElement('button');
        up.type = 'button';
        up.className = 'canovia-map-page-mini-action';
        up.dataset.mapPageMove = '-1';
        up.dataset.mapPageId = page.id;
        up.setAttribute('aria-label', page.name + 'を前へ移動');
        up.textContent = '↑';
        up.disabled = index === 0;

        const down = documentRef.createElement('button');
        down.type = 'button';
        down.className = 'canovia-map-page-mini-action';
        down.dataset.mapPageMove = '1';
        down.dataset.mapPageId = page.id;
        down.setAttribute('aria-label', page.name + 'を後ろへ移動');
        down.textContent = '↓';
        down.disabled = index === state.pages.length - 1;

        const remove = documentRef.createElement('button');
        remove.type = 'button';
        remove.className = 'canovia-map-page-mini-action is-delete';
        remove.dataset.mapPageDelete = '';
        remove.dataset.mapPageId = page.id;
        remove.setAttribute('aria-label', page.name + 'を削除');
        remove.textContent = '×';

        actions.append(up, down, remove);
        row.append(open, actions);
        list.append(row);
    });

    list.hidden = state.pages.length === 0;
    control.dataset.mapPageCustomCount = String(state.pages.length);
}

export function mountMapPages({
    documentRef = globalThis.document,
    windowRef = globalThis.window,
} = {}) {
    const page = documentRef?.querySelector?.('[data-canovia-map-page]');
    const control = page?.querySelector?.('[data-map-page-control]');

    if (!page || !control || !windowRef) return null;
    if (control.dataset.mapPagesInitialized === '1') return null;

    control.dataset.mapPagesInitialized = '1';

    const maxCustom = Math.max(
        1,
        Number(control.dataset.mapPageMaxCustom || MAP_PAGE_MAX_CUSTOM),
    );
    const storage = windowRef.localStorage;
    const builtins = builtinPages(control, windowRef);
    const list = control.querySelector('[data-map-page-custom-list]');
    const form = control.querySelector('[data-map-page-save-form]');
    const nameInput = control.querySelector('[data-map-page-name-input]');
    const note = control.querySelector('[data-map-page-note]');
    const summaryLabel = control.querySelector('[data-map-page-summary-label]');
    const count = control.querySelector('[data-map-page-count]');

    let state = readState(storage, maxCustom);
    let activeId = resolveMapPageActiveId({
        currentRoute: currentRoute(control, windowRef),
        storedActiveId: readActiveId(storage),
        customPages: state.pages,
        builtinPages: builtins,
    });

    const sync = () => {
        builtins.forEach((preset) => {
            const active = preset.id === activeId;
            preset.element.classList.toggle('is-active', active);
            preset.element.setAttribute('aria-current', active ? 'page' : 'false');
        });

        renderCustomPages({
            control,
            list,
            state,
            activeId,
            documentRef,
        });

        const activeCustom = state.pages.find((preset) => preset.id === activeId);
        const activeBuiltin = builtins.find((preset) => preset.id === activeId);
        const label = activeCustom?.name || activeBuiltin?.name || 'ページ';

        if (summaryLabel) summaryLabel.textContent = label;

        const total = builtins.length + state.pages.length;
        if (count) {
            count.textContent = String(total);
            count.hidden = total === 0;
        }

        if (nameInput && nameInput.value.trim() === '') {
            nameInput.placeholder = '例: ' + currentLabel(control);
        }

        if (note) {
            note.textContent = state.pages.length >= maxCustom
                ? '保存できるカスタムページは' + maxCustom + '件までです。不要なページを削除してください。'
                : '現在地と表示情報だけを保存します。Plan / Taskはコピーしません。';
        }
    };

    const applyPreset = (preset, { globalHome = false } = {}) => {
        if (!preset) return;

        const previousActiveId = activeId;
        activeId = preset.id;
        persistActiveId(storage, activeId);

        if (preset.layers) {
            const layers = persistMapDataLayerState(storage, preset.layers);
            dispatchLayerState(documentRef, layers);
        }

        const resolvesToGlobalHome = globalHome || preset.route === '/map';
        if (resolvesToGlobalHome) {
            requestGlobalHomeReset(windowRef);
        }

        const current = normalizeMapPageRoute(windowRef.location.href, windowRef.location.origin);
        const hasLocalSelection = String(windowRef.location.hash || '') !== '';
        const changedPage = previousActiveId !== activeId;
        sync();

        if (preset.route !== current || hasLocalSelection || resolvesToGlobalHome || changedPage) {
            navigateToPage(windowRef, preset.route);
        }
    };

    const onSubmit = (event) => {
        if (event.target !== form) return;
        event.preventDefault();

        const name = normalizeMapPageName(nameInput?.value || currentLabel(control));
        if (!name) return;

        if (state.pages.length >= maxCustom) {
            sync();
            return;
        }

        const preset = createMapPagePreset({
            id: newPageId(windowRef),
            name,
            route: currentRoute(control, windowRef),
            layers: readMapDataLayerState(storage, MAP_DATA_LAYER_KEYS, []),
            createdAt: Date.now(),
        });

        if (!preset) return;

        state = persistState(storage, {
            version: MAP_PAGE_SCHEMA_VERSION,
            pages: [...state.pages, preset],
        }, maxCustom);
        activeId = preset.id;
        persistActiveId(storage, activeId);
        if (nameInput) nameInput.value = '';
        sync();
    };

    const onClick = (event) => {
        const deleteButton = event.target.closest?.('[data-map-page-delete]');
        if (deleteButton && control.contains(deleteButton)) {
            event.preventDefault();
            const id = String(deleteButton.dataset.mapPageId || '');
            state = persistState(storage, removeMapPage(state, id), maxCustom);
            if (activeId === id) {
                activeId = resolveMapPageActiveId({
                    currentRoute: currentRoute(control, windowRef),
                    storedActiveId: '',
                    customPages: state.pages,
                    builtinPages: builtins,
                });
                persistActiveId(storage, activeId);
            }
            sync();
            return;
        }

        const moveButton = event.target.closest?.('[data-map-page-move]');
        if (moveButton && control.contains(moveButton)) {
            event.preventDefault();
            const id = String(moveButton.dataset.mapPageId || '');
            state = persistState(
                storage,
                moveMapPage(state, id, Number(moveButton.dataset.mapPageMove || 1)),
                maxCustom,
            );
            sync();
            return;
        }

        const openButton = event.target.closest?.('[data-map-page-open]');
        if (!openButton || !control.contains(openButton)) return;

        event.preventDefault();

        const id = String(openButton.dataset.mapPageId || '');
        const custom = state.pages.find((preset) => preset.id === id);
        if (custom) {
            applyPreset(custom);
            return;
        }

        const builtin = builtins.find((preset) => preset.id === id);
        if (builtin) {
            applyPreset(builtin, { globalHome: builtin.globalHome });
        }
    };

    form?.addEventListener('submit', onSubmit);
    control.addEventListener('click', onClick);
    sync();

    return {
        get state() {
            return normalizeMapPageState(state, maxCustom);
        },
        get activeId() {
            return activeId;
        },
        destroy() {
            form?.removeEventListener('submit', onSubmit);
            control.removeEventListener('click', onClick);
            delete control.dataset.mapPagesInitialized;
        },
    };
}

function mountCurrentMapPages() {
    mountMapPages();
}

if (globalThis.document?.addEventListener) {
    globalThis.document.addEventListener('DOMContentLoaded', mountCurrentMapPages);
    globalThis.document.addEventListener('canovia:page-ready', mountCurrentMapPages);
}
