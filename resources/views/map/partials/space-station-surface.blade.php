@php
    $station = $graph['space_station'] ?? [];
    $latestItem = $station['latest_item'] ?? null;
    $candidate = $station['routing_candidate'] ?? null;
    $destinations = $station['routing_destinations'] ?? [];
    $executionActorTypes = $station['execution_actor_types'] ?? [];
    $editablePlans = collect($station['editable_plans'] ?? []);
    $contextCandidate = $station['context_candidate'] ?? null;
    $routeResult = $station['route_result'] ?? null;
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

    $selectedDestination = (string) old('destination', $candidate['destination'] ?? 'keep_inbox');
    $planDestinations = ['career_capture', 'recall_material', 'task_evidence', 'plan_resource', 'execution_request'];
    $taskDestinations = ['recall_material', 'task_evidence', 'execution_request'];
    $destinationNeedsPlan = in_array($selectedDestination, $planDestinations, true);
    $destinationNeedsTask = in_array($selectedDestination, $taskDestinations, true);
    $contextPlanId = (int) data_get($contextCandidate, 'plan_id', 0);
    $selectedPlanId = (string) old('plan_id', $contextPlanId > 0 ? $contextPlanId : '');
    $selectedTaskId = (string) old('task_id', '');
    $flowStep = $routeResult
        ? 4
        : ($latestItem
            ? ($candidate ? 3 : 2)
            : 1);
@endphp

<div
    class="canovia-space-station-panel"
    data-space-station-panel
    data-space-station-flow-step="{{ $flowStep }}"
