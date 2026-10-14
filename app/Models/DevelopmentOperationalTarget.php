<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class DevelopmentOperationalTarget extends Model
{
    public const PROVIDERS = ['render', 'other'];
    public const ENVIRONMENTS = ['staging', 'production'];

    protected $fillable = [
        'plan_id', 'provider', 'environment', 'resource_ref',
        'revision', 'backup_confirmed_at', 'backup_revision',
        'backup_store', 'restore_confirmed_at', 'restore_revision',
        'restore_store', 'service_checked_at',
        'service_checked_revision', 'service_checked_environment',
    ];

    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'backup_confirmed_at' => 'datetime',
            'restore_confirmed_at' => 'datetime',
            'backup_revision' => 'integer',
            'restore_revision' => 'integer',
            'service_checked_at' => 'datetime',
            'service_checked_revision' => 'integer',
        ];
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }
}
