import { createHmac, timingSafeEqual } from 'node:crypto'
import { WebhookSignatureError } from './errors.js'
import { camelize } from './client.js'

export const WebhookEventType = Object.freeze({
  MESSAGE_DELIVERED: 'message.delivered',
  MESSAGE_FAILED: 'message.failed',
  CAMPAIGN_COMPLETED: 'campaign.completed',
  CAMPAIGN_PAUSED: 'campaign.paused',
  CONTACT_OPTED_OUT: 'contact.opted_out',
  PING: 'ping',
})

/**
 * Verifies X-Nexora-Signature (t=<unix>,v1=<hex HMAC-SHA256(secret, "<t>.<body>")>) and parses the event.
 * Use the raw body exactly as received (Buffer or string), before any JSON parsing.
 */
export class WebhookVerifier {
  #secret
  #toleranceSeconds
  #now

  /**
   * @param {string} secret The endpoint's signing secret (whsec_…).
   * @param {{ toleranceSeconds?: number, now?: () => number }} [options] now returns milliseconds (tests).
   */
  constructor(secret, { toleranceSeconds = 300, now = Date.now } = {}) {
    if (typeof secret !== 'string' || !secret) throw new TypeError('Indique o segredo do webhook (whsec_…).')
    this.#secret = secret
    this.#toleranceSeconds = toleranceSeconds
    this.#now = now
  }

  /**
   * @param {string | Buffer | Uint8Array} rawBody
   * @param {string | undefined | null} signatureHeader
   * @returns {{ id: string, type: string, createdAt: string, data: any }}
   */
  verify(rawBody, signatureHeader) {
    const body = typeof rawBody === 'string' ? rawBody : Buffer.from(rawBody ?? []).toString('utf8')
    const parts = {}
    for (const piece of String(signatureHeader ?? '').split(',')) {
      const index = piece.indexOf('=')
      if (index > 0) parts[piece.slice(0, index).trim()] = piece.slice(index + 1).trim()
    }
    const timestamp = Number(parts.t)
    if (!parts.t || !Number.isInteger(timestamp) || !parts.v1) throw new WebhookSignatureError('Cabeçalho X-Nexora-Signature mal formado.')

    const expected = createHmac('sha256', this.#secret).update(`${parts.t}.${body}`).digest('hex')
    const given = Buffer.from(parts.v1, 'utf8')
    if (given.length !== expected.length || !timingSafeEqual(given, Buffer.from(expected, 'utf8')))
      throw new WebhookSignatureError('Assinatura do webhook inválida.')
    if (Math.abs(this.#now() / 1000 - timestamp) > this.#toleranceSeconds)
      throw new WebhookSignatureError('Webhook fora da janela de tempo permitida.')

    let event
    try { event = JSON.parse(body) } catch { throw new WebhookSignatureError('Corpo do webhook não é JSON válido.') }
    return camelize(event)
  }
}

/** True for message.delivered / message.failed (data is a MessageEvent). */
export const isMessageEvent = event => event?.type === WebhookEventType.MESSAGE_DELIVERED || event?.type === WebhookEventType.MESSAGE_FAILED
