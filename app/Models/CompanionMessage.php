<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanionMessage extends Model
{
    public const ROLES = ['user', 'assistant'];

    protected $fillable = [
        'companion_thread_id',
        'user_id',
        'native_ai_run_id',
        'request_id',
        'role',
        'content',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function thread()
    {
        return $this->belongsTo(CompanionThread::class, 'companion_thread_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function nativeAiRun()
    {
        return $this->belongsTo(NativeAiRun::class);
    }

    public function mutationCandidates()
    {
        return $this->hasMany(CompanionMutationCandidate::class)->orderBy('id');
    }
}
