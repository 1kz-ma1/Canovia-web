<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlanMember extends Model
{
    public const ROLE_VIEWER = 'viewer';
    public const ROLE_EDITOR = 'editor';
    public const ROLES = [self::ROLE_VIEWER, self::ROLE_EDITOR];

    protected $fillable = [
        'plan_id',
        'user_id',
        'role',
        'invited_by_user_id',
        'joined_at',
    ];

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'authorization_epoch' => 'integer',
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

    public function invitedBy()
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }
}
