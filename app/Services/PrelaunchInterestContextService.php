<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserPersonalizationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Owner-only namespace. Never feeds legacy inference, Plan generation or analytics. */
final class PrelaunchInterestContextService
{
    public const KEY = 'interest_diagnostic';

    private const KIND = 'canovia.interest_diagnostic.v1';

    public function __construct(private readonly PrelaunchInterestScoringService $scorer) {}

    public function read(User $user): array
    {
        $payload = UserPersonalizationContext::where('user_id', $user->id)->value('self_reported_context');
        $payload = is_string($payload) ? json_decode($payload, true) : $payload;
        $record = is_array($payload) ? ($payload[self::KEY] ?? null) : null;

        // Stored scores are a snapshot, never a second scoring authority.
        $visible = $this->supported($record) ? $record : null;
        if ($visible !== null) {
            $visible['result'] = $this->scorer->calculate($visible['answers'], config('prelaunch_interest_diagnostic'));
        }

        return ['record' => $visible,
            'unsupported' => ($payload !== null && ! is_array($payload)) || ($record !== null && ! $this->supported($record)),
            'revision' => $this->revision($record)];
    }

    public function saveAnswers(User $user, string $revision, array $answers, int $step, bool $result): array
    {
        $bank = config('prelaunch_interest_diagnostic');
        // The pure scorer validates IDs/types and is the only score authority.
        $scores = $this->scorer->calculate($answers, $bank);
        ksort($answers);
        $intent = ['action' => 'answers', 'answers' => $answers, 'step' => $step, 'result' => $result];

        return $this->write($user, $revision, $intent, function (?array $old) use ($answers, $step, $result, $scores, $bank): array {
            return ['kind' => self::KIND, 'item_bank_version' => $bank['item_bank_version'],
                'category_version' => $bank['category_version'], 'scoring_version' => $bank['scoring_version'],
                'answers' => $answers, 'result' => $scores, 'step' => $step,
                'state' => $result ? 'result' : 'draft',
                'confirmed_categories' => $old['confirmed_categories'] ?? [],
                'requested_support' => $old['requested_support'] ?? [],
                'reviewed_at' => $old['reviewed_at'] ?? null,
                'personal_storage_consent_at' => $old['personal_storage_consent_at'] ?? now()->toIso8601String(),
                'updated_at' => now()->toIso8601String()];
        });
    }

    public function saveReview(User $user, string $revision, array $confirmed, array $support): array
    {
        sort($confirmed);
        sort($support);

        return $this->write($user, $revision, ['action' => 'review', 'confirmed' => $confirmed, 'support' => $support],
            function (?array $record) use ($confirmed, $support): array {
                if ($record === null) {
                    throw ValidationException::withMessages(['record' => '先に回答を保存してください。']);
                }

                // Explicit self-correction / service interest are separate from inferred scores.
                return [...$record, 'confirmed_categories' => $confirmed, 'requested_support' => $support,
                    'reviewed_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String()];
            });
    }

    public function delete(User $user, string $revision): void
    {
        $this->write($user, $revision, ['action' => 'delete'], fn () => null, true);
    }

    private function write(User $user, string $expected, array $intent, callable $change, bool $delete = false): array
    {
        return DB::transaction(function () use ($user, $expected, $intent, $change, $delete): array {
            // Serializes first-row creation too; legacy self-report uses the same lock order.
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $context = UserPersonalizationContext::where('user_id', $user->id)->lockForUpdate()->first();
            $reported = $context?->self_reported_context;
            if ($reported !== null && ! is_array($reported)) {
                throw ValidationException::withMessages(['record' => '既存の記録形式を確認できないため、書き換えません。']);
            }
            $reported ??= [];
            $record = $reported[self::KEY] ?? null;
            if ($record !== null && ! $this->supported($record)) {
                throw ValidationException::withMessages(['record' => '別の版の記録を保持しています。自動的に書き換えません。']);
            }
            if ($delete && $record === null) {
                return ['record' => null, 'revision' => $this->revision(null)];
            }
            $fingerprint = $this->revision($intent);
            if (! hash_equals($this->revision($record), $expected)) {
                if (! $delete && ($record['previous_revision'] ?? null) === $expected
                    && ($record['write_fingerprint'] ?? null) === $fingerprint) {
                    return ['record' => $record, 'revision' => $this->revision($record)];
                }
                throw ValidationException::withMessages(['revision' => '別の画面で更新されています。この入力は保存していません。最新の記録を確認してください。']);
            }
            $next = $change($record);
            if ($next === null) {
                unset($reported[self::KEY]);
            } else {
                $next['previous_revision'] = $expected;
                $next['write_fingerprint'] = $fingerprint;
                $reported[self::KEY] = $next;
            }
            if ($context === null) {
                $context = new UserPersonalizationContext(['user_id' => $user->id,
                    'version' => PersonalizationContextService::VERSION, 'context_revision' => 0]);
            }
            $context->self_reported_context = $reported;
            $context->context_revision = (int) $context->context_revision + 1;
            $context->save();

            return ['record' => $next, 'revision' => $this->revision($next)];
        });
    }

    private function supported(mixed $record): bool
    {
        if (! is_array($record) || ($record['kind'] ?? null) !== self::KIND) {
            return false;
        }
        foreach (['item_bank_version', 'category_version', 'scoring_version'] as $version) {
            if (($record[$version] ?? null) !== config('prelaunch_interest_diagnostic.'.$version)) {
                return false;
            }
        }

        if (! is_array($record['answers'] ?? null) || ! is_array($record['result'] ?? null)
            || ! in_array($record['state'] ?? null, ['draft', 'result'], true)
            || ! is_int($record['step'] ?? null) || $record['step'] < 1 || $record['step'] > 6) {
            return false;
        }
        foreach (['confirmed_categories', 'requested_support'] as $key) {
            if (! is_array($record[$key] ?? null) || ! array_is_list($record[$key])
                || count(array_filter($record[$key], 'is_string')) !== count($record[$key])
                || array_diff($record[$key], array_keys(config('prelaunch_interest_diagnostic.categories')))) {
                return false;
            }
        }
        try {
            $this->scorer->calculate($record['answers'], config('prelaunch_interest_diagnostic'));
        } catch (\InvalidArgumentException) {
            return false;
        }

        return true;
    }

    private function revision(mixed $record): string
    {
        $canonical = function ($value) use (&$canonical) {
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }

            return array_map($canonical, $value);
        };

        return hash('sha256', json_encode($canonical($record), JSON_THROW_ON_ERROR));
    }
}
