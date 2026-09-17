<?php

namespace Database\Factories;

use App\Models\Characterization;
use Illuminate\Database\Eloquent\Factories\Factory;

class CharacterizationFactory extends Factory
{
    protected $model = Characterization::class;

    public function definition(): array
    {
        return [
            'status' => Characterization::STATUS_DRAFT,
            'nace_code' => null,
            'esrs_topic_ids' => [],
            'form_data' => [],
            'retry_count' => 0,
        ];
    }
}
