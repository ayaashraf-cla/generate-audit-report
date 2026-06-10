<?php

namespace Cla\GenerateAuditReport\Services\Audit;

class DocumentStatsExtractor
{
    public function extract(array $chunks): array
    {
        $text = collect($chunks)
            ->pluck('content')
            ->filter()
            ->implode("\n");

        if (trim($text) === '') {
            return [];
        }

        $stats = [];

        if ($records = $this->extractInteger($text, [
            'Total Transactions',
            'Total Transaction',
            'Transaction Count',
            'Total Records',
            'Record Count',
            'عدد المعاملات',
            'إجمالي المعاملات',
        ])) {
            $stats['total_records'] = $records;
        }

        if ($amount = $this->extractMoney($text, [
            'Total Amount',
            'Gross Amount',
            'Gross Revenue',
            'إجمالي المبلغ',
            'إجمالي الإيرادات',
        ])) {
            $stats['total_amount'] = $amount['value'];
            $stats['currency'] = $amount['currency'] ?? ($stats['currency'] ?? null);
        }

        if ($net = $this->extractMoney($text, [
            'Net Amount',
            'Net Revenue',
            'Settled Amount',
            'Settlement Amount',
            'صافي المبلغ',
            'صافي الإيرادات',
        ])) {
            $stats['net_amount'] = $net['value'];
            $stats['currency'] = $net['currency'] ?? ($stats['currency'] ?? null);
        }

        // Fallback: when no labeled total row exists, sum the amount column from
        // structured (CSV/TSV) row data so the AI never has to self-compute totals
        // from a potentially truncated context window.
        if (! isset($stats['total_amount'])) {
            $rowSums = $this->sumStructuredAmountColumn($chunks);
            if ($rowSums !== null) {
                $stats['total_amount'] = $rowSums['total_amount'];
                if (! isset($stats['currency']) && isset($rowSums['currency'])) {
                    $stats['currency'] = $rowSums['currency'];
                }
            }
        }

        if (
            isset($stats['total_amount'], $stats['total_records']) &&
            is_numeric($stats['total_amount']) &&
            (int) $stats['total_records'] > 0
        ) {
            $stats['average_amount'] = $this->formatDecimal(
                (float) $stats['total_amount'] / (int) $stats['total_records'],
                4
            );
        }

        return array_filter($stats, fn ($value) => $value !== null && $value !== '');
    }

    public function countDataRows(array $chunks): ?int
    {
        $dataCount    = 0;
        $skippedLines = [];

        foreach ($chunks as $chunk) {
            $content = trim($chunk['content'] ?? '');
            if ($content === '') {
                continue;
            }

            foreach (preg_split('/\r\n|\r|\n/', $content) as $rawLine) {
                $line = trim($rawLine);
                if ($line === '') {
                    continue;
                }

                if (isset($skippedLines[$line])) {
                    continue;
                }

                if (preg_match('/^(total|grand\s*total|summary|subtotal|إجمالي|المجموع|مجموع)/iu', $line)) {
                    $skippedLines[$line] = true;
                    continue;
                }

                if (!preg_match('/\d/', $line)) {
                    $skippedLines[$line] = true;
                    continue;
                }

                if (! $this->looksLikeStructuredDataRow($line)) {
                    continue;
                }

                $skippedLines[$line] = true;
                $dataCount++;
            }
        }

        return $dataCount > 0 ? $dataCount : null;
    }

    private function sumStructuredAmountColumn(array $chunks): ?array
    {
        $allLines  = [];
        $seenLines = [];

        foreach ($chunks as $chunk) {
            foreach (preg_split('/\r\n|\r|\n/', trim($chunk['content'] ?? '')) as $rawLine) {
                $line = trim($rawLine);
                if ($line !== '' && ! isset($seenLines[$line])) {
                    $seenLines[$line] = true;
                    $allLines[]       = $line;
                }
            }
        }

        if (count($allLines) < 2) {
            return null;
        }

        $delimiter = null;
        foreach ([',', "\t", ';', '|'] as $d) {
            if (substr_count($allLines[0], $d) >= 1) {
                $delimiter = $d;
                break;
            }
        }

        if ($delimiter === null) {
            return null;
        }

        $headerIdx = null;
        $headers   = [];
        foreach ($allLines as $idx => $line) {
            if (! preg_match('/\d/', $line)) {
                $cells = array_map('trim', str_getcsv($line, $delimiter));
                if (count(array_filter($cells)) >= 2) {
                    $headerIdx = $idx;
                    $headers   = array_map('mb_strtolower', $cells);
                    break;
                }
            }
        }

        if ($headerIdx === null || empty($headers)) {
            return null;
        }

        $amountKeywords = ['amount', 'revenue', 'price', 'المبلغ', 'القيمة', 'الإيراد', 'السعر'];
        $amountColIdx   = null;
        foreach ($headers as $idx => $header) {
            foreach ($amountKeywords as $keyword) {
                if (mb_stripos($header, $keyword) !== false) {
                    $amountColIdx = $idx;
                    break 2;
                }
            }
        }

        if ($amountColIdx === null) {
            return null;
        }

        $total     = 0.0;
        $hasValues = false;

        for ($i = $headerIdx + 1, $max = count($allLines); $i < $max; $i++) {
            $line = $allLines[$i];

            if (preg_match('/^(total|grand\s*total|summary|subtotal|إجمالي|المجموع|مجموع)/iu', $line)) {
                continue;
            }

            $cells = array_map('trim', str_getcsv($line, $delimiter));

            if (! isset($cells[$amountColIdx]) || trim($cells[$amountColIdx]) === '') {
                continue;
            }

            $raw   = preg_replace('/[A-Z]{3}|ر\.?س\.?/u', '', $cells[$amountColIdx]);
            $value = $this->normalizeDecimal(trim($raw));

            if ($value !== null && is_numeric($value) && (float) $value >= 0) {
                $total     += (float) $value;
                $hasValues  = true;
            }
        }

        return $hasValues ? ['total_amount' => $this->formatDecimal($total, 4)] : null;
    }

