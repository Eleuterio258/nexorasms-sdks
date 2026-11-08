package nexorasms

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"reflect"
	"regexp"
	"strings"
	"sync"
	"testing"
	"time"
)

const (
	testPK = "e258_nex_sms_pk_HXj4qcWmXl0m"
	testAK = "e258_nex_sms_ak_secretsecretsecretsecret123"
)

// ------------------------------------------------------------------ Fake server

type reply struct {
	status  int
	body    any
	headers map[string]string
}

type seenRequest struct {
	method string
	uri    string
	header http.Header
	body   map[string]any
	raw    string
}

type fakeAPI struct {
	t       *testing.T
	server  *httptest.Server
	mu      sync.Mutex
	replies []reply
	seen    []seenRequest
	sleeps  []time.Duration
}

func newFakeAPI(t *testing.T) *fakeAPI {
	t.Helper()
	f := &fakeAPI{t: t}
	f.server = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		raw, _ := io.ReadAll(r.Body)
		var body map[string]any
		if len(raw) > 0 {
			if err := json.Unmarshal(raw, &body); err != nil {
				t.Errorf("corpo não é JSON: %v", err)
			}
		}
		f.mu.Lock()
		f.seen = append(f.seen, seenRequest{method: r.Method, uri: r.RequestURI, header: r.Header.Clone(), body: body, raw: string(raw)})
		rep := reply{status: 200, body: map[string]any{}}
		if len(f.replies) > 0 {
			rep, f.replies = f.replies[0], f.replies[1:]
		}
		f.mu.Unlock()
		w.Header().Set("Content-Type", "application/json")
		for k, v := range rep.headers {
			w.Header().Set(k, v)
		}
		w.WriteHeader(rep.status)
		if rep.body != nil {
			_ = json.NewEncoder(w).Encode(rep.body)
		}
	}))
	t.Cleanup(f.server.Close)
	return f
}

func (f *fakeAPI) push(replies ...reply) { f.replies = append(f.replies, replies...) }

func (f *fakeAPI) builder() *ClientBuilder {
	return NewClientBuilder().PublicKey(testPK).APIKey(testAK).BaseURL(f.server.URL + "/").
		withSleep(func(_ context.Context, d time.Duration) error { f.sleeps = append(f.sleeps, d); return nil })
}

func (f *fakeAPI) client(configure ...func(*ClientBuilder)) *Client {
	f.t.Helper()
	b := f.builder()
	for _, c := range configure {
		c(b)
	}
	c, err := b.Build()
	if err != nil {
		f.t.Fatalf("Build: %v", err)
	}
	return c
}

// mustBuild unwraps a builder result; a build error is a bug in the test itself.
func mustBuild[T any](v *T, err error) *T {
	if err != nil {
		panic("build: " + err.Error())
	}
	return v
}

func smsRequest(_ *testing.T) *SendMessageRequest {
	r, err := NewSendMessageRequest().SMS("Olá", "MINHALOJA").To("+258841234567").Build()
	return mustBuild(r, err)
}

var sendResult = map[string]any{
	"channels": []any{map[string]any{"channel": "sms", "campaign_id": "c1", "recipients": 1, "cost": 1.5, "status": "running"}},
	"invalid":  []any{},
}

func assertEqual(t *testing.T, got, want any) {
	t.Helper()
	if !reflect.DeepEqual(got, want) {
		t.Fatalf("obtido %#v, esperado %#v", got, want)
	}
}

func assertErrContains(t *testing.T, err error, part string) {
	t.Helper()
	if err == nil || !strings.Contains(err.Error(), part) {
		t.Fatalf("esperado erro com %q, obtido %v", part, err)
	}
}

var ctx = context.Background()

// ------------------------------------------------------------------ Client builder

