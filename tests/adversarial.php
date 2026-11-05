<?php

declare(strict_types=1);

use Lofrano\PositionalCodec\BatchCodec;
use Lofrano\PositionalCodec\ColumnSlicer;
use Lofrano\PositionalCodec\EncodingMode;
use Lofrano\PositionalCodec\LayoutSchema;
use Lofrano\PositionalCodec\ResourceLimits;
use Lofrano\PositionalCodec\SafeMath;

// These wire records are assembled independently of the serializer.
function wireLines(): array
{
    return ['H20260315' . str_pad('ORIGIN', 15), 'D0001' . str_pad('SYNTH', 10) . '000000100',
        'T0001' . '000000000100' . str_repeat(' ', 7)];
}

$runner->run('strict JSON shapes, keys and identifier anchors', static function (): void {
    assertCodecError('schema_root_not_object', static fn () => LayoutSchema::fromJsonString('[]'));
    $data = baseSchemaArray();
    $data['records']['header']['fields'] = (object) $data['records']['header']['fields'];
    assertCodecError('schema_invalid_fields_list', static fn () => LayoutSchema::fromJsonString(json_encode($data, JSON_THROW_ON_ERROR)));
    $data = baseSchemaArray();
    $data['records']['detail']['fields'][0] = ['value' => 'D'];
    assertCodecError('schema_invalid_field_name', static fn () => LayoutSchema::fromArray($data));
    $data = baseSchemaArray();
    $data['name'] = "name\n";
    assertCodecError('schema_invalid_name', static fn () => LayoutSchema::fromArray($data));
    $data = baseSchemaArray();
    $data['records']['detail']['fields'][1]['name'] = "seq\n";
    assertCodecError('schema_invalid_field_name', static fn () => LayoutSchema::fromArray($data));
    $data = baseSchemaArray();
    $data['line_lenght'] = 24;
    assertCodecError('schema_unknown_property', static fn () => LayoutSchema::fromArray($data));
    $data = baseSchemaArray();
    $data['records']['detail']['fields'][1]['start_col'] = 2.0;
    assertCodecError('schema_invalid_column_bounds', static fn () => LayoutSchema::fromArray($data));
    $data = baseSchemaArray();
    $data['trailer_controls']['sum_controls'] = ['named' => $data['trailer_controls']['sum_controls'][0]];
    assertCodecError('schema_invalid_sum_controls', static fn () => LayoutSchema::fromArray($data));
});

$runner->run('schema byte, depth, field and role bounds', static function (): void {
    assertCodecError('schema_size_limit_exceeded', static fn () => LayoutSchema::fromJsonString(str_repeat(' ', 262145)));
    assertCodecError('schema_invalid_json', static fn () => LayoutSchema::fromJsonString(str_repeat('[', 70) . '0' . str_repeat(']', 70)));
    $data = baseSchemaArray();
    assertCodecError('schema_invalid_fields_list', static fn () => LayoutSchema::fromArray($data, new ResourceLimits(maxFieldsPerRecord: 2)));
    $data['records']['extra'] = $data['records']['detail'];
    assertCodecError('schema_unknown_record_role', static fn () => LayoutSchema::fromArray($data));
    assertCodecError('invalid_resource_limits', static fn () => new ResourceLimits(maxLineLength: PHP_INT_MAX));
    assertCodecError('invalid_resource_limits', static fn () => new ResourceLimits(maxDiagnostics: 1));
    assertCodecError('invalid_resource_limits', static fn () => new ResourceLimits(maxLines: 2));
});

