@extends('layouts.app')

@section('title', 'GitHub Integration Diagnostics | Canovia')

@section('content')
    @php
        $overall = (string) data_get($snapshot, 'overall_state', 'unverified');
        $configuration = (array) data_get($snapshot, 'configuration', []);
        $queue = (array) data_get($snapshot, 'queue', []);
        $deliveries = (array) data_get($snapshot, 'deliveries', []);
        $schema = (array) data_get($snapshot, 'schema', []);
        $worker = (array) data_get($queue, 'worker_observation', []);
        $repositories = collect(data_get($snapshot, 'repositories.items', []));
        $recentDeliveries = collect(data_get($snapshot, 'recent_deliveries', []));
        $operatorSteps = collect(data_get($snapshot, 'operator_steps', []));
        $diagnosticErrors = collect(data_get($snapshot, 'diagnostic_errors', []));
        $schemaReady = (bool) data_get($schema, 'webhook_deliveries_table')
            && (bool) data_get($schema, 'plan_artifacts_table')
            && ((string) data_get($configuration, 'queue_driver', 'sync') !== 'database'
                || (bool) data_get($schema, 'jobs_table'));

        $overallLabel = match ($overall) {
            'healthy' => '動作確認済み',
            'attention' => '要確認',
            'setup_required' => '初回設定が必要',
            default => '稼働未確認',
        };

        $overallClass = match ($overall) {
            'healthy' => 'border-emerald-300/20 bg-emerald-300/[0.04] text-emerald-200',
            'attention' => 'border-rose-300/20 bg-rose-300/[0.04] text-rose-200',
            'setup_required' => 'border-amber-300/20 bg-amber-300/[0.04] text-amber-200',
            default => 'border-slate-700 bg-slate-900/50 text-slate-300',
        };

        $statusClass = fn (bool $ok) => $ok
            ? 'border-emerald-300/15 bg-emerald-300/[0.035] text-emerald-200'
            : 'border-amber-300/15 bg-amber-300/[0.035] text-amber-200';

        $deliveryStatusClass = fn (string $status) => match ($status) {
            'processed' => 'text-emerald-200',
            'failed' => 'text-rose-200',
            'accepted', 'processing' => 'text-amber-200',
            default => 'text-slate-400',
        };
    @endphp

    <div class="mx-auto max-w-7xl space-y-6">
        @include('admin.partials.nav')

        <header class="rounded-[1.6rem] border border-cyan-300/15 bg-slate-950/55 p-5 shadow-[0_20px_60px_rgba(2,6,23,.22)] sm:p-7">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div class="max-w-3xl">
                    <p class="text-[10px] font-black uppercase tracking-[.18em] text-cyan-300">GITHUB INTEGRATION DIAGNOSTICS</p>
                    <h1 class="mt-2 text-2xl font-black tracking-tight text-slate-50 sm:text-3xl">GitHub連携の本番状態を確認</h1>
                    <p class="mt-2 text-sm leading-6 text-slate-400">
                        GitHub App設定、Webhook受付、Queue滞留、Return Evidence処理、Repository接続を1画面で確認します。
                        Secret・Private Key・Webhook本文は表示しません。
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="rounded-full border px-3 py-1.5 text-xs font-black {{ $overallClass }}">{{ $overallLabel }}</span>
                    <a href="{{ route('admin.github.index') }}" class="btn-secondary">再読込</a>
                </div>
            </div>
        </header>

        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ([
                [
                    'label' => 'GITHUB APP',
                    'ok' => (bool) data_get($configuration, 'app_configured'),
                    'value' => data_get($configuration, 'app_configured') ? 'Credential設定済み' : '未設定',
                    'note' => 'App ID + Private Key',
                ],
                [
                    'label' => 'INSTALL URL',
                    'ok' => (bool) data_get($configuration, 'install_url_configured'),
                    'value' => data_get($configuration, 'install_url_configured') ? '設定済み' : '未設定',
                    'note' => 'Repository選択へ進むGitHub App URL',
                ],
                [
                    'label' => 'WEBHOOK',
                    'ok' => (bool) data_get($configuration, 'webhook_configured'),
                    'value' => data_get($configuration, 'webhook_configured') ? 'Secret設定済み' : '未設定',
                    'note' => 'X-Hub-Signature-256',
                ],
                [
                    'label' => 'ASYNC QUEUE',
                    'ok' => (bool) data_get($configuration, 'async_queue_configured'),
                    'value' => strtoupper((string) data_get($configuration, 'queue_driver', 'unknown')),
                    'note' => 'sync/nullは本番自動同期に不向き',
                ],
                [
                    'label' => 'DB SCHEMA',
                    'ok' => $schemaReady,
                    'value' => $schemaReady ? 'Ready' : '要確認',
                    'note' => (string) data_get($schema, 'webhook_deliveries_table_name', 'github_webhook_deliveries'),
                ],
                [
                    'label' => 'CONNECTED REPOS',
                    'ok' => (int) data_get($snapshot, 'repositories.connected_count', 0) > 0,
                    'value' => (int) data_get($snapshot, 'repositories.connected_count', 0).' repositories',
                    'note' => 'Canovia GitHub App接続済み',
                ],
            ] as $item)
                <article class="rounded-2xl border p-4 {{ $statusClass((bool) $item['ok']) }}">
                    <p class="text-[10px] font-black tracking-[.14em] opacity-80">{{ $item['label'] }}</p>
                    <p class="mt-2 text-xl font-black">{{ $item['value'] }}</p>
                    <p class="mt-1 text-[10px] leading-5 text-slate-500">{{ $item['note'] }}</p>
                </article>
            @endforeach
        </section>

        @if ($diagnosticErrors->isNotEmpty())
            <section class="rounded-2xl border border-rose-300/15 bg-rose-300/[0.03] p-4" data-github-diagnostic-errors>
                <p class="text-xs font-black text-rose-100">診断中に読み取れなかった項目があります</p>
                <div class="mt-3 space-y-2">
                    @foreach ($diagnosticErrors as $error)
                        <div class="rounded-xl border border-rose-300/10 bg-slate-950/25 p-3">
                            <p class="text-[10px] font-black uppercase tracking-[.1em] text-rose-200">{{ data_get($error, 'area', 'unknown') }}</p>
                            <p class="mt-1 text-xs leading-5 text-slate-400">{{ data_get($error, 'message') }}</p>
                        </div>
                    @endforeach
                </div>
                <p class="mt-3 text-[10px] leading-5 text-slate-600">
                    診断画面自体は500にせず、欠けているSchemaやruntime状態をここへ表示します。
                </p>
            </section>
        @endif

        <section class="grid gap-5 xl:grid-cols-[1.05fr_.95fr]">
            <article class="page-card p-5 sm:p-6">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-[.14em] text-cyan-300">WEBHOOK ENDPOINT</p>
                        <h2 class="mt-1 text-xl font-black text-slate-50">GitHub App側へ設定するURL</h2>
                    </div>
                    <span class="badge badge-slate">運営設定</span>
                </div>

                <div class="mt-4 rounded-2xl border border-slate-800 bg-slate-950/45 p-4">
                    <code class="break-all text-xs text-cyan-100">{{ route('api.github.webhook') }}</code>
                </div>

                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    <div class="rounded-xl border border-slate-800 bg-slate-950/30 p-4">
                        <p class="text-xs font-black text-slate-200">GitHub App</p>
                        <p class="mt-2 text-[11px] leading-5 text-slate-500">
                            WebhookをActiveにし、このURLとRender等に同じWebhook Secretを設定します。
                        </p>
                    </div>
                    <div class="rounded-xl border border-slate-800 bg-slate-950/30 p-4">
                        <p class="text-xs font-black text-slate-200">Queue Worker</p>
                        <code class="mt-2 block break-all text-[10px] leading-5 text-slate-400">php artisan queue:work --sleep=1 --tries=4 --timeout=90</code>
                    </div>
                </div>
            </article>

            <article class="page-card p-5 sm:p-6">
                <p class="text-[10px] font-black uppercase tracking-[.14em] text-violet-300">WORKER OBSERVATION</p>
                <div class="mt-2 flex items-center justify-between gap-3">
                    <h2 class="text-xl font-black text-slate-50">{{ data_get($worker, 'label', '稼働未確認') }}</h2>
                    <span class="badge badge-slate">{{ strtoupper((string) data_get($worker, 'state', 'unknown')) }}</span>
                </div>
                <p class="mt-3 text-xs leading-6 text-slate-500">{{ data_get($worker, 'note') }}</p>

                <div class="mt-5 grid grid-cols-2 gap-3">
                    <div class="rounded-xl border border-slate-800 bg-slate-950/30 p-3">
                        <p class="text-[10px] text-slate-500">GitHub Queue待ち</p>
                        <p class="mt-1 text-2xl font-black text-slate-100">{{ (int) data_get($queue, 'pending_github_jobs', 0) }}</p>
                    </div>
                    <div class="rounded-xl border border-slate-800 bg-slate-950/30 p-3">
                        <p class="text-[10px] text-slate-500">Failed Job</p>
                        <p class="mt-1 text-2xl font-black {{ (int) data_get($queue, 'failed_github_jobs', 0) > 0 ? 'text-rose-200' : 'text-slate-100' }}">
                            {{ (int) data_get($queue, 'failed_github_jobs', 0) }}
                        </p>
                    </div>
                </div>

                @if (data_get($queue, 'oldest_pending_at'))
                    <p class="mt-3 text-[10px] text-slate-600">
                        最古のGitHub Queue Job: {{ data_get($queue, 'oldest_pending_at')->format('Y-m-d H:i:s') }}
                    </p>
                @endif
            </article>
        </section>

        <section class="page-card p-5 sm:p-6">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-[.14em] text-emerald-300">DELIVERY HEALTH</p>
                    <h2 class="mt-1 text-xl font-black text-slate-50">Webhook delivery</h2>
                    <p class="mt-2 text-xs leading-5 text-slate-500">
                        GitHubから受けたraw本文は保持せず、routingと処理状態だけを確認します。
                    </p>
                </div>
                <div class="text-right text-[10px] leading-5 text-slate-600">
                    <p>Last received: {{ data_get($deliveries, 'last_received_at') ?: '—' }}</p>
                    <p>Last processed: {{ data_get($deliveries, 'last_processed_at') ?: '—' }}</p>
                </div>
            </div>

            <div class="mt-5 grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
                @foreach ([
                    ['Total', 'total', 'text-slate-100'],
                    ['Accepted', 'accepted', 'text-amber-100'],
                    ['Processing', 'processing', 'text-amber-200'],
                    ['Processed', 'processed', 'text-emerald-200'],
                    ['Ignored', 'ignored', 'text-slate-400'],
                    ['Failed', 'failed', 'text-rose-200'],
                ] as [$label, $key, $class])
                    <div class="rounded-xl border border-slate-800 bg-slate-950/30 p-3">
                        <p class="text-[10px] text-slate-500">{{ $label }}</p>
                        <p class="mt-1 text-xl font-black {{ $class }}">{{ (int) data_get($deliveries, $key, 0) }}</p>
                    </div>
                @endforeach
            </div>

            @if ((int) data_get($deliveries, 'stuck', 0) > 0)
                <div class="mt-4 rounded-xl border border-rose-300/15 bg-rose-300/[0.035] p-3 text-xs leading-5 text-rose-100">
                    5分以上 accepted / processing のまま残っているdeliveryが {{ (int) data_get($deliveries, 'stuck') }} 件あります。Queue Workerを確認してください。
                </div>
            @endif

            <div class="mt-5 overflow-x-auto">
                <table class="w-full min-w-[920px] text-left text-xs">
                    <thead class="uppercase tracking-[.08em] text-slate-600">
                        <tr>
                            <th class="pb-3 pr-4">受信</th>
                            <th class="pb-3 pr-4">Repository</th>
                            <th class="pb-3 pr-4">Event</th>
                            <th class="pb-3 pr-4">PR</th>
                            <th class="pb-3 pr-4">Status</th>
                            <th class="pb-3 pr-4">Sync</th>
                            <th class="pb-3">Attempts</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800">
                        @forelse ($recentDeliveries as $delivery)
                            <tr>
                                <td class="py-3 pr-4 text-slate-500">{{ data_get($delivery, 'received_at')?->format('m/d H:i:s') }}</td>
                                <td class="py-3 pr-4 font-semibold text-slate-300">{{ data_get($delivery, 'repo_full_name') }}</td>
                                <td class="py-3 pr-4 text-slate-400">
                                    {{ data_get($delivery, 'event_name') }}
                                    @if (filled(data_get($delivery, 'action')))
                                        <span class="text-slate-600">/ {{ data_get($delivery, 'action') }}</span>
                                    @endif
                                </td>
                                <td class="py-3 pr-4 text-slate-400">
                                    {{ collect(data_get($delivery, 'pull_request_numbers', []))->map(fn ($n) => '#'.$n)->implode(', ') ?: '—' }}
                                </td>
                                <td class="py-3 pr-4 font-bold {{ $deliveryStatusClass((string) data_get($delivery, 'status')) }}">
                                    {{ data_get($delivery, 'status') }}
                                    @if (data_get($delivery, 'has_error'))
                                        <span class="text-rose-300"> · error</span>
                                    @endif
                                </td>
                                <td class="py-3 pr-4 text-slate-400">
                                    {{ (int) data_get($delivery, 'synced_tasks', 0) }} task
                                    @if ((int) data_get($delivery, 'skipped_entitlement', 0) > 0)
                                        <span class="text-amber-300"> / entitlement skip {{ (int) data_get($delivery, 'skipped_entitlement') }}</span>
                                    @endif
                                </td>
                                <td class="py-3 text-slate-500">{{ (int) data_get($delivery, 'attempts', 0) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="py-8 text-center text-slate-600">まだWebhook deliveryはありません。</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="grid gap-5 xl:grid-cols-[1.05fr_.95fr]">
            <article class="page-card p-5 sm:p-6">
                <p class="text-[10px] font-black uppercase tracking-[.14em] text-sky-300">CONNECTED REPOSITORIES</p>
                <h2 class="mt-1 text-xl font-black text-slate-50">Canovia GitHub App接続先</h2>

                <div class="mt-4 space-y-2">
                    @forelse ($repositories as $repository)
                        <div class="rounded-xl border border-slate-800 bg-slate-950/30 p-4">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <p class="text-sm font-black text-slate-200">{{ data_get($repository, 'repo_full_name') }}</p>
                                    <p class="mt-1 text-[10px] text-slate-600">{{ data_get($repository, 'plan_title') }}</p>
                                </div>
                                <span class="badge badge-green">connected</span>
                            </div>
                        </div>
                    @empty
                        <div class="rounded-xl border border-slate-800 bg-slate-950/30 p-4 text-xs leading-5 text-slate-500">
                            接続済みRepositoryはまだありません。対象RepositoryへCanovia GitHub Appをinstallするとここに表示されます。
                        </div>
                    @endforelse
                </div>
            </article>

            <article class="page-card p-5 sm:p-6">
                <p class="text-[10px] font-black uppercase tracking-[.14em] text-amber-300">OPERATOR CHECKLIST</p>
                <h2 class="mt-1 text-xl font-black text-slate-50">本番有効化までの作業</h2>

                <div class="mt-4 space-y-2">
                    @foreach ($operatorSteps as $check)
                        <div class="rounded-xl border border-slate-800 bg-slate-950/30 p-3">
                            <div class="flex items-start gap-3">
                                <span class="mt-0.5 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full border text-[10px] font-black {{ data_get($check, 'done') ? 'border-emerald-300/25 bg-emerald-300/10 text-emerald-200' : 'border-slate-700 text-slate-500' }}">
                                    {{ data_get($check, 'done') ? '✓' : '·' }}
                                </span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="text-xs font-black {{ data_get($check, 'done') ? 'text-slate-300' : 'text-slate-400' }}">{{ data_get($check, 'label') }}</p>
                                        <span class="badge badge-slate">{{ data_get($check, 'owner') }}</span>
                                    </div>
                                    <p class="mt-1 text-[10px] leading-5 text-slate-600">{{ data_get($check, 'detail') }}</p>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <p class="mt-4 text-[10px] leading-5 text-slate-600">
                    Workerの「未確認」は異常確定ではありません。Webhook trafficがない時間帯はprocessの生存をこのDB情報だけから断定しない設計です。
                </p>
            </article>
        </section>
    </div>
@endsection
