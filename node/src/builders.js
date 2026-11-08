// Request builders. Each build() validates and returns a frozen request; the JSON body uses the API's snake_case.

export const Channel = Object.freeze({ SMS: 'sms', WHATSAPP: 'whatsapp', EMAIL: 'email' })

const MAX_LIST = 1000
const visibleAscii = /^[\x21-\x7E]{1,200}$/

const nonEmpty = value => typeof value === 'string' && value.trim().length > 0

/** Checks an Idempotency-Key: 1 to 200 visible ASCII characters. */
export function checkIdempotencyKey(key) {
  if (typeof key !== 'string' || !visibleAscii.test(key))
    throw new RangeError('idempotencyKey deve ter de 1 a 200 caracteres ASCII visíveis.')
  return key
}

function checkPageSize(pageSize) {
  if (pageSize !== undefined && (!Number.isInteger(pageSize) || pageSize < 1 || pageSize > 200))
    throw new RangeError('pageSize deve ser um inteiro entre 1 e 200.')
}

function checkPage(page) {
  if (page !== undefined && (!Number.isInteger(page) || page < 1))
    throw new RangeError('page deve ser um inteiro a partir de 1.')
}

// ------------------------------------------------------------------ SendMessageRequest
export class SendMessageRequest {
  /** @returns {SendMessageRequestBuilder} */
  static builder() { return new SendMessageRequestBuilder() }

  constructor(body, idempotencyKey) {
    this.body = body
    this.idempotencyKey = idempotencyKey
    Object.freeze(this)
  }
}

export class SendMessageRequestBuilder {
  #channels = []
  #recipients = []
  #contactIds = []
  #ignoreQuietHours
  #idempotencyKey

