<?php

namespace Cla\GenerateAuditReport\Services\Document\Profile;

readonly class DocumentStats
{
    /**
     * @param  array<string,string>                                          $columnTypes
     * @param  array<string,array{sum:float,min:float,max:float,non_null_count:int,null_count:int}>  $numericColumns
     * @param  array<string,array{min:string,max:string,non_null_count:int,null_count:int}>          $dateColumns
     * @param  string[]                                                      $columnNames
     */
    public function __construct(
        public int     $totalRows,
        public int     $totalColumns,
        public bool    $hasHeaderRow,
        public array   $columnNames,
        public array   $columnTypes,
        public array   $numericColumns,
        public array   $dateColumns,
        public ?string $currency,
        public ?string $primaryAmountColumn,
        public ?string $sheetName = null,
        public ?string $detectedStatusColumn = null,
        public ?array  $statusBreakdown = null,
    ) {}

    public function toFlatStats(): array
    {
        $stats = [
            'total_records'          => $this->totalRows,
            'currency'               => $this->currency,
            'detected_amount_column' => $this->primaryAmountColumn,
            'detected_status_column' => $this->detectedStatusColumn,
        ];

        if ($this->primaryAmountColumn && isset($this->numericColumns[$this->primaryAmountColumn])) {
            $col   = $this->numericColumns[$this->primaryAmountColumn];
            $total = round((float) $col['sum'], 2);
            $avg   = $this->totalRows > 0 ? round((float) $col['sum'] / $this->totalRows, 2) : null;
            $min   = isset($col['min']) && $col['min'] !== null ? round((float) $col['min'], 2) : null;
            $max   = isset($col['max']) && $col['max'] !== null ? round((float) $col['max'], 2) : null;

            $stats['total_amount']           = $total;
            $stats['total_amount_display']   = self::formatCompact($total);
            $stats['average_amount']         = $avg;
            $stats['average_amount_display'] = $avg !== null ? self::formatCompact($avg) : null;
            $stats['min_amount']             = $min;
            $stats['min_amount_display']     = $min !== null ? self::formatCompact($min) : null;
            $stats['max_amount']             = $max;
            $stats['max_amount_display']     = $max !== null ? self::formatCompact($max) : null;
        }

        $netKeywords = ['net', 'settled', 'settlement', 'صافي'];
        foreach ($this->numericColumns as $colName => $colStats) {
            if ($colName === $this->primaryAmountColumn) {
                continue;
            }
            foreach ($netKeywords as $kw) {
                if (mb_stripos($colName, $kw) !== false) {
                    $net = round((float) $colStats['sum'], 2);
                    $stats['net_amount']         = $net;
                    $stats['net_amount_display'] = self::formatCompact($net);
                    break 2;
                }
            }
        }

        foreach ($this->dateColumns as $colStats) {
            if (! empty($colStats['min'])) {
                $stats['date_from'] = $colStats['min'];
                $stats['date_to']   = $colStats['max'];
                break;
            }
        }

        if (! empty($this->statusBreakdown)) {
            $enriched = [];
            foreach ($this->statusBreakdown as $statusVal => $data) {
                $enriched[$statusVal] = array_merge((array) $data, [
                    'amount_display'  => self::formatCompact((float) ($data['amount'] ?? 0)),
                    'average_display' => self::formatCompact((float) ($data['average'] ?? 0)),
                ]);
            }
            $stats['status_breakdown'] = $enriched;
        }

        return array_filter($stats, fn ($v) => $v !== null && $v !== '');
    }

    public static function formatCompact(float $value): string
    {
        if ($value == 0.0) {
            return '0';
        }

        $abs  = abs($value);
        $sign = $value < 0 ? '-' : '';

        if ($abs >= 1_000_000_000) {
            $num = $abs / 1_000_000_000; $suffix = 'B';
        } elseif ($abs >= 1_000_000) {
            $num = $abs / 1_000_000; $suffix = 'M';
        } elseif ($abs >= 1_000) {
            $num = $abs / 1_000; $suffix = 'K';
        } else {
            return $sign . rtrim(rtrim(number_format($abs, 2), '0'), '.');
        }

        return $sign . rtrim(rtrim(number_format($num, 2), '0'), '.') . $suffix;
    }

    public function toArray(): array
    {
        return [
            'total_rows'             => $this->totalRows,
            'total_columns'          => $this->totalColumns,
            'has_header_row'         => $this->hasHeaderRow,
            'column_names'           => $this->columnNames,
            'column_types'           => $this->columnTypes,
            'numeric_columns'        => $this->numericColumns,
            'date_columns'           => $this->dateColumns,
            'currency'               => $this->currency,
            'primary_amount_column'  => $this->primaryAmountColumn,
            'sheet_name'             => $this->sheetName,
            'detected_status_column' => $this->detectedStatusColumn,
            'status_breakdown'       => $this->statusBreakdown,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            totalRows:            (int) ($data['total_rows'] ?? 0),
            totalColumns:         (int) ($data['total_columns'] ?? 0),
            hasHeaderRow:         (bool) ($data['has_header_row'] ?? false),
            columnNames:          (array) ($data['column_names'] ?? []),
            columnTypes:          (array) ($data['column_types'] ?? []),
            numericColumns:       (array) ($data['numeric_columns'] ?? []),
            dateColumns:          (array) ($data['date_columns'] ?? []),
            currency:             $data['currency'] ?? null,
            primaryAmountColumn:  $data['primary_amount_column'] ?? null,
            sheetName:            $data['sheet_name'] ?? null,
            detectedStatusColumn: $data['detected_status_column'] ?? null,
            statusBreakdown:      isset($data['status_breakdown']) ? (array) $data['status_breakdown'] : null,
        );
    }
}
