package nexorasms

import (
	"errors"
	"fmt"
	"strconv"
	"strings"
)

const maxList = 1000

func nonEmpty(s string) bool { return strings.TrimSpace(s) != "" }

// checkIdempotencyKey accepts 1 to 200 visible ASCII characters (0x21–0x7E).
func checkIdempotencyKey(key string) error {
	if len(key) < 1 || len(key) > 200 {
		return errIdempotencyKey
	}
	for i := 0; i < len(key); i++ {
		if key[i] < 0x21 || key[i] > 0x7E {
			return errIdempotencyKey
		}
	}
	return nil
}

var errIdempotencyKey = errors.New("idempotencyKey deve ter de 1 a 200 caracteres ASCII visíveis.")

// ------------------------------------------------------------------ SendMessageRequest

type whatsAppTemplate struct {
	Name       string   `json:"name"`
	Language   string   `json:"language"`
	Parameters []string `json:"parameters"`
}

type channelSpec struct {
	Channel          Channel           `json:"channel"`
	Subject          string            `json:"subject,omitempty"`
	Content          string            `json:"content,omitempty"`
	Sender           string            `json:"sender,omitempty"`
	WhatsAppTemplate *whatsAppTemplate `json:"whatsapp_template,omitempty"`
}

type sendMessageBody struct {
	Channels         []channelSpec `json:"channels"`
	Recipients       []string      `json:"recipients,omitempty"`
	ContactIDs       []string      `json:"contact_ids,omitempty"`
	IgnoreQuietHours *bool         `json:"ignore_quiet_hours,omitempty"`
}

// SendMessageRequest is a validated, read-only direct send (body of POST /api/v1/messages and /estimate).
type SendMessageRequest struct {
	body           sendMessageBody
	idempotencyKey string
}

// IdempotencyKey returns the key set on the builder, or "" (Send then generates one per call).
func (r *SendMessageRequest) IdempotencyKey() string { return r.idempotencyKey }

// SendMessageRequestBuilder builds a SendMessageRequest. Use NewSendMessageRequest.
type SendMessageRequestBuilder struct {
	channels         []channelSpec
	recipients       []string
	contactIDs       []string
	ignoreQuietHours *bool
	idempotencyKey   *string
}

// NewSendMessageRequest starts a direct send.
func NewSendMessageRequest() *SendMessageRequestBuilder { return &SendMessageRequestBuilder{} }

// SMS adds the SMS channel, with an optional approved Sender ID.
func (b *SendMessageRequestBuilder) SMS(content string, sender ...string) *SendMessageRequestBuilder {
	b.channels = append(b.channels, channelSpec{Channel: ChannelSMS, Content: content, Sender: first(sender)})
	return b
}

// Email adds the email channel (HTML content), with an optional sender at a verified domain.
func (b *SendMessageRequestBuilder) Email(subject, content string, sender ...string) *SendMessageRequestBuilder {
	b.channels = append(b.channels, channelSpec{Channel: ChannelEmail, Subject: subject, Content: content, Sender: first(sender)})
	return b
}

// WhatsApp adds the WhatsApp channel with free text (accepted only within 24 h of the customer's last message).
func (b *SendMessageRequestBuilder) WhatsApp(content string) *SendMessageRequestBuilder {
	b.channels = append(b.channels, channelSpec{Channel: ChannelWhatsApp, Content: content})
	return b
}

// WhatsAppTemplate adds the WhatsApp channel with a template approved by Meta; params fill {{1}}, {{2}}… in order.
func (b *SendMessageRequestBuilder) WhatsAppTemplate(name, language string, params ...string) *SendMessageRequestBuilder {
	tpl := &whatsAppTemplate{Name: name, Language: language, Parameters: append([]string{}, params...)}
	b.channels = append(b.channels, channelSpec{Channel: ChannelWhatsApp, WhatsAppTemplate: tpl})
	return b
}

// To adds E.164 phone numbers and/or email addresses (accumulates).
func (b *SendMessageRequestBuilder) To(recipients ...string) *SendMessageRequestBuilder {
	b.recipients = append(b.recipients, recipients...)
	return b
}

