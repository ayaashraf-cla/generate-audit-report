<?php

namespace Cla\GenerateAuditReport\Http\Livewire;

use Cla\GenerateAuditReport\Models\AuditSession;
use Livewire\Attributes\Locked;
use Livewire\Component;

class AuditProgress extends Component
{
    #[Locked]
    public int $sessionId;

    public string  $status      = 'pending';
    public int     $currentStep = 0;
    public int     $totalSteps  = 5;
    public string  $label       = 'Starting audit...';
    public int     $percent     = 0;
    public bool    $complete    = false;
    public bool    $failed      = false;
    public ?string $error       = null;
    public array   $stepLogs    = [];

    public function mount(int $sessionId): void
    {
        $this->sessionId = $sessionId;
        $this->refresh();
    }

    public function refresh(): void
    {
        $session = AuditSession::find($this->sessionId);

        if (! $session) {
            return;
        }

        $this->status      = $session->status->value;
        $this->currentStep = $session->current_step;
        $this->label       = $session->step_label ?? 'Waiting...';
        $this->percent     = $this->currentStep > 0
            ? (int) round(($this->currentStep / $this->totalSteps) * 100)
            : 0;

        $this->complete = $session->status->value === 'completed';
        $this->failed   = $session->status->value === 'failed';
        $this->error    = $session->error_message;

        $this->stepLogs = $session->stepLogs()
            ->where('status', 'completed')
            ->orderBy('step')
            ->get()
            ->map(fn ($log) => [
                'id'               => $log->id,
                'step'             => $log->step,
                'step_name'        => $log->step_name,
                'status'           => $log->status,
                'duration_ms'      => $log->duration_ms,
                'chunks_retrieved' => $log->chunks_retrieved,
                'token_usage'      => $log->token_usage,
                'prompt'           => $log->prompt,
                'output'           => $log->output ?? [],
                'error'            => $log->error,
            ])
            ->toArray();
    }

    public function render()
    {
        return view('generate-audit-report::livewire.audit-progress');
    }
}
