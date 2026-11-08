# NexoraSMS — SDK para Java

SDK oficial do NexoraSMS para Java 17+: envio de SMS, WhatsApp e email, contactos, carteira, campanhas e
verificação de webhooks. Usa `java.net.http.HttpClient` e Jackson; não tem outras dependências.

- Documentação da API: <https://nexorasms.e258tech.tech/docs/api>
- Licença: MIT (ver `LICENSE`)

## Instalação

A publicação no Maven Central fica para depois. Por agora, instale a partir do repositório:

```bash
git clone https://github.com/Eleuterio258/nexorasms-sdks.git
cd nexorasms-sdks/java
mvn -B install
```

E acrescente a dependência ao seu `pom.xml`:

```xml
<dependency>
  <groupId>tech.e258tech</groupId>
  <artifactId>nexorasms-sdk</artifactId>
  <version>0.1.0</version>
</dependency>
```

Gradle: `implementation("tech.e258tech:nexorasms-sdk:0.1.0")` (com `mavenLocal()` nos repositórios).

## Configuração

O cliente é criado só por builder. Depois de construído é imutável e pode (e deve) ser partilhado entre threads.
Crie-o uma vez, por exemplo como singleton da aplicação.

```java
import java.time.Duration;
import tech.e258tech.nexorasms.NexoraSmsClient;

NexoraSmsClient client = NexoraSmsClient.builder()
    .publicKey(System.getenv("NEXORASMS_PUBLIC_KEY"))   // e258_nex_sms_pk_…  (obrigatória)
    .apiKey(System.getenv("NEXORASMS_API_KEY"))         // e258_nex_sms_ak_…  (obrigatória, só no servidor)
    .timeout(Duration.ofSeconds(15))                    // por tentativa; por omissão 30 s
    .maxRetries(2)                                      // por omissão 2; 0 desliga as repetições
    .userAgent("loja-online/2.3")                       // acrescentado a "nexorasms-java/0.1.0"
    .build();
```

Outras opções: `baseUrl(...)` (por omissão `https://sms.e258tech.tech`; útil para um ambiente de testes) e
`httpClient(...)` para usar o seu próprio `HttpClient` (proxy, TLS, executor).

`build()` lança `IllegalStateException` se faltar uma chave ou se o prefixo estiver errado. Os setters lançam
`IllegalArgumentException` para valores inválidos (timeout não positivo, `maxRetries` negativo, URL inválido).
Nenhum destes erros faz pedidos HTTP.

> Nunca coloque a `apiKey` numa aplicação móvel, num browser ou num repositório.

## Enviar SMS

```java
import tech.e258tech.nexorasms.SendMessageRequest;
import tech.e258tech.nexorasms.model.SendMessageResult;

SendMessageResult result = client.messages().send(
    SendMessageRequest.builder()
        .sms("A sua encomenda 1042 foi enviada.", "MINHALOJA")   // Sender ID aprovado (opcional)
        .to("+258841234567")
        .build());

result.channels().forEach(c ->
    System.out.println(c.channel() + " → campanha " + c.campaignId() + ", custo " + c.cost()));
System.out.println("Inválidos: " + result.invalid());
```

O SDK não valida o formato dos números: os destinatários que a API não aceita vêm em `result.invalid()`.

## Enviar SMS e email ao mesmo tempo

Até 3 canais por pedido (cada canal no máximo uma vez); a API cria uma campanha por canal.

```java
SendMessageResult result = client.messages().send(
    SendMessageRequest.builder()
        .sms("Olá Ana, a sua factura FT 2026/118 vence amanhã.")
        .email("Factura FT 2026/118", "<p>Olá Ana,</p><p>A sua factura vence amanhã.</p>", "facturas@loja.co.mz")
        .to("+258841234567", "ana@loja.co.mz")
        .contact("8c1f…")                 // ids de contactos existentes (acumula)
        .ignoreQuietHours(true)
        .build());
```

WhatsApp: `.whatsapp("texto")` (só nas 24 h seguintes à última mensagem do cliente) ou
`.whatsappTemplate("aviso_factura", "pt_PT", "Ana", "FT 2026/118")` com um template aprovado na Meta.

