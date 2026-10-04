<?php

namespace App\Contracts;

use App\Models\ExecutionActivity;
use App\Models\Task;
use App\Models\TaskEvidence;

interface ExecutionActivityProjector
{
    public function project(
        ExecutionActivity $activity,
        Task $task,
    ): TaskEvidence;
}
