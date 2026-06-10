<?php

namespace Cla\GenerateAuditReport\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AuditProgressUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int    $sessionId,
        public readonly int    $step,
        public readonly int    $totalSteps,
        public readonly string $label,
        public readonly string $status,
        public readonly ?array $summary = null,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("audit.{$this->sessionId}")];
    }

    public function broadcastAs(): string
    {
        return 'progress.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'session_id' => $this->sessionId,
            'step'       => $this->step,
            'total'      => $this->totalSteps,
            'label'      => $this->label,
            'status'     => $this->status,
            'percent'    => $this->totalSteps > 0
                ? (int) round(($this->step / $this->totalSteps) * 100)
                : 0,
            'summary'    => $this->summary,
        ];
    }
}