func TestBuilderRequiresBothKeysWithPrefix(t *testing.T) {
	_, err := NewClientBuilder().APIKey(testAK).Build()
	assertErrContains(t, err, "publicKey")
	_, err = NewClientBuilder().PublicKey(testPK).Build()
	assertErrContains(t, err, "apiKey")
	_, err = NewClientBuilder().PublicKey("nxk_x").APIKey(testAK).Build()
	assertErrContains(t, err, "e258_nex_sms_pk_")
	_, err = NewClientBuilder().PublicKey(testPK).APIKey(testPK).Build()
	assertErrContains(t, err, "e258_nex_sms_ak_")
	_, err = NewClientBuilder().PublicKey(testPK).APIKey(testAK).MaxRetries(-1).Build()
	assertErrContains(t, err, "maxRetries")
	_, err = NewClientBuilder().PublicKey(testPK).APIKey(testAK).Timeout(0).Build()
	assertErrContains(t, err, "timeout")
	_, err = NewClientBuilder().PublicKey(testPK).APIKey(testAK).BaseURL("sms.e258tech.tech").Build()
	assertErrContains(t, err, "baseURL")
	c, err := NewClientBuilder().PublicKey(testPK).APIKey(testAK).Build()
	if err != nil {
		t.Fatal(err)
	}
	assertEqual(t, c.BaseURL(), "https://sms.e258tech.tech")
}

// ------------------------------------------------------------------ Messages

func TestSendSendsKeysSnakeCaseJSONAndGeneratedIdempotencyKey(t *testing.T) {
	f := newFakeAPI(t)
	f.push(reply{status: 201, body: sendResult})
	req := mustBuild(NewSendMessageRequest().SMS("Olá", "MINHALOJA").Email("Assunto", "<p>Olá</p>").
		To("+258841234567", "ana@x.co.mz").Contact("k1").IgnoreQuietHours(true).Build())
	result, err := f.client(func(b *ClientBuilder) { b.UserAgent("loja/1.0") }).Messages.Send(ctx, req)
	if err != nil {
		t.Fatal(err)
	}
	r := f.seen[0]
	assertEqual(t, r.method, "POST")
	assertEqual(t, r.uri, "/api/v1/messages")
	assertEqual(t, r.header.Get("X-Public-Key"), testPK)
	assertEqual(t, r.header.Get("X-Api-Key"), testAK)
	assertEqual(t, r.header.Get("Accept"), "application/json")
	assertEqual(t, r.header.Get("Content-Type"), "application/json")
	assertEqual(t, r.header.Get("User-Agent"), "nexorasms-go/0.1.0 loja/1.0")
	key := r.header.Get("Idempotency-Key")
	if !regexp.MustCompile(`^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$`).MatchString(key) {
		t.Fatalf("Idempotency-Key não é UUID v4: %q", key)
	}
	var want map[string]any
	_ = json.Unmarshal([]byte(`{
		"channels":[{"channel":"sms","content":"Olá","sender":"MINHALOJA"},{"channel":"email","subject":"Assunto","content":"<p>Olá</p>"}],
		"recipients":["+258841234567","ana@x.co.mz"],"contact_ids":["k1"],"ignore_quiet_hours":true}`), &want)
	assertEqual(t, r.body, want)
	assertEqual(t, result.Channels[0].CampaignID, "c1")
	assertEqual(t, result.Channels[0].Cost, 1.5)
	assertEqual(t, result.IdempotencyKey, key)
	assertEqual(t, result.Replayed, false)
}

func TestSendGeneratesNewKeyPerCall(t *testing.T) {
	f := newFakeAPI(t)
	f.push(reply{status: 201, body: sendResult}, reply{status: 201, body: sendResult})
	c := f.client()
	req := smsRequest(t)
	if _, err := c.Messages.Send(ctx, req); err != nil {
		t.Fatal(err)
	}
	if _, err := c.Messages.Send(ctx, req); err != nil {
		t.Fatal(err)
	}
	if f.seen[0].header.Get("Idempotency-Key") == f.seen[1].header.Get("Idempotency-Key") {
		t.Fatal("cada chamada deve gerar uma chave nova")
	}
}

