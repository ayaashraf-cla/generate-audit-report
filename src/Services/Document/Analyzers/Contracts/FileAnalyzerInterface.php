<?php

namespace Cla\GenerateAuditReport\Services\Document\Analyzers\Contracts;

use Cla\GenerateAuditReport\Services\Document\Profile\DocumentProfile;

interface FileAnalyzerInterface
{
    public function supports(string $mimeType, string $extension): bool;

    public function analyze(string $absolutePath, string $fileName): DocumentProfile;
}
