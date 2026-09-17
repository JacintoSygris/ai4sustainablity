<?php

namespace App\Events;

use App\Models\Characterization;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CharacterizationStatusUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Characterization $characterization)
    {
    }

    public function broadcastOn(): Channel
    {
        return new PrivateChannel('characterizations.' . $this->characterization->user_id);
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->characterization->id,
            'status' => $this->characterization->status,
            'submitted_at' => optional($this->characterization->submitted_at)->toIso8601String(),
            'completed_at' => optional($this->characterization->completed_at)->toIso8601String(),
            'last_error' => $this->characterization->last_error,
            'result_data' => $this->characterization->result_data,
            'retry_count' => $this->characterization->retry_count,
            'next_retry_at' => optional($this->characterization->next_retry_at)->toIso8601String(),
            'last_job_attempted_at' => optional($this->characterization->last_job_attempted_at)->toIso8601String(),
        ];
    }
}
