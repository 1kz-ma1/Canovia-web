<?php

namespace App\Http\Controllers;

use App\Models\McpStudyResultProposal;
use App\Services\McpStudyResultProposalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Explicit Canovia-session owner action, protected by auth, CSRF and route
 * throttle. MCP bearer tokens cannot reach this route.
 */
final class McpStudyResultDecisionController extends Controller
{
    public function __invoke(
        Request $request,
        McpStudyResultProposal $proposal,
        McpStudyResultProposalService $service,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor
            && (int) $proposal->user_id === (int) $actor->id, 404);

        $choice = $request->validate(['decision' => ['required', 'in:approve,reject']]);
        $approved = $choice['decision'] === 'approve';
        $success = $service->decide($actor, (int) $proposal->id, $approved);

        return redirect()->route('auth.account')->with(
            'status',
            $success
                ? ($approved
                    ? '演習結果を本人確認済みの記録として保存しました。Task進捗は自動変更していません。'
                    : '演習結果の候補を破棄しました。')
                : 'この候補は保存できません。期限・共有許可・Plan/Taskの権限をご確認ください。',
        );
    }
}
