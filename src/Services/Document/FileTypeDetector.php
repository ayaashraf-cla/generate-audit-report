<?php

namespace Cla\GenerateAuditReport\Services\Document;

class FileTypeDetector
{
    private const STRUCTURED_MIME_TYPES = [
        'text/csv',
        'application/csv',
        'text/comma-separated-values',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.oasis.opendocument.spreadsheet',
        'application/vnd.ms-excel.sheet.macroEnabled.12',
        'application/x-vnd.oasis.opendocument.spreadsheet',
    ];

    private const STRUCTURED_EXTENSIONS = [
        'csv', 'tsv', 'xlsx', 'xls', 'ods', 'xlsm',
    ];

    public function classify(string $mimeType, string $extension): string
    {
        $mimeType  = strtolower(trim($mimeType));
        $extension = strtolower(trim($extension, ". \t"));

        if (in_array($mimeType, self::STRUCTURED_MIME_TYPES, true)) {
            return 'structured';
        }

        if (in_array($extension, self::STRUCTURED_EXTENSIONS, true)) {
            return 'structured';
        }

        return 'unstructured';
    }
}
