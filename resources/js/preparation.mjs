// Preparation-only UI: no telemetry, providers, browser storage or automatic Plan writes.
import './future-carousel.mjs';

for (const form of document.querySelectorAll('[data-interest-form]')) {
    const pages = [...form.querySelectorAll('[data-interest-page]')];
    const step = form.elements.step;
    const progress = form.querySelector('[data-interest-progress]');
    const previous = form.querySelector('[data-interest-prev]');
    const next = form.querySelector('[data-interest-next]');
    const status = form.querySelector('[data-interest-save-status]');
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