func TestSendRetries503And429WithSameKeyAndRetryAfter(t *testing.T) {
	f := newFakeAPI(t)
	f.push(
		reply{status: 503, body: map[string]any{"title": "Indisponível", "status": 503}},
		reply{status: 429, body: map[string]any{"title": "Devagar", "status": 429}, headers: map[string]string{"Retry-After": "2"}},
		reply{status: 201, body: sendResult, headers: map[string]string{"Idempotent-Replayed": "true"}},
	)
	req := mustBuild(NewSendMessageRequest().SMS("Olá").To("+258841234567").IdempotencyKey("fatura-123").Build())
	result, err := f.client().Messages.Send(ctx, req)
	if err != nil {
		t.Fatal(err)
	}
	assertEqual(t, len(f.seen), 3)
	for _, r := range f.seen {
		assertEqual(t, r.header.Get("Idempotency-Key"), "fatura-123")
	}
	assertEqual(t, len(f.sleeps), 2)
	if f.sleeps[0] < 400*time.Millisecond || f.sleeps[0] > 600*time.Millisecond {
		t.Fatalf("primeira espera fora de 0,5 s ±20%%: %v", f.sleeps[0])
	}
	assertEqual(t, f.sleeps[1], 2*time.Second)
	assertEqual(t, result.Replayed, true)
	assertEqual(t, result.IdempotencyKey, "fatura-123")
}

func TestRetryAfterIsCappedAt30Seconds(t *testing.T) {
	f := newFakeAPI(t)
	f.push(reply{status: 429, body: map[string]any{}, headers: map[string]string{"Retry-After": "120"}}, reply{status: 200, body: map[string]any{}})
	if _, err := f.client().Wallet.Get(ctx); err != nil {
		t.Fatal(err)
	}
	assertEqual(t, f.sleeps, []time.Duration{30 * time.Second})
}

func TestNoRetryOn400Nor402And402IsInsufficientFunds(t *testing.T) {
	f := newFakeAPI(t)
	f.push(reply{status: 402, body: map[string]any{"title": "Saldo insuficiente para este envio.", "status": 402, "traceId": "t1"}})
	_, err := f.client().Messages.Send(ctx, smsRequest(t))
	if !IsInsufficientFunds(err) {
		t.Fatalf("esperado InsufficientFunds, obtido %v", err)
	}
	apiErr, ok := AsAPIError(err)
	if !ok {
		t.Fatal("esperado *APIError")
	}
	assertEqual(t, apiErr.StatusCode, 402)
	assertEqual(t, apiErr.Kind, KindInsufficientFunds)
	assertEqual(t, apiErr.Title, "Saldo insuficiente para este envio.")
	assertEqual(t, apiErr.Error(), "Saldo insuficiente para este envio.")
	assertEqual(t, apiErr.TraceID, "t1")
	assertEqual(t, len(f.seen), 1)

	f.push(reply{status: 400, body: map[string]any{"title": "Pedido inválido", "status": 400, "errors": map[string]any{"channels": []string{"obrigatório"}}}})
	_, err = f.client().Messages.Send(ctx, smsRequest(t))
	if !IsValidation(err) {
		t.Fatalf("esperado Validation, obtido %v", err)
	}
	var target *APIError
	if !errors.As(err, &target) || target.Errors["channels"][0] != "obrigatório" {
		t.Fatalf("errors não mapeados: %#v", target)
	}
	assertEqual(t, len(f.seen), 2)
	assertEqual(t, len(f.sleeps), 0)
}

func TestNoRetryOn500(t *testing.T) {
	f := newFakeAPI(t)
	f.push(reply{status: 500, body: map[string]any{"detail": "Erro interno"}})
	_, err := f.client().Wallet.Get(ctx)
	if !IsServerError(err) {
		t.Fatalf("esperado ServerError, obtido %v", err)
	}
	assertEqual(t, err.Error(), "Erro interno")
	assertEqual(t, len(f.seen), 1)
}

func TestGivesUpAfterMaxRetries(t *testing.T) {
	f := newFakeAPI(t)
	for i := 0; i < 3; i++ {
		f.push(reply{status: 503, body: map[string]any{"title": "Indisponível", "status": 503}})
	}
	_, err := f.client().Messages.Send(ctx, smsRequest(t))
	if !IsServerError(err) {
		t.Fatalf("esperado ServerError, obtido %v", err)
	}
	assertEqual(t, len(f.seen), 3)
	f.push(reply{status: 503, body: map[string]any{}})
	_, err = f.client(func(b *ClientBuilder) { b.MaxRetries(0) }).Wallet.Get(ctx)
	if !IsServerError(err) || err.Error() != "HTTP 503" {
		t.Fatalf("esperado 'HTTP 503', obtido %v", err)
	}
	assertEqual(t, len(f.seen), 4)
}

