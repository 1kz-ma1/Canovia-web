import {
    canoviaClientPlatform,
    canoviaClientSurface,
} from './client-runtime.mjs';

const PUBLIC_MODES = new Set(['overview', 'study', 'development']);
const AUTO_CONTEXT_SOURCES = new Set(['route_hint', 'plan_profile', 'default']);
const CONTEXT_SOURCES = new Set([
    'explicit',
    'manual_preference',
    'route_hint',
    'plan_profile',
    'default',
]);
const AUTO_CONTEXT_STORAGE_KEY = 'canovia.workspace_mode.auto_context.v54';

function clientDevice(windowRef) {
    return windowRef?.matchMedia?.('(max-width: 767px)')?.matches
        ? 'mobile'
        : 'desktop';
}

function firstModeBar(documentRef) {
    return documentRef?.querySelector?.('[data-workspace-mode-bar]') || null;
}

function currentContext(documentRef) {
    const bar = firstModeBar(documentRef);
    const mode = String(
        documentRef?.body?.dataset?.workspaceMode
        || bar?.dataset?.currentWorkspaceMode
        || '',
    ).trim();
    const source = String(
        documentRef?.body?.dataset?.workspaceModeSource
        || bar?.dataset?.workspaceModeSource
        || '',
    ).trim();

    return {
        bar,
        mode: PUBLIC_MODES.has(mode) ? mode : null,
        source: CONTEXT_SOURCES.has(source) ? source : null,
        eventUrl: String(bar?.dataset?.workspaceModeEventUrl || '').trim(),
    };
}

function closeOpenSwitchers(documentRef, except = null) {
    documentRef?.querySelectorAll?.(
        '[data-workspace-mode-bar] details.workspace-mode-switcher[open]',
    )?.forEach((details) => {
        if (details !== except) details.removeAttribute('open');
    });
}

function safeSessionStorage(windowRef) {
    try {
        return windowRef?.sessionStorage || null;
    } catch (_) {
        return null;
    }
}

function behaviorPayload(windowRef, metadata) {
    return {
        metadata: {
            ...metadata,
            surface: canoviaClientSurface(windowRef),
            device: clientDevice(windowRef),
            platform: canoviaClientPlatform(windowRef),
        },
    };
}

function postBehavior(
    eventUrl,
    eventType,
    metadata,
    documentRef,
    windowRef,
    fetchRef,
) {
    const csrf = documentRef?.querySelector?.(
        'meta[name="csrf-token"]',
    )?.content;
    if (!eventUrl || !csrf || typeof fetchRef !== 'function') return;

    try {
        void fetchRef(eventUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrf,
            },
            credentials: 'same-origin',
            keepalive: true,
            body: JSON.stringify({
                event_type: eventType,
                ...behaviorPayload(windowRef, metadata),
            }),
        }).catch(() => {});
    } catch (_) {}
}

