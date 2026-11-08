# e258tech/nexorasms — SDK PHP

SDK oficial do NexoraSMS para PHP 8.1 ou superior: envio de SMS, WhatsApp e email, contactos, estado das mensagens,
carteira, campanhas e verificação de webhooks. Sem dependências em tempo de execução além de `ext-curl` e `ext-json`.

Documentação completa da API: https://nexorasms.e258tech.tech/docs/api

## Instalação

```bash
composer require e258tech/nexorasms
```

> O pacote fica disponível no Packagist depois da publicação (ainda por fazer). Até lá, instale-o directamente do
> repositório Git, acrescentando-o ao `composer.json` do seu projecto:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/Eleuterio258/nexorasms-sdks" }
    ],
    "require": {
        "e258tech/nexorasms": "dev-main"
    }
}
```

Para desenvolvimento local, com uma cópia do repositório ao lado do seu projecto, pode usar um repositório do tipo
`path`: `{ "type": "path", "url": "../nexorasms-sdks/php" }`.

## Configuração (builder)

Crie as chaves no painel, em **Configurações → API e webhooks → Nova chave** (permissões «Operador»). Guarde-as em
variáveis de ambiente, nunca no código nem no navegador.

```php
use E258Tech\NexoraSms\NexoraSmsClient;

$nexora = NexoraSmsClient::builder()
    ->publicKey(getenv('NEXORASMS_PUBLIC_KEY'))   // e258_nex_sms_pk_…
    ->apiKey(getenv('NEXORASMS_API_KEY'))         // e258_nex_sms_ak_…
    ->timeout(15)                                 // opcional, em segundos (por omissão 30)
    ->maxRetries(2)                               // opcional (por omissão 2; 0 desliga)
    ->userAgent('loja-online/2.3')                // opcional, acrescentado a «nexorasms-php/0.1.0»
    ->build();
```

O cliente é imutável e pode ser partilhado por toda a aplicação (por exemplo, registado como serviço único no
contentor de dependências). Se faltar uma chave ou o prefixo estiver errado, `build()` lança
`InvalidArgumentException` com a explicação, sem fazer nenhum pedido.

### Transporte HTTP

Por omissão o SDK usa cURL (`CurlTransport`). Para usar outro cliente:

- **PSR-18** (Guzzle 7, Symfony HttpClient, …): `->psr18($client, $requestFactory, $streamFactory)`. Exige os pacotes
  `psr/http-client` e `psr/http-factory` (que o SDK não instala). O tempo limite configura-se no seu cliente.
- **Qualquer outro**: implemente a interface `E258Tech\NexoraSms\Http\Transport` (um método
  `send(HttpRequest $request, float $timeout): HttpResponse`, que lança `TransportException` só quando não há resposta)
  e passe-a em `->transport($meuTransporte)`. Opções de cURL (proxy, CA) também podem ir em
  `new CurlTransport(curlOptions: [CURLOPT_PROXY => '…'])`.

## Enviar um SMS

```php
use E258Tech\NexoraSms\Request\SendMessageRequest;

$result = $nexora->messages()->send(
    SendMessageRequest::builder()
        ->sms('A sua encomenda 58231 foi confirmada. Obrigado!')   // ->sms($texto, 'MINHALOJA') com Sender ID aprovado
        ->to('+258841234567')
        ->idempotencyKey('encomenda-58231-confirmacao')
        ->build(),
);

echo $result->channels[0]->campaignId, ' ', $result->channels[0]->cost;
print_r($result->invalid);   // destinatários rejeitados pela API (o SDK não valida o formato dos números)
```

## SMS e email ao mesmo tempo, para contactos existentes

```php
$request = SendMessageRequest::builder()
    ->sms('Olá {{nome|Cliente}}, a fatura {{fatura}} vence amanhã.')
    ->email('Fatura a vencer', '<p>Olá {{nome|Cliente}},</p><p>A fatura {{fatura}} vence amanhã.</p>')
    ->contacts('6f1c…', '9a2d…')
    ->build();