func TestConflictRetriedOnlyWithRetryAfter(t *testing.T) {
	f := newFakeAPI(t)
	f.push(reply{status: 409, body: map[string]any{"title": "Em processamento", "status": 409}, headers: map[string]string{"Retry-After": "1"}},
		reply{status: 201, body: sendResult})
	if _, err := f.client().Messages.Send(ctx, smsRequest(t)); err != nil {
		t.Fatal(err)
	}
	assertEqual(t, len(f.seen), 2)
	f.push(reply{status: 409, body: map[string]any{"title": "Conteúdo diferente", "status": 409}})
	_, err := f.client().Messages.Send(ctx, smsRequest(t))
	if !IsConflict(err) {
		t.Fatalf("esperado Conflict, obtido %v", err)
	}
	assertEqual(t, len(f.seen), 3)
}

func TestMaps401403404And429(t *testing.T) {
	f := newFakeAPI(t)
	f.push(reply{status: 401, body: map[string]any{"title": "Unauthorized", "status": 401}})
	if _, err := f.client().Wallet.Get(ctx); !IsAuthentication(err) {
		t.Fatalf("esperado Authentication, obtido %v", err)
	}
	f.push(reply{status: 403, body: map[string]any{"title": "Proibido"}})
	if _, err := f.client().Wallet.Get(ctx); !IsPermissionDenied(err) {
		t.Fatalf("esperado PermissionDenied, obtido %v", err)
	}
	f.push(reply{status: 404, body: map[string]any{"title": "Mensagem não encontrada.", "status": 404}})
	if _, err := f.client().Messages.Get(ctx, "m1"); !IsNotFound(err) {
		t.Fatalf("esperado NotFound, obtido %v", err)
	}
	f.push(reply{status: 429, body: map[string]any{}}, reply{status: 429, body: map[string]any{}},
		reply{status: 429, body: map[string]any{}, headers: map[string]string{"Retry-After": "5"}})
	_, err := f.client().Wallet.Get(ctx)
	if !IsRateLimited(err) {
		t.Fatalf("esperado RateLimit, obtido %v", err)
	}
	apiErr, _ := AsAPIError(err)
	if apiErr.RetryAfter == nil || *apiErr.RetryAfter != 5*time.Second {
		t.Fatalf("RetryAfter esperado 5s, obtido %v", apiErr.RetryAfter)
	}
	if IsNotFound(err) || IsInsufficientFunds(nil) {
		t.Fatal("as funções Is* não devem confundir tipos")
	}
}

type failingTransport struct{ calls int }

func (t *failingTransport) RoundTrip(*http.Request) (*http.Response, error) {
	t.calls++
	return nil, errors.New("connection refused")
}

func TestNetworkErrorBecomesConnectionErrorAfterRetries(t *testing.T) {
	transport := &failingTransport{}
	var sleeps int
	c, err := NewClientBuilder().PublicKey(testPK).APIKey(testAK).BaseURL("http://127.0.0.1:1").
		HTTPClient(&http.Client{Transport: transport}).
		withSleep(func(context.Context, time.Duration) error { sleeps++; return nil }).Build()
	if err != nil {
		t.Fatal(err)
	}
	_, err = c.Wallet.Get(ctx)
	if !IsConnectionError(err) || !errors.Is(err, ErrConnection) {
		t.Fatalf("esperado ConnectionError, obtido %v", err)
	}
	var connErr *ConnectionError
	if !errors.As(err, &connErr) || connErr.Timeout {
		t.Fatalf("ConnectionError inesperado: %#v", err)
	}
	assertErrContains(t, err, "Não foi possível contactar 127.0.0.1:1")
	assertEqual(t, transport.calls, 3)
	assertEqual(t, sleeps, 2)

	transport.calls = 0
	_, err = c.Contacts.Create(ctx, mustBuild(NewContactRequest().Name("Ana").Build()))
	if !IsConnectionError(err) {
		t.Fatalf("esperado ConnectionError, obtido %v", err)
	}
	assertEqual(t, transport.calls, 1) // POST /contacts is not idempotent: never retried

	transport.calls = 0
	_, _ = c.Contacts.Update(ctx, "k1", mustBuild(NewContactRequest().Name("Ana").Build()))
	_, _ = c.Contacts.RecordConsent(ctx, "k1", ChannelSMS, ConsentGranted, "erp")
	assertEqual(t, transport.calls, 2) // PATCH and consents: never retried

	transport.calls = 0
	_, _ = c.Contacts.UpsertByExternalID(ctx, "x", mustBuild(NewContactRequest().Name("Ana").Build()))
	assertEqual(t, transport.calls, 3) // PUT upsert is idempotent
}

