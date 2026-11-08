// Types for @e258tech/nexorasms. Dates are ISO 8601 strings (UTC); money is a number in the wallet currency.

export declare const VERSION: string
export declare const DEFAULT_BASE_URL: string

export type ChannelName = 'sms' | 'whatsapp' | 'email'
export declare const Channel: { readonly SMS: 'sms'; readonly WHATSAPP: 'whatsapp'; readonly EMAIL: 'email' }
export type MessageStatus = 'pending' | 'processing' | 'accepted' | 'delivered' | 'failed' | 'unknown' | 'cancelled'
export type AttemptStatus = 'in_flight' | 'accepted' | 'rejected' | 'failed' | 'unknown'
export type CampaignStatus = 'draft' | 'scheduled' | 'running' | 'paused' | 'completed' | 'cancelled' | 'failed'
export type ContactStatus = 'active' | 'inactive'
export type ConsentStatus = 'granted' | 'revoked'
export type WalletTransactionType = 'credit' | 'debit' | 'reserve' | 'release' | 'refund' | 'withdrawal'

// ------------------------------------------------------------------ Client
export declare class NexoraSmsClientBuilder {
  publicKey(value: string): this
  apiKey(value: string): this
  baseUrl(value: string): this
  timeout(ms: number): this
  maxRetries(value: number): this
  userAgent(value: string): this
  fetch(fn: typeof globalThis.fetch): this
  build(): NexoraSmsClient
}

export declare class NexoraSmsClient {
  static builder(): NexoraSmsClientBuilder
  readonly baseUrl: string
  readonly messages: {
    send(request: SendMessageRequest): Promise<SendMessageResult>
    estimate(request: SendMessageRequest): Promise<EstimateResult>
    get(id: string): Promise<Message>
    list(query?: MessageQuery): Promise<Page<Message>>
  }
  readonly contacts: {
    upsertByExternalId(externalId: string, request: ContactRequest): Promise<Contact>
    create(request: ContactRequest): Promise<Contact>
    get(id: string): Promise<Contact>
    update(id: string, request: ContactRequest): Promise<Contact>
    list(query?: ContactQuery): Promise<Page<Contact>>
    recordConsent(id: string, channel: ChannelName, status: ConsentStatus, source: string): Promise<Contact>
  }
  readonly wallet: {
    get(): Promise<Wallet>
    transactions(page?: number, pageSize?: number): Promise<Page<WalletTransaction>>
  }
  readonly campaigns: {
    get(id: string): Promise<Campaign>
    stats(id: string): Promise<CampaignStats>
  }
}

// ------------------------------------------------------------------ Requests
export declare class SendMessageRequest {
  static builder(): SendMessageRequestBuilder
  readonly body: Record<string, unknown>
  readonly idempotencyKey?: string
}
export declare class SendMessageRequestBuilder {
  sms(content: string, sender?: string): this
  email(subject: string, content: string, sender?: string): this
  whatsapp(content: string): this
  whatsappTemplate(name: string, language: string, ...parameters: Array<string | string[]>): this
  to(...recipients: Array<string | string[]>): this
  contacts(...ids: Array<string | string[]>): this
  contact(id: string): this
  ignoreQuietHours(value?: boolean): this
  idempotencyKey(key: string): this
  build(): SendMessageRequest
}

export declare class ContactRequest {
  static builder(): ContactRequestBuilder
  readonly body: Record<string, unknown>
}
export declare class ContactRequestBuilder {
  name(value: string): this
  phone(value: string): this
  email(value: string): this
  status(value: ContactStatus): this
  externalId(value: string): this
  tags(...values: Array<string | string[]>): this
  tag(value: string): this
  groupIds(...ids: Array<string | string[]>): this
  groupId(id: string): this
  attribute(key: string, value: string | null): this
  attributes(map: Record<string, string | null>): this
  build(): ContactRequest
}

export declare class MessageQuery { static builder(): MessageQueryBuilder; readonly params: Record<string, unknown> }
export declare class MessageQueryBuilder {
  campaignId(value: string): this
  status(value: MessageStatus): this
  channel(value: ChannelName): this
  search(value: string): this
  page(value: number): this
  pageSize(value: number): this
  build(): MessageQuery
}
export declare class ContactQuery { static builder(): ContactQueryBuilder; readonly params: Record<string, unknown> }
export declare class ContactQueryBuilder {
  search(value: string): this
  groupId(value: string): this
  tag(value: string): this
  status(value: ContactStatus): this
  page(value: number): this
  pageSize(value: number): this
  build(): ContactQuery
}

