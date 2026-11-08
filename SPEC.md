# Especificação comum dos SDKs do NexoraSMS (C#, Java, Node.js, Go, PHP)

Os quatro SDKs têm a mesma forma e o mesmo comportamento, adaptados aos costumes de cada linguagem.
A API está descrita para integradores em https://nexorasms.e258tech.tech/docs/api (no repositório do NexoraSMS: `docs/INTEGRACAO_API.md`, fonte de verdade dos formatos). Repositório público dos SDKs: https://github.com/Eleuterio258/nexorasms-sdks.
Versão inicial de todos: **0.1.0**. Licença **MIT** (`LICENSE`, «Copyright (c) 2026 E258Tech») em cada SDK. Maven: `groupId` `tech.e258tech`, pacote `tech.e258tech.nexorasms`. Documentação (README) em português de Portugal; código e comentários em inglês.

## 1. Padrão builder

- **Cliente:** criado só por builder, imutável e seguro entre threads depois de construído.
  - `publicKey` (obrigatória, começa por `e258_nex_sms_pk_`), `apiKey` (obrigatória, começa por `e258_nex_sms_ak_`).
  - `baseUrl` (por omissão `https://sms.e258tech.tech`, sem `/` final), `timeout` (por omissão 30 s),
    `maxRetries` (por omissão 2; 0 desliga), `userAgent` extra (acrescentado ao do SDK), cliente HTTP próprio (opcional).
  - `build()` valida e falha com erro de configuração (mensagem clara) se faltar uma chave ou o prefixo estiver errado.
- **Pedidos:** cada pedido com vários campos tem um builder com validação no `build()`:
  - `SendMessageRequest` (envio directo), `ContactRequest` (criar/actualizar contacto), `MessageQuery`, `ContactQuery`.
- Os resultados são objectos/records só de leitura.

## 2. Autenticação e cabeçalhos

Todos os pedidos: `X-Public-Key`, `X-Api-Key`, `Accept: application/json`,
`User-Agent: nexorasms-<lang>/0.1.0[ <extra>]` (`<lang>` = `dotnet`, `java`, `node`, `go`).
Com corpo: `Content-Type: application/json`. JSON sempre em `snake_case`; campos nulos não são enviados.

## 3. Recursos e métodos

