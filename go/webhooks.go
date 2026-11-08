package nexorasms

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"strconv"
	"strings"
	"time"
)

// WebhookEventType is the type of a webhook event.
type WebhookEventType string

// Webhook event types.
const (
	EventMessageDelivered  WebhookEventType = "message.delivered"
	EventMessageFailed     WebhookEventType = "message.failed"
	EventCampaignCompleted WebhookEventType = "campaign.completed"
	EventCampaignPaused    WebhookEventType = "campaign.paused"
	EventContactOptedOut   WebhookEventType = "contact.opted_out"
	EventPing              WebhookEventType = "ping"
)

// DefaultWebhookTolerance is the accepted difference between now and the signature timestamp.
const DefaultWebhookTolerance = 300 * time.Second

// WebhookEvent is a verified webhook delivery.
type WebhookEvent struct {
	ID        string           `json:"id"`
	Type      WebhookEventType `json:"type"`
	CreatedAt time.Time        `json:"created_at"`
	// Data is the raw JSON of the event payload; see MessageEvent and DecodeData.
	Data json.RawMessage `json:"data"`
}

// MessageEvent is the payload of message.delivered and message.failed.
type MessageEvent struct {
	MessageID  string        `json:"message_id"`
	CampaignID string        `json:"campaign_id"`
	ContactID  string        `json:"contact_id"`
	ExternalID *string       `json:"external_id"`
	Channel    Channel       `json:"channel"`
	Status     MessageStatus `json:"status"`
	Cost       float64       `json:"cost"`
	OccurredAt time.Time     `json:"occurred_at"`
}

// IsMessageEvent reports message.delivered / message.failed (Data is a MessageEvent).
func (e *WebhookEvent) IsMessageEvent() bool {
	return e.Type == EventMessageDelivered || e.Type == EventMessageFailed
}

// MessageEvent decodes Data for message.delivered / message.failed events.
func (e *WebhookEvent) MessageEvent() (*MessageEvent, error) {
	if !e.IsMessageEvent() {
		return nil, errors.New("O evento " + string(e.Type) + " não é um evento de mensagem.")
	}
	var m MessageEvent
	if err := e.DecodeData(&m); err != nil {
		return nil, err
	}
	return &m, nil
}

// DecodeData decodes Data into v (any JSON target).
func (e *WebhookEvent) DecodeData(v any) error { return json.Unmarshal(e.Data, v) }

// WebhookOption configures a WebhookVerifier.
type WebhookOption func(*WebhookVerifier)

// WithTolerance changes the accepted clock difference (default 300 s).
func WithTolerance(d time.Duration) WebhookOption {
	return func(v *WebhookVerifier) { v.tolerance = d }
}

// WithClock injects the clock (tests).
func WithClock(now func() time.Time) WebhookOption { return func(v *WebhookVerifier) { v.now = now } }

// WebhookVerifier checks X-Nexora-Signature (t=<unix>,v1=<hex HMAC-SHA256(secret, "<t>.<body>")>) and parses
// the event. It is safe for concurrent use.
type WebhookVerifier struct {
	secret    []byte
	tolerance time.Duration
	now       func() time.Time
}

// NewWebhookVerifier creates a verifier with the endpoint's signing secret (whsec_…).
func NewWebhookVerifier(secret string, opts ...WebhookOption) (*WebhookVerifier, error) {
	if secret == "" {
		return nil, errors.New("Indique o segredo do webhook (whsec_…).")
	}
	v := &WebhookVerifier{secret: []byte(secret), tolerance: DefaultWebhookTolerance, now: time.Now}
	for _, opt := range opts {
		opt(v)
	}
	if v.tolerance < 0 {
		return nil, errors.New("A tolerância do webhook não pode ser negativa.")
	}
	if v.now == nil {
		v.now = time.Now
	}
	return v, nil
}

// Verify checks the signature header against the raw body (exactly as received, before any JSON parsing)
// and returns the event. Failures are *WebhookSignatureError.
func (v *WebhookVerifier) Verify(body []byte, header string) (*WebhookEvent, error) {
	parts := map[string]string{}
	for _, piece := range strings.Split(header, ",") {
		if i := strings.Index(piece, "="); i > 0 {
			parts[strings.TrimSpace(piece[:i])] = strings.TrimSpace(piece[i+1:])
		}
	}
	t, v1 := parts["t"], parts["v1"]
	timestamp, err := strconv.ParseInt(t, 10, 64)
	if t == "" || err != nil || v1 == "" {
		return nil, &WebhookSignatureError{Reason: "Cabeçalho X-Nexora-Signature mal formado."}
	}

	mac := hmac.New(sha256.New, v.secret)
	mac.Write([]byte(t + "."))
	mac.Write(body)
	expected := hex.EncodeToString(mac.Sum(nil))
	if !hmac.Equal([]byte(v1), []byte(expected)) {
		return nil, &WebhookSignatureError{Reason: "Assinatura do webhook inválida."}
	}
	diff := v.now().Sub(time.Unix(timestamp, 0))
	if diff < 0 {
		diff = -diff
	}
	if diff > v.tolerance {
		return nil, &WebhookSignatureError{Reason: "Webhook fora da janela de tempo permitida."}
	}

	var event WebhookEvent
	if err := json.Unmarshal(body, &event); err != nil {
		return nil, &WebhookSignatureError{Reason: "Corpo do webhook não é JSON válido."}
	}
	return &event, nil
}
