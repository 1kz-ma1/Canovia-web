@inject('providerCapabilityService', 'App\\Services\\DevelopmentOperationalProviderCapabilityService')
@inject('operatorHandoffService', 'App\\Services\\DevelopmentOperationalOperatorHandoffService')
@inject('operationalAttestationService', 'App\\Services\\DevelopmentOperationalAttestationService')
@inject('operatorReadinessService', 'App\\Services\\DevelopmentOperationalOperatorReadinessService')
@php
    $operationalTarget = $developmentOperationalTarget ?? null;
    $providerCapability = $providerCapabilityService->status($operationalTarget);
    $operatorHandoff = $operatorHandoffService->build($plan, $operationalTarget, $developmentOperationalProfile ?? null);
    $serviceIdentityCheck = $operationalAttestationService->projectServiceIdentity($plan, $operationalTarget);
    $operatorReadiness = $operatorReadinessService->summarize($plan, $developmentOperationalProfile ?? null, $operationalTarget);
    $operationalStore = data_get($developmentOperationalProfile, 'data_store', 'unknown');
    $canAttestRecovery = in_array($operationalStore, ['server_db', 'local_device', 'cloud_files', 'mixed'], true);
@endphp

<details class="rounded-xl border border-white/8 bg-slate-950/20 px-3 py-2.5"
    data-development-operational-attestations>
    <summary class="cursor-pointer text-xs font-semibold text-slate-400">
        対象環境と復旧テストの実施記録（任意）
    </summary>
    <p class="mt-3 text-[11px] leading-5 text-slate-500">
        外部サービスとの接続ではありません。対象の識別子と実施した事実だけを、このPlanの所有者の申告として記録します。
        パスワード・接続URL・秘密鍵・DB内容・バックアップファイルは入力しないでください。
        記録しても「外部システムで検証済み」にはなりません。
    </p>
    <form method="POST"
        action="{{ route('workspace.development.operational_target.update', $plan) }}"
        class="mt-3 space-y-2" data-development-operational-target-form>
        @csrf
        <input type="hidden" name="action" value="set_target">
        <div class="grid gap-2 sm:grid-cols-3">
            <label class="text-[11px] font-semibold text-slate-300">
                サービス種別
                <select name="provider" class="mt-1 w-full rounded-lg border border-white/10 bg-slate-950 p-2 text-xs text-slate-100">
                    <option value="render" @selected(data_get($operationalTarget, 'provider', 'render') === 'render')>Render</option>
                    <option value="other" @selected(data_get($operationalTarget, 'provider') === 'other')>その他</option>
                </select>
            </label>
            <label class="text-[11px] font-semibold text-slate-300">
                対象の種類
                <select name="environment" class="mt-1 w-full rounded-lg border border-white/10 bg-slate-950 p-2 text-xs text-slate-100">
                    <option value="staging" @selected(data_get($operationalTarget, 'environment') === 'staging')>検証・Staging</option>
                    <option value="production" @selected(data_get($operationalTarget, 'environment') === 'production')>本番・Production</option>
                </select>
            </label>
            <label class="text-[11px] font-semibold text-slate-300">
                非機密のサービス識別子
                <input name="resource_ref" type="text"
                    required minlength="5" maxlength="80"
                    pattern="[A-Za-z0-9][A-Za-z0-9_-]{4,79}"
                    autocomplete="off"
                    placeholder="srv-... など"
                    value="{{ old('resource_ref', data_get($operationalTarget, 'resource_ref', '')) }}"
                    class="mt-1 w-full rounded-lg border border-white/10 bg-slate-950 p-2 text-xs text-slate-100">
            </label>
        </div>
        @if ($errors->has('provider') || $errors->has('environment') || $errors->has('resource_ref'))
            <p class="text-xs text-rose-300" role="alert">対象環境の選択と識別子を確認してください。</p>
        @endif
        <div class="flex justify-end">
            <button type="submit" class="btn-secondary min-h-10 px-4 text-xs"
                data-development-operational-target-save>対象環境を登録・更新</button>
        </div>
    </form>

    @if ($operationalTarget)
        <div class="mt-3 border-t border-white/8 pt-3">
            <p class="text-[11px] leading-5 text-slate-400">
                登録先：{{ data_get($operationalTarget, 'provider') }} /
                {{ data_get($operationalTarget, 'environment') }}
                · 自己申告・外部サービス未接続
            </p>
            @if ($operatorReadiness)
                <section class="mt-3 rounded-lg border border-white/10 bg-slate-950/30 p-3"
                    aria-label="運用確認の自己申告一覧"
                    data-development-operator-summary>
                    <p class="text-xs font-semibold text-slate-200">公開準備の手動確認記録</p>
                    <p class="mt-1 text-[11px] leading-5 text-slate-400">
                        {{ $operatorReadiness['basis'] }}
                    </p>
                    <div class="mt-2 space-y-2">
                        @foreach ($operatorReadiness['checks'] as $check)
                            <div class="rounded-md border border-white/10 px-2.5 py-2"
                                data-development-operator-check="{{ $check['key'] }}"
                                data-development-operator-status="{{ $check['status'] }}">
                                <div class="flex flex-wrap items-baseline justify-between gap-2">
                                    <span class="text-[11px] font-semibold text-slate-200">{{ $check['label'] }}</span>
                                    <span class="text-[11px] text-slate-400">
                                        @switch ($check['status'])
                                            @case ('operator_confirmed')
                                                所有者の申告あり
                                                @break
                                            @case ('stale')
                                                要再確認
                                                @break
                                            @case ('not_applicable')
                                                申告上は対象外
                                                @break
                                            @default
                                                未記録
                                        @endswitch
                                    </span>
                                </div>
                                <p class="mt-1 text-[11px] leading-5 text-slate-400">{{ $check['detail'] }}</p>
                                @if ($check['recorded_at'])
                                    <p class="mt-1 text-[10px] text-slate-500">前回の申告：{{ $check['recorded_at'] }}</p>
                                @endif
                                <p class="mt-1 text-[10px] text-slate-500">{{ $check['source'] }}</p>
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-3 border-t border-white/10 pt-2"
                        data-development-operator-next="{{ $operatorReadiness['next']['key'] }}">
                        <p class="text-[11px] font-semibold text-slate-200">{{ $operatorReadiness['next']['title'] }}</p>
                        <p class="mt-1 text-[11px] leading-5 text-slate-400">{{ $operatorReadiness['next']['detail'] }}</p>
                    </div>
                </section>
            @endif
            <div class="mt-2 rounded-lg border border-white/10 bg-slate-950/30 px-3 py-2"
                data-development-provider-connection-state="{{ $providerCapability['state'] }}" role="status">
                <p class="text-[11px] font-semibold text-slate-300">外部サービスの自動照合：未接続</p>
                @if ($providerCapability['provider'] === 'render')
                    <p class="mt-1 text-[11px] leading-5 text-slate-400">
                        RenderのAPIキーは所属Workspace全体にアクセスでき、公式MCPのOAuthも変更操作を含みます。
                        安全な読み取り専用の認可方法が確認できるまで、Canoviaからの接続や自動検証は提供しません。
                        APIキーやトークンは入力しないでください。
                    </p>
                @else
                    <p class="mt-1 text-[11px] leading-5 text-slate-400">
                        このProviderの認証・権限確認は未対応です。登録内容は自己申告として扱います。
                    </p>
                @endif
            </div>
            @if ($operatorHandoff)
                <details class="mt-3 rounded-lg border border-white/10 px-3 py-2"
                    data-development-render-operator-handoff>
                    <summary class="cursor-pointer text-xs font-semibold text-slate-300">
                        Renderで安全に手動確認する（認証情報不要）
                    </summary>
                    <p class="mt-2 text-[11px] leading-5 text-slate-400">
                        この案内は確認手順です。CanoviaはRenderにアクセスせず、結果を取得・保存していません。
                        操作の前に、Render上のWorkspaceと登録サービスが一致するか確認してください。
                    </p>
                    <div class="mt-2 text-[11px] text-slate-300">
                        照合対象ID：
                        <code class="break-all select-all" data-development-render-target-id>{{ $operatorHandoff['resource_ref'] }}</code>
                        <span class="ml-1 text-slate-500">（{{ $operatorHandoff['environment'] }}・所有者の申告）</span>
                    </div>
                    <a href="https://dashboard.render.com/" target="_blank" rel="noopener noreferrer"
                        class="mt-2 inline-flex min-h-10 items-center text-xs font-semibold text-sky-300 underline underline-offset-4"
                        data-development-render-dashboard-link>Renderダッシュボードを開く（別タブ）</a>
                    <ol class="mt-2 list-decimal space-y-2 pl-4">
                        @foreach ($operatorHandoff['steps'] as $step)
                            <li class="text-[11px] leading-5 text-slate-300"
                                data-development-render-step="{{ $step['key'] }}">
                                <span class="font-semibold">{{ $step['title'] }}</span>
                                <p class="text-slate-400">{{ $step['detail'] }}</p>
                            </li>
                        @endforeach
                    </ol>
                    @if ($serviceIdentityCheck)
                        <div class="mt-3 rounded-lg border border-white/10 p-3"
                            data-development-service-check-status="{{ $serviceIdentityCheck['status'] }}">
                            <p class="text-[11px] font-semibold text-slate-300">
                                手動のサービス環境照合：
                                @if ($serviceIdentityCheck['status'] === 'operator_confirmed')
                                    所有者が確認済み（外部検証なし）
                                @elseif ($serviceIdentityCheck['status'] === 'stale')
                                    要再確認
                                @else
                                    未記録
                                @endif
                            </p>
                            <p class="mt-1 text-[11px] leading-5 text-slate-400">{{ $serviceIdentityCheck['detail'] }}</p>
                            @if (isset($serviceIdentityCheck['checkedAt']))
                                <p class="mt-1 text-[10px] text-slate-500">前回の申告：{{ $serviceIdentityCheck['checkedAt'] }}</p>
                            @endif
                            <form method="POST"
                                action="{{ route('workspace.development.operational_target.update', $plan) }}"
                                class="mt-2 flex flex-wrap items-center gap-2">
                                @csrf
                                <input type="hidden" name="action" value="confirm_service">
                                <input type="hidden" name="target_revision" value="{{ $operationalTarget->revision }}">
                                <label class="flex items-start gap-2 text-[11px] text-slate-300">
                                    <input type="checkbox" name="attest" value="yes" required class="mt-0.5">
                                    Render画面でこのサービスIDと環境の対応を自分で確認した（不一致なら先に登録情報を修正）
                                </label>
                                <button class="btn-secondary min-h-10 px-3 text-xs" type="submit"
                                    data-development-service-check-submit>手動照合を記録</button>
                            </form>
                            @if ($operationalTarget->service_checked_at)
                                <form method="POST"
                                    action="{{ route('workspace.development.operational_target.update', $plan) }}"
                                    class="mt-2">
                                    @csrf
                                    <input type="hidden" name="action" value="clear_service">
                                    <button class="text-[11px] text-slate-400 underline underline-offset-4"
                                        type="submit" data-development-service-check-clear>照合記録を取り消す</button>
                                </form>
                            @endif
                            <p class="mt-2 text-[10px] text-slate-500">
                                これは所有者の申告です。7日経過・対象Revision変更で古い記録と判定します。CI・デプロイ・復元検証を代替しません。
                            </p>
                        </div>
                    @endif
                    <p class="mt-2 text-[10px] leading-5 text-slate-500">
                        APIキー・トークン・環境変数・DB接続文字列・ログをCanoviaに貼り付けないでください。
                        実施を記録する場合は下の所有者申告を使います。申告しても独立検証済みにはなりません。
                    </p>
                </details>
            @endif
            @if ($canAttestRecovery)
                @foreach (['backup' => 'バックアップを取得した', 'restore' => '隔離環境で復元テストを実施した'] as $kind => $label)
                    <div class="mt-3 flex flex-wrap items-center gap-2" data-development-confirm-kind="{{ $kind }}">
                        <form method="POST"
                            action="{{ route('workspace.development.operational_target.update', $plan) }}"
                            class="flex flex-wrap items-center gap-2">
                            @csrf
                            <input type="hidden" name="action" value="confirm_{{ $kind }}">
                            <input type="hidden" name="target_revision" value="{{ $operationalTarget->revision }}">
                            <input type="hidden" name="expected_data_store" value="{{ $operationalStore }}">
                            <input type="hidden" name="expected_data_store_revision" value="{{ (int) data_get($developmentOperationalProfile, 'data_store_revision', 0) }}">
                            <label class="flex items-start gap-2 text-[11px] text-slate-300">
                                <input type="checkbox" name="attest" value="yes" required class="mt-0.5">
                                {{ $label }}ことを自分で確認した
                            </label>
                            <button class="btn-secondary min-h-10 px-3 text-xs" type="submit"
                                data-development-confirm-submit="{{ $kind }}">実施を記録</button>
                        </form>
                        @if (data_get($operationalTarget, $kind.'_confirmed_at'))
                            <form method="POST" action="{{ route('workspace.development.operational_target.update', $plan) }}">
                                @csrf
                                <input type="hidden" name="action" value="clear_{{ $kind }}">
                                <button class="text-[11px] text-slate-400 underline underline-offset-4"
                                    type="submit" data-development-confirm-clear="{{ $kind }}">記録を取り消す</button>
                            </form>
                        @endif
                    </div>
                @endforeach
                <p class="mt-2 text-[10px] text-slate-500">
                    バックアップは7日、復元テストは90日経過後に再確認を促します。対象環境や保存方式を変えた場合も過去の申告は古いものとして扱います。
                </p>
            @else
                <p class="mt-2 text-[11px] text-slate-500">
                    実施記録を残すには、上の「開発環境と保存方式」で永続データの保存先を登録してください。
                    データ保存がなければ復旧作業は必須にしません。
                </p>
            @endif
        </div>
    @endif
</details>
