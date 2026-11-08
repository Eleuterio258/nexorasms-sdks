package nexorasms

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"strings"
	"testing"
	"time"
)

const (
	webhookSecret = "whsec_test"
	webhookBody   = `{"id":"evt_1","type":"ping","created_at":"2026-11-08T10:20:00Z","data":{"message":"ping"}}`
	webhookT      = 1762597200
	webhookV1     = "cde593b98434dd7cd2944443131e9bf1bf47bc37630a621aa9481d76ab38b77e"
)

func verifierAt(t *testing.T, unix int64) *WebhookVerifier {
	t.Helper()
	v, err := NewWebhookVerifier(webhookSecret, WithClock(func() time.Time { return time.Unix(unix, 0) }))
	if err != nil {
		t.Fatal(err)
	}
	return v
}

func sign(t int64, body string) string {
	mac := hmac.New(sha256.New, []byte(webhookSecret))
	fmt.Fprintf(mac, "%d.%s", t, body)
	return hex.EncodeToString(mac.Sum(nil))
}

func TestWebhookFixedVectorIsValid(t *testing.T) {
	assertEqual(t, sign(webhookT, webhookBody), webhookV1)
	event, err := verifierAt(t, webhookT).Verify([]byte(webhookBody), fmt.Sprintf("t=%d,v1=%s", webhookT, webhookV1))
	if err != nil {
		t.Fatal(err)
	}
	assertEqual(t, event.ID, "evt_1")
	assertEqual(t, event.Type, EventPing)
	assertEqual(t, event.CreatedAt, time.Date(2026, 11, 8, 10, 20, 0, 0, time.UTC))
	var data map[string]any
	if err := event.DecodeData(&data); err != nil {
		t.Fatal(err)
	}
	assertEqual(t, data, map[string]any{"message": "ping"})
	if event.IsMessageEvent() {
		t.Fatal("ping não é evento de mensagem")
	}
	if _, err := event.MessageEvent(); err == nil {
		t.Fatal("MessageEvent devia falhar para ping")
	}
}

func TestWebhookRejectsBadSignatureExpiredAndMalformed(t *testing.T) {
	header := fmt.Sprintf("t=%d,v1=%s", webhookT, webhookV1)
	cases := []struct {
		name   string
		now    int64
		body   string
		header string
		want   string
	}{
		{"assinatura errada", webhookT, webhookBody, fmt.Sprintf("t=%d,v1=%s", webhookT, strings.Repeat("0", 64)), "inválida"},
		{"corpo alterado", webhookT, webhookBody + " ", header, "inválida"},
		{"assinatura em maiúsculas", webhookT, webhookBody, fmt.Sprintf("t=%d,v1=%s", webhookT, strings.ToUpper(webhookV1)), "inválida"},
		{"expirado", webhookT + 301, webhookBody, header, "janela"},
		{"do futuro", webhookT - 301, webhookBody, header, "janela"},
		{"sem t", webhookT, webhookBody, "v1=" + webhookV1, "mal formado"},
		{"sem v1", webhookT, webhookBody, fmt.Sprintf("t=%d", webhookT), "mal formado"},
		{"vazio", webhookT, webhookBody, "", "mal formado"},
		{"t não numérico", webhookT, webhookBody, "t=abc,v1=" + webhookV1, "mal formado"},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			_, err := verifierAt(t, tc.now).Verify([]byte(tc.body), tc.header)
			assertErrContains(t, err, tc.want)
			var sigErr *WebhookSignatureError
			if !errors.As(err, &sigErr) || !errors.Is(err, ErrWebhookSignature) {
				t.Fatalf("esperado *WebhookSignatureError, obtido %T", err)
			}
		})
	}
	// Exactly at the tolerance edge is accepted; spaces around parts are tolerated.
	if _, err := verifierAt(t, webhookT-300).Verify([]byte(webhookBody), fmt.Sprintf(" t=%d , v1=%s ", webhookT, webhookV1)); err != nil {
		t.Fatal(err)
	}
}

func TestWebhookToleranceOptionAndConstructor(t *testing.T) {
	v, err := NewWebhookVerifier(webhookSecret, WithTolerance(10*time.Second), WithClock(func() time.Time { return time.Unix(webhookT+11, 0) }))
	if err != nil {
		t.Fatal(err)
	}
	_, err = v.Verify([]byte(webhookBody), fmt.Sprintf("t=%d,v1=%s", webhookT, webhookV1))
	assertErrContains(t, err, "janela")
	_, err = NewWebhookVerifier("")
	assertErrContains(t, err, "segredo")
	_, err = NewWebhookVerifier(webhookSecret, WithTolerance(-time.Second))
	assertErrContains(t, err, "negativa")
}

func TestWebhookInvalidJSONBody(t *testing.T) {
	body := "not json"
	_, err := verifierAt(t, webhookT).Verify([]byte(body), fmt.Sprintf("t=%d,v1=%s", webhookT, sign(webhookT, body)))
	assertErrContains(t, err, "JSON")
}

func TestWebhookMessageEvent(t *testing.T) {
	raw, _ := json.Marshal(map[string]any{"id": "e2", "type": "message.delivered", "created_at": "2026-11-08T10:00:00Z",
		"data": map[string]any{"message_id": "m1", "campaign_id": "c1", "contact_id": "k1", "external_id": "CLI-1", "channel": "sms",
			"status": "delivered", "cost": 1.5, "occurred_at": "2026-11-08T10:00:00Z"}})
	body := string(raw)
	event, err := verifierAt(t, webhookT).Verify(raw, fmt.Sprintf("t=%d,v1=%s", webhookT, sign(webhookT, body)))
	if err != nil {
		t.Fatal(err)
	}
	if !event.IsMessageEvent() {
		t.Fatal("esperado evento de mensagem")
	}
	m, err := event.MessageEvent()
	if err != nil {
		t.Fatal(err)
	}
	assertEqual(t, m.MessageID, "m1")
	assertEqual(t, *m.ExternalID, "CLI-1")
	assertEqual(t, m.Status, MessageDelivered)
	assertEqual(t, m.Cost, 1.5)
	assertEqual(t, m.OccurredAt, time.Date(2026, 11, 8, 10, 0, 0, 0, time.UTC))
}