$runner->run('fan-out controls do not multiply a source sum', static function (): void {
    $data = baseSchemaArray();
    $data['records']['trailer']['fields'][3] = ['name' => 'copy_minor', 'type' => 'implied_decimal', 'start_col' => 18, 'end_col' => 24, 'scale' => 2];
    $data['trailer_controls']['sum_controls'][] = ['detail_field' => 'amount_minor', 'trailer_field' => 'copy_minor'];
    $codec = new BatchCodec(LayoutSchema::fromArray($data));
    $lines = wireLines();
    $lines[2] = 'T0001' . '000000000100' . '0000100';
    $text = implode("\n", $lines);
    $r = $codec->parseText($text);
    assertTrue($r->valid);
    assertSame(100, $r->accumulatedTotals['amount_minor']);
    assertSame(100, $r->declaredTrailerTotals['copy_minor']);
    assertSame($text, $codec->formatBatch($r->header, $r->details, $r->trailer));
    assertSame($text, $codec->formatBatch($r->header, $r->details));
});

$runner->run('control names cannot collide with summary metadata', static function (): void {
    $data = baseSchemaArray();
    $data['records']['detail']['fields'][3]['name'] = 'detail_count';
    $data['records']['trailer']['fields'][1]['name'] = 'records';
    $data['records']['trailer']['fields'][2]['name'] = 'detail_count';
    $data['trailer_controls'] = ['detail_count_field' => 'records', 'sum_controls' => [
        ['detail_field' => 'detail_count', 'trailer_field' => 'detail_count'],
    ]];
    $codec = new BatchCodec(LayoutSchema::fromArray($data));
    $text = implode("\n", wireLines());
    $r = $codec->parseText($text);
    assertTrue($r->valid);
    assertSame(1, $r->detailCount);
    assertSame(['detail_count' => 100], $r->accumulatedTotals);
    assertSame(['records' => 1, 'detail_count' => 100], $r->declaredTrailerTotals);
    assertSame($text, $codec->formatBatch($r->header, $r->details, $r->trailer));
});

$runner->run('line framing rejects mixed endings and a bare terminal CR', static function (): void {
    $codec = new BatchCodec(LayoutSchema::fromArray(baseSchemaArray()));
    $lines = wireLines();
    foreach (["\n", "\r\n"] as $ending) {
        foreach ([false, true] as $final) {
            $text = implode($ending, $lines) . ($final ? $ending : '');
            $r = $codec->parseText($text);
            assertTrue($r->valid);
            assertSame($ending, $r->lineEnding);
            assertSame($final, $r->hasTrailingNewline);
            assertSame($text, $codec->formatBatch($r->header, $r->details, $r->trailer, $r->lineEnding, $r->hasTrailingNewline));
        }
    }
    $r = $codec->auditText($lines[0] . "\n" . $lines[1] . "\r\n" . $lines[2]);
    assertTrue(in_array('mixed_line_endings', codes($r), true));
    $r = $codec->auditText(implode("\n", $lines) . "\r");
    assertTrue(in_array('invalid_control_character', codes($r), true));
    assertSame(false, $codec->auditText(implode("\n", $lines) . "\n\n")->valid);
});

$runner->run('all sequence states and missing controls are rejected', static function (): void {
    $codec = new BatchCodec(LayoutSchema::fromArray(baseSchemaArray()));
    [$h, $d, $t] = wireLines();
    foreach ([
        [[$d, $t], 'missing_header_record'],
        [[$h, $h, $d, $t], 'unexpected_header_record'],
        [[$h, $d, $t, $t], 'duplicate_trailer_record'],
        [[$h, $t], 'missing_detail_records'],
        [[$h, $d, $t, $d], 'record_after_trailer'],
        [[$h, $d], 'missing_trailer_record'],
        [[], 'empty_batch'],
    ] as [$lines, $code]) {
        $r = $codec->parseText(implode("\n", $lines));
        assertSame(false, $r->valid);
        assertTrue(in_array($code, codes($r), true), $code);
        assertSame(null, $r->header);
        assertSame([], $r->details);
        assertSame(null, $r->trailer);
    }
    $zero = new BatchCodec(LayoutSchema::fromArray(baseSchemaArray()), new ResourceLimits(minDetailRecords: 0));
    assertTrue($zero->auditText($h . "\n" . 'T0000' . '000000000000' . str_repeat(' ', 7))->valid);
});

