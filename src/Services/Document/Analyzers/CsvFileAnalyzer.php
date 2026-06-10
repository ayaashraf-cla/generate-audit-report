<?php

namespace Cla\GenerateAuditReport\Services\Document\Analyzers;

use Cla\GenerateAuditReport\Services\Document\Analyzers\Contracts\FileAnalyzerInterface;
use Cla\GenerateAuditReport\Services\Document\Profile\DocumentProfile;
use Cla\GenerateAuditReport\Services\Document\Profile\DocumentStats;
use Illuminate\Support\Facades\Log;

class CsvFileAnalyzer implements FileAnalyzerInterface
{
    private const AMOUNT_KEYWORDS = [
        'amount', 'total', 'price', 'revenue', 'value', 'gross', 'charge', 'payment', 'invoice',
        'المبلغ', 'الإجمالي', 'القيمة', 'الإيراد', 'السعر', 'الرسوم', 'الدفع',
    ];

    private const CURRENCY_CODES = ['SAR', 'USD', 'AED', 'EUR', 'GBP', 'EGP', 'KWD', 'BHD', 'QAR', 'OMR'];

    private const SUMMARY_ROW_PATTERN = '/^(total|grand\s*total|summary|subtotal|إجمالي|المجموع|مجموع|الإجمالي)/iu';

    public function supports(string $mimeType, string $extension): bool
    {
        return in_array($mimeType, ['text/csv', 'application/csv', 'text/comma-separated-values'], true)
            || in_array($extension, ['csv', 'tsv'], true);
    }

    public function analyze(string $absolutePath, string $fileName): DocumentProfile
    {
        $handle = @fopen($absolutePath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open file for reading: {$absolutePath}");
        }

        try {
            $this->skipBom($handle);
            $stats = $this->aggregate($handle, $fileName);
        } finally {
            fclose($handle);
        }

        return new DocumentProfile(
            fileName:      $fileName,
            fileCategory:  'structured',
            mimeType:      'text/csv',
            extension:     'csv',
            analyzerClass: static::class,
            stats:         $stats,
            extractedText: null,
            profiledAt:    now()->toIso8601String(),
        );
    }

    private function aggregate($handle, string $fileName): DocumentStats
    {
        $firstLine = fgets($handle);
        if ($firstLine === false) {
            return $this->emptyStats();
        }
        rewind($handle);
        $this->skipBom($handle);

        $delimiter = $this->detectDelimiter($firstLine);

        $allRows   = [];
        $maxSample = 5000;

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            if ($row === [null]) {
                continue;
            }
            $allRows[] = array_map('trim', $row);
            if (count($allRows) >= $maxSample) {
                break;
            }
        }

        if (empty($allRows)) {
            return $this->emptyStats();
        }

        $hasHeader   = $this->isHeaderRow($allRows[0]);
        $headers     = $hasHeader
            ? array_map('mb_strtolower', $allRows[0])
            : array_map(fn ($i) => "col_{$i}", array_keys($allRows[0]));
        $dataStart   = $hasHeader ? 1 : 0;
        $columnCount = count($headers);

        $amountColIdx   = $this->detectAmountColumn($headers);
        $dateColIndices = $this->detectDateColumns($headers);
        $currencyColIdx = $this->detectCurrencyColumn($headers);
        $statusColIdx   = $this->detectStatusColumn($headers);

        Log::debug("DocumentProfiler [{$fileName}]: column detection", [
            'headers'         => $headers,
            'amount_col_idx'  => $amountColIdx,
            'amount_col_name' => $amountColIdx !== null ? ($allRows[0][$amountColIdx] ?? null) : 'not detected (will use fallback)',
            'date_col_idxs'   => $dateColIndices,
            'status_col_idx'  => $statusColIdx,
        ]);

