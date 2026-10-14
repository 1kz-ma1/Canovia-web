<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class DevelopmentOperationalProfile extends Model
{
    public const STAGES = ['unknown', 'prototype', 'preview', 'public_launch', 'live'];
    public const RUNTIMES = ['unknown', 'web', 'ios', 'android', 'hybrid', 'backend', 'other'];
    public const DATA_STORES = ['unknown', 'none', 'local_device', 'server_db', 'cloud_files', 'mixed'];

    protected $fillable = ['plan_id', 'stage', 'runtime', 'data_store'];

    protected function casts(): array
    {
        return ['data_store_revision' => 'integer'];
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return array{stage:string,runtime:string,data_store:string,data_store_revision:int} */
    public function summary(): array
    {
        return [
            'stage' => in_array($this->stage, self::STAGES, true) ? $this->stage : 'unknown',
            'runtime' => in_array($this->runtime, self::RUNTIMES, true) ? $this->runtime : 'unknown',
            'data_store' => in_array($this->data_store, self::DATA_STORES, true) ? $this->data_store : 'unknown',
            'data_store_revision' => (int) ($this->data_store_revision ?? 0),
        ];
    }
}
