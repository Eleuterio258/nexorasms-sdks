package nexorasms

import "time"

// Enumerations are plain strings with constants, so values added later by the API never break decoding.

// Channel is a sending channel.
type Channel string

const (
	ChannelSMS      Channel = "sms"
	ChannelWhatsApp Channel = "whatsapp"
	ChannelEmail    Channel = "email"
)

// MessageStatus is the state of a message.
type MessageStatus string

const (
	MessagePending    MessageStatus = "pending"
	MessageProcessing MessageStatus = "processing"
	MessageAccepted   MessageStatus = "accepted"
	MessageDelivered  MessageStatus = "delivered"
	MessageFailed     MessageStatus = "failed"
	MessageUnknown    MessageStatus = "unknown"
	MessageCancelled  MessageStatus = "cancelled"
)

// AttemptStatus is the state of one delivery attempt.
type AttemptStatus string

const (
	AttemptInFlight AttemptStatus = "in_flight"
	AttemptAccepted AttemptStatus = "accepted"
	AttemptRejected AttemptStatus = "rejected"
	AttemptFailed   AttemptStatus = "failed"
	AttemptUnknown  AttemptStatus = "unknown"
)

// ContactStatus is the state of a contact.
type ContactStatus string

const (
	ContactActive   ContactStatus = "active"
	ContactInactive ContactStatus = "inactive"
)

// ConsentStatus is the state of a consent.
type ConsentStatus string

const (
	ConsentGranted ConsentStatus = "granted"
	ConsentRevoked ConsentStatus = "revoked"
)

// Encoding of an SMS.
type Encoding string

const (
	EncodingGSM7 Encoding = "gsm7"
	EncodingUCS2 Encoding = "ucs2"
	EncodingUTF8 Encoding = "utf8"
)

// TransactionType is the kind of wallet transaction.
type TransactionType string

const (
	TransactionCredit     TransactionType = "credit"
	TransactionDebit      TransactionType = "debit"
	TransactionReserve    TransactionType = "reserve"
	TransactionRelease    TransactionType = "release"
	TransactionRefund     TransactionType = "refund"
	TransactionWithdrawal TransactionType = "withdrawal"
)

// CampaignStatus is the state of a campaign.
type CampaignStatus string

const (
	CampaignDraft     CampaignStatus = "draft"
	CampaignScheduled CampaignStatus = "scheduled"
	CampaignRunning   CampaignStatus = "running"
	CampaignPaused    CampaignStatus = "paused"
	CampaignCompleted CampaignStatus = "completed"
	CampaignCancelled CampaignStatus = "cancelled"
	CampaignFailed    CampaignStatus = "failed"
)

// SendMessageResult is the response of Messages.Send: one campaign per channel.
type SendMessageResult struct {
	Channels []SendChannelResult `json:"channels"`
	Invalid  []string            `json:"invalid"`
	// IdempotencyKey is the key sent (given or generated); not part of the JSON.
	IdempotencyKey string `json:"-"`
	// Replayed is true when the API returned a stored result (Idempotent-Replayed: true).
	Replayed bool `json:"-"`
}

// SendChannelResult is the campaign created for one channel.
type SendChannelResult struct {
	Channel    Channel        `json:"channel"`
	CampaignID string         `json:"campaign_id"`
	Recipients int            `json:"recipients"`
	Cost       float64        `json:"cost"`
	Status     CampaignStatus `json:"status"`
}

// EstimateResult is the response of Messages.Estimate.
type EstimateResult struct {
	Channels  []EstimateChannel `json:"channels"`
	Total     float64           `json:"total"`
	Available float64           `json:"available"`
	Invalid   []string          `json:"invalid"`
}

// EstimateChannel is the estimate for one channel.
type EstimateChannel struct {
	Channel    Channel  `json:"channel"`
	Recipients int      `json:"recipients"`
	Segments   int      `json:"segments"`
	Encoding   Encoding `json:"encoding"`
	UnitPrice  float64  `json:"unit_price"`
	Cost       float64  `json:"cost"`
}