  /** SMS with optional approved Sender ID. */
  sms(content, sender) { this.#channels.push({ channel: Channel.SMS, content, sender }); return this }
  /** Email (HTML content) with optional sender at a verified domain. */
  email(subject, content, sender) { this.#channels.push({ channel: Channel.EMAIL, subject, content, sender }); return this }
  /** WhatsApp free text (accepted only within 24 h of the customer's last message). */
  whatsapp(content) { this.#channels.push({ channel: Channel.WHATSAPP, content }); return this }
  /** WhatsApp with a template approved by Meta; parameters fill {{1}}, {{2}}… in order. */
  whatsappTemplate(name, language, ...parameters) {
    this.#channels.push({ channel: Channel.WHATSAPP, whatsapp_template: { name, language, parameters: parameters.flat() } })
    return this
  }
  /** E.164 phone numbers and/or email addresses (accumulates). */
  to(...recipients) { this.#recipients.push(...recipients.flat()); return this }
  /** Existing contact ids (accumulates). */
  contacts(...ids) { this.#contactIds.push(...ids.flat()); return this }
  contact(id) { return this.contacts(id) }
  ignoreQuietHours(value = true) { this.#ignoreQuietHours = Boolean(value); return this }
  idempotencyKey(key) { this.#idempotencyKey = checkIdempotencyKey(key); return this }

  /** @returns {SendMessageRequest} */
  build() {
    const channels = this.#channels
    if (channels.length < 1 || channels.length > 3) throw new RangeError('Indique entre 1 e 3 canais.')
    const names = channels.map(c => c.channel)
    if (new Set(names).size !== names.length) throw new RangeError('Cada canal só pode aparecer uma vez.')
    for (const c of channels) {
      if (c.channel === Channel.SMS && !nonEmpty(c.content)) throw new TypeError('O SMS precisa de conteúdo.')
      if (c.channel === Channel.EMAIL) {
        if (!nonEmpty(c.subject)) throw new TypeError('O email precisa de assunto.')
        if (!nonEmpty(c.content)) throw new TypeError('O email precisa de conteúdo.')
      }
      if (c.channel === Channel.WHATSAPP && !nonEmpty(c.content) && !c.whatsapp_template) throw new TypeError('O WhatsApp precisa de conteúdo ou de um template.')
      if (c.whatsapp_template && (!nonEmpty(c.whatsapp_template.name) || !nonEmpty(c.whatsapp_template.language)))
        throw new TypeError('O template do WhatsApp precisa de nome e idioma.')
    }
    if (!this.#recipients.length && !this.#contactIds.length) throw new RangeError('Indique pelo menos um destinatário ou contacto.')
    if (this.#recipients.length > MAX_LIST) throw new RangeError(`No máximo ${MAX_LIST} destinatários.`)
    if (this.#contactIds.length > MAX_LIST) throw new RangeError(`No máximo ${MAX_LIST} contactos.`)

    const body = prune({
      channels: channels.map(c => prune({ ...c })),
      recipients: this.#recipients.length ? [...this.#recipients] : undefined,
      contact_ids: this.#contactIds.length ? [...this.#contactIds] : undefined,
      ignore_quiet_hours: this.#ignoreQuietHours,
    })
    return new SendMessageRequest(body, this.#idempotencyKey)
  }
}

// ------------------------------------------------------------------ ContactRequest
export class ContactRequest {
  /** @returns {ContactRequestBuilder} */
  static builder() { return new ContactRequestBuilder() }

  constructor(body) { this.body = body; Object.freeze(this) }
}

export class ContactRequestBuilder {
  #fields = {}
  #tags
  #groupIds
  #attributes

  name(value) { this.#fields.name = value; return this }
  phone(value) { this.#fields.phone = value; return this }
  email(value) { this.#fields.email = value; return this }
  /** 'active' or 'inactive' (update only). */
  status(value) {
    if (value !== 'active' && value !== 'inactive') throw new RangeError("status deve ser 'active' ou 'inactive'.")
    this.#fields.status = value
    return this
  }
  externalId(value) { this.#fields.external_id = value; return this }
  /** Replaces the contact's tags when sent; an explicit empty list clears them. */
  tags(...values) { this.#tags = [...(this.#tags ?? []), ...values.flat()]; return this }
  tag(value) { return this.tags(value) }
  /** Replaces the contact's groups when sent; an explicit empty list clears them. */
  groupIds(...ids) { this.#groupIds = [...(this.#groupIds ?? []), ...ids.flat()]; return this }
  groupId(id) { return this.groupIds(id) }
  /** Custom field; null removes it on update. */
  attribute(key, value) { this.#attributes = { ...(this.#attributes ?? {}), [key]: value }; return this }
  attributes(map) { this.#attributes = { ...(this.#attributes ?? {}), ...map }; return this }

  /** @returns {ContactRequest} */
  build() {
    const body = prune({ ...this.#fields })
    if (this.#tags) body.tags = [...this.#tags]
    if (this.#groupIds) body.group_ids = [...this.#groupIds]
    if (this.#attributes) body.attributes = { ...this.#attributes }
    return new ContactRequest(body)
  }
}

// ------------------------------------------------------------------ Queries
class Query {
  constructor(params) { this.params = params; Object.freeze(this) }
}

export class MessageQuery extends Query {
  static builder() { return new MessageQueryBuilder() }
}

export class ContactQuery extends Query {
  static builder() { return new ContactQueryBuilder() }
}

class QueryBuilder {
  _params = {}
  page(value) { checkPage(value); this._params.page = value; return this }
  pageSize(value) { checkPageSize(value); this._params.page_size = value; return this }
}

export class MessageQueryBuilder extends QueryBuilder {
  campaignId(value) { this._params.campaign_id = value; return this }
  status(value) { this._params.status = value; return this }
  channel(value) { this._params.channel = value; return this }
  search(value) { this._params.search = value; return this }
  build() { return new MessageQuery(prune({ ...this._params })) }
}

export class ContactQueryBuilder extends QueryBuilder {
  search(value) { this._params.search = value; return this }
  groupId(value) { this._params.group_id = value; return this }
  tag(value) { this._params.tag = value; return this }
  status(value) { this._params.status = value; return this }
  build() { return new ContactQuery(prune({ ...this._params })) }
}

/** Drops undefined and null fields (the API treats absent fields as "not set"). */
function prune(object) {
  for (const key of Object.keys(object)) if (object[key] === undefined || object[key] === null) delete object[key]
  return object
}
