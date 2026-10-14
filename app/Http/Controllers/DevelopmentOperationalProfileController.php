<?php

namespace App\Http\Controllers;

use App\Models\DevelopmentOperationalProfile;
use App\Models\Plan;
use App\Services\PlanCategoryProfileService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class DevelopmentOperationalProfileController extends Controller
{
    public function __invoke(
        Request $request,
        Plan $plan,
        PlanCategoryProfileService $profiles,
    ) {
        $user = $request->user();

        // Owner-only: collaborators/guest token holders must not alter
        // a plan's operational context, or gain a private stack inventory.
        abort_unless(
            $user && $plan->user_id !== null
                && (int) $plan->user_id === (int) $user->id
                && $profiles->forPlan($plan)->key === 'development',
            404,
        );

        // Enum-only, partial updates; no hostnames, DB content, secret,
        // credential, or other arbitrary metadata is accepted or stored.
        $validated = $request->validate([
            'stage' => ['sometimes', 'required', Rule::in(DevelopmentOperationalProfile::STAGES)],
            'runtime' => ['sometimes', 'required', Rule::in(DevelopmentOperationalProfile::RUNTIMES)],
            'data_store' => ['sometimes', 'required', Rule::in(DevelopmentOperationalProfile::DATA_STORES)],
        ]);

        if ($validated !== []) {
            DB::transaction(static function () use ($plan, $validated): void {
                // Serialize storage changes with recovery confirmations, which
                // lock the same profile row before recording any claim.
                $profile = DevelopmentOperationalProfile::query()
                    ->where('plan_id', $plan->id)->lockForUpdate()->first();
                if (! $profile) {
                    DevelopmentOperationalProfile::query()->create([
                        'plan_id' => (int) $plan->id,
                        ...$validated,
                    ]);
                    return;
                }

                if (array_key_exists('data_store', $validated)
                    && $profile->data_store !== $validated['data_store']) {
                    // Never wrap an exhausted counter and revalidate old forms.
                    abort_unless((int) $profile->data_store_revision >= 1
                        && (int) $profile->data_store_revision < 4294967295, 409);
                    $profile->data_store_revision = (int) $profile->data_store_revision + 1;
                }

                $profile->fill($validated);
                if ($profile->isDirty()) {
                    $profile->save();
                }
            });
        }

        return redirect()->route('workspace.development.index', [
            'plan_id' => $plan->id,
            'surface' => 'work',
            'release_preflight' => 1,
        ])->with(
            'success',
            '公開準備の自己申告情報を保存しました。外部環境・DB・復元テストを検証したものではありません。',
        );
    }
}
