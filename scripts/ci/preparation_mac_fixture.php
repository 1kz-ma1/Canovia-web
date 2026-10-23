<?php

// Public fake data only. The launcher migrates a fresh owner-private SQLite first.
$database = getenv('DB_DATABASE');
$storage = getenv('LARAVEL_STORAGE_PATH');
if (getenv('CANOVIA_MAC_LAB_FIXTURE') !== '1' || getenv('APP_ENV') !== 'local'
    || getenv('DB_CONNECTION') !== 'sqlite' || ! $storage
    || $database !== dirname($storage).'/synthetic.sqlite'
    || ! str_starts_with(basename(dirname($storage)), 'canovia-preparation-mac-')
    || ! is_file($database) || is_link($database)) {
    exit(2);
}
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
\Illuminate\Support\Facades\Http::preventStrayRequests();
if (\App\Models\User::query()->exists()) {
    exit(2);
}
foreach (['tester' => 1, 'normal' => null, 'admin' => null] as $role => $level) {
    \App\Models\User::factory()->create(['name' => 'Synthetic '.$role, 'email' => $role.'@example.test',
        'password' => \Illuminate\Support\Facades\Hash::make('Synthetic-Preparation-Only-2026'),
        'release_level_override' => $level]);
}
$owner = \App\Models\User::where('email', 'tester@example.test')->sole();
foreach (['資格学習' => '合成AP学習Plan', '個人開発' => '合成Development Plan'] as $category => $title) {
    $plan = \App\Models\Plan::create(['user_id' => $owner->id, 'owner_token' => \Illuminate\Support\Str::random(64),
        'public_slug' => (string) \Illuminate\Support\Str::uuid(), 'title' => $title, 'category' => $category,
        'start_date' => today(), 'is_public' => false, 'is_collaborative' => false]);
    $task = \App\Models\Task::create(['plan_id' => $plan->id, 'title' => '合成の既存Task', 'status' => 'doing',
        'estimated_minutes' => 30, 'remaining_minutes' => 25, 'progress_percent' => 17,
        'priority' => 1, 'activation_cost' => 1, 'sort_order' => 1]);
    if ($category === '資格学習') {
        \App\Models\StudyPracticeAttempt::create(['plan_id' => $plan->id, 'task_id' => $task->id, 'user_id' => $owner->id,
            'request_hash' => hash('sha256', 'public-synthetic-attempt'), 'exercise_title' => '合成の採点履歴',
            'questions' => [], 'answers' => [], 'assessment' => [], 'score_percent' => 60,
            'recommended_task_progress_percent' => 17, 'progress_before_percent' => 17, 'progress_after_percent' => 17]);
    }
}
echo "SYNTHETIC_FIXTURE_CREATED\n";
