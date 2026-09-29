@php
    $station = $graph['space_station'] ?? [];
    $latestItem = $station['latest_item'] ?? null;
    $candidate = $station['routing_candidate'] ?? null;
    $destinations = $station['routing_destinations'] ?? [];
    $editablePlans = collect($station['editable_plans'] ?? []);
    $canUseInboxAi = (bool) ($station['can_use_inbox_ai'] ?? false);
    $canUseCompanion = (bool) ($station['can_use_companion'] ?? false);
    $pendingCount = (int) ($station['pending_count'] ?? 0);
    $stationMapLevel = (string) ($graph['level'] ?? 'l0');
    $stationIsDock = $stationMapLevel !== 'l0';
    $stationReturnTo = $stationIsDock ? 'map_station' : 'space_station';
    $stationHierarchy = $graph['hierarchy'] ?? [];
    $stationCollaborationContext = (string) ($stationHierarchy['collaboration_context_key'] ?? '');
    $stationReflectionContext = (string) ($stationHierarchy['reflection_context_key'] ?? '');
    $stationHash = $stationIsDock
        ? '#dock=space-station'
        : '#focus='.rawurlencode('intent:space-station');
    $stationSourcePath = request()->getRequestUri().$stationHash;
@endphp

<div class="canovia-space-station-panel" data-space-station-panel>
    <section class="canovia-space-station-section">
        <div class="canovia-space-station-section-head">
            <div>
                <p class="canovia-map-companion-kicker">CAPTURE</p>
                <h3>まずはここへ投げる</h3>
            </div>
            <span class="canovia-space-station-badge">未整理 {{ $pendingCount }}件</span>
        </div>

        <form
            method="POST"
            action="{{ route('inbox.store') }}"
            enctype="multipart/form-data"
            class="canovia-space-station-form"
            data-mutation-once
        >
            @csrf
            <input type="hidden" name="return_to" value="{{ $stationReturnTo }}">
            @if ($stationIsDock)
                <input type="hidden" name="map_level" value="{{ $stationMapLevel }}">
                <input type="hidden" name="map_intent" value="{{ $stationHierarchy['intent'] ?? '' }}">
                <input type="hidden" name="map_domain" value="{{ $stationHierarchy['domain_key'] ?? '' }}">
                <input type="hidden" name="map_plan" value="{{ $stationHierarchy['plan_id'] ?? '' }}">
                @if ($stationCollaborationContext !== '')
                    <input type="hidden" name="map_collab_context" value="{{ $stationCollaborationContext }}">
                @endif
                @if ($stationReflectionContext !== '')
                    <input type="hidden" name="map_reflection_context" value="{{ $stationReflectionContext }}">
                @endif
            @endif

            <div>
                <label for="space-station-content">テキスト</label>
                <textarea
                    id="space-station-content"
                    name="content"
                    rows="3"
                    maxlength="50000"
                    class="input-field w-full resize-y"
                    placeholder="思いついたこと、作業結果、相談したいことをそのまま入力"
                >{{ old('content') }}</textarea>
            </div>

            <details
                class="canovia-space-station-attachments"
                @if (filled(old('source_url')) || $errors->has('source_url') || $errors->has('source_file')) open @endif
            >
                <summary>URL・スクリーンショット・PDFを追加</summary>
                <div class="canovia-space-station-attachments-grid">
                    <div>
                        <label for="space-station-url">URL</label>
                        <input
                            id="space-station-url"
                            type="url"
                            name="source_url"
                            value="{{ old('source_url') }}"
                            class="input-field w-full"
                            placeholder="https://..."
                        >
                    </div>

                    <div>
                        <label for="space-station-file">スクリーンショット / PDF</label>
                        <input
                            id="space-station-file"
                            type="file"
                            name="source_file"
                            accept=".pdf,image/jpeg,image/png,image/webp"
                            class="input-field w-full"
                        >
                        <p class="canovia-space-station-help">画像・PDFは既存Inboxと同じprivate storageへ保存します。</p>
                    </div>
                </div>
            </details>

            <button type="submit" class="btn-primary w-full justify-center">
                Space Stationへ送る
            </button>
        </form>
    </section>

    @if ($latestItem)
        <section class="canovia-space-station-section" data-space-station-latest>
            <div class="canovia-space-station-section-head">
                <div>
                    <p class="canovia-map-companion-kicker">LATEST INPUT</p>
                    <h3>{{ $latestItem->displayTitle() }}</h3>
                </div>
                <span class="canovia-space-station-badge">{{ $latestItem->sourceLabel() }}</span>
            </div>

            <p class="canovia-space-station-summary">
                {{ filled($latestItem->content)
                    ? str((string) $latestItem->content)->squish()->limit(160)
                    : (filled($latestItem->source_url) ? str((string) $latestItem->source_url)->limit(160) : 'ファイル入力を受け取りました。') }}
            </p>

            @if ($candidate)
                <div class="canovia-space-station-candidate" data-space-station-routing-candidate>
                    <div class="canovia-space-station-route">
                        <span>{{ $candidate['intent_label'] }}</span>
                        <span aria-hidden="true">→</span>
                        <strong>{{ $candidate['destination_label'] }}</strong>
                    </div>
                    @if (filled($candidate['reason'] ?? null))
                        <p>{{ $candidate['reason'] }}</p>
                    @endif
                    <div class="canovia-space-station-candidate-meta">
                        <span>確信度 {{ $candidate['confidence'] }}%</span>
                        @if (filled($candidate['suggested_plan_title'] ?? null))
                            <span>Plan候補: {{ $candidate['suggested_plan_title'] }}</span>
                        @endif
                        @if (filled($candidate['suggested_task_title'] ?? null))
                            <span>Task候補: {{ $candidate['suggested_task_title'] }}</span>
                        @endif
                    </div>
                    <p class="canovia-space-station-help">
                        AIはPlan / Task IDを選びません。下で実際の接続先を確認してから確定します。
                    </p>
                </div>
            @elseif ($canUseInboxAi)
                <form method="POST" action="{{ route('inbox.suggest', $latestItem) }}" data-mutation-once>
                    @csrf
                    <input type="hidden" name="return_to" value="{{ $stationReturnTo }}">
                    @if ($stationIsDock)
                        <input type="hidden" name="map_level" value="{{ $stationMapLevel }}">
                        <input type="hidden" name="map_intent" value="{{ $stationHierarchy['intent'] ?? '' }}">
                        <input type="hidden" name="map_domain" value="{{ $stationHierarchy['domain_key'] ?? '' }}">
                        <input type="hidden" name="map_plan" value="{{ $stationHierarchy['plan_id'] ?? '' }}">
                @if ($stationCollaborationContext !== '')
                    <input type="hidden" name="map_collab_context" value="{{ $stationCollaborationContext }}">
                @endif
                    @endif
                    <button type="submit" class="btn-secondary w-full justify-center">
                        ✦ 内容を読んで接続候補を作る
                    </button>
                </form>
            @else
                <p class="canovia-space-station-help">
                    AI候補を使わなくても、下の接続先は手動で確定できます。
                </p>
            @endif

            <form
                method="POST"
                action="{{ route('inbox.route', $latestItem) }}"
                class="canovia-space-station-form"
                data-space-station-confirm
                data-mutation-once
            >
                @csrf
                <input type="hidden" name="return_to" value="{{ $stationReturnTo }}">
                @if ($stationIsDock)
                    <input type="hidden" name="map_level" value="{{ $stationMapLevel }}">
                    <input type="hidden" name="map_intent" value="{{ $stationHierarchy['intent'] ?? '' }}">
                    <input type="hidden" name="map_domain" value="{{ $stationHierarchy['domain_key'] ?? '' }}">
                    <input type="hidden" name="map_plan" value="{{ $stationHierarchy['plan_id'] ?? '' }}">
                @if ($stationCollaborationContext !== '')
                    <input type="hidden" name="map_collab_context" value="{{ $stationCollaborationContext }}">
                @endif
                @endif

                <div>
                    <label for="space-station-destination">接続先</label>
                    <select id="space-station-destination" name="destination" class="input-field w-full" required>
                        @foreach ($destinations as $key => $label)
                            <option
                                value="{{ $key }}"
                                @selected(old('destination', $candidate['destination'] ?? 'keep_inbox') === $key)
                            >{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="space-station-plan">Plan</label>
                    <select id="space-station-plan" name="plan_id" class="input-field w-full">
                        <option value="">まだ選ばない</option>
                        @foreach ($editablePlans as $plan)
                            <option value="{{ $plan->id }}" @selected((string) old('plan_id') === (string) $plan->id)>
                                {{ $plan->title }}
                            </option>
                        @endforeach
                    </select>
                    @if (filled($candidate['suggested_plan_title'] ?? null))
                        <p class="canovia-space-station-help">AIの名前hint: {{ $candidate['suggested_plan_title'] }}</p>
                    @endif
                </div>

                <div>
                    <label for="space-station-task">Task</label>
                    <select id="space-station-task" name="task_id" class="input-field w-full">
                        <option value="">Task指定なし</option>
                        @foreach ($editablePlans as $plan)
                            @if ($plan->tasks->isNotEmpty())
                                <optgroup label="{{ $plan->title }}">
                                    @foreach ($plan->tasks as $task)
                                        <option value="{{ $task->id }}" @selected((string) old('task_id') === (string) $task->id)>
                                            {{ $task->title }}
                                        </option>
                                    @endforeach
                                </optgroup>
                            @endif
                        @endforeach
                    </select>
                    @if (filled($candidate['suggested_task_title'] ?? null))
                        <p class="canovia-space-station-help">AIの名前hint: {{ $candidate['suggested_task_title'] }}</p>
                    @endif
                </div>

                <button type="submit" class="btn-primary w-full justify-center">
                    この接続先で確定
                </button>
                <p class="canovia-space-station-help">
                    確定するまでPlan / Task / Evidence等の正規データは作りません。
                </p>
            </form>
        </section>
    @endif

    <section class="canovia-space-station-section">
        <div class="canovia-space-station-section-head">
            <div>
                <p class="canovia-map-companion-kicker">COMPANION</p>
                <h3>ここから相談する</h3>
            </div>
        </div>

        @auth
            @if ($canUseCompanion)
                <form method="POST" action="{{ route('companion.entry') }}" data-mutation-once data-map-companion-form>
                    @csrf
                    <input type="hidden" name="entry_type" value="global">
                    <input type="hidden" name="source_path" value="{{ $stationSourcePath }}">
                    <input type="hidden" name="source_route" value="map.index">
                    <button type="submit" class="btn-secondary w-full justify-center">
                        ✦ Companionへ相談
                    </button>
                </form>
            @else
                <a href="{{ route('companion.index') }}" class="btn-secondary w-full justify-center">
                    Companionを開く
                </a>
            @endif
        @else
            <a href="{{ route('chat.index') }}" class="btn-secondary w-full justify-center">
                AI Chatを開く
            </a>
        @endauth

        <a href="{{ route('inbox.index') }}" class="btn-secondary w-full justify-center">
            Inboxをすべて見る
        </a>
    </section>
</div>
