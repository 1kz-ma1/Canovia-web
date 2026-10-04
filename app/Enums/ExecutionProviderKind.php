<?php

namespace App\Enums;

enum ExecutionProviderKind: string
{
    case Native = 'native';
    case External = 'external';
}
