<?php

namespace Cla\GenerateAuditReport\Services\Document\Analyzers;

use Cla\GenerateAuditReport\Services\Document\Analyzers\Contracts\FileAnalyzerInterface;
use Cla\GenerateAuditReport\Services\Document\Profile\DocumentProfile;
use Cla\GenerateAuditReport\Services\Document\Profile\DocumentStats;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as SpreadsheetDate;

abstract class BaseSpreadsheetAnalyzer implements FileAnalyzerInterface
{
    private const AMOUNT_KEYWORDS = [
        'amount', 'total', 'price', 'revenue', 'value', 'gross', 'charge', 'payment', 'invoice',
        'المبلغ', 'الإجمالي', 'القيمة', 'الإيراد', 'السعر', 'الرسوم', 'الدفع',
    ];

    private const CURRENCY_CODES = ['SAR', 'USD', 'AED', 'EUR', 'GBP', 'EGP', 'KWD', 'BHD', 'QAR', 'OMR'];

    private const DATE_KEYWORDS = ['date', 'day', 'time', 'created', 'updated', 'period', 'month', 'year',
        'تاريخ', 'يوم', 'وقت', 'شهر', 'سنة'];

    private const SUMMARY_ROW_PATTERN = '/^(total|grand\s*total|summary|subtotal|إجمالي|المجموع|مجموع|الإجمالي)/iu';

    abstract protected function fileCategory(): string;
    abstract protected function mimeType(): string;
    abstract protected function extension(): string;

    public function analyze(string $absolutePath, string $fileName): DocumentProfile
    {
        $reader = IOFactory::createReaderForFile($absolutePath);
        $reader->setReadDataOnly(true);

        $spreadsheet = $reader->load($absolutePath);
        $sheet       = $spreadsheet->getActiveSheet();
        $sheetName   = $sheet->getTitle();

        $stats = $this->aggregateSheet($sheet, $fileName);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return new DocumentProfile(
            fileName:      $fileName,
            fileCategory:  'structured',
            mimeType:      $this->mimeType(),
            extension:     $this->extension(),
            analyzerClass: static::class,
            stats:         new DocumentStats(
                totalRows:            $stats['totalRows'],
                totalColumns:         $stats['totalColumns'],
                hasHeaderRow:         $stats['hasHeaderRow'],
                columnNames:          $stats['columnNames'],
                columnTypes:          $stats['columnTypes'],
                numericColumns:       $stats['numericColumns'],
                dateColumns:          $stats['dateColumns'],
                currency:             $stats['currency'],
                primaryAmountColumn:  $stats['primaryAmountColumn'],
                sheetName:            $sheetName,
                detectedStatusColumn: $stats['detectedStatusColumn'],
                statusBreakdown:      $stats['statusBreakdown'],
            ),
            extractedText: null,
            profiledAt:    now()->toIso8601String(),
        );
    }

    private function aggregateSheet(mixed $sheet, string $fileName): array
    {
        $highestRow = $sheet->getHighestRow();
        $highestCol = $sheet->getHighestColumn();
        $colCount   = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestCol);

        if ($highestRow < 1 || $colCount < 1) {
            return $this->emptyResult();
        }

        $rowIterator = $sheet->getRowIterator(1, min($highestRow, 100000));
        $headers     = null;
        $hasHeader   = false;
        $dataStart   = 1;

        foreach ($rowIterator as $row) {
            $cells = $this->extractRowValues($row, $colCount);
            if ($this->isHeaderRow($cells)) {
                $headers   = array_map('mb_strtolower', $cells);
                $hasHeader = true;
                $dataStart = $row->getRowIndex() + 1;
            } else {
                $headers   = array_map(fn ($i) => "col_{$i}", range(0, $colCount - 1));
                $dataStart = $row->getRowIndex();
            }
            break;
        }

        $amountColIdx   = $this->detectAmountColumn($headers);
        $dateColIndices = $this->detectDateColumns($headers);
        $currencyColIdx = $this->detectCurrencyColumn($headers);
        $statusColIdx   = $this->detectStatusColumn($headers);

