<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CharacterizationDocumentPurge extends Model
{
    protected $fillable = [
        'source_document_id',
        'user_id',
        'characterization_id',
        'size_bytes',
        'path_hash',
        'storage_disk',
        'stored_path',
        'attempts',
        'last_error',
        'last_attempted_at',
        'next_attempt_at',
        'lease_token',
        'leased_until',
    ];

    protected $casts = [
        'source_document_id' => 'integer',
        'user_id' => 'integer',
        'characterization_id' => 'integer',
        'size_bytes' => 'integer',
        'attempts' => 'integer',
        'last_attempted_at' => 'datetime',
        'next_attempt_at' => 'datetime',
        'leased_until' => 'datetime',
    ];
}
