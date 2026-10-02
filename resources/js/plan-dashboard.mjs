const mountedPlanDashboards = new WeakMap();
const activePlanDashboards = new Set();

function taskTemplateFromHash(windowRef) {
    const hash = String(windowRef.location?.hash || '');
    if (!hash.startsWith('#roadmap-task=')) return null;

    try {
        return decodeURIComponent(hash.slice('#roadmap-task='.length)) || null;
    } catch (_) {
        return null;
    }
}

function taskHash(templateId) {
    return '#roadmap-task='+encodeURIComponent(String(templateId || ''));
}

function mountPlanDashboard(root, windowRef = window) {
    if (!root || mountedPlanDashboards.has(root)) {
        return mountedPlanDashboards.get(root) || null;
    }

    const overlay = root.querySelector('[data-plan-dashboard-task-detail]');
    const content = overlay?.querySelector('[data-plan-dashboard-task-detail-content]');
    const title = overlay?.querySelector('[data-plan-dashboard-task-detail-title]');
    if (!overlay || !content) return null;

    const state = {
        root,
        overlay,
        content,
        title,
        activeTemplateId: null,
        disposed: false,
    };

    const templateFor = (templateId) => {
        const normalized = String(templateId || '');
        if (!normalized) return null;

        return [...root.querySelectorAll('template[data-map-surface-template]')]
            .find((template) => template.dataset.mapSurfaceTemplate === normalized) || null;
    };

    const clear = () => {
        state.activeTemplateId = null;
        delete root.dataset.planDashboardTask;
        content.replaceChildren();
        if (title) title.textContent = 'Task Detail';
        overlay.setAttribute('aria-hidden', 'true');
        overlay.classList.remove('is-open');
        root.classList.remove('is-plan-dashboard-task-open');
    };

    const open = (templateId, { historyMode = 'push' } = {}) => {
        const normalized = String(templateId || '');
        const template = templateFor(normalized);
        if (!normalized || !template?.content) return false;

        const fragment = template.content.cloneNode(true);
        const sourceTitle = fragment.querySelector?.('[data-map-document-title-source]')?.textContent?.trim();

        content.replaceChildren(fragment);
        if (title) title.textContent = sourceTitle || 'Task Detail';

        state.activeTemplateId = normalized;
        root.dataset.planDashboardTask = normalized;
        overlay.setAttribute('aria-hidden', 'false');
        overlay.classList.add('is-open');
        root.classList.add('is-plan-dashboard-task-open');

        if (historyMode === 'push' && windowRef.location.hash !== taskHash(normalized)) {
            windowRef.history.pushState({
                ...(windowRef.history.state || {}),
                canoviaPlanDashboardTask: normalized,
            }, '', taskHash(normalized));
        }

        return true;
    };

    const close = ({ historyMode = 'auto' } = {}) => {
        if (!state.activeTemplateId && overlay.getAttribute('aria-hidden') === 'true') return;

        const shouldGoBack = historyMode === 'auto'
            && Boolean(windowRef.history.state?.canoviaPlanDashboardTask);

        clear();

        if (shouldGoBack) {
            windowRef.history.back();
            return;
        }

        if (taskTemplateFromHash(windowRef)) {
            const nextState = { ...(windowRef.history.state || {}) };
            delete nextState.canoviaPlanDashboardTask;
            windowRef.history.replaceState(
                nextState,
                '',
                String(windowRef.location.pathname || '') + String(windowRef.location.search || ''),
            );
        }
    };

    const syncFromLocation = () => {
        const requested = taskTemplateFromHash(windowRef);
        if (requested) {
            if (requested !== state.activeTemplateId) {
                open(requested, { historyMode: 'none' });
            }
            return;
        }

        if (state.activeTemplateId) clear();
    };

    const onClick = (event) => {
        const opener = event.target.closest?.('[data-roadmap-task-detail-open]');
        if (opener && root.contains(opener)) {
            event.preventDefault();
            event.stopPropagation();
            open(opener.dataset.roadmapTaskTemplate || '');
            return;
        }

        const closeControl = event.target.closest?.('[data-plan-dashboard-task-detail-close]');
        if (closeControl && root.contains(closeControl)) {
            event.preventDefault();
            close();
        }
    };

    const onKeyDown = (event) => {
        if (event.key === 'Escape' && state.activeTemplateId) {
            event.preventDefault();
            close();
        }
    };

    const destroy = () => {
        if (state.disposed) return;
        state.disposed = true;
        root.removeEventListener('click', onClick);
        windowRef.removeEventListener('keydown', onKeyDown);
        windowRef.removeEventListener('popstate', syncFromLocation);
        windowRef.removeEventListener('hashchange', syncFromLocation);
        mountedPlanDashboards.delete(root);
        activePlanDashboards.delete(state);
    };

    root.addEventListener('click', onClick);
    windowRef.addEventListener('keydown', onKeyDown);
    windowRef.addEventListener('popstate', syncFromLocation);
    windowRef.addEventListener('hashchange', syncFromLocation);

    state.open = open;
    state.close = close;
    state.destroy = destroy;

    mountedPlanDashboards.set(root, state);
    activePlanDashboards.add(state);
    syncFromLocation();

    return state;
}

export function mountStandalonePlanDashboards(root = document, { windowRef = window } = {}) {
    for (const state of [...activePlanDashboards]) {
        if (!state.root?.isConnected) state.destroy?.();
    }

    const dashboards = root.matches?.('[data-plan-dashboard-standalone]')
        ? [root]
        : [...root.querySelectorAll?.('[data-plan-dashboard-standalone]') || []];

    return dashboards
        .map((dashboard) => mountPlanDashboard(dashboard, windowRef))
        .filter(Boolean);
}

export {
    taskTemplateFromHash,
    taskHash,
};
