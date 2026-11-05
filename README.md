# positional-record-codec

[Português (Brasil)](README.pt-BR.md) · Development version **0.1.0** · MIT

An original offline PHP library and CLI by [Lofrano Tecnologia](https://lofranotecnologia.com.br) for explicit JSON fixed-width layouts. It checks contiguous 1-based column spans, header/detail/trailer order, dates, implied-decimal amounts, and trailer counts and sums. No network, telemetry, model/API calls, Composer packages, or banking standards manuals are used.

Repository: [martinlofranodeoliveira/positional-record-codec](https://github.com/martinlofranodeoliveira/positional-record-codec).

## Install, test and demo

Use a local copy of this repository with **64-bit PHP 8.4 CLI**, core JSON and PCRE. No dependency installation or build is required; Composer metadata is optional.

```sh
php -v
php tests/run.php
php bin/positional-codec demo
php bin/positional-codec --help
```

The test runner prints named PASS/FAIL results and exits nonzero on failure. The demo audits the bundled **synthetic** ERP payment fixture, reserializes it and emits pretty JSON. Selected output fields:

```json
{
  "command": "demo",
  "version": "0.1.0",
  "round_trip_byte_identical": true
}
```

The complete output also contains `audit_summary`: 5 lines, 1 header, 3 details, 1 trailer, net total `556000`, tax total `44480`, and no diagnostics. These are fixture values, not deployment or performance claims. Amounts are integer minor units, not floating-point currency values.

## CLI

```sh
php bin/positional-codec validate-schema fixtures/erp_payment_schema.json
php bin/positional-codec audit fixtures/erp_payment_schema.json fixtures/erp_payment_batch.txt
```

Help is human-readable; other successful commands and validation failures are JSON. Exit codes: `0` valid/success, `1` invalid contract or batch, `2` usage, IO or unexpected failure. Only explicitly supplied regular local files are opened; URL/stream wrappers are rejected. The CLI uses default resource limits and never prints input paths in failures.

## Library examples

```php
<?php
declare(strict_types=1);
require 'src/autoload.php';

use Lofrano\PositionalCodec\BatchCodec;
use Lofrano\PositionalCodec\LayoutSchema;
use Lofrano\PositionalCodec\ResourceLimits;

$json = file_get_contents('fixtures/erp_payment_schema.json');
$text = file_get_contents('fixtures/erp_payment_batch.txt');
if ($json === false || $text === false) {
    throw new RuntimeException('Unable to read local fixture.');
}
$limits = new ResourceLimits(maxLines: 1000, maxBatchBytes: 1048576);
$codec = new BatchCodec(LayoutSchema::fromJsonString($json, $limits), $limits);
$audit = $codec->auditText($text); // Does not retain decoded records.
echo $audit->toAuditSummaryJson(), "\n";
$parsed = $codec->parseText($text); // Retains records only if fully valid.
if ($parsed->valid) {
    $wire = $codec->formatBatch($parsed->header, $parsed->details, $parsed->trailer,
        $parsed->lineEnding, $parsed->hasTrailingNewline);
}
```

Format one detail with the bundled layout (constants are filled automatically):

```php
$line = $codec->formatRecord('detail', [
    'sequence_number' => 1, 'vendor_code' => '0001004501',
    'vendor_name' => 'SYNTHETIC SUPPLIER', 'due_date' => '2026-03-20',
    'net_amount_minor' => '1540.75', 'tax_amount_minor' => 12325,
    'status_code' => 'APR',
]);
```

`154075` as an integer and `'1540.75'` as a scale-2 decimal string mean the same amount. A scale-2 digit string `'154075'` is **not** accepted as serializer input; parsed minor units are integers. `formatBatch(..., trailer: null)` calculates controls and fills trailer alpha fields with spaces; any other trailer fields remain required. Supplied controls are checked, not silently overwritten.

## Rationale and limitations

- Explicit coverage catches alignment errors before reading data. Immutable schemas can only be created through validation factories.
- ASCII mode accepts printable bytes `0x20..0x7E`; UTF-8 mode counts validated Unicode scalar/code points, **not graphemes**. `São` is 3 columns/4 bytes; `e` plus a combining acute accent is 2 columns. No normalization is performed.
- Exact checked integer addition prevents PHP overflow from becoming floating point. Individual implied-decimal fields have at most 18 digits; accumulated sums must fit `PHP_INT_MAX`.
- Parsing scans one physical line at a time from a bounded in-memory string. This is not an unbounded streaming API. Limits also cap diagnostics, retained decoded fields and generated output. Use `auditText` when decoded records are unnecessary.
- One batch per input, LF or CRLF consistently, optional final newline. Mixed endings and bare CR are invalid. Every valid accepted batch round-trips byte-for-byte with its reported framing.
- Reports omit individual record values but **do contain aggregate amounts and schema identifiers**. Treat summaries as potentially sensitive. Never log parsed records or PHP exception traces with arguments.
- Native PHP JSON parsing uses the last occurrence of a duplicate object key. Use unique keys. Unknown properties and incorrect object/list shapes are rejected.
- This is a development tool for user-supplied contracts, not bank-certified software, a CNAB/EDI implementation, or a claim of production readiness.

See [contract](docs/contract.md), [design](docs/design.md), [security](SECURITY.md), [contributing](CONTRIBUTING.md) and [changelog](CHANGELOG.md). General contact: [official homepage](https://lofranotecnologia.com.br).

## Primary references

[PCRE splitting](https://www.php.net/manual/en/function.preg-split.php), [checkdate](https://www.php.net/manual/en/function.checkdate.php), [integers](https://www.php.net/manual/en/language.types.integer.php), [JSON](https://www.php.net/manual/en/book.json.php).

MIT License. Copyright (c) 2026 Lofrano Tecnologia.
