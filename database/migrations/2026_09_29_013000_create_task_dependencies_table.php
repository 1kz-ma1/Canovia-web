<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_dependencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('prerequisite_task_id')->constrained('tasks')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['task_id', 'prerequisite_task_id']);
            $table->index('prerequisite_task_id');
        });

        $now = now();
        DB::table('tasks as child')
            ->join('tasks as prerequisite', 'prerequisite.id', '=', 'child.depends_on_task_id')
            ->whereNotNull('child.depends_on_task_id')
            ->whereColumn('child.plan_id', 'prerequisite.plan_id')
            ->orderBy('child.id')
            ->get([
                'child.id as task_id',
                'child.depends_on_task_id as prerequisite_task_id',
            ])
            ->each(function ($edge) use ($now) {
                if ((int) $edge->task_id === (int) $edge->prerequisite_task_id) {
                    return;
                }

                DB::table('task_dependencies')->insertOrIgnore([
                    'task_id' => (int) $edge->task_id,
                    'prerequisite_task_id' => (int) $edge->prerequisite_task_id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_dependencies');
    }
};
