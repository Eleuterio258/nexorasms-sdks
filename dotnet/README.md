# NexoraSMS — SDK para .NET

SDK oficial do [NexoraSMS](https://nexorasms.e258tech.tech/docs/api) para .NET 8 ou superior: envio de SMS, WhatsApp e
email, contactos, carteira, campanhas e verificação de webhooks.

- Sem dependências externas (só `System.Text.Json` e `HttpClient`).
- API assíncrona com `CancellationToken`, cliente imutável e seguro entre threads.
- Repetições automáticas com a mesma `Idempotency-Key` (um envio repetido nunca é enviado duas vezes).
- Licença MIT.

## Instalação

A publicação no NuGet fica para depois. Por agora, a partir do repositório:

**Referência ao projecto** (mais simples):

```bash
git clone https://github.com/Eleuterio258/nexorasms-sdks.git
dotnet add <o-seu-projecto>.csproj reference nexorasms-sdks/dotnet/src/E258Tech.NexoraSms/E258Tech.NexoraSms.csproj
```

**Pacote local**:

```bash
cd nexorasms-sdks/dotnet
dotnet pack src/E258Tech.NexoraSms -c Release -o ./pacotes
dotnet nuget add source "$PWD/pacotes" --name nexorasms-local
dotnet add <o-seu-projecto>.csproj package E258Tech.NexoraSms --version 0.1.0
```

## Configuração

As chaves criam-se no painel, em **Configurações → API e webhooks → Nova chave**. A chave secreta (`X-Api-Key`) só
deve existir no servidor.

```csharp
using E258Tech.NexoraSms;

using var nexora = NexoraSmsClient.Builder()
    .PublicKey(Environment.GetEnvironmentVariable("NEXORASMS_PUBLIC_KEY")!) // e258_nex_sms_pk_…
    .ApiKey(Environment.GetEnvironmentVariable("NEXORASMS_API_KEY")!)       // e258_nex_sms_ak_…
    .Timeout(TimeSpan.FromSeconds(30))   // por tentativa (omissão: 30 s)
    .MaxRetries(2)                       // omissão: 2; 0 desliga
    .UserAgent("loja-online/2.3")        // opcional, acrescentado a "nexorasms-dotnet/0.1.0"
    .Build();
```

| Opção | Por omissão | Notas |
|---|---|---|
| `PublicKey` | — | Obrigatória, começa por `e258_nex_sms_pk_`. |
| `ApiKey` | — | Obrigatória, começa por `e258_nex_sms_ak_`. |
| `BaseUrl` | `https://sms.e258tech.tech` | A `/` final é removida. |
| `Timeout` | 30 s | Tempo limite de cada tentativa. |
| `MaxRetries` | 2 | Repetições em erros transitórios. |
| `UserAgent` | — | Texto extra no `User-Agent`. |
| `HttpClient` | criado pelo SDK | O seu `HttpClient` (por exemplo do `IHttpClientFactory`); o SDK não o liberta. |

`Build()` lança `InvalidOperationException` se faltar uma chave ou o prefixo estiver errado; os métodos do builder
lançam `ArgumentException` para valores inválidos. Nenhum destes erros faz pedidos HTTP.

Crie **um** cliente para toda a aplicação (por exemplo como singleton na injecção de dependências):

```csharp
builder.Services.AddSingleton(_ => NexoraSmsClient.Builder()
    .PublicKey(builder.Configuration["NexoraSms:PublicKey"]!)
    .ApiKey(builder.Configuration["NexoraSms:ApiKey"]!)
    .Build());
```

O cliente é `IDisposable`: ao ser libertado, liberta o `HttpClient` só se tiver sido ele a criá-lo.

## Enviar um SMS

```csharp
var resultado = await nexora.Messages.SendAsync(
    SendMessageRequest.Builder()
        .Sms("A sua fatura FT 2026/123 vence amanhã.", "MINHALOJA") // Sender ID aprovado (opcional)
        .To("+258841234567")
        .Build(),
    cancellationToken);

foreach (var canal in resultado.Channels)
    Console.WriteLine($"{canal.Channel}: campanha {canal.CampaignId}, {canal.Recipients} destinatário(s), custo {canal.Cost}");
foreach (var invalido in resultado.Invalid)
    Console.WriteLine($"Não enviado (inválido): {invalido}");
```

Os destinatários escritos têm de vir em E.164 (`+258…`) ou como endereço de email. O SDK não valida o formato dos
telefones: os que a API rejeitar vêm em `Invalid`.

## Enviar SMS e email ao mesmo tempo

Um envio pode ter de 1 a 3 canais (cada canal uma vez), até 1000 destinatários e até 1000 contactos:

```csharp
var pedido = SendMessageRequest.Builder()
    .Sms("Olá Ana, a sua encomenda saiu para entrega.", "MINHALOJA")
    .Email("Encomenda a caminho", "<p>Olá Ana, a sua encomenda saiu para entrega.</p>", "noreply@loja.co.mz")
    .To("+258841234567", "ana@loja.co.mz")
    .Contacts("6f1c…", "9a2d…")       // contactos já existentes (opcional)
    .IgnoreQuietHours()               // enviar mesmo nas horas de silêncio
    .Build();

var resultado = await nexora.Messages.SendAsync(pedido);
```

WhatsApp com template aprovado pela Meta (os parâmetros preenchem `{{1}}`, `{{2}}`…):

```csharp
var pedido = SendMessageRequest.Builder()
    .WhatsAppTemplate("aviso_fatura", "pt_PT", "Ana", "FT 2026/123")
    .To("+258841234567")
    .Build();
```

`Build()` valida o pedido e lança `InvalidOperationException` com a razão (sem canais, canal repetido, SMS sem texto,
email sem assunto, sem destinatários, mais de 1000…).

## Estimar o custo

Não envia nada:

```csharp
var estimativa = await nexora.Messages.EstimateAsync(pedido);
Console.WriteLine($"Total {estimativa.Total} de {estimativa.Available} disponíveis");
foreach (var canal in estimativa.Channels)
    Console.WriteLine($"{canal.Channel}: {canal.Segments} segmento(s) {canal.Encoding}, {canal.UnitPrice} cada");
```

## Contactos: criar ou actualizar pelo id do seu sistema

```csharp
var contacto = await nexora.Contacts.UpsertByExternalIdAsync("CLI-0042",
    ContactRequest.Builder()
        .Name("Ana Matola")
        .Phone("841234567")                 // sem indicativo = Moçambique
        .Email("ana@exemplo.co.mz")
        .Tags("cliente", "maputo")          // substitui as tags actuais
        .Attribute("fatura", "FT 2026/123")
        .Attribute("plano_antigo", null)    // null remove o campo
        .Build());
```

Só os campos definidos no builder são enviados: num `UpdateAsync` (PATCH), um campo que não definir não é alterado.
`Tags()` sem argumentos envia uma lista vazia, que limpa as tags.

```csharp
await nexora.Contacts.UpdateAsync(contacto.Id, ContactRequest.Builder().Status(ContactStatus.Inactive).Build());
await nexora.Contacts.RecordConsentAsync(contacto.Id, Channel.Sms, ConsentStatus.Revoked, "erp");

var vip = await nexora.Contacts.ListAsync(ContactQuery.Builder().Tag("vip").PageSize(100).Build());
```

## Consultar mensagens

```csharp
var pagina = await nexora.Messages.ListAsync(MessageQuery.Builder()
    .CampaignId(resultado.Channels[0].CampaignId)
    .Status(MessageStatus.Failed)
    .Page(1).PageSize(50)                    // 1 a 200
    .Build());

Console.WriteLine($"{pagina.Total} mensagens; página {pagina.PageNumber}");
foreach (var mensagem in pagina.Items)
    Console.WriteLine($"{mensagem.Destination}: {mensagem.Status}");

var detalhe = await nexora.Messages.GetAsync(pagina.Items[0].Id);    // inclui as tentativas (Attempts)
```

Também há `nexora.Wallet.GetAsync()`, `nexora.Wallet.TransactionsAsync(page, pageSize)`, `nexora.Campaigns.GetAsync(id)`
e `nexora.Campaigns.StatsAsync(id)`.

Os estados são enums (`MessageStatus`, `CampaignStatus`, …). Um valor que a API venha a acrescentar e que esta versão
do SDK não conheça aparece como `Unrecognized`, em vez de dar erro. Dinheiro vem em `decimal` e datas em
`DateTimeOffset`.

## Tratar erros

Todos os erros da API derivam de `NexoraSmsException`, com `Status`, `Title` (em português, pode ser mostrado ao
utilizador), `Detail`, `TraceId`, `Errors` (mensagens por campo) e `RetryAfter`.

| Estado | Excepção |
|---|---|
| 400 | `ValidationException` |
| 401 | `AuthenticationException` |
| 402 | `InsufficientFundsException` |
| 403 | `PermissionDeniedException` |
| 404 | `NotFoundException` |
| 409 | `ConflictException` |
| 429 | `RateLimitException` |
| 5xx | `ServerException` |
| rede / tempo limite | `ConnectionException` (`Status` nulo) |

```csharp
try
{
    await nexora.Messages.SendAsync(pedido);
}
catch (InsufficientFundsException e)   // 402: nada foi enviado
{
    logger.LogWarning("Saldo insuficiente: {Mensagem} (trace {TraceId})", e.Title, e.TraceId);
}
catch (RateLimitException e)           // 429: já repetido MaxRetries vezes
{
    var espera = e.RetryAfter ?? TimeSpan.FromSeconds(30);
    // agende uma nova tentativa daqui a `espera`, com a mesma IdempotencyKey
}
catch (ValidationException e)          // 400
{
    foreach (var (campo, mensagens) in e.Errors)
        logger.LogError("{Campo}: {Mensagens}", campo, string.Join("; ", mensagens));
}
catch (NexoraSmsException e)
{
    logger.LogError(e, "NexoraSMS falhou (HTTP {Status})", e.Status);
}
```

**Repetições automáticas** (até `MaxRetries`): erro de rede ou tempo limite, `429`, `502`, `503`, `504` e `409` com
`Retry-After` (pedido idempotente ainda em processamento). A espera é o `Retry-After` (no máximo 30 s) ou
0,5 s × 2^tentativa com variação de ±20 % (no máximo 8 s). Nunca se repetem outros 4xx nem `500`. `CreateAsync`,
`UpdateAsync` e `RecordConsentAsync` não se repetem (não são idempotentes); `SendAsync`, `EstimateAsync`, o upsert
(`PUT`) e as consultas sim. Cancelar o `CancellationToken` lança `OperationCanceledException`.

## Idempotência

`SendAsync` envia sempre uma `Idempotency-Key`. Se não indicar nenhuma, o SDK gera um UUID v4 **por chamada** e
reutiliza-o em todas as repetições dessa chamada. Para ficar protegido também contra repetições feitas pelo seu
sistema (por exemplo uma tarefa que volta a correr), use uma chave sua, estável, de 1 a 200 caracteres ASCII visíveis:

```csharp
var resultado = await nexora.Messages.SendAsync(SendMessageRequest.Builder()
    .Sms("A sua fatura FT 2026/123 vence amanhã.")
    .To("+258841234567")
    .IdempotencyKey("aviso-FT-2026-123")
    .Build());

Console.WriteLine(resultado.IdempotencyKey); // a chave usada
Console.WriteLine(resultado.Replayed);       // true = a API devolveu o resultado guardado; nada foi reenviado
```

A mesma chave com conteúdo diferente dá `ConflictException` (409).

## Webhooks

O segredo de assinatura (`whsec_…`) é mostrado uma vez, ao criar o webhook no painel. Verifique sempre o corpo **em
bruto**, exactamente como foi recebido, antes de qualquer conversão de JSON. Exemplo com ASP.NET Core (minimal API):

```csharp
var verificador = new WebhookVerifier(builder.Configuration["NexoraSms:WebhookSecret"]!); // tolerância: 300 s

app.MapPost("/webhooks/nexorasms", async (HttpRequest request) =>
{
    using var memoria = new MemoryStream();
    await request.Body.CopyToAsync(memoria);

    WebhookEvent evento;
    try
    {
        evento = verificador.Verify(memoria.ToArray(), request.Headers["X-Nexora-Signature"]);
    }
    catch (WebhookVerificationException)
    {
        return Results.BadRequest();
    }

    if (evento.IsMessageEvent) // message.delivered / message.failed
    {
        var mensagem = evento.GetMessageEvent();
        // actualizar a mensagem mensagem.MessageId (mensagem.ExternalId = id do contacto no seu sistema)
    }
    else if (evento.Type == WebhookEventTypes.CampaignPaused)
    {
        var motivo = evento.Data.GetProperty("reason").GetString();
    }

    return Results.Ok(); // responder 2xx depressa; tratar o resto em segundo plano
});
```

`Verify` lança `WebhookVerificationException` se o cabeçalho `X-Nexora-Signature` (`t=<unix>,v1=<hex>`) estiver mal
formado, se a assinatura não bater ou se `t` estiver a mais de 5 minutos do relógio do servidor (tolerância
configurável: `new WebhookVerifier(segredo, TimeSpan.FromMinutes(10))`). Os eventos podem chegar repetidos ou fora de
ordem: use `evento.Id` para ignorar repetidos e `OccurredAt` para ordenar.

Tipos de evento (`WebhookEventTypes`): `message.delivered`, `message.failed`, `campaign.completed`, `campaign.paused`,
`contact.opted_out`, `ping`.

## Desenvolvimento

```bash
docker run --rm -v "$PWD":/src -w /src mcr.microsoft.com/dotnet/sdk:10.0 dotnet test
```

Os testes usam um `HttpMessageHandler` falso e um relógio falso: nunca contactam a API real nem esperam tempo real.
