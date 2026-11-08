# @e258tech/nexorasms — SDK Node.js

SDK oficial do NexoraSMS para Node.js 18 ou superior: envio de SMS, WhatsApp e email, contactos, estado das
mensagens, carteira e verificação de webhooks. Sem dependências. Inclui tipos TypeScript.

Documentação completa da API: https://nexorasms.e258tech.tech/docs/api

## Instalação

Enquanto o pacote não está publicado no npm, instale-o a partir do repositório:

```bash
npm install ./caminho/para/nexorasms-sdks/node
```

## Configuração (builder)

Crie as chaves no painel, em **Configurações → API e webhooks → Nova chave** (permissões «Operador»). Guarde-as em
variáveis de ambiente, nunca no código nem no navegador.

```js
import { NexoraSmsClient } from '@e258tech/nexorasms'

const nexora = NexoraSmsClient.builder()
  .publicKey(process.env.NEXORASMS_PUBLIC_KEY)   // e258_nex_sms_pk_…
  .apiKey(process.env.NEXORASMS_API_KEY)         // e258_nex_sms_ak_…
  .timeout(15_000)                               // opcional (por omissão 30 s)
  .maxRetries(2)                                 // opcional (por omissão 2)
  .userAgent('loja-online/2.3')                  // opcional
  .build()
```

O cliente é imutável e pode ser partilhado por toda a aplicação.

## Enviar um SMS

```js
import { SendMessageRequest } from '@e258tech/nexorasms'

const result = await nexora.messages.send(
  SendMessageRequest.builder()
    .sms('A sua encomenda 58231 foi confirmada. Obrigado!')   // .sms(texto, 'MINHALOJA') com Sender ID aprovado
    .to('+258841234567')
    .idempotencyKey('encomenda-58231-confirmacao')
    .build(),
)

console.log(result.channels[0].campaignId, result.channels[0].cost, result.invalid)
```

## SMS e email ao mesmo tempo, para contactos existentes

```js
const request = SendMessageRequest.builder()
  .sms('Olá {{nome|Cliente}}, a fatura {{fatura}} vence amanhã.')
  .email('Fatura a vencer', '<p>Olá {{nome|Cliente}},</p><p>A fatura {{fatura}} vence amanhã.</p>')
  .contacts('6f1c…', '9a2d…')
  .build()

const estimate = await nexora.messages.estimate(request)   // não envia nada
if (estimate.total <= estimate.available) await nexora.messages.send(request)
```

WhatsApp com template aprovado na Meta: `.whatsappTemplate('aviso_fatura', 'pt_PT', 'Ana', 'FT 2026/123')`.
Códigos e avisos urgentes: `.ignoreQuietHours()`.

## Contactos

```js
import { ContactRequest, ContactQuery } from '@e258tech/nexorasms'

// Cria ou actualiza pelo id do seu sistema
const contact = await nexora.contacts.upsertByExternalId('CLI-0042',
  ContactRequest.builder()
    .name('Ana Matola')
    .phone('841234567')
    .email('ana@exemplo.co.mz')
    .tags('cliente', 'maputo')
    .attribute('fatura', 'FT 2026/123')
    .build())

await nexora.contacts.recordConsent(contact.id, 'sms', 'revoked', 'erp')
const vip = await nexora.contacts.list(ContactQuery.builder().tag('vip').pageSize(100).build())
```

## Estado das mensagens

```js
import { MessageQuery } from '@e258tech/nexorasms'

const page = await nexora.messages.list(MessageQuery.builder().campaignId(result.channels[0].campaignId).build())
for (const message of page.items) console.log(message.destination, message.status)   // delivered, failed…
```

Também: `nexora.messages.get(id)`, `nexora.wallet.get()`, `nexora.wallet.transactions(page, pageSize)`,
`nexora.campaigns.get(id)`, `nexora.campaigns.stats(id)`.

## Erros

```js
import { InsufficientFundsError, RateLimitError, ValidationError, NexoraSmsError, ConnectionError } from '@e258tech/nexorasms'

try {
  await nexora.messages.send(request)
} catch (error) {
  if (error instanceof InsufficientFundsError) console.log('Sem saldo: carregue a carteira.')
  else if (error instanceof RateLimitError) console.log(`Tente daqui a ${error.retryAfter} s.`)
  else if (error instanceof ValidationError) console.log(error.message, error.errors)
  else if (error instanceof ConnectionError) console.log('Sem ligação ao NexoraSMS.')
  else if (error instanceof NexoraSmsError) console.log(error.status, error.message, error.traceId)
  else throw error
}
```

| Erro | Estado HTTP |
|---|---|
| `ValidationError` | 400 |
| `AuthenticationError` | 401 (chaves erradas ou revogadas) |
| `InsufficientFundsError` | 402 |
| `PermissionDeniedError` | 403 |
| `NotFoundError` | 404 |
| `ConflictError` | 409 |
| `RateLimitError` | 429 |
| `ServerError` | 5xx |
| `ConnectionError` | rede ou tempo limite |

Os builders validam os pedidos antes de chamar a API e lançam `TypeError`/`RangeError` com a explicação.

## Idempotência e repetições

- `messages.send` envia sempre um `Idempotency-Key`: o seu (`.idempotencyKey(...)`, recomendado: ligado à operação do
  seu sistema) ou um UUID gerado. A mesma chave é usada em todas as repetições, por isso uma falha de rede nunca
  duplica o envio. `result.replayed` é `true` quando o NexoraSMS devolveu o resultado de um pedido anterior igual.
- O SDK repete automaticamente (até `maxRetries`) em erros de rede, `429`, `502`, `503`, `504` e `409` com
  `Retry-After`, respeitando o `Retry-After`. Criar contacto (`POST`), actualizar (`PATCH`) e consentimentos não se repetem.

## Webhooks

```js
import express from 'express'
import { WebhookVerifier, WebhookSignatureError, isMessageEvent } from '@e258tech/nexorasms'

const verifier = new WebhookVerifier(process.env.NEXORASMS_WEBHOOK_SECRET)   // whsec_…

app.post('/webhooks/nexorasms', express.raw({ type: 'application/json' }), (req, res) => {
  let event
  try {
    event = verifier.verify(req.body, req.get('X-Nexora-Signature'))   // corpo bruto, tal como chegou
  } catch (error) {
    if (error instanceof WebhookSignatureError) return res.sendStatus(401)
    throw error
  }
  res.sendStatus(204)                       // responda depressa; processe depois
  if (isMessageEvent(event)) atualizarEstado(event.data.externalId, event.data.status)   // ignore ids repetidos
})
```

## Testes

```bash
npm test
```

Os modelos devolvidos usam camelCase (`campaignId`, `createdAt`). As datas vêm como texto ISO 8601 (UTC) e os
valores em número, na moeda da carteira. Os nomes dos campos personalizados (`attributes`) mantêm-se como foram definidos.