        $numericSums      = array_fill(0, $columnCount, 0.0);
        $numericMins      = array_fill(0, $columnCount, PHP_FLOAT_MAX);
        $numericMaxes     = array_fill(0, $columnCount, -PHP_FLOAT_MAX);
        $numericCounts    = array_fill(0, $columnCount, 0);
        $dateMins         = array_fill(0, $columnCount, null);
        $dateMaxes        = array_fill(0, $columnCount, null);
        $dateCounts       = array_fill(0, $columnCount, 0);
        $nullCounts       = array_fill(0, $columnCount, 0);
        $detectedCurrency = null;
        $rowCount         = 0;
        $statusData       = [];
        $sampleAmountValues = [];

        for ($i = $dataStart; $i < count($allRows); $i++) {
            $row = $allRows[$i];

            if ($this->isSummaryRow($row)) {
                continue;
            }

            $rowCount++;

            if ($amountColIdx !== null && count($sampleAmountValues) < 50) {
                $rawAmt                = $row[$amountColIdx] ?? '';
                ['value' => $cleanAmt] = $this->stripInlineCurrency((string) $rawAmt);
                $parsedAmount          = $this->normalizeDecimal($cleanAmt);
                $sampleAmountValues[]  = ['raw' => $rawAmt, 'parsed' => $parsedAmount !== null ? (float) $parsedAmount : null];
            }

            $this->processRow(
                $row, $columnCount,
                $amountColIdx, $dateColIndices, $currencyColIdx,
                $numericSums, $numericMins, $numericMaxes, $numericCounts,
                $dateMins, $dateMaxes, $dateCounts, $nullCounts,
                $detectedCurrency,
            );

            if ($statusColIdx !== null) {
                $this->trackStatusRow($row, $statusColIdx, $amountColIdx, $statusData);
            }
        }

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            if ($row === [null]) {
                continue;
            }
            $row = array_map('trim', $row);

            if ($this->isSummaryRow($row)) {
                continue;
            }

            $rowCount++;
            $this->processRow(
                $row, $columnCount,
                $amountColIdx, $dateColIndices, $currencyColIdx,
                $numericSums, $numericMins, $numericMaxes, $numericCounts,
                $dateMins, $dateMaxes, $dateCounts, $nullCounts,
                $detectedCurrency,
            );

