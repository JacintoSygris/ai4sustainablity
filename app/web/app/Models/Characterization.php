<?php

namespace App\Models;

use App\Events\CharacterizationStatusUpdated;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Characterization extends Model
{
    use HasFactory;
    protected $fillable = [
        'user_id',
        'status',
        'nace_code',
        'esrs_topic_ids',
        'form_data',
        'result_data',
        'last_error',
        'submitted_at',
        'completed_at',
        'retry_count',
        'next_retry_at',
        'last_job_attempted_at',
    ];

    protected $casts = [
        'esrs_topic_ids' => 'array',
        'form_data' => 'array',
        'result_data' => 'array',
        'submitted_at' => 'datetime',
        'completed_at' => 'datetime',
        'next_retry_at' => 'datetime',
        'last_job_attempted_at' => 'datetime',
    ];

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_WAITING = 'waiting';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_FAILED = 'failed';
    public const STATUS_TIMED_OUT = 'timed_out';
    public const STATUS_COMPLETED = 'completed';

    public function scopeForUser(EloquentBuilder $query, int $userId): EloquentBuilder
    {
        return $query->where('user_id', $userId);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function updateStatus(string $status, array $attributes = []): void
    {
        $this->fill(array_merge($attributes, ['status' => $status]));
        $statusChanged = $this->isDirty('status');
        $this->save();

        if ($statusChanged) {
            event(new CharacterizationStatusUpdated($this->fresh()));
        }
    }
}
