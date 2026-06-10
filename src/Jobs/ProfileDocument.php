<?php

namespace Cla\GenerateAuditReport\Jobs;

use Cla\GenerateAuditReport\Services\Document\DocumentProfiler;
use AyaAshraf\LaravelRag\Models\Document;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Storage;

class ProfileDocument implements ShouldQueue
{
    use Queueable;

    public int $tries   = 2;
    public int $timeout = 300;

    public function __construct(public readonly Document $document) {}

    public function middleware(): array
    {
        return [new WithoutOverlapping("profile-document:{$this->document->id}")];
    }

    public function handle(DocumentProfiler $profiler): void
    {
        $doc = $this->document;

        $absolutePath = Storage::disk($doc->disk)->path($doc->path);
        $fileName     = $doc->specified_name ?? $doc->original_name ?? 'Unknown';
        $mimeType     = $doc->mime_type ?? '';
        $extension    = strtolower(pathinfo($doc->original_name ?? '', PATHINFO_EXTENSION));

        $result = $profiler->profile(
            absolutePath:  $absolutePath,
            fileName:      $fileName,
            mimeType:      $mimeType,
            extension:     $extension,
            extractedText: $doc->extracted_text,
        );

        // Direct attribute assignment bypasses $fillable on the vendor model.
        $doc->document_profile = $result->profile->toArray();
        $doc->summary_en       = $result->summary['en'];
        $doc->summary_ar       = $result->summary['ar'];
        $doc->profiled_at      = now();
        $doc->save();
    }
}
