<?php

namespace App\Services\FutureSupport;

use App\Enums\RoadmapFeatureStatus;
use App\Models\RoadmapFeature;
use App\Models\RoadmapVote;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SupportAuthority
{
    public static function reject(string $code, int $status): never
    {
        throw new HttpResponseException(response()->json(['code' => $code], $status));
    }

    public function issue(): array
    {
        $id = (string) Str::uuid();
        $token = 'fs1.'.$id.'.'.bin2hex(random_bytes(32));
        $expires = now()->addDays(90);
        DB::table('future_native_credentials')->insert([
            'id' => $id, 'token_hash' => hash('sha256', $token), 'expires_at' => $expires,
        ]);

        return ['credential' => $token, 'expires_at' => $expires->toISOString()];
    }

    public function actor(Request $request, bool $required, bool $lock = false): ?object
    {
        $header = $request->header('Authorization');
        if ($header === null && ! $required) {
            return null;
        }
        if (! is_string($header) || ! preg_match('/^Bearer (fs1\.[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.[0-9a-f]{64})$/D', $header, $match)) {
            self::reject('FUTURE_UNAUTHORIZED', 401);
        }
        $query = DB::table('future_native_credentials')->where('token_hash', hash('sha256', $match[1]))
            ->whereNull('revoked_at')->where('expires_at', '>', now());
        $actor = ($lock ? $query->lockForUpdate() : $query)->first();
        if ($actor === null) {
            self::reject('FUTURE_UNAUTHORIZED', 401);
        }

        return $actor;
    }

    private function projection(?object $actor)
    {
        $key = $actor ? 'native:'.$actor->id : '__no_anonymous_identity__';

        return RoadmapFeature::query()->published()
            ->whereIn('status', array_column(RoadmapFeatureStatus::cases(), 'value'))
            ->withCount('votes')
            ->withExists(['votes as supported' => fn ($q) => $q->where('voter_key', $key)]);
    }

    private function describe(RoadmapFeature $feature): array
    {
        return [
            'feature_key' => $feature->feature_key, 'title' => $feature->title,
            'description' => $feature->description ?? '', 'status' => $feature->status->value,
            'support_count' => (int) $feature->votes_count, 'supported' => (bool) $feature->supported,
            'voting_enabled' => $feature->voting_enabled, 'image_key' => $feature->feature_key,
        ];
    }

    public function features(?object $actor): array
    {
        return $this->projection($actor)->get()->filter(fn ($feature) =>
            preg_match('/^[a-z0-9][a-z0-9._-]{0,119}$/D', $feature->feature_key) && trim($feature->title) !== ''
        )->map(fn ($feature) => $this->describe($feature))->values()->all();
    }

    public function set(Request $request, string $key, bool $desired): array
    {
        return DB::transaction(function () use ($request, $key, $desired) {
            // Recheck with lock: a concurrent revocation cannot leave a new orphan vote.
            $actor = $this->actor($request, true, true);
            $feature = RoadmapFeature::query()->where('feature_key', $key)->lockForUpdate()->first();
            if (! $feature || ! $feature->is_published || ! $feature->voting_enabled
                || ! in_array($feature->getRawOriginal('status'), array_column(RoadmapFeatureStatus::cases(), 'value'), true)) {
                self::reject('FUTURE_NOT_FOUND', 404);
            }
            $identity = ['roadmap_feature_id' => $feature->id, 'voter_key' => 'native:'.$actor->id];
            if ($desired) {
                RoadmapVote::firstOrCreate($identity, ['user_id' => null, 'actor_token' => null]);
            } else {
                RoadmapVote::query()->where($identity)->delete();
            }

            return $this->describe($this->projection($actor)->where('id', $feature->id)->firstOrFail());
        }, 3);
    }

    public function revoke(Request $request): void
    {
        DB::transaction(function () use ($request) {
            $actor = $this->actor($request, true, true);
            RoadmapVote::query()->where('voter_key', 'native:'.$actor->id)->delete();
            DB::table('future_native_credentials')->where('id', $actor->id)->update(['revoked_at' => now()]);
        }, 3);
    }
}
