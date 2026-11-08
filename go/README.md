# NexoraSMS — SDK Go

SDK oficial do NexoraSMS para Go: envio de SMS, WhatsApp e email, contactos, carteira, campanhas e verificação de
webhooks. Versão **0.1.0**. Só usa a biblioteca padrão (Go 1.22 ou superior).

A descrição completa da API está em `docs/INTEGRACAO_API.md` no repositório do NexoraSMS.

## Instalação

O SDK é publicado no repositório público [github.com/Eleuterio258/nexorasms-sdks](https://github.com/Eleuterio258/nexorasms-sdks),
na pasta `go/`. As versões são etiquetas `go/vX.Y.Z` (a primeira é `go/v0.1.0`).

```sh
go get github.com/Eleuterio258/nexorasms-sdks/go@latest
```

```go
import nexorasms "github.com/Eleuterio258/nexorasms-sdks/go"
```

## Configuração com builder

O cliente só se cria pelo builder. Depois de construído é imutável e pode ser partilhado entre goroutines.

```go
client, err := nexorasms.NewClientBuilder().
	PublicKey(os.Getenv("NEXORA_SMS_PUBLIC_KEY")). // e258_nex_sms_pk_…
	APIKey(os.Getenv("NEXORA_SMS_API_KEY")).       // e258_nex_sms_ak_… (só no servidor!)
	Timeout(30 * time.Second).                     // por tentativa (por omissão 30 s)
	MaxRetries(2).                                 // por omissão 2; 0 desliga
	UserAgent("loja-online/2.3").                  // acrescentado a "nexorasms-go/0.1.0"
	Build()
if err != nil {
	log.Fatal(err) // ex.: "apiKey em falta ou inválida: deve começar por e258_nex_sms_ak_."
}
```

Outras opções: `BaseURL("https://…")` (por omissão `https://sms.e258tech.tech`) e `HTTPClient(&http.Client{…})`
(proxies, transportes próprios). Todos os métodos de rede recebem um `context.Context` como primeiro argumento.

## Enviar SMS

```go
req, err := nexorasms.NewSendMessageRequest().
	SMS("A sua encomenda já saiu.", "MINHALOJA"). // Sender ID aprovado (opcional)
	To("+258841234567").
	Build()
if err != nil {
	return err // validação local: nunca chega a fazer o pedido
}
result, err := client.Messages.Send(ctx, req)
if err != nil {
	return err
}
fmt.Println(result.Channels[0].CampaignID, result.Channels[0].Cost, result.Invalid)
```

O SDK não valida o formato dos telefones: os destinos rejeitados pela API vêm em `result.Invalid`.

## Enviar SMS e email

Até 3 canais por envio (`SMS`, `Email`, `WhatsApp`/`WhatsAppTemplate`), cada um no máximo uma vez. É criada uma
campanha por canal.

```go
req, err := nexorasms.NewSendMessageRequest().
	SMS("Fatura FT-123 vence amanhã.", "MINHALOJA").
	Email("Fatura FT-123", "<p>A sua fatura vence amanhã.</p>", "faturas@loja.co.mz").
	To("+258841234567", "ana@loja.co.mz").
	Contacts("8b1f…", "c2d4…"). // contactos já existentes (opcional)
	IgnoreQuietHours(true).
	Build()
```

WhatsApp com template aprovado pela Meta:

```go
nexorasms.NewSendMessageRequest().WhatsAppTemplate("aviso", "pt_PT", "Ana", "FT-123").Contact(id).Build()
```

## Estimar custo

Não envia nada: devolve segmentos, codificação, custo e saldo disponível.

```go
estimate, err := client.Messages.Estimate(ctx, req)
if err == nil && estimate.Total > estimate.Available {
	fmt.Println("Saldo insuficiente para este envio.")
}
```

## Upsert de contacto

Cria ou actualiza o contacto com o identificador do seu sistema (o id é codificado no URL automaticamente).

```go
req, err := nexorasms.NewContactRequest().
	Name("Ana Silva").
	Phone("+258841234567").
	Email("ana@loja.co.mz").
	Tags("vip", "maputo").
	Attribute("data_vencimento", "2026-12-01").
	RemoveAttribute("campo_antigo"). // enviado como null: remove
	Build()
contact, err := client.Contacts.UpsertByExternalID(ctx, "CLI/42", req)
```

Só os campos definidos vão no JSON: num `Update`, um campo que não indique fica como está. `Tags()` ou `GroupIDs()`
sem argumentos enviam uma lista vazia (limpa). Consentimentos:
`client.Contacts.RecordConsent(ctx, id, nexorasms.ChannelSMS, nexorasms.ConsentRevoked, "erp")`.

Também há `Contacts.Create`, `Get`, `Update`, `List(ctx, nexorasms.NewContactQuery()…)`, `Wallet.Get`,
`Wallet.Transactions(ctx, page, pageSize)`, `Campaigns.Get` e `Campaigns.Stats`.

## Consultar mensagens

```go
query, err := nexorasms.NewMessageQuery().
	CampaignID(result.Channels[0].CampaignID).
	Status(nexorasms.MessageFailed).
	Page(1).PageSize(50). // 1–200
	Build()
page, err := client.Messages.List(ctx, query) // query pode ser nil
for _, m := range page.Items {
	fmt.Println(m.Destination, m.Status, m.CreatedAt)
	if m.DeliveredAt != nil { /* campos opcionais são ponteiros */ }
}
```

Modelos: datas em `time.Time` (opcionais em `*time.Time`), dinheiro em `float64`, estados como texto com constantes
(`nexorasms.MessageDelivered`, `nexorasms.AttemptInFlight`, …). Valores novos da API não partem a leitura.

## Tratar erros (402, 429)

Respostas de erro da API devolvem `*nexorasms.APIError` (`StatusCode`, `Kind`, `Title`, `Detail`, `TraceID`,
`Errors`, `RetryAfter`); falhas de rede e tempo limite devolvem `*nexorasms.ConnectionError`
(`errors.Is(err, nexorasms.ErrConnection)`). Erros de configuração e de validação dos builders são `error` simples.

```go
_, err := client.Messages.Send(ctx, req)
switch {
case nexorasms.IsInsufficientFunds(err): // 402
	fmt.Println("Carregue a carteira:", err) // mensagem = title da API
case nexorasms.IsRateLimited(err): // 429 (já repetido até MaxRetries)
	if apiErr, ok := nexorasms.AsAPIError(err); ok && apiErr.RetryAfter != nil {
		fmt.Println("Tente de novo daqui a", *apiErr.RetryAfter)
	}
case nexorasms.IsValidation(err): // 400
	apiErr, _ := nexorasms.AsAPIError(err)
	fmt.Println(apiErr.Errors) // campo -> mensagens
case errors.Is(err, nexorasms.ErrConnection):
	fmt.Println("Sem ligação:", err)
case err != nil:
	fmt.Println(err)
}
```

Outras funções: `IsAuthentication` (401), `IsPermissionDenied` (403), `IsNotFound` (404), `IsConflict` (409),
`IsServerError` (5xx), `IsConnectionError`.

O SDK repete automaticamente (até `MaxRetries`) em erro de rede/tempo limite, `429`, `502`, `503`, `504` e `409` com
`Retry-After`. Espera o `Retry-After` (máx. 30 s) ou 0,5 s × 2^tentativa com ±20 % (máx. 8 s). Nunca repete outros
4xx nem `500`. `Contacts.Create`, `Update` e `RecordConsent` não se repetem.

## Webhooks

Verifique o cabeçalho `X-Nexora-Signature` com o corpo **bruto**, antes de o interpretar:

```go
verifier, err := nexorasms.NewWebhookVerifier(os.Getenv("NEXORA_SMS_WEBHOOK_SECRET")) // whsec_…
// opções: nexorasms.WithTolerance(5*time.Minute) (por omissão), nexorasms.WithClock(func() time.Time {…})

http.HandleFunc("/webhooks/nexora", func(w http.ResponseWriter, r *http.Request) {
	body, _ := io.ReadAll(r.Body)
	event, err := verifier.Verify(body, r.Header.Get("X-Nexora-Signature"))
	if err != nil { // *nexorasms.WebhookSignatureError
		http.Error(w, err.Error(), http.StatusBadRequest)
		return
	}
	switch event.Type {
	case nexorasms.EventMessageDelivered, nexorasms.EventMessageFailed:
		m, _ := event.MessageEvent()
		fmt.Println(m.MessageID, m.Status, m.Cost)
	case nexorasms.EventPing:
	default:
		var data map[string]any
		_ = event.DecodeData(&data)
	}
	w.WriteHeader(http.StatusNoContent)
})
```

## Idempotência

`Messages.Send` envia sempre o cabeçalho `Idempotency-Key`: o valor de `IdempotencyKey(…)` (1 a 200 caracteres ASCII
visíveis, ex.: o número da fatura) ou um UUID v4 gerado uma vez por chamada. A mesma chave é usada em todas as
repetições dessa chamada, por isso nunca há envios duplicados. Se a API devolver um resultado já guardado,
`result.Replayed` é `true`; `result.IdempotencyKey` diz a chave usada.

```go
req, _ := nexorasms.NewSendMessageRequest().SMS("Fatura FT-123 paga.").To(phone).IdempotencyKey("FT-123-pago").Build()
```

## Desenvolvimento

```sh
docker run --rm -v "$PWD":/src -w /src golang:1.24 sh -c 'go vet ./... && go test ./...'
```

Os testes usam um servidor HTTP falso local (`net/http/httptest`) e nunca chamam a API de produção.

## Licença

MIT — ver [LICENSE](LICENSE). Copyright (c) 2026 E258Tech.