func TestTimeoutBecomesConnectionError(t *testing.T) {
	release := make(chan struct{})
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		select {
		case <-release:
		case <-r.Context().Done():
		}
	}))
	defer server.Close()
	defer close(release)
	c, err := NewClientBuilder().PublicKey(testPK).APIKey(testAK).BaseURL(server.URL).
		Timeout(50 * time.Millisecond).MaxRetries(0).Build()
	if err != nil {
		t.Fatal(err)
	}
	_, err = c.Wallet.Get(ctx)
	var connErr *ConnectionError
	if !errors.As(err, &connErr) || !connErr.Timeout {
		t.Fatalf("esperado timeout, obtido %v", err)
	}
	assertErrContains(t, err, "Tempo limite excedido")
}

func TestCancelledContextIsNotRetried(t *testing.T) {
	transport := &failingTransport{}
	c, _ := NewClientBuilder().PublicKey(testPK).APIKey(testAK).HTTPClient(&http.Client{Transport: transport}).
		withSleep(func(context.Context, time.Duration) error { return nil }).Build()
	cancelled, cancel := context.WithCancel(ctx)
	cancel()
	_, err := c.Wallet.Get(cancelled)
	if !IsConnectionError(err) {
		t.Fatalf("esperado ConnectionError, obtido %v", err)
	}
	if transport.calls > 1 {
		t.Fatalf("não devia repetir com o contexto cancelado (%d chamadas)", transport.calls)
	}
}

func TestEstimateGetAndListWithQueryString(t *testing.T) {
	f := newFakeAPI(t)
	f.push(reply{status: 200, body: map[string]any{
		"channels": []any{map[string]any{"channel": "sms", "recipients": 1, "segments": 1, "encoding": "gsm7", "unit_price": 1.5, "cost": 1.5}},
		"total":    1.5, "available": 10, "invalid": []any{}, "unknown_field": true,
	}})
	estimate, err := f.client().Messages.Estimate(ctx, smsRequest(t))
	if err != nil {
		t.Fatal(err)
	}
	assertEqual(t, f.seen[0].uri, "/api/v1/messages/estimate")
	assertEqual(t, f.seen[0].header.Get("Idempotency-Key"), "")
	assertEqual(t, estimate.Channels[0].UnitPrice, 1.5)
	assertEqual(t, estimate.Channels[0].Encoding, EncodingGSM7)
	assertEqual(t, estimate.Available, 10.0)

	f.push(reply{status: 200, body: map[string]any{
		"items": []any{map[string]any{"id": "m1", "campaign_id": "c1", "status": "delivered", "created_at": "2026-11-08T10:15:00Z",
			"delivered_at": "2026-11-08T10:15:03Z", "attempts": []any{map[string]any{"part_number": 1, "status": "in_flight", "started_at": "2026-11-08T10:15:01Z"}}}},
		"page": 2, "page_size": 10, "total": 11,
	}})
	q := mustBuild(NewMessageQuery().CampaignID("c1").Status(MessageFailed).Page(2).PageSize(10).Build())
	page, err := f.client().Messages.List(ctx, q)
	if err != nil {
		t.Fatal(err)
	}
	assertEqual(t, f.seen[1].uri, "/api/v1/messages?campaign_id=c1&status=failed&page=2&page_size=10")
	assertEqual(t, f.seen[1].raw, "")
	assertEqual(t, f.seen[1].header.Get("Content-Type"), "")
	assertEqual(t, page.PageSize, 10)
	assertEqual(t, page.Total, 11)
	m := page.Items[0]
	assertEqual(t, m.Attempts[0].PartNumber, 1)
	assertEqual(t, m.Attempts[0].Status, AttemptInFlight)
	assertEqual(t, m.CreatedAt, time.Date(2026, 11, 8, 10, 15, 0, 0, time.UTC))
	if m.DeliveredAt == nil || m.AcceptedAt != nil || m.Sender != nil {
		t.Fatalf("campos opcionais mal lidos: %#v", m)
	}

	f.push(reply{status: 200, body: map[string]any{"items": []any{}, "page": 1, "page_size": 50, "total": 0}})
	if _, err := f.client().Contacts.List(ctx, mustBuild(NewContactQuery().Tag("vip").Build())); err != nil {
		t.Fatal(err)
	}
	assertEqual(t, f.seen[2].uri, "/api/v1/contacts?tag=vip")

	f.push(reply{status: 200, body: map[string]any{"items": []any{}}})
	if _, err := f.client().Messages.List(ctx, nil); err != nil {
		t.Fatal(err)
	}
	assertEqual(t, f.seen[3].uri, "/api/v1/messages")

	f.push(reply{status: 200, body: map[string]any{"items": []any{}}})
	cq := mustBuild(NewContactQuery().Search("ana silva").GroupID("g1").Status(ContactActive).Page(1).PageSize(200).Build())
	if _, err := f.client().Contacts.List(ctx, cq); err != nil {
		t.Fatal(err)
	}
	assertEqual(t, f.seen[4].uri, "/api/v1/contacts?search=ana+silva&group_id=g1&status=active&page=1&page_size=200")

	_, err = NewMessageQuery().PageSize(500).Build()
	assertErrContains(t, err, "pageSize")
	_, err = NewContactQuery().PageSize(0).Build()
	assertErrContains(t, err, "pageSize")
	_, err = NewMessageQuery().Page(0).Build()
	assertErrContains(t, err, "page")
}

