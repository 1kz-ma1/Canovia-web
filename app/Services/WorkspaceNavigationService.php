<?php
namespace App\Services;

use App\Enums\WorkspaceMode;
use App\Models\Plan;
use App\Models\Task;
use Illuminate\Http\Request;

/**
 * Workspace navigation is session-scoped. Study/exam answer state remains
 * canonical in its own DB; this service stores only safe return locations.
 */
final class WorkspaceNavigationService
{
    private const KEY = 'canovia_workspace_navigation_v1';

    public function activeMode(Request $request): ?WorkspaceMode
    {
        $name = (string) $request->route()?->getName();
        $mode = match (true) {
            str_starts_with($name, 'workspace.study.'),
            str_starts_with($name, 'plans.study_scope.'),
            str_starts_with($name, 'plans.study_action.'),
            str_starts_with($name, 'plans.tasks.study_'),
            str_starts_with($name, 'plans.tasks.learning.') => WorkspaceMode::Study,
            str_starts_with($name, 'workspace.development.'),
            str_starts_with($name, 'github_workflow.'),
            str_starts_with($name, 'plans.development_readiness.'),
            str_starts_with($name, 'plans.tasks.execution_orchestration.') => WorkspaceMode::Development,
            str_starts_with($name, 'workspace.career.'),
            str_starts_with($name, 'career.explore.'),
            str_starts_with($name, 'plans.career.') => WorkspaceMode::Career,
            default => null,
        };
        return $mode && in_array($mode->value, app(WorkspaceModeRegistry::class)->publicKeys(), true)
            ? $mode : null;
    }

    private function key(Request $request): string
    {
        return self::KEY.':'.($request->user() ? 'user:'.$request->user()->id : 'guest');
    }

    public function remember(Request $request): void
    {
        if (! $request->hasSession() || ! $request->isMethod('GET')
            || $request->header('X-Canovia-Instant-Navigation')) return;
        $mode = $this->activeMode($request);
        if (! $mode) return;

        // Only canonical, successful GET pages. No callback URLs or arbitrary
        // query strings; prefetch requests never overwrite a visible screen.
        $name = (string) $request->route()?->getName();
        if (! in_array($name, [
            'workspace.study.top', 'workspace.study.index',
            'workspace.development.top', 'workspace.development.index',
            'workspace.career.index', 'career.explore.show',
            'plans.study_scope.index', 'plans.tasks.study_practice.show',
            'plans.tasks.learning.index', 'plans.tasks.learning.show',
            'github_workflow.index',
        ], true)) return;

        $plan = $request->route('plan');
        $planId = $plan instanceof Plan ? (int) $plan->id
            : (is_numeric($plan) ? (int) $plan : (int) $request->query('plan_id', 0));
        $task = $request->route('task');
        $taskId = $task instanceof Task ? (int) $task->id
            : (is_numeric($task) ? (int) $task : null);
        $query = array_filter([
            'plan_id' => $request->query('plan_id'),
            'surface' => $request->query('surface'),
        ], fn ($value) => is_string($value) && preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $value));

        $path = $request->getPathInfo();
        if (! str_starts_with($path, '/') || str_starts_with($path, '//')) return;
        $state = (array) $request->session()->get($this->key($request), []);
        $state['last'] = $mode->value;
        $state['screens'][$mode->value] = [
            'url' => $path.($query ? '?'.http_build_query($query) : ''),
            'plan_id' => $planId > 0 ? $planId : null,
            'task_id' => $taskId,
        ];
        $request->session()->put($this->key($request), $state);
    }

    public function lastMode(Request $request): WorkspaceMode
    {
        $state = $request->hasSession() ? $request->session()->get($this->key($request), []) : [];
        $raw = data_get($state, 'last');
        $mode = is_string($raw) ? WorkspaceMode::tryFrom($raw) : null;
        if (! $mode || $mode === WorkspaceMode::Overview
            || ! in_array($mode->value, app(WorkspaceModeRegistry::class)->publicKeys(), true)) {
            $mode = app(WorkspaceModePreference::class)->selected($request);
        }
        return $mode && $mode !== WorkspaceMode::Overview ? $mode : WorkspaceMode::Study;
    }

    public function resumeUrl(Request $request, WorkspaceMode $mode): string
    {
        $fallback = route(match ($mode) {
            WorkspaceMode::Overview => 'home',
            WorkspaceMode::Study => 'workspace.study.top',
            WorkspaceMode::Development => 'workspace.development.top',
            WorkspaceMode::Career => 'workspace.career.index',
        });
        if ($mode === WorkspaceMode::Overview || ! $request->hasSession()) return $fallback;

        $screen = data_get($request->session()->get($this->key($request), []), 'screens.'.$mode->value);
        if (! is_array($screen)) return $fallback;
        $url = $screen['url'] ?? null;
        if (! is_string($url) || ! str_starts_with($url, '/')
            || str_starts_with($url, '//') || str_contains($url, '\\')) return $fallback;

        $planId = (int) ($screen['plan_id'] ?? 0);
        if ($planId > 0) {
            $plan = Plan::find($planId);
            if (! $plan || ! app(PlanOwnershipService::class)->canView($request, $plan)) return $fallback;
            $taskId = (int) ($screen['task_id'] ?? 0);
            if ($taskId > 0 && ! Task::whereKey($taskId)->where('plan_id', $planId)->exists()) return $fallback;
        }
        $allowed = match ($mode) {
            WorkspaceMode::Study => ['/workspace/study', '/plans/'],
            WorkspaceMode::Development => ['/workspace/development', '/github-workflow'],
            WorkspaceMode::Career => ['/workspace/career'],
            default => [],
        };
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        if (! collect($allowed)->contains(fn ($prefix) => $path === $prefix
            || str_starts_with($path, $prefix.'/'))) return $fallback;
        if (str_starts_with($path, '/plans/') && $planId === 0) return $fallback;
        return $url;
    }
}
