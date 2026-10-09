<?php

namespace App\Services;

use App\Models\LearningRun;
use App\Models\LearningRunItem;
use Illuminate\Http\Request;

/**
 * Session-lifetime, actor-scoped, ungraded Learning answer drafts.
 *
 * Drafts are not AnswerEvents and never mutate Task/Attempt/grade state.
 * They do not follow the learner to another browser or expired session.
 */
final class AdaptiveLearningAnswerDraftService
{
    private const KEY = 'adaptive_learning_answer_drafts_v1';

    private function owner(Request $request, LearningRun $run): string
    {
        if ($request->user()) {
            return 'user:'.$request->user()->getAuthIdentifier();
        }

        return 'guest:'.hash('sha256', (string) $run->actor_token);
    }

    public function restore(Request $request, LearningRun $run, LearningRunItem $item): array
    {
        if ($run->status !== LearningRun::STATUS_ACTIVE || $item->answer()->exists()) {
            return [];
        }

        $entry = $request->session()->get(self::KEY.'.'.$run->id.'.'.$item->id);

        return is_array($entry)
            && ($entry['actor'] ?? null) === $this->owner($request, $run)
            && ($entry['type'] ?? null) === data_get($item->question_snapshot, 'response_field.type')
            && is_array($entry['fields'] ?? null)
            ? $entry['fields']
            : [];
    }

    public function save(Request $request, LearningRun $run, LearningRunItem $item, array $fields): void
    {
        $entry = [
            'actor' => $this->owner($request, $run),
            'type' => data_get($item->question_snapshot, 'response_field.type'),
            'fields' => $fields,
        ];
        // At most one draft per run: a newly served item retires the old
        // draft, without modifying another Learning Run in this session.
        $request->session()->forget(self::KEY.'.'.$run->id);
        $request->session()->put(self::KEY.'.'.$run->id.'.'.$item->id, $entry);
    }

    public function forgetItem(Request $request, LearningRun $run, int $itemId): void
    {
        $request->session()->forget(self::KEY.'.'.$run->id.'.'.$itemId);
    }

    public function forgetRun(Request $request, LearningRun $run): void
    {
        $request->session()->forget(self::KEY.'.'.$run->id);
    }
}
