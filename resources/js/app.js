import { normalizeAiJsonText, buildAiJsonRepairPrompt } from './ai-json.mjs';
import { mountInstantStartServiceWorker } from './instant-start.mjs';
import { mountCanoviaInstantNavigation } from './instant-navigation.mjs';
import { mountCanoviaInteractionPerformance } from './interaction-performance.mjs';
import { mountWorkspaceModeRuntime } from './workspace-mode-runtime.mjs';
import './study-practice-one-question.mjs';
import {
    canoviaClientSurface,
    isCanoviaNativeRuntime,
    mountCanoviaNativeBridge,
} from './client-runtime.mjs';
import { mountLivingGoalMap } from './living-map.mjs';
import { mountDashboardDocuments } from './dashboard-document.mjs';
import { mountStandalonePlanDashboards } from './plan-dashboard.mjs';
import { mountConstellationRoadmap } from './constellation-roadmap.mjs';
import './map-data-layers.mjs';
import './map-pages.mjs';
import './map-personalization.mjs';
import './space-station-intake.mjs';
import {
    advanceMapTelemetryForClassicNavigation,
    attachMapTelemetryToWorkStartForm,
} from './map-telemetry.mjs';

const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

document.addEventListener('canovia:before-instant-navigation', (event) => {
    const link = event.detail?.link;
    if (link) advanceMapTelemetryForClassicNavigation(link);
});


document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-copy-text], [data-copy-target]');
    if (!button) return;

    const target = button.dataset.copyTarget
        ? document.querySelector(button.dataset.copyTarget)
        : null;
    const text = target && 'value' in target
        ? target.value
        : (button.dataset.copyText || '');
    if (!text) return;

    const original = button.textContent;
    try {
        await navigator.clipboard.writeText(text);
        button.textContent = 'コピーしました';
    } catch (_) {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.setAttribute('readonly', '');
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        textarea.remove();
        button.textContent = 'コピーしました';
    }
    window.setTimeout(() => { button.textContent = original; }, 1600);
});


document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-paste-target]');
    if (!button) return;

    const target = document.querySelector(button.dataset.pasteTarget || '');
    if (!(target instanceof HTMLTextAreaElement || target instanceof HTMLInputElement)) return;

    const original = button.textContent;
    const fallback = button.dataset.pasteFallback
        ? document.querySelector(button.dataset.pasteFallback)
        : target.closest('details');
    const status = button.dataset.pasteStatus
        ? document.querySelector(button.dataset.pasteStatus)
        : null;

    const openFallback = (message) => {
        if (fallback instanceof HTMLDetailsElement) fallback.open = true;
        target.focus();
        if (status) {
            status.textContent = message;
            status.classList.remove('hidden');
        }
    };

    try {
        if (!navigator.clipboard?.readText) {
            throw new Error('clipboard-read-unsupported');
        }

        const text = await navigator.clipboard.readText();
        if (!text.trim()) {
            throw new Error('clipboard-empty');
        }

        target.value = text;
        target.dispatchEvent(new Event('input', { bubbles: true }));
        target.dispatchEvent(new Event('change', { bubbles: true }));
        button.textContent = '貼り付けました';

        if (status) {
            status.textContent = 'クリップボードの内容を読み込みました。';
            status.classList.remove('hidden');
        }

        if (button.dataset.pasteSubmit === '1') {
            const form = target.closest('form');
            window.setTimeout(() => form?.requestSubmit(), 120);
        }
    } catch (error) {
        const message = error?.message === 'clipboard-empty'
            ? 'クリップボードが空でした。手動貼り付け欄を開きました。'
            : 'このブラウザではクリップボードを直接読めません。手動貼り付け欄を開きました。';
        openFallback(message);
        button.textContent = '手動貼り付けを開きました';
    }

    window.setTimeout(() => {
        button.textContent = original;
    }, 1800);
});

function recordBehavior(root, eventType, payload = {}) {
    if (!root?.dataset.eventUrl || !csrfToken) return;

    fetch(root.dataset.eventUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': csrfToken,
        },
        credentials: 'same-origin',
        keepalive: true,
        body: JSON.stringify({ event_type: eventType, ...payload }),
    }).catch(() => {});
}

function canoviaClientDevice() {
    return /iPhone|iPad|iPod|Android|Mobile/i.test(navigator.userAgent) ? 'mobile' : 'desktop';
}

function isAiPlanFunnelForm(form) {
    return form instanceof HTMLFormElement && (
        form.matches('[data-ai-plan-generation-import]')
        || form.matches('[data-async-plan-review]')
        || form.matches('[data-review-json-preview]')
        || form.matches('#review-apply-form')
    );
}

function ensureAiPlanFunnelSurface(form) {
    if (!isAiPlanFunnelForm(form)) return;

    let input = form.querySelector('input[name="_client_surface"]');
    if (!input) {
        input = document.createElement('input');
        input.type = 'hidden';
        input.name = '_client_surface';
        form.appendChild(input);
    }
    input.value = canoviaClientSurface();
}

// Use delegated handlers because the review conversation root can be replaced
// after prompt generation. Newly-rendered forms/buttons must be tracked too.
document.addEventListener('submit', (event) => {
    ensureAiPlanFunnelSurface(event.target);
}, true);

document.addEventListener('click', (event) => {
    const trigger = event.target.closest?.('[data-funnel-event]');
    if (!trigger) return;

    const root = trigger.closest('[data-funnel-root]');
    const planId = Number(root?.dataset.planId || 0);
    recordBehavior(root, trigger.dataset.funnelEvent, {
        ...(planId > 0 ? { plan_id: planId } : {}),
        metadata: {
            surface: canoviaClientSurface(),
            device: canoviaClientDevice(),
        },
    });
});

