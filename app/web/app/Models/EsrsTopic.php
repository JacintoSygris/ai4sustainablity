<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EsrsTopic extends Model
{
    protected $fillable = [
        'esrs_code',
        'theme_es',
        'theme_en',
        'subtheme_es',
        'subtheme_en',
        'subtopic_es',
        'subtopic_en',
        'examples_es',
        'examples_en',
        'competency_1',
        'competency_2',
        'competency_3',
        'regulation_1',
        'regulation_2',
        'regulation_3',
        'internal_strategy',
        'internal_activities',
        'internal_policies',
        'internal_regulations',
        'consolidated',
        'hash',
    ];

    protected $casts = [
        'examples_es' => 'array',
        'examples_en' => 'array',
        'competency_1' => 'boolean',
        'competency_2' => 'boolean',
        'competency_3' => 'boolean',
        'regulation_1' => 'boolean',
        'regulation_2' => 'boolean',
        'regulation_3' => 'boolean',
        'internal_strategy' => 'boolean',
        'internal_activities' => 'boolean',
        'internal_policies' => 'boolean',
        'internal_regulations' => 'boolean',
        'consolidated' => 'boolean',
    ];
}
