<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;

class XbrlIdentificationReview extends Model
{
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $casts = ['created_at' => 'immutable_datetime', 'sequence' => 'integer'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainException('xbrl_review_immutable'));
    }
}
