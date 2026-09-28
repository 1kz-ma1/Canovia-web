@extends('layouts.app')

@section('title', '制作ファイル | Canovia')

@section('content')
    @php
        $providerIcons = [
            'google_drive' => '△',
            'onedrive' => '☁',
            'github' => '⌘',
            'canovia' => '✦',
            'external' => '↗',
        ];
    @endphp

    <section class="mb-7 flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.16em] text-cyan-300">PROJECT OUTPUTS</p>
            <h1 class="mt-2 text-3xl font-bold tracking-tight text-slate-50">制作ファイル / 成果物</h1>
            <p class="mt-3 max-w-3xl text-sm leading-7 text-slate-400">
                {{ $plan->title }}で「いま使う最新版がどれか」を分かりやすくまとめます。GitHubを使わないチームでも、DriveやOneDriveの制作物を同じ画面で管理できます。
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('plans.resources.index', $plan) }}" class="btn-secondary">関連資料</a>
            <a href="{{ route('plans.show', $plan) }}" class="btn-secondary">計画へ戻る</a>
        </div>
    </section>

    @if (session('success'))
        <div class="assistant-notice assistant-notice-success mb-6">{{ session('success') }}</div>
    @endif

    @if ($errors->any())
        <div class="assistant-notice mb-6 border border-rose-400/30 bg-rose-500/10 text-rose-100">
            <ul class="list-inside list-disc space-y-1 text-sm">
                @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    @if ($canEdit)
        <section class="page-card mb-7 p-5 sm:p-6">
            <div>
                <p class="text-sm font-semibold text-cyan-300">制作物を追加</p>
                <h2 class="mt-1 text-xl font-bold text-slate-50">チームで追いかけたいファイルを登録</h2>
                <p class="mt-2 text-sm leading-6 text-slate-400">今はファイル本体をCanoviaへ保存せず、共有URLを参照します。担当者や関連タスクも一緒に持たせられます。</p>
            </div>

            <form method="POST" action="{{ route('plans.artifacts.store', $plan) }}" class="mt-5 space-y-5">
                @csrf
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <label class="block">
                        <span class="text-xs font-semibold text-slate-400">保存先</span>
                        <select name="provider" class="form-control mt-2" required>
                            @foreach ($providers as $key => $label)
                                <option value="{{ $key }}" @selected(old('provider', 'google_drive') === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold text-slate-400">種類</span>
                        <select name="artifact_type" class="form-control mt-2" required>
                            @foreach ($artifactTypes as $key => $label)
                                <option value="{{ $key }}" @selected(old('artifact_type', 'file') === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="block sm:col-span-2">
                        <span class="text-xs font-semibold text-slate-400">制作物名</span>
                        <input type="text" name="title" value="{{ old('title') }}" class="form-control mt-2" placeholder="例：Webアプリ最新版" required>
                    </label>
                    <label class="block sm:col-span-2 lg:col-span-3">
                        <span class="text-xs font-semibold text-slate-400">共有URL</span>
                        <input type="url" name="url" value="{{ old('url') }}" class="form-control mt-2" placeholder="https://..." required>
                    </label>
                    <label class="block">
                        <span class="text-xs font-semibold text-slate-400">バージョン（任意）</span>
                        <input type="text" name="version_label" value="{{ old('version_label') }}" class="form-control mt-2" placeholder="v1.4 / 9月17日版">
                    </label>
                    <label class="block sm:col-span-2">
                        <span class="text-xs font-semibold text-slate-400">担当者（任意）</span>
                        <select name="assigned_user_id" class="form-control mt-2">
                            <option value="">未設定</option>
                            @foreach ($assignees as $person)
                                <option value="{{ $person->id }}" @selected((string) old('assigned_user_id') === (string) $person->id)>{{ $person->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    @if ($plan->is_collaborative)
                        <label class="block sm:col-span-2">
                            <span class="text-xs font-semibold text-slate-400">共同作業の状態</span>
                            <select name="collaboration_state" class="form-control mt-2">
                                <option value="">状態未設定</option>
                                @foreach ($collaborationStates as $key => $label)
                                    <option value="{{ $key }}" @selected(old('collaboration_state', 'active') === $key)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <span class="mt-1 block text-[11px] leading-5 text-slate-500">Canovia Mapの「共同」で目的別に整理するための明示状態です。自動変更はしません。</span>
                        </label>
                    @endif
                    <label class="block sm:col-span-2">
                        <span class="text-xs font-semibold text-slate-400">メモ（任意）</span>
                        <input type="text" name="notes" value="{{ old('notes') }}" class="form-control mt-2" placeholder="例：発表用に使う最終版">
                    </label>
                </div>

                @if ($plan->tasks->isNotEmpty())
                    <fieldset>
                        <legend class="text-sm font-semibold text-slate-300">関連タスク</legend>
                        <p class="mt-1 text-xs text-slate-500">この成果物に関係する作業を選んでおくと、タスク側からもすぐ開けます。</p>
                        <div class="mt-3 grid gap-2 sm:grid-cols-2">
                            @foreach ($plan->tasks as $task)
                                <label class="flex gap-3 rounded-xl border border-white/8 bg-white/[0.025] p-3 text-sm text-slate-300">
                                    <input type="checkbox" name="task_ids[]" value="{{ $task->id }}" class="mt-1" @checked(in_array($task->id, old('task_ids', [])))>
                                    <span>{{ $task->title }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                @endif

                <button type="submit" class="btn-primary">制作ファイルとして登録</button>
            </form>
        </section>
    @endif

    <section class="page-card p-5 sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-sm font-semibold text-violet-300">LATEST OUTPUTS</p>
                <h2 class="mt-1 text-2xl font-bold text-slate-50">制作物一覧</h2>
                <p class="mt-2 text-sm leading-6 text-slate-400">GitHubのリポジトリも、Driveのフォルダも、同じ「制作物」として扱います。</p>
            </div>
            <span class="badge badge-slate">{{ $plan->artifacts->count() }}件</span>
        </div>

        @if ($plan->artifacts->isEmpty())
            <div class="empty-state mt-5">まだ制作ファイルはありません。</div>
        @else
            <div class="mt-5 space-y-4">
                @foreach ($plan->artifacts as $artifact)
                    <article class="rounded-2xl border border-white/8 bg-white/[0.03] p-4 sm:p-5">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="badge badge-slate">{{ $providerIcons[$artifact->provider] ?? '•' }} {{ $artifact->providerLabel() }}</span>
                                    <span class="badge badge-slate">{{ $artifact->artifactTypeLabel() }}</span>
                                    @if ($artifact->version_label)<span class="badge badge-green">{{ $artifact->version_label }}</span>@endif
                                    @if ($plan->is_collaborative && $artifact->collaborationStateLabel())
                                        <span class="badge badge-slate">{{ $artifact->collaborationStateLabel() }}</span>
                                    @endif
                                </div>
                                <h3 class="mt-3 break-words text-lg font-bold text-slate-50">{{ $artifact->title }}</h3>
                                <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500">
                                    <span>担当：{{ $artifact->assignedUser?->name ?? '未設定' }}</span>
                                    <span>更新：{{ $artifact->updated_at?->diffForHumans() }}</span>
                                </div>
                                @if ($artifact->notes)<p class="mt-3 text-sm leading-6 text-slate-400">{{ $artifact->notes }}</p>@endif
                                <div class="mt-3 flex flex-wrap gap-2">
                                    @forelse ($artifact->tasks as $task)
                                        <span class="rounded-full border border-cyan-300/15 bg-cyan-300/[0.05] px-3 py-1 text-xs text-cyan-100">{{ $task->title }}</span>
                                    @empty
                                        <span class="text-xs text-slate-500">関連タスク未設定</span>
                                    @endforelse
                                </div>
                            </div>
                            <a href="{{ $artifact->url }}" target="_blank" rel="noopener noreferrer" class="btn-primary px-4 py-2 text-sm">開く</a>
                        </div>

                        @if ($canEdit)
                            <details class="mt-4 rounded-xl border border-white/8 bg-black/10 p-3">
                                <summary class="cursor-pointer text-sm font-semibold text-slate-300">編集・担当・タスクを変更</summary>
                                <form method="POST" action="{{ route('plans.artifacts.update', [$plan, $artifact]) }}" class="mt-4 space-y-4">
                                    @csrf
                                    @method('PUT')
                                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                        <label class="block">
                                            <span class="text-xs font-semibold text-slate-400">保存先</span>
                                            <select name="provider" class="form-control mt-2">
                                                @foreach ($providers as $key => $label)<option value="{{ $key }}" @selected($artifact->provider === $key)>{{ $label }}</option>@endforeach
                                            </select>
                                        </label>
                                        <label class="block">
                                            <span class="text-xs font-semibold text-slate-400">種類</span>
                                            <select name="artifact_type" class="form-control mt-2">
                                                @foreach ($artifactTypes as $key => $label)<option value="{{ $key }}" @selected($artifact->artifact_type === $key)>{{ $label }}</option>@endforeach
                                            </select>
                                        </label>
                                        <label class="block sm:col-span-2">
                                            <span class="text-xs font-semibold text-slate-400">制作物名</span>
                                            <input type="text" name="title" value="{{ $artifact->title }}" class="form-control mt-2" required>
                                        </label>
                                        <label class="block sm:col-span-2 lg:col-span-3">
                                            <span class="text-xs font-semibold text-slate-400">共有URL</span>
                                            <input type="url" name="url" value="{{ $artifact->url }}" class="form-control mt-2" required>
                                        </label>
                                        <label class="block">
                                            <span class="text-xs font-semibold text-slate-400">バージョン</span>
                                            <input type="text" name="version_label" value="{{ $artifact->version_label }}" class="form-control mt-2">
                                        </label>
                                        <label class="block sm:col-span-2">
                                            <span class="text-xs font-semibold text-slate-400">担当者</span>
                                            <select name="assigned_user_id" class="form-control mt-2">
                                                <option value="">未設定</option>
                                                @foreach ($assignees as $person)<option value="{{ $person->id }}" @selected((int) $artifact->assigned_user_id === (int) $person->id)>{{ $person->name }}</option>@endforeach
                                            </select>
                                        </label>
                                        @if ($plan->is_collaborative)
                                            <label class="block sm:col-span-2">
                                                <span class="text-xs font-semibold text-slate-400">共同作業の状態</span>
                                                <select name="collaboration_state" class="form-control mt-2">
                                                    <option value="">状態未設定</option>
                                                    @foreach ($collaborationStates as $key => $label)
                                                        <option value="{{ $key }}" @selected($artifact->collaborationState() === $key)>{{ $label }}</option>
                                                    @endforeach
                                                </select>
                                            </label>
                                        @endif
                                        <label class="block sm:col-span-2">
                                            <span class="text-xs font-semibold text-slate-400">メモ</span>
                                            <input type="text" name="notes" value="{{ $artifact->notes }}" class="form-control mt-2">
                                        </label>
                                    </div>

                                    @if ($plan->tasks->isNotEmpty())
                                        <fieldset>
                                            <legend class="text-xs font-semibold text-slate-400">関連タスク</legend>
                                            @php $linkedTaskIds = $artifact->tasks->pluck('id')->all(); @endphp
                                            <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                                @foreach ($plan->tasks as $task)
                                                    <label class="flex gap-3 rounded-xl border border-white/8 p-3 text-sm text-slate-300">
                                                        <input type="checkbox" name="task_ids[]" value="{{ $task->id }}" class="mt-1" @checked(in_array($task->id, $linkedTaskIds))>
                                                        <span>{{ $task->title }}</span>
                                                    </label>
                                                @endforeach
                                            </div>
                                        </fieldset>
                                    @endif

                                    <button type="submit" class="btn-primary">更新</button>
                                </form>
                                <form method="POST" action="{{ route('plans.artifacts.destroy', [$plan, $artifact]) }}" class="mt-3" onsubmit="return confirm('この制作ファイルを削除しますか？タスクとの紐づけも解除されます。');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-xs font-semibold text-rose-300 hover:text-rose-200">この制作ファイルを削除</button>
                                </form>
                            </details>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif
    </section>
@endsection
