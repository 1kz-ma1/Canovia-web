<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudyScoreObservation extends Model
{
    public const SOURCES = [
        'self_reported' => '自己申告',
        'official_result' => '公式結果',
        'mock_exam' => '模試・模擬試験',
        'school_result' => '学校の結果',
        'imported' => '外部から取り込み',
    ];

    protected $fillable = [
        'plan_id',
        'user_id',
        'actor_token',
        'request_id',
        'metric_key',
        'metric_label',
        'score_value',
        'scale_min',
        'scale_max',
        'unit',
        'source_kind',
        'source_label',
        'components',
        'observed_at',
    ];

    protected function casts(): array
    {
        return [
            'score_value' => 'float',
            'scale_min' => 'float',
            'scale_max' => 'float',
            'components' => 'array',
            'observed_at' => 'datetime',
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

    public function sourceLabel(): string
    {
        return self::SOURCES[$this->source_kind]
            ?? $this->source_kind;
    }

    public function displayValue(): string
    {
        $value = (float) $this->score_value;
        $formatted = floor($value) === $value
            ? (string) (int) $value
            : rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');

        return $formatted.(
            $this->unit === 'band'
                ? ''
                : ($this->unit === 'percent' ? '%' : '点')
        );
    }
}
