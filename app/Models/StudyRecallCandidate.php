<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudyRecallCandidate extends Model
{
    public const STATUSES = ['pending', 'promoted', 'rejected'];

    protected $fillable = [
        'study_recall_source_id',
        'plan_id',
        'task_id',
        'promoted_item_id',
        'reviewed_by_user_id',
        'prompt',
        'answer',
        'note',
        'tags',
        'source_excerpt',
        'confidence',
        'status',
        'fingerprint',
        'review_provenance',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'review_provenance' => 'array',
            'confidence' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    public function source()
    {
        return $this->belongsTo(StudyRecallSource::class, 'study_recall_source_id');
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    public function promotedItem()
    {
        return $this->belongsTo(StudyRecallItem::class, 'promoted_item_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