// ------------------------------------------------------------------ Contacts

func TestUpsertEncodesExternalIDAndKeepsAttributeNames(t *testing.T) {
	f := newFakeAPI(t)
	f.push(reply{status: 201, body: map[string]any{"id": "k1", "external_id": "CLI/42 A", "status": "active",
		"attributes": map[string]any{"data_vencimento": "2026-12-01"}, "group_ids": []any{}, "created_at": "2026-11-08T10:00:00Z"}})
	req := mustBuild(NewContactRequest().Name("Ana").Phone("841234567").Tags("vip", "maputo").
		Attribute("data_vencimento", "2026-12-01").RemoveAttribute("velho").Build())
	contact, err := f.client().Contacts.UpsertByExternalID(ctx, "CLI/42 A", req)
	if err != nil {
		t.Fatal(err)
	}
	assertEqual(t, f.seen[0].method, "PUT")
	assertEqual(t, f.seen[0].uri, "/api/v1/contacts/external/CLI%2F42%20A")
	var want map[string]any
	_ = json.Unmarshal([]byte(`{"name":"Ana","phone":"841234567","tags":["vip","maputo"],"attributes":{"data_vencimento":"2026-12-01","velho":null}}`), &want)
	assertEqual(t, f.seen[0].body, want)
	if contact.ExternalID == nil || *contact.ExternalID != "CLI/42 A" {
		t.Fatalf("ExternalID: %v", contact.ExternalID)
	}
	assertEqual(t, contact.Attributes, map[string]string{"data_vencimento": "2026-12-01"})
	assertEqual(t, contact.Status, ContactActive)
}

