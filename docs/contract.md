# Development 0.1.0 contract / Contrato de desenvolvimento 0.1.0

## English

### Layout

This is a project-specific layout language, not JSON Schema or a financial standard. The complete example is `fixtures/erp_payment_schema.json`. Factories `LayoutSchema::fromJsonString(string, ?ResourceLimits)` and `fromArray(array, ?ResourceLimits)` validate before construction. Unknown properties are rejected at every layout level. JSON objects and lists must have their documented shapes. Native PHP JSON duplicate-key handling is last-definition-wins; unique keys are recommended. Native-array input is the equivalent associative/list representation.

Root properties:

- `schema_version`: optional, default `"0.1.0"`; no other value (including null) accepted.
- `name`: ASCII identifier `[A-Za-z0-9_.-]{1,64}`.
- `line_length`: positive integer column count, bounded by `maxLineLength`.
- `encoding_mode`: `ascii_bytes` or `utf8_chars`.
- `discriminator`: object with integer `start_col`, `end_col`; inclusive base 1, within line length, at most 16 columns.
- `records`: exactly `header`, `detail`, `trailer`, each an object with `discriminator_value` and a nonempty `fields` list. Discriminators are distinct and have the discriminator span's column width.
- `trailer_controls`: object with `detail_count_field` (a trailer `digits` field name) and nonempty `sum_controls` list. Each control has `detail_field`, `trailer_field`, referencing implied-decimal fields with equal scales. Each trailer target is unique. Multiple targets may reference one detail source, which is summed only once.

Each field has `name` (`[A-Za-z_][A-Za-z0-9_]{0,63}`), `type`, integer `start_col`, integer `end_col`. Names are unique within a role. Intervals must cover every column `1..line_length` exactly once; sorting is permitted but overlap, gap and out-of-range coordinates are errors. Each role has an exact constant field aligned with the discriminator span and value. Only implied-decimal fields have `scale`; only constants have `value`.

### Encoding, types and values

`ascii_bytes` permits printable ASCII `0x20..0x7E`, one byte per column. `utf8_chars` validates complete UTF-8 and counts Unicode scalar/code points, not graphemes. No normalization or transcoding occurs. Alpha/constants reject C0, DEL, C1, U+2028 and U+2029. Invalid UTF-8, overlong sequences and surrogates are invalid encoding.

| Type | Parse representation | Serializer input and wire form |
| --- | --- | --- |
| `alpha` | string, only trailing ASCII spaces removed | string, right-padded with ASCII spaces |
| `digits` | exact digit string, preserving leading zeros | nonnegative int or ASCII digit string, left-zero-padded; strings may exceed integer range unless used as the count control |
| `implied_decimal` | nonnegative exact int minor units | nonnegative int minor units OR unsigned decimal string with exactly `scale` fractional digits; scale 0 accepts a digit string without a dot; wire is left-zero-padded digits |
| `date_ymd` | `YYYYMMDD` string | `YYYYMMDD` or `YYYY-MM-DD` string, calendar checked using `checkdate`, years 0001..9999; wire width exactly 8 |
| `constant` | exact literal string | omitted/null auto-fills, otherwise exact string; literal width equals field width |

Implied-decimal width is 1..18, scale 0..18 (scale may exceed width). Scale is interpretation metadata: parsing never divides or uses float. Integer `1` at scale 2 represents `0.01`, not `1.00`. Leading zeros in numeric input are allowed, but digit-string width overflow is rejected. Decimal-string conversion strips insignificant zeros before numeric width checks. No rounding, signs, exponent notation, locale separators or floats. All fields reject overflow and line breaks; string inputs are byte-bounded before regex/conversion. Unknown supplied keys and missing/null nonconstant fields are errors.

### Batch API and framing

`BatchCodec($schema, ?$limits)` exposes `auditText`, `parseText`, `formatRecord($role, $record, $lineNumber = 1)`, and `formatBatch($header, $details, $trailer = null, $lineEnding = "\n", $trailingNewline = false)`.

