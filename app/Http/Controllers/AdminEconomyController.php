<?php

namespace App\Http\Controllers;

use App\Enums\FeatureKey;
use App\Enums\ProductKey;
use App\Enums\ReleaseLevel;
use App\Models\User;
use App\Models\UserProductGrant;
use App\Services\AdminAccessService;
use App\Services\AiCapacityService;
use App\Services\EconomyCatalogService;
use App\Services\EconomyRecommendationService;
use App\Services\FeatureAccessService;
use App\Services\ProductGrantService;
use App\Services\ReleaseLevelService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminEconomyController extends Controller
{
    public function __construct(
        private readonly AdminAccessService $access,
        private readonly EconomyCatalogService $catalog,
        private readonly ProductGrantService $grants,
        private readonly EconomyRecommendationService $recommendations,
        private readonly AiCapacityService $capacity,
        private readonly FeatureAccessService $featureAccess,
        private readonly ReleaseLevelService $releaseLevels,
    ) {}

    public function index(Request $request)
    {
        abort_unless($this->access->authorized($request), 403);

        $users = User::query()->orderBy('id')->limit(100)->get();
        $selectedUser = $request->integer('user_id')
            ? User::query()->find($request->integer('user_id'))
            : $users->first();

        $activeGrants = collect();
        $effectiveProducts = collect();
        $featureDecisions = collect();
        $recommendation = null;
        $capacityPolicy = null;
        $complimentaryPremium = null;
        $complimentaryHistory = collect();

        if ($selectedUser) {
            $activeGrants = $this->grants->activeGrants($selectedUser);
            $effectiveProducts = $this->grants->effectiveProducts($selectedUser);
            $featureDecisions = collect(FeatureKey::cases())->map(fn (FeatureKey $feature) => [
                'feature' => $feature,
                'decision' => $this->featureAccess->resolveAccess($selectedUser, $feature),
            ]);
            $recommendation = $this->recommendations->recommend($selectedUser);
            $capacityPolicy = $this->capacity->policyFor($selectedUser);
            $complimentaryPremium = $activeGrants->first(
                fn (UserProductGrant $grant) => $grant->product_key === ProductKey::PremiumCore
                    && $grant->source === 'complimentary'
            );
            $complimentaryHistory = $selectedUser->productGrants()
                ->where('product_key', ProductKey::PremiumCore->value)
                ->where('source', 'complimentary')
                ->latest('id')
                ->limit(10)
                ->get();
        }

        return view('admin.economy.index', [
            'users' => $users,
            'selectedUser' => $selectedUser,
            'activeGrants' => $activeGrants,
            'effectiveProducts' => $effectiveProducts,
            'featureDecisions' => $featureDecisions,
            'recommendation' => $recommendation,
            'capacityPolicy' => $capacityPolicy,
            'complimentaryPremium' => $complimentaryPremium,
            'complimentaryHistory' => $complimentaryHistory,
            'productCases' => ProductKey::cases(),
            'catalog' => $this->catalog,
            'publicReleaseLevel' => $this->releaseLevels->publicLevel(),
            'selectedUserReleaseLevel' => $selectedUser
                ? $this->releaseLevels->assignedLevelFor($selectedUser)
                : null,
            'releaseLevelCases' => collect(ReleaseLevel::cases())
                ->filter(fn (ReleaseLevel $level) =>
                    $level->value <= (int) config(
                        'release_levels.maximum_user_override',
                        ReleaseLevel::BetaExpansion->value,
                    )
                )
                ->values(),
        ]);
    }

    public function storeComplimentaryPremium(Request $request)
    {
        abort_unless($this->access->authorized($request), 403);

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'duration' => ['required', Rule::in(['unlimited', '30_days', '90_days', 'custom'])],
            'custom_expires_at' => ['nullable', 'required_if:duration,custom', 'date', 'after:now'],
        ]);

        $user = User::query()->findOrFail((int) $validated['user_id']);
        $now = now();
        $expiresAt = match ($validated['duration']) {
            '30_days' => $now->copy()->addDays(30),
            '90_days' => $now->copy()->addDays(90),
            'custom' => Carbon::parse((string) $validated['custom_expires_at']),
            default => null,
        };

        $this->expireActiveComplimentaryPremium($user, $request);

        UserProductGrant::create([
            'user_id' => $user->id,
            'product_key' => ProductKey::PremiumCore,
            'source' => 'complimentary',
            'starts_at' => $now,
            'expires_at' => $expiresAt,
            'metadata' => [
                'granted_via' => 'admin_complimentary_premium',
                'duration' => $validated['duration'],
                'granted_by_user_id' => $request->user()?->id,
            ],
        ]);

        return redirect()
            ->route('admin.economy.index', ['user_id' => $user->id])
            ->with('success', 'Premiumを無償付与しました。');
    }

    public function destroyComplimentaryPremium(Request $request, User $user)
    {
        abort_unless($this->access->authorized($request), 403);

        $count = $this->expireActiveComplimentaryPremium($user, $request);

        return redirect()
            ->route('admin.economy.index', ['user_id' => $user->id])
            ->with('success', $count > 0 ? '無償Premiumを解除しました。' : '有効な無償Premiumはありませんでした。');
    }

    public function storeGrant(Request $request)
    {
        abort_unless($this->access->authorized($request), 403);

        $expiresRules = ['nullable', 'date'];
        if ($request->filled('starts_at')) {
            $expiresRules[] = 'after:starts_at';
        }

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'product_key' => ['required', Rule::enum(ProductKey::class)],
            'source' => ['required', Rule::in(['manual', 'subscription', 'complimentary', 'gift', 'sponsor', 'migration'])],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => $expiresRules,
        ]);

        UserProductGrant::create([
            ...$validated,
            'metadata' => [
                'granted_via' => 'admin_economy_inspector',
                'granted_by_user_id' => $request->user()?->id,
            ],
        ]);

        return redirect()
            ->route('admin.economy.index', ['user_id' => $validated['user_id']])
            ->with('success', 'テスト用Product Grantを追加しました。');
    }

    public function destroyGrant(Request $request, UserProductGrant $grant)
    {
        abort_unless($this->access->authorized($request), 403);

        $userId = (int) $grant->user_id;
        $grant->delete();

        return redirect()
            ->route('admin.economy.index', ['user_id' => $userId])
            ->with('success', 'Product Grantを解除しました。');
    }

    private function expireActiveComplimentaryPremium(User $user, Request $request): int
    {
        $active = $user->productGrants()
            ->active()
            ->where('product_key', ProductKey::PremiumCore->value)
            ->where('source', 'complimentary')
            ->get();

        foreach ($active as $grant) {
            $metadata = is_array($grant->metadata) ? $grant->metadata : [];
            $grant->update([
                'expires_at' => now(),
                'metadata' => [
                    ...$metadata,
                    'revoked_via' => 'admin_complimentary_premium',
                    'revoked_by_user_id' => $request->user()?->id,
                    'revoked_at' => now()->toIso8601String(),
                ],
            ]);
        }

        return $active->count();
    }
}
