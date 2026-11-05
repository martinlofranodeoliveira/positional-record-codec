<?php

declare(strict_types=1);

namespace Lofrano\PositionalCodec;

final readonly class BatchResult
{
    /**
     * @param array<string, int|null> $accumulatedTotals Source field names to sums; null means unavailable.
     * @param array<string, int|null> $declaredTrailerTotals Actual trailer control field names to values.
     * @param list<Diagnostic> $diagnostics
     * @param ?array<string, int|string> $header
     * @param list<array<string, int|string>> $details
     * @param ?array<string, int|string> $trailer
     */
    public function __construct(
        public bool $valid,
        public string $schemaName,
        public EncodingMode $encodingMode,
        public int $lineLength,
        public int $totalLines,
        public int $headerCount,
        public int $detailCount,
        public int $trailerCount,
        public array $accumulatedTotals,
        public array $declaredTrailerTotals,
        public array $diagnostics,
        public ?array $header = null,
        public array $details = [],
        public ?array $trailer = null,
        public string $lineEnding = "\n",
        public bool $hasTrailingNewline = false,
    ) {
    }

    public function toAuditSummaryArray(): array
    {
        return [
            'valid' => $this->valid,
            'schema_name' => $this->schemaName,
            'encoding_mode' => $this->encodingMode->value,
            'line_length' => $this->lineLength,
            'total_lines' => $this->totalLines,
            'header_count' => $this->headerCount,
            'detail_count' => $this->detailCount,
            'trailer_count' => $this->trailerCount,
            'accumulated_totals' => (object) $this->accumulatedTotals,
            'declared_trailer_totals' => (object) $this->declaredTrailerTotals,
            'diagnostics' => array_map(static fn (Diagnostic $d): array => $d->toArray(), $this->diagnostics),
        ];
    }

    public function toAuditSummaryJson(bool $pretty = true): string
    {
        return json_encode($this->toAuditSummaryArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE | ($pretty ? JSON_PRETTY_PRINT : 0));
    }
}
