<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class PlanActionDraft extends Model
{
    public const PROPOSED = 'proposed';
    public const ACCEPTED = 'accepted';
    public const DISMISSED = 'dismissed';

    protected $fillable = ['plan_id', 'request_id', 'source_work_log_id', 'source_kind',
        'completed_action', 'observed_outcome', 'suggested_next_action', 'status',
        'accepted_task_id', 'accepted_at'];

    protected function casts(): array { return ['accepted_at' => 'datetime']; }
}
