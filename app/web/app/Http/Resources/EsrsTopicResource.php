<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EsrsTopicResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'esrs_code' => $this->esrs_code,
            'theme' => [
                'en' => $this->theme_en,
                'es' => $this->theme_es,
            ],
            'subtheme' => [
                'en' => $this->subtheme_en,
                'es' => $this->subtheme_es,
            ],
            'subtopic' => [
                'en' => $this->subtopic_en,
                'es' => $this->subtopic_es,
            ],
            'examples' => [
                'en' => $this->examples_en,
                'es' => $this->examples_es,
            ],
            'tags' => [
                'competencies' => [
                    'competency_1' => (bool) $this->competency_1,
                    'competency_2' => (bool) $this->competency_2,
                    'competency_3' => (bool) $this->competency_3,
                ],
                'regulations' => [
                    'regulation_1' => (bool) $this->regulation_1,
                    'regulation_2' => (bool) $this->regulation_2,
                    'regulation_3' => (bool) $this->regulation_3,
                ],
                'internal' => [
                    'strategy' => (bool) $this->internal_strategy,
                    'activities' => (bool) $this->internal_activities,
                    'policies' => (bool) $this->internal_policies,
                    'regulations' => (bool) $this->internal_regulations,
                ],
            ],
            'consolidated' => (bool) $this->consolidated,
        ];
    }
}
