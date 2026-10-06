<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlanResource extends Model
{
    public const PROVIDERS = [
        'google_drive' => 'Google Drive',
        'onedrive' => 'OneDrive',
        'github' => 'GitHub',
        'device' => 'デバイス',
    ];

    public const RESOURCE_TYPES = [
        'file' => 'ファイル',
        'folder' => 'フォルダ',
    ];

    protected $fillable = [
        'plan_id',
        'created_by_user_id',
        'provider',
        'resource_type',
        'title',
        'url',
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

    public function tasks()
    {
        return $this->belongsToMany(Task::class, 'plan_resource_task')->withTimestamps();
    }

    public function recallSources()
    {
        return $this->hasMany(
            StudyRecallSource::class,
            'plan_resource_id',
        );
    }

    public function providerLabel(): string
    {
        return self::PROVIDERS[$this->provider] ?? $this->provider;
    }

    public function resourceTypeLabel(): string
    {
        return self::RESOURCE_TYPES[$this->resource_type] ?? $this->resource_type;
    }
}