>
    <div class="canovia-space-station-flow" data-space-station-flow aria-label="Space Station intake flow">
        @foreach ([
            1 => ['受信', 'INPUT'],
            2 => ['解釈', 'INTERPRET'],
            3 => ['接続', 'CONNECT'],
            4 => ['Action', 'ACTION'],
        ] as $step => [$label, $caption])
            <div
                class="canovia-space-station-flow-step {{ $flowStep === $step ? 'is-active' : '' }} {{ $flowStep > $step ? 'is-complete' : '' }}"
                data-space-station-flow-stage="{{ $step }}"
                @if ($flowStep === $step) aria-current="step" @endif
            >
                <span class="canovia-space-station-flow-dot" aria-hidden="true">{{ $flowStep > $step ? '✓' : $step }}</span>
                <span>
                    <small>{{ $caption }}</small>
                    <strong>{{ $label }}</strong>
                </span>
            </div>
        @endforeach
    </div>

    @if ($routeResult)
        <section class="canovia-space-station-section is-result" data-space-station-route-result>
            <div class="canovia-space-station-section-head">
                <div>
                    <p class="canovia-map-companion-kicker">CONNECTED</p>
                    <h3>{{ $routeResult['destination_label'] }}へ接続しました</h3>
                </div>
                <span class="canovia-space-station-badge is-complete">確定済み</span>
            </div>

            @if (filled($routeResult['message'] ?? null))
                <p class="canovia-space-station-summary">{{ $routeResult['message'] }}</p>
            @endif

            <div class="canovia-space-station-result-path" aria-label="接続結果">
                <span>Space Station</span>
                <span aria-hidden="true">→</span>
                @if (filled($routeResult['plan_title'] ?? null))
                    <span>{{ $routeResult['plan_title'] }}</span>
                    <span aria-hidden="true">→</span>
                @endif
                @if (filled($routeResult['task_title'] ?? null))
                    <span>{{ $routeResult['task_title'] }}</span>
                    <span aria-hidden="true">→</span>
                @endif
                <strong>{{ $routeResult['destination_label'] }}</strong>
            </div>

            @if (filled($routeResult['action_url'] ?? null))
                <a
                    href="{{ $routeResult['action_url'] }}"
                    class="btn-primary w-full justify-center"
                    data-space-station-result-action
                >
                    {{ $routeResult['action_label'] }}
                </a>
            @endif
        </section>
    @endif

    <section class="canovia-space-station-section" data-space-station-intake>
        <div class="canovia-space-station-section-head">
            <div>
                <p class="canovia-map-companion-kicker">INPUT</p>
                <h3>まずはここへ投げる</h3>
            </div>
            <span class="canovia-space-station-badge">未整理 {{ $pendingCount }}件</span>
        </div>

        <p class="canovia-space-station-help is-leading">
            テキスト・URL・スクリーンショット・PDFをそのまま受け取ります。AIが使える場合は、保存後に接続候補まで自動で整理します。
        </p>

        <form
            method="POST"
            action="{{ route('inbox.store') }}"
            enctype="multipart/form-data"
            class="canovia-space-station-form"
            data-space-station-intake-form
            data-mutation-once
        >
            @csrf
            @include('map.partials.space-station-return-fields')
            <input type="hidden" name="intake_mode" value="chat">

            <div>
                <label for="space-station-content">入力</label>
                <textarea
                    id="space-station-content"
                    name="content"
                    rows="3"
                    maxlength="50000"
                    class="input-field w-full resize-y"
                    placeholder="例: PRがマージされた / 明日の予定が変わった / このスクショを見て / 次に何をすればいい？"
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
                Space Stationへ送って整理
            </button>

            <p class="canovia-space-station-help">
                Interpretationが走っても、Plan / Task / Evidence等への接続はまだ確定しません。
            </p>
        </form>
    </section>

    @if ($latestItem)
        <section class="canovia-space-station-section" data-space-station-latest>
            <div class="canovia-space-station-section-head">
                <div>
                    <p class="canovia-map-companion-kicker">INTERPRET</p>
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
                        <span>入力</span>
                        <span aria-hidden="true">→</span>
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
                        AIは意味と名前の候補だけを出します。Plan / Task IDは選ばず、接続は下で人が確定します。
                    </p>
                </div>
            @elseif ($canUseInboxAi)
                <form method="POST" action="{{ route('inbox.suggest', $latestItem) }}" data-mutation-once>
                    @csrf
                    @include('map.partials.space-station-return-fields')
                    <button type="submit" class="btn-secondary w-full justify-center">
                        ✦ 内容を読んで接続候補を作る
                    </button>
                </form>
            @else
                <p class="canovia-space-station-help">
                    AI Interpretationが利用できないため、下の接続先を手動で選べます。
                </p>
            @endif

            <div class="canovia-space-station-connect" data-space-station-connect>
                <div class="canovia-space-station-section-head is-compact">
                    <div>
                        <p class="canovia-map-companion-kicker">CONNECT</p>
                        <h3>どこにつなぐか確認</h3>
                    </div>
                    <span class="canovia-space-station-badge">人が確定</span>
                </div>

                @if ($contextCandidate)
                    <div class="canovia-space-station-context-candidate" data-space-station-context-candidate>
                        <span class="canovia-space-station-context-mark" aria-hidden="true">◎</span>
                        <div>
                            <small>現在のMap Context</small>
                            <strong>{{ $contextCandidate['plan_title'] }}</strong>
                            <p>Plan候補として初期選択しています。確定するまでは接続されません。</p>
                        </div>
                    </div>
                @endif

                <form
                    method="POST"
                    action="{{ route('inbox.route', $latestItem) }}"
                    class="canovia-space-station-form"
                    data-space-station-confirm
                    data-mutation-once
                >
                    @csrf
                    @include('map.partials.space-station-return-fields')

                    <div>
                        <label for="space-station-destination">接続先</label>
                        <select
                            id="space-station-destination"
                            name="destination"
                            class="input-field w-full"
                            data-space-station-destination
                            required
                        >
                            @foreach ($destinations as $key => $label)
                                <option value="{{ $key }}" @selected($selectedDestination === $key)>
                                    {{ $label }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div
                        data-space-station-plan-field
                        @if (! $destinationNeedsPlan) hidden @endif
                    >
                        <label for="space-station-plan">Plan</label>
                        <select
                            id="space-station-plan"
                            name="plan_id"
                            class="input-field w-full"
                            data-space-station-plan
                        >
                            <option value="">まだ選ばない</option>
                            @foreach ($editablePlans as $plan)
                                <option
                                    value="{{ $plan->id }}"
                                    @selected($selectedPlanId === (string) $plan->id)
                                >
                                    {{ $plan->title }}
                                </option>
                            @endforeach
                        </select>
                        @if (filled($candidate['suggested_plan_title'] ?? null))
                            <p class="canovia-space-station-help">AIの名前hint: {{ $candidate['suggested_plan_title'] }}</p>
                        @endif
                    </div>

                    <div
                        data-space-station-task-field
                        @if (! $destinationNeedsTask) hidden @endif
                    >
                        <label for="space-station-task">Task</label>
                        <select
                            id="space-station-task"
                            name="task_id"
                            class="input-field w-full"
                            data-space-station-task
                        >
                            <option value="">Task指定なし</option>
                            @foreach ($editablePlans as $plan)
                                @foreach ($plan->tasks as $task)
                                    <option
                                        value="{{ $task->id }}"
                                        data-space-station-task-option
                                        data-plan-id="{{ $plan->id }}"
                                        @selected($selectedTaskId === (string) $task->id)
                                    >
                                        {{ $plan->title }} / {{ $task->title }}
                                    </option>
                                @endforeach
                            @endforeach
                        </select>
                        @if (filled($candidate['suggested_task_title'] ?? null))
                            <p class="canovia-space-station-help">AIの名前hint: {{ $candidate['suggested_task_title'] }}</p>
                        @endif
                    </div>

                    <div
                        class="canovia-space-station-execution-fields"
                        data-space-station-execution-fields
                        @if ($selectedDestination !== 'execution_request') hidden @endif
                    >
                        <div>
                            <label for="space-station-execution-instruction">実行してほしいこと</label>
                            <textarea
                                id="space-station-execution-instruction"
                                name="execution_instruction"
                                rows="3"
                                maxlength="6000"
                                class="input-field w-full resize-y"
                                data-space-station-execution-instruction
                                @if ($selectedDestination === 'execution_request') required @endif
                                placeholder="例: このPRの結果を確認し、次に必要な修正を整理する"
                            >{{ old('execution_instruction') }}</textarea>
                        </div>

                        <div class="canovia-space-station-execution-grid">
                            <div>
                                <label for="space-station-execution-actor">担当</label>
                                <select
                                    id="space-station-execution-actor"
                                    name="execution_actor_type"
                                    class="input-field w-full"
                                >
                                    @foreach ($executionActorTypes as $key => $label)
                                        <option value="{{ $key }}" @selected(old('execution_actor_type', 'human_ai') === $key)>
                                            {{ $label }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            <div>
                                <label for="space-station-execution-minutes">使える時間</label>
                                <input
                                    id="space-station-execution-minutes"
                                    type="number"
                                    name="execution_available_minutes"
                                    value="{{ old('execution_available_minutes', 30) }}"
                                    min="5"
                                    max="1440"
                                    step="5"
                                    class="input-field w-full"
                                >
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn-primary w-full justify-center">
                        この接続先で確定
                    </button>
                    <p class="canovia-space-station-help">
                        確定するまでPlan / Task / Evidence等の正規データは作りません。Resource等も同様です。
                    </p>
                </form>
            </div>
        </section>
    @endif

    <section class="canovia-space-station-section is-companion">
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
