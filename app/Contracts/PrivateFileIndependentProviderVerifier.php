<?php

namespace App\Contracts;

/**
 * G3-R25: a future adapter boundary, not an approved provider verifier.
 *
 * A concrete implementation must independently authenticate a
 * provider-issued, specific write response and its immutable generation.
 * An app HMAC, SDK response header, TLS connection, or caller-supplied
 * "verified" flag cannot satisfy that contract. No implementation that
 * accepts external evidence is registered in this release.
 */
interface PrivateFileIndependentProviderVerifier
{
    /**
     * @param array<string,mixed> $candidate Untrusted, already scope-checked data.
     * @return array{verdict:string,provider_authenticated:bool,generation_write_bound:bool}
     */
    public function verify(array $candidate): array;
}
