@extends('layouts.app')

@section('title', '計画作成 | Canovia')

@section('content')
    <div class="mx-auto max-w-5xl space-y-5 pb-28 md:pb-0">
        <header class="relative overflow-hidden rounded-[1.65rem] border border-cyan-300/15 bg-[radial-gradient(circle_at_86%_16%,rgba(34,211,238,.12),transparent_28%),radial-gradient(circle_at_10%_100%,rgba(99,102,241,.14),transparent_34%),rgba(6,13,31,.78)] p-5 shadow-[0_24px_70px_rgba(2,6,23,.28)] sm:p-7">
            <div class="relative z-10 max-w-2xl">
                <p class="text-[10px] font-black uppercase tracking-[.18em] text-cyan-300">CREATE A PLAN</p>
                <h1 class="mt-2 text-2xl font-black tracking-tight text-slate-50 sm:text-3xl">まず、目標の名前だけ決めよう。</h1>
                <p class="mt-3 max-w-xl text-sm leading-7 text-slate-400">
                    最初からロードマップを完成させる必要はありません。まず目標を登録し、今できる行動や実績から方針を整えていきます。
                </p>
            </div>

            <div class="relative z-10 mt-5 grid max-w-2xl grid-cols-2 gap-2" aria-label="計画作成の流れ">
                <div class="rounded-2xl border border-cyan-300/30 bg-cyan-400/10 p-3">
                    <div class="flex items-center gap-2">
                        <span class="grid h-6 w-6 place-items-center rounded-full bg-cyan-300 text-[11px] font-black text-slate-950">1</span>
                        <strong class="text-xs text-cyan-100">基本情報</strong>
                    </div>
                    <p class="mt-2 text-[11px] leading-5 text-slate-400">目標名と、決まっていれば期限を入力</p>
                </div>
                <div class="rounded-2xl border border-slate-700/80 bg-slate-950/35 p-3">
                    <div class="flex items-center gap-2">
                        <span class="grid h-6 w-6 place-items-center rounded-full border border-slate-600 text-[11px] font-black text-slate-400">2</span>
                        <strong class="text-xs text-slate-300">
                            {{ ($workspaceModeContext ?? null) ? ($workspaceModeContext['label'].' Workspace') : '行動から始める' }}
                        </strong>
                    </div>
                    <p class="mt-2 text-[11px] leading-5 text-slate-500">
                        @if ($workspaceModeContext ?? null)
                            Plan作成後、その目的専用のセットアップへ戻ります
                        @else
                            まず今できる行動を確認し、実績に応じて計画を見直す
                        @endif
                    </p>
                </div>
            </div>

            <div class="pointer-events-none absolute -bottom-8 -right-4 hidden w-40 opacity-75 sm:block" aria-hidden="true">
                <img src="/brand/mascot-guide.webp" alt="" class="w-full drop-shadow-[0_18px_30px_rgba(0,0,0,.45)]">
            </div>
        </header>

        @if ($workspaceModeContext ?? null)
            <section
                class="rounded-2xl border {{ $workspaceModeContext['key'] === 'study' ? 'border-amber-300/20 bg-amber-300/[0.025]' : ($workspaceModeContext['key'] === 'career' ? 'border-emerald-300/20 bg-emerald-300/[0.025]' : 'border-cyan-300/20 bg-cyan-300/[0.025]') }} p-4"
                data-plan-create-workspace-mode="{{ $workspaceModeContext['key'] }}"
            >
                <p class="text-[10px] font-black uppercase tracking-[0.16em] {{ $workspaceModeContext['key'] === 'study' ? 'text-amber-300' : ($workspaceModeContext['key'] === 'career' ? 'text-emerald-300' : 'text-cyan-300') }}">
                    {{ strtoupper($workspaceModeContext['key']) }} WORKSPACE
                </p>
                <p class="mt-2 text-sm font-black text-slate-100">{{ $workspaceModeContext['label'] }} WorkspaceからPlanを作成中</p>
                <p class="mt-1 text-xs leading-5 text-slate-500">{{ $workspaceModeContext['description'] }}</p>
                <p class="mt-2 text-[11px] text-slate-600">
                    {{ $workspaceModeContext['suggested_plan_category'] }} を内部の初期分類として設定しています。分類は詳細設定から変更できます。
                </p>
            </section>
        @endif

        @if ($errors->any())
            <div class="assistant-notice assistant-notice-error">
                <p class="font-bold">入力内容を確認してください。</p>
                <ul class="mt-2 list-disc space-y-1 pl-5 text-sm">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form
            action="{{ route('plans.store') }}"
            method="POST"
            class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_19rem] lg:items-start"
            data-onboarding-target="plan-form"
            data-mutation-once
        >
            @csrf
            @if ($workspaceModeContext ?? null)
                <input type="hidden" name="workspace_mode" value="{{ $workspaceModeContext['key'] }}">
            @endif
            <input type="hidden" name="create_request_id" value="{{ old('create_request_id', (string) \Illuminate\Support\Str::uuid()) }}">
            @if (old('personalization_seed_key', data_get($prefill ?? [], 'personalization_seed_key')))
                <input type="hidden" name="personalization_seed_key" value="{{ old('personalization_seed_key', data_get($prefill ?? [], 'personalization_seed_key')) }}">
                <input type="hidden" name="personalization_seed_domain" value="{{ old('personalization_seed_domain', data_get($prefill ?? [], 'personalization_seed_domain')) }}">
            @endif

            <section class="page-card space-y-5 p-5 sm:p-6">
                <div class="rounded-2xl border border-cyan-300/20 bg-cyan-400/[.045] p-4 sm:p-5">
                    <div class="flex items-center gap-2">
                        <span class="rounded-full border border-cyan-300/25 bg-cyan-300/10 px-2 py-1 text-[10px] font-black tracking-[.12em] text-cyan-300">まずこれだけ</span>
                        <span class="text-[11px] text-slate-500">必須</span>
                    </div>

                    <label for="title" class="mt-4 block text-sm font-black text-slate-100">何を達成したい？</label>
                    <input
                        id="title"
                        type="text"
                        name="title"
                        value="{{ old('title', data_get($prefill ?? [], 'title')) }}"
                        placeholder="例：応用情報技術者試験 合格"
                        required
                        autofocus
                        class="form-control mt-2 min-h-12 text-base font-semibold sm:text-lg"
                    >
                    <p class="mt-2 text-xs leading-5 text-slate-500">
                        完璧な名前でなくて大丈夫です。作成後に変更できます。
                    </p>
                </div>

                <div class="rounded-2xl border border-sky-400/15 bg-sky-500/5 p-4 sm:p-5">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <label for="deadline" class="text-sm font-bold text-slate-200">
                                期限 <span class="font-normal text-slate-500">（任意）</span>
                            </label>
                            <p class="mt-1 text-xs leading-5 text-slate-500">
                                決まっていなければ空欄のままでOK。あとからいつでも設定できます。
                            </p>
                        </div>
                        <span class="shrink-0 rounded-full border border-slate-700 px-2 py-1 text-[10px] font-bold text-slate-400">あとで設定可</span>
                    </div>
                    <input
                        id="deadline"
                        type="date"
                        name="deadline"
                        value="{{ old('deadline', data_get($prefill ?? [], 'deadline')) }}"
                        class="form-control mt-3 min-h-11"
                    >
                </div>

                <details
                    class="group rounded-2xl border border-slate-800 bg-slate-950/30 p-4"
                    @if(old('description') || old('category') || old('start_date') || old('is_public') || old('visual_icon') || old('accent_key') || old('roadmap_world') || old('is_collaborative') || data_get($prefill ?? [], 'is_collaborative') || !empty($prefill)) open @endif
                >
                    <summary class="cursor-pointer list-none">
                        <span class="flex items-center justify-between gap-3">
                            <span>
                                <span class="block text-sm font-bold text-slate-200">詳細設定</span>
                                <span class="mt-1 block text-xs font-normal text-slate-500">説明・活動分類・公開設定など。必要なときだけ変更できます。</span>
                            </span>
                            <span class="grid h-8 w-8 shrink-0 place-items-center rounded-full border border-slate-700 text-slate-400 transition group-open:rotate-45" aria-hidden="true">＋</span>
                        </span>
                    </summary>

                    <div class="mt-5 space-y-5 border-t border-slate-800/80 pt-5">
                        <div>
                            <label for="description" class="form-label">説明</label>
                            <textarea id="description" name="description" rows="3" placeholder="目的や完成条件など" class="form-control mt-2">{{ old('description', data_get($prefill ?? [], 'description')) }}</textarea>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label for="category" class="form-label">活動分類（任意）</label>
                                <select id="category" name="category" class="form-control mt-2">
                                    <option value="">未設定</option>
                                    <option value="資格学習" @selected(old('category', data_get($prefill ?? [], 'category')) === '資格学習')>資格学習</option>
                                    <option value="個人開発" @selected(old('category', data_get($prefill ?? [], 'category')) === '個人開発')>個人開発</option>
                                    <option value="制作活動" @selected(old('category', data_get($prefill ?? [], 'category')) === '制作活動')>制作活動</option>
                                    <option value="就活・キャリア" @selected(old('category', data_get($prefill ?? [], 'category')) === '就活・キャリア')>就活・キャリア</option>
                                    <option value="ゲーム開発" @selected(old('category', data_get($prefill ?? [], 'category')) === 'ゲーム開発')>ゲーム開発</option>
                                    <option value="その他" @selected(old('category', data_get($prefill ?? [], 'category')) === 'その他')>その他</option>
                                </select>
                                <p class="mt-1 text-[11px] leading-4 text-slate-500">Canovia内部の表示先を補助する設定です。個人・チームの違いとは別で、あとから訂正できます。</p>
                            </div>

                            <div>
                                <label for="priority_mode" class="form-label">優先度の決め方</label>
                                <select id="priority_mode" name="priority_mode" class="form-control mt-2">
                                    <option value="auto" @selected(old('priority_mode', 'auto') === 'auto')>Canoviaに自動で任せる</option>
                                    <option value="manual" @selected(old('priority_mode') === 'manual')>手動で固定する</option>
                                </select>
                                <p class="mt-1 text-[11px] leading-4 text-slate-500">自動では期限・進捗差・残作業量・利用可能時間などから判定します。</p>
                            </div>

                            <div>
                                <label for="priority" class="form-label">手動優先度</label>
                                <select id="priority" name="priority" class="form-control mt-2">
                                    @for ($priority = 1; $priority <= 5; $priority++)
                                        <option value="{{ $priority }}" @selected((int) old('priority', 3) === $priority)>
                                            {{ $priority }}{{ $priority === 1 ? '（最優先）' : ($priority === 5 ? '（低）' : '') }}
                                        </option>
                                    @endfor
                                </select>
                                <p class="mt-1 text-[11px] leading-4 text-slate-500">「手動で固定する」を選んだ場合だけHomeの並び順に使います。</p>
                            </div>

                            <div>
                                <label for="start_date" class="form-label">開始日</label>
                                <input id="start_date" type="date" name="start_date" value="{{ old('start_date', now()->toDateString()) }}" class="form-control mt-2">
                            </div>
                        </div>

                        <details class="rounded-xl border border-slate-800 bg-slate-950/35 p-3">
                            <summary class="cursor-pointer text-sm font-semibold text-slate-300">見た目を変更する</summary>
                            <div class="mt-4">
                                @include('plans.partials.visual-picker')
                            </div>
                        </details>

                        <label class="flex items-start gap-3 rounded-xl border border-slate-800 bg-slate-950/35 p-4">
                            <input type="checkbox" name="is_public" value="1" @checked(old('is_public')) class="mt-1">
                            <span>
                                <span class="block font-medium text-slate-200">この計画を公開する</span>
                                <span class="mt-1 block text-xs leading-5 text-slate-500">共有URLを知っている人が閲覧できます。編集はできません。</span>
                            </span>
                        </label>

                        @auth
                            <label class="flex items-start gap-3 rounded-xl border border-violet-400/20 bg-violet-500/5 p-4">
                                <input type="checkbox" name="is_collaborative" value="1" @checked(old('is_collaborative', data_get($prefill ?? [], 'is_collaborative', false))) class="mt-1">
                                <span>
                                    <span class="block font-medium text-slate-200">共同計画として作る</span>
                                    <span class="mt-1 block text-xs leading-5 text-slate-500">参加者はURLまたは参加コードで参加できます。最初は閲覧のみで、編集権限はあとからあなたが付与します。</span>
                                </span>
                            </label>
                        @else
                            <div class="rounded-xl border border-slate-800 bg-slate-950/35 p-4 text-sm text-slate-400">
                                <strong class="text-slate-200">共同計画はログイン後に使えます。</strong>
                                <p class="mt-1 text-xs leading-5">計画を作ったあとでアカウントへ紐づけてから有効化できます。</p>
                            </div>
                        @endauth
                    </div>
                </details>
            </section>

            <aside class="space-y-4 lg:sticky lg:top-24">
                <section class="rounded-2xl border border-slate-800 bg-slate-950/35 p-4">
                    <p class="text-xs font-black text-slate-200">このあと</p>
                    <div class="mt-3 space-y-3">
                        <div class="flex gap-3">
                            <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full border border-cyan-300/30 bg-cyan-300/10 text-[10px] font-black text-cyan-300">1</span>
                            <p class="text-xs leading-5 text-slate-400">計画の基本情報を保存</p>
                        </div>

                        @if (($workspaceModeContext['key'] ?? null) === 'study')
                            <div class="flex gap-3">
                                <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full border border-slate-700 text-[10px] font-black text-slate-400">2</span>
                                <p class="text-xs leading-5 text-slate-400">Study Workspaceが学習タイプと現在Stateを判定</p>
                            </div>
                            <div class="flex gap-3">
                                <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full border border-slate-700 text-[10px] font-black text-slate-400">3</span>
                                <p class="text-xs leading-5 text-slate-400">今必要な診断・演習・範囲整理・Recallから開始</p>
                            </div>
                        @elseif (($workspaceModeContext['key'] ?? null) === 'development')
                            <div class="flex gap-3">
                                <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full border border-slate-700 text-[10px] font-black text-slate-400">2</span>
                                <p class="text-xs leading-5 text-slate-400">Developer Homeが現在Stateから次のActionを提示</p>
                            </div>
                            <div class="flex gap-3">
                                <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full border border-slate-700 text-[10px] font-black text-slate-400">3</span>
                                <p class="text-xs leading-5 text-slate-400">GitHub・実装・Review・Releaseの必要な入口から開始</p>
                            </div>
                        @elseif (($workspaceModeContext['key'] ?? null) === 'career')
                            <div class="flex gap-3">
                                <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full border border-slate-700 text-[10px] font-black text-slate-400">2</span>
                                <p class="text-xs leading-5 text-slate-400">Career Workspaceへ戻って求人・応募・選考の事実を1件追加</p>
                            </div>
                            <div class="flex gap-3">
                                <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full border border-slate-700 text-[10px] font-black text-slate-400">3</span>
                                <p class="text-xs leading-5 text-slate-400">Process ReadinessとCurrent Actionを確認</p>
                            </div>
                        @else
                            <div class="flex gap-3">
                                <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full border border-slate-700 text-[10px] font-black text-slate-400">2</span>
                                <p class="text-xs leading-5 text-slate-400">目的に合うWorkspaceやPlanから今の行動を確認</p>
                            </div>
                            <div class="flex gap-3">
                                <span class="grid h-6 w-6 shrink-0 place-items-center rounded-full border border-slate-700 text-[10px] font-black text-slate-400">3</span>
                                <p class="text-xs leading-5 text-slate-400">実績が増えたら計画を見直す。手動編集やAIの初期タスク生成も任意で使えます</p>
                            </div>
                        @endif
                    </div>
                </section>

                <div class="rounded-2xl border border-cyan-300/20 bg-slate-950/70 p-3 shadow-[0_16px_42px_rgba(2,6,23,.3)]">
                    <button
                        type="submit"
                        class="btn-primary w-full justify-center py-3 text-sm sm:text-base"
                        data-onboarding-target="create-plan-submit"
                        data-processing-label="計画を作成しています…"
                    >
                        @if ($workspaceModeContext ?? null)
                            Planを作って{{ $workspaceModeContext['label'] }} Workspaceへ
                        @else
                            目標を登録して今できることへ
                        @endif
                    </button>
                    <a
                        href="{{ ($workspaceModeContext ?? null) ? $workspaceModeContext['return_url'] : route('home') }}"
                        class="mt-2 flex min-h-10 items-center justify-center text-xs font-bold text-slate-500 hover:text-slate-300"
                    >キャンセル</a>
                </div>
            </aside>
        </form>
    </div>
@endsection
