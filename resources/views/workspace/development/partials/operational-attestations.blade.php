@inject('providerCapabilityService', 'App\\Services\\DevelopmentOperationalProviderCapabilityService')
@inject('operatorHandoffService', 'App\\Services\\DevelopmentOperationalOperatorHandoffService')
@php
    $operationalTarget = $developmentOperationalTarget ?? null;
    $providerCapability = $providerCapabilityService->status($operationalTarget);
    $operatorHandoff = $operatorHandoffService->build($plan, $operationalTarget, $developmentOperationalProfile ?? null);
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