$runner->run('line bombs, diagnostics and retained fields have independent caps', static function (): void {
    $schema = LayoutSchema::fromArray(baseSchemaArray());
    $codec = new BatchCodec($schema, new ResourceLimits(maxLines: 3));
    $r = $codec->auditText(str_repeat("\n", 200000));
    assertSame(3, $r->totalLines);
    assertSame(['invalid_line_length', 'invalid_line_length', 'invalid_line_length', 'max_lines_exceeded'], codes($r));
    $codec = new BatchCodec($schema, new ResourceLimits(maxDiagnostics: 2));
    $bad = 'DABCD' . str_pad('SYNTH', 10) . 'BAD991122';
    $r = $codec->auditText(wireLines()[0] . "\n" . str_repeat($bad . "\n", 1000));
    assertSame(2, count($r->diagnostics));
    assertSame('diagnostic_limit_exceeded', codes($r)[1]);
    assertTrue($r->totalLines <= 3);
    $codec = new BatchCodec($schema, new ResourceLimits(maxRetainedFields: 6));
    $text = implode("\n", wireLines());
    $r = $codec->parseText($text);
    assertTrue(in_array('retained_field_limit_exceeded', codes($r), true));
    assertSame([], $r->details);
    assertTrue($codec->auditText($text)->valid, 'Audit does not retain fields');
    $codec = new BatchCodec($schema, new ResourceLimits(maxLineBytes: 24));
    $r = $codec->auditText(str_repeat('X', 100000));
    assertSame('line_byte_limit_exceeded', codes($r)[0]);
    $codec = new BatchCodec($schema, new ResourceLimits(maxBatchBytes: 73));
    assertSame(['batch_size_limit_exceeded'], codes($codec->auditText($text)));
});

$runner->run('serializer enforces output budgets, input types and supplied totals', static function (): void {
    $schema = LayoutSchema::fromArray(baseSchemaArray());
    $header = ['batch_date' => '20260315', 'origin' => 'ORIGIN'];
    $detail = ['seq' => 1, 'customer' => 'SYNTH', 'amount_minor' => 100];
    $codec = new BatchCodec($schema, new ResourceLimits(maxBatchBytes: 74));
    assertSame(implode("\n", wireLines()), $codec->formatBatch($header, [$detail]));
    assertCodecError('batch_size_limit_exceeded', static fn () => $codec->formatBatch($header, [$detail], null, "\n", true));
    $codec = new BatchCodec($schema, new ResourceLimits(maxBatchBytes: 73));
    assertCodecError('batch_size_limit_exceeded', static fn () => $codec->formatBatch($header, [$detail]));
    $codec = new BatchCodec($schema);
    foreach ([1.25, true, [], new stdClass()] as $value) {
        assertCodecError('invalid_field_type', static fn () => $codec->formatRecord('detail', array_replace($detail, ['amount_minor' => $value])));
    }
    assertCodecError('invalid_details_list', static fn () => $codec->formatBatch($header, [2 => $detail]));
    assertCodecError('invalid_record_type', static fn () => $codec->formatBatch($header, ['not a record']));
    assertCodecError('trailer_count_mismatch', static fn () => $codec->formatBatch($header, [$detail], ['detail_count' => 2]));
    assertCodecError('trailer_sum_mismatch', static fn () => $codec->formatBatch($header, [$detail], ['total_minor' => 101]));
    assertCodecError('unknown_field_name', static fn () => $codec->formatRecord('detail', $detail + ['extra' => 'PRIVATE']));
    assertCodecError('missing_required_field', static fn () => $codec->formatRecord('header', []));
    assertCodecError('constant_mismatch', static fn () => $codec->formatRecord('detail', $detail + ['record_type' => 'X']));
    assertCodecError('line_break_not_allowed', static fn () => $codec->formatRecord('detail', $detail + ['record_type' => "D\n"]));
    assertCodecError('field_width_overflow', static fn () => $codec->formatRecord('detail', array_replace($detail, ['seq' => 10000])));
    assertCodecError('field_byte_limit_exceeded', static fn () => $codec->formatRecord('detail', array_replace($detail, ['customer' => str_repeat('X', 16385)])));
    $data = baseSchemaArray();
    $data['encoding_mode'] = 'utf8_chars';
    $codec = new BatchCodec(LayoutSchema::fromArray($data), new ResourceLimits(maxLineBytes: 24));
    assertCodecError('line_byte_limit_exceeded', static fn () => $codec->formatRecord('detail', array_replace($detail, ['customer' => '😀'])));
});

