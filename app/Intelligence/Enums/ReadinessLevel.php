<?php

namespace App\Intelligence\Enums;

enum ReadinessLevel: string
{
    case Unknown = 'unknown';
    case Blocked = 'blocked';
    case Developing = 'developing';
    case Ready = 'ready';
}
