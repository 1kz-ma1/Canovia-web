<?php

namespace App\Http\Controllers;

use App\Enums\BehaviorEventType;
use App\Services\BehaviorEventLogger;
use App\Services\BehaviorIdentityService;
use App\Services\PersonalizationBootstrapService;
use App\Services\PersonalizationContextService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class PersonalizationController extends Controller
{
    public function show(
        Request $request,
        PersonalizationContextService $contexts,
        BehaviorIdentityService $identity,
        BehaviorEventLogger $events,
    ): View {
        $events->recordOnceSafely(
            $identity->resolve($request),
            BehaviorEventType::PersonalizationStarted,
            $request,
            metadata: [
                'source' => $this->source($request),
                'authenticated' => (bool) $request->user(),
            ],
            withinMinutes: 30,
        );

        return view('personalization.show', [
            'context' => $contexts->current($request),
            'source' => $this->source($request),
        ]);
    }

    public function store(
        Request $request,
        PersonalizationContextService $contexts,
        PersonalizationBootstrapService $bootstrap,
        BehaviorIdentityService $identity,
        BehaviorEventLogger $events,
    ): RedirectResponse {
        $validated = $request->validate([
            'domains' => ['required', 'array', 'min:1', 'max:3'],
            'domains.*' => [
                'required',
                'string',
                Rule::in(['study', 'development', 'unsure']),
            ],
            'deadline' => ['nullable', 'date'],
            'weekly_capacity' => [
                'nullable',
                Rule::in([
                    'unknown',
                    'under_2',
                    '2_5',
                    '5_10',
                    '10_plus',
                ]),
            ],
            'study_goal' => ['nullable', 'string', 'max:255'],
            'study_kind' => [
                'nullable',
                Rule::in([
                    'qualification',
                    'school',
                    'self_study',
                    'other',
                ]),
            ],
            'study_stage' => [
                'nullable',
                Rule::in([
                    'not_started',
                    'beginner',
                    'started',
                    'reviewing',
                ]),
            ],
            'development_goal' => ['nullable', 'string', 'max:255'],
            'development_experience' => [
                'nullable',
                Rule::in(['beginner', 'standard', 'advanced']),
            ],
            'development_stage' => [
                'nullable',
                Rule::in(['new', 'existing', 'operating']),
            ],
            'github_usage' => [
                'nullable',
                Rule::in(['yes', 'no', 'unsure']),
            ],
            'repository_ready' => [
                'nullable',
                Rule::in(['yes', 'no', 'unsure']),
            ],
        ]);

        $domains = array_values(array_unique(
            (array) $validated['domains'],
        ));

        if (! in_array('study', $domains, true)) {
            unset(
                $validated['study_goal'],
                $validated['study_kind'],
                $validated['study_stage'],
            );
        }

        if (! in_array('development', $domains, true)) {
            unset(
                $validated['development_goal'],
                $validated['development_experience'],
                $validated['development_stage'],
                $validated['github_usage'],
                $validated['repository_ready'],
            );
        }

        $context = $bootstrap->buildContext($validated);
        $context = $contexts->saveSelfReported($request, $context, completed: true);

        $events->recordSafely(
            $identity->resolve($request),
            BehaviorEventType::PersonalizationCompleted,
            $request,
            metadata: [
                'domains' => $context['domains'],
                'guidance_level' => $context['guidance_level'],
                'github_eligible' => (bool) data_get(
                    $context,
                    'feature_readiness.github.eligible',
                    false,
                ),
            ],
        );

        return redirect()
            ->route('personalization.result')
            ->with(
                'status',
                'Canoviaの準備ができました。最初のPlan候補を確認してください。',
            );
    }

    public function skip(
        Request $request,
        PersonalizationContextService $contexts,
        BehaviorIdentityService $identity,
        BehaviorEventLogger $events,
    ): RedirectResponse {
        $contexts->skip($request);

        $events->recordSafely(
            $identity->resolve($request),
            BehaviorEventType::PersonalizationSkipped,
            $request,
            metadata: [
                'authenticated' => (bool) $request->user(),
            ],
        );

        return redirect()
            ->route('plans.create')
            ->with(
                'status',
                '診断はあとからいつでもできます。まずは今進めたいことから始めましょう。',
            );
    }

    public function result(
        Request $request,
        PersonalizationContextService $contexts,
        PersonalizationBootstrapService $bootstrap,
        BehaviorIdentityService $identity,
        BehaviorEventLogger $events,
    ): View|RedirectResponse {
        $context = $contexts->current($request);

        if ((array) ($context['domains'] ?? []) === []) {
            return redirect()->route('personalization.show');
        }

        $seeds = $bootstrap->seeds($context);
        $githubPreview = $bootstrap->githubPreview($context);

        if ($seeds !== []) {
            $events->recordOnceSafely(
                $identity->resolve($request),
                BehaviorEventType::PlanSeedShown,
                $request,
                metadata: [
                    'seed_keys' => array_values(array_map(
                        fn (array $seed) => $seed['key'],
                        $seeds,
                    )),
                    'count' => count($seeds),
                ],
                withinMinutes: 30,
            );
        }

        if ($githubPreview !== null) {
            $events->recordOnceSafely(
                $identity->resolve($request),
                BehaviorEventType::CapabilityPreviewShown,
                $request,
                metadata: [
                    'capability' => $githubPreview['key'],
                ],
                withinMinutes: 30,
            );
        }

        return view('personalization.result', [
            'context' => $context,
            'seeds' => $seeds,
            'githubPreview' => $githubPreview,
        ]);
    }

    public function acceptSeed(
        Request $request,
        string $seedKey,
        PersonalizationContextService $contexts,
        PersonalizationBootstrapService $bootstrap,
        BehaviorIdentityService $identity,
        BehaviorEventLogger $events,
    ): RedirectResponse {
        $context = $contexts->current($request);
        $seed = collect($bootstrap->seeds($context))
            ->first(
                fn (array $candidate) =>
                    ($candidate['key'] ?? null) === $seedKey,
            );

        abort_unless(is_array($seed), 404);

        $request->session()->put('plan_create_prefill', [
            'title' => $seed['title'],
            'description' => $seed['description'],
            'category' => $seed['category'],
            'deadline' => $seed['deadline'],
            'personalization_seed_key' => $seed['key'],
            'personalization_seed_domain' => $seed['domain'],
        ]);

        $events->recordSafely(
            $identity->resolve($request),
            BehaviorEventType::PlanSeedAccepted,
            $request,
            metadata: [
                'seed_key' => $seed['key'],
                'domain' => $seed['domain'],
            ],
        );

        return redirect()->route('plans.create.manual', [
            'workspace_mode' => $seed['workspace_mode'],
        ]);
    }

    public function capabilityInterest(
        Request $request,
        string $capability,
        PersonalizationContextService $contexts,
        BehaviorIdentityService $identity,
        BehaviorEventLogger $events,
    ): RedirectResponse {
        abort_unless($capability === 'github_integration', 404);

        $validated = $request->validate([
            'interest' => ['required', Rule::in(['yes', 'no'])],
        ]);

        $context = $contexts->current($request);

        abort_unless(
            (bool) data_get(
                $context,
                'feature_readiness.github.eligible',
                false,
            ),
            404,
        );

        data_set(
            $context,
            'feature_readiness.github.interest',
            $validated['interest'],
        );

        $contexts->saveSelfReportedPreference($request, $context);

        $events->recordSafely(
            $identity->resolve($request),
            $validated['interest'] === 'yes'
                ? BehaviorEventType::CapabilityInterestYes
                : BehaviorEventType::CapabilityInterestNo,
            $request,
            metadata: [
                'capability' => $capability,
            ],
        );

        return back()->with(
            'status',
            $validated['interest'] === 'yes'
                ? '興味ありとして保存しました。Plan作成後、必要なタイミングでGitHub連携を案内します。'
                : '今は提案を進めません。DevelopmentはGitHubなしでも使えます。',
        );
    }

    private function source(Request $request): string
    {
        return match ((string) $request->query('source')) {
            'first_run' => 'first_run',
            'account' => 'account',
            default => 'direct',
        };
    }
}
