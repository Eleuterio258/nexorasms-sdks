# SDKs do NexoraSMS

Bibliotecas oficiais para integrar outros sistemas com a API do NexoraSMS. Todas seguem a mesma especificação
([SPEC.md](SPEC.md)) e o padrão **builder**, tanto na configuração do cliente como na construção dos pedidos.

| Linguagem | Pasta | Pacote |
|---|---|---|
| C# / .NET 8+ | [dotnet](dotnet) | `E258Tech.NexoraSms` |
| Java 17+ | [java](java) | `tech.e258:nexorasms-sdk` |
| Node.js 18+ (com tipos TypeScript) | [node](node) | `@e258tech/nexorasms` |
| Go 1.22+ | [go](go) | `github.com/Eleuterio258/NexoraSMS/sdks/go` |

O que todas fazem:

- autenticação com o par de chaves (`X-Public-Key` `e258_nex_sms_pk_…` + `X-Api-Key` `e258_nex_sms_ak_…`);
- envio directo de SMS, WhatsApp e email (`messages.send`) e estimativa de custo (`messages.estimate`);
- contactos (criar, actualizar, upsert pelo id do seu sistema, listar, consentimento);
- estado das mensagens, carteira e campanhas;
- `Idempotency-Key` automática e repetições seguras (rede, 429, 502–504);
- erros tipados (saldo insuficiente, validação, autenticação…);
- verificação da assinatura dos webhooks (`X-Nexora-Signature`).

Guia da API: [../docs/INTEGRACAO_API.md](../docs/INTEGRACAO_API.md) · https://nexorasms.e258tech.tech/docs/api

Exemplo (Node.js):

```js
const nexora = NexoraSmsClient.builder().publicKey(pk).apiKey(ak).build()
await nexora.messages.send(SendMessageRequest.builder().sms('Olá!').to('+258841234567').build())
```

As bibliotecas ainda não estão publicadas nos registos (NuGet, Maven Central, npm); instalam-se a partir deste
repositório, como explica o README de cada uma.