// Message is one message to one destination.
type Message struct {
	ID           string           `json:"id"`
	CampaignID   string           `json:"campaign_id"`
	Channel      Channel          `json:"channel"`
	Destination  string           `json:"destination"`
	Status       MessageStatus    `json:"status"`
	Cost         float64          `json:"cost"`
	Parts        int              `json:"parts"`
	CreatedAt    time.Time        `json:"created_at"`
	AcceptedAt   *time.Time       `json:"accepted_at"`
	DeliveredAt  *time.Time       `json:"delivered_at"`
	Attempts     []MessageAttempt `json:"attempts"`
	CampaignName *string          `json:"campaign_name"`
	Sender       *string          `json:"sender"`
	Subject      *string          `json:"subject"`
	Content      *string          `json:"content"`
	Template     *string          `json:"template"`
	Language     *string          `json:"language"`
}

// MessageAttempt is one delivery attempt of one part of a message.
type MessageAttempt struct {
	PartNumber        int           `json:"part_number"`
	AttemptNumber     int           `json:"attempt_number"`
	Status            AttemptStatus `json:"status"`
	ProviderMessageID *string       `json:"provider_message_id"`
	ErrorCode         *string       `json:"error_code"`
	StartedAt         time.Time     `json:"started_at"`
	CompletedAt       *time.Time    `json:"completed_at"`
}

// Contact is a contact of the account.
type Contact struct {
	ID         string            `json:"id"`
	Name       *string           `json:"name"`
	Phone      *string           `json:"phone"`
	Email      *string           `json:"email"`
	Status     ContactStatus     `json:"status"`
	GroupIDs   []string          `json:"group_ids"`
	Tags       []string          `json:"tags"`
	Consents   []Consent         `json:"consents"`
	CreatedAt  time.Time         `json:"created_at"`
	Attributes map[string]string `json:"attributes"`
	ExternalID *string           `json:"external_id"`
}

// Consent is the consent of a contact for one channel.
type Consent struct {
	Channel    Channel       `json:"channel"`
	Status     ConsentStatus `json:"status"`
	Source     string        `json:"source"`
	RecordedAt time.Time     `json:"recorded_at"`
}

// Page is one page of a list.
type Page[T any] struct {
	Items    []T `json:"items"`
	Page     int `json:"page"`
	PageSize int `json:"page_size"`
	Total    int `json:"total"`
}

// Wallet is the account balance.
type Wallet struct {
	Currency  string  `json:"currency"`
	Balance   float64 `json:"balance"`
	Reserved  float64 `json:"reserved"`
	Available float64 `json:"available"`
}

// WalletTransaction is one movement of the wallet.
type WalletTransaction struct {
	ID                string          `json:"id"`
	Type              TransactionType `json:"type"`
	Amount            float64         `json:"amount"`
	BusinessReference string          `json:"business_reference"`
	MessageID         *string         `json:"message_id"`
	ReversalOfID      *string         `json:"reversal_of_id"`
	CreatedAt         time.Time       `json:"created_at"`
}

// Campaign is a sending campaign (direct sends create one per channel).
type Campaign struct {
	ID               string         `json:"id"`
	Name             string         `json:"name"`
	Channel          Channel        `json:"channel"`
	Status           CampaignStatus `json:"status"`
	TemplateID       *string        `json:"template_id"`
	Subject          *string        `json:"subject"`
	Content          string         `json:"content"`
	Recipients       int            `json:"recipients"`
	ScheduledAt      *time.Time     `json:"scheduled_at"`
	CreatedAt        time.Time      `json:"created_at"`
	IgnoreQuietHours bool           `json:"ignore_quiet_hours"`
	PauseReason      *string        `json:"pause_reason"`
	Sender           *string        `json:"sender"`
	Messages         *int           `json:"messages"`
	Delivered        *int           `json:"delivered"`
	Failed           *int           `json:"failed"`
}

// CampaignStats are the counters and costs of a campaign.
type CampaignStats struct {
	CampaignID    string         `json:"campaign_id"`
	Status        CampaignStatus `json:"status"`
	Recipients    int            `json:"recipients"`
	Messages      map[string]int `json:"messages"`
	SMSSegments   int            `json:"sms_segments"`
	Currency      string         `json:"currency"`
	EstimatedCost float64        `json:"estimated_cost"`
	Reserved      float64        `json:"reserved"`
	Debited       float64        `json:"debited"`
	Clicks        int            `json:"clicks"`
	UniqueClicks  int            `json:"unique_clicks"`
}
