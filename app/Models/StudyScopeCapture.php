<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudyScopeCapture extends Model
{
    public const STATUSES = [
        'captured',
        'review',
        'confirmed',
        'failed',
    ];

    protected $fillable = [
        'plan_id',
        'user_id',
        'inbox_item_id',
        'native_ai_run_id',
        'status',
        'exam_title',
        'exam_date_text',
        'exam_date',
        'draft_data',
        'confidence',
        'extraction_version',
        'failure_code',
        'analyzed_at',
        'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'exam_date' => 'date',
            'draft_data' => 'array',
            'confidence' => 'float',
            'analyzed_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function inboxItem()
    {
        return $this->belongsTo(InboxItem::class);
    }

    public function nativeAiRun()
    {
        return $this->belongsTo(NativeAiRun::class);
    }

    public function items()
    {
        return $this->hasMany(StudyScopeItem::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function sourceLabel(): string
    {
        return $this->inboxItem?->sourceLabel() ?? '学習範囲';
    }
}