export function mountWorkspaceModeRuntime({
    documentRef = globalThis.document,
    windowRef = globalThis.window,
    fetchRef = globalThis.fetch,
} = {}) {
    if (!documentRef || !windowRef) return null;
    if (!firstModeBar(documentRef)) return null;

    const emitAutoContext = () => {
        const context = currentContext(documentRef);
        if (
            !context.mode
            || !context.source
            || !AUTO_CONTEXT_SOURCES.has(context.source)
            || !context.eventUrl
        ) {
            return;
        }

        const fingerprint = `${context.mode}:${context.source}`;
        const storage = safeSessionStorage(windowRef);

        try {
            if (storage?.getItem(AUTO_CONTEXT_STORAGE_KEY) === fingerprint) {
                return;
            }
            storage?.setItem(AUTO_CONTEXT_STORAGE_KEY, fingerprint);
        } catch (_) {}

        postBehavior(
            context.eventUrl,
            'workspace_mode_auto_context',
            {
                mode: context.mode,
                source: context.source,
            },
            documentRef,
            windowRef,
            fetchRef,
        );
    };

    const emitSelection = (form) => {
        const context = currentContext(documentRef);
        if (!context.eventUrl) return;

        const option = form.querySelector?.('[data-workspace-mode-option]');
        const selectedMode = form.hasAttribute('data-workspace-mode-reset-form')
            ? 'auto'
            : String(option?.dataset?.workspaceModeOption || '').trim();

        if (
            selectedMode !== 'auto'
            && !PUBLIC_MODES.has(selectedMode)
        ) {
            return;
        }

        postBehavior(
            context.eventUrl,
            'workspace_mode_selected',
            {
                selected_mode: selectedMode,
                ...(context.mode ? { from_mode: context.mode } : {}),
                ...(context.source ? { from_source: context.source } : {}),
            },
            documentRef,
            windowRef,
            fetchRef,
        );
    };

    const onToggle = (event) => {
        const details = event.target;
        if (
            !(details instanceof windowRef.HTMLDetailsElement)
            || !details.classList.contains('workspace-mode-switcher')
            || !details.open
        ) {
            return;
        }

        closeOpenSwitchers(documentRef, details);

        const current = details.querySelector(
            '[data-workspace-mode-option][aria-current="true"]',
        );
        current?.scrollIntoView?.({
            block: 'nearest',
            inline: 'nearest',
            behavior: 'auto',
        });
    };

    const onPointerDown = (event) => {
        if (event.target?.closest?.('.workspace-mode-switcher')) return;
        closeOpenSwitchers(documentRef);
    };

    const onKeyDown = (event) => {
        if (event.key !== 'Escape') return;
        closeOpenSwitchers(documentRef);
    };

    const onSubmit = (event) => {
        const form = event.target?.closest?.(
            '.workspace-mode-option-form, [data-workspace-mode-reset-form]',
        );
        if (!(form instanceof windowRef.HTMLFormElement)) return;
        emitSelection(form);
    };

    const onPageLifecycle = () => {
        closeOpenSwitchers(documentRef);
        emitAutoContext();
    };

    const onViewportChange = () => closeOpenSwitchers(documentRef);

    documentRef.addEventListener('toggle', onToggle, true);
    documentRef.addEventListener('pointerdown', onPointerDown, true);
    documentRef.addEventListener('keydown', onKeyDown, true);
    documentRef.addEventListener('submit', onSubmit, true);
    documentRef.addEventListener('canovia:before-page-replace', onPageLifecycle);
    documentRef.addEventListener('canovia:page-ready', onPageLifecycle);
    windowRef.addEventListener('pagehide', onPageLifecycle);
    windowRef.addEventListener('orientationchange', onViewportChange);
    windowRef.visualViewport?.addEventListener('resize', onViewportChange);

    emitAutoContext();

    const api = {
        close() {
            closeOpenSwitchers(documentRef);
        },
        sync() {
            onPageLifecycle();
        },
        dispose() {
            documentRef.removeEventListener('toggle', onToggle, true);
            documentRef.removeEventListener('pointerdown', onPointerDown, true);
            documentRef.removeEventListener('keydown', onKeyDown, true);
            documentRef.removeEventListener('submit', onSubmit, true);
            documentRef.removeEventListener(
                'canovia:before-page-replace',
                onPageLifecycle,
            );
            documentRef.removeEventListener(
                'canovia:page-ready',
                onPageLifecycle,
            );
            windowRef.removeEventListener('pagehide', onPageLifecycle);
            windowRef.removeEventListener(
                'orientationchange',
                onViewportChange,
            );
            windowRef.visualViewport?.removeEventListener(
                'resize',
                onViewportChange,
            );
        },
    };

    windowRef.CanoviaWorkspaceMode = api;
    return api;
}

export {
    AUTO_CONTEXT_STORAGE_KEY,
    AUTO_CONTEXT_SOURCES,
    CONTEXT_SOURCES,
    PUBLIC_MODES,
};
