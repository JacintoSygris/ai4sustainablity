<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CharacterizationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'nace_code' => $this->nace_code,
            'esrs_topic_ids' => $this->esrs_topic_ids ?? [],
            'form_data' => $this->form_data ?? [],
            'result_data' => $this->result_data,
            'last_error' => $this->last_error,
            'retry_count' => $this->retry_count ?? 0,
            'next_retry_at' => $this->next_retry_at?->toJSON(),
            'last_job_attempted_at' => $this->last_job_attempted_at?->toJSON(),
            'submitted_at' => $this->submitted_at?->toJSON(),
            'completed_at' => $this->completed_at?->toJSON(),
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
        ];
    }
}
