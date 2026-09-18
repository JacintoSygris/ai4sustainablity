<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReportAuditEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'characterization_id',
        'report_snapshot_id',
        'report_approval_id',
        'event_type',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function reportSnapshot()
    {
        return $this->belongsTo(ReportSnapshot::class);
    }

    public function reportApproval()
    {
        return $this->belongsTo(ReportApproval::class);
    }
}