// ------------------------------------------------------------------ Results
export interface Page<T> { items: T[]; page: number; pageSize: number; total: number }

export interface SendMessageResult {
  channels: Array<{ channel: ChannelName; campaignId: string; recipients: number; cost: number; status: CampaignStatus }>
  invalid: string[]
  /** The Idempotency-Key used (yours, or the one the SDK generated). */
  idempotencyKey: string
  /** True when NexoraSMS returned the stored result of an earlier identical request. */
  replayed: boolean
}

export interface EstimateResult {
  channels: Array<{ channel: ChannelName; recipients: number; segments: number; encoding: 'gsm7' | 'ucs2' | 'utf8'; unitPrice: number; cost: number }>
  total: number
  available: number
  invalid: string[]
}

export interface DeliveryAttempt {
  partNumber: number; attemptNumber: number; status: AttemptStatus
  providerMessageId?: string | null; errorCode?: string | null; startedAt: string; completedAt?: string | null
}

export interface Message {
  id: string; campaignId: string; channel: ChannelName; destination: string; status: MessageStatus; cost: number; parts: number
  createdAt: string; acceptedAt?: string | null; deliveredAt?: string | null; attempts: DeliveryAttempt[]
  campaignName?: string | null; sender?: string | null; subject?: string | null; content?: string | null
  template?: string | null; language?: string | null
}

export interface Consent { channel: ChannelName; status: ConsentStatus; source: string; recordedAt: string }

export interface Contact {
  id: string; name?: string | null; phone?: string | null; email?: string | null; status: ContactStatus
  groupIds: string[]; tags: string[]; consents: Consent[]; createdAt: string
  /** Custom fields, with their names as defined (not converted). */
  attributes: Record<string, string>
  externalId?: string | null
}

export interface Wallet { currency: string; balance: number; reserved: number; available: number }

export interface WalletTransaction {
  id: string; type: WalletTransactionType; amount: number; businessReference: string
  messageId?: string | null; reversalOfId?: string | null; createdAt: string
}

export interface Campaign {
  id: string; name: string; channel: ChannelName; status: CampaignStatus; templateId?: string | null; subject?: string | null
  content: string; recipients: number; scheduledAt?: string | null; createdAt: string; ignoreQuietHours: boolean
  pauseReason?: string | null; sender?: string | null; messages?: number | null; delivered?: number | null; failed?: number | null
}

export interface CampaignStats {
  campaignId: string; status: CampaignStatus; recipients: number; messages: Partial<Record<MessageStatus, number>>
  smsSegments: number; currency: string; estimatedCost: number; reserved: number; debited: number; clicks: number; uniqueClicks: number
}

// ------------------------------------------------------------------ Webhooks
export declare const WebhookEventType: {
  readonly MESSAGE_DELIVERED: 'message.delivered'; readonly MESSAGE_FAILED: 'message.failed'
  readonly CAMPAIGN_COMPLETED: 'campaign.completed'; readonly CAMPAIGN_PAUSED: 'campaign.paused'
  readonly CONTACT_OPTED_OUT: 'contact.opted_out'; readonly PING: 'ping'
}

export interface MessageEvent {
  messageId: string; campaignId: string; contactId: string; externalId?: string | null
  channel: ChannelName; status: 'delivered' | 'failed'; cost: number; occurredAt: string
}

export interface WebhookEvent<T = unknown> { id: string; type: string; createdAt: string; data: T }

export declare class WebhookVerifier {
  constructor(secret: string, options?: { toleranceSeconds?: number; now?: () => number })
  verify(rawBody: string | Uint8Array, signatureHeader: string | null | undefined): WebhookEvent
}
export declare function isMessageEvent(event: WebhookEvent): event is WebhookEvent<MessageEvent>

// ------------------------------------------------------------------ Errors
export declare class NexoraSmsError extends Error {
  readonly status?: number; readonly title?: string; readonly detail?: string; readonly traceId?: string
  readonly errors: Record<string, string[]>; readonly retryAfter?: number
}
export declare class ValidationError extends NexoraSmsError {}
export declare class AuthenticationError extends NexoraSmsError {}
export declare class InsufficientFundsError extends NexoraSmsError {}
export declare class PermissionDeniedError extends NexoraSmsError {}
export declare class NotFoundError extends NexoraSmsError {}
export declare class ConflictError extends NexoraSmsError {}
export declare class RateLimitError extends NexoraSmsError {}
export declare class ServerError extends NexoraSmsError {}
export declare class ConnectionError extends NexoraSmsError {}
export declare class WebhookSignatureError extends Error {}
