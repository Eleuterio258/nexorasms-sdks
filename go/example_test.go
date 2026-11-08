package nexorasms_test

import (
	"context"
	"errors"
	"fmt"
	"log"
	"net/http"
	"net/http/httptest"
	"time"

	nexorasms "github.com/Eleuterio258/nexorasms-sdks/go"
)

// fakeAPI answers like NexoraSMS so the examples never call production.
func fakeAPI() *httptest.Server {
	return httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		switch {
		case r.Method == "POST" && r.URL.Path == "/api/v1/messages":
			if r.Header.Get("Idempotency-Key") == "fatura-sem-saldo" {
				w.WriteHeader(http.StatusPaymentRequired)
				fmt.Fprint(w, `{"title":"Saldo insuficiente para este envio.","status":402}`)
				return
			}
			w.WriteHeader(http.StatusCreated)
			fmt.Fprint(w, `{"channels":[{"channel":"sms","campaign_id":"c1","recipients":1,"cost":1.5,"status":"running"},`+
				`{"channel":"email","campaign_id":"c2","recipients":1,"cost":0.2,"status":"running"}],"invalid":[]}`)
		case r.URL.Path == "/api/v1/messages/estimate":
			fmt.Fprint(w, `{"channels":[{"channel":"sms","recipients":1,"segments":1,"encoding":"gsm7","unit_price":1.5,"cost":1.5}],"total":1.5,"available":98.5,"invalid":[]}`)
		case r.URL.Path == "/api/v1/messages":
			fmt.Fprint(w, `{"items":[{"id":"m1","campaign_id":"c1","channel":"sms","destination":"+258841234567","status":"delivered","cost":1.5,"parts":1,"created_at":"2026-11-08T10:15:00Z","attempts":[]}],"page":1,"page_size":20,"total":1}`)
		case r.Method == "PUT":
			fmt.Fprint(w, `{"id":"k1","name":"Ana","external_id":"CLI-42","status":"active","group_ids":[],"tags":["vip"],"consents":[],"created_at":"2026-11-08T10:00:00Z","attributes":{}}`)
		default:
			w.WriteHeader(http.StatusNotFound)
			fmt.Fprint(w, `{"title":"Não encontrado.","status":404}`)
		}
	}))
}

func newClient(baseURL string) *nexorasms.Client {
	client, err := nexorasms.NewClientBuilder().
		PublicKey("e258_nex_sms_pk_exemplo").
		APIKey("e258_nex_sms_ak_exemplo").
		BaseURL(baseURL). // omit in production: https://sms.e258tech.tech
		Timeout(10 * time.Second).
		MaxRetries(2).
		UserAgent("loja-online/2.3").
		Build()
	if err != nil {
		log.Fatal(err)
	}
	return client
}

func ExampleNewClientBuilder() {
	_, err := nexorasms.NewClientBuilder().PublicKey("chave-errada").APIKey("e258_nex_sms_ak_x").Build()
	fmt.Println(err)
	// Output: publicKey em falta ou inválida: deve começar por e258_nex_sms_pk_.
}

func ExampleMessagesService_Send() {
	server := fakeAPI()
	defer server.Close()
	client := newClient(server.URL)

	req, err := nexorasms.NewSendMessageRequest().
		SMS("A sua fatura FT-123 vence amanhã.", "MINHALOJA").
		Email("Fatura FT-123", "<p>A sua fatura vence amanhã.</p>").
		To("+258841234567", "ana@loja.co.mz").
		IdempotencyKey("fatura-FT-123").
		Build()
	if err != nil {
		log.Fatal(err)
	}
	result, err := client.Messages.Send(context.Background(), req)
	if err != nil {
		log.Fatal(err)
	}
	for _, ch := range result.Channels {
		fmt.Printf("%s: campanha %s, custo %.2f\n", ch.Channel, ch.CampaignID, ch.Cost)
	}
	fmt.Println("chave:", result.IdempotencyKey, "repetido:", result.Replayed)
	// Output:
	// sms: campanha c1, custo 1.50
	// email: campanha c2, custo 0.20
	// chave: fatura-FT-123 repetido: false
}

