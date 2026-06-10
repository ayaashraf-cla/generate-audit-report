<?php

namespace Cla\GenerateAuditReport\Services\Document\Summarizers;

use Cla\GenerateAuditReport\Services\Document\Profile\DocumentProfile;
use Cla\GenerateAuditReport\Services\Document\Profile\DocumentStats;
use Cla\GenerateAuditReport\Services\Document\Summarizers\Contracts\FileSummarizerInterface;
use Laravel\Ai\AnonymousAgent;

class StructuredFileSummarizer implements FileSummarizerInterface
{
    private string $provider;
    private string $model;

    public function __construct()
    {
        $this->provider = config('audit.chat.provider', 'gemini');
        $this->model    = config('audit.chat.model', 'gemini-2.5-flash');
    }

    public function supports(DocumentProfile $profile): bool
    {
        return $profile->isStructured() && $profile->stats !== null;
    }

    public function summarize(DocumentProfile $profile): array
    {
        $stats  = $profile->stats;
        $schema = json_encode([
            'en' => 'string — 3-5 paragraph English description covering amounts, date coverage, missing data, distribution patterns, and variation',
            'ar' => 'string — 3-5 paragraph Arabic description of the same document covering the same topics',
        ]);

        $statsBlock = $this->formatStatsBlock($profile->fileName, $profile->extension, $stats);

        $prompt = <<<PROMPT
        You are a document analyst writing a formal factual description.

        YOUR ONLY JOB: Write natural prose that accurately describes this document using the
        statistics provided below. Do NOT invent, round, estimate, or modify any number.
        Copy figures verbatim from the statistics block.

        Rules:
        - Use ONLY the statistics provided.
        - Mention the document type, total row count, column list, amounts, and date range naturally in prose.
        - If a Status Breakdown is provided, describe each status category, its record count, and its share.
        - Describe missing data observations: mention any columns with notable null/missing counts and their percentage of total records. If no missing data is reported, state the dataset is complete.
        - Describe date coverage: mention all date columns, their full ranges, and the overall time span covered.
        - Describe distribution patterns: for each numeric column, note its minimum, maximum, and average value.
        - Note any columns flagged as high-variation or low-variation and what that implies about data spread.
        - 3–5 paragraphs per language.
        - No audit opinions, compliance conclusions, or recommendations.
        {$statsBlock}

        Respond ONLY with a valid JSON object matching this schema exactly:
        {$schema}
        PROMPT;

        try {
            $response = AnonymousAgent::make(
                instructions: 'You are a precise document analyst. Always respond with a valid JSON object only.',
                messages: [],
                tools: [],
            )->prompt($prompt, provider: $this->provider, model: $this->model, timeout: 60);

            $text    = $this->stripFences($response->text);
            $decoded = json_decode($text, true);

            if (json_last_error() !== JSON_ERROR_NONE || ! isset($decoded['en'], $decoded['ar'])) {
                return ['en' => null, 'ar' => null];
            }

            return [
                'en' => filled($decoded['en']) ? (string) $decoded['en'] : null,
                'ar' => filled($decoded['ar']) ? (string) $decoded['ar'] : null,
            ];
        } catch (\Throwable) {
            return ['en' => null, 'ar' => null];
        }
    }

