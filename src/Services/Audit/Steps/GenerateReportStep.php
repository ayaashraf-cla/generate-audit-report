<?php

namespace Cla\GenerateAuditReport\Services\Audit\Steps;

use Cla\GenerateAuditReport\Enums\AuditStep as AuditStepEnum;
use Cla\GenerateAuditReport\Models\AuditSession;
use Cla\GenerateAuditReport\Services\Audit\AuditAgent;
use AyaAshraf\LaravelRag\Models\Document;
use Illuminate\Support\Collection;

class GenerateReportStep extends AuditStep
{
    public function __construct(private readonly AuditAgent $agent) {}

    public function step(): AuditStepEnum
    {
        return AuditStepEnum::GenerateReport;
    }

    protected function execute(AuditSession $session, array $context): array
    {
        $documentIds = $context['document_ids'] ?? [];
        $language    = $context['survey_language'];

        $documentSummary = null;
        if (! empty($documentIds)) {
            $summaryColumn = $language === 'ar' ? 'summary_ar' : 'summary_en';

            $docs = Document::whereIn('id', $documentIds)
                ->whereNotNull($summaryColumn)
                ->get(['id', 'original_name', 'specified_name', $summaryColumn]);

            if ($docs->isNotEmpty()) {
                $documentSummary = $docs->map(fn ($d) =>
                    '### ' . ($d->specified_name ?? $d->original_name ?? "Document {$d->id}") . "\n" .
                    $d->{$summaryColumn}
                )->implode("\n\n");
            }
        }

        // Fall back to token-budgeted chunks from Step 2 when no pre-computed summary exists.
        $chunks = $documentSummary === null
            ? ($context['step2_output']['chunks'] ?? [])
            : [];

        // Step 5 is self-contained — drop the thread to avoid re-sending Step 3's
        // full chunk evidence to the model a second time.
        $this->agent->setMessages(collect());

        $result = $this->agent->generateFinalReport(
            chunks:          $chunks,
            findings:        $context['step4_output'],
            language:        $language,
            documentSummary: $documentSummary,
        );

        $this->lastPrompt = $this->agent->getLastPrompt();
        $this->lastUsage  = $this->agent->getLastUsage();

        return $result;
    }

    public function getMessages(): Collection
    {
        return $this->agent->getMessages();
    }
}
