<?php

namespace App\Http\Controllers;

use App\Services\PersonalizationLivingProfileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class PersonalizationContextUpdateController extends Controller
{
    public function index(
        Request $request,
        PersonalizationLivingProfileService $livingProfile,
    ) {
        return view('personalization.updates', [
            'livingProfile' => $livingProfile->summary($request),
        ]);
    }

    public function refresh(
        Request $request,
        PersonalizationLivingProfileService $livingProfile,
    ): RedirectResponse {
        $livingProfile->refresh($request, 'manual');

        return redirect()
            ->route('personalization.updates.index')
            ->with(
                'status',
                '現在の利用状況からPersonalization Contextを再評価しました。',
            );
    }

    public function confirm(
        Request $request,
        string $candidateKey,
        PersonalizationLivingProfileService $livingProfile,
    ): RedirectResponse {
        abort_unless(
            preg_match('/^[a-z0-9_]{1,80}$/', $candidateKey),
            404,
        );

        if (! $livingProfile->confirm($request, $candidateKey)) {
            return redirect()
                ->route('personalization.updates.index')
                ->with(
                    'status',
                    'このContext更新候補は現在確認できません。',
                );
        }

        return redirect()
            ->route('personalization.updates.index')
            ->with(
                'success',
                $candidateKey === 'development_plan_direction_review'
                    ? 'レビュー候補を確認済みにしました。Planやタスクは自動変更されません。'
                    : '今後の表示に反映します。初回診断の回答自体は変更していません。',
            );
    }

    public function dismiss(
        Request $request,
        string $candidateKey,
        PersonalizationLivingProfileService $livingProfile,
    ): RedirectResponse {
        abort_unless(
            preg_match('/^[a-z0-9_]{1,80}$/', $candidateKey),
            404,
        );

        if (! $livingProfile->dismiss($request, $candidateKey)) {
            return redirect()
                ->route('personalization.updates.index')
                ->with(
                    'status',
                    'このContext更新候補は現在確認できません。',
                );
        }

        return redirect()
            ->route('personalization.updates.index')
            ->with(
                'status',
                '今回は変更しません。同じ観測事実だけで繰り返し確認は出しません。',
            );
    }
}