$runner->run('minor-unit zero, maximum field value and accumulated overflow', static function (): void {
    $data = baseSchemaArray();
    $data['records']['detail']['fields'] = [
        $data['records']['detail']['fields'][0],
        ['name' => 'amount_minor', 'type' => 'implied_decimal', 'start_col' => 2, 'end_col' => 19, 'scale' => 2],
        ['name' => 'pad', 'type' => 'alpha', 'start_col' => 20, 'end_col' => 24],
    ];
    $data['records']['trailer']['fields'][2]['end_col'] = 23;
    $data['records']['trailer']['fields'][3]['start_col'] = 24;
    $codec = new BatchCodec(LayoutSchema::fromArray($data));
    foreach (['000000000000000000', '999999999999999999'] as $digits) {
        $text = wireLines()[0] . "\nD" . $digits . '     ' . "\nT0001" . $digits . ' ';
        $r = $codec->parseText($text);
        assertTrue($r->valid);
        assertSame((int) $digits, $r->accumulatedTotals['amount_minor']);
        assertSame($text, $codec->formatBatch($r->header, $r->details, $r->trailer));
    }
    $text = wireLines()[0] . "\n" . str_repeat('D999999999999999999     ' . "\n", 10) . 'T0010' . '000000000000000000' . ' ';
    $r = $codec->auditText($text);
    assertSame(['control_sum_overflow'], codes($r));
    assertSame(null, $r->accumulatedTotals['amount_minor']);
    $details = array_fill(0, 10, ['amount_minor' => 999999999999999999, 'pad' => '']);
    assertCodecError('control_sum_overflow', static fn () => $codec->formatBatch(['batch_date' => '20260315', 'origin' => 'ORIGIN'], $details));
    assertSame(0, SafeMath::decimalStringToMinorUnits('000.00', 2));
    assertSame(PHP_INT_MAX, SafeMath::decimalStringToMinorUnits('92233720368547758.07', 2));
    assertSame(null, SafeMath::decimalStringToMinorUnits('92233720368547758.08', 2));
    foreach (["1\n", '-1', '+1', '1e2', '１２'] as $bad) {
        assertSame(null, SafeMath::parseUnsignedInt($bad));
    }
    foreach (["1.00\n", '1.0', '1.000', '.01', '1', 'NaN'] as $bad) {
        assertSame(null, SafeMath::decimalStringToMinorUnits($bad, 2));
    }
    assertSame(null, SafeMath::checkedAdd(-1, 1));
});

