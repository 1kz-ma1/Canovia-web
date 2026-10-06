@php
    $preview = is_array($developmentPreview ?? null)
        ? $developmentPreview
        : [];
    $previewUrl = data_get($preview, 'url');
@endphp

<div class="space-y-4" data-development-surface-panel="preview">
    <section class="page-card overflow-hidden p-0">
        <div class="flex flex-col gap-4 border-b border-white/8 px-5 py-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="text-[10px] font-black uppercase tracking-[0.16em] text-violet-300">PREVIEW</p>
                <h2 class="mt-1 text-xl font-black text-slate-50">実装したサイトを、開発Contextの隣で確認する。</h2>
                <p class="mt-2 max-w-3xl text-xs leading-5 text-slate-500">
                    自動では読み込みません。明示的に「プレビューを読み込む」を押した時だけiframeへURLを渡します。
                </p>
            </div>

            @if ($previewUrl)
                <a href="{{ $previewUrl }}" target="_blank" rel="noopener noreferrer" class="btn-secondary min-h-10 px-3 text-xs">
                    外部で開く ↗
                </a>
            @endif
        </div>

        @if ($previewUrl)
            <div class="p-4 sm:p-5">
                <div class="flex flex-col gap-3 rounded-2xl border border-white/8 bg-slate-950/25 p-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="min-w-0">
                        <p class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-600">PREVIEW URL</p>
                        <p class="mt-1 truncate text-xs font-bold text-slate-300">{{ $previewUrl }}</p>
                    </div>
                    <button
                        type="button"
                        class="btn-primary min-h-10 px-3 text-xs"
                        data-development-preview-load
                        data-preview-url="{{ $previewUrl }}"
                    >
                        プレビューを読み込む
                    </button>
                </div>

                <div class="mt-4 overflow-hidden rounded-2xl border border-white/8 bg-slate-950/50">
                    <div class="flex min-h-[55vh] items-center justify-center" data-development-preview-shell>
                        <div class="max-w-md p-6 text-center">
                            <p class="text-sm font-black text-slate-300">まだプレビューは読み込んでいません。</p>
                            <p class="mt-2 text-xs leading-5 text-slate-600">
                                X-Frame-Options / CSPで埋め込みが拒否されるサイトは、上の「外部で開く」を使ってください。
                            </p>
                        </div>
                    </div>
                    <iframe
                        title="Development Preview"
                        class="hidden h-[65vh] w-full border-0 bg-white"
                        sandbox="allow-scripts allow-forms allow-popups allow-modals"
                        referrerpolicy="strict-origin-when-cross-origin"
                        loading="lazy"
                        data-development-preview-frame
                    ></iframe>
                </div>
            </div>
        @else
            <div class="p-5 sm:p-6">
                <div class="empty-state">
                    まだプレビューURLが設定されていません。Renderなどの公開URLを設定すると、このタブから確認できます。
                </div>
            </div>
        @endif
    </section>

    @if ($canEdit)
        <details class="page-card p-4 sm:p-5" {{ $previewUrl ? '' : 'open' }}>
            <summary class="cursor-pointer list-none">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-[0.16em] text-slate-500">PREVIEW SETTINGS</p>
                        <h3 class="mt-1 text-sm font-black text-slate-100">{{ $previewUrl ? 'URLを変更' : 'URLを設定' }}</h3>
                    </div>
                    <span class="text-[10px] text-slate-600">http / httpsのみ</span>
                </div>
            </summary>

            <form method="POST" action="{{ route('plans.development_preview.store', $plan) }}" class="mt-4 flex flex-col gap-3 sm:flex-row">
                @csrf
                <input
                    type="url"
                    name="url"
                    required
                    maxlength="2048"
                    value="{{ old('url', $previewUrl) }}"
                    placeholder="https://example.onrender.com"
                    class="form-control min-w-0 flex-1"
                >
                <button type="submit" class="btn-primary min-h-11 px-4">保存</button>
            </form>

            @error('url')
                <p class="mt-2 text-xs font-bold text-rose-300">{{ $message }}</p>
            @enderror

            @if ($previewUrl)
                <form method="POST" action="{{ route('plans.development_preview.destroy', $plan) }}" class="mt-3 text-right" onsubmit="return confirm('プレビューURLを解除しますか？');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="text-xs font-bold text-slate-600 hover:text-rose-300">プレビューURLを解除</button>
                </form>
            @endif
        </details>
    @endif
</div>

<script>
    (() => {
        const button = document.querySelector('[data-development-preview-load]');
        const frame = document.querySelector('[data-development-preview-frame]');
        const shell = document.querySelector('[data-development-preview-shell]');

        if (!button || !frame) return;

        button.addEventListener('click', () => {
            const url = String(button.dataset.previewUrl || '').trim();
            if (!/^https?:\/\//i.test(url)) return;

            frame.src = url;
            frame.classList.remove('hidden');
            shell?.classList.add('hidden');
            button.textContent = '再読み込み';
        });
    })();
</script>
