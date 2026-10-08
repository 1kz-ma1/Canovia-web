<section class="page-card p-4 sm:p-5" data-development-private-context-preview
    data-private-context-endpoint="{{ route('workspace.development.private_context.preview', ['plan' => $plan->id]) }}">
    <div>
        <p class="text-[10px] font-black uppercase tracking-[.14em] text-cyan-300">PRIVATE CONTEXT · PREVIEW ONLY</p>
        <h2 class="mt-1 text-sm font-black text-slate-100">AIへの共有内容を事前確認</h2>
        <p class="mt-2 text-xs leading-5 text-slate-400">
            個人の開発Planから、渡してもよい情報だけを確認できます。
            ChatGPTとの接続や外部送信は行いません。実際に渡す場合は内容を確認してからコピーしてください。
        </p>
    </div>

    <div class="mt-3 flex min-w-0 flex-wrap items-end gap-2">
        <label class="min-w-0 flex-1 text-xs text-slate-300" style="min-width: 10rem;">
            共有範囲
            <select class="form-control mt-1 block w-full min-w-0 max-w-full" data-private-context-scope>
                <option value="overview">Planの概要のみ（Taskなし）</option>
                <option value="tasks">進行中Taskを最大5件含む</option>
            </select>
        </label>
        <button type="button" class="btn-secondary min-h-11 px-4 text-xs" data-private-context-fetch>
            AI共有内容を確認
        </button>
    </div>
    <p class="mt-2 text-xs text-cyan-300" role="status" aria-live="polite" data-private-context-status></p>
    <div class="mt-3 hidden" data-private-context-result>
        <p class="text-xs leading-5 text-amber-200">
            この内容はCanoviaの個人データです。AIに渡す前に、Task名などに秘密情報が含まれていないか確認してください。
        </p>
        <textarea readonly rows="9"
            class="form-control mt-2 block w-full min-w-0 max-w-full resize-y text-xs leading-5"
            data-private-context-value></textarea>
        <button type="button" class="btn-secondary mt-2 min-h-11 px-4 text-xs" data-private-context-copy>
            表示内容をコピー
        </button>
        <p class="mt-2 text-[11px] leading-5 text-slate-500">
            取得結果は画面上でのみ保持されます。Taskの説明・Evidence・作業ログ・認証情報は含みません。
        </p>
    </div>
</section>

<script>
(() => {
    const root = document.querySelector('[data-development-private-context-preview]');
    if (!root) return;

    const endpoint = root.dataset.privateContextEndpoint;
    const scope = root.querySelector('[data-private-context-scope]');
    const fetchButton = root.querySelector('[data-private-context-fetch]');
    const status = root.querySelector('[data-private-context-status]');
    const result = root.querySelector('[data-private-context-result]');
    const value = root.querySelector('[data-private-context-value]');
    const copyButton = root.querySelector('[data-private-context-copy]');
    if (!endpoint || !scope || !fetchButton || !status || !result || !value || !copyButton) return;

    const reset = () => {
        value.value = '';
        result.classList.add('hidden');
        status.textContent = '';
    };
    scope.addEventListener('change', reset);

    fetchButton.addEventListener('click', async () => {
        reset();
        fetchButton.disabled = true;
        status.textContent = '本人専用のプレビューを取得しています…';

        try {
            const url = new URL(endpoint, window.location.origin);
            url.searchParams.set('scope', scope.value);
            url.searchParams.set('limit', '5');
            const response = await fetch(url.toString(), {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                cache: 'no-store',
            });
            if (!response.ok || !(response.headers.get('content-type') || '').includes('application/json')) {
                throw new Error('preview_unavailable');
            }

            const data = await response.json();
            if (data.schema !== 'canovia.development_private_context_preview.v1'
                || data.delivery !== 'owner_preview_only'
                || !Array.isArray(data.tasks)
                || !data.plan || typeof data.plan.title !== 'string') {
                throw new Error('invalid_preview');
            }
            value.value = JSON.stringify(data, null, 2);
            result.classList.remove('hidden');
            status.textContent = '共有候補を表示しました。内容を確認してからコピーできます。外部送信はしていません。';
        } catch (_) {
            status.textContent = '共有候補を取得できませんでした。ログイン状態や閲覧権限を確認してください。';
        } finally {
            fetchButton.disabled = false;
        }
    });

    copyButton.addEventListener('click', async () => {
        if (result.classList.contains('hidden') || !value.value) return;
        let copied = false;
        if (navigator.clipboard && window.isSecureContext) {
            try {
                await navigator.clipboard.writeText(value.value);
                copied = true;
            } catch (_) { /* WKWebView may disallow clipboard writes. */ }
        }
        if (!copied) {
            value.focus();
            value.select();
            try { copied = document.execCommand('copy'); } catch (_) { /* User can manually copy selected text. */ }
        }
        status.textContent = copied
            ? '表示内容をコピーしました。外部AIには自動送信されていません。'
            : '自動コピーできませんでした。選択された内容を長押ししてコピーしてください。';
    });
})();
</script>
