<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\PlanArtifact;
use App\Services\PlanCategoryProfileService;
use App\Services\PlanOwnershipService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class DevelopmentPreviewController extends Controller
{
    public function store(
        Request $request,
        Plan $plan,
        PlanOwnershipService $ownership,
        PlanCategoryProfileService $profiles,
    ) {
        $ownership->authorizeEdit($request, $plan);
        abort_unless($profiles->forPlan($plan)->key === 'development', 404);

        $validated = $request->validate([
            'url' => ['required', 'string', 'max:2048', 'url:http,https'],
        ]);

        $url = trim((string) $validated['url']);
        $this->assertPreviewUrl($url);

        $artifact = PlanArtifact::query()
            ->firstOrNew([
                'plan_id' => $plan->id,
                'external_id' => 'canovia:development-preview',
            ]);

        $metadata = is_array($artifact->metadata)
            ? $artifact->metadata
            : [];

        $metadata['development_preview'] = [
            'configured_by_user_id' => $request->user()?->id,
            'configured_at' => now()->toIso8601String(),
        ];

        $artifact->fill([
            'created_by_user_id' => $artifact->exists
                ? $artifact->created_by_user_id
                : $request->user()?->id,
            'assigned_user_id' => null,
            'provider' => 'external',
            'artifact_type' => 'link',
            'title' => 'Development Preview',
            'url' => $url,
            'version_label' => null,
            'notes' => 'Developer Workspace Preview',
            'metadata' => $metadata,
        ])->save();

        return redirect()
            ->route('workspace.development.index', [
                'plan_id' => $plan->id,
                'surface' => 'preview',
            ])
            ->with('status', 'プレビューURLを保存しました。');
    }

    public function destroy(
        Request $request,
        Plan $plan,
        PlanOwnershipService $ownership,
        PlanCategoryProfileService $profiles,
    ) {
        $ownership->authorizeEdit($request, $plan);
        abort_unless($profiles->forPlan($plan)->key === 'development', 404);

        PlanArtifact::query()
            ->where('plan_id', $plan->id)
            ->where('external_id', 'canovia:development-preview')
            ->delete();

        return redirect()
            ->route('workspace.development.index', [
                'plan_id' => $plan->id,
                'surface' => 'preview',
            ])
            ->with('status', 'プレビューURLを解除しました。');
    }

    private function assertPreviewUrl(string $url): void
    {
        $host = mb_strtolower(trim((string) parse_url($url, PHP_URL_HOST)));

        if ($host === '') {
            throw ValidationException::withMessages([
                'url' => 'プレビューURLのhostを確認できませんでした。',
            ]);
        }

        if (
            $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
        ) {
            throw ValidationException::withMessages([
                'url' => 'localhost / .local は共有プレビューに設定できません。',
            ]);
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $isPublic = filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            );

            if ($isPublic === false) {
                throw ValidationException::withMessages([
                    'url' => 'Private / reserved IPは共有プレビューに設定できません。',
                ]);
            }
        }
    }
}