$runner->run('scalar boundaries, grapheme distinction and Unicode line breaks', static function (): void {
    assertSame(2, ColumnSlicer::columnLength("e\u{0301}", EncodingMode::Utf8Chars));
    assertSame(1, ColumnSlicer::columnLength('😀', EncodingMode::Utf8Chars));
    assertSame(true, ColumnSlicer::isValidUtf8("\xF4\x8F\xBF\xBF"));
    foreach (["\xF4\x90\x80\x80", "\xF5\x80\x80\x80", "\x80", "\xF0\x80\x80\xAF"] as $bad) {
        assertSame(false, ColumnSlicer::isValidUtf8($bad));
        $data = baseSchemaArray();
        $data['encoding_mode'] = 'utf8_chars';
        $codec = new BatchCodec(LayoutSchema::fromArray($data));
        $lines = wireLines();
        $lines[1] = 'D0001' . $bad . str_repeat(' ', 9) . '000000100';
        assertTrue(in_array('invalid_encoding', codes($codec->auditText(implode("\n", $lines))), true));
    }
    assertSame(null, ColumnSlicer::columnLength("A\tB", EncodingMode::AsciiBytes));
    $data = baseSchemaArray();
    $data['encoding_mode'] = 'utf8_chars';
    $codec = new BatchCodec(LayoutSchema::fromArray($data));
    foreach (["\u{0085}", "\u{2028}", "\u{2029}"] as $break) {
        assertCodecError('line_break_not_allowed', static fn () => $codec->formatRecord('detail', ['seq' => 1, 'customer' => $break, 'amount_minor' => 0]));
    }
});

$runner->run('privacy regression excludes every sentinel character, not just whole tokens', static function (): void {
    $data = baseSchemaArray();
    $data['encoding_mode'] = 'utf8_chars';
    $codec = new BatchCodec(LayoutSchema::fromArray($data));
    $date = "\u{E101}\u{E102}\u{E103}\u{E104}\u{E105}\u{E106}\u{E107}\u{E108}";
    $digits = "\u{E109}\u{E10A}\u{E10B}\u{E10C}";
    $amount = "\u{E10D}\u{E10E}\u{E10F}\u{E110}\u{E111}\u{E112}\u{E113}\u{E114}\u{E115}";
    $text = 'H' . $date . str_pad('ORIGIN', 15) . "\nD" . $digits . str_pad('SYNTH', 10) . $amount . "\n" . wireLines()[2];
    $r = $codec->parseText($text);
    assertSame(false, $r->valid);
    assertSame(['invalid_date_ymd', 'invalid_digits_format', 'invalid_implied_decimal_format'], codes($r));
    assertSame(null, $r->accumulatedTotals['amount_minor']);
    assertSame(null, $r->header);
    assertSame([], $r->details);
    $reports = [$r->toAuditSummaryJson(), implode('', array_map(static fn ($d): string => $d->message, $r->diagnostics))];
    $e = assertCodecError('invalid_implied_decimal_format', static fn () => $codec->formatRecord('detail', ['seq' => 1, 'customer' => 'SYNTH', 'amount_minor' => $amount]));
    $reports[] = $e->getMessage();
    $reports[] = json_encode($e->diagnostic->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    foreach (preg_split('//u', $date . $digits . $amount, -1, PREG_SPLIT_NO_EMPTY) as $character) {
        foreach ($reports as $report) {
            assertSame(false, str_contains($report, $character), 'Sentinel character leaked');
        }
    }
    foreach ($r->diagnostics as $diagnostic) {
        $a = $diagnostic->toArray();
        foreach (['line_number', 'record_type', 'field_name', 'start_col', 'end_col', 'error_code'] as $key) {
            assertTrue(array_key_exists($key, $a));
        }
        assertTrue($diagnostic->fieldName !== null);
        assertTrue($diagnostic->startCol !== null && $diagnostic->endCol !== null);
    }
    $validAudit = $codec->auditText(implode("\n", wireLines()));
    assertTrue($validAudit->valid);
    assertSame(null, $validAudit->header);
    assertSame([], $validAudit->details);
});

$runner->run('CLI usage and IO failures do not echo paths or wrapper input', static function (): void {
    foreach ([['audit', 'https://synthetic.invalid/PRIVATE_PATH', 'PRIVATE_BATCH'], ['demo', 'PRIVATE_EXTRA']] as $args) {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../bin/positional-codec');
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }
        $output = [];
        exec($command . ' 2>&1', $output, $status);
        assertSame(2, $status);
        assertSame(false, str_contains(implode("\n", $output), 'PRIVATE'));
        assertSame(false, str_contains(implode("\n", $output), 'Warning'));
    }
});
