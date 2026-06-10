<?php

namespace Cla\GenerateAuditReport\Jobs;

use Cla\GenerateAuditReport\Models\AuditSession;
use Cla\GenerateAuditReport\Services\Audit\AuditOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class RunAuditWorkflow implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 600;
    public int $backoff = 30;

    public function __construct(public readonly AuditSession $session) {}

    public function middleware(): array
    {
        return [new WithoutOverlapping("audit-session-{$this->session->id}")];
    }

    public function handle(AuditOrchestrator $orchestrator): void
    {
        $orchestrator->run($this->session);
    }

    public function failed(\Throwable $exception): void
    {
        if ($this->session->status->value !== 'failed') {
            $this->session->markFailed(
                "Job exhausted all retries: {$exception->getMessage()}"
            );
        }
    }

    public function tags(): array
    {
        return [
            'audit',
            "survey:{$this->session->survey_id}",
            "session:{$this->session->id}",
        ];
    }
}