```

WhatsApp com template aprovado na Meta: `->whatsappTemplate('aviso_fatura', 'pt_PT', 'Ana', 'FT 2026/123')`.
Códigos e avisos urgentes: `->ignoreQuietHours()`.

## Estimar o custo

```php
$estimate = $nexora->messages()->estimate($request);   // não envia nada
foreach ($estimate->channels as $channel) {
    echo $channel->channelRaw, ': ', $channel->segments, ' segmento(s), ', $channel->cost, PHP_EOL;
}
if ($estimate->total <= $estimate->available) {
    $nexora->messages()->send($request);
}
```

## Contactos (upsert pelo id do seu sistema)

```php
use E258Tech\NexoraSms\Enum\Channel;
use E258Tech\NexoraSms\Enum\ConsentStatus;
use E258Tech\NexoraSms\Request\ContactQuery;
use E258Tech\NexoraSms\Request\ContactRequest;

// Cria ou actualiza pelo id do seu sistema (o id é codificado no URL)
$contact = $nexora->contacts()->upsertByExternalId('CLI-0042',
    ContactRequest::builder()
        ->name('Ana Matola')
        ->phone('841234567')
        ->email('ana@exemplo.co.mz')
        ->tags('cliente', 'maputo')
        ->attribute('fatura', 'FT 2026/123')
        ->build());

$nexora->contacts()->recordConsent($contact->id, Channel::Sms, ConsentStatus::Revoked, 'erp');
$vip = $nexora->contacts()->list(ContactQuery::builder()->tag('vip')->pageSize(100)->build());
```

Só os campos definidos vão no pedido: num `update()`, um campo não definido fica como está. `->tags()` sem argumentos
envia uma lista vazia (limpa as etiquetas); `->attribute('campo', null)` remove o campo personalizado.
Também: `create()`, `get($id)`, `update($id, $request)`.

## Consultar mensagens

```php
use E258Tech\NexoraSms\Enum\MessageStatus;
use E258Tech\NexoraSms\Request\MessageQuery;

$page = $nexora->messages()->list(
    MessageQuery::builder()->campaignId($result->channels[0]->campaignId)->status(MessageStatus::Failed)->pageSize(50)->build(),
);
foreach ($page->items as $message) {
    echo $message->destination, ' ', $message->statusRaw, ' ', $message->createdAt->format('Y-m-d H:i'), PHP_EOL;
}
if ($page->hasMore()) { /* pedir a página seguinte com ->page($page->page + 1) */ }
```

Também: `$nexora->messages()->get($id)`, `$nexora->wallet()->get()`, `$nexora->wallet()->transactions($page, $pageSize)`,
`$nexora->campaigns()->get($id)`, `$nexora->campaigns()->stats($id)`.

## Tratar erros

```php
use E258Tech\NexoraSms\Exception\ConnectionException;
use E258Tech\NexoraSms\Exception\InsufficientFundsException;
use E258Tech\NexoraSms\Exception\NexoraSmsException;
use E258Tech\NexoraSms\Exception\RateLimitException;
use E258Tech\NexoraSms\Exception\ValidationException;

