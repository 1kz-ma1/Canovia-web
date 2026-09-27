<?php

namespace App\Models;

use App\Support\ReleaseNotes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ReleaseNote extends Model
{
    protected static function booted(): void
    {
        static::saved(fn () => ReleaseNotes::forgetCache());
        static::deleted(fn () => ReleaseNotes::forgetCache());
    }

    protected $fillable = [
        'feedback_id',
        'version',
        'title',
        'summary',
        'user_voice',
        'highlights',
        'tip',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'highlights' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function feedback()
    {
        return $this->belongsTo(Feedback::class);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_at')->where('published_at', '<=', now());
    }
}
