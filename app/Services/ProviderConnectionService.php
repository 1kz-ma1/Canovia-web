<?php

namespace App\Services;

use App\Contracts\ExecutionProviderCatalog;
use App\Data\ProviderConnectionCredentialsData;
use App\Enums\ExecutionProviderKind;
use App\Models\ProviderConnection;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class ProviderConnectionService
{
    public const SIGNATURE_TOLERANCE_SECONDS = 300;

    public function __construct(
        private readonly ExecutionProviderCatalog $providers,
    ) {}

    public function create(
        User $user,
        string $providerKey,
        ?string $label = null,
    ): ProviderConnectionCredentialsData {
        $providerKey = mb_strtolower(trim($providerKey));
        $provider = $this->providers->find($providerKey);

        if (
            $provider === null
            || ! $provider->enabled
            || $provider->kind !== ExecutionProviderKind::External
        ) {
            throw new InvalidArgumentException(
                'Provider connection requires an enabled external provider.',
            );
        }

        $secret = bin2hex(random_bytes(32));

        $connection = ProviderConnection::query()->create([
            'user_id' => (int) $user->id,
            'provider_key' => $provider->key,
            'public_id' => (string) Str::uuid(),
            'secret_ciphertext' => Crypt::encryptString($secret),
            'label' => filled($label)
                ? Str::limit(trim((string) $label), 191, '')
                : null,
            'status' => ProviderConnection::STATUS_ACTIVE,
        ]);

        return new ProviderConnectionCredentialsData(
            connection: $connection,
            secret: $secret,
        );
    }

    public function revoke(ProviderConnection $connection): ProviderConnection
    {
        if ($connection->status !== ProviderConnection::STATUS_REVOKED) {
            $connection->update([
                'status' => ProviderConnection::STATUS_REVOKED,
                'revoked_at' => now(),
            ]);
        }

        return $connection->fresh();
    }

    public function authenticate(
        string $publicId,
        string $timestamp,
        string $signature,
        string $rawPayload,
    ): ?ProviderConnection {
        $publicId = trim($publicId);
        $timestamp = trim($timestamp);
        $signature = trim($signature);

        if (
            $publicId === ''
            || ! ctype_digit($timestamp)
            || ! preg_match('/^sha256=[a-f0-9]{64}$/i', $signature)
        ) {
            return null;
        }

        $signedAt = (int) $timestamp;
        if (
            $signedAt <= 0
            || abs(now()->timestamp - $signedAt)
                > self::SIGNATURE_TOLERANCE_SECONDS
        ) {
            return null;
        }

        $connection = ProviderConnection::query()
            ->where('public_id', $publicId)
            ->where('status', ProviderConnection::STATUS_ACTIVE)
            ->whereNull('revoked_at')
            ->first();

        if (! $connection) {
            return null;
        }

        try {
            $secret = Crypt::decryptString(
                (string) $connection->secret_ciphertext,
            );
        } catch (Throwable) {
            return null;
        }

        $expected = 'sha256='.hash_hmac(
            'sha256',
            $timestamp.'.'.$rawPayload,
            $secret,
        );

        if (! hash_equals(
            strtolower($expected),
            strtolower($signature),
        )) {
            return null;
        }

        $connection->forceFill(['last_used_at' => now()])->save();

        return $connection->fresh();
    }
}