func ExampleMessagesService_Estimate() {
	server := fakeAPI()
	defer server.Close()
	client := newClient(server.URL)

	req, _ := nexorasms.NewSendMessageRequest().SMS("Olá!").To("+258841234567").Build()
	estimate, err := client.Messages.Estimate(context.Background(), req)
	if err != nil {
		log.Fatal(err)
	}
	fmt.Printf("total %.2f, disponível %.2f, segmentos %d\n", estimate.Total, estimate.Available, estimate.Channels[0].Segments)
	// Output: total 1.50, disponível 98.50, segmentos 1
}

func ExampleMessagesService_List() {
	server := fakeAPI()
	defer server.Close()
	client := newClient(server.URL)

	query, err := nexorasms.NewMessageQuery().CampaignID("c1").Status(nexorasms.MessageDelivered).PageSize(20).Build()
	if err != nil {
		log.Fatal(err)
	}
	page, err := client.Messages.List(context.Background(), query)
	if err != nil {
		log.Fatal(err)
	}
	for _, m := range page.Items {
		fmt.Println(m.Destination, m.Status, m.CreatedAt.Format(time.RFC3339))
	}
	// Output: +258841234567 delivered 2026-11-08T10:15:00Z
}

func ExampleContactsService_UpsertByExternalID() {
	server := fakeAPI()
	defer server.Close()
	client := newClient(server.URL)

	req, _ := nexorasms.NewContactRequest().
		Name("Ana").
		Phone("+258841234567").
		Tag("vip").
		Attribute("data_vencimento", "2026-12-01").
		Build()
	contact, err := client.Contacts.UpsertByExternalID(context.Background(), "CLI-42", req)
	if err != nil {
		log.Fatal(err)
	}
	fmt.Println(contact.ID, *contact.Name, *contact.ExternalID, contact.Tags)
	// Output: k1 Ana CLI-42 [vip]
}

func ExampleIsInsufficientFunds() {
	server := fakeAPI()
	defer server.Close()
	client := newClient(server.URL)

	req, _ := nexorasms.NewSendMessageRequest().SMS("Olá!").To("+258841234567").IdempotencyKey("fatura-sem-saldo").Build()
	_, err := client.Messages.Send(context.Background(), req)
	switch {
	case nexorasms.IsInsufficientFunds(err):
		fmt.Println("Carregue a carteira:", err)
	case nexorasms.IsRateLimited(err):
		if apiErr, ok := nexorasms.AsAPIError(err); ok && apiErr.RetryAfter != nil {
			fmt.Println("Tente de novo daqui a", *apiErr.RetryAfter)
		}
	case errors.Is(err, nexorasms.ErrConnection):
		fmt.Println("Sem ligação:", err)
	}
	// Output: Carregue a carteira: Saldo insuficiente para este envio.
}

func ExampleWebhookVerifier_Verify() {
	verifier, err := nexorasms.NewWebhookVerifier("whsec_test",
		nexorasms.WithClock(func() time.Time { return time.Unix(1762597200, 0) })) // omit WithClock in production
	if err != nil {
		log.Fatal(err)
	}
	body := []byte(`{"id":"evt_1","type":"ping","created_at":"2026-11-08T10:20:00Z","data":{"message":"ping"}}`)
	header := "t=1762597200,v1=cde593b98434dd7cd2944443131e9bf1bf47bc37630a621aa9481d76ab38b77e"

	event, err := verifier.Verify(body, header)
	if err != nil {
		fmt.Println("rejeitado:", err) // answer 400
		return
	}
	fmt.Println(event.ID, event.Type)
	if event.IsMessageEvent() {
		m, _ := event.MessageEvent()
		fmt.Println(m.MessageID, m.Status)
	}
	// Output: evt_1 ping
}
