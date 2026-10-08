<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class PlanActionDraftRevision extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'plan_action_draft_id', 'from_revision', 'to_revision',
        'before_action', 'after_action', 'added_evidence_snapshots', 'created_at',
    ];

    protected function casts(): array
    {
        return ['added_evidence_snapshots' => 'array', 'created_at' => 'datetime'];
    }
}
