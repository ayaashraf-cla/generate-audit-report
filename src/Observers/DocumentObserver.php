<?php

namespace Cla\GenerateAuditReport\Observers;

use Cla\GenerateAuditReport\Jobs\ProfileDocument;
use AyaAshraf\LaravelRag\Enums\DocumentStatus;
use AyaAshraf\LaravelRag\Models\Document;

class DocumentObserver
{
    public function updated(Document $document): void
    {
        if (
            $document->wasChanged('status')
            && $document->status === DocumentStatus::PROCESSED
        ) {
            ProfileDocument::dispatch($document);
        }
    }
}