        Log::debug("DocumentProfiler [{$fileName}]: column detection", [
            'headers'         => $headers,
            'amount_col_idx'  => $amountColIdx,
            'amount_col_name' => $amountColIdx !== null ? ($headers[$amountColIdx] ?? null) : 'not detected (will use fallback)',
            'date_col_idxs'   => $dateColIndices,
            'status_col_idx'  => $statusColIdx,
        ]);

        $numericSums   = array_fill(0, $colCount, 0.0);
        $numericMins   = array_fill(0, $colCount, PHP_FLOAT_MAX);
        $numericMaxes  = array_fill(0, $colCount, -PHP_FLOAT_MAX);
        $numericCounts = array_fill(0, $colCount, 0);
        $dateMins      = array_fill(0, $colCount, null);
        $dateMaxes     = array_fill(0, $colCount, null);
        $dateCounts    = array_fill(0, $colCount, 0);
        $nullCounts    = array_fill(0, $colCount, 0);
        $currency           = null;
        $rowCount           = 0;
        $statusData         = [];
        $sampleAmountValues = [];

        $rowIterator = $sheet->getRowIterator($dataStart);
        foreach ($rowIterator as $row) {
            $cells = $this->extractRowValues($row, $colCount);

            if (empty(array_filter($cells, fn ($v) => $v !== null && $v !== ''))) {
                continue;
            }

            if ($this->isSummaryRow($cells)) {
                continue;
            }

            $rowCount++;

            if ($amountColIdx !== null && count($sampleAmountValues) < 50) {
                $rawAmt                = (string) ($cells[$amountColIdx] ?? '');
                ['value' => $cleanAmt] = $this->stripInlineCurrency($rawAmt);
                $sampleAmountValues[]  = ['raw' => $rawAmt, 'parsed' => $this->parseNumeric($cleanAmt)];
            }

            for ($col = 0; $col < $colCount; $col++) {
                $cellValue = (string) ($cells[$col] ?? '');

                if ($cellValue === '') {
                    $nullCounts[$col]++;
                    continue;
                }

                if ($col === $currencyColIdx && $currency === null) {
                    $currency = $this->normalizeCurrency($cellValue);
                }

                if (in_array($col, $dateColIndices, true)) {
                    $parsed = $this->parseDateValue($cells[$col]);
                    if ($parsed !== null) {
                        $dateCounts[$col]++;
                        if ($dateMins[$col] === null || $parsed < $dateMins[$col]) {
                            $dateMins[$col] = $parsed;
                        }
                        if ($dateMaxes[$col] === null || $parsed > $dateMaxes[$col]) {
                            $dateMaxes[$col] = $parsed;
                        }
                        continue;
                    }
                }

                $parseValue = $cellValue;
                if ($col === $amountColIdx) {
                    ['value' => $parseValue, 'currency' => $inlineCurrency] = $this->stripInlineCurrency($cellValue);
                    if ($inlineCurrency && $currency === null) {
                        $currency = $inlineCurrency;
                    }
                }

                $float = $this->parseNumeric($parseValue);
                if ($float !== null) {
                    $numericSums[$col]  += $float;
                    $numericCounts[$col]++;
                    if ($float < $numericMins[$col]) {
                        $numericMins[$col] = $float;
                    }
                    if ($float > $numericMaxes[$col]) {
                        $numericMaxes[$col] = $float;
                    }
                } else {
                    $nullCounts[$col]++;
                }
            }

            if ($statusColIdx !== null) {
                $statusVal = mb_strtolower(trim((string) ($cells[$statusColIdx] ?? '')));
                if ($statusVal !== '') {
                    if (! isset($statusData[$statusVal])) {
                        $statusData[$statusVal] = ['count' => 0, 'amount' => 0.0];
                    }
                    $statusData[$statusVal]['count']++;

                    if ($amountColIdx !== null) {
                        $rawAmount = (string) ($cells[$amountColIdx] ?? '');
                        ['value' => $cleanAmount] = $this->stripInlineCurrency($rawAmount);
                        $amtFloat = $this->parseNumeric($cleanAmount);
                        if ($amtFloat !== null) {
                            $statusData[$statusVal]['amount'] += $amtFloat;
                        }
                    }
                }
            }
        }

