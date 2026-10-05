<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class StudyScenarioFixture extends Model
{
    protected $fillable = [
        'user_id',
        'plan_id',
        'scenario_key',
        'scenario_version',
    ];

    protected function casts(): array
    {
        return [
            'scenario_version' => 'integer',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }
}
