@extends('layouts.app')

@section('title', '計画編集 | Canovia')

@section('content')
    <section class="mb-8">
        <h1 class="text-3xl font-bold tracking-tight text-slate-900">
            計画を編集
        </h1>

        <p class="mt-3 max-w-3xl leading-7 text-slate-600">
            変えたいところだけ直せます。今の内容はそのまま入っています。
        </p>
    </section>

    <section class="grid gap-6 lg:grid-cols-[1fr_360px]">
        <div class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
            @if ($errors->any())
                <div class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                    <h2 class="mb-2 font-bold">入力内容を確認してください</h2>

                    <ul class="list-inside list-disc">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form action="{{ route('plans.update', $plan) }}" method="POST" class="space-y-6">
                @csrf
                @method('PUT')

                <div>
                    <label for="title" class="mb-2 block text-sm font-medium text-slate-700">
                        計画タイトル
                    </label>

                    <input
                        id="title"
                        type="text"
                        name="title"
                        value="{{ old('title', $plan->title) }}"
                        required
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 outline-none focus:border-slate-900 focus:ring-2 focus:ring-slate-200"
                    >
                </div>

                <div>
                    <label for="description" class="mb-2 block text-sm font-medium text-slate-700">
                        説明
                    </label>

                    <textarea
                        id="description"
                        name="description"
                        rows="5"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 outline-none focus:border-slate-900 focus:ring-2 focus:ring-slate-200"
                    >{{ old('description', $plan->description) }}</textarea>
                </div>

                <section class="rounded-2xl border border-cyan-300/20 bg-slate-950/80 p-4" data-plan-domain-override>
                    <label for="workspace_domain_override" class="block text-sm font-bold text-slate-100">どのWorkspaceで扱いますか？</label>
                    <p class="mt-2 text-xs leading-5 text-slate-400">
                        通常はCanoviaに任せて大丈夫です。以前「制作活動」で作ったチーム開発などは、
                        ここで「開発」を選ぶと次回からDevelopmentに表示できます。
                        カテゴリ・参加メンバー・共有権限は変わりません。
                    </p>
                    <select id="workspace_domain_override" name="workspace_domain_override"
                        class="form-control mt-3 min-h-11 w-full text-sm">
                        <option value="auto" @selected(old('workspace_domain_override', $plan->workspace_domain_override ?? 'auto') === 'auto')>Canoviaに任せる（現在：{{ app(\App\Services\PlanCategoryProfileService::class)->forCategory((string) $plan->category)->label }}）</option>
                        <option value="study" @selected(old('workspace_domain_override', $plan->workspace_domain_override) === 'study')>学習</option>
                        <option value="development" @selected(old('workspace_domain_override', $plan->workspace_domain_override) === 'development')>開発（個人・チーム共通）</option>
                        <option value="career" @selected(old('workspace_domain_override', $plan->workspace_domain_override) === 'career')>就活・キャリア</option>
                        <option value="creative" @selected(old('workspace_domain_override', $plan->workspace_domain_override) === 'creative')>制作活動</option>
                        <option value="general" @selected(old('workspace_domain_override', $plan->workspace_domain_override) === 'general')>一般</option>
                    </select>
                    @error('workspace_domain_override')
                        <p class="mt-2 text-xs text-rose-300">{{ $message }}</p>
                    @enderror
                </section>

                <div>
                    <label for="category" class="mb-2 block text-sm font-medium text-slate-700">
                        カテゴリ（旧分類・必要な場合だけ修正）
                    </label>

                    <select
                        id="category"
                        name="category"
                        class="w-full rounded-lg border border-slate-300 px-3 py-2 outline-none focus:border-slate-900 focus:ring-2 focus:ring-slate-200"
                    >
                        <option value="">選択してください</option>
                        <option value="資格学習" @selected(old('category', $plan->category) === '資格学習')>資格学習</option>
                        <option value="個人開発" @selected(old('category', $plan->category) === '個人開発')>個人開発</option>
                        <option value="制作活動" @selected(old('category', $plan->category) === '制作活動')>制作活動</option>
                        <option value="就活・キャリア" @selected(old('category', $plan->category) === '就活・キャリア')>就活・キャリア</option>
                        <option value="ゲーム開発" @selected(old('category', $plan->category) === 'ゲーム開発')>ゲーム開発</option>
                        <option value="その他" @selected(old('category', $plan->category) === 'その他')>その他</option>
                    </select>
                </div>

                <div class="rounded-xl border border-cyan-300/15 bg-cyan-300/[0.035] p-4">
                    <label for="priority_mode" class="mb-2 block text-sm font-medium text-slate-200">優先度の決め方</label>
                    <select id="priority_mode" name="priority_mode" class="form-control">
                        <option value="auto" @selected(old('priority_mode', $plan->priority_mode ?? 'auto') === 'auto')>Canoviaに自動で任せる</option>
                        <option value="manual" @selected(old('priority_mode', $plan->priority_mode ?? 'auto') === 'manual')>手動で固定する</option>
                    </select>
                    <p class="mt-3 text-xs leading-5 text-slate-400">
                        現在の自動判定は <strong class="text-cyan-200">優先度 {{ (int) ($priorityEvaluation['auto_priority'] ?? 3) }}</strong>。
                        {{ collect($priorityEvaluation['auto_reasons'] ?? $priorityEvaluation['reasons'] ?? [])->implode(' / ') }}
                    </p>

                    <label for="priority" class="mt-4 mb-2 block text-sm font-medium text-slate-200">手動優先度</label>
                    <select id="priority" name="priority" class="form-control">
                        @for ($priority = 1; $priority <= 5; $priority++)
                            <option value="{{ $priority }}" @selected((int) old('priority', $plan->priority ?? 3) === $priority)>
                                {{ $priority }}{{ $priority === 1 ? '（最優先）' : ($priority === 5 ? '（低）' : '') }}
                            </option>
                        @endfor
                    </select>
                    <p class="mt-2 text-xs leading-5 text-slate-500">手動モードのときだけこの値で固定します。「自動」に戻せばCanoviaが再評価します。</p>
                </div>

                <section id="plan-design" class="scroll-mt-28">
                    <div class="mb-3">
                        <h2 class="text-lg font-bold text-slate-900">この計画の見た目</h2>
                    </div>
                    @include('plans.partials.visual-picker', ['visualPlan' => $plan])
                </section>

                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label for="start_date" class="mb-2 block text-sm font-medium text-slate-700">
                            開始日
                        </label>

                        <input
                            id="start_date"
                            type="date"
                            name="start_date"
                            value="{{ old('start_date', $plan->start_date?->format('Y-m-d')) }}"
                            required
                            class="w-full rounded-lg border border-slate-300 px-3 py-2 outline-none focus:border-slate-900 focus:ring-2 focus:ring-slate-200"
                        >
                    </div>

                    <div>
                        <label for="deadline" class="mb-2 block text-sm font-medium text-slate-700">
                            期限
                        </label>

                        <input
                            id="deadline"
                            type="date"
                            name="deadline"
                            value="{{ old('deadline', $plan->deadline?->format('Y-m-d')) }}"
                            class="w-full rounded-lg border border-slate-300 px-3 py-2 outline-none focus:border-slate-900 focus:ring-2 focus:ring-slate-200"
                        >
                    </div>
                </div>

                @auth
                    <div class="rounded-xl border border-violet-400/20 bg-violet-500/5 p-4">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <span class="block font-medium text-slate-100">共同計画</span>
                                <span class="mt-1 block text-sm leading-6 text-slate-400">共有URL・参加コード・メンバー権限を管理します。</span>
                            </div>
                            <a href="{{ route('plans.collaboration.settings', $plan) }}" class="btn-secondary">{{ $plan->is_collaborative ? '共有設定を開く' : '共同計画を設定' }}</a>
                        </div>
                    </div>
                @endauth

                <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                    <input type="hidden" name="is_public" value="0">
                    <label class="flex items-start gap-3">
                        <input
                            type="checkbox"
                            name="is_public"
                            value="1"
                            @checked(old('is_public', $plan->is_public))
                            class="mt-1"
                        >

                        <span>
                            <span class="block font-medium text-slate-900">
                                この計画を公開する
                            </span>

                            <span class="mt-1 block text-sm leading-6 text-slate-600">
                                公開すると、共有URLを知っている人がこの計画を見られます。
                                公開ページでは編集や作業ログの追加はできません。
                            </span>
                        </span>
                    </label>
                </div>

                <div class="flex flex-wrap gap-3">
                    <button
                        type="submit"
                        class="btn-primary"
                    >
                        更新する
                    </button>

                    <a
                        href="{{ route('plans.show', $plan) }}"
                        class="btn-secondary"
                    >
                        戻る
                    </a>
                </div>
            </form>
        </div>

        <aside class="space-y-6">
            <div class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
                <h2 class="text-lg font-bold text-slate-900">
                    現在の状態
                </h2>

                <dl class="mt-4 space-y-3 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">公開設定</dt>
                        <dd class="font-medium text-slate-900">
                            {{ $plan->is_public ? '公開' : '非公開' }}
                        </dd>
                    </div>

                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">作成日</dt>
                        <dd class="font-medium text-slate-900">
                            {{ $plan->created_at->format('Y-m-d') }}
                        </dd>
                    </div>

                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">最終更新</dt>
                        <dd class="font-medium text-slate-900">
                            {{ $plan->updated_at->format('Y-m-d H:i') }}
                        </dd>
                    </div>
                </dl>
            </div>

            <div class="rounded-2xl border border-red-200 bg-red-50 p-6">
                <h2 class="text-lg font-bold text-red-900">
                    危険な操作
                </h2>

                <p class="mt-3 text-sm leading-6 text-red-700">
                    計画を削除すると、紐づくタスクと作業ログも削除されます。
                    この操作は元に戻せません。
                </p>

                <form
                    action="{{ route('plans.destroy', $plan) }}"
                    method="POST"
                    class="mt-4"
                    onsubmit="return confirm('この計画を削除しますか？紐づくタスクと作業ログも削除されます。この操作は元に戻せません。');"
                >
                    @csrf
                    @method('DELETE')

                    <button
                        type="submit"
                        class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700"
                    >
                        この計画を削除する
                    </button>
                </form>
            </div>
        </aside>
    </section>
@endsection