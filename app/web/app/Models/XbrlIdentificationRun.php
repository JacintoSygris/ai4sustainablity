<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;

class XbrlIdentificationRun extends Model
{
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $casts = ['input_json' => 'array', 'response_json' => 'array', 'created_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainException('xbrl_identification_immutable'));
    }

    public function reviews()
    {
        return $this->hasMany(XbrlIdentificationReview::class, 'run_id')->orderBy('sequence');
    }
}