func TestUpdateSendsOnlySetFieldsAndConsentValidatesStatus(t *testing.T) {
	f := newFakeAPI(t)
	f.push(reply{status: 200, body: map[string]any{"id": "k1"}})
	if _, err := f.client().Contacts.Update(ctx, "k1", mustBuild(NewContactRequest().Status(ContactInactive).Tags().Build())); err != nil {
		t.Fatal(err)
	}
	assertEqual(t, f.seen[0].method, "PATCH")
	assertEqual(t, f.seen[0].uri, "/api/v1/contacts/k1")
	assertEqual(t, f.seen[0].body, map[string]any{"status": "inactive", "tags": []any{}})

	f.push(reply{status: 200, body: map[string]any{"id": "k1"}})
	if _, err := f.client().Contacts.RecordConsent(ctx, "k1", ChannelSMS, ConsentRevoked, "erp"); err != nil {
		t.Fatal(err)
	}
	assertEqual(t, f.seen[1].uri, "/api/v1/contacts/k1/consents")
	assertEqual(t, f.seen[1].body, map[string]any{"channel": "sms", "status": "revoked", "source": "erp"})

	_, err := f.client().Contacts.RecordConsent(ctx, "k1", ChannelSMS, "talvez", "erp")
	assertErrContains(t, err, "granted")
	_, err = NewContactRequest().Status("talvez").Build()
	assertErrContains(t, err, "active")
	_, err = f.client().Contacts.Get(ctx, "")
	assertErrContains(t, err, "Identificador em falta")
	_, err = f.client().Contacts.Create(ctx, nil)
	assertErrContains(t, err, "NewContactRequest")
	assertEqual(t, len(f.seen), 2) // validation errors never reach the network
}

func TestWalletAndCampaigns(t *testing.T) {
	f := newFakeAPI(t)
	f.push(reply{status: 200, body: map[string]any{"currency": "MZN", "balance": 10, "reserved": 1, "available": 9}})
	w, err := f.client().Wallet.Get(ctx)
	if err != nil {
		t.Fatal(err)
	}
	assertEqual(t, w.Available, 9.0)
	assertEqual(t, f.seen[0].header.Get("X-Api-Key"), testAK)

	f.push(reply{status: 200, body: map[string]any{"items": []any{map[string]any{"business_reference": "r1", "type": "debit", "amount": 1.5}}, "page": 1, "page_size": 20, "total": 1}})
	tx, err := f.client().Wallet.Transactions(ctx, 1, 20)
	if err != nil {
		t.Fatal(err)
	}
	assertEqual(t, tx.Items[0].BusinessReference, "r1")
	assertEqual(t, tx.Items[0].Type, TransactionDebit)
	assertEqual(t, f.seen[1].uri, "/api/v1/wallet/transactions?page=1&page_size=20")

	f.push(reply{status: 200, body: map[string]any{"items": []any{}}})
	if _, err := f.client().Wallet.Transactions(ctx, 0, 0); err != nil {
		t.Fatal(err)
	}
	assertEqual(t, f.seen[2].uri, "/api/v1/wallet/transactions")

	f.push(reply{status: 200, body: map[string]any{"campaign_id": "c1", "messages": map[string]any{"delivered": 3}, "unique_clicks": 1, "estimated_cost": 4.5}})
	stats, err := f.client().Campaigns.Stats(ctx, "c1")
	if err != nil {
		t.Fatal(err)
	}
	assertEqual(t, f.seen[3].uri, "/api/v1/campaigns/c1/stats")
	assertEqual(t, stats.Messages["delivered"], 3)
	assertEqual(t, stats.UniqueClicks, 1)
	assertEqual(t, stats.EstimatedCost, 4.5)

	f.push(reply{status: 200, body: map[string]any{"id": "c1", "name": "Promo", "status": "paused", "pause_reason": "saldo", "delivered": 2}})
	campaign, err := f.client().Campaigns.Get(ctx, "c1")
	if err != nil {
		t.Fatal(err)
	}
	assertEqual(t, f.seen[4].uri, "/api/v1/campaigns/c1")
	assertEqual(t, campaign.Status, CampaignPaused)
	if campaign.Delivered == nil || *campaign.Delivered != 2 || campaign.Failed != nil || *campaign.PauseReason != "saldo" {
		t.Fatalf("campanha mal lida: %#v", campaign)
	}
}

// ------------------------------------------------------------------ Request builders

