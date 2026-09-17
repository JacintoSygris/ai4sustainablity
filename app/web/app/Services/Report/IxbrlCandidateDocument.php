<?php

namespace App\Services\Report;

class IxbrlCandidateDocument
{
    public function __construct(
        public readonly string $bytes,
        public readonly string $filename,
    ) {}
}
