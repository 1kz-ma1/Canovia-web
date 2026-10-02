<section class="canovia-star-task-list" data-constellation-star-group="{{ $star['id'] }}">
    <header>
        <div>
            <p>STAR / TASK GROUP</p>
            <h2>{{ $star['label'] }}</h2>
        </div>
        <span>{{ $star['completed_count'] }} / {{ $star['task_count'] }} 完了</span>
    </header>

    <div class="canovia-star-task-items">
        @foreach ($star['tasks'] as $task)
            <article
                class="canovia-star-task-item"
                data-task-status="{{ $task['status'] }}"
                data-task-current="{{ $task['is_current'] ? '1' : '0' }}"
            >
                <span class="canovia-star-task-state" aria-hidden="true"></span>
                <div>
                    <strong>{{ $task['title'] }}</strong>
                    <small>
                        {{ $task['status_label'] }}
                        · 進捗 {{ $task['progress_percent'] }}%
                        · 残り {{ $task['remaining_minutes'] }}分
                    </small>
                    @if (! empty($task['next_action_note']))
                        <p>次: {{ $task['next_action_note'] }}</p>
                    @elseif (! empty($task['description']))
                        <p>{{ \Illuminate\Support\Str::limit($task['description'], 120, '…') }}</p>
                    @endif
                </div>
            </article>
        @endforeach
    </div>

    <div class="canovia-star-task-actions">
        <a href="{{ route('navigation.index', ['plan_id' => $constellation['plan_id']]) }}" class="btn-primary">
            このPlanを実行
        </a>
        <a href="{{ route('plans.show', $constellation['plan_id']) }}" class="btn-secondary">
            Plan詳細
        </a>
    </div>
</section>