try {
    $nexora->messages()->send($request);
} catch (InsufficientFundsException $e) {          // 402
    echo 'Sem saldo: carregue a carteira. ', $e->getMessage();
} catch (RateLimitException $e) {                  // 429 (depois das repetições automáticas)
    echo "Tente daqui a {$e->retryAfter} s.";
} catch (ValidationException $e) {                 // 400
    print_r($e->errors);                           // ['campo' => ['mensagem', …]]
} catch (ConnectionException $e) {                 // rede ou tempo limite
    echo 'Sem ligação ao NexoraSMS.';
} catch (NexoraSmsException $e) {                  // qualquer outro erro da API
    echo $e->status, ' ', $e->getMessage(), ' ', $e->traceId;
}
```

| Excepção | Estado HTTP |
|---|---|
| `ValidationException` | 400 |
| `AuthenticationException` | 401 (chaves erradas ou revogadas) |
| `InsufficientFundsException` | 402 |
| `PermissionDeniedException` | 403 |
| `NotFoundException` | 404 |
| `ConflictException` | 409 |
| `RateLimitException` | 429 |
| `ServerException` | 5xx |
| `ConnectionException` | rede ou tempo limite (`status` nulo) |

Todas estendem `NexoraSmsException` e expõem `status`, `title`, `detail`, `traceId`, `errors` e `retryAfter`;
a mensagem é o `title` (em português), ou o `detail`, ou `HTTP <estado>`. Os builders validam os pedidos antes de
chamar a API e lançam `InvalidArgumentException` com a explicação.

## Idempotência e repetições

- `messages()->send()` envia sempre um `Idempotency-Key`: o seu (`->idempotencyKey(...)`, recomendado: ligado à
  operação do seu sistema; 1 a 200 caracteres ASCII visíveis) ou um UUID v4 gerado. A mesma chave é usada em todas as
  repetições dessa chamada, por isso uma falha de rede nunca duplica o envio. `$result->idempotencyKey` diz qual foi
  usada e `$result->replayed` é `true` quando o NexoraSMS devolveu o resultado de um pedido anterior igual.
- O SDK repete automaticamente (até `maxRetries`) em erros de rede, `429`, `502`, `503`, `504` e `409` com
  `Retry-After`, respeitando o `Retry-After` (máximo 30 s); sem ele, espera 0,5 s × 2^tentativa (±20 %, máximo 8 s).
  Nunca repete `500` nem outros 4xx. Criar contacto (`POST`), actualizar (`PATCH`) e consentimentos não se repetem.

## Webhooks

```php
use E258Tech\NexoraSms\Exception\WebhookVerificationException;
use E258Tech\NexoraSms\Webhook\WebhookVerifier;

$verifier = new WebhookVerifier(getenv('NEXORASMS_WEBHOOK_SECRET'));   // whsec_…; tolerância por omissão 300 s

$rawBody = file_get_contents('php://input');                           // corpo bruto, tal como chegou
try {
    $event = $verifier->verify($rawBody, $_SERVER['HTTP_X_NEXORA_SIGNATURE'] ?? null);
} catch (WebhookVerificationException $e) {
    http_response_code(401);
    exit;
}

http_response_code(204);                                               // responda depressa; processe depois
if ($event->isMessageEvent()) {                                        // message.delivered / message.failed
    $data = $event->messageEvent();
    atualizarEstado($data->externalId, $data->statusRaw);              // ignore ids de evento repetidos
}
```

Em frameworks: Laravel `$request->getContent()` e `$request->header('X-Nexora-Signature')`; Symfony
`$request->getContent()` e `$request->headers->get('X-Nexora-Signature')`. A comparação é feita em tempo constante
(`hash_equals`). Para testes, injecte o relógio: `new WebhookVerifier($segredo, 300, fn (): int => 1762597200)`.

## Modelos

- Os resultados são classes só de leitura (`readonly`) com nomes em camelCase (`campaignId`, `createdAt`). Campos
  desconhecidos no JSON são ignorados; campos obrigatórios em falta ficam com valores neutros (`''`, `0`, época Unix).
- **Dinheiro** em `float`, na moeda da carteira (`$wallet->currency`). Os valores da API têm no máximo duas casas
  decimais; para somas contabilísticas, arredonde (`round($v, 2)`) ou converta para inteiros/BCMath.
- **Datas** em `DateTimeImmutable` (UTC, como vêm da API); os campos opcionais (`acceptedAt`, `deliveredAt`…) podem ser `null`.
- **Enums**: os estados, canais e tipos são enums PHP (`MessageStatus`, `Channel`, `CampaignStatus`…). Para não partir
  quando a API acrescentar valores, cada um vem em dois campos: o enum (`$message->status`, `null` se o valor for
  desconhecido) e o texto bruto (`$message->statusRaw`, sempre preenchido).
- Os nomes dos campos personalizados (`attributes`) e os dados dos webhooks (`$event->data`) mantêm-se como vêm.

## Testes

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 sh -c 'composer install && vendor/bin/phpunit && vendor/bin/phpstan analyse'
```

Os testes usam um transporte falso (nunca chamam a API real) e um teste de fumo do `CurlTransport` contra o servidor
embutido do PHP (`php -S`). Licença: MIT.