A valid file has exactly one header on physical line 1, at least `minDetailRecords` details, then exactly one trailer as the final record. Trailer count equals recognized detail count; control sums equal checked source minor-unit sums. Default minimum is 1; 0 explicitly permits empty batches. Details passed to the formatter must be a list of associative records. Null trailer generates count/sums, constants and blank alpha fields; other fields remain required. Omitted/null control fields in an explicit trailer are calculated, but other missing nonconstant fields fail. Supplied controls must match.

LF or CRLF must be consistent, with an optional final delimiter. Bare CR, blank records, mixed endings and BOM are not accepted. `formatRecord` does not add a delimiter. For every valid parsed batch, formatting retained records with `lineEnding` and `hasTrailingNewline` reproduces its bytes, including alpha padding and leading zeros.

`BatchResult` contains counts, metadata, diagnostics and two maps: `accumulatedTotals` maps actual detail source names to sums (no synthetic count key); `declaredTrailerTotals` maps actual trailer control names, including the count field, to declared integers. Count is independently available as `detailCount`. Fan-out sources have one sum. Unavailable/incomplete/overflowed sums are null, not partial amounts. On invalid batches counts describe recognized processed records, not proof of completeness; declared controls come from the first recognized trailer. Summaries include aggregates, never decoded individual records. `auditText` retains none. `parseText` exposes `header`, `details`, `trailer` only if fully valid.

### Bounds and errors

All limits are named constructor parameters on `ResourceLimits`. Defaults and hard ceilings are the same except JSON depth: schema bytes 262144, JSON depth default 16/ceiling 64, fields per role 256, line columns 4096, lines 100000, line bytes 16384 (excluding delimiter), batch bytes 67108864 (including delimiters), diagnostics 100, retained decoded field entries 100000. Limits may be lowered; minimum diagnostics is 2, minimum lines 2, minimum schema bytes 64, minimum JSON depth 2; other maxima must be positive. Minimum details is 0..`maxLines - 2`. A 64-bit PHP runtime is mandatory.

Input text bytes are checked before scanning; line count and byte length before column splitting. No batch-wide line array is allocated. Diagnostics stop processing at their cap: on an additional error the final slot becomes `diagnostic_limit_exceeded`. Parse retention has an independent limit; audit is not subject to retention limits. Serialization enforces line, line-count and total-output byte limits before appending. APIs accepting strings/arrays cannot prevent callers from allocating those inputs beforehand.

Diagnostics always have `line_number`, `record_type`, `field_name`, `start_col`, `end_col`, `error_code`, `message`. Field errors use exact coordinates; unavailable context is null. Record roles are fixed names or `unknown`, never input discriminators. Schema/configuration errors use line 0, role `schema`. IO uses line 0, role `io`. Messages are static; no raw slices, invalid values or paths are interpolated. `CodecException` has `errorCode` and `diagnostic`; inspect these or `getMessage()`, not traces. Wrong top-level PHP argument types can still raise PHP `TypeError`.

Important stable codes include `schema_overlapping_columns`, `schema_column_gap`, `schema_invalid_column_bounds`, `invalid_encoding`, `invalid_line_length`, `invalid_digits_format`, `invalid_implied_decimal_format`, `invalid_date_ymd`, `constant_mismatch`, `field_width_overflow`, `trailer_count_mismatch`, `trailer_sum_mismatch`, `control_sum_overflow`, `max_lines_exceeded`, `line_byte_limit_exceeded`, `batch_size_limit_exceeded`, `retained_field_limit_exceeded`, `mixed_line_endings`, and `diagnostic_limit_exceeded`. Invalid control source fields suppress misleading sum comparisons.

CLI commands: `demo`, `validate-schema <schema.json>`, `audit <schema.json> <batch.txt>`, `--help`. Extra arguments fail. Exit 0 success, 1 validation failure, 2 usage/IO/unexpected error. CLI default limits are fixed; library limits are configurable. Files must be regular local files; wrappers are rejected. Successful audit/demo output is pretty JSON; errors use either a batch diagnostics list or `{valid:false, diagnostic:{...}}`. Help/usage is text.

## Português (Brasil)

O contrato 0.1.0 acima define um formato próprio, não JSON Schema, CNAB ou EDI. A fixture JSON é o exemplo completo. As fábricas validam antes de construir o esquema imutável. Chaves desconhecidas, objetos/listas incorretos, coordenadas não inteiras, sobreposições, lacunas, discriminadores incoerentes e escalas incompatíveis são rejeitados. Chaves JSON duplicadas seguem o PHP: prevalece a última definição; use chaves únicas.

