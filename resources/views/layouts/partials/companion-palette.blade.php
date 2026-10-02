@php
    $companionContextLabel = $companionEntryTask?->title
        ?? $companionEntryPlan?->title
        ?? 'Canovia全体';
    $companionContextKind = $companionEntryTask
        ? 'Task'
        : ($companionEntryPlan ? 'Plan' : 'Global');
@endphp

<div class="canovia-companion-shell" data-companion-shell>
    <button
        type="button"
        class="canovia-companion-orb"
        data-companion-palette-open
        aria-haspopup="dialog"
        aria-expanded="false"
        aria-label="Canovia Companionを開く"
        title="Companion"
    >
        <span class="canovia-companion-orb-mark" aria-hidden="true">✦</span>
        <span class="canovia-companion-orb-label">AI</span>
    </button>

    <dialog
        class="canovia-companion-palette"
        data-companion-palette
        aria-labelledby="canovia-companion-palette-title"
    >
        <div class="canovia-companion-palette-card">
            <header class="canovia-companion-palette-header">
                <div>
                    <p class="canovia-companion-palette-kicker">CANOVIA COMPANION</p>
                    <h2 id="canovia-companion-palette-title">この画面から、そのまま相談</h2>
                    <p>今見ているCanoviaの文脈を引き継いで会話を始めます。</p>
                </div>
                <button
                    type="button"
                    class="canovia-companion-palette-close"
                    data-companion-palette-close
                    aria-label="Companionを閉じる"
                >×</button>
            </header>

            <section class="canovia-companion-context-card" aria-label="現在のCompanion Context">
                <div>
                    <span>{{ $companionContextKind }}</span>
                    <strong>{{ $companionContextLabel }}</strong>
                </div>
                <small>{{ $companionSourceRoute ?: 'Canovia' }}</small>
            </section>

            <section class="canovia-companion-palette-body">
                <div class="canovia-companion-palette-status" data-companion-palette-status hidden></div>
                <div data-companion-palette-session>
                <div class="canovia-companion-palette-prompt">
                    <span aria-hidden="true">✦</span>
                    <div>
                        <strong>何でも説明し直さなくて大丈夫です。</strong>
                        <p>PlanやTaskを見ている場合は、その対象をCompanionへ渡します。変更が必要な場合も、既存どおり候補を確認してから反映します。</p>
                    </div>
                </div>

                <div class="canovia-companion-palette-actions">
                    <form
                        method="POST"
                        action="{{ route('companion.entry') }}"
                        data-mutation-once
                        data-companion-palette-async-form
                    >
                        @csrf
                        <input type="hidden" name="entry_type" value="{{ $companionEntryType }}">
                        @if ($companionEntryPlan)
                            <input type="hidden" name="plan_id" value="{{ $companionEntryPlan->id }}">
                        @endif
                        @if ($companionEntryTask)
                            <input type="hidden" name="task_id" value="{{ $companionEntryTask->id }}">
                        @endif
                        <input type="hidden" name="source_path" value="{{ $companionSourcePath }}">
                        <input type="hidden" name="source_route" value="{{ $companionSourceRoute }}">
                        <button type="submit" class="canovia-companion-palette-primary">
                            この文脈で相談を始める
                        </button>
                    </form>

                    <a href="{{ route('companion.index') }}" class="canovia-companion-palette-secondary">
                        最近の会話を見る
                    </a>
                </div>
                </div>
            </section>
        </div>
    </dialog>
</div>
