<?php

namespace App\Http\Controllers;

use App\Models\DevelopmentOperationalProfile;
use App\Models\DevelopmentOperationalTarget;
use App\Models\Plan;
use App\Services\PlanCategoryProfileService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class DevelopmentOperationalTargetController extends Controller
{
    public function __invoke(
        Request $request,
        Plan $plan,
        PlanCategoryProfileService $profiles,
    ) {
        $user = $request->user();
        abort_unless(
            $user && $plan->user_id !== null
                && (int) $plan->user_id === (int) $user->id
                && $profiles->forPlan($plan)->key === 'development',
            404,
        );

        $command = $request->validate([
            'action' => ['required', Rule::in([
                'set_target', 'confirm_backup', 'confirm_restore',
                'clear_backup', 'clear_restore',
                'confirm_service', 'clear_service',
            ])],
        ]);
        $action = $command['action'];

        if ($action === 'set_target') {
            $data = $request->validate([
                'provider' => ['required', Rule::in(DevelopmentOperationalTarget::PROVIDERS)],
                'environment' => ['required', Rule::in(DevelopmentOperationalTarget::ENVIRONMENTS)],
                // Identifier only. No arbitrary URL / hostname / SQL / token.
                'resource_ref' => ['required', 'string', 'regex:/\A[a-zA-Z0-9][a-zA-Z0-9_-]{4,79}\z/'],
            ]);

            DB::transaction(static function () use ($plan, $data): void {
                $target = DevelopmentOperationalTarget::query()
                    ->where('plan_id', $plan->id)->lockForUpdate()->first();

                if (! $target) {
                    DevelopmentOperationalTarget::query()->create([
                        'plan_id' => $plan->id,
                        ...$data,
                        'revision' => 1,
                    ]);
                    return;
                }

                $changed = $target->provider !== $data['provider']
                    || $target->environment !== $data['environment']
                    || $target->resource_ref !== $data['resource_ref'];
                if ($changed) {
                    $target->fill($data);
                    $target->revision = (int) $target->revision + 1;
                    // Keep earlier timestamp only to show it is now stale.
                    $target->save();
                }
            });
        } elseif (in_array($action, ['confirm_service', 'clear_service'], true)) {
            // The browser may have an outdated target open when the owner
            // changes the binding in another tab. Never rebind the assertion.
            $expectedRevision = null;
            if ($action === 'confirm_service') {
                $payload = $request->validate([
                    'attest' => ['required', 'in:yes'],
                    'target_revision' => ['required', 'integer', 'min:1'],
                ]);
                $expectedRevision = (int) $payload['target_revision'];
            }

            DB::transaction(static function () use ($plan, $request, $action, $expectedRevision): void {
                $target = DevelopmentOperationalTarget::query()
                    ->where('plan_id', $plan->id)->lockForUpdate()->firstOrFail();
                if ($action === 'confirm_service') {
                    if (! (
                        $target->provider === 'render'
                            && in_array($target->environment, DevelopmentOperationalTarget::ENVIRONMENTS, true)
                            && is_string($target->resource_ref)
                            && preg_match('/\Asrv-[a-zA-Z0-9_-]{4,76}\z/', $target->resource_ref)
                            && (int) $target->revision === $expectedRevision
                    )) {
                        self::rejectStaleConfirmation($request, $plan);
                    }
                    $target->service_checked_at = now();
                    $target->service_checked_revision = $target->revision;
                    $target->service_checked_environment = $target->environment;
                } else {
                    $target->service_checked_at = null;
                    $target->service_checked_revision = null;
                    $target->service_checked_environment = null;
                }
                $target->save();
            });
        } else {
            // Every statement is a human attestation; never trust form fields
            // claiming provider verification or backdate timestamps.
            $expectedRevision = null;
            $expectedStore = null;
            if (str_starts_with($action, 'confirm_')) {
                // Acknowledge the exact environment AND storage category shown
                // when the owner checked the real backup or isolated restore.
                // Otherwise an old tab could silently attest a new binding.
                $request->validate(['attest' => ['required', 'in:yes']]);
                $snapshot = $request->validate([
                    'target_revision' => ['required', 'integer', 'min:1'],
                    'expected_data_store' => ['required', Rule::in(DevelopmentOperationalProfile::DATA_STORES)],
                ]);
                $expectedRevision = (int) $snapshot['target_revision'];
                $expectedStore = $snapshot['expected_data_store'];
            }

            DB::transaction(static function () use ($plan, $request, $action, $expectedRevision, $expectedStore): void {
                $target = DevelopmentOperationalTarget::query()
                    ->where('plan_id', $plan->id)->lockForUpdate()->firstOrFail();
                $kind = str_ends_with($action, 'backup') ? 'backup' : 'restore';

                if (str_starts_with($action, 'confirm_')) {
                    // The profile row is also locked so concurrent changes to
                    // the selected storage category cannot pass the fence.
                    $profile = DevelopmentOperationalProfile::query()
                        ->where('plan_id', $plan->id)->lockForUpdate()->first();
                    $dataStore = $profile?->data_store;
                    abort_unless(
                        in_array($dataStore, ['server_db', 'local_device', 'cloud_files', 'mixed'], true),
                        422,
                        '保存方式を先に登録してください。',
                    );
                    if ($target->revision !== $expectedRevision || $dataStore !== $expectedStore) {
                        self::rejectStaleConfirmation($request, $plan);
                    }
                    $target->{$kind.'_confirmed_at'} = now();
                    $target->{$kind.'_revision'} = $target->revision;
                    $target->{$kind.'_store'} = $dataStore;
                } else {
                    $target->{$kind.'_confirmed_at'} = null;
                    $target->{$kind.'_revision'} = null;
                    $target->{$kind.'_store'} = null;
                }
                $target->save();
            });
        }

        return redirect()->route('workspace.development.index', [
            'plan_id' => $plan->id,
            'surface' => 'work',
            'release_preflight' => 1,
        ])->with(
            'success',
            '運用準備の自己申告を更新しました。Canoviaが外部サービスや復元結果を検証したものではありません。',
        );
    }
    /**
     * Safe, owner-scoped 409 recovery. Never retry or rewrite the attestation
     * automatically: the operator must re-inspect the current target.
     */
    private static function rejectStaleConfirmation(Request $request, Plan $plan): never
    {
        $returnUrl = route('workspace.development.index', [
            'plan_id' => $plan->id,
            'surface' => 'work',
            'release_preflight' => 1,
        ]);

        $response = $request->expectsJson()
            ? response()->json([
                'code' => 'development_operational_stale',
                'message' => '対象環境または保存方式が変更されています。最新の画面で確認し直してください。',
                'reload_url' => $returnUrl,
            ], 409)
            : response()->view('workspace.development.operational-stale-conflict', [
                'returnUrl' => $returnUrl,
            ], 409);

        throw new HttpResponseException(
            $response->header('Cache-Control', 'private, no-store')
                ->header('Referrer-Policy', 'no-referrer'),
        );
    }

}
