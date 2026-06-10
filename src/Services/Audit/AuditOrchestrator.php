<?php

namespace Cla\GenerateAuditReport\Services\Audit;

use Cla\GenerateAuditReport\Contracts\SurveyDataProviderInterface;
use Cla\GenerateAuditReport\Enums\AuditStep;
use Cla\GenerateAuditReport\Models\AuditReport;
use Cla\GenerateAuditReport\Models\AuditSession;
use Cla\GenerateAuditReport\Models\AuditStepLog;
use Cla\GenerateAuditReport\Services\Audit\Steps\AnalyzeSurveyAnswersStep;
use Cla\GenerateAuditReport\Services\Audit\Steps\CompareEvidenceStep;
use Cla\GenerateAuditReport\Services\Audit\Steps\GenerateFindingsStep;
use Cla\GenerateAuditReport\Services\Audit\Steps\GenerateReportStep;
use Cla\GenerateAuditReport\Services\Audit\Steps\RetrieveDocumentEvidenceStep;
use Cla\GenerateAuditReport\Services\Document\Profile\DocumentStats;
use AyaAshraf\LaravelRag\Models\Document;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\UserMessage;

class AuditOrchestrator
{
    public function __construct(
        private readonly SurveyDataProviderInterface  $surveyDataProvider,
        private readonly AuditContextBuilder          $contextBuilder,
        private readonly AuditProgressBroadcaster     $broadcaster,
        private readonly AnalyzeSurveyAnswersStep      $step1,
        private readonly RetrieveDocumentEvidenceStep  $step2,
        private readonly CompareEvidenceStep           $step3,
        private readonly GenerateFindingsStep          $step4,
        private readonly GenerateReportStep            $step5,
    ) {}

