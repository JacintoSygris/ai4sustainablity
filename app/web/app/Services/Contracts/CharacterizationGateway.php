<?php

namespace App\Services\Contracts;

use App\Models\Characterization;

interface CharacterizationGateway
{
    /**
     * Submit a characterization to the external service and return the resulting payload.
     *
     * @return array<string, mixed>
     */
    public function submit(Characterization $characterization): array;
}