Validações feitas no `build()` (lançam `IllegalStateException`): 1 a 3 canais sem repetições; pelo menos um
destinatário ou contacto; no máximo 1000 de cada; SMS e email com conteúdo; email com assunto; WhatsApp com
conteúdo ou template.

## Estimar o custo

Não envia nada: devolve segmentos, codificação, preço e o saldo disponível.

```java
import tech.e258tech.nexorasms.model.EstimateResult;

EstimateResult estimate = client.messages().estimate(
    SendMessageRequest.builder().sms("Promoção de Natal: -20% em tudo!").to("+258841234567").build());

estimate.channels().forEach(c ->
    System.out.println(c.channel() + ": " + c.segments() + " segmento(s), " + c.encoding() + ", " + c.cost()));
if (estimate.total().compareTo(estimate.available()) > 0) System.out.println("Saldo insuficiente.");
```

## Contactos: upsert pelo id do seu sistema

```java
import tech.e258tech.nexorasms.ContactRequest;
import tech.e258tech.nexorasms.model.Contact;
import tech.e258tech.nexorasms.model.Channel;
import tech.e258tech.nexorasms.model.ConsentStatus;

Contact contact = client.contacts().upsertByExternalId("CLI-0042",
    ContactRequest.builder()
        .name("Ana Machava")
        .phone("+258841234567")
        .email("ana@loja.co.mz")
        .tags("vip", "maputo")                       // substitui as etiquetas; tags() sem argumentos limpa
        .attribute("data_vencimento", "2026-12-01")
        .attribute("campo_antigo", null)             // null remove o atributo
        .build());

client.contacts().recordConsent(contact.id(), Channel.SMS, ConsentStatus.REVOKED, "erp");
```

Só os campos definidos no builder são enviados: num `update(id, request)` (PATCH), um campo não definido fica
como está. Também existem `create`, `get`, `update` e `list(ContactQuery)`.

O `upsertByExternalId` repete-se em falhas transitórias (é idempotente); `create`, `update` e `recordConsent`
**não** se repetem.

## Consultar mensagens

```java
import tech.e258tech.nexorasms.MessageQuery;
import tech.e258tech.nexorasms.model.Message;
import tech.e258tech.nexorasms.model.MessageStatus;
import tech.e258tech.nexorasms.model.Page;

Page<Message> page = client.messages().list(MessageQuery.builder()
    .campaignId(result.channels().get(0).campaignId())
    .status(MessageStatus.FAILED)
    .page(1)
    .pageSize(100)                                   // 1 a 200
    .build());

for (Message m : page.items()) {
  System.out.println(m.destination() + " " + m.status() + " " + m.createdAt());
  m.attempts().forEach(a -> System.out.println("  tentativa " + a.attemptNumber() + ": " + a.errorCode()));
}
if (page.hasNext()) { /* pedir page + 1 */ }

Message one = client.messages().get("3f2a…");
```

Carteira e campanhas: `client.wallet().get()`, `client.wallet().transactions(1, 50)`,
`client.campaigns().get(id)`, `client.campaigns().stats(id)`.

Notas sobre os modelos: são `record`s imutáveis; dinheiro em `BigDecimal`; datas em `OffsetDateTime`; estados
são enums e um valor novo que a API venha a acrescentar aparece como `UNRECOGNIZED` em vez de dar erro.
Campos desconhecidos no JSON são ignorados.

## Tratar erros

Todos os erros da API e de rede são `NexoraSmsException` (unchecked), com `status()`, `title()`, `detail()`,
`traceId()`, `errors()` e `retryAfter()`. A mensagem é o `title` da resposta.

| Estado | Excepção |
|---|---|
| 400 | `ValidationException` (ver `errors()`) |
| 401 | `AuthenticationException` |
| 402 | `InsufficientFundsException` |
| 403 | `PermissionDeniedException` |
| 404 | `NotFoundException` |
| 409 | `ConflictException` |
| 429 | `RateLimitException` |
| 5xx | `ServerException` |
| rede/timeout | `ConnectionException` (`status()` = 0) |