Colunas são base 1 inclusivas e cobrem toda a linha exatamente uma vez. ASCII aceita somente bytes `0x20..0x7E`. UTF-8 conta pontos de código escalares, não grafemas; não normaliza. Alpha e constantes rejeitam controles C0/C1, DEL e separadores U+2028/U+2029. Alpha é preenchido à direita com espaços; digits à esquerda com zeros. Datas têm 8 colunas e usam `checkdate`. Constantes omitidas/nulas são preenchidas; demais campos são obrigatórios.

Decimais implícitos têm largura 1..18 e escala 0..18. O parse retorna inteiros em unidades menores. A serialização aceita inteiro em unidades menores ou string decimal com exatamente a escala declarada; escala zero usa string sem ponto. Não há floats, arredondamento ou notação exponencial. Somar verifica overflow antes da operação. Um mesmo campo de detalhe pode alimentar vários controles sem duplicar a soma.

Um lote contém cabeçalho na linha 1, detalhes (mínimo padrão 1, configurável para 0) e rodapé final. LF ou CRLF consistente, quebra final opcional; misturas, CR isolado, linhas vazias e BOM são inválidos. O formatador verifica controles fornecidos e calcula os omitidos. Rodapé nulo gera controles, constantes e alpha em branco, mas outros campos continuam obrigatórios. Lotes válidos fazem round-trip exato com os metadados de quebra retornados.

`auditText` não retém valores individuais. `parseText` só retorna registros se o lote inteiro for válido. `accumulatedTotals` usa nomes reais dos campos de detalhe; `declaredTrailerTotals` usa nomes reais dos controles de rodapé, inclusive contagem. `detailCount` é independente. Somas indisponíveis/incompletas/com overflow são nulas. Em lotes inválidos, contagens são apenas dos registros reconhecidos processados. Relatórios incluem totais e metadados, portanto podem ser sensíveis.

Os limites padrão/tetos são: esquema 262144 bytes; profundidade JSON 16 padrão/64 teto; 256 campos por registro; 4096 colunas; 100000 linhas; 16384 bytes por linha sem delimitador; 67108864 bytes por lote com delimitadores; 100 diagnósticos; 100000 entradas de campos retidas. Podem ser reduzidos. Diagnósticos têm mínimo 2; linhas mínimo 2; esquema mínimo 64 bytes; profundidade mínimo 2; mínimo de detalhes entre 0 e `maxLines - 2`. Exige PHP de 64 bits. Verificações precedem divisão em colunas; não há array de todas as linhas. A saída também é limitada. A API não impede alocações que o chamador fez antes da chamada.

Todo diagnóstico tem `line_number`, `record_type`, `field_name`, `start_col`, `end_col`, `error_code`, `message`. Contexto indisponível é nulo; esquema/configuração usa linha 0 e tipo `schema`, IO usa tipo `io`. Valores inválidos, caminhos e fatias brutas não são interpolados. Consulte `errorCode`, `diagnostic` e `getMessage()`, não traces PHP. Tipos incorretos nos parâmetros PHP de nível superior ainda podem gerar `TypeError`. O limite de diagnósticos interrompe processamento, substituindo a última posição por `diagnostic_limit_exceeded` se surgir outro erro.

A CLI oferece `demo`, `validate-schema`, `audit` e `--help`; rejeita argumentos extras e wrappers. Usa limites padrão e arquivos locais regulares. Retorna 0 para sucesso, 1 para validação inválida e 2 para uso/IO/falha inesperada. Saídas de dados são JSON; ajuda/uso são texto. A tabela e os códigos na seção inglesa são os identificadores estáveis da API, sem tradução.


CLI writes handle partial writes and verify flush. Output I/O failure returns 2 with a structural output_io_error diagnostic on stderr when available; partial stdout must be discarded. Native PHP warnings are not part of the diagnostic contract.

A CLI trata escritas parciais e verifica flush. Falha de E/S de saída retorna 2 com diagnóstico estrutural output_io_error em stderr quando disponível; descarte stdout parcial. Warnings nativos do PHP não fazem parte do contrato de diagnóstico.
