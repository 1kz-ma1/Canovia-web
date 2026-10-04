<?php

namespace App\Data;

use App\Models\ProviderConnection;

final readonly class ProviderConnectionCredentialsData
{
    public function __construct(
        public ProviderConnection $connection,
        public string $secret,
    ) {}
}