        Log::debug("DocumentProfiler [{$fileName}]: amount column sample", [
            'column'        => $amountColIdx !== null ? ($headers[$amountColIdx] ?? "col_{$amountColIdx}") : 'fallback (not detected by name)',
            'sample_values' => $sampleAmountValues,
            'raw_values'    => array_column($sampleAmountValues, 'raw'),
            'parsed_values' => array_column($sampleAmountValues, 'parsed'),
        ]);

        $originalNames = $hasHeader
            ? array_values(array_slice($this->getFirstRowValues($sheet, $colCount), 0, $colCount))
            : array_map(fn ($i) => "col_{$i}", range(0, $colCount - 1));

        $columnTypes = [];
        $numericCols = [];
        $dateCols    = [];

        foreach ($headers as $idx => $header) {
            $original = $originalNames[$idx] ?? $header;

            if ($dateCounts[$idx] > 0 && $dateCounts[$idx] >= $numericCounts[$idx]) {
                $columnTypes[$original] = 'date';
                $dateCols[$original]    = [
                    'min'            => $dateMins[$idx],
                    'max'            => $dateMaxes[$idx],
                    'non_null_count' => $dateCounts[$idx],
                    'null_count'     => $nullCounts[$idx],
                ];
            } elseif ($numericCounts[$idx] > 0) {
                $columnTypes[$original] = 'numeric';
                $numericCols[$original] = [
                    'sum'            => round($numericSums[$idx], 2),
                    'min'            => $numericMins[$idx] === PHP_FLOAT_MAX ? null : round($numericMins[$idx], 2),
                    'max'            => $numericMaxes[$idx] === -PHP_FLOAT_MAX ? null : round($numericMaxes[$idx], 2),
                    'non_null_count' => $numericCounts[$idx],
                    'null_count'     => $nullCounts[$idx],
                ];
            } else {
                $columnTypes[$original] = 'text';
            }
        }

        $primaryAmountCol = $amountColIdx !== null
            ? ($originalNames[$amountColIdx] ?? null)
            : $this->fallbackAmountColumn($numericCols);

        if ($primaryAmountCol && isset($numericCols[$primaryAmountCol])) {
            $detectedMax  = $numericCols[$primaryAmountCol]['max'] ?? 0;
            $parsedSample = array_filter(
                array_column($sampleAmountValues, 'parsed'),
                fn ($v) => is_numeric($v) && (float) $v > 0,
            );

            if ($detectedMax > 1_000_000) {
                Log::warning("DocumentProfiler [{$fileName}]: amount column '{$primaryAmountCol}' has suspicious max value", [
                    'max'           => $detectedMax,
                    'sum'           => $numericCols[$primaryAmountCol]['sum'],
                    'sample_parsed' => array_column($sampleAmountValues, 'parsed'),
                ]);
            }

            if (! empty($parsedSample)) {
                $sampleMax = max(array_map('floatval', $parsedSample));
                if ($sampleMax > 0 && $detectedMax > 10 * $sampleMax) {
                    Log::error("DocumentProfiler [{$fileName}]: PARSING ANOMALY — detected max is >10× the largest sample value", [
                        'detected_max'  => $detectedMax,
                        'sample_max'    => $sampleMax,
                        'ratio'         => round($detectedMax / $sampleMax),
                        'sample_parsed' => array_column($sampleAmountValues, 'parsed'),
                    ]);
                }

                $sampleAvg   = array_sum(array_map('floatval', $parsedSample)) / count($parsedSample);
                $detectedAvg = ($numericCols[$primaryAmountCol]['non_null_count'] ?? 0) > 0
                    ? (float) $numericCols[$primaryAmountCol]['sum'] / (int) $numericCols[$primaryAmountCol]['non_null_count']
                    : null;

                if ($detectedAvg !== null && $sampleAvg > 0 && $detectedAvg > 10 * $sampleAvg) {
                    Log::error("DocumentProfiler [{$fileName}]: PARSING ANOMALY — average amount is >10× the sample average", [
                        'detected_average' => round($detectedAvg, 2),
                        'sample_average'   => round($sampleAvg, 2),
                        'ratio'            => round($detectedAvg / $sampleAvg),
                        'raw_values'       => array_column($sampleAmountValues, 'raw'),
                        'parsed_values'    => array_column($sampleAmountValues, 'parsed'),
                    ]);
                }
            }
        }

