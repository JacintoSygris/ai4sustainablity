<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NaceCodeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'level' => $this->level,
            'parent_code' => $this->parent_code,
            'title' => [
                'en' => $this->title_en,
                'es' => $this->title_es,
            ],
        ];
    }
}
