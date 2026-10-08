<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class PlanActionDraftStep extends Model
{
    protected $fillable = [
        'plan_action_draft_id', 'sort_order', 'evidence_revision',
        'title', 'accepted_task_id', 'proposal_fingerprint',
    ];

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'evidence_revision' => 'integer'];
    }

    public function draft()
    {
        return $this->belongsTo(PlanActionDraft::class, 'plan_action_draft_id');
    }

    public function acceptedTask()
    {
        return $this->belongsTo(Task::class, 'accepted_task_id');
    }
}
