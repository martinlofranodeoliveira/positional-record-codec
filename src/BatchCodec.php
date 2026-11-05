<?php

declare(strict_types=1);

namespace Lofrano\PositionalCodec;

final readonly class BatchCodec
{
    private ResourceLimits $limits;

    public function __construct(public LayoutSchema $schema, ?ResourceLimits $limits = null)
    {
        $this->limits = $limits ?? new ResourceLimits();
        if ($schema->lineLength > $this->limits->maxLineLength) {
            throw CodecException::schema('schema_invalid_line_length', 'Layout exceeds codec column limit.');
        }
        foreach ($schema->recordFields as $fields) {
            if (count($fields) > $this->limits->maxFieldsPerRecord) {
                throw CodecException::schema('schema_invalid_fields_list', 'Layout exceeds codec field limit.');
            }
        }
    }

    private function problem(string $code, int $line, string $role = 'batch', ?FieldDefinition $field = null, ?int $start = null, ?int $end = null): Diagnostic
    {
        return new Diagnostic($line, $role, $field?->name, $field?->startCol ?? $start, $field?->endCol ?? $end,
            $code, 'Structural validation failed; inspect error_code.');
    }

    private function fail(string $code, int $line, string $role = 'batch', ?FieldDefinition $field = null): never
    {
        $d = $this->problem($code, $line, $role, $field);
        throw new CodecException($code, $d->message, $d);
    }

    /** @return array<string, int|null> */
    private function emptySums(): array
    {
        $sums = [];
        foreach ($this->schema->sumControls as $control) {
            $sums[$control['detail_field']] = 0;
        }
        return $sums;
    }

    public function auditText(string $content): BatchResult
    {
        return $this->parseInternal($content, false);
    }

    public function parseText(string $content): BatchResult
    {
        return $this->parseInternal($content, true);
    }

    public function formatRecord(string $role, array $record, int $lineNumber = 1): string
    {
        if (!isset($this->schema->recordFields[$role])) {
            $this->fail('unknown_record_role', max(1, $lineNumber), 'unknown');
        }
        if ($lineNumber < 1) {
            $this->fail('invalid_line_number', 1, $role);
        }
        foreach (array_keys($record) as $key) {
            if (!is_string($key) || !isset($this->schema->recordFieldsByName[$role][$key])) {
                $this->fail('unknown_field_name', $lineNumber, $role);
            }
        }
        $output = '';
        foreach ($this->schema->recordFields[$role] as $field) {
            $segment = $this->serializeField($role, $field, $record[$field->name] ?? null, $lineNumber);
            if (strlen($output) + strlen($segment) > $this->limits->maxLineBytes) {
                $this->fail('line_byte_limit_exceeded', $lineNumber, $role, $field);
            }
            $output .= $segment;
        }
        return $output;
    }

    public function formatBatch(array $header, array $details, ?array $trailer = null, string $lineEnding = "\n", bool $trailingNewline = false): string
    {
        if ($lineEnding !== "\n" && $lineEnding !== "\r\n") {
            $this->fail('invalid_line_ending', 1);
        }
        if (!array_is_list($details)) {
            $this->fail('invalid_details_list', 1);
        }
        $count = count($details);
        if ($count < $this->limits->minDetailRecords) {
            $this->fail('missing_detail_records', 1);
        }
        if ($count > $this->limits->maxLines - 2) {
            $this->fail('max_lines_exceeded', $this->limits->maxLines + 1);
        }
        $output = '';
        $written = 0;
        $append = function (string $line, string $role) use (&$output, &$written, $lineEnding): void {
            $prefix = $written === 0 ? '' : $lineEnding;
            $bytes = strlen($prefix) + strlen($line);
            if ($bytes > $this->limits->maxBatchBytes || strlen($output) > $this->limits->maxBatchBytes - $bytes) {
                $this->fail('batch_size_limit_exceeded', $written + 1, $role);
            }
            $output .= $prefix . $line;
            $written++;
        };
        $append($this->formatRecord('header', $header, 1), 'header');
        $sums = $this->emptySums();
        foreach ($details as $index => $record) {
            $line = $index + 2;
            if (!is_array($record)) {
                $this->fail('invalid_record_type', $line, 'detail');
            }
            $append($this->formatRecord('detail', $record, $line), 'detail');
            // Each source field is accumulated once, even when controls fan out.
            foreach ($sums as $name => $sum) {
                $field = $this->schema->recordFieldsByName['detail'][$name];
                $minor = $this->minorUnits($record[$name] ?? null, $field, $line, 'detail');
                $next = SafeMath::checkedAdd($sum, $minor);
                if ($next === null) {
                    $this->fail('control_sum_overflow', $line, 'detail', $field);
                }
                $sums[$name] = $next;
            }
        }
        $line = $count + 2;
        $data = $trailer ?? [];
        $name = $this->schema->detailCountField;
        $field = $this->schema->recordFieldsByName['trailer'][$name];
        if (isset($data[$name])) {
            $value = $data[$name];
            if (!is_int($value) && !is_string($value)) {
                $this->fail('invalid_field_type', $line, 'trailer', $field);
            }
            $declared = is_int($value) ? $value : SafeMath::parseUnsignedInt($value);
            if ($declared === null || $declared < 0) {
                $this->fail('invalid_digits_format', $line, 'trailer', $field);
            }
            if ($declared !== $count) {
                $this->fail('trailer_count_mismatch', $line, 'trailer', $field);
            }
        } else {
            $data[$name] = $count;
        }
        foreach ($this->schema->sumControls as $control) {
            $name = $control['trailer_field'];
            $field = $this->schema->recordFieldsByName['trailer'][$name];
            $expected = $sums[$control['detail_field']];
            if (isset($data[$name])) {
                if ($this->minorUnits($data[$name], $field, $line, 'trailer') !== $expected) {
                    $this->fail('trailer_sum_mismatch', $line, 'trailer', $field);
                }
            } else {
                $data[$name] = $expected;
            }
        }
        if ($trailer === null) {
            foreach ($this->schema->recordFields['trailer'] as $field) {
                if ($field->type === FieldType::Alpha) {
                    $data[$field->name] ??= '';
                }
            }
        }
        $append($this->formatRecord('trailer', $data, $line), 'trailer');
        if ($trailingNewline) {
            if (strlen($output) > $this->limits->maxBatchBytes - strlen($lineEnding)) {
                $this->fail('batch_size_limit_exceeded', $line);
            }
            $output .= $lineEnding;
        }
        return $output;
    }

    private function parseInternal(string $content, bool $retainRecords): BatchResult
    {
        $diagnostics = [];
        $halt = false;
        $sums = $this->emptySums();
        $declared = [$this->schema->detailCountField => null];
        foreach ($this->schema->sumControls as $control) {
            $declared[$control['trailer_field']] = null;
        }
        $add = function (Diagnostic $d) use (&$diagnostics, &$halt): void {
            if (count($diagnostics) === $this->limits->maxDiagnostics) {
                $diagnostics[$this->limits->maxDiagnostics - 1] = $this->problem('diagnostic_limit_exceeded', $d->lineNumber);
                $halt = true;
            } else {
                $diagnostics[] = $d;
            }
        };
        $invalidate = static function () use (&$sums): void {
            foreach ($sums as $name => $_) {
                $sums[$name] = null;
            }
        };
        $length = strlen($content);
        $total = $headers = $detailsCount = $trailers = $retained = 0;
        $header = $trailer = null;
        $details = [];
        $ending = null;
        $trailing = false;
        if ($length > $this->limits->maxBatchBytes) {
            $add($this->problem('batch_size_limit_exceeded', 1));
            $invalidate();
            $halt = true;
        } elseif ($length === 0) {
            $add($this->problem('empty_batch', 1));
        } else {
            $trailing = str_ends_with($content, "\n");
        }
        $offset = 0;
        // Scan one physical frame at a time. No batch-wide explode or Unicode split.
        while (!$halt && $offset < $length) {
            $line = $total + 1;
            if ($line > $this->limits->maxLines) {
                $add($this->problem('max_lines_exceeded', $line));
                $invalidate();
                $halt = true;
                break;
            }
            $lf = strpos($content, "\n", $offset);
            $end = $lf === false ? $length : $lf;
            $delimiter = null;
            if ($lf !== false) {
                $delimiter = "\n";
                if ($end > $offset && $content[$end - 1] === "\r") {
                    $delimiter = "\r\n";
                    $end--;
                }
                if ($ending !== null && $ending !== $delimiter) {
                    $add($this->problem('mixed_line_endings', $line));
                }
                $ending ??= $delimiter;
            }
            $bytes = $end - $offset;
            $begin = $offset;
            $offset = $lf === false ? $length : $lf + 1;
            $total++;
            if ($halt) {
                break;
            }
            if ($bytes > $this->limits->maxLineBytes) {
                $add($this->problem('line_byte_limit_exceeded', $line, 'unknown'));
                $invalidate();
                continue;
            }
            $raw = substr($content, $begin, $bytes);
            if (str_contains($raw, "\r")) {
                $add($this->problem('invalid_control_character', $line, 'unknown'));
                $invalidate();
                continue;
            }
            $columns = ColumnSlicer::splitColumns($raw, $this->schema->encodingMode, $this->limits->maxLineBytes);
            if ($columns === null) {
                $add($this->problem('invalid_encoding', $line, 'unknown'));
                $invalidate();
                continue;
            }
            if (count($columns) !== $this->schema->lineLength) {
                $add($this->problem('invalid_line_length', $line, 'unknown', null, 1, $this->schema->lineLength));
                $invalidate();
                continue;
            }
            $disc = ColumnSlicer::slice($columns, $this->schema->discriminatorStartCol, $this->schema->discriminatorEndCol);
            $role = $this->schema->discriminatorToRole[$disc] ?? null;
            if ($role === null) {
                $add($this->problem('unknown_record_discriminator', $line, 'unknown', null, $this->schema->discriminatorStartCol, $this->schema->discriminatorEndCol));
                $invalidate();
                continue;
            }
            if ($role === 'header') {
                $headers++;
                if ($line !== 1 || $headers !== 1) {
                    $add($this->problem('unexpected_header_record', $line, $role));
                }
            } else {
                if ($headers === 0) {
                    $add($this->problem('missing_header_record', $line, $role));
                }
                if ($role === 'detail') {
                    $detailsCount++;
                    if ($trailers > 0) {
                        $add($this->problem('record_after_trailer', $line, $role));
                    }
                } else {
                    $trailers++;
                    if ($trailers > 1) {
                        $add($this->problem('duplicate_trailer_record', $line, $role));
                    }
                    if ($detailsCount < $this->limits->minDetailRecords) {
                        $add($this->problem('missing_detail_records', $line, $role));
                    }
                }
            }
            [$values, $errors] = $this->parseRecordFields($role, $columns, $line);
            foreach ($errors as $error) {
                $add($error);
                if ($halt) {
                    break;
                }
            }
            if ($halt) {
                $invalidate();
                break;
            }
            if ($role === 'detail') {
                foreach ($sums as $name => $sum) {
                    if ($sum === null) {
                        continue;
                    }
                    if (!isset($values[$name])) {
                        $sums[$name] = null;
                        continue;
                    }
                    $next = SafeMath::checkedAdd($sum, $values[$name]);
                    $sums[$name] = $next;
                    if ($next === null) {
                        $add($this->problem('control_sum_overflow', $line, $role, $this->schema->recordFieldsByName[$role][$name]));
                    }
                }
            }
            if ($role === 'trailer' && $trailers === 1) {
                $name = $this->schema->detailCountField;
                if (isset($values[$name])) {
                    $count = SafeMath::parseUnsignedInt($values[$name]);
                    $declared[$name] = $count;
                    if ($count === null || $count !== $detailsCount) {
                        $add($this->problem($count === null ? 'numeric_overflow' : 'trailer_count_mismatch', $line, $role, $this->schema->recordFieldsByName[$role][$name]));
                    }
                }
                foreach ($this->schema->sumControls as $control) {
                    $name = $control['trailer_field'];
                    if (isset($values[$name])) {
                        $declared[$name] = $values[$name];
                        $sum = $sums[$control['detail_field']];
                        if ($sum !== null && $sum !== $values[$name]) {
                            $add($this->problem('trailer_sum_mismatch', $line, $role, $this->schema->recordFieldsByName[$role][$name]));
                        }
                    }
                }
            }
            if ($retainRecords && $diagnostics === []) {
                $retained += count($values);
                if ($retained > $this->limits->maxRetainedFields) {
                    $add($this->problem('retained_field_limit_exceeded', $line, $role));
                    $invalidate();
                    $halt = true;
                    break;
                }
                if ($role === 'header') {
                    $header = $values;
                } elseif ($role === 'detail') {
                    $details[] = $values;
                } else {
                    $trailer = $values;
                }
            }
            if ($diagnostics !== []) {
                $header = $trailer = null;
                $details = [];
            }
        }
        if (!$halt && $length > 0) {
            if ($headers === 0) {
                $add($this->problem('missing_header_record', 1));
            }
            if ($trailers === 0) {
                $add($this->problem('missing_trailer_record', max(1, $total)));
            }
        }
        $valid = $diagnostics === [];
        return new BatchResult(
            valid: $valid, schemaName: $this->schema->name, encodingMode: $this->schema->encodingMode,
            lineLength: $this->schema->lineLength, totalLines: $total,
            headerCount: $headers, detailCount: $detailsCount, trailerCount: $trailers,
            accumulatedTotals: $sums, declaredTrailerTotals: $declared, diagnostics: $diagnostics,
            header: $valid && $retainRecords ? $header : null,
            details: $valid && $retainRecords ? $details : [],
            trailer: $valid && $retainRecords ? $trailer : null,
            lineEnding: $ending ?? "\n", hasTrailingNewline: $trailing,
        );
    }

    private function parseRecordFields(string $role, array $columns, int $line): array
    {
        $values = $errors = [];
        foreach ($this->schema->recordFields[$role] as $field) {
            $raw = ColumnSlicer::slice($columns, $field->startCol, $field->endCol);
            $value = $raw;
            $code = null;
            switch ($field->type) {
                case FieldType::Constant:
                    $code = $raw === $field->constantValue ? null : 'constant_mismatch';
                    break;
                case FieldType::Alpha:
                    $code = ColumnSlicer::hasControlCharacters($raw) ? 'invalid_alpha_characters' : null;
                    $value = rtrim($raw, ' ');
                    break;
                case FieldType::Digits:
                    $code = preg_match('/\A[0-9]+\z/', $raw) === 1 ? null : 'invalid_digits_format';
                    break;
                case FieldType::ImpliedDecimal:
                    if (preg_match('/\A[0-9]+\z/', $raw) !== 1) {
                        $code = 'invalid_implied_decimal_format';
                    } else {
                        $value = SafeMath::parseUnsignedInt($raw);
                        $code = $value === null ? 'numeric_overflow' : null;
                    }
                    break;
                case FieldType::DateYmd:
                    $code = self::isValidDateYmd($raw) ? null : 'invalid_date_ymd';
                    break;
            }
            if ($code === null) {
                $values[$field->name] = $value;
            } else {
                $errors[] = $this->problem($code, $line, $role, $field);
            }
        }
        return [$values, $errors];
    }

    private function serializeField(string $role, FieldDefinition $field, mixed $value, int $line): string
    {
        if ($value !== null && !is_int($value) && !is_string($value)) {
            $this->fail('invalid_field_type', $line, $role, $field);
        }
        if (is_string($value)) {
            if (strlen($value) > $this->limits->maxLineBytes) {
                $this->fail('field_byte_limit_exceeded', $line, $role, $field);
            }
            if (ColumnSlicer::hasLineBreak($value)) {
                $this->fail('line_break_not_allowed', $line, $role, $field);
            }
        }
        if ($field->type === FieldType::Constant) {
            if ($value !== null && $value !== $field->constantValue) {
                $this->fail('constant_mismatch', $line, $role, $field);
            }
            return $field->constantValue;
        }
        if ($value === null) {
            $this->fail('missing_required_field', $line, $role, $field);
        }
        switch ($field->type) {
            case FieldType::Alpha:
                if (!is_string($value)) {
                    $this->fail('invalid_alpha_type', $line, $role, $field);
                }
                if (ColumnSlicer::hasControlCharacters($value)) {
                    $this->fail('invalid_alpha_characters', $line, $role, $field);
                }
                $width = ColumnSlicer::columnLength($value, $this->schema->encodingMode, $this->limits->maxLineBytes);
                if ($width === null) {
                    $this->fail('invalid_encoding', $line, $role, $field);
                }
                if ($width > $field->width) {
                    $this->fail('field_width_overflow', $line, $role, $field);
                }
                return $value . str_repeat(' ', $field->width - $width);
            case FieldType::Digits:
                if ((is_int($value) && $value < 0) || (is_string($value) && str_starts_with($value, '-'))) {
                    $this->fail('negative_value_not_allowed', $line, $role, $field);
                }
                $wire = (string) $value;
                if (preg_match('/\A[0-9]+\z/', $wire) !== 1) {
                    $this->fail('invalid_digits_format', $line, $role, $field);
                }
                break;
            case FieldType::ImpliedDecimal:
                $wire = (string) $this->minorUnits($value, $field, $line, $role);
                break;
            case FieldType::DateYmd:
                if (!is_string($value)) {
                    $this->fail('invalid_date_ymd', $line, $role, $field);
                }
                $wire = $value;
                if (preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $value, $parts) === 1) {
                    $wire = $parts[1] . $parts[2] . $parts[3];
                }
                if (!self::isValidDateYmd($wire)) {
                    $this->fail('invalid_date_ymd', $line, $role, $field);
                }
                return $wire;
            default:
                $this->fail('invalid_field_type', $line, $role, $field);
        }
        if (strlen($wire) > $field->width) {
            $this->fail('field_width_overflow', $line, $role, $field);
        }
        return str_pad($wire, $field->width, '0', STR_PAD_LEFT);
    }

    private function minorUnits(mixed $value, FieldDefinition $field, int $line, string $role): int
    {
        if ($value === null) {
            $this->fail('missing_required_field', $line, $role, $field);
        }
        if (!is_int($value) && !is_string($value)) {
            $this->fail('invalid_field_type', $line, $role, $field);
        }
        if (is_int($value)) {
            if ($value < 0) {
                $this->fail('negative_value_not_allowed', $line, $role, $field);
            }
            return $value;
        }
        if (strlen($value) > $this->limits->maxLineBytes) {
            $this->fail('field_byte_limit_exceeded', $line, $role, $field);
        }
        if (ColumnSlicer::hasLineBreak($value)) {
            $this->fail('line_break_not_allowed', $line, $role, $field);
        }
        if (str_starts_with($value, '-')) {
            $this->fail('negative_value_not_allowed', $line, $role, $field);
        }
        $minor = SafeMath::decimalStringToMinorUnits($value, $field->scale);
        if ($minor === null) {
            $this->fail('invalid_implied_decimal_format', $line, $role, $field);
        }
        return $minor;
    }

    public static function isValidDateYmd(string $raw): bool
    {
        return strlen($raw) === 8 && preg_match('/\A[0-9]{8}\z/', $raw) === 1
            && (int) substr($raw, 0, 4) >= 1
            && checkdate((int) substr($raw, 4, 2), (int) substr($raw, 6, 2), (int) substr($raw, 0, 4));
    }
}
