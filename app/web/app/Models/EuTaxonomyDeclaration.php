<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EuTaxonomyDeclaration extends Model
{
    protected $fillable = ['characterization_id', 'revision', 'source_hash', 'source_context', 'activities'];

    protected $casts = ['revision' => 'integer', 'activities' => 'array', 'source_context' => 'array'];
}