    public function run(AuditSession $session): void
    {
        $session->markRunning();

        if (! $session->thread_id) {
            $session->update(['thread_id' => (string) Str::uuid()]);
        }

        $logSubject = $session->context_prompt !== null
            ? "builder ({$session->auditable_type}:{$session->auditable_id})"
            : "survey {$session->survey_id}";

        Log::info("Audit [{$session->id}] started for {$logSubject}");

        try {
            if ($session->context_prompt !== null) {
                [$ctx, $documents] = $this->buildBuilderContext($session);
            } else {
                [$ctx, $documents] = $this->buildSurveyContext($session);
            }

            $documentIds = $ctx['document_ids'];
            $fileNames   = $ctx['file_names'];

            $this->step3->getAgent()->setDocumentContext($documentIds, $fileNames, $ctx['survey_language']);
            $this->step4->getAgent()->setDocumentContext($documentIds, $fileNames, $ctx['survey_language']);

            // ── Step 1: Analyze Answers ──────────────────────────
            $step1WasDone = $this->step1->alreadyCompleted($session);
            $this->broadcaster->stepStarted($session, AuditStep::AnalyzeAnswers);
            $step1Out = $this->runStep($this->step1, $session, $ctx);
            $ctx['step1_output'] = $step1Out;
            $ctx['messages'] = $step1WasDone
                ? $this->loadMessagesSnapshot($session, AuditStep::AnalyzeAnswers)
                : $this->step1->getMessages();
            $this->broadcaster->stepCompleted($session, AuditStep::AnalyzeAnswers, [
                'risk_signals'  => count($step1Out['risk_signals'] ?? []),
                'key_topics'    => count($step1Out['key_topics'] ?? []),
                'completeness'  => $step1Out['overall_completeness'] ?? null,
            ]);

            // ── Step 2: Retrieve Evidence ────────────────────────
            $this->broadcaster->stepStarted($session, AuditStep::RetrieveEvidence);
            $step2Out = $this->runStep($this->step2, $session, $ctx);
            $ctx['step2_output'] = $step2Out;
            $this->broadcaster->stepCompleted($session, AuditStep::RetrieveEvidence, [
                'chunks_found' => $step2Out['chunk_count'],
            ]);

            if ($warning = $step2Out['evidence_warning'] ?? null) {
                Log::warning("Audit [{$session->id}] evidence warning: {$warning}");
            }

            // ── Step 2b: Readiness check ─────────────────────────
            if (! empty($step2Out['chunks'])) {
                $readinessAgent = new AuditAgent();
                $readiness = $readinessAgent->checkReadiness(
                    surveyContextText: $ctx['survey_context_text'],
                    initialAnalysis:   $ctx['step1_output'],
                    chunks:            $step2Out['chunks'],
                    fileNames:         array_values($fileNames),
                    language:          $ctx['survey_language'],
                );

                $isReady = (bool) ($readiness['ready'] ?? true);
                Log::info("Audit [{$session->id}] readiness: " . ($isReady ? 'ready' : 'needs more context — ' . ($readiness['reasoning'] ?? '')));

                if (! $isReady && ! empty($readiness['additional_queries'])) {
                    $supplemental = $this->step2->supplementalRetrieve(
                        $readiness['additional_queries'],
                        $documentIds,
                        $fileNames,
                        $ctx['survey_language']
                    );

                    $merged = collect($step2Out['chunks'])
                        ->concat($supplemental)
                        ->unique('id')
                        ->values()
                        ->toArray();

                    $step2Out['chunks']      = $merged;
                    $step2Out['chunk_count'] = count($merged);
                    $ctx['step2_output']     = $step2Out;

                    Log::info("Audit [{$session->id}] supplemental retrieval added " . count($supplemental) . " chunks (total: " . count($merged) . ")");
                }
            }

            // ── Step 3: Compare Evidence ─────────────────────────
            $step3WasDone = $this->step3->alreadyCompleted($session);
            $this->broadcaster->stepStarted($session, AuditStep::CompareEvidence);
            $step3Out = $this->runStep($this->step3, $session, $ctx);
            $ctx['step3_output'] = $step3Out;
            if ($step3WasDone) {
                $ctx['messages'] = $this->loadMessagesSnapshot($session, AuditStep::CompareEvidence);
            } else {
                $ctx['messages'] = $this->step3->getMessages()->filter(function ($msg) {
                    if (! ($msg instanceof UserMessage)) {
                        return true;
                    }
                    return ! str_contains($msg->content ?? '', '--- DOCUMENT EVIDENCE ---')
                        && ! str_contains($msg->content ?? '', '--- الأدلة الوثائقية ---');
                })->values();
            }
            $this->broadcaster->stepCompleted($session, AuditStep::CompareEvidence, [
                'gaps_found'           => count($step3Out['documentation_gaps'] ?? []),
                'contradictions_found' => count($step3Out['contradicted_by_docs'] ?? []),
            ]);

            // ── Step 4: Generate Findings ────────────────────────
            $this->broadcaster->stepStarted($session, AuditStep::GenerateFindings);
            $step4Out = $this->runStep($this->step4, $session, $ctx);
            $ctx['step4_output'] = $step4Out;
            $ctx['messages']     = $this->step4->getMessages();
            $this->broadcaster->stepCompleted($session, AuditStep::GenerateFindings, [
                'findings_count'   => count($step4Out['findings'] ?? []),
                'compliance_score' => $step4Out['compliance_score'] ?? null,
                'risk_level'       => $step4Out['risk_level'] ?? null,
            ]);

            // ── Step 5: Generate Report ──────────────────────────
            $this->broadcaster->stepStarted($session, AuditStep::GenerateReport);
            $step5Out = $this->runStep($this->step5, $session, $ctx);
            $this->broadcaster->stepCompleted($session, AuditStep::GenerateReport);

            // ── Persist Final Report ─────────────────────────────
            $documentStats = $this->extractDocumentStats($documents);

            AuditReport::updateOrCreate(
                ['audit_session_id' => $session->id],
                [
                    'risk_level'          => $step4Out['risk_level'] ?? null,
                    'compliance_score'    => $step4Out['compliance_score'] ?? null,
                    'document_stats'      => $documentStats ?? ($step5Out['document_stats'] ?? null),
                    'document_summary'    => $step5Out['document_summary'] ?? null,
                    'executive_summary'   => $step5Out['executive_summary'] ?? null,
                    'findings'            => $step4Out['findings'] ?? [],
                    'recommendations'     => $step5Out['recommendations'] ?? [],
                    'evidence_references' => array_map(fn ($c) => [
                        'content'    => mb_substr((string) ($c['content'] ?? ''), 0, 300),
                        'similarity' => $c['similarity'] ?? null,
                    ], $step2Out['chunks'] ?? []),
                    'raw_report'          => json_encode($step5Out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                    'generated_at'        => now(),
                ]
            );

            $session->markCompleted();
            $this->broadcaster->auditCompleted($session);

            Log::info("Audit [{$session->id}] completed successfully");
        } catch (\Throwable $e) {
            Log::error("Audit [{$session->id}] failed: {$e->getMessage()}", [
                'trace' => $e->getTraceAsString(),
            ]);

            $session->markFailed($e->getMessage());
            $this->broadcaster->auditFailed($session, $e->getMessage());

            throw $e;
        }
    }

    /**
     * Build context from a legacy survey session (survey_id + response_id via SurveyDataProvider).
     *
     * @return array{0: array, 1: Collection}
     */
    private function buildSurveyContext(AuditSession $session): array
    {
        $surveyData = $this->surveyDataProvider->getSurveyContext(
            $session->survey_id,
            $session->response_id,
        );

        $context = $this->contextBuilder->build($surveyData);

        $documentableType   = config('audit.documentable_type', 'App\\Models\\Survey');
        $documentableColumn = config('audit.documentable_id_column', 'survey_id');

        $documents = Document::where('documentable_type', $documentableType)
            ->where('documentable_id', $session->{$documentableColumn})
            ->where('status', 'processed')
            ->select(['id', 'original_name', 'specified_name', 'document_profile', 'summary_en', 'summary_ar'])
            ->get();

        $documentIds = $documents->pluck('id')->toArray();

        $fileNames = $documents->mapWithKeys(fn ($d) => [
            $d->id => $d->specified_name ?? $d->original_name ?? ('Document ' . $d->id),
        ])->toArray();

        $documentSummaries = $documents->mapWithKeys(fn ($d) => [
            $d->id => ['en' => $d->summary_en, 'ar' => $d->summary_ar],
        ])->toArray();

        $ctx = [
            'survey_context_text' => $this->contextBuilder->formatForPrompt(
                $context,
                fileNames: array_values($fileNames)
            ),
            'survey_language'     => $context['survey_language'],
            'search_queries'      => $this->contextBuilder->extractSearchQueries($context),
            'document_ids'        => $documentIds,
            'file_names'          => $fileNames,
            'document_summaries'  => $documentSummaries,
            'messages'            => collect(),
        ];

        return [$ctx, $documents];
    }

    /**
     * Build context from a builder session (caller-provided prompt + document IDs).
     * SurveyDataProvider is never called on this path.
     *
     * @return array{0: array, 1: Collection}
     */
    private function buildBuilderContext(AuditSession $session): array
    {
        $language       = $session->context_language ?? 'en';
        $documentIdList = $session->context_document_ids ?? [];

        if (! empty($documentIdList)) {
            $documents = Document::whereIn('id', $documentIdList)
                ->where('status', 'processed')
                ->select(['id', 'original_name', 'specified_name', 'document_profile', 'summary_en', 'summary_ar'])
                ->get();
        } else {
            // No explicit document list — fall back to config-driven query against the auditable subject.
            $documentableType = config('audit.documentable_type', 'App\\Models\\Survey');
            $documents = Document::where('documentable_type', $documentableType)
                ->where('documentable_id', $session->auditable_id)
                ->where('status', 'processed')
                ->select(['id', 'original_name', 'specified_name', 'document_profile', 'summary_en', 'summary_ar'])
                ->get();
        }

        $documentIds = $documents->pluck('id')->toArray();

        $fileNames = $documents->mapWithKeys(fn ($d) => [
            $d->id => $d->specified_name ?? $d->original_name ?? ('Document ' . $d->id),
        ])->toArray();

        $documentSummaries = $documents->mapWithKeys(fn ($d) => [
            $d->id => ['en' => $d->summary_en, 'ar' => $d->summary_ar],
        ])->toArray();

        // Derive RAG search queries from the prompt text (up to 5 non-trivial lines/paragraphs).
        $searchQueries = collect(preg_split('/\n+/', $session->context_prompt))
            ->map(fn (string $line) => trim($line))
            ->filter(fn (string $line) => mb_strlen($line) > 10)
            ->take(5)
            ->values()
            ->toArray();

        if (empty($searchQueries)) {
            $searchQueries = [mb_substr($session->context_prompt, 0, 500)];
        }

        $ctx = [
            'survey_context_text' => $session->context_prompt,
            'survey_language'     => $language,
            'search_queries'      => $searchQueries,
            'document_ids'        => $documentIds,
            'file_names'          => $fileNames,
            'document_summaries'  => $documentSummaries,
            'messages'            => collect(),
        ];

        return [$ctx, $documents];
    }

    private function extractDocumentStats(Collection $documents): ?array
    {
        foreach ($documents as $document) {
            $raw = $document->document_profile;
            if (empty($raw)) {
                continue;
            }

            $profile = is_string($raw) ? json_decode($raw, true) : (array) $raw;

            if (($profile['file_category'] ?? '') !== 'structured') {
                continue;
            }

            $statsArray = $profile['stats'] ?? [];
            if (empty($statsArray)) {
                continue;
            }

            return DocumentStats::fromArray($statsArray)->toFlatStats();
        }

        return null;
    }

    private function runStep(
        \Cla\GenerateAuditReport\Services\Audit\Steps\AuditStep $step,
        AuditSession $session,
        array $ctx
    ): array {
        if ($step->alreadyCompleted($session)) {
            Log::info("Audit [{$session->id}] step {$step->step()->name} already completed — loading checkpoint");
            return $session->stepOutput($step->step()->value);
        }

        return $step->run($session, $ctx);
    }

    private function loadMessagesSnapshot(AuditSession $session, AuditStep $step): Collection
    {
        $log = AuditStepLog::where('audit_session_id', $session->id)
            ->where('step', $step->value)
            ->where('status', 'completed')
            ->first(['messages_snapshot']);

        if (! $log || empty($log->messages_snapshot)) {
            return collect();
        }

        return collect($log->messages_snapshot)->map(
            fn ($msg) => Message::tryFrom($msg)
        )->filter()->values();
    }
}
