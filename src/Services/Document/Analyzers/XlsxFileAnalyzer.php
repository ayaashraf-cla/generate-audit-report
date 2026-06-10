<?php

namespace Cla\GenerateAuditReport\Services\Document\Analyzers;

class XlsxFileAnalyzer extends BaseSpreadsheetAnalyzer
{
    public function supports(string $mimeType, string $extension): bool
    {
        return in_array($mimeType, [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-excel.sheet.macroEnabled.12',
        ], true) || in_array($extension, ['xlsx', 'xlsm'], true);
    }

    protected function fileCategory(): string { return 'structured'; }
    protected function mimeType(): string { return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'; }
    protected function extension(): string { return 'xlsx'; }
}
