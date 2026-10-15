<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Append-only through the upload API; normalized rows retain verbatim cell text. */
class DmaImport extends Model
{
    public $timestamps = false;

    protected $fillable = ['characterization_id', 'user_id', 'sources', 'payload', 'uploaded_at'];

    protected $casts = ['sources' => 'array', 'payload' => 'array', 'uploaded_at' => 'datetime'];

    public function presentation(): array
    {
        return [
            'id' => $this->id,
            'characterization_id' => $this->characterization_id,
            'user_id' => $this->user_id,
            'uploaded_at' => $this->uploaded_at->toJSON(),
            'sources' => $this->sources,
            ...$this->payload,
            ...(\App\Services\Dma\P8Authority::enabled() ? [
                'iro_rows' => app(\App\Services\Dma\DmaCsvParser::class)->identifyRows(
                    $this->payload['iro_rows'], (int) $this->characterization_id, $this->sources['iro_file']['sha256']),
            ] : []),
        ];
    }
}
