<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReportApproval extends Model
{
    use HasFactory;

    public const ROLE_MODE_SINGLE_PERSON_DECLARED = 'single_person_declared';

    protected $fillable = [
        'report_snapshot_id',
        'user_id',
        'role_mode',
        'preparer_user_id',
        'reviewer_user_id',
        'approver_user_id',
        'single_person_declaration',
        'snapshot_hash',
        'approved_at',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
    ];

    public function reportSnapshot()
    {
        return $this->belongsTo(ReportSnapshot::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