        $detectedStatusColName = $statusColIdx !== null
            ? ($originalNames[$statusColIdx] ?? null)
            : null;

        $this->validateStatusData($statusData, $rowCount, $numericSums, $amountColIdx, $fileName);

        return [
            'totalRows'            => $rowCount,
            'totalColumns'         => $colCount,
            'hasHeaderRow'         => $hasHeader,
            'columnNames'          => array_values($originalNames),
            'columnTypes'          => $columnTypes,
            'numericColumns'       => $numericCols,
            'dateColumns'          => $dateCols,
            'currency'             => $currency ?? $this->sniffCurrencyFromFileName($fileName),
            'primaryAmountColumn'  => $primaryAmountCol,
            'detectedStatusColumn' => $detectedStatusColName,
            'statusBreakdown'      => $this->computeStatusBreakdown($statusData),
        ];
    }

    private function validateStatusData(array $statusData, int $totalRows, array $numericSums, ?int $amountColIdx, string $fileName): void
    {
        if (empty($statusData)) {
            return;
        }

        $statusCountSum = (int) array_sum(array_column($statusData, 'count'));
        if ($statusCountSum !== $totalRows) {
            Log::warning("DocumentProfiler [{$fileName}]: status count mismatch", [
                'status_sum'     => $statusCountSum,
                'total_rows'     => $totalRows,
                'untracked_rows' => $totalRows - $statusCountSum,
            ]);
        }

        if ($amountColIdx !== null && isset($numericSums[$amountColIdx]) && $numericSums[$amountColIdx] > 0) {
            $statusAmountSum = (float) array_sum(array_column($statusData, 'amount'));
            $columnTotal     = $numericSums[$amountColIdx];
            $diff            = abs($statusAmountSum - $columnTotal);
            $diffPct         = $diff / $columnTotal * 100;

            if ($diffPct > 0.01) {
                Log::warning("DocumentProfiler [{$fileName}]: status amount mismatch", [
                    'status_amount_sum' => round($statusAmountSum, 2),
                    'column_total'      => round($columnTotal, 2),
                    'diff'              => round($diff, 2),
                    'diff_pct'          => round($diffPct, 4),
                ]);
            }
        }
    }

    private function detectAmountColumn(array $headers): ?int
    {
        $exact = [
            'amount', 'total_amount', 'net_amount', 'gross_amount',
            'payment_amount', 'invoice_amount', 'subtotal', 'المبلغ',
        ];
        foreach ($exact as $kw) {
            foreach ($headers as $idx => $h) {
                if (mb_strtolower((string) $h) === $kw) {
                    return $idx;
                }
            }
        }
        foreach (self::AMOUNT_KEYWORDS as $kw) {
            foreach ($headers as $idx => $h) {
                if (mb_stripos((string) $h, $kw) !== false && ! $this->isLikelyIdColumn((string) $h)) {
                    return $idx;
                }
            }
        }

        return null;
    }

    private function detectStatusColumn(array $headers): ?int
    {
        $exactKeywords = ['status', 'state', 'حالة', 'الحالة'];
        foreach ($exactKeywords as $kw) {
            foreach ($headers as $idx => $h) {
                if (mb_strtolower((string) $h) === $kw) {
                    return $idx;
                }
            }
        }

        foreach ($headers as $idx => $h) {
            $lower = mb_strtolower((string) $h);
            if (mb_stripos($lower, 'status') !== false || mb_stripos($lower, 'حالة') !== false) {
                return $idx;
            }
        }

        return null;
    }

    private function computeStatusBreakdown(array $statusData): ?array
    {
        if (empty($statusData)) {
            return null;
        }

        if (count($statusData) > 50) {
            return null;
        }

        $totalCount = array_sum(array_column($statusData, 'count'));
        $breakdown  = [];

        foreach ($statusData as $statusVal => $data) {
            $breakdown[$statusVal] = [
                'count'      => $data['count'],
                'amount'     => round($data['amount'], 2),
                'average'    => $data['count'] > 0 ? round($data['amount'] / $data['count'], 2) : 0.0,
                'percentage' => $totalCount > 0 ? round($data['count'] / $totalCount * 100, 2) : 0.0,
            ];
        }

        return $breakdown;
    }

    private function detectDateColumns(array $headers): array
    {
        $indices = [];
        foreach ($headers as $idx => $h) {
            foreach (self::DATE_KEYWORDS as $kw) {
                if (mb_stripos((string) $h, $kw) !== false) {
                    $indices[] = $idx;
                    break;
                }
            }
        }

        return $indices;
    }

    private function detectCurrencyColumn(array $headers): ?int
    {
        foreach ($headers as $idx => $h) {
            if (mb_stripos((string) $h, 'currency') !== false || mb_stripos((string) $h, 'عملة') !== false) {
                return $idx;
            }
        }

        return null;
    }

    private function isSummaryRow(array $cells): bool
    {
        $first = trim((string) ($cells[0] ?? ''));

        if ($first !== '' && preg_match(self::SUMMARY_ROW_PATTERN, $first) === 1) {
            return true;
        }

        if ($first === '' || ctype_digit($first)) {
            $limit = min(5, count($cells));
            for ($i = 1; $i < $limit; $i++) {
                $cell = trim((string) ($cells[$i] ?? ''));
                if ($cell !== '' && ! is_numeric($cell) && preg_match(self::SUMMARY_ROW_PATTERN, mb_strtolower($cell)) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    private function extractRowValues(mixed $row, int $colCount): array
    {
        $values       = [];
        $cellIterator = $row->getCellIterator('A', \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colCount));
        $cellIterator->setIterateOnlyExistingCells(false);
        foreach ($cellIterator as $cell) {
            $values[] = $cell->getValue();
        }

        return array_slice($values, 0, $colCount);
    }

    private function getFirstRowValues(mixed $sheet, int $colCount): array
    {
        $row    = $sheet->getRowIterator(1, 1)->current();
        $values = [];
        $ci     = $row->getCellIterator('A', \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colCount));
        $ci->setIterateOnlyExistingCells(false);
        foreach ($ci as $cell) {
            $values[] = (string) ($cell->getValue() ?? '');
        }

        return array_slice($values, 0, $colCount);
    }

    private function isHeaderRow(array $cells): bool
    {
        $nonEmpty = array_filter($cells, fn ($v) => trim((string) $v) !== '');
        if (count($nonEmpty) < 2) {
            return false;
        }
        foreach ($nonEmpty as $cell) {
            if (is_numeric(trim((string) $cell))) {
                return false;
            }
        }

        return true;
    }

    private function parseDateValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_float($value) || (is_int($value) && $value > 1)) {
            try {
                $dt   = SpreadsheetDate::excelToDateTimeObject((float) $value);
                $year = (int) $dt->format('Y');
                if ($year >= 1900 && $year <= 2100) {
                    return $dt->format('Y-m-d');
                }
            } catch (\Throwable) {}
        }

        if (is_string($value)) {
            foreach (['Y-m-d', 'Y/m/d', 'd/m/Y', 'm/d/Y', 'd-m-Y'] as $fmt) {
                $dt = \DateTime::createFromFormat($fmt, trim($value));
                if ($dt && $dt->format('Y') >= '1900' && $dt->format('Y') <= '2100') {
                    return $dt->format('Y-m-d');
                }
            }
        }

        return null;
    }

    private function parseNumeric(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (! is_string($value)) {
            return null;
        }

        $v = $this->normalizeDecimal($value);

        return $v !== null ? (float) $v : null;
    }

    private function normalizeDecimal(string $value): ?string
    {
        $value = $this->normalizeArabicDigits($value);
        $value = trim($value);
        $value = str_replace(["\u{00A0}", ' ', "\t", "'", '٬'], '', $value);
        $value = str_replace('٫', '.', $value);

        $lastDot   = strrpos($value, '.');
        $lastComma = strrpos($value, ',');

        if ($lastDot !== false && $lastComma !== false) {
            $decimal   = $lastDot > $lastComma ? '.' : ',';
            $thousands = $decimal === '.' ? ',' : '.';
            $value     = str_replace($thousands, '', $value);
            $value     = str_replace($decimal, '.', $value);
        } elseif ($lastComma !== false) {
            $value = $this->singleSeparator($value, ',');
        } elseif ($lastDot !== false) {
            $value = $this->singleSeparator($value, '.');
        }

        $value = preg_replace('/[^0-9.\-+]/', '', $value);

        return is_numeric($value) ? $value : null;
    }

    private function singleSeparator(string $value, string $sep): string
    {
        if ($sep === '.') {
            return $value;
        }

        $pos      = strrpos($value, $sep);
        $decimals = strlen($value) - $pos - 1;

        return ($decimals >= 1 && $decimals <= 4)
            ? str_replace($sep, '.', $value)
            : str_replace($sep, '', $value);
    }

    private function normalizeArabicDigits(string $value): string
    {
        return strtr($value, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }

    private function stripInlineCurrency(string $raw): array
    {
        $currency = null;
        foreach (self::CURRENCY_CODES as $code) {
            if (str_starts_with(strtoupper($raw), $code)) {
                $currency = $code;
                $raw      = trim(substr($raw, strlen($code)));
                break;
            }
            if (str_ends_with(strtoupper(trim($raw)), $code)) {
                $currency = $code;
                $raw      = trim(substr($raw, 0, -strlen($code)));
                break;
            }
        }

        return ['value' => trim($raw), 'currency' => $currency];
    }

    private function normalizeCurrency(string $raw): ?string
    {
        $upper = strtoupper(trim($raw));
        if (in_array($upper, self::CURRENCY_CODES, true)) {
            return $upper;
        }
        if (preg_match('/ر\.?س/u', $raw)) {
            return 'SAR';
        }

        return null;
    }

    private function sniffCurrencyFromFileName(string $fileName): ?string
    {
        foreach (self::CURRENCY_CODES as $code) {
            if (mb_stripos($fileName, $code) !== false) {
                return $code;
            }
        }

        return null;
    }

    private function fallbackAmountColumn(array $numericCols): ?string
    {
        $best    = null;
        $bestSum = -1;
        foreach ($numericCols as $col => $stats) {
            if ($this->isLikelyIdColumn(mb_strtolower($col))) {
                continue;
            }
            if ($stats['sum'] > $bestSum) {
                $bestSum = $stats['sum'];
                $best    = $col;
            }
        }

        return $best;
    }

    private function isLikelyIdColumn(string $header): bool
    {
        $exact = ['id', 'no', 'num', 'number', 'code', 'ref', 'serial', 'key', 'seq', '#'];
        if (in_array($header, $exact, true)) {
            return true;
        }
        $suffixes = ['_id', '_no', '_num', '_number', '_code', '_ref', '_serial', '_key', '_seq', ' id', ' no', ' number', ' code'];
        foreach ($suffixes as $s) {
            if (str_ends_with($header, $s)) {
                return true;
            }
        }

        return false;
    }

    private function emptyResult(): array
    {
        return [
            'totalRows'            => 0,
            'totalColumns'         => 0,
            'hasHeaderRow'         => false,
            'columnNames'          => [],
            'columnTypes'          => [],
            'numericColumns'       => [],
            'dateColumns'          => [],
            'currency'             => null,
            'primaryAmountColumn'  => null,
            'detectedStatusColumn' => null,
            'statusBreakdown'      => null,
        ];
    }
}