    private function looksLikeStructuredDataRow(string $line): bool
    {
        if (preg_match('/[,|\t;]/', $line)) {
            $cells       = preg_split('/[,|\t;]/', $line);
            $filledCells = array_filter(array_map('trim', $cells), fn ($cell) => $cell !== '');

            return count($filledCells) >= 2;
        }

        return preg_match('/\S+\s{2,}\S+/', $line) === 1;
    }

    private function extractInteger(string $text, array $labels): ?int
    {
        foreach ($labels as $label) {
            $pattern = '/(?:^|[\r\n])\s*' . preg_quote($label, '/') . '\s*[:：\-]?\s*([0-9٠-٩٬,.\s]+)/iu';

            if (preg_match($pattern, $text, $matches)) {
                $number = preg_replace('/[^\d٠-٩]/u', '', $this->normalizeArabicDigits($matches[1]));

                return $number !== '' ? (int) $number : null;
            }
        }

        return null;
    }

    private function extractMoney(string $text, array $labels): ?array
    {
        foreach ($labels as $label) {
            $pattern = '/(?:^|[\r\n])\s*' . preg_quote($label, '/') . '\s*[:：\-]?\s*(SAR|USD|AED|ر\.?س\.?)?\s*([-+]?[0-9٠-٩][0-9٠-٩٬٫,.\'\s]*)/iu';

            if (preg_match($pattern, $text, $matches)) {
                $value = $this->normalizeDecimal($matches[2]);

                if ($value === null) {
                    continue;
                }

                return [
                    'value'    => $value,
                    'currency' => $this->normalizeCurrency($matches[1] ?? null),
                ];
            }
        }

        return null;
    }

    private function normalizeDecimal(string $value): ?string
    {
        $value = $this->normalizeArabicDigits($value);
        $value = trim($value);
        $value = str_replace(["\u{00A0}", ' ', "\t", "\r", "\n", "'", '٬'], '', $value);
        $value = str_replace('٫', '.', $value);

        $lastDot   = strrpos($value, '.');
        $lastComma = strrpos($value, ',');

        if ($lastDot !== false && $lastComma !== false) {
            $decimalSeparator   = $lastDot > $lastComma ? '.' : ',';
            $thousandsSeparator = $decimalSeparator === '.' ? ',' : '.';
            $value              = str_replace($thousandsSeparator, '', $value);
            $value              = str_replace($decimalSeparator, '.', $value);
        } elseif ($lastComma !== false) {
            $value = $this->singleSeparatorDecimal($value, ',');
        } elseif ($lastDot !== false) {
            $value = $this->singleSeparatorDecimal($value, '.');
        }

        $value = preg_replace('/[^0-9.\-+]/', '', $value);

        return is_numeric($value) ? $value : null;
    }

    private function singleSeparatorDecimal(string $value, string $separator): string
    {
        $position = strrpos($value, $separator);
        $decimals = strlen($value) - $position - 1;

        if ($decimals > 0 && $decimals <= 4) {
            return str_replace($separator, '.', $value);
        }

        return str_replace($separator, '', $value);
    }

    private function normalizeArabicDigits(string $value): string
    {
        return strtr($value, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }

    private function normalizeCurrency(?string $currency): ?string
    {
        if (! $currency) {
            return null;
        }

        return preg_match('/ر|SAR/i', $currency) ? 'SAR' : strtoupper($currency);
    }

    private function formatDecimal(float $value, int $precision): string
    {
        return rtrim(rtrim(number_format($value, $precision, '.', ''), '0'), '.');
    }
}
