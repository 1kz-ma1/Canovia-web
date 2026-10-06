@extends('layouts.app')

@section('title', 'Economy Inspector | Canovia')

@section('content')
    <div class="mx-auto max-w-7xl space-y-6">
        @include('admin.partials.nav')

        <header class="rounded-[1.6rem] border border-cyan-300/15 bg-slate-950/55 p-5 sm:p-7">
            <p class="text-[10px] font-black uppercase tracking-[.18em] text-cyan-300">ECONOMY FOUNDATION</p>
            <h1 class="mt-2 text-2xl font-black text-slate-50 sm:text-3xl">Economy Inspector</h1>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-400">
                課金処理とは切り離して、Product Grant・Feature Access・AI Capacity・料金構成の推薦を確認します。
                ここでのGrantは開発/検証用で、決済情報ではありません。
            </p>
        </header>

        <section class="page-card p-5">
            <form method="GET" action="{{ route('admin.economy.index') }}" class="flex flex-col gap-3 sm:flex-row sm:items-end">
                <label class="flex-1">
                    <span class="form-label">確認するユーザー</span>
                    <select name="user_id" class="form-control mt-2">
                        @foreach ($users as $user)
                            <option value="{{ $user->id }}" @selected($selectedUser?->id === $user->id)>
                                #{{ $user->id }} {{ $user->name ?: $user->email }}
                            </option>
                        @endforeach
                    </select>
                </label>
                <button class="btn-secondary" type="submit">切り替える</button>
            </form>
        </section>

        @if (session('success'))
            <div class="assistant-notice assistant-notice-success">{{ session('success') }}</div>
        @endif

        @if ($selectedUser)
            <section class="grid gap-4 lg:grid-cols-3">
                <article class="page-card p-5">
                    <p class="text-xs font-black text-cyan-300">EFFECTIVE PRODUCTS</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @forelse ($effectiveProducts as $product)
                            <span class="badge badge-slate">{{ $catalog->label($product) }}</span>
                        @empty
                            <span class="text-sm text-slate-500">Free</span>
                        @endforelse
                    </div>
                </article>

                <article class="page-card p-5">
                    <p class="text-xs font-black text-violet-300">AI CAPACITY</p>
                    <p class="mt-3 text-xl font-black text-slate-100">{{ data_get($capacityPolicy, 'tier', 'standard') }}</p>
                    <p class="mt-1 text-xs leading-5 text-slate-500">{{ data_get($capacityPolicy, 'policy.description') }}</p>
                </article>

                <article class="page-card p-5">
                    <p class="text-xs font-black text-emerald-300">RECOMMENDATION</p>
                    @if (data_get($recommendation, 'free_is_sufficient'))
                        <p class="mt-3 font-black text-slate-100">Freeのままで十分</p>
                        <p class="mt-1 text-xs leading-5 text-slate-500">現時点では追加Productを勧めるだけの利用シグナルがありません。</p>
                    @else
                        <div class="mt-3 flex flex-wrap gap-2">
                            @foreach (data_get($recommendation, 'recommended_products', []) as $productKey)
                                @php $product = \App\Enums\ProductKey::tryFrom($productKey); @endphp
                                <span class="badge badge-green">{{ $product ? $catalog->label($product) : $productKey }}</span>
                            @endforeach
                        </div>
                    @endif
                </article>
            </section>

            <section class="page-card border-violet-300/20 p-5 sm:p-6">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-[.18em] text-violet-300">RELEASE ACCESS</p>
                        <h2 class="mt-1 text-lg font-black text-slate-100">User Release Level</h2>
                        <p class="mt-1 max-w-2xl text-xs leading-5 text-slate-500">
                            Public Releaseとは独立して、このユーザーだけBeta構成へ進めます。Internal Previewは管理者専用です。
                        </p>
                    </div>
                    <div class="text-right">
                        <span class="badge badge-slate">L{{ $selectedUserReleaseLevel?->value ?? $publicReleaseLevel->value }} {{ $selectedUserReleaseLevel?->label() ?? $publicReleaseLevel->label() }}</span>
                        <p class="mt-1 text-[10px] text-slate-600">Public L{{ $publicReleaseLevel->value }}</p>
                    </div>
                </div>

                <form method="POST" action="{{ route('admin.release_level.user.update', $selectedUser) }}" class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end">
                    @csrf
                    <label class="flex-1">
                        <span class="form-label">Access Level</span>
                        <select name="level" class="form-control mt-2">
                            <option value="public" @selected($selectedUser->release_level_override === null)>
                                Publicを継承 — L{{ $publicReleaseLevel->value }} {{ $publicReleaseLevel->label() }}
                            </option>
                            @foreach ($releaseLevelCases as $releaseLevel)
                                <option value="{{ $releaseLevel->value }}" @selected((int) $selectedUser->release_level_override === $releaseLevel->value && $selectedUser->release_level_override !== null)>
                                    L{{ $releaseLevel->value }} {{ $releaseLevel->label() }}
                                </option>
                            @endforeach
                        </select>
                    </label>
                    <button class="btn-secondary justify-center" type="submit">Release Levelを変更</button>
                </form>
            </section>

            <section class="page-card border-cyan-300/20 p-5 sm:p-6">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-[10px] font-black uppercase tracking-[.18em] text-cyan-300">COMPLIMENTARY PREMIUM</p>
                        <h2 class="mt-1 text-lg font-black text-slate-100">Premiumを無償付与</h2>
                        <p class="mt-1 max-w-2xl text-xs leading-5 text-slate-500">
                            身近なユーザーやテスターへPremium Coreだけを提供します。Admin権限やAll Access、AI Capacity Boostは付与されません。
                        </p>
                    </div>
                    @if ($complimentaryPremium)
                        <span class="badge badge-green">無償Premium 有効</span>
                    @else
                        <span class="badge badge-slate">未付与</span>
                    @endif
                </div>

                @if ($complimentaryPremium)
                    <div class="mt-4 rounded-2xl border border-emerald-300/15 bg-emerald-300/[0.04] p-4">
                        <p class="text-sm font-bold text-slate-100">
                            {{ $complimentaryPremium->expires_at ? $complimentaryPremium->expires_at->format('Y/m/d H:i').' まで' : '無期限' }}
                        </p>
                        <p class="mt-1 text-xs text-slate-500">grant #{{ $complimentaryPremium->id }} · complimentary</p>
                        <form method="POST" action="{{ route('admin.economy.complimentary.destroy', $selectedUser) }}" class="mt-3">
                            @csrf
                            @method('DELETE')
                            <button class="btn-secondary px-3 py-2 text-xs text-rose-200" type="submit">無償Premiumを解除</button>
                        </form>
                    </div>
                @else
                    <form method="POST" action="{{ route('admin.economy.complimentary.store') }}" class="mt-4 grid gap-3 lg:grid-cols-[1fr_1fr_auto] lg:items-end">
                        @csrf
                        <input type="hidden" name="user_id" value="{{ $selectedUser->id }}">
                        <label>
                            <span class="form-label">期間</span>
                            <select name="duration" class="form-control mt-2">
                                <option value="unlimited">無期限</option>
                                <option value="30_days">30日</option>
                                <option value="90_days">90日</option>
                                <option value="custom">任意期限</option>
                            </select>
                        </label>
                        <label>
                            <span class="form-label">任意期限（custom時のみ）</span>
                            <input type="datetime-local" name="custom_expires_at" class="form-control mt-2">
                        </label>
                        <button class="btn-primary justify-center" type="submit">Premiumを無償付与</button>
                    </form>
                @endif

                @if (($complimentaryHistory ?? collect())->isNotEmpty())
                    <details class="pk-action-details mt-4">
                        <summary>無償付与の履歴</summary>
                        <div class="mt-2 space-y-2">
                            @foreach ($complimentaryHistory as $grant)
                                <div class="rounded-xl border border-slate-800 bg-slate-950/30 p-3 text-xs text-slate-400">
                                    <span class="font-bold text-slate-200">#{{ $grant->id }}</span>
                                    · {{ $grant->created_at?->format('Y/m/d H:i') }}
                                    · {{ $grant->expires_at ? '終了 '.$grant->expires_at->format('Y/m/d H:i') : '無期限' }}
                                </div>
                            @endforeach
                        </div>
                    </details>
                @endif
            </section>

            <section class="grid gap-6 xl:grid-cols-[.9fr_1.1fr]">
                <article class="page-card p-5 sm:p-6">
                    <h2 class="text-lg font-black text-slate-100">開発用 Product Grant</h2>
                    <p class="mt-1 text-xs leading-5 text-slate-500">任意ProductのEntitlement検証用です。通常の無償提供には上のPremium専用操作を使います。</p>
                    <form method="POST" action="{{ route('admin.economy.grants.store') }}" class="mt-4 space-y-3">
                        @csrf
                        <input type="hidden" name="user_id" value="{{ $selectedUser->id }}">
                        <label class="block">
                            <span class="form-label">Product</span>
                            <select name="product_key" class="form-control mt-2">
                                @foreach ($productCases as $product)
                                    <option value="{{ $product->value }}">{{ $catalog->label($product) }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="form-label">Source</span>
                            <select name="source" class="form-control mt-2">
                                @foreach (['manual', 'subscription', 'complimentary', 'gift', 'sponsor', 'migration'] as $source)
                                    <option value="{{ $source }}">{{ $source }}</option>
                                @endforeach
                            </select>
                        </label>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <label><span class="form-label">Starts at</span><input type="datetime-local" name="starts_at" class="form-control mt-2"></label>
                            <label><span class="form-label">Expires at</span><input type="datetime-local" name="expires_at" class="form-control mt-2"></label>
                        </div>
                        <button class="btn-primary" type="submit">テストGrantを追加</button>
                    </form>

                    <div class="mt-5 space-y-2">
                        @forelse ($activeGrants as $grant)
                            <div class="rounded-xl border border-slate-800 bg-slate-950/35 p-3">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <p class="text-sm font-bold text-slate-100">{{ $catalog->label($grant->product_key) }}</p>
                                        <p class="mt-1 text-[11px] text-slate-500">{{ $grant->source }} · grant #{{ $grant->id }}</p>
                                    </div>
                                    <form method="POST" action="{{ route('admin.economy.grants.destroy', $grant) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button class="text-xs font-bold text-rose-300" type="submit">解除</button>
                                    </form>
                                </div>
                            </div>
                        @empty
                            <p class="text-sm text-slate-500">有効なGrantはありません。</p>
                        @endforelse
                    </div>
                </article>

                <article class="page-card overflow-hidden">
                    <div class="border-b border-slate-800 p-5">
                        <h2 class="text-lg font-black text-slate-100">Feature Access</h2>
                        <p class="mt-1 text-xs text-slate-500">最終判定は既存のFeatureAccessServiceを通します。</p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-left text-xs">
                            <thead class="bg-slate-950/55 text-slate-500">
                                <tr><th class="px-4 py-3">Feature</th><th class="px-4 py-3">Access</th><th class="px-4 py-3">Source</th><th class="px-4 py-3">Product</th></tr>
                            </thead>
                            <tbody class="divide-y divide-slate-800">
                                @foreach ($featureDecisions as $row)
                                    <tr>
                                        <td class="px-4 py-3 font-mono text-slate-300">{{ $row['feature']->value }}</td>
                                        <td class="px-4 py-3 {{ $row['decision']->allowed ? 'text-emerald-300' : 'text-slate-600' }}">{{ $row['decision']->allowed ? 'allowed' : 'denied' }}</td>
                                        <td class="px-4 py-3 text-slate-400">{{ $row['decision']->source?->value ?? '—' }}</td>
                                        <td class="px-4 py-3 text-slate-400">{{ data_get($row['decision']->metadata, 'effective_product_key', '—') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </article>
            </section>

            <section class="page-card p-5 sm:p-6">
                <h2 class="text-lg font-black text-slate-100">Recommendation detail</h2>
                <div class="mt-4 grid gap-4 lg:grid-cols-2">
                    <div class="space-y-3">
                        @forelse (data_get($recommendation, 'why', []) as $productKey => $reason)
                            <div class="rounded-xl border border-emerald-300/10 bg-emerald-300/[0.03] p-3">
                                <p class="text-xs font-black text-emerald-200">{{ $productKey }}</p>
                                <p class="mt-1 text-xs leading-5 text-slate-400">{{ $reason }}</p>
                            </div>
                        @empty
                            <p class="text-sm text-slate-500">追加構成の推薦理由はありません。</p>
                        @endforelse
                    </div>
                    <div class="space-y-3">
                        @foreach (data_get($recommendation, 'unused_products', []) as $productKey => $reason)
                            <div class="rounded-xl border border-slate-800 bg-slate-950/30 p-3">
                                <p class="text-xs font-black text-slate-300">{{ $productKey }} は今は不要</p>
                                <p class="mt-1 text-xs leading-5 text-slate-500">{{ $reason }}</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </section>
        @endif
    </div>
@endsection
