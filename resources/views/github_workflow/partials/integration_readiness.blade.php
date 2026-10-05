@php
    $integration = (array) ($github_integration_status ?? []);
    $evidence = (array) data_get($integration, 'evidence', []);
    $write = (array) data_get($integration, 'write', []);
    $runtime = (array) data_get($integration, 'runtime', []);

    $evidenceAllowed = (bool) ($evidence['allowed'] ?? false);
    $writeAllowed = (bool) ($write['allowed'] ?? false);
    $appConfigured = (bool) ($runtime['app_configured'] ?? false);
    $installConfigured = (bool) ($runtime['install_url_configured'] ?? false);
    $webhookConfigured = (bool) ($runtime['webhook_configured'] ?? false);
    $asyncQueueConfigured = (bool) ($runtime['async_queue_configured'] ?? false);

    $autoSyncConfigured = (bool) (
        $runtime['automatic_return_sync_configured']
        ?? false
    );

    $cardClass = static fn (bool $ok) => $ok
        ? 'border-emerald-300/15 bg-emerald-300/[0.025]'
        : 'border-amber-300/15 bg-amber-300/[0.025]';
@endphp

<section
    class="rounded-3xl border border-cyan-300/15 bg-slate-950/45 p-4 md:p-5"
    data-github-integration-readiness
>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-[10px] font-black uppercase tracking-[0.16em] text-cyan-300">INTEGRATION READINESS</p>
            <h2 class="mt-1 text-lg font-black text-slate-100">GitHub連携のどこまで使えるか</h2>
            <p class="mt-2 max-w-3xl text-xs leading-6 text-slate-500">
                Repository閲覧、GitHub AppによるReview Write、自動Return Syncは別Capabilityです。
                1つが未設定でも、使える範囲までを止めません。
            </p>
        </div>
        <span class="badge badge-slate">Queue: {{ strtoupper((string) ($runtime['queue_driver'] ?? 'unknown')) }}</span>
    </div>

    <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <article class="rounded-2xl border p-4 {{ $cardClass($evidenceAllowed) }}">
            <p class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-500">REPOSITORY / EVIDENCE</p>
            <p class="mt-2 text-sm font-black {{ $evidenceAllowed ? 'text-emerald-100' : 'text-amber-100' }}">
                {{ $evidenceAllowed ? '利用可能' : '利用不可' }}
            </p>
            <p class="mt-2 text-[11px] leading-5 text-slate-500">{{ $evidence['message'] ?? '' }}</p>
        </article>

        <article class="rounded-2xl border p-4 {{ $cardClass($writeAllowed) }}">
            <p class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-500">REVIEW WRITE</p>
            <p class="mt-2 text-sm font-black {{ $writeAllowed ? 'text-emerald-100' : 'text-amber-100' }}">
                {{ $writeAllowed ? 'Capabilityあり' : 'Capabilityなし' }}
            </p>
            <p class="mt-2 text-[11px] leading-5 text-slate-500">{{ $write['message'] ?? '' }}</p>
        </article>

        <article class="rounded-2xl border p-4 {{ $cardClass($appConfigured && $installConfigured) }}">
            <p class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-500">GITHUB APP SERVER</p>
            <p class="mt-2 text-sm font-black {{ $appConfigured && $installConfigured ? 'text-emerald-100' : 'text-amber-100' }}">
                {{ $appConfigured && $installConfigured ? '接続導線Ready' : '運営設定が必要' }}
            </p>
            <div class="mt-2 space-y-1 text-[11px] text-slate-500">
                <p>App credential: {{ $appConfigured ? '✓' : '未設定' }}</p>
                <p>Install URL: {{ $installConfigured ? '✓' : '未設定' }}</p>
            </div>
        </article>

        <article class="rounded-2xl border p-4 {{ $cardClass($autoSyncConfigured) }}">
            <p class="text-[10px] font-black uppercase tracking-[0.12em] text-slate-500">AUTO RETURN SYNC</p>
            <p class="mt-2 text-sm font-black {{ $autoSyncConfigured ? 'text-emerald-100' : 'text-amber-100' }}">
                {{ $autoSyncConfigured ? 'Server設定済み' : '運営設定が必要' }}
            </p>
            <div class="mt-2 space-y-1 text-[11px] text-slate-500">
                <p>Webhook Secret: {{ $webhookConfigured ? '✓' : '未設定' }}</p>
                <p>Async Queue: {{ $asyncQueueConfigured ? '✓' : '未設定' }}</p>
                <p>Worker: 実deliveryで別途稼働確認</p>
            </div>
        </article>
    </div>

    @if (! $writeAllowed && in_array(($write['reason'] ?? ''), ['admin_preview_free', 'admin_preview_premium'], true))
        <div class="mt-4 rounded-2xl border border-violet-300/15 bg-violet-300/[0.03] p-4 text-xs leading-6 text-slate-400">
            <strong class="text-violet-100">Admin PreviewがGitHub Writeを制限しています。</strong>
            実際のGitHub App接続確認をするときは、管理者メニューの表示モードをSuper Adminへ戻してください。
        </div>
    @endif
</section>
