<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class UserPersonalizationContext extends Model
{
    protected $fillable = [
        'user_id',
        'version',
        'domains',
        'common_context',
        'domain_context',
        'guidance_level',
        'recommended_surfaces',
        'feature_readiness',
        'completed_at',
        'skipped_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'domains' => 'array',
            'common_context' => 'array',
            'domain_context' => 'array',
            'recommended_surfaces' => 'array',
            'feature_readiness' => 'array',
            'completed_at' => 'datetime',
            'skipped_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
