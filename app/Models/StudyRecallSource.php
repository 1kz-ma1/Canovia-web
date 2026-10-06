<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class StudyRecallSource extends Model
{
    public const TYPES = ['image', 'pdf', 'text'];
    public const STATUSES = ['pending', 'ready', 'failed'];

    protected $fillable = [
        'plan_id',
        'task_id',
        'plan_resource_id',
        'user_id',
        'actor_token',
        'source_type',
        'original_name',
        'mime_type',
        'storage_path',
        'source_text',
        'status',
        'native_ai_run_id',
        'candidate_count',
    ];

    protected function casts(): array
    {
        return [
            'candidate_count' => 'integer',
        ];
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    public function resource()
    {
        return $this->belongsTo(
            PlanResource::class,
            'plan_resource_id',
        );
    }

    public function candidates()
    {
        return $this->hasMany(StudyRecallCandidate::class);
    }

    public function hasStoredMaterial(): bool
    {
        if ($this->source_type === 'text') {
            return trim((string) $this->source_text) !== '';
        }

        if (in_array($this->source_type, ['image', 'pdf'], true)) {
            return filled($this->storage_path)
                && Storage::exists((string) $this->storage_path);
        }

        return false;
    }

    public function sourceLabel(): string
    {
        return match ($this->source_type) {
            'image' => '画像・スクリーンショット',
            'pdf' => 'PDF',
            'text' => 'テキスト',
            default => '教材',
        };
    }
}