// Contact adds an existing contact id (accumulates).
func (b *SendMessageRequestBuilder) Contact(id string) *SendMessageRequestBuilder {
	return b.Contacts(id)
}

// Contacts adds existing contact ids (accumulates).
func (b *SendMessageRequestBuilder) Contacts(ids ...string) *SendMessageRequestBuilder {
	b.contactIDs = append(b.contactIDs, ids...)
	return b
}

// IgnoreQuietHours sends even during the account's quiet hours.
func (b *SendMessageRequestBuilder) IgnoreQuietHours(value bool) *SendMessageRequestBuilder {
	b.ignoreQuietHours = &value
	return b
}

// IdempotencyKey sets the Idempotency-Key (1 to 200 visible ASCII characters), e.g. your invoice number.
func (b *SendMessageRequestBuilder) IdempotencyKey(key string) *SendMessageRequestBuilder {
	b.idempotencyKey = &key
	return b
}

// Build validates and returns the request.
func (b *SendMessageRequestBuilder) Build() (*SendMessageRequest, error) {
	if len(b.channels) < 1 || len(b.channels) > 3 {
		return nil, errors.New("Indique entre 1 e 3 canais.")
	}
	seen := map[Channel]bool{}
	for _, c := range b.channels {
		if seen[c.Channel] {
			return nil, errors.New("Cada canal só pode aparecer uma vez.")
		}
		seen[c.Channel] = true
		switch c.Channel {
		case ChannelSMS:
			if !nonEmpty(c.Content) {
				return nil, errors.New("O SMS precisa de conteúdo.")
			}
		case ChannelEmail:
			if !nonEmpty(c.Subject) {
				return nil, errors.New("O email precisa de assunto.")
			}
			if !nonEmpty(c.Content) {
				return nil, errors.New("O email precisa de conteúdo.")
			}
		case ChannelWhatsApp:
			if !nonEmpty(c.Content) && c.WhatsAppTemplate == nil {
				return nil, errors.New("O WhatsApp precisa de conteúdo ou de um template.")
			}
			if c.WhatsAppTemplate != nil && (!nonEmpty(c.WhatsAppTemplate.Name) || !nonEmpty(c.WhatsAppTemplate.Language)) {
				return nil, errors.New("O template do WhatsApp precisa de nome e idioma.")
			}
		}
	}
	if len(b.recipients) == 0 && len(b.contactIDs) == 0 {
		return nil, errors.New("Indique pelo menos um destinatário ou contacto.")
	}
	if len(b.recipients) > maxList {
		return nil, fmt.Errorf("No máximo %d destinatários.", maxList)
	}
	if len(b.contactIDs) > maxList {
		return nil, fmt.Errorf("No máximo %d contactos.", maxList)
	}
	req := &SendMessageRequest{}
	if b.idempotencyKey != nil {
		if err := checkIdempotencyKey(*b.idempotencyKey); err != nil {
			return nil, err
		}
		req.idempotencyKey = *b.idempotencyKey
	}
	channels := make([]channelSpec, len(b.channels))
	for i, c := range b.channels {
		if c.WhatsAppTemplate != nil {
			tpl := *c.WhatsAppTemplate
			tpl.Parameters = append([]string{}, tpl.Parameters...)
			c.WhatsAppTemplate = &tpl
		}
		channels[i] = c
	}
	req.body = sendMessageBody{
		Channels:   channels,
		Recipients: cloneOrNil(b.recipients),
		ContactIDs: cloneOrNil(b.contactIDs),
	}
	if b.ignoreQuietHours != nil {
		v := *b.ignoreQuietHours
		req.body.IgnoreQuietHours = &v
	}
	return req, nil
}

// ------------------------------------------------------------------ ContactRequest

// ContactRequest is a validated, read-only contact body (create, upsert and PATCH). Only fields that were set
// are sent: on update, an absent field is left unchanged.
type ContactRequest struct {
	body map[string]any
}

