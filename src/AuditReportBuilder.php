<?php

namespace Cla\GenerateAuditReport;

use AyaAshraf\LaravelRag\Models\Document;
use Cla\GenerateAuditReport\Enums\AuditStatus;
use Cla\GenerateAuditReport\Jobs\RunAuditWorkflow;
use Cla\GenerateAuditReport\Models\AuditSession;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class AuditReportBuilder
{
    private ?Model $subject = null;

    /** @var Collection<int,Document>|null */
    private ?Collection $documents = null;

    private ?string $promptOverride = null;
    private string $language = 'en';
    private ?int $initiatedBy = null;

    public function for(Model $model): static
    {
        $this->subject = $model;
        return $this;
    }

    /**
     * Pass the collection of already-processed Document models to audit against.
     * If omitted, the orchestrator falls back to the config-driven documentable query.
     *
     * @param  Collection<int,Document>  $documents
     */
    public function documents(Collection $documents): static
    {
        $this->documents = $documents;
        return $this;
    }

    /**
     * Supply a custom context/prompt string.
     * When set, the orchestrator skips SurveyDataProvider entirely (Phase D behaviour).
     */
    public function prompt(string $prompt): static
    {
        $this->promptOverride = $prompt;
        return $this;
    }

    public function language(string $language): static
    {
        $this->language = $language;
        return $this;
    }

    public function initiatedBy(int $userId): static
    {
        $this->initiatedBy = $userId;
        return $this;
    }

    /**
     * Create the AuditSession, persist context data, and dispatch the background job.
     *
     * @throws \InvalidArgumentException if no subject model has been set.
     */
    public function generate(): AuditSession
    {
        if ($this->subject === null) {
            throw new \InvalidArgumentException(
                'A subject model must be provided via ->for($model) before calling generate().'
            );
        }

        $session = AuditSession::create([
            'auditable_type'       => get_class($this->subject),
            'auditable_id'         => $this->subject->getKey(),
            'initiated_by'         => $this->initiatedBy ?? auth()->id(),
            'provider'             => config('audit.chat.provider', 'gemini'),
            'status'               => AuditStatus::Pending->value,
            'context_prompt'       => $this->promptOverride,
            'context_document_ids' => $this->documents
                ? $this->documents->pluck('id')->values()->all()
                : null,
            'context_language'     => $this->language,
        ]);

        RunAuditWorkflow::dispatch($session)->onQueue(
            config('audit.queue', 'audit-workflow')
        );

        return $session;
    }
}
