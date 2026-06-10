<?php

namespace Cla\GenerateAuditReport\Services\Audit\Steps;

use Cla\GenerateAuditReport\Enums\AuditStep as AuditStepEnum;
use Cla\GenerateAuditReport\Models\AuditSession;
use Cla\GenerateAuditReport\Models\AuditStepLog;
use Illuminate\Support\Collection;

abstract class AuditStep
{
    protected ?string $lastPrompt = null;
    protected ?array  $lastUsage  = null;
    protected ?array  $messagesSnapshot = null;

    abstract public function step(): AuditStepEnum;

    abstract protected function execute(AuditSession $session, array $context): array;

    public function run(AuditSession $session, array $context): array
    {
        $startedAt = microtime(true);

        AuditStepLog::create([
            'audit_session_id' => $session->id,
            'step'             => $this->step()->value,
            'step_name'        => $this->step()->name,
            'status'           => 'started',
            'created_at'       => now(),
        ]);

        try {
            $output = $this->execute($session, $context);

            AuditStepLog::where('audit_session_id', $session->id)
                ->where('step', $this->step()->value)
                ->where('status', 'started')
                ->update([
                    'status'            => 'completed',
                    'prompt'            => $this->lastPrompt,
                    'output'            => $output,
                    'duration_ms'       => (int) ((microtime(true) - $startedAt) * 1000),
                    'chunks_retrieved'  => isset($output['chunk_count']) ? (int) $output['chunk_count'] : null,
                    'token_usage'       => $this->lastUsage,
                    'messages_snapshot' => $this->messagesSnapshot,
                ]);

            return $output;
        } catch (\Throwable $e) {
            AuditStepLog::where('audit_session_id', $session->id)
                ->where('step', $this->step()->value)
                ->where('status', 'started')
                ->update([
                    'status' => 'failed',
                    'prompt' => $this->lastPrompt,
                    'error'  => $e->getMessage(),
                ]);

            throw $e;
        }
    }

    public function getMessages(): Collection
    {
        return collect();
    }

    public function alreadyCompleted(AuditSession $session): bool
    {
        return AuditStepLog::where('audit_session_id', $session->id)
            ->where('step', $this->step()->value)
            ->where('status', 'completed')
            ->exists();
    }
}
