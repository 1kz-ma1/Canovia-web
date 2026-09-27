<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    protected $fillable = [
        'plan_id',
        'depends_on_task_id',
        'continuation_of_task_id',
        'lineage_source_task_ids',
        'lineage_source_snapshots',
        'title',
        'description',
        'estimated_minutes',
        'remaining_minutes',
        'progress_percent',
        'progress_reason',
        'next_action_note',
        'status',
        'priority',
        'activation_cost',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'estimated_minutes' => 'integer',
            'remaining_minutes' => 'integer',
            'progress_percent' => 'integer',
            'priority' => 'integer',
            'activation_cost' => 'integer',
            'sort_order' => 'integer',
            'lineage_source_task_ids' => 'array',
            'lineage_source_snapshots' => 'array',
        ];
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function resources()
    {
        return $this->belongsToMany(PlanResource::class, 'plan_resource_task')->withTimestamps();
    }

    public function artifacts()
    {
        return $this->belongsToMany(PlanArtifact::class, 'plan_artifact_task')->withTimestamps();
    }

    public function workLogs()
    {
        return $this->hasMany(WorkLog::class);
    }

    public function guidedExecutions()
    {
        return $this->hasMany(GuidedExecution::class)->latest('prepared_at')->latest('id');
    }

    public function studyPracticeAttempts()
    {
        return $this->hasMany(StudyPracticeAttempt::class);
    }

    public function studyRecallItems()
    {
        return $this->hasMany(StudyRecallItem::class);
    }

    public function studyRecallReviews()
    {
        return $this->hasMany(StudyRecallReview::class);
    }

    public function evidences()
    {
        return $this->hasMany(TaskEvidence::class)->latest('occurred_at')->latest('id');
    }

    public function milestones()
    {
        return $this->hasMany(TaskMilestone::class)->orderBy('sort_order')->orderBy('id');
    }

    public function careerSelectionEvents()
    {
        return $this->hasMany(CareerSelectionEvent::class);
    }

    public function continuationOf()
    {
        return $this->belongsTo(self::class, 'continuation_of_task_id');
    }

    public function continuations()
    {
        return $this->hasMany(self::class, 'continuation_of_task_id');
    }

    public function prerequisite()
    {
        return $this->belongsTo(self::class, 'depends_on_task_id');
    }

    public function dependents()
    {
        return $this->hasMany(self::class, 'depends_on_task_id');
    }
}