Nomes indicativos (seguir a convenção da linguagem: `SendAsync` em C#, `send` em Java/Node, `Send(ctx, …)` em Go).

| Método | HTTP | Notas |
|---|---|---|
| `messages.send(SendMessageRequest)` | `POST /api/v1/messages` | Envia `Idempotency-Key` (ver 5). Resposta `201`: `SendMessageResult`. |
| `messages.estimate(SendMessageRequest)` | `POST /api/v1/messages/estimate` | Sem Idempotency-Key. `EstimateResult`. |
| `messages.get(id)` | `GET /api/v1/messages/{id}` | `Message`. |
| `messages.list(MessageQuery?)` | `GET /api/v1/messages?campaign_id=&status=&channel=&search=&page=&page_size=` | `Page<Message>`. |
| `contacts.upsertByExternalId(externalId, ContactRequest)` | `PUT /api/v1/contacts/external/{externalId}` (externalId com URL-encoding) | `Contact` (200 ou 201). |
| `contacts.create(ContactRequest)` | `POST /api/v1/contacts` | `Contact`. |
| `contacts.get(id)` | `GET /api/v1/contacts/{id}` | `Contact`. |
| `contacts.update(id, ContactRequest)` | `PATCH /api/v1/contacts/{id}` | `Contact`. |
| `contacts.list(ContactQuery?)` | `GET /api/v1/contacts?search=&group_id=&tag=&status=&page=&page_size=` | `Page<Contact>`. |
| `contacts.recordConsent(id, channel, status, source)` | `POST /api/v1/contacts/{id}/consents` `{channel,status,source}` | `Contact`. |
| `wallet.get()` | `GET /api/v1/wallet` | `Wallet`. |
| `wallet.transactions(page?, pageSize?)` | `GET /api/v1/wallet/transactions?page=&page_size=` | `Page<WalletTransaction>`. |
| `campaigns.get(id)` | `GET /api/v1/campaigns/{id}` | `Campaign`. |
| `campaigns.stats(id)` | `GET /api/v1/campaigns/{id}/stats` | `CampaignStats`. |

Parâmetros de query nulos não são enviados.

## 4. Builders dos pedidos

### SendMessageRequest (corpo JSON de `POST /api/v1/messages` e `/estimate`)

```json
{
  "channels": [
    { "channel": "sms", "content": "…", "sender": "MINHALOJA" },
    { "channel": "email", "subject": "…", "content": "<p>…</p>", "sender": "noreply@loja.co.mz" },
    { "channel": "whatsapp", "content": "…", "whatsapp_template": { "name": "aviso", "language": "pt_PT", "parameters": ["Ana"] } }
  ],
  "recipients": ["+258841234567", "ana@loja.co.mz"],
  "contact_ids": ["uuid"],
  "ignore_quiet_hours": true
}
```

Métodos do builder:
- `sms(content)` e `sms(content, sender)`; `email(subject, content)` e `email(subject, content, sender)`;
  `whatsapp(content)`; `whatsappTemplate(name, language, params...)` (canal whatsapp com template; `content` opcional).
- `to(recipient)` / `to(recipients...)` (acumula), `contact(id)` / `contacts(ids...)` (acumula), `ignoreQuietHours(bool)`,
  `idempotencyKey(string)`.
- Validação no `build()`: 1 a 3 canais, sem canais repetidos; pelo menos um destinatário ou contacto; no máximo 1000
  de cada; SMS e email exigem `content` não vazio; email exige `subject`; WhatsApp exige `content` ou template;
  `idempotencyKey` (se indicada) com 1 a 200 caracteres ASCII visíveis (0x21–0x7E).
- O SDK **não** valida o formato dos telefones (a API devolve-os em `invalid`).

### ContactRequest (corpo de criar, upsert e PATCH)

Campos: `name`, `phone`, `email`, `status` (`active`/`inactive`; só faz sentido no update), `group_ids`, `tags`,
`attributes` (mapa texto → texto; valor nulo = remover no update), `external_id`.
Métodos: `name()`, `phone()`, `email()`, `status()`, `groupId(id)`/`groupIds(...)`, `tag(t)`/`tags(...)`,
`attribute(key, value)`/`attributes(map)`, `externalId()`. Só os campos definidos vão no JSON (no update, não enviar
um campo = não mexer). `tags`/`group_ids` só vão se o utilizador os definir (lista vazia explícita = limpar).

### MessageQuery / ContactQuery

`MessageQuery`: `campaignId`, `status`, `channel`, `search`, `page`, `pageSize` (1–200).
`ContactQuery`: `search`, `groupId`, `tag`, `status`, `page`, `pageSize` (1–200).

## 5. Idempotência e repetições

- `messages.send`: usa a `idempotencyKey` do pedido ou gera um UUID v4 **uma vez por chamada**; a mesma chave é
  reutilizada em todas as repetições dessa chamada. O resultado expõe a chave usada e se veio repetido
  (cabeçalho de resposta `Idempotent-Replayed: true`).
- Repetir (até `maxRetries`) quando: erro de rede/timeout; `429`; `502`, `503`, `504`; `409` **com** `Retry-After`
  (pedido idempotente ainda em processamento). Nunca repetir outros 4xx nem `500`.
  POST/PUT/PATCH só se repetem se forem idempotentes: `messages.send` (tem chave), `estimate` (sem efeitos), `PUT` upsert.
  `POST /contacts`, `PATCH` e consents **não** se repetem em erro de rede.
- Espera: `Retry-After` (segundos) se existir (máx. 30 s); senão 0,5 s × 2^tentativa com jitter (±20 %), máx. 8 s.

## 6. Erros

Resposta de erro = ProblemDetails: `{ "type", "title", "status", "detail", "traceId", "errors": { "campo": ["msg"] } }`.
Uma excepção/erro base (`NexoraSmsException` / `NexoraSmsError` / `*nexorasms.APIError`) com: `status` (int),
`title`, `detail`, `traceId`, `errors` (mapa), `retryAfter` (opcional), e a mensagem = `title` (ou `detail`, ou
`HTTP <status>`). Tipos (ou códigos, em Go, com funções/`errors.As`):

| Estado | Tipo |
|---|---|
| 400 | `ValidationError` |
| 401 | `AuthenticationError` |
| 402 | `InsufficientFundsError` |
| 403 | `PermissionDeniedError` |
| 404 | `NotFoundError` |
| 409 | `ConflictError` |
| 429 | `RateLimitError` |
| 5xx | `ServerError` |
| rede/timeout | `ConnectionError` (sem status) |

Erros de configuração ou de validação do builder: erro próprio da linguagem (`ArgumentException`,
`IllegalArgumentException`/`IllegalStateException`, `TypeError`/`RangeError`, `error` em Go), nunca um pedido HTTP.

## 7. Modelos (JSON → tipo; nomes em camelCase/PascalCase conforme a linguagem)

Dinheiro: C# `decimal`, Java `BigDecimal`, Node `number`, Go `float64`. Datas: C# `DateTimeOffset`, Java
`OffsetDateTime`, Node `Date` ou string ISO (escolher `string` ISO para não perder precisão, documentar), Go `time.Time`.
Ids: string (UUID). Enums como texto em minúsculas `snake_case` (ex.: `in_flight`); em C#/Java usar enums com valor
desconhecido tolerado (`Unknown`/`UNRECOGNIZED`) para não partir quando a API acrescentar valores — ou strings com constantes.
Campos desconhecidos no JSON são ignorados.

- `SendMessageResult`: `channels[]` { `channel`, `campaign_id`, `recipients` (int), `cost`, `status` }, `invalid[]` (string),
  mais `idempotencyKey` e `replayed` (do cliente, não do JSON).
- `EstimateResult`: `channels[]` { `channel`, `recipients`, `segments`, `encoding` (`gsm7`/`ucs2`/`utf8`), `unit_price`, `cost` },
  `total`, `available`, `invalid[]`.
- `Message`: `id`, `campaign_id`, `channel`, `destination`, `status` (`pending`, `processing`, `accepted`, `delivered`,
  `failed`, `unknown`, `cancelled`), `cost`, `parts` (int), `created_at`, `accepted_at?`, `delivered_at?`,
  `attempts[]` { `part_number`, `attempt_number`, `status` (`in_flight`, `accepted`, `rejected`, `failed`, `unknown`),
  `provider_message_id?`, `error_code?`, `started_at`, `completed_at?` }, `campaign_name?`, `sender?`, `subject?`,
  `content?`, `template?`, `language?`.
- `Contact`: `id`, `name?`, `phone?`, `email?`, `status` (`active`/`inactive`), `group_ids[]`, `tags[]`,
  `consents[]` { `channel`, `status` (`granted`/`revoked`), `source`, `recorded_at` }, `created_at`, `attributes` (mapa), `external_id?`.
- `Page<T>`: `items[]`, `page`, `page_size`, `total`.
- `Wallet`: `currency`, `balance`, `reserved`, `available`.
- `WalletTransaction`: `id`, `type` (`credit`, `debit`, `reserve`, `release`, `refund`, `withdrawal`), `amount`,
  `business_reference`, `message_id?`, `reversal_of_id?`, `created_at`.
- `Campaign`: `id`, `name`, `channel`, `status` (`draft`, `scheduled`, `running`, `paused`, `completed`, `cancelled`,
  `failed`), `template_id?`, `subject?`, `content`, `recipients`, `scheduled_at?`, `created_at`, `ignore_quiet_hours`,
  `pause_reason?`, `sender?`, `messages?`, `delivered?`, `failed?` (int).
- `CampaignStats`: `campaign_id`, `status`, `recipients`, `messages` (mapa estado → int), `sms_segments`, `currency`,
  `estimated_cost`, `reserved`, `debited`, `clicks`, `unique_clicks`.

Canais: `sms`, `whatsapp`, `email`.

## 8. Webhooks

`WebhookVerifier` construído com o segredo (`whsec_…`) e tolerância (por omissão 300 s; builder ou parâmetro opcional).
`verify(rawBody, signatureHeader)` (Go: `Verify(body []byte, header string) (*WebhookEvent, error)`):
- cabeçalho `X-Nexora-Signature: t=<unix>,v1=<hex>`; partes separadas por `,`, cada uma `chave=valor`;
- esperado = hex minúsculo de HMAC-SHA256(segredo UTF-8, `"<t>.<rawBody>"`); comparar em tempo constante;
- falhar se faltar `t` ou `v1`, se a assinatura não bater, ou se `|agora − t| > tolerância`;
- devolve `WebhookEvent` { `id`, `type`, `created_at`, `data` (JSON bruto/objecto genérico) } com helpers para ler
  `data` como `MessageEvent` { `message_id`, `campaign_id`, `contact_id`, `external_id?`, `channel`, `status`, `cost`,
  `occurred_at` } quando `type` é `message.delivered`/`message.failed`;
- erro próprio `WebhookSignatureError`/`WebhookVerificationException`.
- Constantes dos tipos: `message.delivered`, `message.failed`, `campaign.completed`, `campaign.paused`,
  `contact.opted_out`, `ping`.
- Permitir injectar o relógio (para testes).

## 9. Testes e entrega

- Testes unitários com servidor HTTP falso local (nunca chamar produção): cabeçalhos de autenticação, JSON
  snake_case enviado, Idempotency-Key gerada e reutilizada nas repetições, repetição em 503/429 com `Retry-After`,
  sem repetição em 400/402, mapeamento de erros (402 → InsufficientFunds com `title`), paginação/query string,
  URL-encoding do external_id, validação dos builders, verificação de webhooks (válido, assinatura errada, expirado,
  cabeçalho mal formado) com um vector fixo:
  - segredo `whsec_test`, `t=1762597200`, corpo `{"id":"evt_1","type":"ping","created_at":"2026-11-08T10:20:00Z","data":{"message":"ping"}}`
  - assinatura v1 esperada: `cde593b98434dd7cd2944443131e9bf1bf47bc37630a621aa9481d76ab38b77e`
    (cabeçalho `t=1762597200,v1=cde593b9…b77e`; o teste fixa o relógio em 1762597200).
- README.md por SDK, em português: instalação (a partir do repositório; publicação nos registos fica para depois),
  configuração com builder, enviar SMS, enviar SMS+email, estimar, upsert de contacto, consultar mensagens,
  tratar erros (402, 429), webhooks, idempotência.
- Sem dependências externas sempre que possível (Java pode usar Jackson).
