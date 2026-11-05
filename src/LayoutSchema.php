<?php

declare(strict_types=1);

namespace Lofrano\PositionalCodec;

use JsonException;
use stdClass;

/** Validated immutable layout. Use fromArray() or fromJsonString(). */
final readonly class LayoutSchema
{
    public array $discriminatorToRole;
    public array $recordFieldsByName;

    private function __construct(
        public string $name,
        public string $schemaVersion,
        public int $lineLength,
        public EncodingMode $encodingMode,
        public int $discriminatorStartCol,
        public int $discriminatorEndCol,
        public array $roleDiscriminators,
        public array $recordFields,
        public string $detailCountField,
        public array $sumControls,
    ) {
        $map = [];
        foreach ($roleDiscriminators as $role => $value) {
            $map[$value] = $role;
        }
        $this->discriminatorToRole = $map;
        $byName = [];
        foreach ($recordFields as $role => $fields) {
            $byName[$role] = [];
            foreach ($fields as $field) {
                $byName[$role][$field->name] = $field;
            }
        }
        $this->recordFieldsByName = $byName;
    }

    private static function reject(string $code): never
    {
        throw CodecException::schema($code, 'Layout contract rejected; inspect error_code.');
    }

    private static function keys(array $data, array $allowed): void
    {
        foreach (array_keys($data) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                self::reject('schema_unknown_property');
            }
        }
    }

    private static function object(mixed $value): array
    {
        if (!$value instanceof stdClass) {
            self::reject('schema_invalid_json_shape');
        }
        return (array) $value;
    }

    public static function fromJsonString(string $json, ?ResourceLimits $limits = null): self
    {
        $limits ??= new ResourceLimits();
        if (strlen($json) > $limits->maxSchemaBytes) {
            self::reject('schema_size_limit_exceeded');
        }
        if (!ColumnSlicer::isValidUtf8($json)) {
            self::reject('schema_invalid_utf8');
        }
        try {
            $decoded = json_decode($json, false, $limits->maxJsonDepth, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            self::reject('schema_invalid_json');
        }
        if (!$decoded instanceof stdClass) {
            self::reject('schema_root_not_object');
        }
        // Preserve object/list distinctions before converting to native schema arrays.
        $data = (array) $decoded;
        foreach (['discriminator', 'records', 'trailer_controls'] as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = self::object($data[$key]);
            }
        }
        if (isset($data['records'])) {
            foreach ($data['records'] as $role => $definition) {
                $definition = self::object($definition);
                if (array_key_exists('fields', $definition)) {
                    if (!is_array($definition['fields'])) {
                        self::reject('schema_invalid_fields_list');
                    }
                    $definition['fields'] = array_map(self::object(...), $definition['fields']);
                }
                $data['records'][$role] = $definition;
            }
        }
        if (isset($data['trailer_controls']['sum_controls'])) {
            $controls = $data['trailer_controls']['sum_controls'];
            if (!is_array($controls)) {
                self::reject('schema_invalid_sum_controls');
            }
            $data['trailer_controls']['sum_controls'] = array_map(self::object(...), $controls);
        }
        return self::fromArray($data, $limits);
    }

    public static function fromArray(array $data, ?ResourceLimits $limits = null): self
    {
        $limits ??= new ResourceLimits();
        self::keys($data, ['schema_version', 'name', 'line_length', 'encoding_mode', 'discriminator', 'records', 'trailer_controls']);
        $version = array_key_exists('schema_version', $data) ? $data['schema_version'] : '0.1.0';
        if ($version !== '0.1.0') {
            self::reject('schema_unsupported_version');
        }
        $name = $data['name'] ?? null;
        if (!is_string($name) || strlen($name) > 64 || preg_match('/\A[A-Za-z0-9_.-]{1,64}\z/', $name) !== 1) {
            self::reject('schema_invalid_name');
        }
        $length = $data['line_length'] ?? null;
        if (!is_int($length) || $length < 1 || $length > $limits->maxLineLength) {
            self::reject('schema_invalid_line_length');
        }
        $encoding = $data['encoding_mode'] ?? null;
        $mode = is_string($encoding) ? EncodingMode::tryFrom($encoding) : null;
        if ($mode === null) {
            self::reject('schema_invalid_encoding_mode');
        }
        $disc = $data['discriminator'] ?? null;
        if (!is_array($disc)) {
            self::reject('schema_invalid_discriminator');
        }
        self::keys($disc, ['start_col', 'end_col']);
        $start = $disc['start_col'] ?? null;
        $end = $disc['end_col'] ?? null;
        if (!is_int($start) || !is_int($end) || $start < 1 || $end < $start || $end > $length || $end - $start + 1 > 16) {
            self::reject('schema_invalid_discriminator_bounds');
        }
        $records = $data['records'] ?? null;
        if (!is_array($records)) {
            self::reject('schema_invalid_records');
        }
        $roles = ['header', 'detail', 'trailer'];
        foreach (array_keys($records) as $role) {
            if (!in_array($role, $roles, true)) {
                self::reject('schema_unknown_record_role');
            }
        }
        $discriminators = [];
        $seen = [];
        $fields = [];
        $maps = [];
        foreach ($roles as $role) {
            $record = $records[$role] ?? null;
            if (!is_array($record)) {
                self::reject('schema_missing_record_role');
            }
            self::keys($record, ['discriminator_value', 'fields']);
            $literal = $record['discriminator_value'] ?? null;
            if (!is_string($literal) || ColumnSlicer::hasControlCharacters($literal)) {
                self::reject('schema_invalid_discriminator_value');
            }
            if (ColumnSlicer::columnLength($literal, $mode, $limits->maxLineBytes) !== $end - $start + 1) {
                self::reject('schema_discriminator_width_mismatch');
            }
            if (isset($seen[$literal])) {
                self::reject('schema_duplicate_discriminator_value');
            }
            $seen[$literal] = true;
            $discriminators[$role] = $literal;
            $raw = $record['fields'] ?? null;
            if (!is_array($raw) || !array_is_list($raw) || $raw === [] || count($raw) > $limits->maxFieldsPerRecord) {
                self::reject('schema_invalid_fields_list');
            }
            $fields[$role] = self::validateFields($raw, $length, $mode, $start, $end, $literal, $limits);
            $maps[$role] = [];
            foreach ($fields[$role] as $field) {
                $maps[$role][$field->name] = $field;
            }
        }
        $controls = $data['trailer_controls'] ?? null;
        if (!is_array($controls)) {
            self::reject('schema_missing_trailer_controls');
        }
        self::keys($controls, ['detail_count_field', 'sum_controls']);
        $countName = $controls['detail_count_field'] ?? null;
        if (!is_string($countName) || $countName === '') {
            self::reject('schema_invalid_detail_count_field');
        }
        if (!isset($maps['trailer'][$countName])) {
            self::reject('schema_unknown_detail_count_field');
        }
        if ($maps['trailer'][$countName]->type !== FieldType::Digits) {
            self::reject('schema_invalid_detail_count_field_type');
        }
        $rawSums = $controls['sum_controls'] ?? null;
        if (!is_array($rawSums) || !array_is_list($rawSums) || $rawSums === [] || count($rawSums) > $limits->maxFieldsPerRecord) {
            self::reject('schema_invalid_sum_controls');
        }
        $sums = [];
        $used = [];
        foreach ($rawSums as $item) {
            if (!is_array($item)) {
                self::reject('schema_invalid_sum_control_item');
            }
            self::keys($item, ['detail_field', 'trailer_field']);
            $d = $item['detail_field'] ?? null;
            $t = $item['trailer_field'] ?? null;
            if (!is_string($d) || !is_string($t)) {
                self::reject('schema_invalid_sum_control_item');
            }
            if (!isset($maps['detail'][$d], $maps['trailer'][$t])) {
                self::reject('schema_unknown_sum_control_field');
            }
            if ($t === $countName || isset($used[$t])) {
                self::reject('schema_duplicate_trailer_control_field');
            }
            $used[$t] = true;
            if ($maps['detail'][$d]->type !== FieldType::ImpliedDecimal || $maps['trailer'][$t]->type !== FieldType::ImpliedDecimal) {
                self::reject('schema_invalid_sum_control_field_type');
            }
            if ($maps['detail'][$d]->scale !== $maps['trailer'][$t]->scale) {
                self::reject('schema_control_scale_mismatch');
            }
            $sums[] = ['detail_field' => $d, 'trailer_field' => $t];
        }
        return new self($name, $version, $length, $mode, $start, $end, $discriminators, $fields, $countName, $sums);
    }

    private static function validateFields(array $raw, int $length, EncodingMode $mode, int $discStart, int $discEnd, string $literal, ResourceLimits $limits): array
    {
        $fields = [];
        $seen = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                self::reject('schema_invalid_field_definition');
            }
            $name = $item['name'] ?? null;
            if (!is_string($name) || strlen($name) > 64 || preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,63}\z/', $name) !== 1) {
                self::reject('schema_invalid_field_name');
            }
            if (isset($seen[$name])) {
                self::reject('schema_duplicate_field_name');
            }
            $seen[$name] = true;
            $type = $item['type'] ?? null;
            $type = is_string($type) ? FieldType::tryFrom($type) : null;
            if ($type === null) {
                self::reject('schema_invalid_field_type');
            }
            $allowed = ['name', 'type', 'start_col', 'end_col'];
            if ($type === FieldType::Constant) {
                $allowed[] = 'value';
            }
            if ($type === FieldType::ImpliedDecimal) {
                $allowed[] = 'scale';
            }
            self::keys($item, $allowed);
            $start = $item['start_col'] ?? null;
            $end = $item['end_col'] ?? null;
            if (!is_int($start) || !is_int($end) || $start < 1 || $end < $start || $end > $length) {
                self::reject('schema_invalid_column_bounds');
            }
            $width = $end - $start + 1;
            if ($type === FieldType::DateYmd && $width !== 8) {
                self::reject('schema_invalid_date_width');
            }
            $scale = null;
            if ($type === FieldType::ImpliedDecimal) {
                $scale = $item['scale'] ?? null;
                if (!is_int($scale) || $scale < 0 || $scale > 18) {
                    self::reject('schema_invalid_implied_decimal_scale');
                }
                if ($width > 18) {
                    self::reject('schema_invalid_implied_decimal_width');
                }
            }
            $value = null;
            if ($type === FieldType::Constant) {
                $value = $item['value'] ?? null;
                if (!is_string($value) || ColumnSlicer::hasControlCharacters($value)
                    || ColumnSlicer::columnLength($value, $mode, $limits->maxLineBytes) !== $width) {
                    self::reject('schema_invalid_constant_value');
                }
            }
            $fields[] = new FieldDefinition($name, $type, $start, $end, $scale, $value);
        }
        usort($fields, static fn (FieldDefinition $a, FieldDefinition $b): int => $a->startCol <=> $b->startCol);
        $next = 1;
        $aligned = false;
        foreach ($fields as $field) {
            if ($field->startCol < $next) {
                self::reject('schema_overlapping_columns');
            }
            if ($field->startCol > $next) {
                self::reject('schema_column_gap');
            }
            $next = $field->endCol + 1;
            if ($field->startCol === $discStart && $field->endCol === $discEnd) {
                $aligned = true;
                if ($field->type !== FieldType::Constant || $field->constantValue !== $literal) {
                    self::reject('schema_discriminator_mismatch');
                }
            }
        }
        if ($next !== $length + 1) {
            self::reject('schema_column_gap');
        }
        if (!$aligned) {
            self::reject('schema_discriminator_span_unaligned');
        }
        return $fields;
    }
}
