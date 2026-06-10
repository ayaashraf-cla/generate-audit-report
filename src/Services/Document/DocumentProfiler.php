<?php

namespace Cla\GenerateAuditReport\Services\Document;

use Cla\GenerateAuditReport\Services\Document\Analyzers\Contracts\FileAnalyzerInterface;
use Cla\GenerateAuditReport\Services\Document\Profile\DocumentProfile;
use Cla\GenerateAuditReport\Services\Document\Profile\ProfileResult;
use Cla\GenerateAuditReport\Services\Document\Summarizers\Contracts\FileSummarizerInterface;

class DocumentProfiler
{
    /**
     * @param  FileAnalyzerInterface[]   $analyzers
     * @param  FileSummarizerInterface[] $summarizers
     */
    public function __construct(
        private readonly FileTypeDetector $detector,
        private readonly array            $analyzers,
        private readonly array            $summarizers,
    ) {}

    public function profile(
        string  $absolutePath,
        string  $fileName,
        string  $mimeType,
        string  $extension,
        ?string $extractedText,
    ): ProfileResult {
        $category = $this->detector->classify($mimeType, $extension);

        $profile = $category === 'structured'
            ? $this->analyzeStructured($absolutePath, $fileName, $mimeType, $extension)
            : null;

        if ($profile === null) {
            $profile = new DocumentProfile(
                fileName:      $fileName,
                fileCategory:  'unstructured',
                mimeType:      $mimeType,
                extension:     $extension,
                analyzerClass: 'none',
                stats:         null,
                extractedText: mb_substr((string) $extractedText, 0, 4000),
                profiledAt:    now()->toIso8601String(),
            );
        }

        $summary = $this->summarize($profile);

        return new ProfileResult($profile, $summary);
    }

    private function analyzeStructured(
        string $absolutePath,
        string $fileName,
        string $mimeType,
        string $extension,
    ): ?DocumentProfile {
        foreach ($this->analyzers as $analyzer) {
            if ($analyzer->supports($mimeType, $extension)) {
                try {
                    return $analyzer->analyze($absolutePath, $fileName);
                } catch (\Throwable $e) {
                    report($e);

                    return null;
                }
            }
        }

        return null;
    }

    private function summarize(DocumentProfile $profile): array
    {
        foreach ($this->summarizers as $summarizer) {
            if ($summarizer->supports($profile)) {
                return $summarizer->summarize($profile);
            }
        }

        return ['en' => null, 'ar' => null];
    }
}
