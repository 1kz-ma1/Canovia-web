/**
 * Progressive enhancement for A/B Learning Run answers only.
 * The server session is authoritative; this module never puts learner
 * answers in origin-wide localStorage or exposes grading rules.
 */
export function mountLearningAnswerAutosave(root = document, options = {}) {
    const form = root.querySelector('[data-learning-answer-form][data-learning-draft-url]');
    if (!form || form.dataset.learningDraftMounted === '1') return;

    const status = form.querySelector('[data-learning-draft-status]');
    const fetcher = options.fetcher || ((...args) => fetch(...args));
    const makeBody = options.makeBody || ((element) => new FormData(element));
    const delay = options.delay || ((callback, ms) => setTimeout(callback, ms));
    const cancel = options.cancel || ((id) => clearTimeout(id));
    const sendBeacon = options.sendBeacon || ((url, body) => navigator.sendBeacon?.(url, body));
    const debounceMs = options.debounceMs ?? 500;

    let timer = null;
    let saving = false;
    let pending = false;
    let stopped = false;
    let changed = false;
    form.dataset.learningDraftMounted = '1';

    const announce = (message) => {
        if (status) status.textContent = message;
    };

    async function save() {
        timer = null;
        if (!changed || stopped || !form.isConnected) return;
        if (saving) {
            pending = true;
            return;
        }
        saving = true;
        pending = false;
        changed = false;
        announce('下書きを保存中…');
        try {
            const result = await fetcher(form.dataset.learningDraftUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                body: makeBody(form),
            });
            if (!result.ok) throw new Error('draft-save-failed');
            if (!changed) announce('下書きを保存しました');
        } catch (_) {
            // Never claim success on network/session/CSRF/permission failure.
            changed = true;
            announce('下書きを保存できませんでした。接続を確認してください。');
        } finally {
            saving = false;
            if ((pending || changed) && !stopped && form.isConnected) schedule();
        }
    }

    function schedule() {
        if (stopped) return;
        changed = true;
        if (timer !== null) cancel(timer);
        timer = delay(save, debounceMs);
    }

    function flush() {
        if (stopped || !changed || !form.isConnected) return;
        if (timer !== null) cancel(timer);
        timer = null;
        try {
            // Standard FormData includes @csrf and the current Run Item ID.
            // Delivery is best effort; the visible saved status remains honest.
            sendBeacon(form.dataset.learningDraftUrl, makeBody(form));
        } catch (_) { /* no claim that a draft was saved */ }
    }

    form.addEventListener('input', schedule);
    form.addEventListener('change', schedule);
    form.addEventListener('submit', () => {
        stopped = true;
        if (timer !== null) cancel(timer);
    });
    // The shared shell can replace pages without a normal browser unload.
    document.addEventListener('canovia:before-page-replace', flush, { once: true });
    window.addEventListener('pagehide', flush, { once: true });
}

document.addEventListener('DOMContentLoaded', () => mountLearningAnswerAutosave());
document.addEventListener('canovia:page-ready', () => mountLearningAnswerAutosave());
