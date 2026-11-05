# Design / Arquitetura — development 0.1.0

## English

The original small class architecture is retained: immutable `LayoutSchema`, `ResourceLimits`, `FieldDefinition`, `Diagnostic`, `BatchResult`, and the `BatchCodec` orchestration layer. A restricted namespace autoloader works without Composer. Validated schemas have a private constructor so callers cannot accidentally bypass invariant checks.

### Bounds before expansion

Schema bytes/depth precede JSON decoding. JSON object/list distinctions are checked before conversion to native arrays; schema key sets and record/field/control counts are constrained. Native arrays use the same validation factories. Sorted intervals allow linear coverage verification after sorting rather than quadratic pairwise comparison.

Input text is byte-capped, then scanned using offsets and `strpos` for LF. A frame's byte count and physical line count are checked before copying/splitting it. This avoids a newline-dense input allocating millions of empty strings through `explode`. UTF-8 is completely validated before `preg_split('//u', ...)`, whose positive result limit derives from the already bounded byte count. Slicing costs O(field width), not O(1); total field widths equal the layout width.

Diagnostics are capped independently of input size. Retained decoded field entries have a separate cap because short input can otherwise expand into many PHP hash entries. Audit mode discards per-line values. Formatting checks individual string bytes, complete line bytes, and total output bytes before append. These are explicit application bounds, not a memory sandbox or a streaming interface; callers already own input strings/arrays.

### Exact arithmetic and naming

Unsigned digit strings are lexically compared to `PHP_INT_MAX` before integer conversion. Addition checks `a > PHP_INT_MAX - b` before executing it. Decimal strings concatenate major/fraction digits without floating conversion. Individual wire amounts have at most 18 digits; repeated values can still overflow a sum and produce `control_sum_overflow` with a null sum.

Control sources are deduplicated for accumulation, while each trailer target is verified independently. Source totals use source field names; declared totals use actual trailer field names. Counts are separate metadata. This permits fields named `detail_count` without corrupting arithmetic or losing declared controls. Invalid sources yield unavailable sums instead of plausible partial totals or misleading mismatch errors.

### Framing and privacy

Line terminators are detected per physical frame. A final bare CR is data and invalid, not silently removed. Consistent LF/CRLF plus preserved final-newline metadata makes the valid round-trip guarantee possible. Alpha trimming removes only right-padding ASCII spaces; zero padding and Unicode normalization are never guessed.

Codec failures build static messages from structural context only. Unknown roles/discriminators and unknown supplied keys are not echoed. Mixed/unsupported field value types are rejected deliberately rather than escaping as PHP argument-type errors. The CLI suppresses filesystem warnings and emits sanitized diagnostics; unexpected exceptions become a fixed `internal_error`. Aggregate amounts remain intentionally visible. Applications must not expose PHP traces, raw parsed objects or arbitrary native PHP error handlers to untrusted recipients.

Tests combine the existing ERP fixture regression with independent wire records, arithmetic boundaries, duplicate control sources, metadata-name collisions, framing mutations, Unicode sentinels, malformed UTF-8, output budgets and real CLI subprocesses. No claimed test run or performance measurement is part of the product contract.

## Português (Brasil)

A arquitetura mantém classes pequenas: esquema, limites, campos, diagnósticos e resultados imutáveis; `BatchCodec` coordena leitura, auditoria e formatação. O autoloader restrito dispensa Composer. O construtor privado do esquema impede desvio acidental da validação.

Bytes/profundidade limitam JSON antes da decodificação. Objetos/listas e chaves são verificados; intervalos ordenados permitem verificar cobertura linear após ordenar. O leitor percorre offsets até LF e verifica bytes/quantidade de linhas antes de copiar ou dividir. Isso evita amplificação por `explode` em entradas com milhões de quebras. UTF-8 completo é validado antes do `preg_split` limitado. Fatiar custa O(largura do campo), não O(1).

Diagnósticos e entradas de campos retidas têm limites próprios, pois strings curtas podem virar muitos arrays PHP. Auditoria descarta valores por linha. A formatação verifica bytes dos campos, linhas completas e saída total antes de anexar. São limites de aplicação, não sandbox de memória nem API de streaming; o chamador já alocou a entrada.

Conversões comparam dígitos a `PHP_INT_MAX`; somas verificam o limite antes de somar. Strings decimais concatenam partes sem float. Fontes de controle são acumuladas uma vez, mesmo com múltiplos alvos. Mapas usam nomes reais; contagens ficam separadas. Fontes inválidas e overflow produzem soma nula em vez de total parcial enganoso.

O enquadramento verifica LF/CRLF por linha e não descarta CR final isolado. Espaços alpha removidos no parse são apenas o preenchimento final ASCII; nenhuma normalização Unicode é aplicada. Diagnósticos usam mensagens estáticas e contexto estrutural. A CLI suprime avisos de arquivos e sanitiza falhas inesperadas. Totais continuam visíveis; não exponha traces PHP nem registros decodificados.

Os testes combinam regressões da fixture ERP com linhas independentes, limites aritméticos, controles com fonte compartilhada, colisões de nomes, mutações de quebras, sentinelas Unicode, UTF-8 malformado, limites de saída e subprocessos reais da CLI. O contrato não inclui alegações de execução de testes ou medições de desempenho.
