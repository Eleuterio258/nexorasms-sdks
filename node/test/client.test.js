import { test, before, after, beforeEach } from 'node:test'
import assert from 'node:assert/strict'
import { createServer } from 'node:http'
import { createHmac } from 'node:crypto'
import {
  NexoraSmsClient, SendMessageRequest, ContactRequest, MessageQuery, ContactQuery, WebhookVerifier, isMessageEvent,
  InsufficientFundsError, ValidationError, AuthenticationError, NotFoundError, RateLimitError, ServerError, ConflictError,
  ConnectionError, WebhookSignatureError,
} from '../src/index.js'

const PK = 'e258_nex_sms_pk_HXj4qcWmXl0m'
const AK = 'e258_nex_sms_ak_secretsecretsecretsecret123'

// ------------------------------------------------------------------ Fake server
let server, baseUrl
/** Queue of responses: { status, body, headers }; requests seen: { method, url, headers, body }. */
let replies, seen

before(async () => {
  server = createServer((req, res) => {
    let raw = ''
    req.on('data', chunk => { raw += chunk })
    req.on('end', () => {
      seen.push({ method: req.method, url: req.url, headers: req.headers, body: raw ? JSON.parse(raw) : undefined })
      const reply = replies.shift() ?? { status: 200, body: {} }
      res.writeHead(reply.status, { 'Content-Type': 'application/json', ...(reply.headers ?? {}) })
      res.end(reply.body === undefined ? '' : JSON.stringify(reply.body))
    })
  })
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve))
  baseUrl = `http://127.0.0.1:${server.address().port}`
})
after(() => new Promise(resolve => server.close(resolve)))
beforeEach(() => { replies = []; seen = [] })

const sleeps = []
const client = (configure = b => b) => configure(NexoraSmsClient.builder().publicKey(PK).apiKey(AK).baseUrl(baseUrl + '/'))
  .sleep(async ms => { sleeps.push(ms) }).build()

const smsRequest = () => SendMessageRequest.builder().sms('Olá', 'MINHALOJA').to('+258841234567').build()
const sendResult = { channels: [{ channel: 'sms', campaign_id: 'c1', recipients: 1, cost: 1.5, status: 'running' }], invalid: [] }

// ------------------------------------------------------------------ Builder of the client
test('o builder exige as duas chaves com o prefixo certo', () => {
  assert.throws(() => NexoraSmsClient.builder().apiKey(AK).build(), /publicKey/)
  assert.throws(() => NexoraSmsClient.builder().publicKey(PK).build(), /apiKey/)
  assert.throws(() => NexoraSmsClient.builder().publicKey('nxk_x').apiKey(AK).build(), /e258_nex_sms_pk_/)
  assert.throws(() => NexoraSmsClient.builder().publicKey(PK).apiKey(PK).build(), /e258_nex_sms_ak_/)
  assert.throws(() => NexoraSmsClient.builder().maxRetries(-1), RangeError)
  assert.equal(NexoraSmsClient.builder().publicKey(PK).apiKey(AK).build().baseUrl, 'https://sms.e258tech.tech')
})

// ------------------------------------------------------------------ Messages
test('send envia as chaves, JSON snake_case e uma Idempotency-Key gerada', async () => {
  replies.push({ status: 201, body: sendResult })
  const result = await client(b => b.userAgent('loja/1.0')).messages.send(
    SendMessageRequest.builder().sms('Olá', 'MINHALOJA').email('Assunto', '<p>Olá</p>').to('+258841234567', 'ana@x.co.mz').contact('k1').ignoreQuietHours().build())

  const [req] = seen
  assert.equal(req.method, 'POST')
  assert.equal(req.url, '/api/v1/messages')
  assert.equal(req.headers['x-public-key'], PK)
  assert.equal(req.headers['x-api-key'], AK)
  assert.equal(req.headers['content-type'], 'application/json')
  assert.equal(req.headers['user-agent'], 'nexorasms-node/0.1.0 loja/1.0')
  assert.match(req.headers['idempotency-key'], /^[0-9a-f-]{36}$/)
  assert.deepEqual(req.body, {
    channels: [{ channel: 'sms', content: 'Olá', sender: 'MINHALOJA' }, { channel: 'email', subject: 'Assunto', content: '<p>Olá</p>' }],
    recipients: ['+258841234567', 'ana@x.co.mz'],
    contact_ids: ['k1'],
    ignore_quiet_hours: true,
  })
  assert.equal(result.channels[0].campaignId, 'c1')
  assert.equal(result.idempotencyKey, req.headers['idempotency-key'])
  assert.equal(result.replayed, false)
})

