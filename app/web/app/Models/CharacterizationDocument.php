<?php

namespace App\Models;

use App\Services\CharacterizationDocumentPurgeService;
use Illuminate\Database\Eloquent\Model;

class CharacterizationDocument extends Model
{
    public const STATUS_UPLOADED = 'uploaded';

    public const STATUS_EXTRACTING = 'extracting';

    public const STATUS_EXTRACTED = 'extracted';

    public const STATUS_FAILED = 'failed';

    public const STATUS_NO_USABLE_EVIDENCE = 'no_usable_evidence';

    public const STORAGE_DISK = 'local';

    protected $fillable = [
        'characterization_id',
        'original_filename',
        'stored_path',
        'sha256',
        'size_bytes',
        'mime',
        'status',
        'extraction_generation',
        'extraction_lease_token',
        'extraction_dispatched_at',
        'extraction_started_at',
        'extraction_json',
        'merged_state_version',
    ];

    protected $casts = [
        'extraction_json' => 'array',
        'size_bytes' => 'integer',
        'extraction_dispatched_at' => 'datetime',
        'extraction_started_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::deleting(function (CharacterizationDocument $document) {
            // Persist the only private-file locator in the same transaction as
            // the row deletion. Physical deletion happens only after commit so
            // a rollback can never resurrect a row whose bytes are already gone.
            app(CharacterizationDocumentPurgeService::class)->stage($document);
        });
    }

    public function characterization()
    {
        return $this->belongsTo(Characterization::class);
    }
}
