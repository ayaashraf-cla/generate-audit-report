<?php

namespace Cla\GenerateAuditReport\Services\Audit;

use Cla\GenerateAuditReport\Enums\AuditStep;
use Cla\GenerateAuditReport\Events\AuditProgressUpdated;
use Cla\GenerateAuditReport\Models\AuditSession;

class AuditProgressBroadcaster
{
    private const TOTAL_STEPS = 5;

    public function stepStarted(AuditSession $session, AuditStep $step): void
    {
        $session->advanceToStep($step->value, $step->label());

        broadcast(new AuditProgressUpdated(
            sessionId:  $session->id,
            step:       $step->value,
            totalSteps: self::TOTAL_STEPS,
            label:      $step->label(),
            status:     'running',
        ));
    }

    public function stepCompleted(AuditSession $session, AuditStep $step, ?array $summary = null): void
    {
        broadcast(new AuditProgressUpdated(
            sessionId:  $session->id,
            step:       $step->value,
            totalSteps: self::TOTAL_STEPS,
            label:      $step->label(),
            status:     'completed',
            summary:    $summary,
        ));
    }

    public function auditCompleted(AuditSession $session): void
    {
        broadcast(new AuditProgressUpdated(
            sessionId:  $session->id,
            step:       self::TOTAL_STEPS,
            totalSteps: self::TOTAL_STEPS,
            label:      'Audit complete',
            status:     'completed',
        ));
    }

    public function auditFailed(AuditSession $session, string $reason): void
    {
        broadcast(new AuditProgressUpdated(
            sessionId:  $session->id,
            step:       $session->current_step,
            totalSteps: self::TOTAL_STEPS,
            label:      'Audit failed',
            status:     'failed',
            summary:    ['error' => $reason],
        ));
    }
}
