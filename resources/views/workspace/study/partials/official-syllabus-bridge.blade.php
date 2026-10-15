@php($bridge = $officialSyllabusBridge ?? ['available' => false])
@if(($canEdit ?? false) && ($bridge['available'] ?? false))
<section class="page-card p-5 sm:p-6" data-study-official-syllabus-bridge
    data-study-syllabus-index-state="{{ $bridge['index_state'] }}">
    <p class="text-[10px] font-black uppercase tracking-[0.16em] text-cyan-300">SOURCE / REVIEWED MAPPING GATE</p>
    <h2 class="mt-1 text-lg font-bold text-slate-50">公式シラバスとの対応状況</h2>
    @if($bridge['source_identified'])
        <p class="mt-2 text-sm font-semibold text-slate-100" data-study-syllabus-source-version>
            IPA 応用情報技術者試験（レベル3）シラバス Ver.{{ $bridge['source']['version'] }}
        </p>
        <p class="mt-1 text-xs leading-5 text-slate-400">
            資料掲載：{{ $bridge['source']['published_date'] }}
            · 科目名表記の更新：{{ $bridge['source']['label_update_date'] }}。
            2027年度以降に向けた別の新制度シラバス案とは混同しません。
        </p>
        <p class="mt-2 text-xs">
            <a class="text-sky-300 underline" href="{{ $bridge['source']['index_url'] }}"
                target="_blank" rel="noopener noreferrer">IPA公式のシラバス一覧を開く ↗</a>
            <span class="px-2 text-slate-500">·</span>
            <a class="text-sky-300 underline" href="{{ $bridge['source']['document_url'] }}"
                target="_blank" rel="noopener noreferrer">Ver.7.2の原資料 ↗</a>
        </p>
    @else
        <p class="mt-2 text-xs font-semibold text-amber-200" data-study-syllabus-invalid-source>
            公式出典と版の設定が一致しないため、この画面では対応関係を評価できません。
        </p>
    @endif
    <div class="mt-4 space-y-2">
        @if($bridge['index_state'] === 'source_only')
            <p class="text-sm font-semibold text-slate-200" data-study-syllabus-review-pending>
                公式範囲の項目別索引：未転記・未監修
            </p>
            <p class="text-xs leading-5 text-slate-400">
                公式資料の存在と版は確認されていますが、各小分類・学習目標を
                個別に出典ページへ結び付ける監修はまだ行っていません。
            </p>
        @elseif($bridge['index_state'] === 'reviewed_partial')
            <p class="text-sm font-semibold text-slate-200" data-study-syllabus-partial-index>
                監修記録付きで登録された範囲項目 {{ count($bridge['nodes']) }}件（部分索引）
            </p>
            @foreach($bridge['nodes'] as $node)
                <p class="text-xs leading-5 text-slate-300" data-study-syllabus-scope-node>
                    {{ $node['code'] }} · {{ $node['label'] }}
                    （原資料p.{{ $node['source_page'] }} / 対応付け済み問題ID {{ $node['linked_question_count'] }}件）
                </p>
            @endforeach
        @else
            <p class="text-sm font-semibold text-amber-200" data-study-syllabus-index-invalid>
                索引の監修状態または登録内容を確認できません。
            </p>
        @endif
    </div>
    <p class="mt-3 text-xs leading-6 text-slate-300" data-study-syllabus-reviewed-links>
        版・問題内容ハッシュ・監修記録の条件を満たす対応付け {{ $bridge['reviewed_link_count'] }}件
        · 不適合または古い対応付け {{ $bridge['rejected_links'] }}件
    </p>
    <p class="mt-2 text-xs leading-6 text-slate-400" data-study-syllabus-no-percentage>
        <strong>公式試験範囲のカバー率：算出対象外。</strong>
        公式範囲の全項目・対応する問題の妥当性・独立した監修・利用権限が確認されるまでは、
        0%や100%などの割合を表示しません。登録された監修記録も第三者による承認の証明とは限りません。
        V6.19の問題集タグ集計とは別の根拠として扱います。
    </p>
    <p class="mt-2 text-xs text-slate-500">
        ここから問題の公開・自動生成、出題ルール、Task進捗、Evidence、次Actionは変更しません。
    </p>
</section>
@endif
