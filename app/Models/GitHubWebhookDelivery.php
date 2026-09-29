<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class GitHubWebhookDelivery extends Model
{
    protected $fillable = [
        'delivery_id',
        'event_name',
        'action',
        'repo_full_name',
        'installation_id',
        'pull_request_numbers',
        'status',
        'matched_artifacts',
        'synced_tasks',
        'skipped_entitlement',
        'attempts',
        'last_error',
        'received_at',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'installation_id' => 'integer',
            'pull_request_numbers' => 'array',
            'matched_artifacts' => 'integer',
            'synced_tasks' => 'integer',
            'skipped_entitlement' => 'integer',
            'attempts' => 'integer',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
