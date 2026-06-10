<?php

namespace Cla\GenerateAuditReport\Services\Document\Profile;

readonly class DocumentProfile
{
    public function __construct(
        public string          $fileName,
        public string          $fileCategory,   // 'structured' | 'unstructured'
        public string          $mimeType,
        public string          $extension,
        public string          $analyzerClass,
        public ?DocumentStats  $stats,
        public ?string         $extractedText,
        public string          $profiledAt,
    ) {}

    public function isStructured(): bool
    {
        return $this->fileCategory === 'structured';
    }

    public function toArray(): array
    {
        return [
            'file_category'          => $this->fileCategory,
            'mime_type'              => $this->mimeType,
            'extension'              => $this->extension,
            'analyzer'               => $this->analyzerClass,
            'profiled_at'            => $this->profiledAt,
            'stats'                  => $this->stats?->toArray(),
            'summary_generated_from' => $this->stats !== null ? 'computed_stats' : 'extracted_text',
        ];
    }
}
