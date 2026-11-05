# Changelog / Histórico

## Unreleased — 0.1.0 (development / desenvolvimento)

### English

- Explicit fixed-width JSON contracts, contiguous inclusive base-1 spans and coherent header/detail/trailer discriminators.
- Printable ASCII and validated UTF-8 scalar columns without mbstring; exact date and implied-decimal codecs.
- Checked integer count/sum controls, deterministic formatting and synthetic ERP round-trip demo.
- Bounded line scanning, diagnostics, decoded-field retention and generated output; strict JSON shapes and unknown-property rejection.
- Corrected shared-source control accumulation, control-name collisions, mixed newline acceptance, bare final CR handling and newline-suffixed numeric conversions.
- Structural serialization failures for unsupported field value types; sanitized local-file CLI failures.
- Regression/adversarial coverage for privacy sentinels, overflow, framing, resource amplification and output budgets.

### Português (Brasil)

- Contratos JSON posicionais explícitos, colunas base 1 inclusivas contíguas e discriminadores coerentes.
- ASCII imprimível e colunas escalares UTF-8 sem mbstring; codecs exatos de datas e decimais implícitos.
- Controles inteiros de contagem/soma, formatação determinística e demonstração ERP sintética com round-trip.
- Limites para leitura por linha, diagnósticos, campos retidos e saída; validação estrita de objetos/listas e propriedades.
- Correções em fontes compartilhadas de controles, colisões de nomes, quebras misturadas, CR final e conversões numéricas com quebra final.
- Falhas estruturais para tipos de campos não suportados e IO local sanitizado na CLI.
- Testes de regressão/adversariais para privacidade, overflow, enquadramento, amplificação e limites de saída.

- Check CLI partial writes/flush and map failed stdout/stderr to private structural errors with exit 2.
- Verifica escrita parcial/flush da CLI e mapeia stdout/stderr falhos para erro estrutural privado com saída 2.
