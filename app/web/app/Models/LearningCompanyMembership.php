<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LearningCompanyMembership extends Model
{
    public const SUBJECT_ACCOUNT = 'account';

    public const SUBJECT_SOURCE = 'source';

    public const STATUS_UNRESOLVED = 'unresolved';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_REVOKED = 'revoked';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'learning_company_group_id' => 'integer',
            'user_id' => 'integer',
            'verified_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(LearningCompanyGroup::class, 'learning_company_group_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
