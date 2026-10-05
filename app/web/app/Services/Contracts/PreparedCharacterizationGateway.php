<?php

namespace App\Services\Contracts;

use App\Models\Characterization;
use App\Services\LearningP6PreparedRequest;

interface PreparedCharacterizationGateway extends CharacterizationGateway
{
    public function prepare(Characterization $characterization): LearningP6PreparedRequest;

    public function submitPrepared(LearningP6PreparedRequest $request): array;
}
