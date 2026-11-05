# Security / Segurança

## English

Development line 0.1.x is the current scope for fixes; no production-readiness or response-time guarantee is made.

Report vulnerabilities using [GitHub private vulnerability reporting](https://github.com/martinlofranodeoliveira/positional-record-codec/security/advisories/new), not public issues. Use synthetic reproductions and do not attach real records or secrets. General contact is through the [official Lofrano Tecnologia homepage](https://lofranotecnologia.com.br).

The codec has no network, telemetry or API dependency. Schema decoding, Unicode splitting, line counts, diagnostics, retained fields and generated output are bounded; callers can lower documented limits. The CLI opens regular local files only and sanitizes IO warnings and unexpected failures. It is not a filesystem sandbox: callers choose paths and must apply their own access policy. It is not an unbounded streaming parser or protection against all process-level memory exhaustion.

Diagnostics omit raw field values and paths. Summaries intentionally contain schema identifiers, counts and aggregate amounts, which may still be sensitive. Parsed records are sensitive application data. Do not expose them, PHP exception traces with arguments, native warnings or debug dumps. Native PHP JSON duplicate-key behavior uses the last definition; use unique schema keys. Validate user-supplied schemas and treat their identifiers as public structural metadata, never secret storage.

No proprietary financial manuals, customer code or real personal records are bundled. The synthetic layout is not a certification or a financial standard implementation.

## Português (Brasil)

A linha de desenvolvimento 0.1.x é o escopo atual de correções; não há garantia de prontidão para produção nem prazo de resposta.

Reporte vulnerabilidades pelo [relato privado do GitHub](https://github.com/martinlofranodeoliveira/positional-record-codec/security/advisories/new), não em issues públicas. Use reproduções sintéticas, sem registros reais ou segredos. Contato geral pelo [site oficial da Lofrano Tecnologia](https://lofranotecnologia.com.br).

Não há rede, telemetria ou dependência de API. Há limites para JSON, Unicode, linhas, diagnósticos, campos retidos e saída; podem ser reduzidos. A CLI usa arquivos locais regulares e sanitiza avisos/falhas inesperadas. Não é sandbox de arquivos: o chamador escolhe caminhos e aplica sua política de acesso. Não é parser de streaming ilimitado nem proteção contra todo esgotamento de memória do processo.

Diagnósticos omitem valores brutos e caminhos. Resumos incluem identificadores, contagens e totais potencialmente sensíveis. Registros decodificados também são sensíveis. Não exponha traces PHP com argumentos, avisos nativos ou dumps. Chaves JSON duplicadas seguem a última definição do PHP; use chaves únicas. Identificadores de esquemas são metadados públicos, não locais para armazenar segredos.

Não são incluídos manuais financeiros proprietários, código de clientes ou dados pessoais reais. O layout sintético não representa certificação nem implementação de padrão financeiro.
