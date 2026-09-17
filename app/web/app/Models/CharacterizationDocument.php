<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

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
        'extraction_json',
        'merged_state_version',
    ];

    protected $casts = [
        'extraction_json' => 'array',
        'size_bytes' => 'integer',
    ];

    protected static function booted(): void
    {
        static::deleting(function (CharacterizationDocument $document) {
            if (filled($document->stored_path)) {
                Storage::disk(self::STORAGE_DISK)->delete($document->stored_path);
            }
        });
    }

    public function characterization()
    {
        return $this->belongsTo(Characterization::class);
    }
}
