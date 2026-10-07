<?php

namespace App\Services;

use App\Models\UserPersonalizationContext;
use Illuminate\Http\Request;

final class PersonalizationContextService
{
    public const SESSION_KEY = 'canovia.personalization.context';
    public const VERSION = 1;

    /**
     * Phase 1 resolves the live Context from self-reported input only.
     * Observed / inferred sources are persisted separately for future
     * Context Update Loops and must never silently overwrite user answers.
     *
     * @return array<string,mixed>
     */
    public function current(Request $request): array
    {
        $session = $request->session()->get(self::SESSION_KEY, []);
        $session = is_array($session) ? $session : [];

        $stored = $request->user()?->personalizationContext;

        if (! $stored instanceof UserPersonalizationContext) {
            return $this->normalize($session);
        }

        $selfReported = is_array($stored->self_reported_context)
            ? $stored->self_reported_context
            : [];

        return $this->normalize([
            'domains' => (array) data_get(
                $selfReported,
                'domains',
                [],
            ),
            'common_context' => (array) data_get(
                $selfReported,
                'common_context',
                [],
            ),
            'domain_context' => (array) data_get(
                $selfReported,
                'domain_context',
                [],
            ),
            'guidance_level' => $stored->guidance_level,
            'recommended_surfaces' => $stored->recommended_surfaces,
            'feature_readiness' => $stored->feature_readiness,
            'context_revision' => $stored->context_revision,
            'last_evaluated_at' =>
                $stored->last_evaluated_at?->toIso8601String(),
            'context_sources' => [
                'self_reported' => $selfReported,
                'observed' => is_array($stored->observed_context)
                    ? $stored->observed_context
                    : [],
                'inferred' => is_array($stored->inferred_context)
                    ? $stored->inferred_context
                    : [],
            ],
            'completed_at' => $stored->completed_at?->toIso8601String(),
            'skipped_at' => $stored->skipped_at?->toIso8601String(),
            ...$session,
        ]);
    }

    /**
     * Save user-answered context. This is deliberately source-specific:
     * future observed/inferred updates must use a different path.
     *
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function saveSelfReported(
        Request $request,
        array $context,
        bool $completed = false,
    ): array {
        $normalized = $this->normalize($context);

        if ($completed) {
            $normalized['completed_at'] = now()->toIso8601String();
            $normalized['skipped_at'] = null;
        }

        $normalized['context_sources']['self_reported'] =
            $this->selfReportedPayload($normalized);

        $request->session()->put(self::SESSION_KEY, $normalized);

        $user = $request->user();
        if ($user) {
            $existing = UserPersonalizationContext::query()
                ->where('user_id', $user->id)
                ->first();

            UserPersonalizationContext::query()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'version' => self::VERSION,
                    'context_revision' => max(
                        1,
                        (int) ($existing?->context_revision ?? 0) + 1,
                    ),
                    'self_reported_context' =>
                        $this->selfReportedPayload($normalized),
                    'observed_context' =>
                        $existing?->observed_context ?? [],
                    'inferred_context' =>
                        $existing?->inferred_context ?? [],
                    'guidance_level' => $normalized['guidance_level'],
                    'recommended_surfaces' =>
                        $normalized['recommended_surfaces'],
                    'feature_readiness' =>
                        $normalized['feature_readiness'],
                    'last_evaluated_at' => now(),
                    'completed_at' => $completed
                        ? now()
                        : $existing?->completed_at,
                    'skipped_at' => null,
                ],
            );
        }

        return $normalized;
    }

    /**
     * Phase 1 convenience for user-confirmed capability preference.
     * This remains self-reported context, not observed/inferred behavior.
     *
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function saveSelfReportedPreference(
        Request $request,
        array $context,
    ): array {
        return $this->saveSelfReported(
            $request,
            $context,
            completed: false,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function skip(Request $request): array
    {
        $context = $this->current($request);
        $context['skipped_at'] = now()->toIso8601String();

        $request->session()->put(self::SESSION_KEY, $context);

        $user = $request->user();
        if ($user) {
            $existing = UserPersonalizationContext::query()
                ->where('user_id', $user->id)
                ->first();

            UserPersonalizationContext::query()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'version' => self::VERSION,
                    'context_revision' => max(
                        1,
                        (int) ($existing?->context_revision ?? 0) + 1,
                    ),
                    'self_reported_context' =>
                        $existing?->self_reported_context ?? [],
                    'observed_context' =>
                        $existing?->observed_context ?? [],
                    'inferred_context' =>
                        $existing?->inferred_context ?? [],
                    'guidance_level' =>
                        $existing?->guidance_level ?? 'standard',
                    'recommended_surfaces' =>
                        $existing?->recommended_surfaces ?? [],
                    'feature_readiness' =>
                        $existing?->feature_readiness ?? [],
                    'last_evaluated_at' =>
                        $existing?->last_evaluated_at,
                    'completed_at' => $existing?->completed_at,
                    'skipped_at' => now(),
                ],
            );
        }

        return $context;
    }

    /**
     * Future Phase 3 boundary.
     *
     * Observed behavior may be stored separately, but this method intentionally
     * does not resolve or auto-apply it to guidance / Plan decisions yet.
     *
     * @param array<string,mixed> $observed
     */
    public function storeObservedCandidate(
        Request $request,
        array $observed,
    ): void {
        $user = $request->user();
        if (! $user) {
            return;
        }

        $context = UserPersonalizationContext::query()
            ->where('user_id', $user->id)
            ->first();

        if (! $context) {
            return;
        }

        $context->forceFill([
            'observed_context' => [
                ...(is_array($context->observed_context)
                    ? $context->observed_context
                    : []),
                ...$observed,
            ],
        ])->save();
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function normalize(array $context): array
    {
        $sources = is_array($context['context_sources'] ?? null)
            ? $context['context_sources']
            : [];

        return [
            'domains' => array_values(array_unique(array_filter(
                (array) ($context['domains'] ?? []),
                fn ($value) => is_string($value) && $value !== '',
            ))),
            'common_context' => is_array($context['common_context'] ?? null)
                ? $context['common_context']
                : [],
            'domain_context' => is_array($context['domain_context'] ?? null)
                ? $context['domain_context']
                : [],
            'guidance_level' => is_string($context['guidance_level'] ?? null)
                ? $context['guidance_level']
                : 'standard',
            'recommended_surfaces' => array_values(array_unique(array_filter(
                (array) ($context['recommended_surfaces'] ?? []),
                fn ($value) => is_string($value) && $value !== '',
            ))),
            'feature_readiness' => is_array($context['feature_readiness'] ?? null)
                ? $context['feature_readiness']
                : [],
            'context_revision' => max(
                1,
                (int) ($context['context_revision'] ?? 1),
            ),
            'last_evaluated_at' => $context['last_evaluated_at'] ?? null,
            'context_sources' => [
                'self_reported' => is_array(
                    $sources['self_reported'] ?? null,
                )
                    ? $sources['self_reported']
                    : [],
                'observed' => is_array($sources['observed'] ?? null)
                    ? $sources['observed']
                    : [],
                'inferred' => is_array($sources['inferred'] ?? null)
                    ? $sources['inferred']
                    : [],
            ],
            'completed_at' => $context['completed_at'] ?? null,
            'skipped_at' => $context['skipped_at'] ?? null,
        ];
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function selfReportedPayload(array $context): array
    {
        return [
            'domains' => $context['domains'],
            'common_context' => $context['common_context'],
            'domain_context' => $context['domain_context'],
        ];
    }
}