func TestSendMessageRequestValidation(t *testing.T) {
	b := NewSendMessageRequest
	cases := []struct {
		name string
		b    *SendMessageRequestBuilder
		want string
	}{
		{"sem canais", b().To("+258841234567"), "entre 1 e 3"},
		{"quatro canais", b().SMS("a").Email("s", "c").WhatsApp("w").SMS("b").To("x"), "entre 1 e 3"},
		{"canal repetido", b().SMS("a").SMS("b").To("x"), "uma vez"},
		{"sem destinatário", b().SMS("a"), "destinatário"},
		{"SMS vazio", b().SMS("  ").To("x"), "SMS"},
		{"email sem assunto", b().Email("", "x").To("x"), "assunto"},
		{"email sem conteúdo", b().Email("s", "").To("x"), "email precisa de conteúdo"},
		{"WhatsApp vazio", b().WhatsApp("").To("x"), "WhatsApp"},
		{"template sem nome", b().WhatsAppTemplate("", "pt_PT").To("x"), "template"},
		{"destinatários a mais", b().SMS("a").To(make([]string, 1001)...), "1000"},
		{"contactos a mais", b().SMS("a").Contacts(make([]string, 1001)...), "1000"},
		{"chave com espaço", b().SMS("a").To("x").IdempotencyKey("com espaço"), "idempotencyKey"},
		{"chave longa", b().SMS("a").To("x").IdempotencyKey(strings.Repeat("x", 201)), "idempotencyKey"},
		{"chave vazia", b().SMS("a").To("x").IdempotencyKey(""), "idempotencyKey"},
		{"chave não ASCII", b().SMS("a").To("x").IdempotencyKey("fatura-ç"), "idempotencyKey"},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			_, err := tc.b.Build()
			assertErrContains(t, err, tc.want)
		})
	}

	ok := mustBuild(b().SMS("a").To("x").IdempotencyKey(strings.Repeat("~", 200)).Build())
	assertEqual(t, ok.IdempotencyKey(), strings.Repeat("~", 200))

	builder := b().WhatsAppTemplate("aviso", "pt_PT", "Ana", "FT 1").Contacts("k1", "k2")
	wa := mustBuild(builder.Build())
	raw, _ := json.Marshal(wa.body)
	assertEqual(t, string(raw), `{"channels":[{"channel":"whatsapp","whatsapp_template":{"name":"aviso","language":"pt_PT","parameters":["Ana","FT 1"]}}],"contact_ids":["k1","k2"]}`)

	// The built request is a snapshot: later builder calls do not change it.
	builder.Contact("k3")
	raw2, _ := json.Marshal(wa.body)
	assertEqual(t, string(raw2), string(raw))
	_, err := b().SMS("a").To("x").Build()
	if err != nil {
		t.Fatalf("pedido válido rejeitado: %v", err)
	}
}

func TestSendRejectsNilRequest(t *testing.T) {
	f := newFakeAPI(t)
	_, err := f.client().Messages.Send(ctx, nil)
	assertErrContains(t, err, "NewSendMessageRequest")
	assertEqual(t, len(f.seen), 0)
}

func TestParseRetryAfter(t *testing.T) {
	now := time.Date(2026, 11, 8, 10, 0, 0, 0, time.UTC)
	if parseRetryAfter("", now) != nil || parseRetryAfter("abc", now) != nil || parseRetryAfter("-1", now) != nil {
		t.Fatal("valores inválidos devem dar nil")
	}
	assertEqual(t, *parseRetryAfter("3", now), 3*time.Second)
	assertEqual(t, *parseRetryAfter(now.Add(10*time.Second).Format(http.TimeFormat), now), 10*time.Second)
}

func TestBackoffBounds(t *testing.T) {
	for attempt := 0; attempt < 20; attempt++ {
		base := min(baseBackoff<<min(attempt, 10), maxBackoff)
		for i := 0; i < 50; i++ {
			d := backoff(attempt)
			lo, hi := time.Duration(float64(base)*0.8), time.Duration(float64(base)*1.2)
			if d < lo || d > hi {
				t.Fatalf("backoff(%d) = %v fora de [%v, %v]", attempt, d, lo, hi)
			}
		}
	}
}

func TestErrorMessageFallbacks(t *testing.T) {
	assertEqual(t, errorFor(418, []byte("não é json"), nil).Error(), "HTTP 418")
	assertEqual(t, errorFor(418, nil, nil).Kind, KindUnknown)
	e := errorFor(502, []byte(`{"detail":"Gateway","trace_id":"t9"}`), nil)
	assertEqual(t, fmt.Sprint(e.Error(), e.TraceID, e.Kind), "Gatewayt9server")
}
