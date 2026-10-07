<?php

namespace App\Services;

use App\Models\UserPersonalizationContext;
use Illuminate\Http\Request;

final class PersonalizationContextService
{
    public const SESSION_KEY = 'canovia.personalization.context';
    public const VERSION = 1;

    /**
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

        return $this->normalize([
            'domains' => $stored->domains,
            'common_context' => $stored->common_context,
            'domain_context' => $stored->domain_context,
            'guidance_level' => $stored->guidance_level,
            'recommended_surfaces' => $stored->recommended_surfaces,
            'feature_readiness' => $stored->feature_readiness,
            'completed_at' => $stored->completed_at?->toIso8601String(),
            'skipped_at' => $stored->skipped_at?->toIso8601String(),
            ...$session,
        ]);
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function save(
        Request $request,
        array $context,
        bool $completed = false,
    ): array {
        $normalized = $this->normalize($context);

        if ($completed) {
            $normalized['completed_at'] = now()->toIso8601String();
            $normalized['skipped_at'] = null;
        }

        $request->session()->put(self::SESSION_KEY, $normalized);

        $user = $request->user();
        if ($user) {
            UserPersonalizationContext::query()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'version' => self::VERSION,
                    'domains' => $normalized['domains'],
                    'common_context' => $normalized['common_context'],
                    'domain_context' => $normalized['domain_context'],
                    'guidance_level' => $normalized['guidance_level'],
                    'recommended_surfaces' =>
                        $normalized['recommended_surfaces'],
                    'feature_readiness' =>
                        $normalized['feature_readiness'],
                    'completed_at' => $completed
                        ? now()
                        : data_get($normalized, 'completed_at'),
                    'skipped_at' => null,
                ],
            );
        }

        return $normalized;
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
            UserPersonalizationContext::query()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'version' => self::VERSION,
                    'domains' => $context['domains'],
                    'common_context' => $context['common_context'],
                    'domain_context' => $context['domain_context'],
                    'guidance_level' => $context['guidance_level'],
                    'recommended_surfaces' =>
                        $context['recommended_surfaces'],
                    'feature_readiness' => $context['feature_readiness'],
                    'skipped_at' => now(),
                ],
            );
        }

        return $context;
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function normalize(array $context): array
    {
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
            'completed_at' => $context['completed_at'] ?? null,
            'skipped_at' => $context['skipped_at'] ?? null,
        ];
    }
}