test('send repete em 503 e 429 com a mesma Idempotency-Key e respeita o Retry-After', async () => {
  sleeps.length = 0
  replies.push({ status: 503, body: { title: 'Indisponível', status: 503 } })
  replies.push({ status: 429, body: { title: 'Devagar', status: 429 }, headers: { 'Retry-After': '2' } })
  replies.push({ status: 201, body: sendResult, headers: { 'Idempotent-Replayed': 'true' } })
  const result = await client().messages.send(SendMessageRequest.builder().sms('Olá').to('+258841234567').idempotencyKey('fatura-123').build())
  assert.equal(seen.length, 3)
  assert.deepEqual(seen.map(r => r.headers['idempotency-key']), ['fatura-123', 'fatura-123', 'fatura-123'])
  assert.equal(sleeps[1], 2000)
  assert.equal(result.replayed, true)
})

test('não repete em 400 nem em 402, e 402 vira InsufficientFundsError', async () => {
  replies.push({ status: 402, body: { title: 'Saldo insuficiente para este envio.', status: 402, traceId: 't1' } })
  await assert.rejects(client().messages.send(smsRequest()), error => {
    assert.ok(error instanceof InsufficientFundsError)
    assert.equal(error.status, 402)
    assert.equal(error.message, 'Saldo insuficiente para este envio.')
    assert.equal(error.traceId, 't1')
    return true
  })
  assert.equal(seen.length, 1)

  replies.push({ status: 400, body: { title: 'Pedido inválido', status: 400, errors: { channels: ['obrigatório'] } } })
  await assert.rejects(client().messages.send(smsRequest()), error => error instanceof ValidationError && error.errors.channels[0] === 'obrigatório')
  assert.equal(seen.length, 2)
})

test('desiste depois de maxRetries e devolve o último erro', async () => {
  for (let i = 0; i < 3; i++) replies.push({ status: 503, body: { title: 'Indisponível', status: 503 } })
  await assert.rejects(client().messages.send(smsRequest()), ServerError)
  assert.equal(seen.length, 3)
  replies.push({ status: 503, body: {} })
  await assert.rejects(client(b => b.maxRetries(0)).wallet.get(), error => error instanceof ServerError && error.message === 'HTTP 503')
  assert.equal(seen.length, 4)
})

test('409 só se repete com Retry-After', async () => {
  replies.push({ status: 409, body: { title: 'Em processamento', status: 409 }, headers: { 'Retry-After': '1' } })
  replies.push({ status: 201, body: sendResult })
  await client().messages.send(smsRequest())
  assert.equal(seen.length, 2)
  replies.push({ status: 409, body: { title: 'Conteúdo diferente', status: 409 } })
  await assert.rejects(client().messages.send(smsRequest()), ConflictError)
  assert.equal(seen.length, 3)
})

test('mapeia 401, 404 e 429', async () => {
  replies.push({ status: 401, body: { title: 'Unauthorized', status: 401 } })
  await assert.rejects(client().wallet.get(), AuthenticationError)
  replies.push({ status: 404, body: { title: 'Mensagem não encontrada.', status: 404 } })
  await assert.rejects(client().messages.get('m1'), NotFoundError)
  replies.push({ status: 429, body: {} }, { status: 429, body: {} }, { status: 429, body: {}, headers: { 'Retry-After': '5' } })
  await assert.rejects(client().wallet.get(), error => error instanceof RateLimitError && error.retryAfter === 5)
})

test('erro de rede vira ConnectionError depois das repetições', async () => {
  let calls = 0
  const failing = NexoraSmsClient.builder().publicKey(PK).apiKey(AK).baseUrl(baseUrl)
    .fetch(async () => { calls++; throw new TypeError('fetch failed') }).sleep(async () => {}).build()
  await assert.rejects(failing.wallet.get(), ConnectionError)
  assert.equal(calls, 3)
  calls = 0
  await assert.rejects(failing.contacts.create(ContactRequest.builder().name('Ana').build()), ConnectionError)
  assert.equal(calls, 1) // POST /contacts is not idempotent: never retried
})

