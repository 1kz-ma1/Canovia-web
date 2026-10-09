@extends('layouts.app')

@section('title', 'Question Pack管理 | Canovia')

@section('content')
    <div class="mx-auto max-w-7xl space-y-6">
        @include('admin.partials.nav')

        <header class="rounded-[1.6rem] border border-cyan-300/15 bg-slate-950/55 p-5 sm:p-7">
            <p class="text-[10px] font-black uppercase tracking-[.18em] text-cyan-300">QUESTION BANK</p>
            <h1 class="mt-2 text-2xl font-black text-slate-50">Question Pack管理</h1>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-400">
                資格別の問題データをDraftとして取り込み、確認後に公開します。公開中PackはJSON再インポートで直接上書きせず、新versionを別slugで準備してください。
            </p>
        </header>

        @if (session('status'))
            <div class="rounded-2xl border border-emerald-300/20 bg-emerald-300/[0.06] px-4 py-3 text-sm text-emerald-100">{{ session('status') }}</div>
        @endif

        @error('status')
            <div role="alert" class="rounded-xl border border-rose-300/30 bg-rose-950/20 p-4 text-sm text-rose-200" data-question-pack-status-error>
                {{ $message }}
            </div>
        @enderror

        <section class="page-card border-violet-300/15 p-5 sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[.14em] text-violet-300">BUNDLED PACKS</p>
                    <h2 class="mt-1 text-xl font-black text-slate-50">Canovia同梱問題集</h2>
                    <p class="mt-2 max-w-3xl text-xs leading-5 text-slate-500">
                        リポジトリで管理しているPackです。未監修の候補も含まれるため、同梱や機械検査の成功だけで監修済みとは扱いません。まずDraftへ取り込み、個別に品質・利用条件を確認してください。
                    </p>
                </div>
                <span class="badge badge-slate">{{ ($bundledPacks ?? collect())->count() }} packs</span>
            </div>

            <div class="mt-4 grid gap-3 lg:grid-cols-2">
                @forelse (($bundledPacks ?? collect()) as $bundled)
                    @php($installed = ($bundledInstallations ?? collect())->get($bundled['slug']))
                    <article class="rounded-2xl border border-slate-800 bg-slate-950/35 p-4" data-bundled-pack="{{ $bundled['key'] }}" data-install-status="{{ $installed?->status ?? 'absent' }}">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <strong class="text-sm text-slate-100">{{ $bundled['title'] }}</strong>
                                <p class="mt-1 text-[11px] text-slate-500">
                                    {{ $bundled['exam_code'] ?: '—' }}
                                    @if ($bundled['subject']) · {{ $bundled['subject'] }} @endif
                                    @if ($bundled['version']) · v{{ $bundled['version'] }} @endif
                                </p>
                            </div>
                            <span class="badge badge-slate">{{ $bundled['question_count'] }}問</span>
                        </div>

                        @if (data_get($bundled, 'metadata.source_period'))
                            <p class="mt-3 text-xs leading-5 text-slate-400">
                                出典：{{ data_get($bundled, 'metadata.source_period') }}
                            </p>
                        @endif

                        @if (data_get($bundled, 'metadata.license_note'))
                            <p class="mt-2 text-[11px] leading-5 text-slate-500">
                                {{ data_get($bundled, 'metadata.license_note') }}
                            </p>
                        @endif

                        <p class="mt-3 text-xs font-semibold {{ $installed?->status === 'published' ? 'text-emerald-300' : 'text-slate-300' }}">
                            DB登録状態：
                            @if (! $installed)
                                未取込（ユーザーには出題されません）
                            @else
                                {{ $installed->status }} · DB版 v{{ $installed->version }}
                                @if ($installed->status === 'published')
                                    （公開中）
                                @elseif ($installed->status === 'retired')
                                    （公開終了。再取込は不可）
                                @else
                                    （確認後に別途公開操作が必要）
                                @endif
                            @endif
                        </p>
                        @if (! $installed || in_array($installed->status, ['draft', 'review'], true))
                            <form method="POST" action="{{ route('admin.question_packs.import_bundled') }}" class="mt-4">
                                @csrf
                                <input type="hidden" name="catalog_key" value="{{ $bundled['key'] }}">
                                <button type="submit" class="btn-secondary">
                                    {{ $installed ? 'Draftを再取込（既存Draftの問題を更新）' : 'Draftへ取り込む' }}
                                </button>
                            </form>
                        @else
                            <p class="mt-2 text-xs text-slate-500">
                                公開済み・公開終了済みPackは再取込できません。修正版は新しいslug/versionを作成してください。
                            </p>
                        @endif
                    </article>
                @empty
                    <div class="rounded-2xl border border-slate-800 bg-slate-950/35 p-4 text-sm text-slate-500">
                        同梱Question Packはまだありません。
                    </div>
                @endforelse
            </div>

            @error('catalog_key')
                <p class="mt-3 text-sm font-semibold text-rose-300">{{ $message }}</p>
            @enderror
        </section>

        @if(isset($apCandidateAudit))
            <section class="page-card p-5 sm:p-6" data-ap-a-item-review-queue>
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[.14em] text-amber-300">AP SUBJECT A / QUALITY GATE</p>
                        <h2 class="mt-1 text-xl font-black text-slate-50">80問候補の内容監査キュー</h2>
                        <p class="mt-2 max-w-3xl text-xs leading-6 text-slate-400">
                            リポジトリ内の未公開候補（v{{ $apCandidateAudit['candidate_version'] }}）を出典と比較した読み取り専用の結果です。
                            自動検査の成功は正答・分野・著作権・解説の専門監修を意味しません。
                            ここには承認ボタンも公開処理もありません。
                        </p>
                    </div>
                    <span class="badge badge-slate">未監修 / 未公開</span>
                </div>

                <div class="mt-4 grid grid-cols-2 gap-3 md:grid-cols-4">
                    <div class="rounded-xl border border-slate-700 p-3">
                        <p class="text-[11px] text-slate-400">監査対象</p>
                        <p class="mt-1 text-xl font-black text-slate-100">{{ $apCandidateAudit['count'] }}問</p>
                    </div>
                    <div class="rounded-xl border border-slate-700 p-3">
                        <p class="text-[11px] text-slate-400">機械的な不整合</p>
                        <p class="mt-1 text-xl font-black {{ $apCandidateAudit['structural_failure_count'] ? 'text-rose-300' : 'text-slate-100' }}"
                           data-ap-a-automatic-failures>{{ $apCandidateAudit['structural_failure_count'] }}問</p>
                    </div>
                    <div class="rounded-xl border border-slate-700 p-3">
                        <p class="text-[11px] text-slate-400">人手の内容監修待ち</p>
                        <p class="mt-1 text-xl font-black text-amber-300" data-ap-a-human-review-pending>{{ $apCandidateAudit['independent_review_pending_count'] }}問</p>
                    </div>
                    <div class="rounded-xl border border-slate-700 p-3">
                        <p class="text-[11px] text-slate-400">分野の暫定内訳</p>
                        <p class="mt-1 text-sm font-semibold text-slate-200">
                            技{{ $apCandidateAudit['distribution']['technology'] }}・管{{ $apCandidateAudit['distribution']['management'] }}・戦{{ $apCandidateAudit['distribution']['strategy'] }}
                        </p>
                    </div>
                </div>

                <div class="mt-4 rounded-xl border border-slate-700/80 p-3" data-ap-a-priority-summary>
                    <p class="text-xs font-bold text-slate-200">監修の優先順位</p>
                    <p class="mt-2 text-xs leading-6 text-slate-300">
                        P0：{{ $apCandidateAudit['priority_counts']['P0'] }}問（IPA原文未照合・構造要修正）／
                        P1：{{ $apCandidateAudit['priority_counts']['P1'] }}問（独自解説・新規問題・重要計算）／
                        P2：{{ $apCandidateAudit['priority_counts']['P2'] }}問（その他）
                    </p>
                    <p class="mt-2 text-xs text-slate-300" data-ap-a-choice-draft-summary>
                        Canovia独自問題の選択肢理由案：
                        {{ $apCandidateAudit['choice_draft_current_count'] }}/45問（誤答135肢）、
                        未記録・改変による失効 {{ $apCandidateAudit['choice_draft_stale_count'] }}問。
                        これらは監修者の承認ではなく、独立した品質確認が引き続き必要です。
                    </p>
                    <p class="mt-2 text-xs text-slate-400">
                        IPA原問題のスポット照合 {{ $apCandidateAudit['source_visual_spotchecked_count'] }}/35問。
                        未照合 {{ $apCandidateAudit['source_visual_unchecked_official_count'] }}問。
                        照合済みの問題も正答・解説・著作権の独立監修は未完了です。
                    </p>
                </div>

                <p class="mt-3 text-xs leading-6 text-amber-200" data-ap-a-release-blocked>
                    {{ $apCandidateAudit['publication_blocked'] ? '通常公開と本番模試提供はブロック中です。' : '公開ゲートの状態が変わりました。管理者による権利・正答・解説の審査記録を別途確認してください。' }}
                    既知の重複 {{ $apCandidateAudit['known_overlap_count'] }}組は
                    {{ $apCandidateAudit['known_overlap_excluded'] ? '候補から除外済み' : '再確認が必要' }}。
                    未発見の意味上の重複がないと保証するものではありません。
                </p>

                <details class="mt-4 rounded-xl border border-slate-700 p-3" data-ap-a-review-items>
                    <summary class="cursor-pointer text-sm font-bold text-cyan-200">
                        80問それぞれの出典・正答・解説と監修項目を確認する
                    </summary>
                    <div class="mt-4 space-y-2">
                        @foreach(collect($apCandidateAudit['items'])->sortBy(fn ($item) => [$item['priority'], $item['number']]) as $item)
                            <details class="rounded-xl border border-slate-800 bg-slate-950/30 p-3"
                                     data-ap-a-review-item="{{ $item['key'] }}">
                                <summary class="cursor-pointer text-xs font-semibold text-slate-100">
                                    問{{ $item['number'] }} · {{ $item['key'] }}
                                    · {{ $item['origin_type'] === 'official' ? 'IPA過去問' : ($item['origin_type'] === 'core' ? '既存Canovia' : '新規Canovia') }}
                                    · {{ $item['domain'] }}
                                    · {{ $item['priority'] }}優先
                                    · {{ count($item['flags']) ? '自動検査で要修正' : '構造検査OK' }}
                                    · 専門監修待ち
                                </summary>
                                <div class="mt-3 space-y-3 text-xs leading-6 text-slate-300">
                                    <p class="font-semibold text-amber-300" data-ap-a-review-priority="{{ $item['priority'] }}">
                                        {{ $item['priority'] }}：{{ $item['priority_reason'] }}
                                    </p>
                                    @if($item['source_visual_spotcheck'])
                                        <p class="text-cyan-200" data-ap-a-source-spotcheck>
                                            原文のスポット照合記録：PDF {{ $item['source_visual_spotcheck_pdf_page'] }}ページ。
                                            {{ $item['source_visual_spotcheck_note'] }}
                                            （専門監修済みではありません）
                                        </p>
                                    @endif
                                    <p class="whitespace-pre-wrap text-slate-100">{{ $item['prompt'] }}</p>
                                    <div class="grid gap-1 sm:grid-cols-2">
                                        @foreach($item['choices'] as $choice)
                                            <p class="{{ $choice['id'] === $item['answer'] ? 'font-semibold text-emerald-200' : 'text-slate-300' }}">
                                                {{ $choice['id'] }}：{{ $choice['label'] }}
                                            </p>
                                        @endforeach
                                    </div>
                                    <p class="font-semibold text-slate-100">正答：{{ $item['answer'] }}</p>
                                    <p class="whitespace-pre-wrap">Canovia解説：{{ $item['explanation'] }}</p>
                                    @if($item['choice_draft_state'] === 'current_unreviewed_draft')
                                        <div class="rounded-lg border border-cyan-500/20 p-3" data-ap-a-choice-draft="{{ $item['key'] }}">
                                            <p class="font-semibold text-cyan-200">Canovia独自問題の誤答理由案（未監修）</p>
                                            <ul class="mt-2 list-inside list-disc space-y-1 text-slate-300">
                                                @foreach($item['choice_draft_reasons'] as $choiceId => $reason)
                                                    <li>{{ $choiceId }}：{{ $reason }}</li>
                                                @endforeach
                                            </ul>
                                            @if(($item['choice_draft_review_focus']['level'] ?? 'standard') !== 'standard')
                                                <p class="mt-2 text-amber-200">
                                                    監修時の注意：{{ $item['choice_draft_review_focus']['note'] }}
                                                </p>
                                                @if(str_starts_with($item['choice_draft_review_focus']['reference'] ?? '', 'https://'))
                                                    <p class="mt-1">
                                                        <a class="text-cyan-300 underline" href="{{ $item['choice_draft_review_focus']['reference'] }}"
                                                            target="_blank" rel="noopener noreferrer">注意事項の参考資料を開く</a>
                                                    </p>
                                                @endif
                                            @endif
                                        </div>
                                    @elseif($item['choice_draft_state'] === 'missing_or_stale')
                                        <p class="font-semibold text-rose-300" data-ap-a-choice-draft-stale>
                                            監査用の誤答理由案が不足、または現行内容と不一致です。再確認が必要です。
                                        </p>
                                    @endif
                                    <p>出典問題集：{{ $item['source_pack'] }} / {{ $item['source_key'] }}</p>
                                    @if($item['source_reference'])
                                        <p>出典表記：{{ $item['source_reference'] }}</p>
                                    @endif
                                    @if(str_starts_with($item['source_url'], 'https://www.ipa.go.jp/'))
                                        <p class="flex flex-wrap gap-x-4 gap-y-1">
                                            <a class="text-cyan-300 underline" href="{{ $item['source_url'] }}" target="_blank" rel="noopener noreferrer">IPA原問題を確認（別タブ）</a>
                                            @if(str_starts_with($item['source_answer_url'], 'https://www.ipa.go.jp/'))
                                                <a class="text-cyan-300 underline" href="{{ $item['source_answer_url'] }}" target="_blank" rel="noopener noreferrer">IPA解答例を確認（別タブ）</a>
                                            @endif
                                        </p>
                                    @endif
                                    @if(count($item['flags']))
                                        <ul class="list-inside list-disc text-rose-300" data-ap-a-audit-flags>
                                            @foreach($item['flags'] as $flag)
                                                <li>{{ $flag }}</li>
                                            @endforeach
                                        </ul>
                                    @endif
                                    <div class="rounded-lg border border-amber-300/20 p-3">
                                        <p class="font-semibold text-amber-200">未確認の監修項目（自動承認なし）</p>
                                        <ul class="mt-1 list-inside list-disc text-amber-100">
                                            @foreach($item['review_tasks'] as $task)
                                                <li>{{ $task }}</li>
                                            @endforeach
                                        </ul>
                                    </div>
                                </div>
                            </details>
                        @endforeach
                    </div>
                </details>
            </section>
        @endif

        <section class="grid gap-6 xl:grid-cols-[1.05fr_.95fr]">
            <div class="page-card p-5 sm:p-6">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[.14em] text-cyan-300">IMPORT</p>
                        <h2 class="mt-1 text-xl font-black text-slate-50">Question Pack JSONを取り込む</h2>
                    </div>
                    <span class="badge badge-slate">Draft</span>
                </div>

                <form method="POST" action="{{ route('admin.question_packs.import') }}" class="mt-5">
                    @csrf
                    <textarea
                        name="pack_json"
                        class="form-control min-h-[560px] font-mono text-xs leading-5"
                        spellcheck="false"
                    >{{ old('pack_json', $importTemplate) }}</textarea>
                    @error('pack_json')
                        <p class="mt-3 text-sm font-semibold text-rose-300">{{ $message }}</p>
                    @enderror
                    <button type="submit" class="btn-primary mt-4">Draftへ取り込む</button>
                </form>

                <div class="mt-5 rounded-xl border border-amber-300/15 bg-amber-300/[0.04] p-4 text-xs leading-6 text-slate-400">
                    <strong class="text-amber-100">公開前確認：</strong>
                    問題文・図表・解説の利用条件、source_reference、正答、grading_rule、learning_metadataを確認してください。
                    <code>official</code> / <code>licensed</code> / <code>derived</code> はsource_reference必須です。
                </div>
            </div>

            <div class="space-y-4">
                <div class="page-card p-5 sm:p-6">
                    <p class="text-xs font-bold uppercase tracking-[.14em] text-violet-300">PACKS</p>
                    <h2 class="mt-1 text-xl font-black text-slate-50">登録済みPack</h2>
                    <p class="mt-2 text-xs leading-5 text-slate-500">AI演習で自動利用されるのはpublishedだけです。</p>
                </div>

                @forelse ($packs as $pack)
                    <article class="page-card p-5">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <strong class="text-slate-100">{{ $pack->title }}</strong>
                                    <span class="badge {{ $pack->status === 'published' ? 'badge-green' : 'badge-slate' }}">{{ $pack->status }}</span>
                                </div>
                                <p class="mt-1 text-xs text-slate-500">
                                    {{ $pack->slug }} · v{{ $pack->version }}
                                    @if ($pack->exam_code) · {{ $pack->exam_code }} @endif
                                    @if ($pack->subject) · {{ $pack->subject }} @endif
                                </p>
                            </div>
                            <div class="text-right">
                                <strong class="text-lg text-slate-100">{{ $pack->active_questions_count }}</strong>
                                <p class="text-[10px] text-slate-500">active / {{ $pack->questions_count }} total</p>
                            </div>
                        </div>

                        @if (collect(data_get($pack->metadata, 'match_terms', []))->isNotEmpty())
                            <div class="mt-3 flex flex-wrap gap-2">
                                @foreach (data_get($pack->metadata, 'match_terms', []) as $term)
                                    <span class="badge badge-slate">{{ $term }}</span>
                                @endforeach
                            </div>
                        @endif

                        @php($inspection = ($publicationReadiness ?? collect())->get($pack->id))
                        @if ($inspection)
                            <section class="mt-4 rounded-xl border border-slate-700 p-4" data-question-pack-readiness="{{ $pack->id }}">
                                <p class="text-xs font-bold text-slate-200">公開前チェック · 1問ずつ学習への対応</p>
                                <p class="mt-2 text-sm text-slate-300">
                                    有効 {{ $inspection['active_count'] }}問 /
                                    1問採点対応 <strong>{{ $inspection['one_question_count'] }}問</strong>
                                </p>
                                <p class="mt-1 text-xs {{ $inspection['publishable'] ? 'text-emerald-300' : 'text-rose-300' }}">
                                    {{ $inspection['publishable'] ? '公開条件：充足（内容・権利は管理者が最終確認）' : '公開条件：未充足' }}
                                </p>
                                @if ($inspection['blocking'] !== [])
                                    <ul class="mt-2 list-inside list-disc space-y-1 text-xs text-rose-300" data-question-pack-blockers>
                                        @foreach ($inspection['blocking'] as $reason)
                                            <li>{{ $reason }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                                @if ($inspection['warnings'] !== [])
                                    <ul class="mt-2 list-inside list-disc space-y-1 text-xs text-amber-200" data-question-pack-warnings>
                                        @foreach ($inspection['warnings'] as $warning)
                                            <li>{{ $warning }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                                <p class="mt-2 text-[11px] leading-5 text-slate-500">
                                    対応問数は現行Learning Runの単一選択・即時採点に基づきます。
                                    問題の著作権・正答の妥当性は自動判定していません。公開は明示操作のみです。
                                </p>
                            </section>
                        @endif

                        @php($examCheck = ($examPublicationReadiness ?? collect())->get($pack->id))
                        @if($examCheck)
                            @php($examStatus = $examCheck['inspection'])
                            @php($examProfile = $examCheck['profile'])
                            <section class="mt-4 rounded-xl border border-slate-700 p-4"
                                     data-exam-pack-readiness="{{ $pack->id }}">
                                <p class="text-xs font-bold text-slate-200">
                                    本番形式の模試 · {{ $examProfile['exam_code'] }} {{ $examProfile['subject'] }}
                                    （{{ $examProfile['question_count'] }}問 / {{ $examProfile['duration_minutes'] }}分）
                                </p>
                                <p class="mt-2 text-sm {{ $examStatus['ready'] ? 'text-emerald-300' : 'text-amber-300' }}">
                                    {{ $examStatus['ready'] ? '模試セット：利用可能' : '模試セット：未完成・未承認' }}
                                </p>
                                @if(! $examStatus['ready'])
                                    <ul class="mt-2 list-inside list-disc space-y-1 text-xs text-amber-200">
                                        @foreach($examStatus['blocking'] as $reason)
                                            <li>{{ $reason }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                                <p class="mt-3 text-xs leading-5 text-slate-400">
                                    通常の問題集公開と本番形式の模試提供は別審査です。
                                    この問題集の JSON metadata.exam_simulation_profile_key / version と
                                    exam_simulation_review に版・正答・形式・解説・著作権の確認履歴を記録してください。
                                    未監修の解説案がある場合は explanation_review_state を専門家の監修後に更新し、
                                    exam_simulation_review.explanations_checked=true を明示してください。
                                    全問の内容と正答を確認した最終版の
                                    reviewed_content_sha256 は、次の内容指紋に一致する必要があります。
                                    <code class="mt-2 block break-all text-[11px] select-all" data-exam-content-sha256>{{ $examStatus['content_sha256'] }}</code>
                                    この値のコピーだけで監修済み・利用権取得済みになるわけではありません。
                                    公式形式の出典を確認できても、そのまま出題コンテンツの利用許諾を意味しません。
                                </p>
                            </section>
                        @endif

                        <form method="POST" action="{{ route('admin.question_packs.status', $pack) }}" class="mt-4 flex flex-wrap items-center gap-2">
                            @csrf
                            @method('PATCH')
                            <select name="status" class="form-control max-w-[180px]">
                                @foreach (\App\Models\QuestionPack::STATUSES as $status)
                                    <option value="{{ $status }}" @selected($pack->status === $status)>{{ $status }}</option>
                                @endforeach
                            </select>
                            <button type="submit" class="btn-secondary">状態を更新</button>
                        </form>
                    </article>
                @empty
                    <div class="page-card p-6 text-sm text-slate-500">まだQuestion Packはありません。</div>
                @endforelse

                {{ $packs->links() }}
            </div>
        </section>
    </div>
@endsection
