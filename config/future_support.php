<?php

// Isolated development candidate. This cannot enable a hosted production API.
return [
    'enabled' => env('CANOVIA_FUTURE_NATIVE_LAB', false),
    'allow_loopback_http' => false,
];
