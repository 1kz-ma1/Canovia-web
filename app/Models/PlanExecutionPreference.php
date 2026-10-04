<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlanExecutionPreference extends Model
{
    protected $fillable = [
        'plan_id',
        'capability',
        'provider_key',
        'user_selected',
    ];

    protected function casts(): array
    {
        return [
            'user_selected' => 'boolean',
        ];
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }
}
