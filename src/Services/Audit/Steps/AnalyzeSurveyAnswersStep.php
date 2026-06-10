<?php

namespace Cla\GenerateAuditReport\Services\Audit\Steps;

use Cla\GenerateAuditReport\Enums\AuditStep as AuditStepEnum;
use Cla\GenerateAuditReport\Models\AuditSession;
use Cla\GenerateAuditReport\Services\Audit\AuditAgent;
use Illuminate\Support\Collection;

class AnalyzeSurveyAnswersStep extends AuditStep
{
    public function __construct(private readonly AuditAgent $agent) {}

    public function step(): AuditStepEnum
    {
        return AuditStepEnum::AnalyzeAnswers;
    }

    protected function execute(AuditSession $session, array $context): array
    {
        $this->agent->setMessages($context['messages']);

        $result = $this->agent->analyzeAnswers(
            surveyContextText: $context['survey_context_text'],
            language:          $context['survey_language'],
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
}
