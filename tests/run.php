<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/autoload.php';

use Lofrano\PositionalCodec\BatchCodec;
use Lofrano\PositionalCodec\CodecException;
use Lofrano\PositionalCodec\ColumnSlicer;
use Lofrano\PositionalCodec\EncodingMode;
use Lofrano\PositionalCodec\LayoutSchema;
use Lofrano\PositionalCodec\ResourceLimits;
use Lofrano\PositionalCodec\SafeMath;

// Warnings must fail tests, including otherwise hidden regex/type/IO regressions.
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

final class TestRunner
{
    private int $passed = 0;
    private int $failed = 0;

    public function run(string $name, callable $fn): void
    {
        try {
            $fn();
            $this->passed++;
            fwrite(STDOUT, "[PASS] {$name}\n");
        } catch (Throwable $e) {
            $this->failed++;
            fwrite(STDERR, "[FAIL] {$name}: " . $e->getMessage() . "\n");
        }
    }

    public function summary(): int
    {
        fwrite(STDOUT, "\nTests passed: {$this->passed}, failed: {$this->failed}\n");
        return $this->failed === 0 ? 0 : 1;
    }
}

function assertSame(mixed $expected, mixed $actual, string $context = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($context . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertTrue(bool $condition, string $context = 'Assertion failed'): void
{
    if (!$condition) {
        throw new RuntimeException($context);
    }
}

function assertCodecError(string $expected, callable $fn): CodecException
{
    try {
        $fn();
    } catch (CodecException $e) {
        assertSame($expected, $e->errorCode);
        assertTrue($e->diagnostic !== null, 'Exception needs structural diagnostic');
        return $e;
    }
    throw new RuntimeException('Expected CodecException: ' . $expected);
}

function baseSchemaArray(): array
{
    return [
        'schema_version' => '0.1.0', 'name' => 'test_batch_v1', 'line_length' => 24, 'encoding_mode' => 'ascii_bytes',
        'discriminator' => ['start_col' => 1, 'end_col' => 1],
        'records' => [
            'header' => ['discriminator_value' => 'H', 'fields' => [
                ['name' => 'record_type', 'type' => 'constant', 'start_col' => 1, 'end_col' => 1, 'value' => 'H'],
                ['name' => 'batch_date', 'type' => 'date_ymd', 'start_col' => 2, 'end_col' => 9],
                ['name' => 'origin', 'type' => 'alpha', 'start_col' => 10, 'end_col' => 24],
            ]],
            'detail' => ['discriminator_value' => 'D', 'fields' => [
                ['name' => 'record_type', 'type' => 'constant', 'start_col' => 1, 'end_col' => 1, 'value' => 'D'],
                ['name' => 'seq', 'type' => 'digits', 'start_col' => 2, 'end_col' => 5],
                ['name' => 'customer', 'type' => 'alpha', 'start_col' => 6, 'end_col' => 15],
                ['name' => 'amount_minor', 'type' => 'implied_decimal', 'start_col' => 16, 'end_col' => 24, 'scale' => 2],
            ]],
            'trailer' => ['discriminator_value' => 'T', 'fields' => [
                ['name' => 'record_type', 'type' => 'constant', 'start_col' => 1, 'end_col' => 1, 'value' => 'T'],
                ['name' => 'detail_count', 'type' => 'digits', 'start_col' => 2, 'end_col' => 5],
                ['name' => 'total_minor', 'type' => 'implied_decimal', 'start_col' => 6, 'end_col' => 17, 'scale' => 2],
                ['name' => 'filler', 'type' => 'alpha', 'start_col' => 18, 'end_col' => 24],
            ]],
        ],
        'trailer_controls' => ['detail_count_field' => 'detail_count', 'sum_controls' => [
            ['detail_field' => 'amount_minor', 'trailer_field' => 'total_minor'],
        ]],
    ];
}

function fixtureCodec(): array
{
    return [new BatchCodec(LayoutSchema::fromJsonString((string) file_get_contents(__DIR__ . '/../fixtures/erp_payment_schema.json'))),
        (string) file_get_contents(__DIR__ . '/../fixtures/erp_payment_batch.txt')];
}

function codes(\Lofrano\PositionalCodec\BatchResult $result): array
{
    return array_map(static fn ($d): string => $d->errorCode, $result->diagnostics);
}

$runner = new TestRunner();
$runner->run('schema rejects overlapping spans', static function (): void {
    $data = baseSchemaArray();
    $data['records']['detail']['fields'][1]['end_col'] = 6;
    assertCodecError('schema_overlapping_columns', static fn () => LayoutSchema::fromArray($data));
});
$runner->run('schema rejects middle, start and end gaps', static function (): void {
    $data = baseSchemaArray();
    $data['records']['detail']['fields'][2]['start_col'] = 7;
    assertCodecError('schema_column_gap', static fn () => LayoutSchema::fromArray($data));
    $data = baseSchemaArray();
    $data['discriminator'] = ['start_col' => 2, 'end_col' => 2];
    $data['records']['header']['fields'][0]['start_col'] = 2;
    $data['records']['header']['fields'][0]['end_col'] = 2;
    $data['records']['header']['fields'][1]['start_col'] = 3;
    $data['records']['header']['fields'][1]['end_col'] = 10;
    $data['records']['header']['fields'][2]['start_col'] = 11;
    assertCodecError('schema_column_gap', static fn () => LayoutSchema::fromArray($data));
    $data = baseSchemaArray();
    $data['records']['header']['fields'][2]['end_col'] = 23;
    assertCodecError('schema_column_gap', static fn () => LayoutSchema::fromArray($data));
});
$runner->run('schema rejects reversed, zero-based and excessive coordinates', static function (): void {
    foreach ([[5, 2], [0, 5], [2, 25]] as [$start, $end]) {
        $data = baseSchemaArray();
        $data['records']['detail']['fields'][1]['start_col'] = $start;
        $data['records']['detail']['fields'][1]['end_col'] = $end;
        assertCodecError('schema_invalid_column_bounds', static fn () => LayoutSchema::fromArray($data));
    }
});
$runner->run('schema validates widths, discriminator and control scales', static function (): void {
    $data = baseSchemaArray();
    $data['records']['header']['fields'][1]['end_col'] = 10;
    $data['records']['header']['fields'][2]['start_col'] = 11;
    assertCodecError('schema_invalid_date_width', static fn () => LayoutSchema::fromArray($data));
    $data = baseSchemaArray();
    $data['records']['header']['fields'][0]['value'] = 'HH';
    assertCodecError('schema_invalid_constant_value', static fn () => LayoutSchema::fromArray($data));
    $data['records']['header']['fields'][0]['value'] = 'X';
    assertCodecError('schema_discriminator_mismatch', static fn () => LayoutSchema::fromArray($data));
    $data = baseSchemaArray();
    $data['records']['trailer']['fields'][2]['scale'] = 4;
    assertCodecError('schema_control_scale_mismatch', static fn () => LayoutSchema::fromArray($data));
});
$runner->run('ERP fixture has independently specified totals and exact round trip', static function (): void {
    [$codec, $text] = fixtureCodec();
    $r = $codec->parseText($text);
    assertTrue($r->valid, 'ERP fixture must be valid');
    assertSame(5, $r->totalLines);
    assertSame(1, $r->headerCount);
    assertSame(3, $r->detailCount);
    assertSame(1, $r->trailerCount);
    assertSame(556000, $r->accumulatedTotals['net_amount_minor']);
    assertSame(44480, $r->accumulatedTotals['tax_amount_minor']);
    assertTrue($r->header !== null && $r->trailer !== null);
    assertSame('SÃO PAULO LOGÍSTICA LTDA', $r->details[0]['vendor_name']);
    assertSame(154075, $r->details[0]['net_amount_minor']);
    assertSame($text, $codec->formatBatch($r->header, $r->details, $r->trailer, $r->lineEnding, $r->hasTrailingNewline));
    assertSame($text, $codec->formatBatch($r->header, $r->details, null, $r->lineEnding, $r->hasTrailingNewline));
});
$runner->run('CRLF round trip and exact decimal string formatting', static function (): void {
    $codec = new BatchCodec(LayoutSchema::fromArray(baseSchemaArray()));
    $text = $codec->formatBatch(['batch_date' => '2026-02-28', 'origin' => 'MAINFRAME_A'], [
        ['seq' => 1, 'customer' => 'ACME', 'amount_minor' => '120.50'],
        ['seq' => 2, 'customer' => 'BETA', 'amount_minor' => 7950],
    ], null, "\r\n", false);
    $r = $codec->parseText($text);
    assertTrue($r->valid);
    assertSame("\r\n", $r->lineEnding);
    assertSame(false, $r->hasTrailingNewline);
    assertSame(20000, $r->accumulatedTotals['amount_minor']);
    assertSame($text, $codec->formatBatch($r->header ?? [], $r->details, $r->trailer, $r->lineEnding, $r->hasTrailingNewline));
});
$runner->run('one cent change and missing detail trigger explicit trailer errors', static function (): void {
    [$codec, $text] = fixtureCodec();
    $changed = str_replace('000000154075', '000000154076', $text, $replacements);
    assertSame(1, $replacements);
    $r = $codec->auditText($changed);
    assertSame(false, $r->valid);
    assertSame(['trailer_sum_mismatch'], codes($r));
    assertSame('total_net_minor', $r->diagnostics[0]->fieldName);
    assertSame(5, $r->diagnostics[0]->lineNumber);
    assertSame(8, $r->diagnostics[0]->startCol);
    assertSame(21, $r->diagnostics[0]->endCol);
    $lines = explode("\n", rtrim($text, "\n"));
    unset($lines[3]);
    $r = $codec->auditText(implode("\n", $lines) . "\n");
    assertSame(false, $r->valid);
    assertTrue(in_array('trailer_count_mismatch', codes($r), true));
    assertTrue(in_array('trailer_sum_mismatch', codes($r), true));
});
$runner->run('ASCII privacy sentinels never appear in audit reports', static function (): void {
    $codec = new BatchCodec(LayoutSchema::fromArray(baseSchemaArray()));
    $r = $codec->auditText(implode("\n", [
        'H' . '99887766' . 'SECRET453201511',
        'D' . 'ABCD' . 'CUST_00001' . 'BAD991122',
        'Z' . '0002' . 'CUST_00002' . '000001000',
        'D0003LEAK_PII_TOKEN_001122334455',
        'T0099000000999999       ',
    ]));
    assertSame(false, $r->valid);
    assertTrue(count($r->diagnostics) >= 5);
    foreach (['SECRET453201511', '99887766', 'BAD991122', 'ABCD', 'LEAK_PII_TOKEN_001122334455', 'CUST_00001'] as $secret) {
        assertSame(false, str_contains($r->toAuditSummaryJson(), $secret));
    }
    $unknown = array_values(array_filter($r->diagnostics, static fn ($d): bool => $d->errorCode === 'unknown_record_discriminator'));
    assertSame(1, count($unknown));
    assertSame('unknown', $unknown[0]->recordType);
    assertSame(false, str_contains($unknown[0]->message, 'Z'));
});
$runner->run('UTF8 code points and malformed sequences', static function (): void {
    assertSame(3, ColumnSlicer::columnLength('São', EncodingMode::Utf8Chars));
    assertSame(null, ColumnSlicer::columnLength('São', EncodingMode::AsciiBytes));
    assertSame(3, ColumnSlicer::columnLength('Sao', EncodingMode::AsciiBytes));
    foreach (["\xC0\xAF", "\xE0\x80\xAF", "\xED\xA0\x80", "\xE2\x82"] as $bad) {
        assertSame(false, ColumnSlicer::isValidUtf8($bad));
    }
    assertSame(true, ColumnSlicer::isValidUtf8("\xE2\x82\xAC"));
    assertSame(1, ColumnSlicer::columnLength("\xE2\x82\xAC", EncodingMode::Utf8Chars));
});
$runner->run('calendar and leap-year boundaries', static function (): void {
    foreach (['20240229', '20000229'] as $date) {
        assertTrue(BatchCodec::isValidDateYmd($date));
    }
    foreach (['20230229', '19000229', '20260015', '20261315', '20260431', '00000101'] as $date) {
        assertSame(false, BatchCodec::isValidDateYmd($date));
    }
});
$runner->run('checked integer math and serializer boundaries', static function (): void {
    assertSame(PHP_INT_MAX, SafeMath::checkedAdd(PHP_INT_MAX - 10, 10));
    assertSame(null, SafeMath::checkedAdd(PHP_INT_MAX - 10, 11));
    assertSame(PHP_INT_MAX, SafeMath::parseUnsignedInt((string) PHP_INT_MAX));
    assertSame(null, SafeMath::parseUnsignedInt('9223372036854775808'));
    assertSame(154075, SafeMath::decimalStringToMinorUnits('1540.75', 2));
    assertSame('1540.75', SafeMath::minorUnitsToDecimalString(154075, 2));
    assertSame('0.05', SafeMath::minorUnitsToDecimalString(5, 2));
    $codec = new BatchCodec(LayoutSchema::fromArray(baseSchemaArray()));
    assertCodecError('field_width_overflow', static fn () => $codec->formatRecord('header', ['batch_date' => '20260315', 'origin' => 'THIS_ORIGIN_NAME_IS_TOO_LONG']));
    assertCodecError('field_width_overflow', static fn () => $codec->formatRecord('detail', ['seq' => 1, 'customer' => 'ACME', 'amount_minor' => 1_000_000_000]));
    assertCodecError('negative_value_not_allowed', static fn () => $codec->formatRecord('detail', ['seq' => 1, 'customer' => 'ACME', 'amount_minor' => -1]));
    assertCodecError('line_break_not_allowed', static fn () => $codec->formatRecord('detail', ['seq' => 1, 'customer' => "ACME\nINJECT", 'amount_minor' => 100]));
});
$runner->run('sequence and max_lines regressions', static function (): void {
    $codec = new BatchCodec(LayoutSchema::fromArray(baseSchemaArray()), new ResourceLimits(maxLines: 3));
    $r = $codec->auditText("H20260315ORIGIN         \nD0001CUST_A    000000100");
    assertSame(false, $r->valid);
    assertTrue(in_array('missing_trailer_record', codes($r), true));
    $r = $codec->auditText(implode("\n", ['H20260315ORIGIN         ', 'D0001CUST_A    000000100', 'D0002CUST_B    000000200', 'T0002000000000300       ']));
    assertSame(false, $r->valid);
    assertTrue(in_array('max_lines_exceeded', codes($r), true));
});
$runner->run('real offline CLI demo emits JSON', static function (): void {
    $output = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../bin/positional-codec') . ' demo', $output, $status);
    assertSame(0, $status);
    $r = json_decode(implode("\n", $output), true, 16, JSON_THROW_ON_ERROR);
    assertTrue(is_array($r));
    assertSame('demo', $r['command']);
    assertSame(true, $r['round_trip_byte_identical']);
    assertSame(true, $r['audit_summary']['valid']);
    assertSame('erp_vendor_payout_v1', $r['audit_summary']['schema_name']);
    assertSame(3, $r['audit_summary']['detail_count']);
});

require __DIR__ . '/adversarial.php';
if (file_exists('/dev/full')) {
    $runner->run('CLI writes and flushes fail with status 2 without PHP warnings', static function (): void {
        $script = __DIR__ . '/../bin/positional-codec';
        foreach (['demo', '--help'] as $command) {
            $process = proc_open([PHP_BINARY, $script, $command], [
                0 => ['pipe', 'r'], 1 => ['file', '/dev/full', 'wb'], 2 => ['pipe', 'w'],
            ], $pipes);
            assertTrue(is_resource($process));
            fclose($pipes[0]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            assertSame(2, proc_close($process));
            $diagnostic = json_decode($error, true, 16, JSON_THROW_ON_ERROR);
            assertSame('output_io_error', $diagnostic['diagnostic']['error_code']);
            assertTrue(!str_contains($error, 'Warning'));
            assertTrue(!str_contains($error, 'Fatal error'));
        }
        $process = proc_open([PHP_BINARY, $script, 'unknown'], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/full', 'wb'],
        ], $pipes);
        assertTrue(is_resource($process));
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        assertSame(2, proc_close($process));
        assertSame('', $output);
    });
}
exit($runner->summary());
