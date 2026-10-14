<?php

namespace App\Services;

use App\Contracts\PrivateFileIndependentProviderVerifier;

/**
 * R25's deliberately unconfigured verifier. This final implementation has
 * no SDK, trust root, external credentials, key material, or positive path.
 */
final class PrivateFileUnavailableProviderVerifier implements PrivateFileIndependentProviderVerifier
{
    /** @param array<string,mixed> $candidate */
    public function verify(array $candidate): array
    {
        return [
            'verdict' => 'independent_verifier_unavailable',
            'provider_authenticated' => false,
            'generation_write_bound' => false,
        ];
    }
}
