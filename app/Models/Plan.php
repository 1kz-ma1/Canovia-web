<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    public const ACCENT_KEYS = ['sky', 'emerald', 'violet', 'amber', 'rose', 'cyan'];

    public const ACCENT_LABELS = [
        'sky' => '青',
        'emerald' => '緑',
        'violet' => '紫',
        'amber' => 'オレンジ',
        'rose' => 'ピンク',
        'cyan' => 'ターコイズ',
    ];

    public const ROADMAP_WORLDS = ['default', 'study', 'sweet', 'halloween', 'space', 'forest'];

    protected $hidden = ['owner_token', 'creation_request_id', 'collaboration_share_token', 'collaboration_join_code'];

    protected $fillable = [
        'user_id',
        'owner_token',
        'creation_request_id',
        'public_slug',
        'title',
        'description',
        'category',
        'workspace_domain_override',
        'priority',
        'priority_mode',
        'start_date',
        'deadline',
        'is_public',
        'is_collaborative',
        'collaboration_join_code',
        'collaboration_share_token',
        'last_ai_context_exported_at',
        'visual_icon',
        'accent_key',
        'roadmap_world',
        'study_learning_type_override',
        'study_learning_type_confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'start_date' => 'date',
            'deadline' => 'date',
            'is_public' => 'boolean',
            'is_collaborative' => 'boolean',
            'last_ai_context_exported_at' => 'datetime',
            'study_learning_type_confirmed_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function memberships()
    {
        return $this->hasMany(PlanMember::class);
    }

    public function activityLogs()
    {
        return $this->hasMany(\App\Models\PlanActivityLog::class)->latest('created_at')->latest('id');
    }

    public function members()
    {
        return $this->belongsToMany(User::class, 'plan_members')
            ->withPivot(['role', 'joined_at'])
            ->withTimestamps();
    }

    public function tasks()
    {
        return $this->hasMany(Task::class);
    }

    public function goalContext()
    {
        return $this->hasOne(GoalContext::class);
    }

    public function resources()
    {
        return $this->hasMany(PlanResource::class);
    }

    public function artifacts()
    {
        return $this->hasMany(PlanArtifact::class);
    }

    public function workLogs()
    {
        return $this->hasMany(WorkLog::class);
    }

    public function guidedExecutions()
    {
        return $this->hasMany(GuidedExecution::class)->latest('prepared_at')->latest('id');
    }

    public function adjustments()
    {
        return $this->hasMany(PlanAdjustment::class);
    }

    public function studyPracticeAttempts()
    {
        return $this->hasMany(StudyPracticeAttempt::class);
    }

    public function studyScopeCaptures()
    {
        return $this->hasMany(StudyScopeCapture::class);
    }

    public function studyScopeItems()
    {
        return $this->hasMany(StudyScopeItem::class);
    }

    public function taskEvidences()
    {
        return $this->hasMany(TaskEvidence::class);
    }

    public function executionPreferences()
    {
        return $this->hasMany(PlanExecutionPreference::class);
    }

    public function executionActivities()
    {
        return $this->hasMany(ExecutionActivity::class);
    }

    public function intelligenceActionProjections()
    {
        return $this->hasMany(IntelligenceActionProjection::class);
    }

    public function careerApplications()
    {
        return $this->hasMany(CareerApplication::class);
    }

    public function careerCaptures()
    {
        return $this->hasMany(CareerCapture::class);
    }

    public function interviewReviews()
    {
        return $this->hasMany(InterviewReview::class);
    }

    public function availabilityRules()
    {
        return $this->hasMany(PlanAvailabilityRule::class);
    }

    public function availabilityOverrides()
    {
        return $this->hasMany(PlanAvailabilityOverride::class);
    }

    public function displayIcon(): string
    {
        if (filled($this->visual_icon)) {
            return mb_substr((string) $this->visual_icon, 0, 4);
        }

        return match ($this->category) {
            '資格学習' => '📘',
            'ゲーム開発' => '🎮',
            '個人開発' => '💻',
            '制作活動' => '🛠️',
            '就活・キャリア', '就活', '就職活動', '就職・将来', '転職', 'キャリア' => '💼',
            default => '🧭',
        };
    }

    public function accentKey(): string
    {
        $accent = (string) ($this->accent_key ?: 'sky');

        return in_array($accent, self::ACCENT_KEYS, true) ? $accent : 'sky';
    }

    public function roadmapWorld(): string
    {
        $world = (string) ($this->roadmap_world ?: 'default');

        return in_array($world, self::ROADMAP_WORLDS, true) ? $world : 'default';
    }
}