    private function formatStatsBlock(string $fileName, string $extension, DocumentStats $stats): string
    {
        $flat         = $stats->toFlatStats();
        $currency     = $stats->currency ?? '';
        $columns      = implode(', ', $stats->columnNames);
        $docTypeLabel = strtoupper($extension) . ' file';
        $rows         = number_format($stats->totalRows);

        $lines = [
            "PRE-COMPUTED STATISTICS (authoritative — copy figures verbatim, never recalculate):",
            "  File name         : {$fileName}",
            "  Document type     : {$docTypeLabel}",
            "  Total data rows   : {$rows}",
            "  Total columns     : {$stats->totalColumns}",
            "  Column names      : {$columns}",
        ];

        if (isset($flat['total_amount'])) {
            $display = $flat['total_amount_display'] ?? (string) $flat['total_amount'];
            $lines[] = "  Total amount      : {$display} {$currency}";
        }

        if (isset($flat['average_amount'])) {
            $display = $flat['average_amount_display'] ?? (string) $flat['average_amount'];
            $lines[] = "  Average per row   : {$display} {$currency}";
        }

        if (isset($flat['net_amount'])) {
            $display = $flat['net_amount_display'] ?? (string) $flat['net_amount'];
            $lines[] = "  Net amount        : {$display} {$currency}";
        }

        if (! empty($stats->dateColumns)) {
            $lines[] = "  Date coverage:";
            foreach ($stats->dateColumns as $colName => $colStats) {
                if (! empty($colStats['min'])) {
                    $lines[] = "    - {$colName}: {$colStats['min']} to {$colStats['max']}";
                }
            }
        }

        if (! empty($stats->numericColumns)) {
            $lines[] = "  Numeric column distribution:";
            foreach ($stats->numericColumns as $colName => $colStats) {
                $nonNull = (int) ($colStats['non_null_count'] ?? 0);
                $minFmt  = isset($colStats['min']) ? DocumentStats::formatCompact((float) $colStats['min']) : 'N/A';
                $maxFmt  = isset($colStats['max']) ? DocumentStats::formatCompact((float) $colStats['max']) : 'N/A';
                $avgFmt  = ($nonNull > 0 && isset($colStats['sum']))
                    ? DocumentStats::formatCompact((float) $colStats['sum'] / $nonNull)
                    : 'N/A';
                $suffix  = ($currency && $colName === $stats->primaryAmountColumn) ? " {$currency}" : '';
                $lines[] = "    - {$colName}: min={$minFmt}{$suffix}, max={$maxFmt}{$suffix}, avg={$avgFmt}{$suffix}";
            }
        }

        if ($stats->primaryAmountColumn) {
            if (isset($flat['min_amount_display'])) {
                $lines[] = "  Min row amount    : {$flat['min_amount_display']} {$currency}";
            }
            if (isset($flat['max_amount_display'])) {
                $lines[] = "  Max row amount    : {$flat['max_amount_display']} {$currency}";
            }
            $lines[] = "  Amount column     : {$stats->primaryAmountColumn}";
        }

        if ($currency) {
            $lines[] = "  Currency          : {$currency}";
        }

        $missingLines = [];
        foreach ($stats->numericColumns as $colName => $colStats) {
            $nullCount = (int) ($colStats['null_count'] ?? 0);
            if ($nullCount > 0) {
                $pct           = $stats->totalRows > 0 ? number_format($nullCount / $stats->totalRows * 100, 1) : '0';
                $missingLines[] = "    - {$colName}: {$nullCount} missing ({$pct}%)";
            }
        }
        foreach ($stats->dateColumns as $colName => $colStats) {
            $nullCount = (int) ($colStats['null_count'] ?? 0);
            if ($nullCount > 0) {
                $pct           = $stats->totalRows > 0 ? number_format($nullCount / $stats->totalRows * 100, 1) : '0';
                $missingLines[] = "    - {$colName}: {$nullCount} missing ({$pct}%)";
            }
        }
        if (! empty($missingLines)) {
            $lines[] = "  Missing data:";
            array_push($lines, ...$missingLines);
        } else {
            $lines[] = "  Missing data      : none detected";
        }

        $highVariation = []; $lowVariation = [];
        foreach ($stats->numericColumns as $colName => $colStats) {
            $nonNull = (int) ($colStats['non_null_count'] ?? 0);
            if ($nonNull > 0 && isset($colStats['sum'], $colStats['min'], $colStats['max'])) {
                $mean  = (float) $colStats['sum'] / $nonNull;
                $range = (float) $colStats['max'] - (float) $colStats['min'];
                if ($mean != 0) {
                    $cv = $range / abs($mean);
                    if ($cv > 5) { $highVariation[] = $colName; }
                    elseif ($cv < 0.05) { $lowVariation[] = $colName; }
                }
            }
        }
        if (! empty($highVariation)) { $lines[] = "  High-variation columns : " . implode(', ', $highVariation); }
        if (! empty($lowVariation))  { $lines[] = "  Low-variation columns  : " . implode(', ', $lowVariation); }

        if (! empty($stats->statusBreakdown) && $stats->detectedStatusColumn) {
            $flatBreakdown = $flat['status_breakdown'] ?? [];
            $lines[] = "  Status column     : {$stats->detectedStatusColumn}";
            $lines[] = "  Status Breakdown  :";
            foreach ($flatBreakdown as $statusVal => $data) {
                $amtDisplay = $data['amount_display'] ?? number_format((float) ($data['amount'] ?? 0), 2);
                $avgDisplay = $data['average_display'] ?? number_format((float) ($data['average'] ?? 0), 2);
                $pct        = number_format((float) ($data['percentage'] ?? 0), 2);
                $lines[]    = "    - {$statusVal}: {$data['count']} records ({$pct}%), total {$amtDisplay} {$currency}, avg {$avgDisplay} {$currency}";
            }
        }

        return implode("\n", $lines);
    }

    private function stripFences(string $text): string
    {
        $text = preg_replace('/^```(?:json)?\s*/m', '', $text);
        $text = preg_replace('/\s*```$/m', '', $text);

        return trim($text);
    }
}