// ContactRequestBuilder builds a ContactRequest. Use NewContactRequest.
type ContactRequestBuilder struct {
	name, phone, email, status, externalID *string
	tags, groupIDs                         []string
	tagsSet, groupIDsSet                   bool
	attributes                             map[string]*string
}

// NewContactRequest starts a contact body.
func NewContactRequest() *ContactRequestBuilder { return &ContactRequestBuilder{} }

func (b *ContactRequestBuilder) Name(value string) *ContactRequestBuilder { b.name = &value; return b }
func (b *ContactRequestBuilder) Phone(value string) *ContactRequestBuilder {
	b.phone = &value
	return b
}
func (b *ContactRequestBuilder) Email(value string) *ContactRequestBuilder {
	b.email = &value
	return b
}

// Status sets "active" or "inactive" (update only); see ContactActive / ContactInactive.
func (b *ContactRequestBuilder) Status(value ContactStatus) *ContactRequestBuilder {
	s := string(value)
	b.status = &s
	return b
}

// ExternalID sets the contact's id in your system.
func (b *ContactRequestBuilder) ExternalID(value string) *ContactRequestBuilder {
	b.externalID = &value
	return b
}

// Tags replaces the contact's tags when sent (accumulates); calling it with no arguments sends an empty list (clears).
func (b *ContactRequestBuilder) Tags(values ...string) *ContactRequestBuilder {
	b.tags, b.tagsSet = append(b.tags, values...), true
	return b
}

// Tag adds one tag (see Tags).
func (b *ContactRequestBuilder) Tag(value string) *ContactRequestBuilder { return b.Tags(value) }

// GroupIDs replaces the contact's groups when sent (accumulates); no arguments sends an empty list (clears).
func (b *ContactRequestBuilder) GroupIDs(ids ...string) *ContactRequestBuilder {
	b.groupIDs, b.groupIDsSet = append(b.groupIDs, ids...), true
	return b
}

// GroupID adds one group id (see GroupIDs).
func (b *ContactRequestBuilder) GroupID(id string) *ContactRequestBuilder { return b.GroupIDs(id) }

// Attribute sets a custom field.
func (b *ContactRequestBuilder) Attribute(key, value string) *ContactRequestBuilder {
	b.setAttribute(key, &value)
	return b
}

// RemoveAttribute sends the custom field as null, which removes it on update.
func (b *ContactRequestBuilder) RemoveAttribute(key string) *ContactRequestBuilder {
	b.setAttribute(key, nil)
	return b
}

// Attributes sets several custom fields.
func (b *ContactRequestBuilder) Attributes(values map[string]string) *ContactRequestBuilder {
	for k, v := range values {
		b.Attribute(k, v)
	}
	return b
}

func (b *ContactRequestBuilder) setAttribute(key string, value *string) {
	if b.attributes == nil {
		b.attributes = map[string]*string{}
	}
	b.attributes[key] = value
}

// Build validates and returns the request.
func (b *ContactRequestBuilder) Build() (*ContactRequest, error) {
	body := map[string]any{}
	set := func(key string, v *string) {
		if v != nil {
			body[key] = *v
		}
	}
	if b.status != nil && *b.status != string(ContactActive) && *b.status != string(ContactInactive) {
		return nil, errors.New("status deve ser 'active' ou 'inactive'.")
	}
	set("name", b.name)
	set("phone", b.phone)
	set("email", b.email)
	set("status", b.status)
	set("external_id", b.externalID)
	if b.tagsSet {
		body["tags"] = append([]string{}, b.tags...)
	}
	if b.groupIDsSet {
		body["group_ids"] = append([]string{}, b.groupIDs...)
	}
	if b.attributes != nil {
		attrs := make(map[string]*string, len(b.attributes))
		for k, v := range b.attributes {
			if v != nil {
				s := *v
				v = &s
			}
			attrs[k] = v
		}
		body["attributes"] = attrs
	}
	return &ContactRequest{body: body}, nil
}

// ------------------------------------------------------------------ Queries

type queryBuilder struct {
	params   []queryParam
	page     *int
	pageSize *int
}