test('estimate, get e list com query string', async () => {
  replies.push({ status: 200, body: { channels: [{ channel: 'sms', recipients: 1, segments: 1, encoding: 'gsm7', unit_price: 1.5, cost: 1.5 }], total: 1.5, available: 10, invalid: [] } })
  const estimate = await client().messages.estimate(smsRequest())
  assert.equal(seen[0].url, '/api/v1/messages/estimate')
  assert.equal(seen[0].headers['idempotency-key'], undefined)
  assert.equal(estimate.channels[0].unitPrice, 1.5)

  replies.push({ status: 200, body: { items: [{ id: 'm1', campaign_id: 'c1', attempts: [{ part_number: 1, status: 'in_flight' }] }], page: 2, page_size: 10, total: 11 } })
  const page = await client().messages.list(MessageQuery.builder().campaignId('c1').status('failed').page(2).pageSize(10).build())
  assert.equal(seen[1].url, '/api/v1/messages?campaign_id=c1&status=failed&page=2&page_size=10')
  assert.equal(page.pageSize, 10)
  assert.equal(page.items[0].attempts[0].partNumber, 1)

  replies.push({ status: 200, body: { items: [], page: 1, page_size: 50, total: 0 } })
  await client().contacts.list(ContactQuery.builder().tag('vip').build())
  assert.equal(seen[2].url, '/api/v1/contacts?tag=vip')
  assert.throws(() => MessageQuery.builder().pageSize(500), RangeError)
})

// ------------------------------------------------------------------ Contacts
test('upsert por id externo codifica o id e mantém os nomes dos atributos', async () => {
  replies.push({ status: 201, body: { id: 'k1', external_id: 'CLI/42 A', attributes: { data_vencimento: '2026-12-01' }, group_ids: [] } })
  const contact = await client().contacts.upsertByExternalId('CLI/42 A',
    ContactRequest.builder().name('Ana').phone('841234567').tags('vip', 'maputo').attribute('data_vencimento', '2026-12-01').attribute('velho', null).build())
  assert.equal(seen[0].method, 'PUT')
  assert.equal(seen[0].url, '/api/v1/contacts/external/CLI%2F42%20A')
  assert.deepEqual(seen[0].body, { name: 'Ana', phone: '841234567', tags: ['vip', 'maputo'], attributes: { data_vencimento: '2026-12-01', velho: null } })
  assert.equal(contact.externalId, 'CLI/42 A')
  assert.deepEqual(contact.attributes, { data_vencimento: '2026-12-01' })
})

test('update só envia os campos definidos; consentimento valida o estado', async () => {
  replies.push({ status: 200, body: { id: 'k1' } })
  await client().contacts.update('k1', ContactRequest.builder().status('inactive').tags().build())
  assert.equal(seen[0].method, 'PATCH')
  assert.deepEqual(seen[0].body, { status: 'inactive', tags: [] })
  replies.push({ status: 200, body: { id: 'k1' } })
  await client().contacts.recordConsent('k1', 'sms', 'revoked', 'erp')
  assert.deepEqual(seen[1].body, { channel: 'sms', status: 'revoked', source: 'erp' })
  await assert.rejects(client().contacts.recordConsent('k1', 'sms', 'talvez', 'erp'), RangeError)
})

test('carteira e campanhas', async () => {
  replies.push({ status: 200, body: { currency: 'MZN', balance: 10, reserved: 1, available: 9 } })
  assert.equal((await client().wallet.get()).available, 9)
  replies.push({ status: 200, body: { items: [{ business_reference: 'r1' }], page: 1, page_size: 20, total: 1 } })
  assert.equal((await client().wallet.transactions(1, 20)).items[0].businessReference, 'r1')
  assert.equal(seen[1].url, '/api/v1/wallet/transactions?page=1&page_size=20')
  replies.push({ status: 200, body: { campaign_id: 'c1', messages: { delivered: 3 }, unique_clicks: 1 } })
  const stats = await client().campaigns.stats('c1')
  assert.equal(seen[2].url, '/api/v1/campaigns/c1/stats')
  assert.equal(stats.messages.delivered, 3)
  assert.equal(stats.uniqueClicks, 1)
})

