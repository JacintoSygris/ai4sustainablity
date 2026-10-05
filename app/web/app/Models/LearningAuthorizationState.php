<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Lockable synthetic projection; this is not an approved legal holder policy. */
class LearningAuthorizationState extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['user_id' => 'integer', 'learning_company_group_id' => 'integer',
            'generation' => 'integer', 'promotion_allowed' => 'boolean'];
    }
}
