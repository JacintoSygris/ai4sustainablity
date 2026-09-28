<?php

namespace App\Exceptions;

use RuntimeException;

class CharacterizationCapacityException extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct('AI prediction capacity is busy; retry later.');
    }
}
