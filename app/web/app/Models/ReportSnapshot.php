<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReportSnapshot extends Model
{
    use HasFactory;

    public const STALE_FRESH = 'fresh';
    public const STALE_STALE = 'stale';

    protected $fillable = [
        'user_id',
        'characterization_id',
        'profile_id',
        'profile_hash',
        'facts_hash',
        'characterization_hash',
        'snapshot_hash',
        'source_manifest',
        'snapshot_json',
        'stale_state',
        'stale_reasons',
    ];

    protected $casts = [
        'source_manifest' => 'array',
        'snapshot_json' => 'array',
        'stale_reasons' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function characterization()
    {
        return $this->belongsTo(Characterization::class);
    }

    public function approval()
    {
        return $this->hasOne(ReportApproval::class);
    }
}
