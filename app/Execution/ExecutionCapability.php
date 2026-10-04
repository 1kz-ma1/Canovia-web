<?php

namespace App\Execution;

use InvalidArgumentException;

final class ExecutionCapability
{
    public const STUDY_PRACTICE = 'study.practice';
    public const STUDY_RECALL = 'study.recall';
    public const STUDY_RESOURCE = 'study.resource';
    public const CODING_REPOSITORY = 'coding.repository';
    public const GENERAL_TASK = 'general.task';

    public static function normalize(string $capability): string
    {
        $capability = mb_strtolower(trim($capability));

        if (
            $capability === ''
            || preg_match('/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/', $capability) !== 1
        ) {
            throw new InvalidArgumentException('Invalid execution capability key.');
        }

        return $capability;
    }
}