// ------------------------------------------------------------------ Request builders
test('validação do SendMessageRequest', () => {
  const b = () => SendMessageRequest.builder()
  assert.throws(() => b().to('+258841234567').build(), /entre 1 e 3/)
  assert.throws(() => b().sms('a').sms('b').to('x').build(), /uma vez/)
  assert.throws(() => b().sms('a').build(), /destinatário/)
  assert.throws(() => b().sms('  ').to('x').build(), /SMS/)
  assert.throws(() => b().email('', 'x').to('x').build(), /assunto/)
  assert.throws(() => b().whatsapp('').to('x').build(), /WhatsApp/)
  assert.throws(() => b().sms('a').to(Array.from({ length: 1001 }, (_, i) => `+2588400${i}`)).build(), /1000/)
  assert.throws(() => b().idempotencyKey('com espaço'), RangeError)
  assert.throws(() => b().idempotencyKey('x'.repeat(201)), RangeError)
  const wa = b().whatsappTemplate('aviso', 'pt_PT', 'Ana', 'FT 1').contacts(['k1', 'k2']).build()
  assert.deepEqual(wa.body, { channels: [{ channel: 'whatsapp', whatsapp_template: { name: 'aviso', language: 'pt_PT', parameters: ['Ana', 'FT 1'] } }], contact_ids: ['k1', 'k2'] })
  assert.ok(Object.isFrozen(wa))
})

// ------------------------------------------------------------------ Webhooks
const SECRET = 'whsec_test'
const BODY = '{"id":"evt_1","type":"ping","created_at":"2026-11-08T10:20:00Z","data":{"message":"ping"}}'
const T = 1762597200
const V1 = 'cde593b98434dd7cd2944443131e9bf1bf47bc37630a621aa9481d76ab38b77e'
const verifier = (now = T * 1000) => new WebhookVerifier(SECRET, { now: () => now })

test('webhook: vector fixo válido', () => {
  assert.equal(createHmac('sha256', SECRET).update(`${T}.${BODY}`).digest('hex'), V1)
  const event = verifier().verify(Buffer.from(BODY), `t=${T},v1=${V1}`)
  assert.equal(event.id, 'evt_1')
  assert.equal(event.type, 'ping')
  assert.equal(event.createdAt, '2026-11-08T10:20:00Z')
  assert.deepEqual(event.data, { message: 'ping' })
})

test('webhook: assinatura errada, expirado e cabeçalho mal formado', () => {
  assert.throws(() => verifier().verify(BODY, `t=${T},v1=${'0'.repeat(64)}`), WebhookSignatureError)
  assert.throws(() => verifier().verify(BODY + ' ', `t=${T},v1=${V1}`), /inválida/)
  assert.throws(() => verifier((T + 301) * 1000).verify(BODY, `t=${T},v1=${V1}`), /janela/)
  assert.doesNotThrow(() => verifier((T - 300) * 1000).verify(BODY, `t=${T},v1=${V1}`))
  assert.throws(() => verifier().verify(BODY, `v1=${V1}`), /mal formado/)
  assert.throws(() => verifier().verify(BODY, undefined), /mal formado/)
  assert.throws(() => verifier().verify(BODY, `t=abc,v1=${V1}`), /mal formado/)
})

test('webhook: evento de mensagem', () => {
  const body = JSON.stringify({ id: 'e2', type: 'message.delivered', created_at: '2026-11-08T10:00:00Z', data: { message_id: 'm1', external_id: 'CLI-1', status: 'delivered', cost: 1.5, occurred_at: '2026-11-08T10:00:00Z' } })
  const sig = createHmac('sha256', SECRET).update(`${T}.${body}`).digest('hex')
  const event = verifier().verify(body, `t=${T},v1=${sig}`)
  assert.ok(isMessageEvent(event))
  assert.equal(event.data.messageId, 'm1')
  assert.equal(event.data.externalId, 'CLI-1')
})
