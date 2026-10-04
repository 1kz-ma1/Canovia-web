<?php

namespace App\Enums;

enum WorkspaceMode: string
{
    case Overview = 'overview';
    case Study = 'study';
    case Development = 'development';
    case Career = 'career';
}
