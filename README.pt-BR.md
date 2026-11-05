# positional-record-codec

[English](README.md) · Versão de desenvolvimento **0.1.0** · MIT

Biblioteca e CLI originais da [Lofrano Tecnologia](https://lofranotecnologia.com.br), em PHP, para auditar e formatar arquivos posicionais offline segundo layouts JSON explícitos. Verifica cobertura contígua de colunas base 1, sequência cabeçalho/detalhe/rodapé, datas, decimais implícitos, contagens e somas. Não usa rede, telemetria, modelos/APIs, pacotes Composer ou manuais bancários.

Repositório: [martinlofranodeoliveira/positional-record-codec](https://github.com/martinlofranodeoliveira/positional-record-codec).

## Instalação, testes e demonstração

Use uma cópia local do repositório com **PHP 8.4 CLI de 64 bits**, JSON e PCRE nativos. Não há instalação de dependências nem compilação; os metadados Composer são opcionais.

```sh
php -v
php tests/run.php
php bin/positional-codec demo
php bin/positional-codec --help
```

Os testes imprimem PASS/FAIL por caso e retornam código diferente de zero em falhas. A demonstração audita o lote ERP **sintético** incluído e verifica o round-trip. Campos selecionados da saída:

```json
{
  "command": "demo",
  "version": "0.1.0",
  "round_trip_byte_identical": true
}
```

A saída completa inclui `audit_summary`: 5 linhas, 1 cabeçalho, 3 detalhes, 1 rodapé, total líquido `556000`, tributos `44480` e nenhum diagnóstico. São valores da fixture, não alegações de implantação ou desempenho. Valores monetários são inteiros em unidades menores, nunca ponto flutuante.

## CLI

```sh
php bin/positional-codec validate-schema fixtures/erp_payment_schema.json
php bin/positional-codec audit fixtures/erp_payment_schema.json fixtures/erp_payment_batch.txt
```

A ajuda é textual; os demais resultados e falhas de validação são JSON. Códigos de saída: `0` sucesso/válido, `1` esquema ou lote inválido, `2` uso incorreto, IO ou falha inesperada. A CLI aceita somente arquivos locais regulares explicitamente informados, rejeita wrappers de URL/stream e não imprime caminhos nas falhas. Utiliza os limites padrão.

## Uso como biblioteca

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
    throw new RuntimeException('Falha na leitura da fixture local.');
}
$limits = new ResourceLimits(maxLines: 1000, maxBatchBytes: 1048576);
$codec = new BatchCodec(LayoutSchema::fromJsonString($json, $limits), $limits);
echo $codec->auditText($text)->toAuditSummaryJson(), "\n";
$parsed = $codec->parseText($text);
if ($parsed->valid) {
    $wire = $codec->formatBatch($parsed->header, $parsed->details, $parsed->trailer,
        $parsed->lineEnding, $parsed->hasTrailingNewline);
}
```

`auditText` não retém registros decodificados. `parseText` só os disponibiliza quando todo o lote é válido. Exemplo de formatação com o layout incluído:

```php
$line = $codec->formatRecord('detail', [
    'sequence_number' => 1, 'vendor_code' => '0001004501',
    'vendor_name' => 'FORNECEDOR SINTETICO', 'due_date' => '2026-03-20',
    'net_amount_minor' => '1540.75', 'tax_amount_minor' => 12325,
    'status_code' => 'APR',
]);
```

Em escala 2, o inteiro `154075` equivale à string decimal `'1540.75'`. A string sem separador `'154075'` **não** é entrada válida para esse campo na serialização. Constantes são preenchidas automaticamente. Com `trailer: null`, `formatBatch` calcula controles e preenche campos alpha do rodapé com espaços; outros campos continuam obrigatórios. Controles fornecidos são verificados, não sobrescritos silenciosamente.

## Decisões e limitações

- A cobertura explícita detecta desalinhamentos antes da leitura. Esquemas imutáveis só podem ser criados pelas fábricas validadoras.
- `ascii_bytes` aceita bytes imprimíveis `0x20..0x7E`. `utf8_chars` conta pontos de código/valores escalares Unicode validados, **não grafemas**: `São` ocupa 3 colunas/4 bytes; `e` com acento combinante ocupa 2 colunas. Não há normalização.
- Somas são verificadas antes da adição para impedir promoção para float. Cada campo decimal implícito tem até 18 dígitos; somas acumuladas precisam caber em `PHP_INT_MAX`.
- A leitura percorre linhas de uma string limitada em memória; não é uma API de streaming ilimitado. Há limites de diagnósticos, campos retidos e saída gerada. Prefira `auditText` se não precisar dos registros.
- Um lote por entrada; LF ou CRLF consistente, com quebra final opcional. Misturas e CR isolado são inválidos. Lotes válidos fazem round-trip byte a byte com os metadados de quebra retornados.
- Relatórios omitem valores individuais, mas **incluem totais e identificadores do esquema**. Trate-os como potencialmente sensíveis. Não registre registros decodificados nem traces PHP com argumentos.
- O JSON nativo do PHP usa a última ocorrência de uma chave duplicada. Use chaves únicas. Propriedades desconhecidas e formatos objeto/lista incorretos são rejeitados.
- Ferramenta em desenvolvimento para contratos fornecidos pelo usuário: não é software bancário homologado, implementação CNAB/EDI ou alegação de prontidão para produção.

Consulte [contrato](docs/contract.md), [design](docs/design.md), [segurança](SECURITY.md), [contribuição](CONTRIBUTING.md) e [histórico](CHANGELOG.md). Contato geral: [site oficial](https://lofranotecnologia.com.br).

## Referências primárias

[Divisão PCRE](https://www.php.net/manual/en/function.preg-split.php), [checkdate](https://www.php.net/manual/en/function.checkdate.php), [inteiros](https://www.php.net/manual/en/language.types.integer.php), [JSON](https://www.php.net/manual/en/book.json.php).

Licença MIT. Copyright (c) 2026 Lofrano Tecnologia.
