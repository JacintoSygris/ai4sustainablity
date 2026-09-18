<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReportingFact extends Model
{
    use HasFactory;

    public const SCHEMA_VERSION = 'reporting_fact_v1';
    public const PROFILE_ID = 'esrs-2023-preparatory-v1';

    protected $fillable = [
        'characterization_id',
        'fact_id',
        'schema_version',
        'profile_id',
        'datapoint_id',
        'applicability',
        'value_type',
        'value',
        'unit',
        'decimals',
        'dimensions',
        'language',
        'nil',
        'nil_reason',
        'evidence_refs',
        'provenance',
        'approval_status',
        'blocking_reasons',
    ];

    protected $casts = [
        'value' => 'array',
        'decimals' => 'integer',
        'dimensions' => 'array',
        'nil' => 'boolean',
        'evidence_refs' => 'array',
        'blocking_reasons' => 'array',
    ];

    public function characterization()
    {
        return $this->belongsTo(Characterization::class);
    }

    public static function factId(
        int $characterizationId,
        string $profileId,
        string $datapointId,
        array $dimensions,
        string $valueType,
        ?string $language,
    ): string {
        $identity = implode('|', [
            $characterizationId,
            $profileId,
            trim($datapointId),
            json_encode($dimensions, JSON_UNESCAPED_SLASHES),
            $valueType,
            $language ?? '',
        ]);

        return 'rf_'.substr(hash('sha256', $identity), 0, 32);
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(string $origin = 'persisted'): array
    {
        return [
            'origin' => $origin,
            'id' => $this->id,
            'fact_id' => $this->fact_id,
            'schema_version' => $this->schema_version,
            'profile_id' => $this->profile_id,
            'datapoint_id' => $this->datapoint_id,
            'applicability' => $this->applicability,
            'value_type' => $this->value_type,
            'value' => $this->value,
            'unit' => $this->unit,
            'decimals' => $this->decimals,
            'dimensions' => $this->dimensions ?? [],
            'language' => $this->language,
            'nil' => $this->nil,
            'nil_reason' => $this->nil_reason,
            'evidence_refs' => $this->evidence_refs ?? [],
            'provenance' => $this->provenance,
            'approval_status' => $this->approval_status,
            'blocking_reasons' => $this->blocking_reasons ?? [],
            'created_at' => $this->created_at?->toJSON(),
            'updated_at' => $this->updated_at?->toJSON(),
        ];
    }
}