function formatTimer(totalSeconds) {
    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;
    return hours > 0
        ? `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`
        : `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
}

function aiJsonInputForForm(form) {
    return form?.querySelector?.('textarea[name="operations_json"], textarea[name="tasks_json"], textarea[name="ai_json"], textarea[name="assignment_json"]') || null;
}

function clearAiJsonErrorBubble(form) {
    const owner = form?.dataset?.aiJsonErrorOwner;
    if (!owner) return;
    document.querySelectorAll(`[data-ai-json-error-owner="${owner}"]`).forEach((node) => node.remove());
}

async function copyTextSafely(value) {
    const text = String(value || '');
    try {
        await navigator.clipboard.writeText(text);
        return true;
    } catch (_) {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.setAttribute('readonly', '');
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        textarea.style.pointerEvents = 'none';
        document.body.appendChild(textarea);
        textarea.select();
        textarea.setSelectionRange(0, textarea.value.length);
        let copied = false;
        try { copied = document.execCommand('copy'); } catch (_) {}
        textarea.remove();
        return copied;
    }
}

function renderAiJsonErrorBubble(form, message, originalJson) {
    if (!form) return;
    document.getElementById('review-errors')?.remove();
    if (!form.dataset.aiJsonErrorOwner) {
        form.dataset.aiJsonErrorOwner = `ai-json-${Date.now()}-${Math.random().toString(16).slice(2)}`;
    }
    const owner = form.dataset.aiJsonErrorOwner;
    clearAiJsonErrorBubble(form);

    const row = document.createElement('div');
    row.className = 'assistant-message-row assistant-message-left mt-4';
    row.dataset.aiJsonErrorOwner = owner;

    const avatar = document.createElement('div');
    avatar.className = 'assistant-avatar';
    avatar.textContent = 'CV';

    const bubble = document.createElement('div');
    bubble.className = 'assistant-bubble assistant-bubble-support assistant-wide-bubble';

    const speaker = document.createElement('p');
    speaker.className = 'assistant-speaker';
    speaker.textContent = 'Canovia サポーター';

    const title = document.createElement('h3');
    title.className = 'mt-2 text-lg font-bold text-slate-100';
    title.textContent = 'JSONを読み込めませんでした';

    const guidance = document.createElement('p');
    guidance.className = 'mt-2 text-sm leading-6 text-slate-300';
    guidance.textContent = '入力を消す必要はありません。下の修正依頼をコピーして、さっきJSONを作ったAIへ送ってください。修正版JSONが返ったら同じ欄へ貼り直せます。';

    const errorBox = document.createElement('div');
    errorBox.className = 'assistant-notice assistant-notice-error mt-4';
    const errorLabel = document.createElement('p');
    errorLabel.className = 'font-bold';
    errorLabel.textContent = 'Canoviaが検出した内容';
    const errorCopy = document.createElement('p');
    errorCopy.className = 'mt-1 text-sm leading-6';
    errorCopy.textContent = String(message || 'JSONを読み込めませんでした。');
    errorBox.append(errorLabel, errorCopy);

    const repairPrompt = buildAiJsonRepairPrompt(message, originalJson);
    const prompt = document.createElement('textarea');
    prompt.className = 'form-control mt-4 min-h-[220px] font-mono text-xs';
    prompt.readOnly = true;
    prompt.value = repairPrompt;
    prompt.setAttribute('aria-label', 'AIへ送るJSON修正依頼');

    const actions = document.createElement('div');
    actions.className = 'mt-3 flex flex-wrap items-center gap-3';
    const copyButton = document.createElement('button');
    copyButton.type = 'button';
    copyButton.className = 'btn-primary';
    copyButton.textContent = '修正依頼をコピー';
    const copyStatus = document.createElement('span');
    copyStatus.className = 'text-xs text-slate-400';
    copyButton.addEventListener('click', async () => {
        const copied = await copyTextSafely(repairPrompt);
        copyStatus.textContent = copied
            ? 'コピーしました。JSONを作ったAIへそのまま送ってください。'
            : '自動コピーできませんでした。上の文章を選択してコピーしてください。';
        if (!copied) {
            prompt.focus();
            prompt.select();
        }
    });
    actions.append(copyButton, copyStatus);

    bubble.append(speaker, title, guidance, errorBox, prompt, actions);
    row.append(avatar, bubble);

    const messageRow = form.closest('.assistant-message-row');
    if (messageRow?.parentElement) messageRow.insertAdjacentElement('afterend', row);
    else form.insertAdjacentElement('afterend', row);

    requestAnimationFrame(() => row.scrollIntoView({ behavior: 'smooth', block: 'center' }));
}

// Normalize AI JSON before page-specific validation runs. This keeps all AI
// import surfaces consistent and prevents harmless wrappers/trailing commas
// from becoming user-facing syntax errors.
document.addEventListener('submit', (event) => {
    const form = event.target instanceof HTMLFormElement ? event.target : null;
    const input = aiJsonInputForForm(form);
    if (!form || !input || !input.value.trim()) return;

    // Critical plan JSON imports are intentionally server-owned. Do not let
    // any client parser block the browser's native POST on Safari/PWA.
    if (form.matches('[data-review-json-preview], [data-ai-plan-generation-import]')) return;

    const original = input.value;
    try {
        const normalized = normalizeAiJsonText(original);
        input.value = normalized.text;
        clearAiJsonErrorBubble(form);
    } catch (error) {
        event.preventDefault();
        event.stopImmediatePropagation();
        renderAiJsonErrorBubble(form, error?.message || 'JSONを読み込めませんでした。', original);
    }
}, true);

document.addEventListener('input', (event) => {
    const input = event.target instanceof HTMLTextAreaElement ? event.target : null;
    if (!input || !['operations_json', 'tasks_json', 'ai_json', 'assignment_json'].includes(input.name)) return;
    const form = input.closest('form');
    clearAiJsonErrorBubble(form);
});

function updateTimers() {
    document.querySelectorAll('[data-work-timer]').forEach((element) => {
        const startedAt = Date.parse(element.dataset.startedAt || '');
        if (!Number.isFinite(startedAt)) return;

        const pausedSeconds = Number(element.dataset.pausedSeconds || 0);
        const pausedAt = Date.parse(element.dataset.pausedAt || '');
        const status = element.dataset.sessionStatus || 'active';
        const now = Date.now();
        let currentPauseSeconds = 0;

        if (status === 'paused' && Number.isFinite(pausedAt)) {
            currentPauseSeconds = Math.max(0, Math.floor((now - pausedAt) / 1000));
        }

        const wallSeconds = Math.max(0, Math.floor((now - startedAt) / 1000));
        const activeSeconds = Math.max(0, wallSeconds - pausedSeconds - currentPauseSeconds);
        element.textContent = formatTimer(activeSeconds);
    });
}

let canoviaWorkTimerInterval = null;

function stopWorkTimerTicker() {
    if (canoviaWorkTimerInterval === null) return;
    window.clearInterval(canoviaWorkTimerInterval);
    canoviaWorkTimerInterval = null;
}

function syncWorkTimerTicker() {
    const hasTimer = Boolean(document.querySelector('[data-work-timer]'));
    const shouldRun = hasTimer && document.visibilityState === 'visible';

    if (!shouldRun) {
        stopWorkTimerTicker();
        return;
    }

    updateTimers();
    if (canoviaWorkTimerInterval !== null) return;

    canoviaWorkTimerInterval = window.setInterval(() => {
        if (document.visibilityState !== 'visible' || !document.querySelector('[data-work-timer]')) {
            stopWorkTimerTicker();
            return;
        }

        updateTimers();
    }, 1000);
}

document.addEventListener('visibilitychange', syncWorkTimerTicker);
document.addEventListener('canovia:before-page-replace', stopWorkTimerTicker);
document.addEventListener('canovia:page-ready', syncWorkTimerTicker);


const CANOVIA_TIMER_AWAY_THRESHOLD_MS = 15 * 60 * 1000;
const CANOVIA_TIMER_HEARTBEAT_MS = 60 * 1000;

function timerActiveSeconds(element) {
    if (!element) return 0;
    const startedAt = Date.parse(element.dataset.startedAt || '');
    if (!Number.isFinite(startedAt)) return 0;
    const pausedSeconds = Number(element.dataset.pausedSeconds || 0);
    const pausedAt = Date.parse(element.dataset.pausedAt || '');
    const status = element.dataset.sessionStatus || 'active';
    let currentPauseSeconds = 0;
    if (status === 'paused' && Number.isFinite(pausedAt)) {
        currentPauseSeconds = Math.max(0, Math.floor((Date.now() - pausedAt) / 1000));
    }
    return Math.max(0, Math.floor((Date.now() - startedAt) / 1000) - pausedSeconds - currentPauseSeconds);
}

function mountWorkTimerSafety() {
    const root = document.querySelector('[data-work-session-safety]');
    if (!root) return;
    const timer = root.querySelector('[data-work-timer]');
    if (!timer) return;

    const sessionId = root.dataset.sessionId;
    const sessionStatus = root.dataset.sessionStatus || 'active';
    const intendedMinutes = Number(root.dataset.intendedMinutes || 0);
    const basePausedSeconds = Number(root.dataset.pausedSeconds || timer.dataset.pausedSeconds || 0);
    const storageKey = `canovia.timer.safety.${sessionId}`;
    const finishForms = [...root.querySelectorAll('[data-work-finish-form]')];
    const completeForm = root.querySelector('[data-work-complete-form]') || finishForms[0];
    const awayDialog = root.querySelector('[data-timer-away-dialog]');
    const longDialog = root.querySelector('[data-timer-long-dialog]');
    const awayLabel = awayDialog?.querySelector('[data-timer-away-label]');
    const manualWrap = awayDialog?.querySelector('[data-timer-manual-wrap]');
    const manualInput = awayDialog?.querySelector('[data-timer-manual-input]');
    const longLabel = longDialog?.querySelector('[data-timer-long-label]');
    const longManualWrap = longDialog?.querySelector('[data-timer-long-manual-wrap]');
    const longManualInput = longDialog?.querySelector('[data-timer-long-manual-input]');
    let submitting = false;

    const loadState = () => {
        try {
            return JSON.parse(localStorage.getItem(storageKey) || '{}') || {};
        } catch (_) {
            return {};
        }
    };
    let state = loadState();
    state.pending_excluded_seconds = Number(state.pending_excluded_seconds || 0);

    const persist = () => {
        try { localStorage.setItem(storageKey, JSON.stringify(state)); } catch (_) {}
    };
    if (sessionStatus !== 'active') {
        // A server-side pause is authoritative. Do not carry a pagehide marker
        // from the navigation that submitted the pause into the next resume.
        state.away_started_at = null;
        state.last_heartbeat_at = new Date().toISOString();
        persist();
    }
    const openDialog = (dialog) => {
        if (!dialog) return;
        if (typeof dialog.showModal === 'function' && !dialog.open) dialog.showModal();
        else dialog.setAttribute('open', '');
    };
    const closeDialog = (dialog) => {
        if (!dialog) return;
        if (typeof dialog.close === 'function' && dialog.open) dialog.close();
        else dialog.removeAttribute('open');
    };
    const setFormValue = (form, selector, value) => {
        const input = form?.querySelector(selector);
        if (input) input.value = value ?? '';
    };
    const applyPendingPause = () => {
        const adjustedPaused = Math.max(0, basePausedSeconds + Number(state.pending_excluded_seconds || 0));
        timer.dataset.pausedSeconds = String(adjustedPaused);
        finishForms.forEach((form) => setFormValue(form, '[data-timer-extra-paused]', Math.round(state.pending_excluded_seconds || 0)));
        updateTimers();
    };
    const clearAway = () => {
        state.away_started_at = null;
        state.last_heartbeat_at = new Date().toISOString();
        persist();
    };
    const markAway = () => {
        if (sessionStatus !== 'active' || submitting) return;
        const now = new Date().toISOString();
        state.away_started_at ||= now;
        state.last_heartbeat_at = now;
        persist();
    };
    const unresolvedAwayAt = () => {
        const explicit = Date.parse(state.away_started_at || '');
        if (Number.isFinite(explicit)) return explicit;
        const heartbeat = Date.parse(state.last_heartbeat_at || '');
        if (Number.isFinite(heartbeat) && Date.now() - heartbeat >= CANOVIA_TIMER_AWAY_THRESHOLD_MS) return heartbeat;
        return null;
    };
    const showAwayReview = () => {
        if (sessionStatus !== 'active' || submitting) return false;
        const awayAt = unresolvedAwayAt();
        if (!awayAt) return false;
        if (Date.now() - awayAt < CANOVIA_TIMER_AWAY_THRESHOLD_MS) {
            // Short app switches are treated as normal work. Clear the marker
            // so multiple brief switches never accumulate into one fake absence.
            clearAway();
            return false;
        }
        state.away_started_at = new Date(awayAt).toISOString();
        persist();
        const minutes = Math.max(1, Math.round((Date.now() - awayAt) / 60000));
        if (awayLabel) awayLabel.textContent = `${new Date(awayAt).toLocaleTimeString('ja-JP', { hour: '2-digit', minute: '2-digit' })}ごろから約${minutes}分、Canoviaを離れていました。`;
        manualWrap?.classList.add('hidden');
        if (manualInput) manualInput.value = String(Math.max(1, Math.round(timerActiveSeconds(timer) / 60)));
        openDialog(awayDialog);
        return true;
    };
    const rawSubmit = (form) => {
        if (!form) return;
        submitting = true;
        HTMLFormElement.prototype.submit.call(form);
    };
    const prepareForm = (form, action = 'normal') => {
        setFormValue(form, '[data-timer-action]', action);
        setFormValue(form, '[data-timer-away-started]', state.away_started_at || '');
        setFormValue(form, '[data-timer-extra-paused]', Math.round(state.pending_excluded_seconds || 0));
    };

    applyPendingPause();

    awayDialog?.querySelectorAll('[data-timer-away-action]').forEach((button) => {
        button.addEventListener('click', () => {
            const action = button.dataset.timerAwayAction;
            const awayAt = unresolvedAwayAt();
            if (!awayAt) {
                clearAway();
                closeDialog(awayDialog);
                return;
            }
            if (action === 'continued') {
                clearAway();
                closeDialog(awayDialog);
                return;
            }
            if (action === 'break') {
                state.pending_excluded_seconds += Math.max(0, Math.floor((Date.now() - awayAt) / 1000));
                clearAway();
                applyPendingPause();
                closeDialog(awayDialog);
                return;
            }
            if (action === 'end') {
                prepareForm(completeForm, 'away_end');
                setFormValue(completeForm, '[data-timer-away-started]', new Date(awayAt).toISOString());
                setFormValue(completeForm, '[data-timer-duration-confirmed]', '1');
                rawSubmit(completeForm);
                return;
            }
            if (action === 'manual') {
                manualWrap?.classList.remove('hidden');
                manualInput?.focus();
            }
        });
    });

    awayDialog?.querySelector('[data-timer-manual-apply]')?.addEventListener('click', () => {
        const minutes = Number(manualInput?.value || 0);
        if (!Number.isFinite(minutes) || minutes < 1 || minutes > 480) {
            manualInput?.setCustomValidity('1〜480分で入力してください。');
            manualInput?.reportValidity();
            manualInput?.setCustomValidity('');
            return;
        }
        prepareForm(completeForm, 'manual');
        setFormValue(completeForm, '[data-timer-manual-minutes]', Math.round(minutes));
        setFormValue(completeForm, '[data-timer-duration-confirmed]', '1');
        rawSubmit(completeForm);
    });

    awayDialog?.querySelector('[data-timer-away-later]')?.addEventListener('click', () => closeDialog(awayDialog));

    const showLongReview = (form) => {
        const minutes = Math.max(1, Math.round(timerActiveSeconds(timer) / 60));
        if (longLabel) longLabel.textContent = `現在の実作業時間は約${minutes}分です。長時間の計測なので、保存前に確認してください。`;
        longManualWrap?.classList.add('hidden');
        if (longManualInput) longManualInput.value = String(minutes);
        longDialog.dataset.targetForm = finishForms.indexOf(form).toString();
        openDialog(longDialog);
    };

    longDialog?.querySelector('[data-timer-long-record]')?.addEventListener('click', () => {
        const form = finishForms[Number(longDialog.dataset.targetForm || 0)] || completeForm;
        prepareForm(form, 'normal');
        setFormValue(form, '[data-timer-duration-confirmed]', '1');
        rawSubmit(form);
    });
    longDialog?.querySelector('[data-timer-long-manual]')?.addEventListener('click', () => {
        longManualWrap?.classList.remove('hidden');
        longManualInput?.focus();
    });
    longDialog?.querySelector('[data-timer-long-manual-apply]')?.addEventListener('click', () => {
        const form = finishForms[Number(longDialog.dataset.targetForm || 0)] || completeForm;
        const minutes = Number(longManualInput?.value || 0);
        if (!Number.isFinite(minutes) || minutes < 1 || minutes > 480) {
            longManualInput?.setCustomValidity('1〜480分で入力してください。');
            longManualInput?.reportValidity();
            longManualInput?.setCustomValidity('');
            return;
        }
        prepareForm(form, 'manual');
        setFormValue(form, '[data-timer-manual-minutes]', Math.round(minutes));
        setFormValue(form, '[data-timer-duration-confirmed]', '1');
        rawSubmit(form);
    });
    longDialog?.querySelector('[data-timer-long-cancel]')?.addEventListener('click', () => closeDialog(longDialog));

    finishForms.forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (submitting) return;
            prepareForm(form, form.querySelector('[data-timer-action]')?.value || 'normal');
            if (showAwayReview()) {
                event.preventDefault();
                return;
            }
            const thresholdSeconds = (intendedMinutes > 0 ? Math.max(intendedMinutes * 2, 90) : 120) * 60;
            const confirmed = form.querySelector('[data-timer-duration-confirmed]')?.value === '1';
            if (!confirmed && timerActiveSeconds(timer) > thresholdSeconds) {
                event.preventDefault();
                showLongReview(form);
                return;
            }
            submitting = true;
        });
    });

    if (sessionStatus === 'active') {
        const heartbeat = () => {
            if (document.visibilityState !== 'visible' || submitting) return;
            state.last_heartbeat_at = new Date().toISOString();
            persist();
        };
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'hidden') markAway();
            else window.setTimeout(showAwayReview, 80);
        });
        window.addEventListener('pagehide', markAway);
        window.addEventListener('pageshow', () => window.setTimeout(showAwayReview, 80));
        window.setInterval(heartbeat, CANOVIA_TIMER_HEARTBEAT_MS);
        // Check the persisted heartbeat before writing a new one. This catches
        // abrupt browser/PWA termination where pagehide never had a chance to run.
        window.setTimeout(() => {
            if (!showAwayReview()) heartbeat();
        }, 160);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    syncWorkTimerTicker();

    const reviewRoot = document.getElementById('workSessionReview');
    if (reviewRoot) {
        const continuationFields = reviewRoot.querySelector('[data-continuation-fields]');
        const outcomeInputs = [...reviewRoot.querySelectorAll('input[name="task_outcome"]')];
        const updateContinuationFields = () => {
            const selected = outcomeInputs.find((input) => input.checked)?.value;
            continuationFields?.classList.toggle('hidden', selected !== 'checkpoint');
        };
        outcomeInputs.forEach((input) => input.addEventListener('change', updateContinuationFields));
        updateContinuationFields();
    }

    const root = document.getElementById('behaviorDashboard');
    if (!root) return;

    let planSwitches = 0;
    let taskViews = 0;
    let workStarted = root.dataset.workStarted === '1';
    let idleNudgeShown = false;
    const enteredAt = Date.now();
    const viewedTaskIds = new Set();
    const tabs = [...root.querySelectorAll('[data-dashboard-tab]')];
    const panels = [...root.querySelectorAll('[data-dashboard-panel]')];
    let activeTarget = tabs.find((tab) => tab.getAttribute('aria-selected') === 'true')?.dataset.dashboardTab || 'overall';

    function updateNavigationContext(planId = null) {
        const baseUrl = root.dataset.navigationUrl;
        if (!baseUrl) return;

        const url = new URL(baseUrl, window.location.origin);
        if (planId) {
            url.searchParams.set('plan_id', String(planId));
        } else {
            url.searchParams.delete('plan_id');
        }

        document.querySelectorAll('[data-navigation-link]').forEach((link) => {
            link.href = url.pathname + url.search;
        });
    }

    function recordTaskView(details) {
        const taskId = Number(details?.dataset.taskId);
        if (!taskId || viewedTaskIds.has(taskId)) return;
        viewedTaskIds.add(taskId);
        taskViews = viewedTaskIds.size;
        recordBehavior(root, 'task_viewed', {
            plan_id: Number(details.dataset.planId),
            task_id: taskId,
            metadata: { task_views: taskViews },
        });
    }

    function activateTab(target) {
        if (target === activeTarget) return;
        activeTarget = target;
        tabs.forEach((tab) => {
            const active = tab.dataset.dashboardTab === target;
            tab.classList.toggle('nav-link-active', active);
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        panels.forEach((panel) => panel.classList.toggle('hidden', panel.dataset.dashboardPanel !== target));

        const tab = tabs.find((item) => item.dataset.dashboardTab === target);
        const contextPlanId = tab?.dataset.planId ? Number(tab.dataset.planId) : null;
        updateNavigationContext(contextPlanId);

        if (contextPlanId) {
            planSwitches += 1;
            recordBehavior(root, 'plan_tab_viewed', { plan_id: contextPlanId, metadata: { plan_switches: planSwitches } });
            const panel = panels.find((item) => item.dataset.dashboardPanel === target);
            recordTaskView(panel?.querySelector('[data-task-view]'));
        }
    }

    const initialTab = tabs.find((tab) => tab.dataset.dashboardTab === activeTarget);
    updateNavigationContext(initialTab?.dataset.planId ? Number(initialTab.dataset.planId) : null);
    tabs.forEach((tab) => tab.addEventListener('click', () => activateTab(tab.dataset.dashboardTab)));
    root.querySelectorAll('[data-open-dashboard-tab]').forEach((button) => button.addEventListener('click', () => activateTab(button.dataset.openDashboardTab)));
    root.querySelectorAll('[data-task-view]').forEach((element) => element.addEventListener('click', () => recordTaskView(element)));
    document.querySelectorAll('[data-work-start-form]').forEach((form) => form.addEventListener('submit', () => { workStarted = true; }));

    const dashboardIdleInterval = window.setInterval(() => {
        if (!root.isConnected) {
            window.clearInterval(dashboardIdleInterval);
            return;
        }

        const elapsedSeconds = Math.floor((Date.now() - enteredAt) / 1000);
        if (document.visibilityState !== 'visible' || workStarted || elapsedSeconds < 90 || planSwitches + taskViews < 2) return;
        if (!idleNudgeShown) {
            root.querySelector('[data-idle-nudge]')?.classList.remove('hidden');
            idleNudgeShown = true;
        }
        recordBehavior(root, 'dashboard_idle', {
            metadata: {
                elapsed_seconds: elapsedSeconds,
                plan_switches: planSwitches,
                task_views: taskViews,
                page_visible: true,
                work_started: false,
            },
        });
    }, 15000);
});

// -----------------------------------------------------------------------------
// Mobile app shell enhancements
// -----------------------------------------------------------------------------
document.addEventListener('DOMContentLoaded', () => {
    // Remove one-shot navigation flags after the real app has loaded
    // successfully, without triggering another navigation.
    const currentUrl = new URL(window.location.href);
    const justAppliedUpdate = currentUrl.searchParams.has('_canovia_update');
    const oneShotFlags = ['_pk_network', '_canovia_network', '_canovia_update', '_canovia_stable'];
    if (oneShotFlags.some((key) => currentUrl.searchParams.has(key))) {
        oneShotFlags.forEach((key) => currentUrl.searchParams.delete(key));
        window.history.replaceState({}, '', currentUrl.pathname + currentUrl.search + currentUrl.hash);
    }

    if (justAppliedUpdate) {
        const notice = document.createElement('div');
        notice.className = 'assistant-notice assistant-notice-info fixed left-1/2 top-4 z-[100] w-[min(92vw,28rem)] -translate-x-1/2 shadow-2xl';
        notice.setAttribute('role', 'status');
        notice.textContent = 'Canoviaを最新バージョンへ更新しました。';
        document.body.appendChild(notice);
        window.setTimeout(() => notice.remove(), 3200);
    }

    const mobileBack = document.querySelector('[data-mobile-back]');
    mobileBack?.addEventListener('click', () => {
        if (
            isCanoviaNativeRuntime(window)
            && window.CanoviaNativeBridge?.handleBack?.()
        ) {
            return;
        }

        if (window.history.length > 1) {
            window.history.back();
            return;
        }
        window.location.href = '/';
    });

    const visualViewport = window.visualViewport;
    let mobileViewportBaseline = Math.max(
        window.innerHeight || 0,
        Number(visualViewport?.height) || 0,
    );

    const isEditableElement = (element) => Boolean(
        element
        && (
            element.matches?.('input, textarea, select')
            || element.isContentEditable
        )
    );

    const syncVirtualKeyboardState = () => {
        const viewportHeight = Number(visualViewport?.height) || window.innerHeight || 0;
        const editing = isEditableElement(document.activeElement);

        if (!editing) {
            mobileViewportBaseline = Math.max(mobileViewportBaseline, viewportHeight);
        }

        const keyboardOpen = Boolean(
            editing
            && visualViewport
            && mobileViewportBaseline - viewportHeight > 120
        );

        document.documentElement.dataset.canoviaKeyboard = keyboardOpen ? 'open' : 'closed';
    };

    document.addEventListener('focusin', () => window.requestAnimationFrame(syncVirtualKeyboardState));
    document.addEventListener('focusout', () => window.setTimeout(syncVirtualKeyboardState, 80));
    visualViewport?.addEventListener('resize', syncVirtualKeyboardState, { passive: true });
    visualViewport?.addEventListener('scroll', syncVirtualKeyboardState, { passive: true });
    window.addEventListener('orientationchange', () => {
        window.setTimeout(() => {
            mobileViewportBaseline = Number(window.visualViewport?.height) || window.innerHeight || mobileViewportBaseline;
            syncVirtualKeyboardState();
        }, 220);
    });
    syncVirtualKeyboardState();

    document.querySelectorAll('[data-auto-toast]').forEach((toast) => {
        window.setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(0.5rem)';
            window.setTimeout(() => toast.remove(), 220);
        }, 3600);
    });

    const loadingOverlay = document.querySelector('[data-route-loading]');
    let loadingTimer = null;
    let navigationLocked = false;
    let pendingNavigationLink = null;
    let navigationUnlockTimer = null;

    const showLoading = () => {
        if (!loadingOverlay) return;
        window.clearTimeout(loadingTimer);
        loadingTimer = window.setTimeout(() => {
            loadingOverlay.classList.add('is-visible');
            loadingOverlay.setAttribute('aria-hidden', 'false');
        }, 120);
    };

    const resetNavigationLock = () => {
        navigationLocked = false;
        window.clearTimeout(navigationUnlockTimer);
        navigationUnlockTimer = null;

        if (pendingNavigationLink) {
            pendingNavigationLink.removeAttribute('aria-busy');
            pendingNavigationLink.classList.remove('pointer-events-none', 'opacity-70');
            pendingNavigationLink = null;
        }
    };

    const uuidForMutation = () => {
        if (window.crypto?.randomUUID) return window.crypto.randomUUID();

        const bytes = new Uint8Array(16);
        if (window.crypto?.getRandomValues) window.crypto.getRandomValues(bytes);
        else bytes.forEach((_, index) => { bytes[index] = Math.floor(Math.random() * 256); });
        bytes[6] = (bytes[6] & 0x0f) | 0x40;
        bytes[8] = (bytes[8] & 0x3f) | 0x80;
        const hex = [...bytes].map((value) => value.toString(16).padStart(2, '0')).join('');
        return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
    };

    const mutationPath = (form) => {
        try {
            return new URL(form.action, window.location.href).pathname;
        } catch (_) {
            return '';
        }
    };

    const ensureWorkStartRequestId = (form) => {
        if (mutationPath(form) !== '/work-sessions' || form.method.toUpperCase() !== 'POST') return;
        if (form.querySelector('input[name="start_request_id"]')) return;

        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'start_request_id';
        input.value = uuidForMutation();
        form.appendChild(input);
    };

    const isCriticalMutation = (form) => {
        if (form.hasAttribute('data-mutation-once') || form.matches('[data-ai-plan-generation-import], #review-apply-form')) return true;
        const path = mutationPath(form);
        return path === '/work-sessions'
            || /^\/work-sessions\/\d+\/(pause|resume|complete|interrupt)$/.test(path)
            || /^\/plans\/\d+\/review-assistant\/apply$/.test(path);
    };

    const lockMutationForm = (form, event) => {
        if (!isCriticalMutation(form)) return true;

        if (form.dataset.mutationBusy === '1') {
            event.preventDefault();
            event.stopImmediatePropagation();
            return false;
        }

        form.dataset.mutationBusy = '1';
        form.setAttribute('aria-busy', 'true');
        form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach((button) => {
            button.setAttribute('aria-disabled', 'true');
            button.classList.add('pointer-events-none', 'opacity-70');
            const pending = button.dataset.processingLabel;
            if (pending && button instanceof HTMLButtonElement) {
                button.dataset.mutationOriginalLabel = button.textContent || '';
                button.textContent = pending;
            }
        });
        return true;
    };

    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || form.target === '_blank') return;

        attachMapTelemetryToWorkStartForm(form);
        ensureWorkStartRequestId(form);
        if (!lockMutationForm(form, event)) return;

        if (form.hasAttribute('data-loading-skip')) return;
        showLoading();
    });

    const resetMutationLocks = () => {
        document.querySelectorAll('form[data-mutation-busy="1"]').forEach((form) => {
            form.dataset.mutationBusy = '0';
            form.removeAttribute('aria-busy');
            form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach((button) => {
                button.removeAttribute('aria-disabled');
                button.classList.remove('pointer-events-none', 'opacity-70');
                if (button instanceof HTMLButtonElement && button.dataset.mutationOriginalLabel !== undefined) {
                    button.textContent = button.dataset.mutationOriginalLabel;
                    delete button.dataset.mutationOriginalLabel;
                }
            });
        });
    };

    document.addEventListener('click', (event) => {
        const link = event.target.closest('a[href]');
        if (!link || event.defaultPrevented) return;
        if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        if (link.target === '_blank' || link.hasAttribute('download') || link.hasAttribute('data-route-lock-skip')) return;
        if (link.href.startsWith('mailto:') || link.href.startsWith('tel:')) return;

        const url = new URL(link.href, window.location.href);
        if (url.origin !== window.location.origin) return;
        if (url.pathname === window.location.pathname && url.search === window.location.search && url.hash) return;

        advanceMapTelemetryForClassicNavigation(link);

        if (navigationLocked) {
            event.preventDefault();
            event.stopImmediatePropagation();
            return;
        }

        navigationLocked = true;
        pendingNavigationLink = link;
        link.setAttribute('aria-busy', 'true');
        link.classList.add('pointer-events-none', 'opacity-70');
        showLoading();

        // Fail open if another handler cancels navigation or the browser keeps
        // the current document alive. Normal navigation resets on pageshow.
        navigationUnlockTimer = window.setTimeout(resetNavigationLock, 8000);
    });

    window.addEventListener('pageshow', () => {
        window.clearTimeout(loadingTimer);
        loadingOverlay?.classList.remove('is-visible');
        loadingOverlay?.setAttribute('aria-hidden', 'true');
        resetNavigationLock();
        resetMutationLocks();
    });

    // Plan review uses one delegated async submit pipeline. The page also
    // contains an older inline submit listener, so this capture-phase handler
    // deliberately stops propagation to prevent duplicate POSTs.
    document.addEventListener('submit', async (event) => {
        const form = event.target instanceof HTMLFormElement
            ? event.target.closest('form[data-async-plan-review]')
            : null;
        if (!form) return;

        // JSON preview must stay a completely native browser form submission.
        // The JSON normalizer above already ran in capture phase. Do not call
        // preventDefault(), fetch(), requestSubmit() or form.submit() here:
        // Safari/PWA proved unreliable when we re-issued this submit from JS.
        // Let the browser perform POST -> Laravel session save -> redirect.
        const isJsonPreview = form.action.includes('/review-assistant/preview');
        if (isJsonPreview) {
            return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();
        if (form.dataset.asyncBusy === '1') return;
        form.dataset.asyncBusy = '1';

        window.clearTimeout(loadingTimer);
        loadingOverlay?.classList.remove('is-visible');
        loadingOverlay?.setAttribute('aria-hidden', 'true');
        document.getElementById('review-errors')?.remove();

        const submitButton = event.submitter || form.querySelector('[data-async-plan-review-submit]');
        const statusBox = form.querySelector('[data-async-plan-review-status]');
        const originalLabel = submitButton?.textContent || '送信';
        const revealSelector = form.dataset.revealTarget || '';
        const currentScrollY = window.scrollY;
        const jsonInput = aiJsonInputForForm(form);
        const originalJson = jsonInput?.value || '';
        const isPromptGeneration = form.action.includes('/review-assistant/prompt');
        const isReset = form.action.includes('/review-assistant/reset');
        const pendingLabel = isPromptGeneration ? 'プロンプトを生成中…' : (isReset ? 'リセット中…' : '変更内容を確認中…');
        const pendingMessage = isPromptGeneration
            ? 'AI用プロンプトを生成しています。通常は数秒で完了します。'
            : (isReset ? '入力内容をリセットしています。' : 'AIの更新内容を確認しています。通常は数秒で完了します。');

        const setStatus = (message, mode = 'info') => {
            if (!statusBox) return;
            statusBox.textContent = message;
            statusBox.classList.remove('hidden', 'border-red-400/30', 'bg-red-400/10', 'text-red-100', 'border-cyan-300/20', 'bg-cyan-300/5', 'text-cyan-100');
            statusBox.classList.add(...(mode === 'error'
                ? ['border-red-400/30', 'bg-red-400/10', 'text-red-100']
                : ['border-cyan-300/20', 'bg-cyan-300/5', 'text-cyan-100']));
        };

        if (submitButton) {
            submitButton.disabled = true;
            submitButton.textContent = pendingLabel;
        }
        setStatus(pendingMessage);

        const controller = new AbortController();
        const timeout = window.setTimeout(() => controller.abort(), 25000);
        let completed = false;

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin',
                signal: controller.signal,
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            let payload = null;
            try { payload = await response.json(); } catch (_) {}

            if (!response.ok) {
                const errors = payload?.errors ? Object.values(payload.errors).flat() : [];
                const message = errors[0] || payload?.message || `処理に失敗しました (${response.status})`;
                if (jsonInput) {
                    statusBox?.classList.add('hidden');
                    renderAiJsonErrorBubble(form, message, originalJson);
                    return;
                }
                throw new Error(message);
            }

            const redirect = payload?.redirect;
            if (!redirect) throw new Error('次の画面の移動先を取得できませんでした。もう一度お試しください。');
            completed = true;

            // A successful JSON preview adds interactive roadmap controls, so
            // keep the normal navigation there. Prompt generation/reset can be
            // refreshed in place without losing page-level initializers.
            if (!isPromptGeneration && !isReset) {
                window.location.assign(redirect);
                return;
            }

            // Refresh only the review conversation for prompt/reset. If this
            // secondary GET fails, fall back to a normal navigation without
            // showing a false "generation failed" alert.
            try {
                const pageResponse = await fetch(redirect, {
                    method: 'GET',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: { 'Accept': 'text/html' },
                });
                if (!pageResponse.ok) throw new Error('partial-refresh-failed');

                const html = await pageResponse.text();
                const nextDocument = new DOMParser().parseFromString(html, 'text/html');
                const nextRoot = nextDocument.getElementById('plan-review-root');
                const currentRoot = document.getElementById('plan-review-root');
                if (!nextRoot || !currentRoot) throw new Error('partial-refresh-root-missing');

                currentRoot.innerHTML = nextRoot.innerHTML;
                if (nextDocument.title) document.title = nextDocument.title;
                const redirectUrl = new URL(redirect, window.location.href);
                window.history.replaceState({}, '', redirectUrl.pathname + redirectUrl.search + redirectUrl.hash);

                const revealTarget = document.getElementById('review-errors')
                    || (revealSelector ? document.querySelector(revealSelector) : null);
                if (revealTarget) {
                    requestAnimationFrame(() => revealTarget.scrollIntoView({ behavior: 'smooth', block: 'center' }));
                } else {
                    window.scrollTo({ top: currentScrollY, behavior: 'auto' });
                }
            } catch (_) {
                window.location.assign(redirect);
                return;
            }
        } catch (error) {
            if (completed) return;
            if (error?.name === 'AbortError') {
                setStatus('処理に時間がかかりすぎたため中断しました。通信状態を確認して、もう一度お試しください。', 'error');
            } else {
                setStatus(error?.message || '処理を完了できませんでした。もう一度お試しください。', 'error');
            }
        } finally {
            window.clearTimeout(timeout);
            window.clearTimeout(loadingTimer);
            loadingOverlay?.classList.remove('is-visible');
            loadingOverlay?.setAttribute('aria-hidden', 'true');
            if (form.isConnected) {
                form.dataset.asyncBusy = '0';
                if (submitButton) {
                    submitButton.disabled = false;
                    submitButton.textContent = originalLabel;
                }
            }
        }
    }, true);

    document.querySelectorAll('[data-candidate-carousel]').forEach((carousel) => {
        const shell = carousel.querySelector('[data-candidate-shell]');
        const toggle = carousel.querySelector('[data-candidate-toggle]');
        const track = carousel.querySelector('[data-candidate-track]');
        const cards = Array.from(carousel.querySelectorAll('[data-candidate-card]'));
        const dots = Array.from(carousel.querySelectorAll('[data-candidate-dot]'));
        const eventUrl = carousel.dataset.eventUrl;
        const viewed = new Set();

        const setActive = (index) => {
            dots.forEach((dot, dotIndex) => dot.classList.toggle('is-active', dotIndex === index));
            const card = cards[index];
            if (!card) return;
            const taskId = Number(card.dataset.taskId || 0);
            if (!taskId || viewed.has(taskId) || !eventUrl || !csrfToken) return;
            viewed.add(taskId);
            fetch(eventUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify({
                    event_type: 'task_viewed',
                    plan_id: Number(card.dataset.planId || 0) || null,
                    task_id: taskId,
                    metadata: { source: 'navigation_candidate_carousel', candidate_index: index },
                }),
                keepalive: true,
            }).catch(() => {});
        };

        toggle?.addEventListener('click', () => {
            const opening = !shell?.classList.contains('is-open');
            shell?.classList.toggle('is-open', opening);
            toggle.setAttribute('aria-expanded', opening ? 'true' : 'false');
            toggle.textContent = opening ? '候補を閉じる' : '別候補を見る';
            if (opening) {
                setActive(0);
                window.setTimeout(() => shell?.scrollIntoView({ behavior: 'smooth', block: 'nearest' }), 30);
            }
        });

        if (carousel.hasAttribute('data-candidate-always-open') && cards.length > 0) {
            setActive(0);
        }

        if (track && cards.length > 0 && 'IntersectionObserver' in window) {
            const observer = new IntersectionObserver((entries) => {
                const visible = entries
                    .filter((entry) => entry.isIntersecting)
                    .sort((a, b) => b.intersectionRatio - a.intersectionRatio)[0];
                if (!visible || visible.intersectionRatio < 0.62) return;
                const index = cards.indexOf(visible.target);
                if (index >= 0) setActive(index);
            }, { root: track, threshold: [0.62, 0.8] });
            cards.forEach((card) => observer.observe(card));
        }

        dots.forEach((dot, index) => {
            dot.addEventListener('click', () => {
                cards[index]?.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'start' });
                setActive(index);
            });
        });
    });

    document.querySelectorAll('[data-collapsible-copy]').forEach((root) => {
        const text = root.querySelector('[data-collapsible-copy-text]');
        const toggle = root.querySelector('[data-collapsible-copy-toggle]');
        if (!text || !toggle) return;

        const refresh = () => {
            root.classList.remove('is-expanded');
            toggle.setAttribute('aria-expanded', 'false');
            toggle.textContent = '続きを読む';
            toggle.classList.toggle('hidden', text.scrollHeight <= text.clientHeight + 2);
        };

        requestAnimationFrame(refresh);
        const parentDetails = root.closest('details');
        parentDetails?.addEventListener('toggle', () => {
            if (parentDetails.open) requestAnimationFrame(refresh);
        });

        toggle.addEventListener('click', () => {
            const expanded = !root.classList.contains('is-expanded');
            root.classList.toggle('is-expanded', expanded);
            toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            toggle.textContent = expanded ? '閉じる' : '続きを読む';
        });
    });

    const releaseNotesDialog = document.querySelector('[data-release-notes-dialog]');
    const releaseNotesCard = releaseNotesDialog?.querySelector('.release-notes-card');
    const releaseNoteDetailDialog = document.querySelector('[data-release-note-detail-dialog]');
    const releaseNoteDetailCard = releaseNoteDetailDialog?.querySelector('[data-release-note-detail-card]');
    const releaseNoteDetails = releaseNoteDetailDialog ? [...releaseNoteDetailDialog.querySelectorAll('[data-release-note-detail]')] : [];
    const latestReleaseKey = releaseNotesDialog?.dataset.latestReleaseKey || '';
    const releaseSeenKey = 'canovia.release_notes.seen';

    const closeDialog = (dialog) => {
        if (!dialog) return;
        if (typeof dialog.close === 'function' && dialog.open) dialog.close();
        else dialog.removeAttribute('open');
    };

    const openDialog = (dialog) => {
        if (!dialog) return;
        if (typeof dialog.showModal === 'function' && !dialog.open) dialog.showModal();
        else dialog.setAttribute('open', '');
    };

    const updateReleaseNewIndicators = () => {
        let seenKey = '';
        try {
            seenKey = localStorage.getItem(releaseSeenKey) || '';
        } catch (_) {}
        const isNew = Boolean(latestReleaseKey && seenKey !== latestReleaseKey);
        document.querySelectorAll('[data-release-notes-new]').forEach((indicator) => {
            indicator.classList.toggle('is-hidden', !isNew);
        });
    };

    const markReleaseNotesSeen = () => {
        if (!latestReleaseKey) return;
        try {
            localStorage.setItem(releaseSeenKey, latestReleaseKey);
        } catch (_) {}
        updateReleaseNewIndicators();
    };

    const hideReleaseNoteDetails = () => {
        releaseNoteDetails.forEach((detail) => detail.classList.add('hidden'));
    };

    document.querySelectorAll('[data-release-notes-open]').forEach((button) => {
        button.addEventListener('click', () => {
            if (!releaseNotesDialog) return;
            markReleaseNotesSeen();
            if (releaseNotesCard) releaseNotesCard.scrollTop = 0;
            openDialog(releaseNotesDialog);
        });
    });

    releaseNotesDialog?.querySelectorAll('[data-release-note-open]').forEach((button) => {
        button.addEventListener('click', () => {
            const key = button.dataset.releaseNoteOpen;
            const detail = releaseNoteDetails.find((item) => item.dataset.releaseNoteDetail === key);
            if (!detail || !releaseNoteDetailDialog) return;

            hideReleaseNoteDetails();
            detail.classList.remove('hidden');
            if (releaseNoteDetailCard) releaseNoteDetailCard.scrollTop = 0;
            openDialog(releaseNoteDetailDialog);
        });
    });

    document.querySelectorAll('[data-release-notes-close]').forEach((button) => {
        button.addEventListener('click', () => closeDialog(releaseNotesDialog));
    });

    document.querySelectorAll('[data-release-note-detail-close]').forEach((button) => {
        button.addEventListener('click', () => closeDialog(releaseNoteDetailDialog));
    });

    releaseNotesDialog?.addEventListener('click', (event) => {
        if (event.target === releaseNotesDialog) closeDialog(releaseNotesDialog);
    });

    releaseNoteDetailDialog?.addEventListener('click', (event) => {
        // Native <dialog> gives us a real top layer. Clicking the dimmed area
        // closes only the detail, leaving the update list available behind it.
        if (event.target === releaseNoteDetailDialog) closeDialog(releaseNoteDetailDialog);
    });

    releaseNoteDetailDialog?.addEventListener('close', hideReleaseNoteDetails);
    releaseNotesDialog?.addEventListener('close', () => closeDialog(releaseNoteDetailDialog));
    updateReleaseNewIndicators();

    const feedbackDialog = document.querySelector('[data-feedback-dialog]');
    document.querySelectorAll('[data-feedback-open]').forEach((button) => {
        button.addEventListener('click', () => {
            if (!feedbackDialog) return;
            if (typeof feedbackDialog.showModal === 'function') feedbackDialog.showModal();
            else feedbackDialog.setAttribute('open', '');
        });
    });
    document.querySelectorAll('[data-feedback-close]').forEach((button) => {
        button.addEventListener('click', () => {
            if (!feedbackDialog) return;
            if (typeof feedbackDialog.close === 'function') feedbackDialog.close();
            else feedbackDialog.removeAttribute('open');
        });
    });
    feedbackDialog?.addEventListener('click', (event) => {
        if (event.target === feedbackDialog && typeof feedbackDialog.close === 'function') feedbackDialog.close();
    });

    // V39.1: restore Instant Start with a deliberately narrow Service Worker.
    // Mutations and sensitive flows stay on the browser/Laravel network path.
    void mountInstantStartServiceWorker();
});

// -----------------------------------------------------------------------------
// Instant Start: persist a safe client-side snapshot and expose sync state.
// -----------------------------------------------------------------------------
const offlineDbName = 'pacekeeper-offline-v1';
const offlineStoreName = 'state';

function openOfflineDb() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(offlineDbName, 1);
        request.onupgradeneeded = () => {
            if (!request.result.objectStoreNames.contains(offlineStoreName)) {
                request.result.createObjectStore(offlineStoreName);
            }
        };
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

async function writeOfflineState(key, value) {
    const db = await openOfflineDb();
    await new Promise((resolve, reject) => {
        const tx = db.transaction(offlineStoreName, 'readwrite');
        tx.objectStore(offlineStoreName).put(value, key);
        tx.oncomplete = resolve;
        tx.onerror = () => reject(tx.error);
    });
}


async function readOfflineState(key) {
    const db = await openOfflineDb();
    return await new Promise((resolve, reject) => {
        const tx = db.transaction(offlineStoreName, 'readonly');
        const request = tx.objectStore(offlineStoreName).get(key);
        request.onsuccess = () => resolve(request.result || null);
        request.onerror = () => reject(request.error);
    });
}

async function deleteOfflineState(key) {
    const db = await openOfflineDb();
    await new Promise((resolve, reject) => {
        const tx = db.transaction(offlineStoreName, 'readwrite');
        tx.objectStore(offlineStoreName).delete(key);
        tx.oncomplete = resolve;
        tx.onerror = () => reject(tx.error);
    });
}

function offlineSessionElapsedSeconds(session) {
    if (!session?.started_at) return 0;
    const now = Date.now();
    const end = session.ended_at ? Date.parse(session.ended_at) : now;
    const start = Date.parse(session.started_at);
    if (!Number.isFinite(start) || !Number.isFinite(end)) return 0;

    const pausedSeconds = Number(session.paused_seconds || 0);
    const livePauseSeconds = session.paused_at
        ? Math.max(0, (now - Date.parse(session.paused_at)) / 1000)
        : 0;

    return Math.max(0, Math.floor((end - start) / 1000 - pausedSeconds - livePauseSeconds));
}

async function syncOfflineWorkSession(session) {
    if (!session?.ended_at || !csrfToken) return null;

    const response = await fetch('/offline/work-sessions/sync', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': csrfToken,
        },
        body: JSON.stringify({
            client_session_id: session.client_session_id,
            task_id: session.task_id,
            started_at: session.started_at,
            ended_at: session.ended_at,
            actual_seconds: session.actual_seconds,
            intended_minutes: session.intended_minutes ?? null,
            adjustment_reason: session.adjustment_reason ?? null,
            adjustment_seconds: session.adjustment_seconds ?? null,
            manual_minutes: session.manual_minutes ?? null,
        }),
    });

    if (!response.ok) return null;
    return await response.json().catch(() => ({}));
}

async function mountOfflineTimerCard(session) {
    const card = document.querySelector('[data-offline-timer-card]');
    if (!card || !session) return false;

    const task = card.querySelector('[data-offline-timer-task]');
    const status = card.querySelector('[data-offline-timer-status]');
    const value = card.querySelector('[data-offline-timer-value]');
    const toggle = card.querySelector('[data-offline-timer-toggle]');
    const complete = card.querySelector('[data-offline-timer-complete]');
    const note = card.querySelector('[data-offline-timer-note]');
    let current = session;
    let busy = false;
    let storageError = false;
    const awayThresholdMs = 15 * 60 * 1000;

    card.classList.remove('hidden');
    if (task) task.textContent = current.task_title || 'オフライン作業';

    const persist = async () => {
        try {
            await writeOfflineState('offline_session', current);
            storageError = false;
            return true;
        } catch (_) {
            storageError = true;
            return false;
        }
    };

    const thresholdSeconds = () => {
        const intended = Number(current.intended_minutes || 0);
        return (intended > 0 ? Math.max(intended * 2, 90) : 120) * 60;
    };

    const clearAway = () => {
        current.away_started_at = null;
        current.last_heartbeat_at = new Date().toISOString();
    };

    const finishAt = async (endedAt, reason, manualMinutes = null) => {
        if (current.paused_at) {
            current.paused_seconds = Number(current.paused_seconds || 0)
                + Math.max(0, (Date.parse(endedAt) - Date.parse(current.paused_at)) / 1000);
            current.paused_at = null;
        }
        current.ended_at = endedAt;
        if (manualMinutes !== null) {
            current.actual_seconds = Math.max(60, Number(manualMinutes) * 60);
            current.manual_minutes = Number(manualMinutes);
        } else {
            current.actual_seconds = Math.max(1, offlineSessionElapsedSeconds(current));
        }
        current.adjustment_reason = reason || null;
        clearAway();
        await persist();
    };

    const reviewAway = async () => {
        if (busy || current.ended_at || current.paused_at || !current.away_started_at) return;
        const awayAt = Date.parse(current.away_started_at);
        if (!Number.isFinite(awayAt)) return;
        if (Date.now() - awayAt < awayThresholdMs) {
            clearAway();
            await persist();
            return;
        }

        const awayMinutes = Math.max(1, Math.round((Date.now() - awayAt) / 60000));
        const choice = window.prompt(
            `${awayMinutes}分ほどCanoviaから離れていました。\n` +
            '1: 作業を続けていた\n2: 休憩していた\n3: 離れた時点で終了\n4: 実際の作業分数を入力\n\n1〜4を入力してください。',
            '1'
        );
        if (choice === null) return;

        if (choice === '1') {
            clearAway();
            await persist();
            render();
            return;
        }

        if (choice === '2') {
            const awaySeconds = Math.max(0, Math.floor((Date.now() - awayAt) / 1000));
            current.paused_seconds = Number(current.paused_seconds || 0) + awaySeconds;
            current.adjustment_reason = 'away_break';
            current.adjustment_seconds = Number(current.adjustment_seconds || 0) + awaySeconds;
            clearAway();
            await persist();
            render();
            return;
        }

        if (choice === '3') {
            await finishAt(new Date(awayAt).toISOString(), 'away_end');
            render();
            return;
        }

        if (choice === '4') {
            const entered = window.prompt('実際に作業した時間を分単位で入力してください。', String(Math.max(1, Math.round(offlineSessionElapsedSeconds(current) / 60))));
            const minutes = Number(entered);
            if (!Number.isFinite(minutes) || minutes < 1 || minutes > 480) return;
            await finishAt(new Date().toISOString(), 'manual', Math.round(minutes));
            render();
        }
    };

    const markAway = () => {
        if (current.ended_at || current.paused_at) return;
        const now = new Date().toISOString();
        current.away_started_at ||= now;
        current.last_heartbeat_at = now;
        void persist();
    };

    const render = () => {
        if (value) value.textContent = formatTimer(offlineSessionElapsedSeconds(current));
        if (toggle) {
            toggle.textContent = current.paused_at ? '再開' : '一時停止';
            toggle.disabled = busy || Boolean(current.ended_at);
        }
        const online = navigator.onLine;
        if (complete) {
            complete.disabled = !online || busy;
            complete.classList.toggle('opacity-50', !online || busy);
            complete.textContent = current.ended_at ? '同期を再試行' : '記録して終了';
        }
        if (status) status.textContent = busy ? '作業記録を同期中…'
            : current.ended_at ? '未同期の作業記録'
            : current.paused_at ? '一時停止中' : '計測中';
        if (note) {
            note.textContent = storageError
                ? '端末に記録を保存できません。ページを閉じずに、空き容量やブラウザ設定を確認してください。'
                : current.ended_at
                    ? '計測は終了しています。接続後に「同期を再試行」できます。'
                    : online
                        ? '接続できています。終了すると作業記録へ同期します。'
                        : 'オフライン中もタイマー状態は端末に保持されます。長時間離れた場合は復帰時に確認します。';
        }
    };

    toggle?.addEventListener('click', async () => {
        if (busy || current.ended_at) return;
        if (current.paused_at) {
            current.paused_seconds = Number(current.paused_seconds || 0)
                + Math.max(0, (Date.now() - Date.parse(current.paused_at)) / 1000);
            current.paused_at = null;
            current.last_heartbeat_at = new Date().toISOString();
        } else {
            current.paused_at = new Date().toISOString();
            current.away_started_at = null;
        }
        await persist();
        render();
    });

    complete?.addEventListener('click', async () => {
        if (busy || !navigator.onLine) return;
        if (!current.ended_at) {
            await reviewAway();
            if (current.away_started_at) return;
            const elapsed = offlineSessionElapsedSeconds(current);
            if (elapsed > thresholdSeconds()) {
                const ok = window.confirm(`現在の計測は約${Math.max(1, Math.round(elapsed / 60))}分です。長時間の記録ですが、この時間で保存しますか？`);
                if (!ok) return;
            }
            await finishAt(new Date().toISOString(), current.adjustment_reason || null);
        }

        busy = true;
        render();
        const result = await syncOfflineWorkSession(current).catch(() => null);
        if (result?.work_session_id) {
            await deleteOfflineState('offline_session').catch(() => {});
            setSyncStatus('online', 'オフライン作業を同期済み', 2200);
            window.location.assign(`/work-sessions/${result.work_session_id}/review`);
            return;
        }

        busy = false;
        if (status) status.textContent = 'まだ同期できていません';
        render();
    });

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') markAway();
        else void reviewAway();
    });
    window.addEventListener('pagehide', markAway);
    window.addEventListener('pageshow', () => { void reviewAway(); });
    window.addEventListener('online', render);
    window.addEventListener('offline', render);

    window.setInterval(() => {
        if (!current.ended_at && !current.paused_at && document.visibilityState === 'visible') {
            current.last_heartbeat_at = new Date().toISOString();
            void persist();
        }
        render();
    }, 60 * 1000);
    window.setInterval(() => {
        if (!current.paused_at && !current.ended_at) render();
    }, 1000);

    if (!current.away_started_at && current.last_heartbeat_at && !current.paused_at && !current.ended_at) {
        const heartbeat = Date.parse(current.last_heartbeat_at);
        if (Number.isFinite(heartbeat) && Date.now() - heartbeat >= awayThresholdMs) {
            current.away_started_at = current.last_heartbeat_at;
        }
    }

    render();
    window.setTimeout(() => { void reviewAway(); }, 120);
    return true;
}

async function clearOfflineState() {
    await new Promise((resolve) => {
        const request = indexedDB.deleteDatabase(offlineDbName);
        request.onsuccess = request.onerror = request.onblocked = () => resolve();
    });
}

let syncPassiveTimer = null;
function setSyncStatus(mode, label, passiveAfterMs = null) {
    const root = document.querySelector('[data-sync-status]');
    if (!root) return;
    window.clearTimeout(syncPassiveTimer);
    root.dataset.syncMode = mode;
    root.classList.remove('is-passive');
    const target = root.querySelector('[data-sync-status-label]');
    if (target) target.textContent = label;

    if (passiveAfterMs !== null) {
        syncPassiveTimer = window.setTimeout(() => root.classList.add('is-passive'), passiveAfterMs);
    }
}

document.addEventListener('DOMContentLoaded', async () => {
    const snapshotElement = document.getElementById('pacekeeper-offline-snapshot');
    if (snapshotElement && 'indexedDB' in window) {
        try {
            const snapshot = JSON.parse(snapshotElement.textContent || '{}');
            if (snapshot && typeof snapshot === 'object') {
                snapshot.last_path = window.location.pathname + window.location.search;
                snapshot.last_title = document.title;
                snapshot.client_captured_at = new Date().toISOString();
                await writeOfflineState('latest_snapshot', snapshot);
            }
        } catch (_) {}
    }

    document.querySelectorAll('[data-clear-offline-state]').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            if (!('indexedDB' in window)) return;
            event.preventDefault();
            await clearOfflineState().catch(() => {});
            form.submit();
        });
    });

    if ('indexedDB' in window) {
        try {
            const pendingOfflineSession = await readOfflineState('offline_session');
            if (pendingOfflineSession?.ended_at && csrfToken) {
                setSyncStatus('syncing', '作業結果を同期中…');
                const result = await syncOfflineWorkSession(pendingOfflineSession).catch(() => null);
                if (result?.work_session_id) {
                    await deleteOfflineState('offline_session');
                    setSyncStatus('online', 'オフライン作業を同期済み', 2200);
                } else {
                    setSyncStatus('pending', '未同期の作業があります');
                    const mounted = await mountOfflineTimerCard(pendingOfflineSession);
                    if (!mounted) {
                        const banner = document.createElement('a');
                        banner.href = '/navigate?resume_offline_timer=1';
                        banner.className = 'offline-session-banner';
                        banner.textContent = '未同期の作業があります · 今日で確認';
                        document.body.appendChild(banner);
                    }
                }
            } else if (pendingOfflineSession && !pendingOfflineSession.ended_at) {
                const mounted = await mountOfflineTimerCard(pendingOfflineSession);
                if (!mounted) {
                    const banner = document.createElement('a');
                    banner.href = '/navigate?resume_offline_timer=1';
                    banner.className = 'offline-session-banner';
                    banner.textContent = 'オフラインで計測中 · 今日のタイマーへ戻る';
                    document.body.appendChild(banner);
                }
                setSyncStatus('pending', 'オフラインで計測中');
            }
        } catch (_) {
            setSyncStatus('error', '同期状態を確認できません');
        }
    }

    let wakeRequest = null;
    let hiddenAt = null;

    const warmCanoviaServer = async ({ announce = true } = {}) => {
        if (!navigator.onLine) {
            setSyncStatus('offline', 'オフライン');
            return false;
        }
        if (wakeRequest) return wakeRequest;

        if (announce) setSyncStatus('syncing', 'Canoviaを準備中…');
        wakeRequest = fetch(`/health?warm=${Date.now()}`, {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'Accept': 'text/plain' },
        })
            .then((response) => {
                if (!response.ok) throw new Error('health-check-failed');
                setSyncStatus('online', '接続できました', 1800);
                return true;
            })
            .catch(() => {
                if (!navigator.onLine) setSyncStatus('offline', 'オフライン');
                else setSyncStatus('pending', '接続を準備しています');
                return false;
            })
            .finally(() => {
                wakeRequest = null;
            });

        return wakeRequest;
    };

    const updateNetworkState = () => {
        if (!navigator.onLine) {
            setSyncStatus('offline', 'オフライン');
            return;
        }
        setSyncStatus('online', '接続済み', 1800);
    };

    window.addEventListener('online', () => {
        updateNetworkState();
        void warmCanoviaServer({ announce: true });
    });
    window.addEventListener('offline', updateNetworkState);

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') {
            hiddenAt = Date.now();
            return;
        }

        const sleptFor = hiddenAt ? Date.now() - hiddenAt : 0;
        hiddenAt = null;
        // Do not keep the free Render service alive in the background. Only
        // pre-warm it when the user actually returns after a long absence.
        if (sleptFor >= 10 * 60 * 1000) {
            void warmCanoviaServer({ announce: true });
        }
    });

    window.addEventListener('pageshow', (event) => {
        if (event.persisted) void warmCanoviaServer({ announce: true });
    });

    updateNetworkState();
});

// -----------------------------------------------------------------------------
// Personal UI: per-device theme, accent, density, and Roadmap view preferences.
// -----------------------------------------------------------------------------
function applyUiPreferences() {
    const root = document.documentElement;
    const accent = localStorage.getItem('pacekeeper.ui.accent') || 'sky';
    const storedDensity = localStorage.getItem('pacekeeper.ui.density');
    const isMobile = window.matchMedia('(max-width: 767px)').matches;
    const density = storedDensity || (isMobile ? 'standard' : 'compact');
    // Canovia v33: dark is the only official theme for now.
    // Overwrite legacy light/system selections so installed PWAs converge on it.
    try {
        localStorage.setItem('pacekeeper.ui.theme', 'dark');
    } catch (_) {}

    root.dataset.uiTheme = 'dark';
    root.dataset.themeResolved = 'dark';
    root.dataset.uiAccent = accent;
    root.dataset.uiDensity = density;
    const themeColor = document.querySelector('meta[name="theme-color"]');
    if (themeColor) themeColor.content = '#020617';

    document.querySelectorAll('[data-ui-accent-value]').forEach((button) => {
        button.classList.toggle('is-active', button.dataset.uiAccentValue === accent);
        button.setAttribute('aria-pressed', button.dataset.uiAccentValue === accent ? 'true' : 'false');
    });
    document.querySelectorAll('[data-ui-density-value]').forEach((button) => {
        button.classList.toggle('is-active', button.dataset.uiDensityValue === density);
        button.setAttribute('aria-pressed', button.dataset.uiDensityValue === density ? 'true' : 'false');
    });
}

function resolveRoadmapView(root) {
    const fixedView = root.dataset.roadmapFixedView;
    if (fixedView === 'map' || fixedView === 'list') return fixedView;

    const planId = root.dataset.roadmapPlanId || 'preview';
    const key = `pacekeeper.roadmap.v19.view.${planId}`;
    const stored = localStorage.getItem(key);
    if (stored === 'map' || stored === 'list') return stored;
    return 'map';
}

function fitDashboardRoadmapOverviews(root = document) {
    let fitted = 0;

    root.querySelectorAll?.('[data-roadmap-spatial-mode="dashboard-overview"]').forEach((shell) => {
        const stage = shell.querySelector('[data-roadmap-spatial-map]');
        if (!stage || shell.clientWidth <= 0 || shell.clientHeight <= 0) return;

        const stageWidth = Math.max(1, Number.parseFloat(getComputedStyle(stage).width) || stage.offsetWidth || 1);
        const stageHeight = Math.max(1, Number.parseFloat(getComputedStyle(stage).height) || stage.offsetHeight || 1);
        const padding = 14;
        const availableWidth = Math.max(1, shell.clientWidth - (padding * 2));
        const availableHeight = Math.max(1, shell.clientHeight - (padding * 2));
        const scale = Math.max(0.12, Math.min(1, availableWidth / stageWidth, availableHeight / stageHeight));

        shell.style.setProperty('--roadmap-overview-scale', scale.toFixed(4));
        shell.dataset.roadmapOverviewFit = scale.toFixed(4);
        fitted += 1;
    });

    return fitted;
}


window.addEventListener('resize', () => {
    window.requestAnimationFrame(() => fitDashboardRoadmapOverviews());
}, { passive: true });

function setRoadmapView(root, view, persist = true) {
    const planId = root.dataset.roadmapPlanId || 'preview';
    root.dataset.roadmapView = view;
    root.querySelectorAll('[data-roadmap-view-button]').forEach((button) => {
        const active = button.dataset.roadmapViewButton === view;
        button.classList.toggle('is-active', active);
        button.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    root.querySelectorAll('[data-roadmap-view-panel]').forEach((panel) => {
        panel.hidden = panel.dataset.roadmapViewPanel !== view;
    });
    if (persist && planId !== 'preview') {
        localStorage.setItem(`pacekeeper.roadmap.v19.view.${planId}`, view);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    applyUiPreferences();
    const fittedRoadmapOverviews = fitDashboardRoadmapOverviews();
    if (fittedRoadmapOverviews > 0) {
        window.requestAnimationFrame(() => fitDashboardRoadmapOverviews());
    }

    const futureMemoHint = document.querySelector('[data-future-memo-home-hint]');
    if (futureMemoHint) {
        let snoozeUntil = 0;
        try {
            snoozeUntil = Number(localStorage.getItem('canovia.future-memo-hint.snooze-until') || 0);
        } catch (_) {}

        if (!Number.isFinite(snoozeUntil) || Date.now() >= snoozeUntil) {
            futureMemoHint.classList.remove('hidden');
        }

        futureMemoHint.querySelector('[data-future-memo-hint-later]')?.addEventListener('click', () => {
            const sevenDays = 7 * 24 * 60 * 60 * 1000;
            try {
                localStorage.setItem('canovia.future-memo-hint.snooze-until', String(Date.now() + sevenDays));
            } catch (_) {}
            futureMemoHint.classList.add('hidden');
        });
    }

    const settingsDialog = document.querySelector('[data-ui-settings-dialog]');
    document.querySelectorAll('[data-ui-settings-open]').forEach((button) => {
        button.addEventListener('click', () => {
            applyUiPreferences();
            if (settingsDialog?.showModal) settingsDialog.showModal();
            else settingsDialog?.setAttribute('open', '');
        });
    });
    document.querySelectorAll('[data-ui-settings-close]').forEach((button) => {
        button.addEventListener('click', () => {
            if (settingsDialog?.close) settingsDialog.close();
            else settingsDialog?.removeAttribute('open');
        });
    });
    settingsDialog?.addEventListener('click', (event) => {
        if (event.target === settingsDialog && settingsDialog.close) settingsDialog.close();
    });

    document.querySelectorAll('[data-ui-accent-value]').forEach((button) => {
        button.addEventListener('click', () => {
            localStorage.setItem('pacekeeper.ui.accent', button.dataset.uiAccentValue);
            applyUiPreferences();
        });
    });
    document.querySelectorAll('[data-ui-density-value]').forEach((button) => {
        button.addEventListener('click', () => {
            localStorage.setItem('pacekeeper.ui.density', button.dataset.uiDensityValue);
            applyUiPreferences();
        });
    });

    document.querySelectorAll('[data-roadmap-view-root]').forEach((root) => {
        setRoadmapView(root, resolveRoadmapView(root), false);
        root.querySelectorAll('[data-roadmap-view-button]').forEach((button) => {
            button.addEventListener('click', () => setRoadmapView(root, button.dataset.roadmapViewButton));
        });
    });

    const roadmapDetailTimers = new WeakMap();
    const ROADMAP_DETAIL_AUTO_CLOSE_MS = 4000;

    const clearRoadmapDetailTimer = (stop) => {
        const timer = roadmapDetailTimers.get(stop);
        if (timer) {
            window.clearTimeout(timer);
            roadmapDetailTimers.delete(stop);
        }
    };

    const scheduleRoadmapDetailClose = (stop) => {
        clearRoadmapDetailTimer(stop);
        if (!stop.open) return;

        const viewRoot = stop.closest('[data-roadmap-view-root]');
        if (viewRoot?.dataset.roadmapPlanId === 'preview') return;

        const timer = window.setTimeout(() => {
            if (stop.open) stop.removeAttribute('open');
            roadmapDetailTimers.delete(stop);
        }, ROADMAP_DETAIL_AUTO_CLOSE_MS);

        roadmapDetailTimers.set(stop, timer);
    };

    document.querySelectorAll('[data-map-stop]').forEach((stop) => {
        stop.addEventListener('toggle', () => {
            if (!stop.open) {
                clearRoadmapDetailTimer(stop);
                return;
            }

            const root = stop.closest('[data-roadmap-map]');
            root?.querySelectorAll('[data-map-stop][open]').forEach((other) => {
                if (other === stop) return;
                clearRoadmapDetailTimer(other);
                other.removeAttribute('open');
            });

            scheduleRoadmapDetailClose(stop);
        });

        // Keep the detail open while the user is interacting with its controls/content.
        stop.addEventListener('pointerdown', () => clearRoadmapDetailTimer(stop));
        stop.addEventListener('focusin', () => clearRoadmapDetailTimer(stop));
        stop.addEventListener('pointerleave', () => scheduleRoadmapDetailClose(stop));
        stop.addEventListener('focusout', (event) => {
            if (!stop.contains(event.relatedTarget)) scheduleRoadmapDetailClose(stop);
        });
    });
});

// v12: Plan Design live preview. Keep the form as the source of truth; preview only reflects it.
document.addEventListener('DOMContentLoaded', () => {
    const worldLabels = {
        default: '🧭 Classic',
        study: '📚 Study',
        sweet: '🍰 Sweet',
        halloween: '🎃 Halloween',
        space: '🪐 Space',
        forest: '🌲 Forest',
    };

    document.querySelectorAll('[data-plan-visual-picker]').forEach((picker) => {
        const preview = picker.querySelector('[data-plan-visual-preview]');
        const iconInput = picker.querySelector('[data-plan-visual-icon-input]');
        const accentInput = picker.querySelector('[data-plan-visual-accent-input]');
        const worldInput = picker.querySelector('[data-plan-visual-world-input]');
        const icon = picker.querySelector('[data-plan-visual-preview-icon]');
        const world = picker.querySelector('[data-plan-visual-preview-world]');
        if (!preview) return;

        const render = () => {
            const fallbackIcon = '🧭';
            if (icon) icon.textContent = (iconInput?.value || '').trim() || fallbackIcon;
            preview.dataset.planAccent = accentInput?.value || 'sky';
            if (world) world.textContent = worldLabels[worldInput?.value] || worldLabels.default;
        };

        iconInput?.addEventListener('input', render);
        accentInput?.addEventListener('change', render);
        worldInput?.addEventListener('change', render);
        render();
    });
});

// v13: Roadmap Plan pager. Tabs provide discoverability; swipe provides speed.
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-roadmap-overview]').forEach((button) => {
        button.addEventListener('click', () => {
            const root = button.closest('[data-roadmap-view-root]');
            if (!root) return;
            setRoadmapView(root, 'map');
            root.querySelectorAll('[data-map-stop][open]').forEach((stop) => stop.removeAttribute('open'));
            root.querySelector('[data-roadmap-map]')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
    });

    document.querySelectorAll('[data-roadmap-plan-pager]').forEach((pager) => {
        let startX = 0;
        let startY = 0;
        let tracking = false;

        const navigate = (url, direction) => {
            if (!url) return;
            pager.classList.add(direction === 'next' ? 'is-leaving-left' : 'is-leaving-right');
            window.setTimeout(() => { window.location.assign(url); }, 110);
        };

        pager.addEventListener('touchstart', (event) => {
            const touch = event.touches?.[0];
            if (!touch) return;
            if (event.target.closest('button, a, input, select, textarea, [data-roadmap-plan-tabs], [data-roadmap-spatial-scroll]')) return;
            startX = touch.clientX;
            startY = touch.clientY;
            tracking = true;
        }, { passive: true });

        pager.addEventListener('touchend', (event) => {
            if (!tracking) return;
            tracking = false;
            const touch = event.changedTouches?.[0];
            if (!touch) return;
            const dx = touch.clientX - startX;
            const dy = touch.clientY - startY;
            if (Math.abs(dx) < 58 || Math.abs(dx) < Math.abs(dy) * 1.25) return;
            if (dx < 0) navigate(pager.dataset.nextUrl, 'next');
            else navigate(pager.dataset.prevUrl, 'prev');
        }, { passive: true });

        pager.addEventListener('keydown', (event) => {
            if (event.key === 'ArrowRight' && pager.dataset.nextUrl) {
                event.preventDefault();
                navigate(pager.dataset.nextUrl, 'next');
            }
            if (event.key === 'ArrowLeft' && pager.dataset.prevUrl) {
                event.preventDefault();
                navigate(pager.dataset.prevUrl, 'prev');
            }
        });
    });

    const activePlanTab = document.querySelector('[data-roadmap-plan-tabs] .pk-v19-plan-card.is-active, [data-roadmap-plan-tabs] .roadmap-plan-tab.is-active');
    activePlanTab?.scrollIntoView({ behavior: 'auto', block: 'nearest', inline: 'center' });

    document.querySelectorAll('[data-roadmap-spatial-scroll]').forEach((scroll) => {
        if (scroll.dataset.roadmapSpatialAutoCenter === '0') return;
        const current = scroll.querySelector('[data-roadmap-spatial-node][data-roadmap-current="1"]');
        if (!current) return;

        window.requestAnimationFrame(() => {
            if (!scroll.isConnected || !current.isConnected) return;
            const left = Math.max(0, current.offsetLeft - (scroll.clientWidth / 2) + (current.offsetWidth / 2));
            const top = Math.max(0, current.offsetTop - (scroll.clientHeight / 2) + (current.offsetHeight / 2));
            scroll.scrollTo({ left, top, behavior: 'auto' });
        });
    });
});

// v13: optional five-star overall score inside the existing feedback flow.
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-feedback-dialog]').forEach((dialog) => {
        const input = dialog.querySelector('[data-feedback-rating-input]');
        const label = dialog.querySelector('[data-feedback-rating-label]');
        const stars = [...dialog.querySelectorAll('[data-feedback-rating-value]')];
        if (!input || stars.length === 0) return;

        const render = (rating) => {
            const value = Number(rating || 0);
            stars.forEach((star) => {
                const selected = Number(star.dataset.feedbackRatingValue) <= value;
                star.classList.toggle('is-selected', selected);
                star.setAttribute('aria-pressed', Number(star.dataset.feedbackRatingValue) === value ? 'true' : 'false');
            });
            if (label) label.textContent = value > 0 ? `${value} / 5` : '未評価';
        };

        stars.forEach((star) => {
            star.addEventListener('click', () => {
                input.value = star.dataset.feedbackRatingValue || '';
                render(input.value);
            });
        });
        render(input.value);
    });
});

// -----------------------------------------------------------------------------
// v15 Guided first-run onboarding + install guidance.
// The tutorial is event-driven and persists across page navigations.
// Existing users are not auto-started simply because a new onboarding version
// ships: automatic start only begins from an empty Home dashboard.
// -----------------------------------------------------------------------------
let pacekeeperDeferredInstallPrompt = null;
window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    pacekeeperDeferredInstallPrompt = event;
    window.dispatchEvent(new CustomEvent('pacekeeper:install-ready'));
});

window.addEventListener('appinstalled', () => {
    localStorage.setItem('pacekeeper.install.state', 'installed');
    localStorage.removeItem('pacekeeper.install.offer-pending');
});

function pacekeeperIsStandalone() {
    return isCanoviaNativeRuntime(window)
        || window.matchMedia('(display-mode: standalone)').matches
        || window.navigator.standalone === true;
}

function pacekeeperVisibleTarget(selector) {
    return [...document.querySelectorAll(selector)].find((element) => {
        const style = window.getComputedStyle(element);
        const rect = element.getBoundingClientRect();
        return style.display !== 'none' && style.visibility !== 'hidden' && rect.width > 0 && rect.height > 0;
    }) || null;
}

function pacekeeperOpenDialog(dialog) {
    if (!dialog) return;
    if (typeof dialog.showModal === 'function' && !dialog.open) dialog.showModal();
    else dialog.setAttribute('open', '');
}

function pacekeeperCloseDialog(dialog) {
    if (!dialog) return;
    if (typeof dialog.close === 'function' && dialog.open) dialog.close();
    else dialog.removeAttribute('open');
}

document.addEventListener('DOMContentLoaded', () => {
    const body = document.body;
    const root = document.querySelector('[data-onboarding-root]');
    const bubble = root?.querySelector('[data-onboarding-bubble]');
    const focusRing = root?.querySelector('[data-onboarding-focus-ring]');
    const blockers = root ? Object.fromEntries(
        [...root.querySelectorAll('[data-onboarding-blocker]')].map((item) => [item.dataset.onboardingBlocker, item])
    ) : {};
    const title = root?.querySelector('[data-onboarding-title]');
    const copy = root?.querySelector('[data-onboarding-copy]');
    const progress = root?.querySelector('[data-onboarding-progress]');
    const actions = root?.querySelector('[data-onboarding-actions]');
    const nextButton = root?.querySelector('[data-onboarding-next]');
    const skipButton = root?.querySelector('[data-onboarding-skip]');
    const introDialog = document.querySelector('[data-onboarding-intro]');
    const introStart = introDialog?.querySelector('[data-onboarding-intro-start]');

    const version = Number(body?.dataset.onboardingVersion || 1);
    const stateKey = `pacekeeper.onboarding.v${version}`;
    const stageKey = `pacekeeper.onboarding.stage.v${version}`;
    const replayKey = `pacekeeper.onboarding.replay.v${version}`;
    const totalSteps = 7;
    let activeTarget = null;
    let cleanupTargetListeners = () => {};
    let currentStage = localStorage.getItem(stageKey);
    let replayMode = localStorage.getItem(replayKey) === '1';

    const steps = {
        'home-create': {
            selector: '[data-onboarding-target="create-plan"]',
            number: 1,
            title: 'Canoviaと話して始めます',
            copy: 'まずは進めたいことや困っていることを、そのままCanoviaへ話します。目標が固まっていなくても大丈夫です。',
            event: 'click',
            next: 'plan-form',
        },
        'plan-form': {
            selector: '[data-onboarding-target="plan-form"]',
            number: 2,
            title: '最初の伴走Conversation',
            copy: 'Canoviaが会話からGoal Contextを組み立て、必要なことだけ確認します。十分になったら、この会話から仮Planを作れます。',
            next: 'ai-copy',
            largeTarget: true,
        },
        'ai-copy': {
            selector: '[data-onboarding-target="ai-copy"]',
            number: 3,
            title: 'Planの中身もAIと作れます',
            copy: '最初の会話で整理したGoal Contextを引き継いで、タスクと順番を作れます。Freeでは普段使っているAIへのhandoffもそのまま使えます。',
            event: 'click',
            next: 'ai-import',
        },
        'ai-import': {
            selector: '[data-onboarding-target="ai-import"]',
            number: 4,
            title: '相談結果をCanoviaへ戻します',
            copy: 'AIが最後に出したJSONをここへ貼り付けて登録すると、タスクと進む順番がロードマップになります。',
            next: 'roadmap-nav',
            largeTarget: true,
        },
        'roadmap-nav': {
            selector: '[data-onboarding-target="roadmap-nav"]',
            number: 5,
            title: '全体像は星座で見る',
            copy: 'Plan全体の形と現在地を確認する場所です。Taskの細かい実行判断とは分けて扱います。',
            event: 'click',
            next: null,
            actionLabel: '完了',
        },
        // Legacy stage aliases keep an onboarding already in progress readable
        // after Inbox left the primary navigation in V51.0.
        'inbox-nav': {
            selector: '[data-onboarding-target="execution-nav"]',
            number: 6,
            title: 'Primary Navigationを整理しました',
            copy: 'Inboxは独立機能として残しつつ、主要ナビゲーションはHome・星座・実行・Timelineへ整理されました。',
            event: 'click',
            next: null,
            actionLabel: '完了',
        },
        'inbox-capture': {
            selector: '[data-onboarding-target="execution-nav"]',
            number: 7,
            title: '実行は専用Surfaceへ',
            copy: '今やるTaskを進めるときは実行を使います。内容はPlanの種類に合わせて今後最適化されます。',
            event: 'click',
            next: null,
            actionLabel: '完了',
        },
        'today-nav': {
            selector: '[data-onboarding-target="execution-nav"]',
            number: 6,
            title: '「実行」',
            copy: '旧「今日」のおすすめ導線は実行Surfaceへ引き継がれます。',
            event: 'click',
            next: null,
            actionLabel: '完了',
        },
        'today-start': {
            selector: '[data-onboarding-target="execution-nav"]',
            number: 7,
            title: '実行する場所',
            copy: 'おすすめTaskを始めるときは実行Surfaceを使います。',
            event: 'click',
            next: null,
            actionLabel: '完了',
        },
        'replay-today': {
            selector: '[data-onboarding-target="execution-nav"]',
            number: 1,
            title: '「実行」',
            copy: '今やることを決め、実際の作業へ入る場所です。',
            actionLabel: '次へ',
            next: 'replay-roadmap',
        },
        'replay-roadmap': {
            selector: '[data-onboarding-target="roadmap-nav"]',
            number: 2,
            title: '「星座」',
            copy: 'Plan全体の形・完成度・現在地を確認する場所です。',
            actionLabel: '完了',
            next: null,
        },
    };

    const apiPost = (url) => {
        if (!url || !csrfToken) return Promise.resolve();
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
            },
            keepalive: true,
        }).catch(() => {});
    };

    const setStage = (stage) => {
        currentStage = stage;
        if (stage) localStorage.setItem(stageKey, stage);
        else localStorage.removeItem(stageKey);
    };

    const hideOnboarding = () => {
        cleanupTargetListeners();
        cleanupTargetListeners = () => {};
        activeTarget = null;
        root?.classList.add('hidden');
        pacekeeperCloseDialog(introDialog);
        body?.classList.remove('onboarding-active');
    };

    const showIntro = () => {
        if (!introDialog) {
            setStage('home-create');
            showStep('home-create');
            return;
        }
        root?.classList.add('hidden');
        body?.classList.remove('onboarding-active');
        pacekeeperOpenDialog(introDialog);
    };

    const completeOnboarding = (isReplay = false) => {
        hideOnboarding();
        setStage(null);
        if (isReplay || replayMode) {
            replayMode = false;
            localStorage.removeItem(replayKey);
            return;
        }
        localStorage.setItem(stateKey, 'completed');
        localStorage.setItem('pacekeeper.install.offer-pending', '1');
        apiPost(root?.dataset.completeUrl);
        window.dispatchEvent(new CustomEvent('pacekeeper:onboarding-complete'));
    };

    const skipOnboarding = () => {
        hideOnboarding();
        setStage(null);
        if (replayMode) {
            replayMode = false;
            localStorage.removeItem(replayKey);
            return;
        }
        localStorage.setItem(stateKey, 'skipped');
        apiPost(root?.dataset.skipUrl);
    };

    const placeOverlay = () => {
        if (!activeTarget || !root || root.classList.contains('hidden')) return;
        const rect = activeTarget.getBoundingClientRect();
        const pad = 8;
        const viewportWidth = window.innerWidth;
        const viewportHeight = window.innerHeight;
        const x = Math.max(8, rect.left - pad);
        const y = Math.max(8, rect.top - pad);
        const right = Math.min(viewportWidth - 8, rect.right + pad);
        const bottom = Math.min(viewportHeight - 8, rect.bottom + pad);
        const width = Math.max(0, right - x);
        const height = Math.max(0, bottom - y);

        const setRect = (element, left, top, w, h) => {
            if (!element) return;
            element.style.left = `${Math.max(0, left)}px`;
            element.style.top = `${Math.max(0, top)}px`;
            element.style.width = `${Math.max(0, w)}px`;
            element.style.height = `${Math.max(0, h)}px`;
        };

        setRect(blockers.top, 0, 0, viewportWidth, y);
        setRect(blockers.left, 0, y, x, height);
        setRect(blockers.right, right, y, viewportWidth - right, height);
        setRect(blockers.bottom, 0, bottom, viewportWidth, viewportHeight - bottom);
        setRect(focusRing, x, y, width, height);

        if (!bubble) return;
        const bubbleWidth = Math.min(360, viewportWidth - 24);
        bubble.style.width = `${bubbleWidth}px`;
        const bubbleHeight = bubble.offsetHeight || 180;
        const below = bottom + 12;
        const above = y - bubbleHeight - 12;
        let top = below + bubbleHeight <= viewportHeight - 12 ? below : above;
        if (top < 12) top = Math.max(12, viewportHeight - bubbleHeight - 12);
        const targetCenter = x + width / 2;
        const left = Math.min(viewportWidth - bubbleWidth - 12, Math.max(12, targetCenter - bubbleWidth / 2));
        bubble.style.left = `${left}px`;
        bubble.style.top = `${top}px`;
    };

    const showStep = (stage) => {
        if (!root || !stage) return;
        const step = steps[stage];
        if (!step) return;
        const target = pacekeeperVisibleTarget(step.selector);
        if (!target) return;

        cleanupTargetListeners();
        cleanupTargetListeners = () => {};
        activeTarget = target;
        root.classList.remove('hidden');
        body?.classList.add('onboarding-active');
        if (title) title.textContent = step.title;
        if (copy) copy.textContent = step.copy;
        if (progress) {
            if (replayMode) progress.textContent = '基本操作';
            else progress.textContent = step.number ? `${step.number} / ${totalSteps}` : 'できました';
        }
        if (actions && nextButton) {
            const showAction = Boolean(step.actionLabel);
            actions.classList.toggle('hidden', !showAction);
            nextButton.textContent = step.actionLabel || '次へ';
            nextButton.onclick = showAction ? () => {
                if (step.next) {
                    setStage(step.next);
                    showStep(step.next);
                } else {
                    completeOnboarding(replayMode);
                }
            } : null;
        }

        target.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });
        window.setTimeout(placeOverlay, 180);

        if (step.event === 'click') {
            const handler = () => {
                if (!step.next) return;
                setStage(step.next);
                window.setTimeout(() => showStep(step.next), 80);
            };
            target.addEventListener('click', handler, { once: true });
            cleanupTargetListeners = () => target.removeEventListener('click', handler);
        } else if (step.event === 'submit') {
            const form = target.matches('form') ? target : target.querySelector('form');
            if (form) {
                const handler = () => {
                    if (step.next) setStage(step.next);
                };
                form.addEventListener('submit', handler, { once: true });
                cleanupTargetListeners = () => form.removeEventListener('submit', handler);
            }
        }
    };

    introStart?.addEventListener('click', () => {
        pacekeeperCloseDialog(introDialog);
        setStage('home-create');
        window.setTimeout(() => showStep('home-create'), 80);
    });
    introDialog?.addEventListener('cancel', (event) => event.preventDefault());
    skipButton?.addEventListener('click', skipOnboarding);
    window.addEventListener('resize', placeOverlay);
    window.addEventListener('scroll', placeOverlay, { passive: true });

    document.querySelectorAll('[data-onboarding-restart]').forEach((button) => {
        button.addEventListener('click', () => {
            document.querySelector('[data-ui-settings-dialog]')?.close?.();
            replayMode = true;
            localStorage.setItem(replayKey, '1');
            setStage('replay-today');
            showStep('replay-today');
        });
    });

    const routeName = body?.dataset.routeName || '';
    if (currentStage === 'home-create' && routeName === 'plans.create') setStage('plan-form');
    if (currentStage === 'plan-form' && routeName === 'plans.ai_task_assistant.show') setStage('ai-copy');
    if (currentStage === 'ai-import' && routeName === 'plans.show') setStage('roadmap-nav');
    if (currentStage === 'roadmap-nav' && routeName === 'roadmap.index') setStage('today-nav');
    if (currentStage === 'today-nav' && routeName === 'navigation.index') setStage('today-start');
    if (currentStage === 'today-start' && routeName === 'work_sessions.active') setStage('timer');
    currentStage = localStorage.getItem(stageKey);

    const localState = localStorage.getItem(stateKey);
    const newUserDashboard = document.querySelector('[data-onboarding-new-user="1"]');
    if (replayMode && !currentStage) currentStage = 'replay-today';
    if (!currentStage && body?.dataset.onboardingAuto === '1' && !localState && newUserDashboard) {
        setStage('intro');
    }
    // A stored legacy stage must not resume over the Personalization first-run
    // experience. Explicit guide replay remains available from settings.
    if (body?.dataset.onboardingAuto !== '1' && !replayMode) {
        currentStage = null;
    }
    if (currentStage) {
        window.setTimeout(() => {
            if (currentStage === 'intro') showIntro();
            else showStep(currentStage);
        }, 260);
    }

    // PWA / home-screen install guidance. Automatic display is queued only
    // after the guided flow is complete, and never interrupts focus/timer mode.
    const installDialog = document.querySelector('[data-install-guide]');
    const installAction = installDialog?.querySelector('[data-install-guide-action]');
    const installLater = installDialog?.querySelector('[data-install-guide-later]');
    const installCopy = installDialog?.querySelector('[data-install-guide-copy]');
    const iosHelp = installDialog?.querySelector('[data-install-ios-help]');
    const browserHelp = installDialog?.querySelector('[data-install-browser-help]');
    const browserHelpTitle = installDialog?.querySelector('[data-install-browser-title]');
    const browserHelpCopy = installDialog?.querySelector('[data-install-browser-copy]');
    const isIos = /iPad|iPhone|iPod/.test(navigator.userAgent)
        || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    const isAndroid = /Android/i.test(navigator.userAgent);

    const configureInstallGuide = () => {
        if (!installDialog || !installAction) return;
        const hasNativePrompt = Boolean(pacekeeperDeferredInstallPrompt);
        iosHelp?.classList.toggle('hidden', !isIos || hasNativePrompt);
        browserHelp?.classList.toggle('hidden', isIos || hasNativePrompt);
        if (pacekeeperDeferredInstallPrompt) {
            installAction.textContent = 'ホーム画面に追加';
            installAction.disabled = false;
            if (installCopy) installCopy.textContent = 'ホーム画面から、普通のアプリのようにすぐ開けます。';
            return;
        }
        if (isIos) {
            installAction.textContent = '手順を確認しました';
            installAction.disabled = false;
            if (installCopy) installCopy.textContent = 'データを引き継ぐ専用画面を開いてから、Safariの共有メニューで追加します。';
            return;
        }
        installAction.textContent = '手順を確認しました';
        installAction.disabled = false;
        if (installCopy) installCopy.textContent = 'ブラウザのメニューからホーム画面へ追加できます。';
        if (browserHelpTitle) browserHelpTitle.textContent = isAndroid ? 'Androidの場合' : 'ブラウザから追加';
        if (browserHelpCopy) {
            browserHelpCopy.textContent = isAndroid
                ? 'ChromeやEdgeの右上メニューから「アプリをインストール」または「ホーム画面に追加」を選んでください。'
                : 'ブラウザのメニューから「アプリをインストール」または「ホーム画面に追加」を選んでください。';
        }
    };

    const showInstallGuide = (force = false) => {
        if (!installDialog || pacekeeperIsStandalone()) return;
        if (!force && body?.dataset.focusMode === '1') return;
        if (!force && localStorage.getItem('pacekeeper.install.offer-pending') !== '1') return;
        if (!force && localStorage.getItem('pacekeeper.install.state') === 'dismissed') return;
        configureInstallGuide();
        pacekeeperOpenDialog(installDialog);
    };

    document.querySelectorAll('[data-install-guide-open]').forEach((button) => {
        button.addEventListener('click', () => {
            document.querySelector('[data-ui-settings-dialog]')?.close?.();
            showInstallGuide(true);
        });
    });

    document.querySelectorAll('[data-install-guide-close]').forEach((button) => {
        button.addEventListener('click', () => pacekeeperCloseDialog(installDialog));
    });

    installLater?.addEventListener('click', () => {
        localStorage.setItem('pacekeeper.install.state', 'dismissed');
        localStorage.removeItem('pacekeeper.install.offer-pending');
        pacekeeperCloseDialog(installDialog);
    });

    installAction?.addEventListener('click', async () => {
        if (pacekeeperDeferredInstallPrompt) {
            const prompt = pacekeeperDeferredInstallPrompt;
            pacekeeperDeferredInstallPrompt = null;
            await prompt.prompt();
            const choice = await prompt.userChoice.catch(() => null);
            if (choice?.outcome === 'accepted') {
                localStorage.setItem('pacekeeper.install.state', 'installed');
                localStorage.removeItem('pacekeeper.install.offer-pending');
                pacekeeperCloseDialog(installDialog);
            } else {
                configureInstallGuide();
            }
            return;
        }
        // iOS Home Screen apps can have a separate cookie jar from Safari.
        // Move to the protected install page first so the saved launch URL can
        // carry a one-time account/Guest handoff into the standalone context.
        if (isIos && body?.dataset.pwaInstallUrl) {
            window.location.href = body.dataset.pwaInstallUrl;
            return;
        }

        // Browsers without beforeinstallprompt still use their menu. The
        // dynamic manifest already contains a short-lived protected start URL.
        localStorage.removeItem('pacekeeper.install.offer-pending');
        pacekeeperCloseDialog(installDialog);
    });

    window.addEventListener('pacekeeper:install-ready', configureInstallGuide);
    window.addEventListener('pacekeeper:onboarding-complete', () => {
        if (body?.dataset.focusMode !== '1') window.setTimeout(() => showInstallGuide(), 500);
    });

    // If onboarding completed on the timer screen, the offer waits until the
    // next normal page rather than interrupting the user's work.
    if (body?.dataset.focusMode !== '1') window.setTimeout(() => showInstallGuide(), 900);
});


// -----------------------------------------------------------------------------
// v37 UX cleanup: prioritise the current action and reduce visual noise.
// -----------------------------------------------------------------------------
document.addEventListener('DOMContentLoaded', () => {
    const dashboard = document.getElementById('behaviorDashboard');
    if (dashboard) {
        const hero = dashboard.querySelector('.pk-v22-hero-stage');
        const active = dashboard.querySelector('[data-action-home-focus-deck], .pk-v18-active-session');
        const recommendation = dashboard.querySelector('.pk-v18-recommendation');
        let anchor = hero;
        if (anchor && active) {
            anchor.after(active);
            anchor = active;
        }
        if (anchor && recommendation) {
            anchor.after(recommendation);
        }
    }

    // Layout already renders flash status globally. Remove identical duplicates
    // produced by page-specific legacy blocks.
    const notices = [...document.querySelectorAll('.assistant-notice-info')];
    const seen = new Set();
    notices.forEach((notice) => {
        const key = notice.textContent.trim();
        if (key && seen.has(key)) notice.remove();
        else if (key) seen.add(key);
    });

    // Dark is currently Canovia's only official theme, so do not present a
    // non-actionable theme section as if it were a setting.
    const settingsDialog = document.querySelector('[data-ui-settings-dialog]');
    settingsDialog?.querySelectorAll('fieldset').forEach((fieldset) => {
        if (fieldset.querySelector('legend')?.textContent.trim() === 'テーマ') fieldset.classList.add('hidden');
    });

    // On narrow screens keep Roadmap's primary actions visible and move
    // supporting links into the existing overflow menu.
    const roadmapPage = document.querySelector('.pk-v19-roadmap-page');
    const roadmapMenuSummary = roadmapPage?.querySelector('summary[aria-label="計画メニュー"]');
    const roadmapMenu = roadmapMenuSummary?.parentElement?.querySelector(':scope > div');
    if (roadmapPage && roadmapMenu) {
        const existingMenuHrefs = new Set([...roadmapMenu.querySelectorAll('a[href]')].map((link) => link.href));
        const supportingLinks = [...roadmapPage.querySelectorAll('a[href]')].filter((link) =>
            !roadmapMenu.contains(link)
            && !existingMenuHrefs.has(link.href)
            && (link.href.includes('/resources') || link.href.includes('/collaboration'))
        );
        const clones = supportingLinks.map((link) => {
            const clone = link.cloneNode(true);
            clone.className = 'block rounded-xl px-3 py-2 text-sm text-slate-200 hover:bg-slate-800';
            clone.dataset.v37MobileRoadmapLink = '1';
            roadmapMenu.prepend(clone);
            return clone;
        });
        const applyRoadmapDensity = () => {
            if (!roadmapPage.isConnected) {
                window.removeEventListener('resize', applyRoadmapDensity);
                return;
            }
            const mobile = window.matchMedia('(max-width: 767px)').matches;
            supportingLinks.forEach((link) => link.classList.toggle('hidden', mobile));
            clones.forEach((link) => link.classList.toggle('hidden', !mobile));
        };
        applyRoadmapDensity();
        window.addEventListener('resize', applyRoadmapDensity, { passive: true });
    }

    mountWorkTimerSafety();
});


// -----------------------------------------------------------------------------
// V40.5 Canovia Guide: searchable catalog + reusable cross-screen Spotlight.
// Future surfaces (for example Achievement "GO!") only need data-guide-start
// or window.CanoviaGuide.start(guideKey).
// -----------------------------------------------------------------------------
document.addEventListener('DOMContentLoaded', () => {
    const catalogElement = document.getElementById('canovia-guide-catalog');
    const dialog = document.querySelector('[data-guide-dialog]');
    const runner = document.querySelector('[data-guide-runner]');

    if (!catalogElement || !dialog || !runner) return;

    let catalog;
    try {
        catalog = JSON.parse(catalogElement.textContent || '{}');
    } catch (_) {
        return;
    }

    const version = Number(catalog.version || 1);
    const guides = catalog.guides || {};
    const authenticated = Boolean(catalog.authenticated);
    const stateKey = `canovia.guide.active.v${version}`;
    const completedKey = `canovia.guide.completed.v${version}`;

    const searchInput = dialog.querySelector('[data-guide-search]');
    const emptyState = dialog.querySelector('[data-guide-empty]');
    const categoryElements = [...dialog.querySelectorAll('[data-guide-category]')];
    const itemElements = [...dialog.querySelectorAll('[data-guide-start]')];

    const bubble = runner.querySelector('[data-guide-bubble]');
    const focusRing = runner.querySelector('[data-guide-focus-ring]');
    const blockers = Object.fromEntries(
        [...runner.querySelectorAll('[data-guide-blocker]')]
            .map((element) => [element.dataset.guideBlocker, element])
    );
    const titleElement = runner.querySelector('[data-guide-title]');
    const copyElement = runner.querySelector('[data-guide-copy]');
    const progressElement = runner.querySelector('[data-guide-progress]');
    const missingElement = runner.querySelector('[data-guide-missing]');
    const prevButton = runner.querySelector('[data-guide-prev]');
    const nextButton = runner.querySelector('[data-guide-next]');
    const stopButton = runner.querySelector('[data-guide-stop]');

    let activeState = null;
    let activeTarget = null;
    let missingMode = false;
    let cleanupTargetListener = () => {};

    const parseStored = (key, fallback) => {
        try {
            const value = JSON.parse(localStorage.getItem(key) || '');
            return value && typeof value === 'object' ? value : fallback;
        } catch (_) {
            return fallback;
        }
    };

    const completed = () => parseStored(completedKey, {});

    const saveCompleted = (key) => {
        const map = completed();
        map[key] = new Date().toISOString();
        localStorage.setItem(completedKey, JSON.stringify(map));
        renderCompletion();
    };

    const renderCompletion = () => {
        const map = completed();
        dialog.querySelectorAll('[data-guide-completion]').forEach((element) => {
            const key = element.dataset.guideCompletion;
            const done = Boolean(map[key]);
            element.textContent = done ? '✓ 完了' : 'GO!';
            element.classList.toggle('is-complete', done);
        });
    };

    const saveState = () => {
        if (activeState) localStorage.setItem(stateKey, JSON.stringify(activeState));
        else localStorage.removeItem(stateKey);
    };

    const currentGuide = () => activeState ? guides[activeState.key] : null;
    const currentStep = () => {
        const guide = currentGuide();
        return guide?.steps?.[Number(activeState?.index || 0)] || null;
    };

    const pathMatches = (path) => !path || window.location.pathname === path;

    const targetFor = (step) => {
        if (!step?.target) return null;
        return pacekeeperVisibleTarget(
            `[data-guide-target="${CSS.escape(step.target)}"], [data-onboarding-target="${CSS.escape(step.target)}"]`
        );
    };

    const setRect = (element, left, top, width, height) => {
        if (!element) return;
        element.style.left = `${Math.max(0, left)}px`;
        element.style.top = `${Math.max(0, top)}px`;
        element.style.width = `${Math.max(0, width)}px`;
        element.style.height = `${Math.max(0, height)}px`;
    };

    const placeBubbleCentered = () => {
        if (!bubble) return;
        const width = Math.min(370, window.innerWidth - 24);
        bubble.style.width = `${width}px`;
        bubble.style.left = `${Math.max(12, (window.innerWidth - width) / 2)}px`;
        const height = bubble.offsetHeight || 190;
        bubble.style.top = `${Math.max(12, (window.innerHeight - height) / 2)}px`;
    };

    const placeOverlay = () => {
        if (!activeTarget || runner.classList.contains('hidden')) return;

        const rect = activeTarget.getBoundingClientRect();
        const pad = 8;
        const viewportWidth = window.innerWidth;
        const viewportHeight = window.innerHeight;
        const left = Math.max(8, rect.left - pad);
        const top = Math.max(8, rect.top - pad);
        const right = Math.min(viewportWidth - 8, rect.right + pad);
        const bottom = Math.min(viewportHeight - 8, rect.bottom + pad);
        const width = Math.max(0, right - left);
        const height = Math.max(0, bottom - top);

        setRect(blockers.top, 0, 0, viewportWidth, top);
        setRect(blockers.left, 0, top, left, height);
        setRect(blockers.right, right, top, viewportWidth - right, height);
        setRect(blockers.bottom, 0, bottom, viewportWidth, viewportHeight - bottom);
        setRect(focusRing, left, top, width, height);

        if (!bubble) return;
        const bubbleWidth = Math.min(370, viewportWidth - 24);
        bubble.style.width = `${bubbleWidth}px`;
        const bubbleHeight = bubble.offsetHeight || 190;
        const below = bottom + 12;
        const above = top - bubbleHeight - 12;
        let bubbleTop = below + bubbleHeight <= viewportHeight - 12 ? below : above;
        if (bubbleTop < 12) bubbleTop = Math.max(12, viewportHeight - bubbleHeight - 12);
        const center = left + width / 2;
        const bubbleLeft = Math.min(
            viewportWidth - bubbleWidth - 12,
            Math.max(12, center - bubbleWidth / 2)
        );
        bubble.style.left = `${bubbleLeft}px`;
        bubble.style.top = `${bubbleTop}px`;
    };

    const clearTarget = () => {
        cleanupTargetListener();
        cleanupTargetListener = () => {};
        activeTarget = null;
    };

    const hideRunner = () => {
        clearTarget();
        runner.classList.add('hidden');
        document.body.classList.remove('canovia-guide-active');
    };

    const stopGuide = ({ openCatalog = false } = {}) => {
        activeState = null;
        saveState();
        hideRunner();
        if (openCatalog) {
            pacekeeperOpenDialog(dialog);
            searchInput?.focus();
        }
    };

    const finishGuide = () => {
        const key = activeState?.key;
        if (key) saveCompleted(key);
        stopGuide({ openCatalog: true });
    };

    const routeToStep = (step) => {
        if (!step?.path || pathMatches(step.path)) return false;
        window.location.assign(step.path);
        return true;
    };

    const setAllBlockers = () => {
        const viewportWidth = window.innerWidth;
        const viewportHeight = window.innerHeight;
        setRect(blockers.top, 0, 0, viewportWidth, viewportHeight);
        setRect(blockers.left, 0, 0, 0, 0);
        setRect(blockers.right, 0, 0, 0, 0);
        setRect(blockers.bottom, 0, 0, 0, 0);
        if (focusRing) {
            focusRing.style.width = '0px';
            focusRing.style.height = '0px';
        }
    };

    const renderStep = () => {
        const guide = currentGuide();
        const step = currentStep();

        if (!guide || !step) {
            finishGuide();
            return;
        }

        if (routeToStep(step)) return;

        clearTarget();
        missingMode = false;

        const index = Number(activeState.index || 0);
        const total = guide.steps?.length || 1;
        if (progressElement) progressElement.textContent = `${index + 1} / ${total} · ${guide.title}`;
        if (titleElement) titleElement.textContent = step.title || guide.title;
        if (copyElement) copyElement.textContent = step.copy || guide.description || '';

        runner.classList.remove('hidden');
        document.body.classList.add('canovia-guide-active');

        const target = targetFor(step);

        if (!target) {
            missingMode = true;
            missingElement?.classList.remove('hidden');
            if (prevButton) prevButton.disabled = index <= 0;
            if (nextButton) {
                nextButton.disabled = false;
                nextButton.textContent = 'ガイド一覧へ';
            }
            setAllBlockers();
            requestAnimationFrame(placeBubbleCentered);
            return;
        }

        missingElement?.classList.add('hidden');
        activeTarget = target;
        if (prevButton) prevButton.disabled = index <= 0;

        const isClickStep = (step.advance || 'next') === 'click';
        if (nextButton) {
            nextButton.disabled = isClickStep;
            nextButton.textContent = isClickStep
                ? '光っている場所を押す'
                : (index >= total - 1 ? '完了' : '次へ');
        }

        activeTarget.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });

        if (isClickStep) {
            const handler = () => {
                const nextIndex = index + 1;
                if (nextIndex >= total) {
                    saveCompleted(activeState.key);
                    activeState = null;
                } else {
                    activeState.index = nextIndex;
                }
                saveState();
            };
            activeTarget.addEventListener('click', handler, { capture: true, once: true });
            cleanupTargetListener = () => activeTarget?.removeEventListener('click', handler, true);
        }

        window.setTimeout(placeOverlay, 180);
    };

    const advance = (delta = 1) => {
        if (!activeState) return;
        const guide = currentGuide();
        if (!guide) return;

        const nextIndex = Number(activeState.index || 0) + delta;
        if (nextIndex < 0) return;

        if (nextIndex >= (guide.steps?.length || 0)) {
            finishGuide();
            return;
        }

        activeState.index = nextIndex;
        saveState();
        renderStep();
    };

    const startGuide = (key) => {
        const guide = guides[key];
        if (!guide) return false;

        if (guide.requires_auth && !authenticated) {
            window.location.assign('/login');
            return false;
        }

        pacekeeperCloseDialog(dialog);

        const onboardingRoot = document.querySelector('[data-onboarding-root]');
        onboardingRoot?.classList.add('hidden');
        const onboardingIntro = document.querySelector('[data-onboarding-intro]');
        pacekeeperCloseDialog(onboardingIntro);
        document.body.classList.remove('onboarding-active');

        activeState = { key, index: 0 };
        saveState();
        renderStep();
        return true;
    };

    const openCatalog = () => {
        hideRunner();
        renderCompletion();
        pacekeeperOpenDialog(dialog);
        window.setTimeout(() => searchInput?.focus(), 60);
    };

    const applySearch = () => {
        const query = (searchInput?.value || '').trim().toLocaleLowerCase('ja');
        let visibleCount = 0;

        itemElements.forEach((item) => {
            const text = (item.dataset.guideSearchText || '').toLocaleLowerCase('ja');
            const visible = query === '' || text.includes(query);
            item.classList.toggle('hidden', !visible);
            if (visible) visibleCount++;
        });

        categoryElements.forEach((category) => {
            const visibleItems = [...category.querySelectorAll('[data-guide-start]')]
                .filter((item) => !item.classList.contains('hidden'));
            category.classList.toggle('hidden', visibleItems.length === 0);
            if (query && visibleItems.length > 0) category.open = true;
        });

        emptyState?.classList.toggle('hidden', visibleCount > 0);
    };

    document.querySelectorAll('[data-guide-open]').forEach((button) => {
        button.addEventListener('click', openCatalog);
    });
    dialog.querySelectorAll('[data-guide-close]').forEach((button) => {
        button.addEventListener('click', () => pacekeeperCloseDialog(dialog));
    });
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) pacekeeperCloseDialog(dialog);
    });
    searchInput?.addEventListener('input', applySearch);

    document.addEventListener('click', (event) => {
        const start = event.target.closest('[data-guide-start]');
        if (!start) return;
        event.preventDefault();
        startGuide(start.dataset.guideStart);
    });

    prevButton?.addEventListener('click', () => advance(-1));
    nextButton?.addEventListener('click', () => {
        if (missingMode) {
            stopGuide({ openCatalog: true });
            return;
        }
        if (!nextButton.disabled) advance(1);
    });
    stopButton?.addEventListener('click', () => stopGuide());

    window.addEventListener('resize', () => {
        if (activeTarget) placeOverlay();
        else if (!runner.classList.contains('hidden')) placeBubbleCentered();
    });
    window.addEventListener('scroll', () => {
        if (activeTarget) placeOverlay();
    }, { passive: true });

    window.CanoviaGuide = {
        start: startGuide,
        open: openCatalog,
        stop: () => stopGuide(),
        completed: (key) => Boolean(completed()[key]),
    };

    renderCompletion();
    applySearch();

    const restored = parseStored(stateKey, null);
    if (restored?.key && guides[restored.key]) {
        activeState = restored;
        renderStep();
    }
});


// -----------------------------------------------------------------------------
// V40.7.3 Study Practice post-action reveal.
//
// After JSON import/assessment the browser returns to the same long page. Scroll
// directly to the newly available step so a successful POST never looks inert.
// -----------------------------------------------------------------------------
document.addEventListener('DOMContentLoaded', () => {
    const root = document.querySelector('[data-study-practice-root]');
    const targetId = root?.dataset.studyPracticeScrollTo || '';
    if (!targetId) return;

    const target = document.getElementById(targetId);
    if (!target) return;

    window.setTimeout(() => {
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, 80);
});


// -----------------------------------------------------------------------------
// V40.7.1 Study Practice draft persistence.
//
// The learner should never need a Save or Resume action. Every edit is written
// to localStorage immediately and debounced to the active StudyPracticeSession.
// The server snapshot is authoritative across normal navigation; localStorage
// covers the short gap before a network autosave finishes.
// -----------------------------------------------------------------------------
document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('[data-study-practice-draft-form]');
    if (!form) return;

    const draftUrl = form.dataset.draftUrl;
    const practiceSessionId = Number(form.dataset.draftSessionId || 0);
    const sessionToken = form.dataset.draftSessionToken;
    if (!draftUrl || !practiceSessionId || !sessionToken) return;

    const storageKey = `canovia.study-practice.draft.v1.${sessionToken}`;
    const answerElements = [...form.querySelectorAll('[name^="answers["]')];
    const namePattern = /^answers\[([^\]]+)\]\[([^\]]+)\](\[\])?$/;
    let saveTimer = null;

    const collectAnswers = () => {
        const answers = {};

        answerElements.forEach((element) => {
            const match = element.name.match(namePattern);
            if (!match) return;

            const [, questionId, fieldId, arraySuffix] = match;
            answers[questionId] ||= {};

            if (arraySuffix) {
                answers[questionId][fieldId] ||= [];
                if (element.checked) answers[questionId][fieldId].push(element.value);
                return;
            }

            if (element.type === 'radio') {
                if (!(fieldId in answers[questionId])) answers[questionId][fieldId] = '';
                if (element.checked) answers[questionId][fieldId] = element.value;
                return;
            }

            answers[questionId][fieldId] = element.value ?? '';
        });

        return answers;
    };

    const applyAnswers = (answers) => {
        if (!answers || typeof answers !== 'object') return;

        answerElements.forEach((element) => {
            const match = element.name.match(namePattern);
            if (!match) return;

            const [, questionId, fieldId, arraySuffix] = match;
            const value = answers?.[questionId]?.[fieldId];

            if (arraySuffix) {
                const values = Array.isArray(value) ? value.map(String) : [];
                element.checked = values.includes(String(element.value));
                return;
            }

            if (element.type === 'radio') {
                element.checked = String(value ?? '') === String(element.value);
                return;
            }

            if (value !== undefined && value !== null) element.value = String(value);
        });
    };

    const readLocalDraft = () => {
        try {
            const value = JSON.parse(localStorage.getItem(storageKey) || 'null');
            if (!value || typeof value !== 'object' || typeof value.answers !== 'object') return null;
            return value;
        } catch (_) {
            return null;
        }
    };

    const persistLocal = () => {
        const snapshot = {
            updatedAt: Date.now(),
            answers: collectAnswers(),
        };

        try {
            localStorage.setItem(storageKey, JSON.stringify(snapshot));
        } catch (_) {
            // Server autosave remains available if local storage is unavailable.
        }

        return snapshot;
    };

    const persistServer = async (snapshot, keepalive = false) => {
        if (!csrfToken || !snapshot) return;

        const payload = JSON.stringify({
            practice_session_id: practiceSessionId,
            answers_json: JSON.stringify(snapshot.answers),
        });

        // Browsers cap keepalive requests to a small payload. localStorage still
        // protects larger answers and the next page load retries them normally.
        if (keepalive && payload.length > 50000) return;

        try {
            await fetch(draftUrl, {
                method: 'POST',
                credentials: 'same-origin',
                keepalive,
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: payload,
            });
        } catch (_) {
            // Draft persistence is intentionally silent. The local snapshot is
            // kept and will be retried when this same practice session reopens.
        }
    };

    const scheduleSave = () => {
        const snapshot = persistLocal();
        if (saveTimer) window.clearTimeout(saveTimer);
        saveTimer = window.setTimeout(() => persistServer(snapshot), 450);
    };

    const localDraft = readLocalDraft();
    const serverSavedAt = Date.parse(form.dataset.draftSavedAt || '') || 0;

    if (localDraft && Number(localDraft.updatedAt || 0) > serverSavedAt) {
        applyAnswers(localDraft.answers);
        // A previous navigation may have happened before the debounce finished.
        // Push the local copy back to the server without showing any resume UI.
        window.setTimeout(() => persistServer(localDraft), 0);
    }

    form.addEventListener('input', scheduleSave);
    form.addEventListener('change', scheduleSave);

    const flushBeforeLeave = () => {
        if (saveTimer) {
            window.clearTimeout(saveTimer);
            saveTimer = null;
        }
        const snapshot = persistLocal();
        persistServer(snapshot, true);
    };

    window.addEventListener('pagehide', flushBeforeLeave);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') flushBeforeLeave();
    });
});


// -----------------------------------------------------------------------------
// V41.20 Canovia Instant Navigation dynamic-page lifecycle.
//
// The global shell keeps its original listeners. Only the swapped core-page
// region is re-initialized after a soft navigation.
// -----------------------------------------------------------------------------
function initializeInstantDashboardPage() {
    const root = document.getElementById('behaviorDashboard');
    if (!root || root.dataset.canoviaInstantInitialized === '1') return;
    root.dataset.canoviaInstantInitialized = '1';

    const hero = root.querySelector('.pk-v22-hero-stage');
    const active = root.querySelector('[data-action-home-focus-deck], .pk-v18-active-session');
    const recommendation = root.querySelector('.pk-v18-recommendation');
    let anchor = hero;
    if (anchor && active) {
        anchor.after(active);
        anchor = active;
    }
    if (anchor && recommendation) anchor.after(recommendation);

    let planSwitches = 0;
    let taskViews = 0;
    let workStarted = root.dataset.workStarted === '1';
    let idleNudgeShown = false;
    const enteredAt = Date.now();
    const viewedTaskIds = new Set();
    const tabs = [...root.querySelectorAll('[data-dashboard-tab]')];
    const panels = [...root.querySelectorAll('[data-dashboard-panel]')];
    let activeTarget = tabs.find((tab) => tab.getAttribute('aria-selected') === 'true')?.dataset.dashboardTab || 'overall';

    const updateNavigationContext = (planId = null) => {
        const baseUrl = root.dataset.navigationUrl;
        if (!baseUrl) return;

        const url = new URL(baseUrl, window.location.origin);
        if (planId) url.searchParams.set('plan_id', String(planId));
        else url.searchParams.delete('plan_id');

        document.querySelectorAll('[data-navigation-link]').forEach((link) => {
            link.href = url.pathname + url.search;
        });
    };

    const recordTaskView = (details) => {
        const taskId = Number(details?.dataset.taskId);
        if (!taskId || viewedTaskIds.has(taskId)) return;
        viewedTaskIds.add(taskId);
        taskViews = viewedTaskIds.size;
        recordBehavior(root, 'task_viewed', {
            plan_id: Number(details.dataset.planId),
            task_id: taskId,
            metadata: { task_views: taskViews },
        });
    };

    const activateTab = (target) => {
        if (!target || target === activeTarget) return;
        activeTarget = target;
        tabs.forEach((tab) => {
            const activeTab = tab.dataset.dashboardTab === target;
            tab.classList.toggle('nav-link-active', activeTab);
            tab.setAttribute('aria-selected', activeTab ? 'true' : 'false');
        });
        panels.forEach((panel) => panel.classList.toggle('hidden', panel.dataset.dashboardPanel !== target));

        const tab = tabs.find((item) => item.dataset.dashboardTab === target);
        const contextPlanId = tab?.dataset.planId ? Number(tab.dataset.planId) : null;
        updateNavigationContext(contextPlanId);

        if (contextPlanId) {
            planSwitches += 1;
            recordBehavior(root, 'plan_tab_viewed', {
                plan_id: contextPlanId,
                metadata: { plan_switches: planSwitches },
            });
            const panel = panels.find((item) => item.dataset.dashboardPanel === target);
            recordTaskView(panel?.querySelector('[data-task-view]'));
        }
    };

    const initialTab = tabs.find((tab) => tab.dataset.dashboardTab === activeTarget);
    updateNavigationContext(initialTab?.dataset.planId ? Number(initialTab.dataset.planId) : null);
    tabs.forEach((tab) => tab.addEventListener('click', () => activateTab(tab.dataset.dashboardTab)));
    root.querySelectorAll('[data-open-dashboard-tab]').forEach((button) => {
        button.addEventListener('click', () => activateTab(button.dataset.openDashboardTab));
    });
    root.querySelectorAll('[data-task-view]').forEach((element) => {
        element.addEventListener('click', () => recordTaskView(element));
    });
    root.querySelectorAll('[data-work-start-form]').forEach((form) => {
        form.addEventListener('submit', () => { workStarted = true; });
    });

    root.querySelectorAll('[data-action-home-dismiss-form]').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();

            const button = form.querySelector('button[type="submit"]');
            if (button) button.disabled = true;

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
                    },
                    credentials: 'same-origin',
                    body: new FormData(form),
                });

                if (!response.ok) throw new Error('action-home-dismiss-failed');

                const card = form.closest('[data-action-home-signal-card]');
                const section = form.closest('[data-action-home-signals]');
                card?.remove();

                const remaining = section?.querySelectorAll('[data-action-home-signal-card]').length ?? 0;
                const count = section?.querySelector('[data-action-home-signal-count]');
                if (count) count.textContent = `${remaining}件`;
                if (section && remaining === 0) section.remove();
            } catch (_) {
                // Preserve a no-JS / network fallback. The server action is
                // idempotent and keeps the WorkLog/history intact.
                form.submit();
            }
        });
    });

    const idleInterval = window.setInterval(() => {
        if (!root.isConnected) {
            window.clearInterval(idleInterval);
            return;
        }

        const elapsedSeconds = Math.floor((Date.now() - enteredAt) / 1000);
        if (document.visibilityState !== 'visible' || workStarted || elapsedSeconds < 90 || planSwitches + taskViews < 2) return;
        if (!idleNudgeShown) {
            root.querySelector('[data-idle-nudge]')?.classList.remove('hidden');
            idleNudgeShown = true;
        }
        recordBehavior(root, 'dashboard_idle', {
            metadata: {
                elapsed_seconds: elapsedSeconds,
                plan_switches: planSwitches,
                task_views: taskViews,
                page_visible: true,
                work_started: false,
            },
        });
    }, 15000);
}

function initializeInstantRoadmapPage() {
    const page = document.querySelector('.pk-v19-roadmap-page');
    if (!page || page.dataset.canoviaInstantInitialized === '1') return;
    page.dataset.canoviaInstantInitialized = '1';

    applyUiPreferences();

    page.querySelectorAll('[data-roadmap-view-root]').forEach((root) => {
        setRoadmapView(root, resolveRoadmapView(root), false);
        root.querySelectorAll('[data-roadmap-view-button]').forEach((button) => {
            button.addEventListener('click', () => setRoadmapView(root, button.dataset.roadmapViewButton));
        });
    });

    const roadmapDetailTimers = new WeakMap();
    const clearDetailTimer = (stop) => {
        const timer = roadmapDetailTimers.get(stop);
        if (timer) window.clearTimeout(timer);
        roadmapDetailTimers.delete(stop);
    };
    const scheduleDetailClose = (stop) => {
        clearDetailTimer(stop);
        if (!stop.open) return;
        const viewRoot = stop.closest('[data-roadmap-view-root]');
        if (viewRoot?.dataset.roadmapPlanId === 'preview') return;
        roadmapDetailTimers.set(stop, window.setTimeout(() => {
            if (stop.isConnected && stop.open) stop.removeAttribute('open');
            roadmapDetailTimers.delete(stop);
        }, 4000));
    };

    page.querySelectorAll('[data-map-stop]').forEach((stop) => {
        stop.addEventListener('toggle', () => {
            if (!stop.open) {
                clearDetailTimer(stop);
                return;
            }
            const root = stop.closest('[data-roadmap-map]');
            root?.querySelectorAll('[data-map-stop][open]').forEach((other) => {
                if (other !== stop) {
                    clearDetailTimer(other);
                    other.removeAttribute('open');
                }
            });
            scheduleDetailClose(stop);
        });
        stop.addEventListener('pointerdown', () => clearDetailTimer(stop));
        stop.addEventListener('focusin', () => clearDetailTimer(stop));
        stop.addEventListener('pointerleave', () => scheduleDetailClose(stop));
        stop.addEventListener('focusout', (event) => {
            if (!stop.contains(event.relatedTarget)) scheduleDetailClose(stop);
        });
    });

    page.querySelectorAll('[data-roadmap-spatial-scroll]').forEach((scroll) => {
        if (scroll.dataset.roadmapSpatialAutoCenter === '0') return;
        const current = scroll.querySelector('[data-roadmap-spatial-node][data-roadmap-current="1"]');
        if (!current) return;

        window.requestAnimationFrame(() => {
            if (!scroll.isConnected || !current.isConnected) return;
            const left = Math.max(0, current.offsetLeft - (scroll.clientWidth / 2) + (current.offsetWidth / 2));
            const top = Math.max(0, current.offsetTop - (scroll.clientHeight / 2) + (current.offsetHeight / 2));
            scroll.scrollTo({ left, top, behavior: 'auto' });
        });
    });

    page.querySelectorAll('[data-roadmap-overview]').forEach((button) => {
        button.addEventListener('click', () => {
            const root = button.closest('[data-roadmap-view-root]');
            if (!root) return;
            setRoadmapView(root, 'map');
            root.querySelectorAll('[data-map-stop][open]').forEach((stop) => stop.removeAttribute('open'));
            root.querySelector('[data-roadmap-map]')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
    });

    page.querySelectorAll('[data-roadmap-plan-pager]').forEach((pager) => {
        let startX = 0;
        let startY = 0;
        let tracking = false;

        const navigate = (url, direction) => {
            if (!url) return;
            pager.classList.add(direction === 'next' ? 'is-leaving-left' : 'is-leaving-right');
            window.setTimeout(() => {
                if (window.CanoviaInstantNavigation?.navigate) {
                    void window.CanoviaInstantNavigation.navigate(url);
                } else {
                    window.location.assign(url);
                }
            }, 110);
        };

        pager.addEventListener('touchstart', (event) => {
            const touch = event.touches?.[0];
            if (!touch || event.target.closest('button, a, input, select, textarea, [data-roadmap-plan-tabs], [data-roadmap-spatial-scroll]')) return;
            startX = touch.clientX;
            startY = touch.clientY;
            tracking = true;
        }, { passive: true });

        pager.addEventListener('touchend', (event) => {
            if (!tracking) return;
            tracking = false;
            const touch = event.changedTouches?.[0];
            if (!touch) return;
            const dx = touch.clientX - startX;
            const dy = touch.clientY - startY;
            if (Math.abs(dx) < 58 || Math.abs(dx) < Math.abs(dy) * 1.25) return;
            if (dx < 0) navigate(pager.dataset.nextUrl, 'next');
            else navigate(pager.dataset.prevUrl, 'prev');
        }, { passive: true });

        pager.addEventListener('keydown', (event) => {
            if (event.key === 'ArrowRight' && pager.dataset.nextUrl) {
                event.preventDefault();
                navigate(pager.dataset.nextUrl, 'next');
            }
            if (event.key === 'ArrowLeft' && pager.dataset.prevUrl) {
                event.preventDefault();
                navigate(pager.dataset.prevUrl, 'prev');
            }
        });
    });

    const roadmapMenuSummary = page.querySelector('summary[aria-label="計画メニュー"]');
    const roadmapMenu = roadmapMenuSummary?.parentElement?.querySelector(':scope > div');
    if (roadmapMenu) {
        const existingMenuHrefs = new Set([...roadmapMenu.querySelectorAll('a[href]')].map((link) => link.href));
        const supportingLinks = [...page.querySelectorAll('a[href]')].filter((link) =>
            !roadmapMenu.contains(link)
            && !existingMenuHrefs.has(link.href)
            && (link.href.includes('/resources') || link.href.includes('/collaboration'))
        );
        const clones = supportingLinks.map((link) => {
            const clone = link.cloneNode(true);
            clone.className = 'block rounded-xl px-3 py-2 text-sm text-slate-200 hover:bg-slate-800';
            clone.dataset.v37MobileRoadmapLink = '1';
            roadmapMenu.prepend(clone);
            return clone;
        });
        const applyDensity = () => {
            if (!page.isConnected) {
                window.removeEventListener('resize', applyDensity);
                return;
            }
            const mobile = window.matchMedia('(max-width: 767px)').matches;
            supportingLinks.forEach((link) => link.classList.toggle('hidden', mobile));
            clones.forEach((link) => link.classList.toggle('hidden', !mobile));
        };
        applyDensity();
        window.addEventListener('resize', applyDensity, { passive: true });
    }

    const activePlanTab = page.querySelector('[data-roadmap-plan-tabs] .pk-v19-plan-card.is-active, [data-roadmap-plan-tabs] .roadmap-plan-tab.is-active');
    activePlanTab?.scrollIntoView({ behavior: 'auto', block: 'nearest', inline: 'center' });
}

async function captureInstantOfflineSnapshot() {
    const snapshotElement = document.getElementById('pacekeeper-offline-snapshot');
    if (!snapshotElement || !('indexedDB' in window)) return;

    try {
        const snapshot = JSON.parse(snapshotElement.textContent || '{}');
        if (!snapshot || typeof snapshot !== 'object') return;
        snapshot.last_path = window.location.pathname + window.location.search;
        snapshot.last_title = document.title;
        snapshot.client_captured_at = new Date().toISOString();
        await writeOfflineState('latest_snapshot', snapshot);
    } catch (_) {}
}

let offlineSnapshotScheduleHandle = null;
let offlineSnapshotScheduleMode = null;

function scheduleInstantOfflineSnapshotCapture() {
    if (offlineSnapshotScheduleHandle !== null) {
        if (offlineSnapshotScheduleMode === 'idle' && typeof window.cancelIdleCallback === 'function') {
            window.cancelIdleCallback(offlineSnapshotScheduleHandle);
        } else {
            window.clearTimeout(offlineSnapshotScheduleHandle);
        }
        offlineSnapshotScheduleHandle = null;
        offlineSnapshotScheduleMode = null;
    }

    const run = () => {
        offlineSnapshotScheduleHandle = null;
        offlineSnapshotScheduleMode = null;
        void captureInstantOfflineSnapshot();
    };

    if (typeof window.requestIdleCallback === 'function') {
        offlineSnapshotScheduleMode = 'idle';
        offlineSnapshotScheduleHandle = window.requestIdleCallback(run, { timeout: 1200 });
        return;
    }

    offlineSnapshotScheduleMode = 'timeout';
    offlineSnapshotScheduleHandle = window.setTimeout(run, 280);
}

function mountCompanionPalette(root = document) {
    const shells = root.matches?.('[data-companion-shell]')
        ? [root]
        : [...root.querySelectorAll?.('[data-companion-shell]') || []];

    shells.forEach((shell) => {
        if (shell.dataset.companionPaletteMounted === '1') return;

        const trigger = shell.querySelector('[data-companion-palette-open]');
        const dialog = shell.querySelector('[data-companion-palette]');
        const close = shell.querySelector('[data-companion-palette-close]');
        const session = shell.querySelector('[data-companion-palette-session]');
        const status = shell.querySelector('[data-companion-palette-status]');
        if (!trigger || !dialog || !session) return;

        const syncExpanded = () => {
            trigger.setAttribute('aria-expanded', dialog.open ? 'true' : 'false');
        };

        const openPalette = () => {
            if (typeof dialog.showModal === 'function') {
                if (!dialog.open) dialog.showModal();
            } else {
                dialog.setAttribute('open', '');
            }
            syncExpanded();
        };

        const closePalette = () => {
            if (typeof dialog.close === 'function' && dialog.open) dialog.close();
            else dialog.removeAttribute('open');
            syncExpanded();
        };

        const setStatus = (message = '', tone = 'info', handoffUrl = null) => {
            if (!status) return;

            status.replaceChildren();
            if (!message && !handoffUrl) {
                status.hidden = true;
                delete status.dataset.tone;
                return;
            }

            const text = document.createElement('span');
            text.textContent = message || '更新しました。';
            status.appendChild(text);

            if (handoffUrl) {
                const link = document.createElement('a');
                link.href = handoffUrl;
                link.textContent = 'Executionを開く';
                status.appendChild(link);
            }

            status.dataset.tone = tone;
            status.hidden = false;
        };

        const focusComposer = () => {
            const composer = session.querySelector('[data-companion-palette-compose] textarea');
            if (composer instanceof HTMLTextAreaElement) {
                window.requestAnimationFrame(() => composer.focus({ preventScroll: true }));
            }

            const messages = session.querySelector('[data-companion-palette-messages]');
            if (messages instanceof HTMLElement) {
                window.requestAnimationFrame(() => {
                    messages.scrollTop = messages.scrollHeight;
                });
            }
        };

        const responseMessage = (payload, fallback) => {
            const direct = typeof payload?.message === 'string' ? payload.message.trim() : '';
            if (direct) return direct;

            const errors = payload?.errors;
            if (errors && typeof errors === 'object') {
                for (const value of Object.values(errors)) {
                    if (Array.isArray(value) && typeof value[0] === 'string') {
                        return value[0];
                    }
                }
            }

            return fallback;
        };

        const setFormBusy = (form, busy) => {
            form.dataset.companionPaletteBusy = busy ? '1' : '0';
            form.setAttribute('aria-busy', busy ? 'true' : 'false');
            form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach((button) => {
                if ('disabled' in button) button.disabled = busy;
            });
        };

        const submitPaletteForm = async (form) => {
            if (!(form instanceof HTMLFormElement)) return;
            if (form.dataset.companionPaletteBusy === '1') return;

            setFormBusy(form, true);
            setStatus('Companionが整理しています…', 'pending');

            try {
                const headers = {
                    'Accept': 'application/json',
                    'X-Canovia-Companion-Surface': 'palette',
                };
                if (csrfToken) headers['X-CSRF-TOKEN'] = csrfToken;

                const response = await fetch(form.action, {
                    method: (form.getAttribute('method') || 'POST').toUpperCase(),
                    headers,
                    credentials: 'same-origin',
                    body: new FormData(form),
                });

                const payload = await response.json().catch(() => null);

                if (!response.ok) {
                    throw new Error(responseMessage(
                        payload,
                        'Companionとの通信に失敗しました。もう一度試してください。',
                    ));
                }

                if (typeof payload?.html === 'string') {
                    session.innerHTML = payload.html;
                }

                setStatus(
                    responseMessage(payload, ''),
                    'success',
                    typeof payload?.handoff_url === 'string' ? payload.handoff_url : null,
                );
                focusComposer();
            } catch (error) {
                setStatus(
                    error?.message || 'Companionとの通信に失敗しました。もう一度試してください。',
                    'error',
                );
            } finally {
                if (form.isConnected) setFormBusy(form, false);
            }
        };

        trigger.addEventListener('click', openPalette);
        close?.addEventListener('click', closePalette);
        dialog.addEventListener('close', syncExpanded);
        dialog.addEventListener('cancel', () => window.requestAnimationFrame(syncExpanded));

        dialog.addEventListener('submit', (event) => {
            const form = event.target.closest?.('[data-companion-palette-async-form]');
            if (!(form instanceof HTMLFormElement)) return;

            event.preventDefault();
            event.stopPropagation();
            void submitPaletteForm(form);
        });

        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) {
                closePalette();
                return;
            }

            const suggestion = event.target.closest?.('[data-companion-palette-suggest]');
            if (!suggestion) return;

            const textarea = session.querySelector('[data-companion-palette-compose] textarea');
            if (!(textarea instanceof HTMLTextAreaElement)) return;

            textarea.value = suggestion.dataset.companionPaletteSuggest || '';
            textarea.focus();
        });

        shell.dataset.companionPaletteMounted = '1';
    });
}

function initializeInstantCorePage(event) {
    const mountStartedAt = performance.now();

    mountCompanionPalette();
    mountConstellationRoadmap();
    mountDashboardDocuments();
    mountStandalonePlanDashboards();

    const fittedRoadmapOverviews = fitDashboardRoadmapOverviews();
    if (fittedRoadmapOverviews > 0) {
        window.requestAnimationFrame(() => fitDashboardRoadmapOverviews());
    }

    document.querySelectorAll('[data-auto-toast]').forEach((toast) => {
        window.setTimeout(() => {
            if (!toast.isConnected) return;
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(0.5rem)';
            window.setTimeout(() => toast.remove(), 220);
        }, 3600);
    });

    initializeInstantDashboardPage();
    initializeInstantRoadmapPage();
    mountLivingGoalMap({ recordBehaviorRef: recordBehavior });
    scheduleInstantOfflineSnapshotCapture();

    document.dispatchEvent(new CustomEvent('canovia:surface-mounted', {
        detail: {
            instant: event?.detail?.instant === true,
            path: window.location.pathname,
            route_name: document.body?.dataset.routeName || null,
            mount_ms: Math.max(0, performance.now() - mountStartedAt),
        },
    }));
}

document.addEventListener('canovia:page-ready', initializeInstantCorePage);

document.addEventListener('DOMContentLoaded', () => {
    mountWorkspaceModeRuntime();
    mountCanoviaNativeBridge();
    mountCanoviaInteractionPerformance();

    const mountStartedAt = performance.now();
    mountCompanionPalette();
    mountConstellationRoadmap();
    mountDashboardDocuments();
    mountStandalonePlanDashboards();

    if (document.body?.dataset.focusMode !== '1') {
        mountLivingGoalMap({ recordBehaviorRef: recordBehavior });
    }

    document.dispatchEvent(new CustomEvent('canovia:surface-mounted', {
        detail: {
            instant: false,
            path: window.location.pathname,
            route_name: document.body?.dataset.routeName || null,
            mount_ms: Math.max(0, performance.now() - mountStartedAt),
        },
    }));

    if (document.body?.dataset.focusMode === '1') return;
    mountCanoviaInstantNavigation();
});


// V41.23: guidance cards are a natural drill-down surface. Keep explicit
// actions, forms and disclosure controls authoritative; card background opens
// the owning Plan without requiring a separate "details" affordance.
document.addEventListener('click', (event) => {
    const card = event.target.closest?.('[data-plan-card-url]');
    if (!card || event.defaultPrevented) return;
    if (event.target.closest?.('a, button, form, input, select, textarea, label, summary, details')) return;

    const url = card.dataset.planCardUrl;
    if (url) window.location.assign(url);
});
