<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class UserPersonalizationContext extends Model
{
    protected $fillable = [
        'user_id',
        'version',
        'context_revision',
        'self_reported_context',
        'observed_context',
        'inferred_context',
        'guidance_level',
        'recommended_surfaces',
        'feature_readiness',
        'last_evaluated_at',
        'completed_at',
        'skipped_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'context_revision' => 'integer',
            'self_reported_context' => 'array',
            'observed_context' => 'array',
            'inferred_context' => 'array',
            'recommended_surfaces' => 'array',
            'feature_readiness' => 'array',
            'last_evaluated_at' => 'datetime',
            'completed_at' => 'datetime',
            'skipped_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