            if ($statusColIdx !== null) {
                $this->trackStatusRow($row, $statusColIdx, $amountColIdx, $statusData);
            }
        }

        Log::debug("DocumentProfiler [{$fileName}]: amount column sample", [
            'column'        => $amountColIdx !== null
                ? ($hasHeader ? ($allRows[0][$amountColIdx] ?? "col_{$amountColIdx}") : "col_{$amountColIdx}")
                : 'fallback (not detected by name)',
            'sample_values' => $sampleAmountValues,
            'raw_values'    => array_column($sampleAmountValues, 'raw'),
            'parsed_values' => array_column($sampleAmountValues, 'parsed'),
        ]);

        $columnTypes   = [];
        $numericCols   = [];
        $dateCols      = [];
        $originalNames = $hasHeader ? $allRows[0] : $headers;

        foreach ($headers as $idx => $header) {
            $originalName = $originalNames[$idx] ?? $header;

            if ($dateCounts[$idx] > 0 && $dateCounts[$idx] >= $numericCounts[$idx]) {
                $columnTypes[$originalName] = 'date';
                $dateCols[$originalName]    = [
                    'min'            => $dateMins[$idx],
                    'max'            => $dateMaxes[$idx],
                    'non_null_count' => $dateCounts[$idx],
                    'null_count'     => $nullCounts[$idx],
                ];
            } elseif ($numericCounts[$idx] > 0) {
                $columnTypes[$originalName] = 'numeric';
                $numericCols[$originalName] = [
                    'sum'            => round($numericSums[$idx], 2),
                    'min'            => $numericMins[$idx] === PHP_FLOAT_MAX ? null : round($numericMins[$idx], 2),
                    'max'            => $numericMaxes[$idx] === -PHP_FLOAT_MAX ? null : round($numericMaxes[$idx], 2),
                    'non_null_count' => $numericCounts[$idx],
                    'null_count'     => $nullCounts[$idx],
                ];
            } else {
                $columnTypes[$originalName] = 'text';
            }
        }

        $primaryAmountCol = $amountColIdx !== null
            ? ($originalNames[$amountColIdx] ?? null)
            : $this->fallbackAmountColumn($numericCols);

        if ($primaryAmountCol && isset($numericCols[$primaryAmountCol])) {
            $detectedMax  = $numericCols[$primaryAmountCol]['max'] ?? 0;
            $detectedAvg  = ($numericCols[$primaryAmountCol]['non_null_count'] ?? 0) > 0
                ? (float) $numericCols[$primaryAmountCol]['sum'] / (int) $numericCols[$primaryAmountCol]['non_null_count']
                : null;
            $parsedSample = array_filter(
                array_column($sampleAmountValues, 'parsed'),
                fn ($v) => is_numeric($v) && (float) $v > 0,
            );

            if ($detectedMax > 1_000_000) {
                Log::warning("DocumentProfiler [{$fileName}]: amount column '{$primaryAmountCol}' has suspicious max value", [
                    'max'           => $detectedMax,
                    'sum'           => $numericCols[$primaryAmountCol]['sum'],
                    'sample_parsed' => array_column($sampleAmountValues, 'parsed'),
                    'hint'          => 'Likely cause: a summary/total row was not filtered.',
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

                $sampleAvg = array_sum(array_map('floatval', $parsedSample)) / count($parsedSample);
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

        return new DocumentStats(
            totalRows:            $rowCount,
            totalColumns:         $columnCount,
            hasHeaderRow:         $hasHeader,
            columnNames:          array_values($originalNames),
            columnTypes:          $columnTypes,
            numericColumns:       $numericCols,
            dateColumns:          $dateCols,
            currency:             $detectedCurrency ?? $this->sniffCurrencyFromFileName($fileName),
            primaryAmountColumn:  $primaryAmountCol,
            detectedStatusColumn: $detectedStatusColName,
            statusBreakdown:      $this->computeStatusBreakdown($statusData),
        );
    }

    private function processRow(
        array   $row,
        int     $columnCount,
        ?int    $amountColIdx,
        array   $dateColIndices,
        ?int    $currencyColIdx,
        array   &$numericSums,
        array   &$numericMins,
        array   &$numericMaxes,
        array   &$numericCounts,
        array   &$dateMins,
        array   &$dateMaxes,
        array   &$dateCounts,
        array   &$nullCounts,
        ?string &$detectedCurrency,
    ): void {
        for ($col = 0; $col < $columnCount; $col++) {
            $raw = $row[$col] ?? '';

            if ($raw === '') {
                $nullCounts[$col]++;
                continue;
            }

            if ($col === $currencyColIdx && $detectedCurrency === null) {
                $detectedCurrency = $this->normalizeCurrency($raw);
            }

            $value = $raw;
            if ($col === $amountColIdx) {
                ['value' => $value, 'currency' => $inlineCurrency] = $this->stripInlineCurrency($raw);
                if ($inlineCurrency && $detectedCurrency === null) {
                    $detectedCurrency = $inlineCurrency;
                }
            }

            if (in_array($col, $dateColIndices, true)) {
                $parsed = $this->parseDate($raw);
                if ($parsed !== null) {
                    $dateCounts[$col]++;
                    if ($dateMins[$col] === null || $parsed < $dateMins[$col]) { $dateMins[$col] = $parsed; }
                    if ($dateMaxes[$col] === null || $parsed > $dateMaxes[$col]) { $dateMaxes[$col] = $parsed; }
                    continue;
                }
            }

            $normalized = $this->normalizeDecimal($value);
            if ($normalized !== null) {
                $float = (float) $normalized;
                $numericSums[$col]  += $float;
                $numericCounts[$col]++;
                if ($float < $numericMins[$col]) { $numericMins[$col] = $float; }
                if ($float > $numericMaxes[$col]) { $numericMaxes[$col] = $float; }
            } else {
                $parsed = $this->parseDate($raw);
                if ($parsed !== null) {
                    $dateCounts[$col]++;
                    if ($dateMins[$col] === null || $parsed < $dateMins[$col]) { $dateMins[$col] = $parsed; }
                    if ($dateMaxes[$col] === null || $parsed > $dateMaxes[$col]) { $dateMaxes[$col] = $parsed; }
                } else {
                    $nullCounts[$col]++;
                }
            }
        }
    }

    private function trackStatusRow(array $row, int $statusColIdx, ?int $amountColIdx, array &$statusData): void
    {
        $statusVal = mb_strtolower(trim($row[$statusColIdx] ?? ''));
        if ($statusVal === '') {
            return;
        }

        if (! isset($statusData[$statusVal])) {
            $statusData[$statusVal] = ['count' => 0, 'amount' => 0.0];
        }
        $statusData[$statusVal]['count']++;

        if ($amountColIdx !== null) {
            $rawAmount = $row[$amountColIdx] ?? '';
            ['value' => $cleanAmount] = $this->stripInlineCurrency((string) $rawAmount);
            $normalized = $this->normalizeDecimal($cleanAmount);
            if ($normalized !== null) {
                $statusData[$statusVal]['amount'] += (float) $normalized;
            }
        }
    }

    private function computeStatusBreakdown(array $statusData): ?array
    {
        if (empty($statusData) || count($statusData) > 50) {
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

    private function detectDelimiter(string $line): string
    {
        $scores = [];
        foreach ([',', "\t", ';', '|'] as $d) {
            $scores[$d] = substr_count($line, $d);
        }
        arsort($scores);
        $best = array_key_first($scores);

        return $scores[$best] > 0 ? $best : ',';
    }

    private function isHeaderRow(array $row): bool
    {
        $nonEmpty = array_filter($row, fn ($v) => trim($v) !== '');
        if (count($nonEmpty) < 2) {
            return false;
        }
        foreach ($nonEmpty as $cell) {
            if (preg_match('/^\d+([.,]\d+)?$/', trim($cell))) {
                return false;
            }
        }

        return true;
    }

    private function isSummaryRow(array $row): bool
    {
        $first = trim($row[0] ?? '');

        if ($first !== '' && preg_match(self::SUMMARY_ROW_PATTERN, $first) === 1) {
            return true;
        }

        if ($first === '' || ctype_digit($first)) {
            $limit = min(5, count($row));
            for ($i = 1; $i < $limit; $i++) {
                $cell = trim((string) ($row[$i] ?? ''));
                if ($cell !== '' && ! is_numeric($cell) && preg_match(self::SUMMARY_ROW_PATTERN, mb_strtolower($cell)) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    private function detectAmountColumn(array $headers): ?int
    {
        $exactPriority = ['amount', 'total_amount', 'net_amount', 'gross_amount', 'payment_amount', 'invoice_amount', 'subtotal', 'المبلغ'];
        foreach ($exactPriority as $keyword) {
            foreach ($headers as $idx => $header) {
                if (mb_strtolower($header) === $keyword) {
                    return $idx;
                }
            }
        }

        foreach (self::AMOUNT_KEYWORDS as $keyword) {
            foreach ($headers as $idx => $header) {
                if (mb_stripos($header, $keyword) !== false && ! $this->isLikelyIdColumn($header)) {
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
            foreach ($headers as $idx => $header) {
                if (mb_strtolower($header) === $kw) {
                    return $idx;
                }
            }
        }

        foreach ($headers as $idx => $header) {
            $lower = mb_strtolower($header);
            if (mb_stripos($lower, 'status') !== false || mb_stripos($lower, 'حالة') !== false) {
                return $idx;
            }
        }

        return null;
    }

    private function detectDateColumns(array $headers): array
    {
        $dateKeywords = ['date', 'day', 'time', 'created', 'updated', 'period', 'month', 'year', 'تاريخ', 'يوم', 'وقت', 'شهر', 'سنة'];
        $indices      = [];
        foreach ($headers as $idx => $header) {
            foreach ($dateKeywords as $kw) {
                if (mb_stripos($header, $kw) !== false) {
                    $indices[] = $idx;
                    break;
                }
            }
        }

        return $indices;
    }

    private function detectCurrencyColumn(array $headers): ?int
    {
        foreach ($headers as $idx => $header) {
            if (mb_stripos($header, 'currency') !== false || mb_stripos($header, 'عملة') !== false) {
                return $idx;
            }
        }

        return null;
    }

    private function fallbackAmountColumn(array $numericCols): ?string
    {
        $best = null; $bestSum = -1;
        foreach ($numericCols as $col => $stats) {
            if ($this->isLikelyIdColumn(mb_strtolower($col))) { continue; }
            if ($stats['sum'] > $bestSum) { $bestSum = $stats['sum']; $best = $col; }
        }

        return $best;
    }

    private function isLikelyIdColumn(string $header): bool
    {
        if (in_array($header, ['id', 'no', 'num', 'number', 'code', 'ref', 'serial', 'key', 'seq', '#'], true)) {
            return true;
        }
        foreach (['_id', '_no', '_num', '_number', '_code', '_ref', '_serial', '_key', '_seq', ' id', ' no', ' number', ' code'] as $s) {
            if (str_ends_with($header, $s)) {
                return true;
            }
        }

        return false;
    }

    private function stripInlineCurrency(string $raw): array
    {
        $currency = null;
        foreach (self::CURRENCY_CODES as $code) {
            if (str_starts_with(strtoupper($raw), $code)) {
                $currency = $code; $raw = trim(substr($raw, strlen($code))); break;
            }
        }
        if ($currency === null && preg_match('/^ر\.?س\.?\s*/u', $raw, $m)) {
            $currency = 'SAR'; $raw = trim(substr($raw, strlen($m[0])));
        }
        if ($currency === null) {
            foreach (self::CURRENCY_CODES as $code) {
                if (str_ends_with(strtoupper(trim($raw)), $code)) {
                    $currency = $code; $raw = trim(substr($raw, 0, -strlen($code))); break;
                }
            }
        }

        return ['value' => trim($raw), 'currency' => $currency];
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

    private function parseDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        foreach (['Y-m-d', 'Y/m/d', 'd/m/Y', 'm/d/Y', 'd-m-Y', 'm-d-Y', 'Y-m-d H:i:s', 'd/m/Y H:i:s', 'm/d/Y H:i:s'] as $format) {
            $dt = \DateTime::createFromFormat($format, $value);
            if ($dt && $dt->format('Y') >= '1900' && $dt->format('Y') <= '2100') {
                return $dt->format('Y-m-d');
            }
        }

        return null;
    }

    private function normalizeArabicDigits(string $value): string
    {
        return strtr($value, ['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
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

    private function skipBom($handle): void
    {
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }
    }

    private function validateStatusData(array $statusData, int $totalRows, array $numericSums, ?int $amountColIdx, string $fileName): void
    {
        if (empty($statusData)) {
            return;
        }

        $statusCountSum = (int) array_sum(array_column($statusData, 'count'));
        if ($statusCountSum !== $totalRows) {
            Log::warning("DocumentProfiler [{$fileName}]: status count mismatch", [
                'status_sum' => $statusCountSum, 'total_rows' => $totalRows,
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
                    'diff_pct'          => round($diffPct, 4),
                ]);
            }
        }
    }

    private function emptyStats(): DocumentStats
    {
        return new DocumentStats(
            totalRows: 0, totalColumns: 0, hasHeaderRow: false,
            columnNames: [], columnTypes: [], numericColumns: [], dateColumns: [],
            currency: null, primaryAmountColumn: null, detectedStatusColumn: null, statusBreakdown: null,
        );
    }
}