func (q *queryBuilder) set(key, value string) {
	for i := range q.params {
		if q.params[i].key == key {
			q.params[i].value = value
			return
		}
	}
	q.params = append(q.params, queryParam{key, value})
}

func (q *queryBuilder) build() ([]queryParam, error) {
	params := append([]queryParam{}, q.params...)
	if q.page != nil {
		if *q.page < 1 {
			return nil, errors.New("page deve ser um inteiro a partir de 1.")
		}
		params = append(params, queryParam{"page", strconv.Itoa(*q.page)})
	}
	if q.pageSize != nil {
		if *q.pageSize < 1 || *q.pageSize > 200 {
			return nil, errors.New("pageSize deve ser um inteiro entre 1 e 200.")
		}
		params = append(params, queryParam{"page_size", strconv.Itoa(*q.pageSize)})
	}
	return params, nil
}

// MessageQuery filters GET /api/v1/messages. Read-only; use NewMessageQuery.
type MessageQuery struct{ params []queryParam }

// MessageQueryBuilder builds a MessageQuery.
type MessageQueryBuilder struct{ q queryBuilder }

// NewMessageQuery starts a message filter.
func NewMessageQuery() *MessageQueryBuilder { return &MessageQueryBuilder{} }

func (b *MessageQueryBuilder) CampaignID(v string) *MessageQueryBuilder {
	b.q.set("campaign_id", v)
	return b
}
func (b *MessageQueryBuilder) Status(v MessageStatus) *MessageQueryBuilder {
	b.q.set("status", string(v))
	return b
}
func (b *MessageQueryBuilder) Channel(v Channel) *MessageQueryBuilder {
	b.q.set("channel", string(v))
	return b
}
func (b *MessageQueryBuilder) Search(v string) *MessageQueryBuilder { b.q.set("search", v); return b }

// Page sets the page number (from 1).
func (b *MessageQueryBuilder) Page(v int) *MessageQueryBuilder { b.q.page = &v; return b }

// PageSize sets the page size (1–200).
func (b *MessageQueryBuilder) PageSize(v int) *MessageQueryBuilder { b.q.pageSize = &v; return b }

// Build validates and returns the query.
func (b *MessageQueryBuilder) Build() (*MessageQuery, error) {
	params, err := b.q.build()
	if err != nil {
		return nil, err
	}
	return &MessageQuery{params: params}, nil
}

// ContactQuery filters GET /api/v1/contacts. Read-only; use NewContactQuery.
type ContactQuery struct{ params []queryParam }

// ContactQueryBuilder builds a ContactQuery.
type ContactQueryBuilder struct{ q queryBuilder }

// NewContactQuery starts a contact filter.
func NewContactQuery() *ContactQueryBuilder { return &ContactQueryBuilder{} }

func (b *ContactQueryBuilder) Search(v string) *ContactQueryBuilder { b.q.set("search", v); return b }
func (b *ContactQueryBuilder) GroupID(v string) *ContactQueryBuilder {
	b.q.set("group_id", v)
	return b
}
func (b *ContactQueryBuilder) Tag(v string) *ContactQueryBuilder { b.q.set("tag", v); return b }
func (b *ContactQueryBuilder) Status(v ContactStatus) *ContactQueryBuilder {
	b.q.set("status", string(v))
	return b
}

// Page sets the page number (from 1).
func (b *ContactQueryBuilder) Page(v int) *ContactQueryBuilder { b.q.page = &v; return b }

// PageSize sets the page size (1–200).
func (b *ContactQueryBuilder) PageSize(v int) *ContactQueryBuilder { b.q.pageSize = &v; return b }

// Build validates and returns the query.
func (b *ContactQueryBuilder) Build() (*ContactQuery, error) {
	params, err := b.q.build()
	if err != nil {
		return nil, err
	}
	return &ContactQuery{params: params}, nil
}

func first(values []string) string {
	if len(values) > 0 {
		return values[0]
	}
	return ""
}

func cloneOrNil(values []string) []string {
	if len(values) == 0 {
		return nil
	}
	return append([]string{}, values...)
}
