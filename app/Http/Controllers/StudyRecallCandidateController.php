<?php

namespace App\Http\Controllers;

use App\Enums\FeatureKey;
use App\Exceptions\NativeAiExecutionException;
use App\Models\Plan;
use App\Models\StudyRecallCandidate;
use App\Models\StudyRecallItem;
use App\Models\StudyRecallSource;
use App\Models\Task;
use App\Services\BehaviorIdentityService;
use App\Services\FeatureAccessService;
use App\Services\PlanOwnershipService;
use App\Services\PlanCategoryProfileService;
use App\Services\StudyRecallCandidateExtractionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StudyRecallCandidateController extends Controller
{
    public function __construct(
        private readonly PlanCategoryProfileService $categoryProfiles,
    ) {}
    public function extract(
        Request $request,
        Plan $plan,
        Task $task,
        PlanOwnershipService $ownership,
        BehaviorIdentityService $identity,
        FeatureAccessService $featureAccess,
        StudyRecallCandidateExtractionService $extractor,
    ) {
        $this->authorizeTask($request, $plan, $task, $ownership);
        $featureAccess->authorizeUse(
            $request->user(),
            FeatureKey::AutomaticAiExecution,
            ['plan_id' => (int) $plan->id, 'task_id' => (int) $task->id],
        );

        $validated = $request->validate([
            'source_file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240', 'required_without:source_text'],
            'source_text' => ['nullable', 'string', 'max:50000', 'required_without:source_file'],
        ]);

        $actorToken = $identity->resolve($request);
        $file = $request->file('source_file');
        $sourceType = 'text';
        $path = null;
        $mime = null;
        $name = null;

        if ($file) {
            $mime = (string) $file->getMimeType();
            $name = (string) $file->getClientOriginalName();
            $sourceType = $mime === 'application/pdf' ? 'pdf' : 'image';
            $extension = strtolower((string) $file->getClientOriginalExtension());
            $path = $file->storeAs(
                'study-recall-sources/'.$plan->id.'/'.$task->id,
                (string) Str::uuid().'.'.$extension,
            );
        }

        $source = StudyRecallSource::query()->create([
            'plan_id' => (int) $plan->id,
            'task_id' => (int) $task->id,
            'user_id' => $request->user()?->id,
            'actor_token' => $request->user() ? null : $actorToken,
            'source_type' => $sourceType,
            'original_name' => $name,
            'mime_type' => $mime,
            'storage_path' => $path,
            'source_text' => $sourceType === 'text' ? trim((string) ($validated['source_text'] ?? '')) : null,
            'status' => 'pending',
        ]);

        try {
            $result = $extractor->extract($source, $plan, $task, $request->user()?->id);
        } catch (NativeAiExecutionException $exception) {
            return redirect()
                ->route('plans.tasks.study_recall.show', [$plan, $task])
                ->with('status', $exception->getMessage().' 教材は保存済みなので、設定確認後に再度取り込めます。');
        }

        return redirect()
            ->route('plans.tasks.study_recall.show', [$plan, $task])
            ->with('success', $result['created'].'件のRecall候補を作成しました。内容を確認してDeckへ追加してください。');
    }

    public function reviewBatch(
        Request $request,
        Plan $plan,
        Task $task,
        PlanOwnershipService $ownership,
    ) {
        $this->authorizeTask($request, $plan, $task, $ownership);

        $validated = $request->validate([
            'decision' => ['required', 'in:promote,reject'],
            'candidates' => ['required', 'array', 'max:100'],
            'candidates.*.selected' => ['nullable', 'boolean'],
            'candidates.*.prompt' => ['nullable', 'string', 'max:1000'],
            'candidates.*.answer' => ['nullable', 'string', 'max:8000'],
            'candidates.*.note' => ['nullable', 'string', 'max:4000'],
        ]);

        $selected = collect($validated['candidates'])
            ->filter(fn ($row) => (bool) ($row['selected'] ?? false));

        if ($selected->isEmpty()) {
            throw ValidationException::withMessages([
                'candidates' => '確認する候補を1件以上選択してください。',
            ]);
        }

        $candidateIds = $selected->keys()->map(fn ($id) => (int) $id)->values();
        $candidates = StudyRecallCandidate::query()
            ->where('plan_id', $plan->id)
            ->where('task_id', $task->id)
            ->where('status', 'pending')
            ->whereIn('id', $candidateIds)
            ->get()
            ->keyBy('id');

        if ($candidates->count() !== $candidateIds->count()) {
            throw ValidationException::withMessages([
                'candidates' => '候補の状態が変わりました。画面を再読み込みしてください。',
            ]);
        }

        if ($validated['decision'] === 'reject') {
            StudyRecallCandidate::query()
                ->whereIn('id', $candidateIds)
                ->update([
                    'status' => 'rejected',
                    'reviewed_by_user_id' => $request->user()?->id,
                    'reviewed_at' => now(),
                    'updated_at' => now(),
                ]);

            return redirect()
                ->route('plans.tasks.study_recall.show', [$plan, $task])
                ->with('status', $candidateIds->count().'件の候補を見送りました。');
        }

        $promoted = 0;
        $duplicates = 0;

        DB::transaction(function () use ($selected, $candidates, $plan, $task, $request, &$promoted, &$duplicates) {
            foreach ($selected as $id => $row) {
                /** @var StudyRecallCandidate $candidate */
                $candidate = $candidates->get((int) $id);
                $prompt = trim((string) ($row['prompt'] ?? $candidate->prompt));
                $answer = trim((string) ($row['answer'] ?? $candidate->answer));
                $note = trim((string) ($row['note'] ?? $candidate->note));

                if ($prompt === '' || $answer === '') {
                    throw ValidationException::withMessages([
                        'candidates' => '表と裏は空にできません。',
                    ]);
                }

                $fingerprint = hash('sha256', $this->normalize($prompt).'|'.$this->normalize($answer));
                $item = StudyRecallItem::query()->firstOrCreate(
                    [
                        'task_id' => (int) $task->id,
                        'fingerprint' => $fingerprint,
                    ],
                    [
                        'plan_id' => (int) $plan->id,
                        'prompt' => $prompt,
                        'answer' => $answer,
                        'note' => $note !== '' ? $note : null,
                        'tags' => $candidate->tags ?? [],
                        'repetitions' => 0,
                        'lapse_count' => 0,
                        'interval_days' => 0,
                        'ease_factor' => 2.50,
                        'due_at' => null,
                        'is_active' => true,
                    ],
                );

                $item->wasRecentlyCreated ? $promoted++ : $duplicates++;

                $candidate->update([
                    'prompt' => $prompt,
                    'answer' => $answer,
                    'note' => $note !== '' ? $note : null,
                    'status' => 'promoted',
                    'promoted_item_id' => (int) $item->id,
                    'reviewed_by_user_id' => $request->user()?->id,
                    'reviewed_at' => now(),
                ]);
            }
        });

        $message = $promoted.'件をRecall Deckへ追加しました。';
        if ($duplicates > 0) {
            $message .= ' '.$duplicates.'件は既存カードと重複していたため再作成していません。';
        }

        return redirect()
            ->route('plans.tasks.study_recall.show', [$plan, $task])
            ->with('success', $message);
    }

    public function sourceFile(
        Request $request,
        Plan $plan,
        Task $task,
        StudyRecallSource $source,
        PlanOwnershipService $ownership,
    ) {
        abort_unless((int) $task->plan_id === (int) $plan->id, 404);
        abort_unless((int) $source->plan_id === (int) $plan->id && (int) $source->task_id === (int) $task->id, 404);
        $ownership->authorizeTaskView($request, $task);

        abort_unless($source->storage_path && Storage::exists($source->storage_path), 404);

        return Storage::response(
            $source->storage_path,
            $source->original_name ?: basename($source->storage_path),
            ['Content-Disposition' => 'inline'],
        );
    }

    private function authorizeTask(Request $request, Plan $plan, Task $task, PlanOwnershipService $ownership): void
    {
        abort_unless((int) $task->plan_id === (int) $plan->id, 404);
        abort_unless($this->categoryProfiles->forPlan($plan)->key === 'study', 404);
        $ownership->authorizeTask($request, $task);
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value));
    }
}