```java
import tech.e258tech.nexorasms.error.*;

try {
  client.messages().send(request);
} catch (InsufficientFundsException e) {          // 402
  avisarFinanceiro(e.getMessage());               // "Saldo insuficiente para este envio."
} catch (RateLimitException e) {                   // 429, depois das repetições automáticas
  Duration espera = e.retryAfter().orElse(Duration.ofSeconds(30));
  reagendar(request, espera);
} catch (ValidationException e) {
  e.errors().forEach((campo, msgs) -> log.warn("{}: {}", campo, msgs));
} catch (ConnectionException e) {
  // sem resposta: pode repetir com a MESMA idempotencyKey sem risco de envio duplicado
} catch (NexoraSmsException e) {
  log.error("NexoraSMS {} (traceId {})", e.status(), e.traceId(), e);
}
```

**Repetições automáticas** (até `maxRetries`): erro de rede/timeout, `429`, `502`, `503`, `504` e `409` com
`Retry-After`. Espera o `Retry-After` (máx. 30 s) ou 0,5 s × 2^tentativa com ±20 % de aleatoriedade (máx. 8 s).
Nunca repete outros 4xx nem `500`.

## Idempotência

Cada `messages().send(...)` leva um cabeçalho `Idempotency-Key`. Se não indicar nenhuma, o SDK gera um UUID v4
por chamada e reutiliza-o em todas as repetições dessa chamada — por isso uma falha de rede nunca dá envio
duplicado. Para ficar protegido também entre execuções da sua aplicação, use uma chave do seu negócio:

```java
SendMessageResult result = client.messages().send(
    SendMessageRequest.builder()
        .sms("Pagamento recebido. Obrigado!")
        .to("+258841234567")
        .idempotencyKey("pagamento-" + pagamentoId)   // 1 a 200 caracteres ASCII visíveis
        .build());

result.idempotencyKey();   // a chave usada
result.replayed();         // true se a API devolveu a resposta guardada de um pedido anterior
```

## Webhooks

Verifique sempre a assinatura com o corpo **bruto**, tal como chegou, antes de o interpretar.

```java
import tech.e258tech.nexorasms.error.WebhookVerificationException;
import tech.e258tech.nexorasms.webhook.*;

WebhookVerifier verifier = new WebhookVerifier(System.getenv("NEXORASMS_WEBHOOK_SECRET"));   // whsec_…
// ou: WebhookVerifier.builder().secret(...).tolerance(Duration.ofMinutes(10)).build()

// Exemplo com Spring: @PostMapping("/webhooks/nexorasms")
public ResponseEntity<Void> receber(@RequestBody byte[] body,
    @RequestHeader(WebhookVerifier.SIGNATURE_HEADER) String signature) {
  WebhookEvent event;
  try {
    event = verifier.verify(body, signature);
  } catch (WebhookVerificationException e) {
    return ResponseEntity.badRequest().build();
  }
  if (event.isMessageEvent()) {                         // message.delivered / message.failed
    MessageEvent m = event.asMessageEvent();
    actualizarEstado(m.messageId(), m.externalId(), m.status(), m.occurredAt());
  } else if (WebhookEventType.CONTACT_OPTED_OUT.equals(event.type())) {
    String contactId = event.data().get("contact_id").asText();
  }
  return ResponseEntity.ok().build();
}
```

O cabeçalho `X-Nexora-Signature` tem a forma `t=<unix>,v1=<hex>`; o SDK calcula HMAC-SHA256 do segredo sobre
`"<t>.<corpo>"`, compara em tempo constante e rejeita eventos com mais de 300 s de diferença (configurável).
Os eventos podem chegar repetidos ou fora de ordem: use `event.id()` para ignorar duplicados e `occurredAt`.
Constantes dos tipos em `WebhookEventType`: `message.delivered`, `message.failed`, `campaign.completed`,
`campaign.paused`, `contact.opted_out`, `ping`.

## Desenvolvimento

```bash
docker run --rm -v "$PWD":/src -w /src maven:3.9-eclipse-temurin-17 mvn -B verify
```

Os testes usam um servidor HTTP local (`com.sun.net.httpserver`) e nunca contactam a API de produção.
