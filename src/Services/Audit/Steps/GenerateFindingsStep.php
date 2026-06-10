<?php

namespace Cla\GenerateAuditReport\Services\Audit\Steps;

use Cla\GenerateAuditReport\Enums\AuditStep as AuditStepEnum;
use Cla\GenerateAuditReport\Models\AuditSession;
use Cla\GenerateAuditReport\Services\Audit\AuditAgent;
use Illuminate\Support\Collection;

class GenerateFindingsStep extends AuditStep
{
    public function __construct(private readonly AuditAgent $agent) {}

    public function step(): AuditStepEnum
    {
        return AuditStepEnum::GenerateFindings;
    }

    protected function execute(AuditSession $session, array $context): array
    {
        $this->agent->setMessages($context['messages']);

        $result = $this->agent->generateFindings(
            language: $context['survey_language'],
        );

        $this->lastPrompt       = $this->agent->getLastPrompt();
        $this->lastUsage        = $this->agent->getLastUsage();
        $this->messagesSnapshot = $this->agent->getMessages()->toArray();

        return $result;
    }

    public function getMessages(): Collection
    {
        return $this->agent->getMessages();
    }

    public function getAgent(): AuditAgent
    {
        return $this->agent;
    }
}
