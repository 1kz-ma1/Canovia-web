// Preparation-only UI: no telemetry, providers, browser storage or automatic Plan writes.
import './future-carousel.mjs';
import '../css/preparation.css';

for (const form of document.querySelectorAll('[data-interest-form]')) {
    const pages = [...form.querySelectorAll('[data-interest-page]')];
    const step = form.elements.step;
    const progress = form.querySelector('[data-interest-progress]');
    const previous = form.querySelector('[data-interest-prev]');
    const next = form.querySelector('[data-interest-next]');
    const status = form.querySelector('[data-interest-save-status]');
    const answered = form.querySelector('[data-interest-answered]');
    const meter = form.querySelector('[data-interest-meter]');
    meter.hidden = false;
    function updateAnswered() {
        const count = form.querySelectorAll('[data-interest-question] input:checked').length;
        // Unknown is a completed choice here, never a numeric score.
        answered.textContent = `選択済み ${count} / 30問（わからないを含む）`;
        meter.value = count;
    }
    form.addEventListener('change', updateAnswered);
    updateAnswered();
    let current = Math.max(1, Math.min(6, Number(step.value) || 1));
    let pending = false;
    let conflict = false;
    function showPage(focus = false) {
        for (const [i, page] of pages.entries()) page.hidden = i + 1 !== current;
        step.value = String(current);
        progress.textContent = `${current} / 6 ページ（全30問・回答は任意）`;
        previous.disabled = current === 1;
        next.disabled = current === 6;
        if (focus) {
            const legend = pages[current - 1].querySelector('legend');
            legend.tabIndex = -1;
            legend.focus();
        }
    }
    previous.addEventListener('click', () => { current--; showPage(true); });
    next.addEventListener('click', () => { current++; showPage(true); });
    form.querySelector('[data-interest-paging]').hidden = false;
    showPage();
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (pending || conflict) return;
        const data = new FormData(form);
        data.set('action', event.submitter?.value === 'result' ? 'result' : 'save');
        pending = true;
        const buttons = [...form.querySelectorAll('button[type="submit"]')];
        for (const button of buttons) button.disabled = true;
        status.textContent = '保存しています…';
        try {
            const response = await fetch(form.getAttribute('action'), { method: 'POST', body: data,
                credentials: 'same-origin', headers: { Accept: 'application/json' } });
            const body = await response.json();
            if (!response.ok) {
                conflict = response.status === 422 && Boolean(body.errors?.revision);
                if (conflict) form.querySelector('[data-interest-latest]').hidden = false;
                status.textContent = conflict ? '別の画面で更新されています。この入力は保存していません。最新の記録を確認してください。'
                    : [401, 419].includes(response.status) ? 'ログイン状態を確認できません。この入力は保持しています。別の画面でログインを確認してください。'
                    : '保存できませんでした。保存への同意と回答を確認してください。';
                return;
            }
            if (body.saved !== true || !/^[a-f0-9]{64}$/.test(body.revision)) throw new Error('INVALID_RESPONSE');
            form.elements.revision.value = body.revision;
            status.textContent = '保存しました。後日このアカウントで再開できます。';
            if (data.get('action') === 'result') {
                const destination = new URL(body.redirect, location.origin);
                if (destination.origin !== location.origin || destination.pathname !== '/preparation/interests/result') throw new Error('INVALID_RESPONSE');
                location.assign(destination.href);
            }
        } catch {
            status.textContent = '保存を確認できません。入力はこの画面に残っています。同じ内容なら再送できます。画面を閉じる前に保存結果を確認してください。';
        } finally {
            pending = false;
            for (const button of buttons) button.disabled = conflict;
        }
    });
}

// Static suggestions only fill an empty new form after an explicit click.
// They never submit, fetch, infer from scores, or replace saved/user-entered text.
for (const form of document.querySelectorAll('[data-preparation-plan-form]')) {
    const suggestions = form.querySelector('[data-preparation-templates]');
    if (!suggestions) continue;
    const fields = ['title', 'description', 'category'].map(name => form.elements[name]);
    const status = suggestions.querySelector('[role="status"]');
    const buttons = [...suggestions.querySelectorAll('[data-plan-example]')];
    function update() {
        for (const button of buttons) button.disabled = fields.some(field => field.value.trim() !== '');
    }
    form.addEventListener('input', update);
    for (const button of buttons) button.addEventListener('click', () => {
        if (fields.some(field => field.value.trim() !== '')) return;
        const example = button.dataset;
        for (const field of fields) field.value = example[field.name];
        status.textContent = '例文を入力しました。自分の言葉に編集し、確認してから保存してください。まだ保存していません。';
        update();
        fields[0].focus();
    });
    suggestions.hidden = false;
    update();
}
