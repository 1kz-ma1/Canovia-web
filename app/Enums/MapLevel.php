<?php

namespace App\Enums;

enum MapLevel: string
{
    case Intent = 'l0';
    case Domain = 'l1';
    case Plan = 'l2';
    case Execution = 'l3';
}
