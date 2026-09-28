<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlanArtifact extends Model
{
    public const PROVIDERS = [
        'google_drive' => 'Google Drive',
        'onedrive' => 'OneDrive',
        'github' => 'GitHub',
        'canovia' => 'Canovia',
        'external' => 'その他URL',
    ];

    public const ARTIFACT_TYPES = [
        'file' => 'ファイル',
        'folder' => 'フォルダ',
        'repository' => 'リポジトリ',
        'link' => 'リンク',
    ];

    public const COLLABORATION_STATES = [
        'active' => '進行中',
        'review' => 'レビュー待ち',
        'waiting' => '相手待ち',
        'external_followup' => '外部確認',
    ];

    protected $fillable = [
        'plan_id',
        'created_by_user_id',
        'assigned_user_id',
        'provider',
        'artifact_type',
        'title',
        'url',
        'version_label',
        'notes',
        'external_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function tasks()
    {
        return $this->belongsToMany(Task::class, 'plan_artifact_task')->withTimestamps();
    }

    public function providerLabel(): string
    {
        return self::PROVIDERS[$this->provider] ?? $this->provider;
    }

    public function artifactTypeLabel(): string
    {
        return self::ARTIFACT_TYPES[$this->artifact_type] ?? $this->artifact_type;
    }

    public function collaborationState(): ?string
    {
        $state = data_get($this->metadata, 'collaboration_state');

        return is_string($state) && array_key_exists($state, self::COLLABORATION_STATES)
            ? $state
            : null;
    }

    public function collaborationStateLabel(): ?string
    {
        $state = $this->collaborationState();

        return $state ? self::COLLABORATION_STATES[$state] : null;
    }
}
