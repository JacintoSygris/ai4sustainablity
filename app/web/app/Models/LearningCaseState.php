<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LearningCaseState extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['state_version' => 'integer'];
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(LearningCase::class, 'learning_case_id');
    }
}
