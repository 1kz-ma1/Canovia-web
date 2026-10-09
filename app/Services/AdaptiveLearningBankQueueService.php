<?php
namespace App\Services;

use App\Models\LearningRun;
use App\Models\LearningRunItem;
use App\Models\LearningRunCandidate;
use App\Models\Question;
use App\Models\QuestionPack;
use Illuminate\Validation\ValidationException;

/**
 * Bank-only initial slice: immutable ready questions, never a hidden AI call.
 * Adaptive candidate re-ranking belongs to a later separately gated release.
 */
final class AdaptiveLearningBankQueueService
{
    public function isSupported(Question $question): bool
    {
        $schema = $question->response_schema ?? [];
        $rule = $question->grading_rule ?? [];
        if (! is_array($schema) || ! is_array($rule)
            || ($rule['field_id'] ?? '') !== 'answer') {
            return false;
        }

        $input = collect($schema)->firstWhere('id', 'answer');
        if (! is_array($input) || ! (bool) ($input['required'] ?? true)) {
            return false;
        }

        // The single-answer Run must not claim to grade any other required
        // field. Optional reasoning can remain available in legacy Practice.
        if (collect($schema)->contains(fn ($field) => is_array($field)
            && (bool) ($field['required'] ?? true) && ($field['id'] ?? '') !== 'answer')) {
            return false;
        }

        $type = (string) ($rule['type'] ?? '');
        if ($type === 'numeric_tolerance') {
            return ($input['type'] ?? '') === 'number'
                && is_numeric($rule['answer'] ?? null)
                && is_finite((float) $rule['answer'])
                && is_numeric($rule['tolerance'] ?? 0)
                && is_finite((float) ($rule['tolerance'] ?? 0))
                && (float) ($rule['tolerance'] ?? 0) >= 0;
        }

        $choices = $input['choices'] ?? [];
        if (! is_array($choices) || count($choices) < 2 || count($choices) > 8) {
            return false;
        }
        $ids = collect($choices)->pluck('id')->map('strval')->all();
        if (count($ids) !== count(array_unique($ids))
            || collect($ids)->contains(fn ($id) => $id === '' || mb_strlen($id) > 20)) {
            return false;
        }

        if ($type === 'exact_choice' && ($input['type'] ?? '') === 'single_choice') {
            return in_array((string) ($rule['answer'] ?? ''), $ids, true);
        }
        if ($type === 'exact_multiple' && ($input['type'] ?? '') === 'multiple_choice') {
            $answers = $rule['answers'] ?? null;
            return is_array($answers) && count($answers) > 0
                && count($answers) === count(array_unique($answers))
                && collect($answers)->every(fn ($answer) =>
                    is_string($answer) && in_array($answer, $ids, true));
        }
        return false;
    }

    /** Keep the initial ready queue nonempty without mutating already queued questions. */
    public function refill(LearningRun $run): void
    {
        if ($run->status !== LearningRun::STATUS_ACTIVE || ! $run->question_pack_id) return;
        $pack = QuestionPack::query()->whereKey($run->question_pack_id)
            ->where('status', 'published')->first();
        if (! $pack) return;

        // The depth is an experiment setting, not a final optimal constant.
        $target = max(1, min(5, (int) config('study.adaptive_learning.locked_queue_size', 2)));
        $reserved = LearningRunItem::where('learning_run_id', $run->id)
            ->where('ordinal', '>=', $run->current_ordinal)->count();
        if ($reserved >= $target) return;

        $used = $run->items()->pluck('question_id')->filter()->map(fn ($id) => (int) $id)->all();
        $nextOrdinal = (int) LearningRunItem::where('learning_run_id', $run->id)->max('ordinal') + 1;
        $available = $pack->questions()->where('is_active', true)
            ->whereNotIn('id', $used)->get()
            ->filter(fn (Question $q) => $this->isSupported($q))
            ->keyBy('id');
        $candidateIds = LearningRunCandidate::where('learning_run_id', $run->id)
            ->orderBy('position')->pluck('question_id')->all();
        $preferred = collect($candidateIds)
            ->map(fn ($id) => $available->get((int) $id))->filter()->values();
        $preferredIds = $preferred->pluck('id')->map(fn ($id) => (int) $id)->all();
        $fallback = $available->reject(fn (Question $q) =>
            in_array((int) $q->id, $preferredIds, true))->values();

        foreach ($preferred->concat($fallback)->take($target - $reserved) as $question) {
            $input = collect($question->response_schema)->firstWhere('id', 'answer');
            $run->items()->create([
                'ordinal' => $nextOrdinal++,
                'question_id' => $question->id,
                'question_snapshot' => [
                    'prompt' => $question->prompt,
                    'source_type' => $question->source_type,
                    'source_reference' => $question->source_reference,
                    'pack_version' => $run->pack_version_snapshot,
                    'learning_metadata' => $question->learning_metadata ?? [],
                    'response_field' => [
                        'id' => 'answer',
                        'label' => (string) ($input['label'] ?? '回答'),
                        'type' => $input['type'],
                        'choices' => $input['choices'] ?? [],
                    ],
                ],
                'grading_rule_snapshot' => $question->grading_rule,
                'explanation_snapshot' => $question->explanation,
                'presented_at' => $nextOrdinal === 2 && (int) $run->current_ordinal === 1
                    ? now() : null,
            ]);
        }
    }

    public function requireSupportedPack(QuestionPack $pack): void
    {
        if ($pack->status !== 'published' || ! $pack->questions()->where('is_active', true)
            ->get()->contains(fn (Question $q) => $this->isSupported($q))) {
            throw ValidationException::withMessages([
                'question_pack_id' => 'この問題集には現在対応している単一選択・複数選択・数値問題がありません。',
            ]);
        }
    }
}
