<?php

namespace Cla\GenerateAuditReport\Services\Document\Summarizers\Contracts;

use Cla\GenerateAuditReport\Services\Document\Profile\DocumentProfile;

interface FileSummarizerInterface
{
    public function supports(DocumentProfile $profile): bool;

    /**
     * @return array{en: string|null, ar: string|null}
     */
    public function summarize(DocumentProfile $profile): array;
}
