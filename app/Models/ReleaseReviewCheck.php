<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ReleaseReviewCheck extends Model
{
    public const STATUS_PASSED = 'passed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'release_level',
        'check_key',
        'status',
        'note',
        'reviewed_by_user_id',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'release_level' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    public function reviewedBy()
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
