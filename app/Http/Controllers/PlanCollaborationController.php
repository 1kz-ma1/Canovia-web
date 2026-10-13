<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\PlanMember;
use App\Services\PlanCollaborationService;
use App\Services\PlanActivityService;
use App\Services\PlanOwnershipService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PlanCollaborationController extends Controller
{
    public function settings(Request $request, Plan $plan, PlanOwnershipService $ownership)
    {
        $ownership->authorizeView($request, $plan);
        $plan->load(['user', 'memberships.user', 'activityLogs.user']);
        $recentActivities = $plan->activityLogs()->with('user')->limit(20)->get();
        $recentArtifacts = $plan->artifacts()->with('assignedUser:id,name')->latest('updated_at')->limit(5)->get();
        $canManage = $ownership->owns($request, $plan);
        $canEdit = $ownership->canEdit($request, $plan);
        $collaborationRole = $ownership->role($request, $plan);

        return view('plans.collaboration', compact('plan', 'recentActivities', 'recentArtifacts', 'canManage', 'canEdit', 'collaborationRole'));
    }

    public function enable(
        Request $request,
        Plan $plan,
        PlanOwnershipService $ownership,
        PlanCollaborationService $collaboration,
    ) {
        $ownership->authorizePlan($request, $plan);

        if (! $request->user()) {
            return redirect()->route('auth.login.form')->with('status', '共同計画を作るにはログインが必要です。');
        }

        if (! $collaboration->canOwnCollaborativePlan($request->user())) {
            abort(403, '共同計画の作成権限がありません。');
        }

        DB::transaction(function () use ($request, $plan, $ownership, $collaboration): void {
            $lockedPlan = Plan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            $ownership->authorizePlan($request, $lockedPlan);
            $collaboration->enable($lockedPlan);
        }, 3);

        return redirect()->route('plans.collaboration.settings', $plan)
            ->with('status', '共同計画を有効にしました。参加者は最初は閲覧者として参加します。');
    }

    public function disable(
        Request $request,
        Plan $plan,
        PlanOwnershipService $ownership,
        PlanCollaborationService $collaboration,
    ) {
        DB::transaction(function () use ($request, $plan, $ownership, $collaboration): void {
            $lockedPlan = Plan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            $ownership->authorizePlan($request, $lockedPlan);
            $collaboration->disable($lockedPlan);
        }, 3);

        return redirect()->route('plans.collaboration.settings', $plan)
            ->with('status', '共同計画を停止しました。メンバー情報は再開に備えて保持しています。');
    }

    public function regenerateInvite(
        Request $request,
        Plan $plan,
        PlanOwnershipService $ownership,
        PlanCollaborationService $collaboration,
        PlanActivityService $activity,
    ) {
        DB::transaction(function () use ($request, $plan, $ownership, $collaboration, $activity): void {
            $lockedPlan = Plan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            $ownership->authorizePlan($request, $lockedPlan);
            abort_unless($lockedPlan->is_collaborative, 404);
            $collaboration->regenerateInvite($lockedPlan);
            $activity->record($lockedPlan, $request->user(), 'invite_regenerated', 'plan', (int) $lockedPlan->id);
        }, 3);

        return redirect()->route('plans.collaboration.settings', $plan)
            ->with('status', '共有リンクと参加コードを再発行しました。以前の招待情報は使えません。');
    }

    public function joinForm()
    {
        return view('plans.join');
    }

    public function joinByCode(Request $request, PlanCollaborationService $collaboration)
    {
        $validated = $request->validate([
            'join_code' => ['required', 'string', 'max:16'],
        ]);

        $code = strtoupper(preg_replace('/\s+/', '', trim((string) $validated['join_code'])) ?? '');
        if ($code !== '' && ! str_starts_with($code, 'CNV-')) {
            $code = 'CNV-' . $code;
        }
        $plan = Plan::query()
            ->where('is_collaborative', true)
            ->where('collaboration_join_code', $code)
            ->first();

        if (! $plan) {
            return back()->withErrors(['join_code' => '有効な共同計画が見つかりませんでした。'])->withInput();
        }

        return $this->join($request, $plan, $collaboration, 'code', $code);
    }

    public function joinByToken(Request $request, string $token, PlanCollaborationService $collaboration)
    {
        $plan = Plan::query()
            ->with('user')
            ->where('is_collaborative', true)
            ->where('collaboration_share_token', $token)
            ->firstOrFail();

        if (! $request->user()) {
            $request->session()->put('url.intended', $request->fullUrl());

            return view('plans.join_invite', [
                'plan' => $plan,
                'token' => $token,
            ]);
        }

        return $this->join($request, $plan, $collaboration, 'token', $token);
    }

    public function updateMember(
        Request $request,
        Plan $plan,
        PlanMember $member,
        PlanOwnershipService $ownership,
        PlanActivityService $activity,
    ) {
        $validated = $request->validate([
            'role' => ['required', Rule::in(PlanMember::ROLES)],
        ]);

        DB::transaction(function () use ($request, $plan, $member, $ownership, $activity, $validated): void {
            // Same Plan -> Member order as Companion commit validation.
            // Recheck owner and membership relation after acquiring the locks.
            $lockedPlan = Plan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            $ownership->authorizePlan($request, $lockedPlan);
            $lockedMember = PlanMember::query()->whereKey($member->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $lockedMember->plan_id === (int) $lockedPlan->id, 404);

            $beforeRole = $lockedMember->role;
            $lockedMember->update(['role' => $validated['role']]);
            $activity->record($lockedPlan, $request->user(), 'member_role_changed', 'user', (int) $lockedMember->user_id, [
                'before_role' => $beforeRole,
                'after_role' => $validated['role'],
                'member_name' => $lockedMember->user?->name,
            ]);
        }, 3);

        return redirect()->route('plans.collaboration.settings', $plan)
            ->with('status', 'メンバー権限を更新しました。');
    }

    public function removeMember(
        Request $request,
        Plan $plan,
        PlanMember $member,
        PlanOwnershipService $ownership,
        PlanActivityService $activity,
    ) {
        DB::transaction(function () use ($request, $plan, $member, $ownership, $activity): void {
            $lockedPlan = Plan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            $ownership->authorizePlan($request, $lockedPlan);
            $lockedMember = PlanMember::query()->whereKey($member->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $lockedMember->plan_id === (int) $lockedPlan->id, 404);

            $memberName = $lockedMember->user?->name;
            $memberUserId = (int) $lockedMember->user_id;
            $lockedMember->delete();
            $activity->record($lockedPlan, $request->user(), 'member_removed', 'user', $memberUserId, [
                'member_name' => $memberName,
            ]);
        }, 3);

        return redirect()->route('plans.collaboration.settings', $plan)
            ->with('status', 'メンバーを共同計画から外しました。');
    }

    private function join(
        Request $request,
        Plan $plan,
        PlanCollaborationService $collaboration,
        string $credentialType,
        string $credential,
    ) {
        // The previous lookup is only a locator. An invitation may have been
        // rotated or disabled while a request was in flight. Plan first, then
        // the unique PlanMember row, and the activity audit in one commit.
        $result = DB::transaction(function () use ($request, $plan, $collaboration, $credentialType, $credential): ?array {
            $lockedPlan = Plan::query()->whereKey($plan->id)->lockForUpdate()->first();
            $currentCredential = $lockedPlan === null ? '' : (string) (
                $credentialType === 'code'
                    ? $lockedPlan->collaboration_join_code
                    : $lockedPlan->collaboration_share_token
            );
            if ($lockedPlan === null || ! $lockedPlan->is_collaborative
                || $lockedPlan->user_id === null || $currentCredential === ''
                || ! hash_equals($currentCredential, $credential)) {
                return null;
            }

            if ((int) $lockedPlan->user_id === (int) $request->user()->id) {
                return ['owner' => true, 'created' => false];
            }

            $member = $collaboration->addViewer($lockedPlan, $request->user(), $lockedPlan->user);
            if ($member->wasRecentlyCreated) {
                app(PlanActivityService::class)->record(
                    $lockedPlan, $request->user(), 'member_joined', 'user',
                    (int) $request->user()->id,
                    ['member_name' => $request->user()->name],
                );
            }

            return ['owner' => false, 'created' => $member->wasRecentlyCreated];
        }, 3);

        if ($result === null) {
            if ($credentialType === 'code') {
                return back()->withErrors(['join_code' => '招待コードの有効性が変更されました。再確認してください。'])->withInput();
            }

            abort(404, 'この招待リンクは現在無効です。');
        }

        if ($result['owner']) {
            return redirect()->route('plans.show', $plan)->with('status', 'あなたが作成した共同計画です。');
        }

        $message = $result['created']
            ? '共同計画に参加しました。最初の権限は閲覧のみです。'
            : 'すでにこの共同計画へ参加しています。';

        return redirect()->route('plans.show', $plan)->with('status', $message);
    }
}
