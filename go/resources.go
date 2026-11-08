package nexorasms

import (
	"context"
	"errors"
	"strconv"
)

var errSendRequest = errors.New("Use NewSendMessageRequest() para construir o envio.")
var errContactRequest = errors.New("Use NewContactRequest() para construir o contacto.")

// ------------------------------------------------------------------ Messages

// MessagesService sends and queries messages.
type MessagesService struct{ client *Client }

// Send sends now (one campaign per channel). It uses the request's IdempotencyKey or a new UUID v4,
// reused on every retry of this call.
func (s *MessagesService) Send(ctx context.Context, req *SendMessageRequest) (*SendMessageResult, error) {
	if req == nil {
		return nil, errSendRequest
	}
	key := req.idempotencyKey
	if key == "" {
		key = newUUID()
	}
	var result SendMessageResult
	header, err := s.client.do(ctx, apiRequest{
		method: "POST", path: "/api/v1/messages", body: req.body,
		headers: map[string]string{"Idempotency-Key": key}, retryable: true,
	}, &result)
	if err != nil {
		return nil, err
	}
	result.IdempotencyKey = key
	result.Replayed = header.Get("Idempotent-Replayed") == "true"
	return &result, nil
}

// Estimate returns cost, segments and available balance. Sends nothing.
func (s *MessagesService) Estimate(ctx context.Context, req *SendMessageRequest) (*EstimateResult, error) {
	if req == nil {
		return nil, errSendRequest
	}
	var result EstimateResult
	if _, err := s.client.do(ctx, apiRequest{method: "POST", path: "/api/v1/messages/estimate", body: req.body, retryable: true}, &result); err != nil {
		return nil, err
	}
	return &result, nil
}

// Get returns one message with its attempts.
func (s *MessagesService) Get(ctx context.Context, id string) (*Message, error) {
	seg, err := pathSegment(id)
	if err != nil {
		return nil, err
	}
	return get[Message](ctx, s.client, "/api/v1/messages/"+seg, nil)
}

// List returns a page of messages; query may be nil.
func (s *MessagesService) List(ctx context.Context, query *MessageQuery) (*Page[Message], error) {
	var params []queryParam
	if query != nil {
		params = query.params
	}
	return get[Page[Message]](ctx, s.client, "/api/v1/messages", params)
}

// ------------------------------------------------------------------ Contacts

// ContactsService manages contacts.
type ContactsService struct{ client *Client }

// UpsertByExternalID creates or updates the contact with this id from your system (retried: PUT is idempotent).
func (s *ContactsService) UpsertByExternalID(ctx context.Context, externalID string, req *ContactRequest) (*Contact, error) {
	seg, err := pathSegment(externalID)
	if err != nil {
		return nil, err
	}
	return s.write(ctx, "PUT", "/api/v1/contacts/external/"+seg, req, true)
}

// Create creates a contact (never retried).
func (s *ContactsService) Create(ctx context.Context, req *ContactRequest) (*Contact, error) {
	return s.write(ctx, "POST", "/api/v1/contacts", req, false)
}

// Get returns one contact.
func (s *ContactsService) Get(ctx context.Context, id string) (*Contact, error) {
	seg, err := pathSegment(id)
	if err != nil {
		return nil, err
	}
	return get[Contact](ctx, s.client, "/api/v1/contacts/"+seg, nil)
}

// Update changes only the fields set on the request (never retried).
func (s *ContactsService) Update(ctx context.Context, id string, req *ContactRequest) (*Contact, error) {
	seg, err := pathSegment(id)
	if err != nil {
		return nil, err
	}
	return s.write(ctx, "PATCH", "/api/v1/contacts/"+seg, req, false)
}

// List returns a page of contacts; query may be nil.
func (s *ContactsService) List(ctx context.Context, query *ContactQuery) (*Page[Contact], error) {
	var params []queryParam
	if query != nil {
		params = query.params
	}
	return get[Page[Contact]](ctx, s.client, "/api/v1/contacts", params)
}

// RecordConsent records a consent (granted or revoked) for a channel (never retried).
func (s *ContactsService) RecordConsent(ctx context.Context, id string, channel Channel, status ConsentStatus, source string) (*Contact, error) {
	if status != ConsentGranted && status != ConsentRevoked {
		return nil, errors.New("status deve ser 'granted' ou 'revoked'.")
	}
	seg, err := pathSegment(id)
	if err != nil {
		return nil, err
	}
	body := map[string]string{"channel": string(channel), "status": string(status), "source": source}
	var contact Contact
	if _, err := s.client.do(ctx, apiRequest{method: "POST", path: "/api/v1/contacts/" + seg + "/consents", body: body}, &contact); err != nil {
		return nil, err
	}
	return &contact, nil
}

func (s *ContactsService) write(ctx context.Context, method, path string, req *ContactRequest, retryable bool) (*Contact, error) {
	if req == nil {
		return nil, errContactRequest
	}
	var contact Contact
	if _, err := s.client.do(ctx, apiRequest{method: method, path: path, body: req.body, retryable: retryable}, &contact); err != nil {
		return nil, err
	}
	return &contact, nil
}

// ------------------------------------------------------------------ Wallet

// WalletService reads the account balance.
type WalletService struct{ client *Client }

// Get returns the balance.
func (s *WalletService) Get(ctx context.Context) (*Wallet, error) {
	return get[Wallet](ctx, s.client, "/api/v1/wallet", nil)
}

// Transactions returns a page of wallet movements. page and pageSize equal to 0 are not sent (API defaults).
func (s *WalletService) Transactions(ctx context.Context, page, pageSize int) (*Page[WalletTransaction], error) {
	var params []queryParam
	if page < 0 {
		return nil, errors.New("page deve ser um inteiro a partir de 1.")
	}
	if pageSize < 0 || pageSize > 200 {
		return nil, errors.New("pageSize deve ser um inteiro entre 1 e 200.")
	}
	if page > 0 {
		params = append(params, queryParam{"page", strconv.Itoa(page)})
	}
	if pageSize > 0 {
		params = append(params, queryParam{"page_size", strconv.Itoa(pageSize)})
	}
	return get[Page[WalletTransaction]](ctx, s.client, "/api/v1/wallet/transactions", params)
}

// ------------------------------------------------------------------ Campaigns

// CampaignsService reads campaigns.
type CampaignsService struct{ client *Client }

// Get returns one campaign.
func (s *CampaignsService) Get(ctx context.Context, id string) (*Campaign, error) {
	seg, err := pathSegment(id)
	if err != nil {
		return nil, err
	}
	return get[Campaign](ctx, s.client, "/api/v1/campaigns/"+seg, nil)
}

// Stats returns the counters and costs of a campaign.
func (s *CampaignsService) Stats(ctx context.Context, id string) (*CampaignStats, error) {
	seg, err := pathSegment(id)
	if err != nil {
		return nil, err
	}
	return get[CampaignStats](ctx, s.client, "/api/v1/campaigns/"+seg+"/stats", nil)
}

func get[T any](ctx context.Context, c *Client, path string, query []queryParam) (*T, error) {
	var out T
	if _, err := c.do(ctx, apiRequest{method: "GET", path: path, query: query, retryable: true}, &out); err != nil {
		return nil, err
	}
	return &out, nil
}
