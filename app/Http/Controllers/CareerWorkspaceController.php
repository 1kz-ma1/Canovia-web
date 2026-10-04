<?php

namespace App\Http\Controllers;

use App\Enums\EvidenceSource;
use App\Intelligence\Career\CareerAdaptiveActionService;
use App\Models\CareerApplication;
use App\Models\CareerCapture;
use App\Models\CareerSelectionEvent;
use App\Models\Plan;
use App\Services\BehaviorIdentityService;
use App\Services\CareerCaptureService;
use App\Services\PlanCategoryProfileService;
use App\Services\PlanOwnershipService;
use App\Services\TaskEvidenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class CareerWorkspaceController extends Controller
{
    public function __construct(
        private readonly CareerAdaptiveActionService $careerIntelligence,
    ) {}

    public function index(
        Request $request,
        Plan $plan,
        PlanOwnershipService $ownership,
        PlanCategoryProfileService $profiles,
    ) {
        $ownership->authorizeView($request, $plan);
        $this->authorizeCareerPlan($plan, $profiles);

        $plan->load([
            'careerCaptures' => fn ($query) => $query->with('application')->latest('captured_at')->latest('id'),
            'careerApplications' => fn ($query) => $query->with(['selectionEvents.interviewReview'])->latest('updated_at')->latest('id'),
            'tasks' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
        ]);

        return view('career.index', [
            'plan' => $plan,
            'captures' => $plan->careerCaptures,
            'applications' => $plan->careerApplications,
            'canEdit' => $ownership->canEdit($request, $plan),
        ]);
    }

    public function storeCapture(
        Request $request,
        Plan $plan,
        PlanOwnershipService $ownership,
        PlanCategoryProfileService $profiles,
        BehaviorIdentityService $identity,
        CareerCaptureService $captureService,
    ) {
        $ownership->authorizeEdit($request, $plan);
        $this->authorizeCareerPlan($plan, $profiles);

        $validated = $request->validate([
            'source_type' => ['required', Rule::in(['screenshot', 'url'])],
            'source_url' => ['nullable', 'required_if:source_type,url', 'url', 'max:2048'],
            'screenshot' => ['nullable', 'required_if:source_type,screenshot', 'file', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $file = $request->file('screenshot');
        $bytes = $file ? file_get_contents($file->getRealPath()) : null;

        $captureService->record(
            $plan,
            sourceType: $validated['source_type'],
            sourceUrl: $validated['source_url'] ?? null,
            screenshotMime: $file?->getMimeType(),
            screenshotOriginalName: $file?->getClientOriginalName(),
            screenshotData: is_string($bytes) ? base64_encode($bytes) : null,
            screenshotByteSize: is_string($bytes) ? strlen($bytes) : null,
            rawText: trim((string) ($validated['note'] ?? '')) ?: null,
            userId: $request->user()?->id,
            actorToken: $identity->resolve($request),
        );

        return redirect()
            ->route('plans.career.index', $plan)
            ->with('success', 'Career Inboxへ追加しました。');
    }

    public function screenshot(
        Request $request,
        Plan $plan,
        CareerCapture $capture,
        PlanOwnershipService $ownership,
        PlanCategoryProfileService $profiles,
    ) {
        $ownership->authorizeView($request, $plan);
        $this->authorizeCareerPlan($plan, $profiles);
        abort_unless((int) $capture->plan_id === (int) $plan->id, 404);
        $capture->loadMissing('payload');

        $bytes = null;
        if ($capture->payload?->screenshot_data) {
            $decoded = base64_decode($capture->payload->screenshot_data, true);
            $bytes = is_string($decoded) ? $decoded : null;
        } elseif (filled($capture->screenshot_path) && Storage::exists($capture->screenshot_path)) {
            $bytes = Storage::get($capture->screenshot_path);
        }

        abort_unless(is_string($bytes) && $bytes !== '', 404);

        $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', basename((string) ($capture->screenshot_original_name ?: 'career-capture'))) ?: 'career-capture';

        return response($bytes, 200, [
            'Content-Type' => $capture->screenshot_mime ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.addslashes($filename).'"',
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroyCapture(
        Request $request,
        Plan $plan,
        CareerCapture $capture,
        PlanOwnershipService $ownership,
        PlanCategoryProfileService $profiles,
    ) {
        $ownership->authorizeEdit($request, $plan);
        $this->authorizeCareerPlan($plan, $profiles);
        abort_unless((int) $capture->plan_id === (int) $plan->id, 404);

        if ($capture->screenshot_path) {
            Storage::delete($capture->screenshot_path);
        }

        $capture->delete();
        $this->careerIntelligence->tryRefresh($plan);

        return redirect()
            ->route('plans.career.index', $plan)
            ->with('success', 'Captureを削除しました。');
    }

    public function storeApplication(
        Request $request,
        Plan $plan,
        PlanOwnershipService $ownership,
        PlanCategoryProfileService $profiles,
    ) {
        $ownership->authorizeEdit($request, $plan);
        $this->authorizeCareerPlan($plan, $profiles);

        $validated = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'company_website' => ['nullable', 'url', 'max:2048'],
            'role_title' => ['nullable', 'string', 'max:255'],
            'stage' => ['required', Rule::in(CareerApplication::STAGES)],
            'capture_id' => ['nullable', 'integer'],
        ]);

        $capture = null;
        if (! empty($validated['capture_id'])) {
            $capture = CareerCapture::query()
                ->where('plan_id', $plan->id)
                ->findOrFail((int) $validated['capture_id']);
        }

        $application = CareerApplication::create([
            'plan_id' => $plan->id,
            'company_name' => trim($validated['company_name']),
            'company_website' => $validated['company_website'] ?? null,
            'role_title' => trim((string) ($validated['role_title'] ?? '')) ?: null,
            'stage' => $validated['stage'],
            'status' => 'active',
            'source' => $capture?->source_type ?? 'manual',
            'applied_at' => in_array($validated['stage'], ['applied', 'screening', 'interview', 'final_interview', 'offer'], true) ? today() : null,
        ]);

        if ($capture) {
            $capture->update([
                'career_application_id' => $application->id,
                'status' => 'linked',
            ]);
        }

        $this->careerIntelligence->tryRefresh($plan);

        return redirect()
            ->route('plans.career.index', $plan)
            ->with('success', '応募先を追加しました。');
    }

    public function updateApplication(
        Request $request,
        Plan $plan,
        CareerApplication $application,
        PlanOwnershipService $ownership,
        PlanCategoryProfileService $profiles,
    ) {
        $ownership->authorizeEdit($request, $plan);
        $this->authorizeCareerPlan($plan, $profiles);
        abort_unless((int) $application->plan_id === (int) $plan->id, 404);

        $validated = $request->validate([
            'stage' => ['required', Rule::in(CareerApplication::STAGES)],
            'status' => ['required', Rule::in(CareerApplication::STATUSES)],
            'result' => ['nullable', 'string', 'max:32'],
        ]);

        if (
            ! $application->applied_at
            && in_array($validated['stage'], ['applied', 'screening', 'interview', 'final_interview', 'offer'], true)
        ) {
            $validated['applied_at'] = today();
        }

        $validated['result'] = trim((string) ($validated['result'] ?? '')) ?: null;
        $application->update($validated);
        $this->careerIntelligence->tryRefresh($plan);

        return redirect()
            ->route('plans.career.index', $plan)
            ->with('success', '応募状況を更新しました。');
    }

    public function linkCapture(
        Request $request,
        Plan $plan,
        CareerCapture $capture,
        PlanOwnershipService $ownership,
        PlanCategoryProfileService $profiles,
    ) {
        $ownership->authorizeEdit($request, $plan);
        $this->authorizeCareerPlan($plan, $profiles);
        abort_unless((int) $capture->plan_id === (int) $plan->id, 404);

        $validated = $request->validate([
            'career_application_id' => ['required', 'integer'],
        ]);

        $application = CareerApplication::query()
            ->where('plan_id', $plan->id)
            ->findOrFail((int) $validated['career_application_id']);

        $capture->update([
            'career_application_id' => $application->id,
            'status' => 'linked',
        ]);
        $this->careerIntelligence->tryRefresh($plan);

        return redirect()
            ->route('plans.career.index', $plan)
            ->with('success', 'Captureを応募先へ紐付けました。');
    }

    public function storeSelectionEvent(
        Request $request,
        Plan $plan,
        CareerApplication $application,
        PlanOwnershipService $ownership,
        PlanCategoryProfileService $profiles,
    ) {
        $ownership->authorizeEdit($request, $plan);
        $this->authorizeCareerPlan($plan, $profiles);
        abort_unless((int) $application->plan_id === (int) $plan->id, 404);

        $validated = $request->validate([
            'stage' => ['required', Rule::in(['interview', 'final_interview'])],
            'scheduled_at' => ['required', 'date'],
            'task_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $taskId = null;
        if (! empty($validated['task_id'])) {
            $taskId = $plan->tasks()->whereKey((int) $validated['task_id'])->value('id');
            abort_unless($taskId, 422, 'このPlanのTaskを指定してください。');
        } else {
            $interviewTasks = $plan->tasks()
                ->whereNotIn('status', ['done', 'cancelled'])
                ->get()
                ->filter(function ($task) {
                    $text = mb_strtolower(trim($task->title.' '.($task->description ?? '').' '.($task->next_action_note ?? '')));

                    return preg_match('/面接|面談|一次|二次|三次|最終|選考|逆質問/u', $text) === 1;
                })
                ->values();

            if ($interviewTasks->count() === 1) {
                $taskId = $interviewTasks->first()->id;
            }
        }

        $event = CareerSelectionEvent::create([
            'career_application_id' => $application->id,
            'task_id' => $taskId,
            'type' => 'interview',
            'stage' => $validated['stage'],
            'status' => 'scheduled',
            'scheduled_at' => $validated['scheduled_at'],
            'notes' => trim((string) ($validated['notes'] ?? '')) ?: null,
        ]);

        $application->update([
            'stage' => $validated['stage'],
            'status' => 'active',
            'next_event_at' => $event->scheduled_at,
            'result' => null,
        ]);
        $this->careerIntelligence->tryRefresh($plan);

        return redirect()
            ->route('plans.career.index', $plan)
            ->with('success', '面接予定を追加しました。');
    }

    public function cancelSelectionEvent(
        Request $request,
        Plan $plan,
        CareerSelectionEvent $event,
        PlanOwnershipService $ownership,
        PlanCategoryProfileService $profiles,
    ) {
        $ownership->authorizeEdit($request, $plan);
        $this->authorizeCareerPlan($plan, $profiles);
        $event->load('application');
        abort_unless((int) $event->application?->plan_id === (int) $plan->id, 404);

        $event->update(['status' => 'cancelled']);

        if (
            $event->application->next_event_at
            && $event->scheduled_at
            && $event->application->next_event_at->equalTo($event->scheduled_at)
        ) {
            $event->application->update(['next_event_at' => null]);
        }

        $this->careerIntelligence->tryRefresh($plan);

        return redirect()
            ->route('plans.career.index', $plan)
            ->with('success', '面接予定を中止しました。');
    }

    public function updateSelectionEventResult(
        Request $request,
        Plan $plan,
        CareerSelectionEvent $event,
        PlanOwnershipService $ownership,
        PlanCategoryProfileService $profiles,
        TaskEvidenceService $evidenceService,
        BehaviorIdentityService $identity,
    ) {
        $ownership->authorizeEdit($request, $plan);
        $this->authorizeCareerPlan($plan, $profiles);
        $event->load(['application', 'task']);
        abort_unless((int) $event->application?->plan_id === (int) $plan->id, 404);

        $validated = $request->validate([
            'result' => ['required', Rule::in(['passed', 'rejected', 'offer', 'withdrawn'])],
        ]);

        $actorToken = $identity->resolve($request);

        DB::transaction(function () use ($request, $event, $validated, $evidenceService, $actorToken) {
            $result = $validated['result'];

            $event->update([
                'status' => 'completed',
                'result' => $result,
                'completed_at' => $event->completed_at ?? now(),
            ]);

            $applicationValues = [
                'next_event_at' => null,
                'result' => $result,
            ];

            if ($result === 'rejected') {
                $applicationValues['stage'] = 'closed';
                $applicationValues['status'] = 'completed';
            } elseif ($result === 'withdrawn') {
                $applicationValues['stage'] = 'closed';
                $applicationValues['status'] = 'withdrawn';
            } elseif ($result === 'offer') {
                $applicationValues['stage'] = 'offer';
                $applicationValues['status'] = 'active';
            } else {
                $applicationValues['status'] = 'active';
            }

            $event->application->update($applicationValues);

            if ($event->task) {
                $evidenceService->record(
                    $event->task,
                    EvidenceSource::Native,
                    'interview_result_recorded',
                    [
                        'career_application_id' => (int) $event->career_application_id,
                        'career_selection_event_id' => (int) $event->id,
                        'company_name' => $event->application->company_name,
                        'stage' => $event->stage,
                        'result' => $result,
                    ],
                    confidence: 1.0,
                    externalKey: 'career-selection-event:'.$event->id.':result',
                    userId: $request->user()?->id,
                    actorToken: $actorToken,
                    occurredAt: now(),
                );
            }
        });

        $this->careerIntelligence->tryRefresh($plan);

        return redirect()
            ->route('plans.career.index', $plan)
            ->with('success', '選考結果を反映しました。');
    }

    private function authorizeCareerPlan(Plan $plan, PlanCategoryProfileService $profiles): void
    {
        abort_unless($profiles->forPlan($plan)->key === 'career', 404);
    }
}
