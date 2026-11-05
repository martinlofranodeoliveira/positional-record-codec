# Contributing / Contribuindo

## English

Use repository issues and pull requests for non-sensitive bugs and improvements. For vulnerabilities follow [SECURITY.md](SECURITY.md). General organization contact: [Lofrano Tecnologia](https://lofranotecnologia.com.br).

With 64-bit PHP 8.4 and core JSON/PCRE, run:

```sh
php tests/run.php
php bin/positional-codec demo
php bin/positional-codec validate-schema fixtures/erp_payment_schema.json
```

Keep runtime dependencies at zero. Use only original code and synthetic fixtures. Do not submit personal records, credentials, private client code or proprietary financial manuals. Keep numeric conversions and controls free of floats; check before addition. Bound input before expansion, and output before append. Never interpolate input values, unknown keys, discriminators or paths into diagnostics. Do not log PHP traces or parsed records.

For behavior changes, add an independent wire-format or adversarial regression and update both READMEs and the bilingual contract/design sections. Do not weaken existing fixture assertions. Preserve trailing spaces in positional fixtures. CI performs tests and an offline demo only, not publication or deployment.

## Português (Brasil)

Use issues e pull requests para melhorias e erros não sensíveis. Para vulnerabilidades, siga [SECURITY.md](SECURITY.md). Contato geral pelo [site da Lofrano Tecnologia](https://lofranotecnologia.com.br).

Com PHP 8.4 de 64 bits e JSON/PCRE nativos, execute os três comandos acima. Mantenha zero dependências externas em tempo de execução. Envie apenas código original e fixtures sintéticas, sem dados pessoais, credenciais, código privado de clientes ou manuais financeiros proprietários.

Não use floats para valores/controles; verifique overflow antes da soma. Limite entrada antes de expandir e saída antes de anexar. Não interpole valores, chaves desconhecidas, discriminadores ou caminhos nos diagnósticos. Não registre traces PHP nem registros decodificados.

Mudanças de comportamento precisam de regressões independentes/adversariais e atualização dos dois READMEs e das seções bilíngues de contrato/design. Não enfraqueça as verificações existentes. Preserve espaços finais das fixtures posicionais. A CI testa e demonstra offline, sem publicar ou implantar.
