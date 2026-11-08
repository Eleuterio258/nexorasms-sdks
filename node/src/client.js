import { randomUUID } from 'node:crypto'
import { ConnectionError, errorFor } from './errors.js'
import { ContactQuery, ContactRequest, MessageQuery, SendMessageRequest } from './builders.js'

export const VERSION = '0.1.0'
export const DEFAULT_BASE_URL = 'https://sms.e258tech.tech'
const PUBLIC_PREFIX = 'e258_nex_sms_pk_'
const SECRET_PREFIX = 'e258_nex_sms_ak_'
const MAX_RETRY_AFTER_MS = 30_000
const MAX_BACKOFF_MS = 8_000

// ------------------------------------------------------------------ Builder
export class NexoraSmsClientBuilder {
  #publicKey
  #apiKey
  #baseUrl = DEFAULT_BASE_URL
  #timeoutMs = 30_000
  #maxRetries = 2
  #userAgent
  #fetch
  #sleep

  /** Public key (X-Public-Key), e258_nex_sms_pk_… */
  publicKey(value) { this.#publicKey = value; return this }
  /** Secret key (X-Api-Key), e258_nex_sms_ak_… Keep it on the server only. */
  apiKey(value) { this.#apiKey = value; return this }
  baseUrl(value) { this.#baseUrl = String(value).replace(/\/+$/, ''); return this }
  /** Per-attempt timeout in milliseconds (default 30 000). */
  timeout(ms) {
    if (!Number.isFinite(ms) || ms <= 0) throw new RangeError('timeout deve ser um número positivo de milissegundos.')
    this.#timeoutMs = ms
    return this
  }
  /** Retries after network errors, 429, 502–504 and 409 with Retry-After (default 2; 0 disables). */
  maxRetries(value) {
    if (!Number.isInteger(value) || value < 0) throw new RangeError('maxRetries deve ser um inteiro >= 0.')
    this.#maxRetries = value
    return this
  }
  /** Extra text appended to the SDK's User-Agent (e.g. "loja-online/2.3"). */
  userAgent(value) { this.#userAgent = value; return this }
  /** Custom fetch implementation (tests, proxies). Defaults to the global fetch. */
  fetch(fn) { this.#fetch = fn; return this }
  /** @internal Custom sleep (tests). */
  sleep(fn) { this.#sleep = fn; return this }

  /** @returns {NexoraSmsClient} */
  build() {
    if (typeof this.#publicKey !== 'string' || !this.#publicKey.startsWith(PUBLIC_PREFIX))
      throw new TypeError(`publicKey em falta ou inválida: deve começar por ${PUBLIC_PREFIX}.`)
    if (typeof this.#apiKey !== 'string' || !this.#apiKey.startsWith(SECRET_PREFIX))
      throw new TypeError(`apiKey em falta ou inválida: deve começar por ${SECRET_PREFIX}.`)
    const fetchFn = this.#fetch ?? globalThis.fetch
    if (typeof fetchFn !== 'function') throw new TypeError('Não há fetch disponível: use Node.js 18 ou superior, ou indique fetch().')
    return new NexoraSmsClient({
      publicKey: this.#publicKey,
      apiKey: this.#apiKey,
      baseUrl: this.#baseUrl,
      timeoutMs: this.#timeoutMs,
      maxRetries: this.#maxRetries,
      userAgent: `nexorasms-node/${VERSION}` + (this.#userAgent ? ` ${this.#userAgent}` : ''),
      fetch: fetchFn,
      sleep: this.#sleep ?? (ms => new Promise(resolve => setTimeout(resolve, ms))),
    })
  }
}

// ------------------------------------------------------------------ Client
export class NexoraSmsClient {
  /** @returns {NexoraSmsClientBuilder} */
  static builder() { return new NexoraSmsClientBuilder() }

  #config

  /** Use NexoraSmsClient.builder(). */
  constructor(config) {
    this.#config = Object.freeze(config)
    this.messages = new Messages(this)
    this.contacts = new Contacts(this)
    this.wallet = new Wallet(this)
    this.campaigns = new Campaigns(this)
    Object.freeze(this)
  }

  get baseUrl() { return this.#config.baseUrl }

  /**
   * @internal Sends a request, retrying when allowed. Returns { status, headers, data } with data in camelCase.
   * @param {{ method: string, path: string, query?: object, body?: object, headers?: object, retryable: boolean }} options
   */
  async request({ method, path, query, body, headers = {}, retryable }) {
    const config = this.#config
    const url = new URL(config.baseUrl + path)
    for (const [key, value] of Object.entries(query ?? {})) if (value !== undefined && value !== null) url.searchParams.set(key, String(value))
    const allHeaders = {
      'X-Public-Key': config.publicKey,
      'X-Api-Key': config.apiKey,
      Accept: 'application/json',
      'User-Agent': config.userAgent,
      ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}),
      ...headers,
    }
    const payload = body !== undefined ? JSON.stringify(body) : undefined

    for (let attempt = 0; ; attempt++) {
      let response
      try {
        response = await config.fetch(url, { method, headers: allHeaders, body: payload, signal: AbortSignal.timeout(config.timeoutMs) })
      } catch (cause) {
        if (retryable && attempt < config.maxRetries) { await config.sleep(backoff(attempt)); continue }
        const timedOut = cause?.name === 'TimeoutError' || cause?.name === 'AbortError'
        throw new ConnectionError(timedOut ? `Tempo limite excedido ao contactar ${url.host}.` : `Não foi possível contactar ${url.host}.`, { cause })
      }

      const text = await response.text()
      let data
      try { data = text ? JSON.parse(text) : undefined } catch { data = undefined }
      if (response.ok) return { status: response.status, headers: response.headers, data: camelize(data) }

      const retryAfter = parseRetryAfter(response.headers.get('retry-after'))
      const transient = response.status === 429 || response.status === 502 || response.status === 503 || response.status === 504
        || (response.status === 409 && retryAfter !== undefined)
      if (retryable && transient && attempt < config.maxRetries) {
        await config.sleep(retryAfter !== undefined ? Math.min(retryAfter * 1000, MAX_RETRY_AFTER_MS) : backoff(attempt))
        continue
      }
      throw errorFor(response.status, data, retryAfter)
    }
  }
}

function backoff(attempt) {
  const base = Math.min(500 * 2 ** attempt, MAX_BACKOFF_MS)
  return Math.round(base * (0.8 + Math.random() * 0.4))
}

function parseRetryAfter(value) {
  if (value === null || value === undefined || value === '') return undefined
  const seconds = Number(value)
  if (Number.isFinite(seconds) && seconds >= 0) return seconds
  const date = Date.parse(value)
  return Number.isNaN(date) ? undefined : Math.max(0, Math.ceil((date - Date.now()) / 1000))
}

// Keys whose values are user data (custom field names): kept as they are.
const keepKeys = new Set(['attributes'])

/** Converts snake_case keys to camelCase, recursively (except inside attributes). */
export function camelize(value) {
  if (Array.isArray(value)) return value.map(camelize)
  if (!value || typeof value !== 'object') return value
  const result = {}
  for (const [key, inner] of Object.entries(value)) {
    const name = key.replace(/_([a-z0-9])/g, (_, c) => c.toUpperCase())
    result[name] = keepKeys.has(key) ? inner : camelize(inner)
  }
  return result
}

const segment = value => {
  if (typeof value !== 'string' || !value) throw new TypeError('Identificador em falta.')
  return encodeURIComponent(value)
}

const queryOf = (query, Type) => {
  if (query === undefined || query === null) return {}
  if (!(query instanceof Type)) throw new TypeError(`Use ${Type.name}.builder() para construir a consulta.`)
  return query.params
}

// ------------------------------------------------------------------ Resources
class Messages {
  #client
  constructor(client) { this.#client = client }

  /**
   * Sends now (one campaign per channel). Uses the request's idempotencyKey or a new UUID, reused on every retry.
   * @param {SendMessageRequest} request
   */
  async send(request) {
    if (!(request instanceof SendMessageRequest)) throw new TypeError('Use SendMessageRequest.builder() para construir o envio.')
    const idempotencyKey = request.idempotencyKey ?? randomUUID()
    const { headers, data } = await this.#client.request({
      method: 'POST', path: '/api/v1/messages', body: request.body, headers: { 'Idempotency-Key': idempotencyKey }, retryable: true,
    })
    return Object.freeze({ ...data, idempotencyKey, replayed: headers.get('idempotent-replayed') === 'true' })
  }

  /** Cost, segments and available balance. Sends nothing. */
  async estimate(request) {
    if (!(request instanceof SendMessageRequest)) throw new TypeError('Use SendMessageRequest.builder() para construir o envio.')
    return (await this.#client.request({ method: 'POST', path: '/api/v1/messages/estimate', body: request.body, retryable: true })).data
  }

  async get(id) { return (await this.#client.request({ method: 'GET', path: `/api/v1/messages/${segment(id)}`, retryable: true })).data }

  async list(query) {
    return (await this.#client.request({ method: 'GET', path: '/api/v1/messages', query: queryOf(query, MessageQuery), retryable: true })).data
  }
}

class Contacts {
  #client
  constructor(client) { this.#client = client }

  /** Creates or updates the contact with this id from your system. */
  async upsertByExternalId(externalId, request) {
    return (await this.#client.request({ method: 'PUT', path: `/api/v1/contacts/external/${segment(externalId)}`, body: bodyOf(request), retryable: true })).data
  }

  async create(request) {
    return (await this.#client.request({ method: 'POST', path: '/api/v1/contacts', body: bodyOf(request), retryable: false })).data
  }

  async get(id) { return (await this.#client.request({ method: 'GET', path: `/api/v1/contacts/${segment(id)}`, retryable: true })).data }

  async update(id, request) {
    return (await this.#client.request({ method: 'PATCH', path: `/api/v1/contacts/${segment(id)}`, body: bodyOf(request), retryable: false })).data
  }

  async list(query) {
    return (await this.#client.request({ method: 'GET', path: '/api/v1/contacts', query: queryOf(query, ContactQuery), retryable: true })).data
  }

  /** channel: sms | whatsapp | email; status: granted | revoked. */
  async recordConsent(id, channel, status, source) {
    if (status !== 'granted' && status !== 'revoked') throw new RangeError("status deve ser 'granted' ou 'revoked'.")
    return (await this.#client.request({
      method: 'POST', path: `/api/v1/contacts/${segment(id)}/consents`, body: { channel, status, source }, retryable: false,
    })).data
  }
}

const bodyOf = request => {
  if (!(request instanceof ContactRequest)) throw new TypeError('Use ContactRequest.builder() para construir o contacto.')
  return request.body
}

class Wallet {
  #client
  constructor(client) { this.#client = client }
  async get() { return (await this.#client.request({ method: 'GET', path: '/api/v1/wallet', retryable: true })).data }
  async transactions(page, pageSize) {
    return (await this.#client.request({ method: 'GET', path: '/api/v1/wallet/transactions', query: { page, page_size: pageSize }, retryable: true })).data
  }
}

class Campaigns {
  #client
  constructor(client) { this.#client = client }
  async get(id) { return (await this.#client.request({ method: 'GET', path: `/api/v1/campaigns/${segment(id)}`, retryable: true })).data }
  async stats(id) { return (await this.#client.request({ method: 'GET', path: `/api/v1/campaigns/${segment(id)}/stats`, retryable: true })).data }
}
