package tech.e258tech.nexorasms;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertInstanceOf;
import static org.junit.jupiter.api.Assertions.assertNull;
import static org.junit.jupiter.api.Assertions.assertThrows;
import static org.junit.jupiter.api.Assertions.assertTrue;

import com.fasterxml.jackson.databind.JsonNode;
import com.sun.net.httpserver.Headers;
import com.sun.net.httpserver.HttpServer;
import java.io.IOException;
import java.io.InputStream;
import java.io.OutputStream;
import java.math.BigDecimal;
import java.net.InetAddress;
import java.net.InetSocketAddress;
import java.net.ServerSocket;
import java.nio.charset.StandardCharsets;
import java.time.Clock;
import java.time.Duration;
import java.time.Instant;
import java.time.OffsetDateTime;
import java.time.ZoneOffset;
import java.util.ArrayList;
import java.util.Collections;
import java.util.List;
import java.util.Map;
import java.util.concurrent.ConcurrentLinkedQueue;
import java.util.concurrent.Executors;
import java.util.function.UnaryOperator;
import java.util.stream.IntStream;
import org.junit.jupiter.api.AfterAll;
import org.junit.jupiter.api.BeforeAll;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;
import tech.e258tech.nexorasms.error.AuthenticationException;
import tech.e258tech.nexorasms.error.ConflictException;
import tech.e258tech.nexorasms.error.ConnectionException;
import tech.e258tech.nexorasms.error.InsufficientFundsException;
import tech.e258tech.nexorasms.error.NotFoundException;
import tech.e258tech.nexorasms.error.PermissionDeniedException;
import tech.e258tech.nexorasms.error.RateLimitException;
import tech.e258tech.nexorasms.error.ServerException;
import tech.e258tech.nexorasms.error.ValidationException;
import tech.e258tech.nexorasms.internal.Json;
import tech.e258tech.nexorasms.model.AttemptStatus;
import tech.e258tech.nexorasms.model.CampaignStats;
import tech.e258tech.nexorasms.model.CampaignStatus;
import tech.e258tech.nexorasms.model.Channel;
import tech.e258tech.nexorasms.model.ConsentStatus;
import tech.e258tech.nexorasms.model.Contact;
import tech.e258tech.nexorasms.model.ContactStatus;
import tech.e258tech.nexorasms.model.Encoding;
import tech.e258tech.nexorasms.model.EstimateResult;
import tech.e258tech.nexorasms.model.Message;
import tech.e258tech.nexorasms.model.MessageStatus;
import tech.e258tech.nexorasms.model.Page;
import tech.e258tech.nexorasms.model.SendMessageResult;
import tech.e258tech.nexorasms.model.TransactionType;
import tech.e258tech.nexorasms.model.WalletTransaction;

class NexoraSmsClientTest {
  static final String PK = "e258_nex_sms_pk_HXj4qcWmXl0m";
  static final String AK = "e258_nex_sms_ak_secretsecretsecretsecret123";

  // ------------------------------------------------------------------ fake server
  record Reply(int status, String body, Map<String, String> headers, long delayMillis) {
    Reply(int status, String body) {
      this(status, body, Map.of(), 0);
    }

    Reply(int status, String body, Map<String, String> headers) {
      this(status, body, headers, 0);
    }
  }

  record Seen(String method, String url, Headers headers, String body) {
    String header(String name) {
      return headers.getFirst(name);
    }

    JsonNode json() throws IOException {
      return Json.MAPPER.readTree(body);
    }
  }

  static HttpServer server;
  static String baseUrl;
  static final ConcurrentLinkedQueue<Reply> replies = new ConcurrentLinkedQueue<>();
  static final List<Seen> seen = Collections.synchronizedList(new ArrayList<>());
  final List<Duration> sleeps = Collections.synchronizedList(new ArrayList<>());

  @BeforeAll
  static void start() throws IOException {
    server = HttpServer.create(new InetSocketAddress(InetAddress.getLoopbackAddress(), 0), 0);
    server.setExecutor(Executors.newCachedThreadPool());
    server.createContext("/", exchange -> {
      String body;
      try (InputStream in = exchange.getRequestBody()) {
        body = new String(in.readAllBytes(), StandardCharsets.UTF_8);
      }
      String query = exchange.getRequestURI().getRawQuery();
      seen.add(new Seen(exchange.getRequestMethod(),
          exchange.getRequestURI().getRawPath() + (query == null ? "" : "?" + query), exchange.getRequestHeaders(), body));
      Reply reply = replies.poll();
      if (reply == null) reply = new Reply(200, "{}");
      if (reply.delayMillis() > 0) {
        try {
          Thread.sleep(reply.delayMillis());
        } catch (InterruptedException e) {
          Thread.currentThread().interrupt();
        }
      }
      exchange.getResponseHeaders().set("Content-Type", "application/json");
      reply.headers().forEach((k, v) -> exchange.getResponseHeaders().set(k, v));
      byte[] bytes = reply.body() == null ? new byte[0] : reply.body().getBytes(StandardCharsets.UTF_8);
      try {
        exchange.sendResponseHeaders(reply.status(), bytes.length == 0 ? -1 : bytes.length);
        try (OutputStream out = exchange.getResponseBody()) {
          out.write(bytes);
        }
      } catch (IOException ignored) {
        // client gave up (timeout test)
      }
    });
    server.start();
    baseUrl = "http://127.0.0.1:" + server.getAddress().getPort();
  }

  @AfterAll
  static void stop() {
    server.stop(0);
  }

  @BeforeEach
  void reset() {
    replies.clear();
    seen.clear();
    sleeps.clear();
  }

  NexoraSmsClient client() {
    return client(b -> b);
  }

  NexoraSmsClient client(UnaryOperator<NexoraSmsClient.Builder> configure) {
    return configure.apply(NexoraSmsClient.builder().publicKey(PK).apiKey(AK).baseUrl(baseUrl + "/")
        .sleeper(sleeps::add)).build();
  }

  static SendMessageRequest smsRequest() {
    return SendMessageRequest.builder().sms("Olá", "MINHALOJA").to("+258841234567").build();
  }

  static final String SEND_RESULT =
      "{\"channels\":[{\"channel\":\"sms\",\"campaign_id\":\"c1\",\"recipients\":1,\"cost\":1.5,\"status\":\"running\"}],\"invalid\":[]}";

  static JsonNode json(String text) throws IOException {
    return Json.MAPPER.readTree(text);
  }

  // ------------------------------------------------------------------ client builder
  @Test
  void builderRequiresBothKeysWithTheRightPrefix() {
    IllegalStateException e = assertThrows(IllegalStateException.class, () -> NexoraSmsClient.builder().apiKey(AK).build());
    assertTrue(e.getMessage().contains("publicKey"));
    e = assertThrows(IllegalStateException.class, () -> NexoraSmsClient.builder().publicKey(PK).build());
    assertTrue(e.getMessage().contains("apiKey"));
    e = assertThrows(IllegalStateException.class, () -> NexoraSmsClient.builder().publicKey("nxk_x").apiKey(AK).build());
    assertTrue(e.getMessage().contains("e258_nex_sms_pk_"));
    e = assertThrows(IllegalStateException.class, () -> NexoraSmsClient.builder().publicKey(PK).apiKey(PK).build());
    assertTrue(e.getMessage().contains("e258_nex_sms_ak_"));
    assertThrows(IllegalArgumentException.class, () -> NexoraSmsClient.builder().maxRetries(-1));
    assertThrows(IllegalArgumentException.class, () -> NexoraSmsClient.builder().timeout(Duration.ZERO));
    assertThrows(IllegalArgumentException.class, () -> NexoraSmsClient.builder().baseUrl("sms.e258tech.tech"));
    assertThrows(IllegalArgumentException.class, () -> NexoraSmsClient.builder().httpClient(null));

    NexoraSmsClient defaults = NexoraSmsClient.builder().publicKey(PK).apiKey(AK).build();
    assertEquals("https://sms.e258tech.tech", defaults.baseUrl());
    assertEquals(Duration.ofSeconds(30), defaults.timeout());
    assertEquals(2, defaults.maxRetries());
    assertEquals("nexorasms-java/0.1.0", defaults.userAgent());
    assertEquals(baseUrl, client().baseUrl());
  }

  // ------------------------------------------------------------------ messages
  @Test
  void sendSendsKeysSnakeCaseJsonAndAGeneratedIdempotencyKey() throws IOException {
    replies.add(new Reply(201, SEND_RESULT));
    SendMessageResult result = client(b -> b.userAgent("loja/1.0")).messages().send(SendMessageRequest.builder()
        .sms("Olá", "MINHALOJA").email("Assunto", "<p>Olá</p>").to("+258841234567", "ana@x.co.mz").contact("k1")
        .ignoreQuietHours(true).build());

    Seen req = seen.get(0);
    assertEquals("POST", req.method());
    assertEquals("/api/v1/messages", req.url());
    assertEquals(PK, req.header("X-Public-Key"));
    assertEquals(AK, req.header("X-Api-Key"));
    assertEquals("application/json", req.header("Accept"));
    assertEquals("application/json", req.header("Content-Type"));
    assertEquals("nexorasms-java/0.1.0 loja/1.0", req.header("User-Agent"));
    assertTrue(req.header("Idempotency-Key").matches("[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}"));
    assertEquals(json("""
        {"channels":[{"channel":"sms","content":"Olá","sender":"MINHALOJA"},
                     {"channel":"email","subject":"Assunto","content":"<p>Olá</p>"}],
         "recipients":["+258841234567","ana@x.co.mz"],"contact_ids":["k1"],"ignore_quiet_hours":true}"""), req.json());

    assertEquals("c1", result.channels().get(0).campaignId());
    assertEquals(Channel.SMS, result.channels().get(0).channel());
    assertEquals(CampaignStatus.RUNNING, result.channels().get(0).status());
    assertEquals(new BigDecimal("1.5"), result.channels().get(0).cost());
    assertEquals(req.header("Idempotency-Key"), result.idempotencyKey());
    assertFalse(result.replayed());
  }

  @Test
  void sendRetries503And429WithTheSameKeyAndHonoursRetryAfter() {
    replies.add(new Reply(503, "{\"title\":\"Indisponível\",\"status\":503}"));
    replies.add(new Reply(429, "{\"title\":\"Devagar\",\"status\":429}", Map.of("Retry-After", "2")));
    replies.add(new Reply(201, SEND_RESULT, Map.of("Idempotent-Replayed", "true")));
    SendMessageResult result = client().messages().send(
        SendMessageRequest.builder().sms("Olá").to("+258841234567").idempotencyKey("fatura-123").build());

    assertEquals(3, seen.size());
    seen.forEach(s -> assertEquals("fatura-123", s.header("Idempotency-Key")));
    assertEquals(2, sleeps.size());
    long first = sleeps.get(0).toMillis();
    assertTrue(first >= 400 && first <= 600, "backoff with ±20% jitter: " + first);
    assertEquals(Duration.ofSeconds(2), sleeps.get(1));
    assertTrue(result.replayed());
    assertEquals("fatura-123", result.idempotencyKey());
  }

  @Test
  void generatedKeyIsReusedAcrossRetriesButNewPerCall() {
    replies.add(new Reply(502, ""));
    replies.add(new Reply(201, SEND_RESULT));
    replies.add(new Reply(201, SEND_RESULT));
    NexoraSmsClient client = client();
    client.messages().send(smsRequest());
    client.messages().send(smsRequest());
    assertEquals(seen.get(0).header("Idempotency-Key"), seen.get(1).header("Idempotency-Key"));
    assertFalse(seen.get(1).header("Idempotency-Key").equals(seen.get(2).header("Idempotency-Key")));
  }

  @Test
  void doesNotRetry400Or402And402BecomesInsufficientFunds() {
    replies.add(new Reply(402, "{\"title\":\"Saldo insuficiente para este envio.\",\"status\":402,\"traceId\":\"t1\"}"));
    InsufficientFundsException funds = assertThrows(InsufficientFundsException.class,
        () -> client().messages().send(smsRequest()));
    assertEquals(402, funds.status());
    assertEquals("Saldo insuficiente para este envio.", funds.getMessage());
    assertEquals("Saldo insuficiente para este envio.", funds.title());
    assertEquals("t1", funds.traceId());
    assertEquals(1, seen.size());

    replies.add(new Reply(400, "{\"title\":\"Pedido inválido\",\"status\":400,\"errors\":{\"channels\":[\"obrigatório\"]}}"));
    ValidationException invalid = assertThrows(ValidationException.class, () -> client().messages().send(smsRequest()));
    assertEquals(List.of("obrigatório"), invalid.errors().get("channels"));
    assertEquals(2, seen.size());
    assertTrue(sleeps.isEmpty());
  }

  @Test
  void givesUpAfterMaxRetriesWithTheLastError() {
    for (int i = 0; i < 3; i++) replies.add(new Reply(503, "{\"title\":\"Indisponível\",\"status\":503}"));
    assertThrows(ServerException.class, () -> client().messages().send(smsRequest()));
    assertEquals(3, seen.size());

    replies.add(new Reply(503, "{}"));
    ServerException e = assertThrows(ServerException.class, () -> client(b -> b.maxRetries(0)).wallet().get());
    assertEquals("HTTP 503", e.getMessage());
    assertEquals(4, seen.size());

    replies.add(new Reply(500, "{\"detail\":\"Erro interno\"}"));
    ServerException internal = assertThrows(ServerException.class, () -> client().wallet().get());
    assertEquals("Erro interno", internal.getMessage());
    assertEquals(5, seen.size()); // 500 is never retried
  }

  @Test
  void conflictIsOnlyRetriedWithRetryAfterAndRetryAfterIsCapped() {
    replies.add(new Reply(409, "{\"title\":\"Em processamento\",\"status\":409}", Map.of("Retry-After", "1")));
    replies.add(new Reply(201, SEND_RESULT));
    client().messages().send(smsRequest());
    assertEquals(2, seen.size());
    assertEquals(Duration.ofSeconds(1), sleeps.get(0));

    replies.add(new Reply(409, "{\"title\":\"Conteúdo diferente\",\"status\":409}"));
    assertThrows(ConflictException.class, () -> client().messages().send(smsRequest()));
    assertEquals(3, seen.size());

    replies.add(new Reply(429, "{}", Map.of("Retry-After", "120")));
    replies.add(new Reply(200, "{\"currency\":\"MZN\"}"));
    client().wallet().get();
    assertEquals(Duration.ofSeconds(30), sleeps.get(sleeps.size() - 1));
  }

  @Test
  void mapsStatusCodesToExceptions() {
    replies.add(new Reply(401, "{\"title\":\"Unauthorized\",\"status\":401}"));
    assertThrows(AuthenticationException.class, () -> client().wallet().get());
    replies.add(new Reply(403, "{\"title\":\"Forbidden\",\"status\":403}"));
    assertThrows(PermissionDeniedException.class, () -> client().wallet().get());
    replies.add(new Reply(404, "{\"title\":\"Mensagem não encontrada.\",\"status\":404}"));
    assertThrows(NotFoundException.class, () -> client().messages().get("m1"));
    replies.add(new Reply(429, "{}"));
    replies.add(new Reply(429, "{}"));
    replies.add(new Reply(429, "{}", Map.of("Retry-After", "5")));
    RateLimitException limit = assertThrows(RateLimitException.class, () -> client().wallet().get());
    assertEquals(Duration.ofSeconds(5), limit.retryAfter().orElseThrow());
    assertEquals(429, limit.status());
  }

  @Test
  void retryAfterAcceptsAnHttpDate() {
    Clock clock = Clock.fixed(Instant.parse("2026-11-08T10:00:00Z"), ZoneOffset.UTC);
    NexoraSmsClient client = client(b -> b.clock(clock));
    assertEquals(Duration.ofSeconds(7), client.parseRetryAfter("Sun, 08 Nov 2026 10:00:07 GMT"));
    assertEquals(Duration.ZERO, client.parseRetryAfter("Sun, 08 Nov 2026 09:00:00 GMT"));
    assertNull(client.parseRetryAfter("amanhã"));
  }

  @Test
  void networkErrorBecomesConnectionExceptionAfterRetries() throws IOException {
    int port;
    try (ServerSocket socket = new ServerSocket(0, 1, InetAddress.getLoopbackAddress())) {
      port = socket.getLocalPort();
    }
    NexoraSmsClient failing = NexoraSmsClient.builder().publicKey(PK).apiKey(AK).baseUrl("http://127.0.0.1:" + port)
        .sleeper(sleeps::add).build();
    ConnectionException e = assertThrows(ConnectionException.class, () -> failing.wallet().get());
    assertEquals(0, e.status());
    assertTrue(e.getMessage().startsWith("Não foi possível contactar"));
    assertEquals(2, sleeps.size()); // 3 attempts

    sleeps.clear();
    assertThrows(ConnectionException.class, () -> failing.contacts().create(ContactRequest.builder().name("Ana").build()));
    assertTrue(sleeps.isEmpty()); // POST /contacts is not idempotent: never retried
    assertThrows(ConnectionException.class, () -> failing.contacts().update("k1", ContactRequest.builder().name("Ana").build()));
    assertThrows(ConnectionException.class, () -> failing.contacts().recordConsent("k1", Channel.SMS, ConsentStatus.GRANTED, "erp"));
    assertTrue(sleeps.isEmpty());
  }

  @Test
  void timeoutBecomesConnectionException() {
    replies.add(new Reply(200, "{}", Map.of(), 2_000));
    ConnectionException e = assertThrows(ConnectionException.class,
        () -> client(b -> b.timeout(Duration.ofMillis(200)).maxRetries(0)).wallet().get());
    assertTrue(e.getMessage().startsWith("Tempo limite excedido"), e.getMessage());
  }

  @Test
  void estimateGetAndListWithQueryString() {
    replies.add(new Reply(200, """
        {"channels":[{"channel":"sms","recipients":1,"segments":1,"encoding":"gsm7","unit_price":1.5,"cost":1.5}],
         "total":1.5,"available":10,"invalid":["123"],"novo_campo":true}"""));
    EstimateResult estimate = client().messages().estimate(smsRequest());
    assertEquals("/api/v1/messages/estimate", seen.get(0).url());
    assertNull(seen.get(0).header("Idempotency-Key"));
    assertEquals(new BigDecimal("1.5"), estimate.channels().get(0).unitPrice());
    assertEquals(Encoding.GSM7, estimate.channels().get(0).encoding());
    assertEquals(0, new BigDecimal("10").compareTo(estimate.available()));
    assertEquals(List.of("123"), estimate.invalid());

    replies.add(new Reply(200, """
        {"items":[{"id":"m1","campaign_id":"c1","status":"teleported","created_at":"2026-11-08T10:15:00+02:00",
                   "attempts":[{"part_number":1,"status":"in_flight"}]}],"page":2,"page_size":10,"total":11}"""));
    Page<Message> page = client().messages().list(MessageQuery.builder().campaignId("c1").status(MessageStatus.FAILED)
        .page(2).pageSize(10).build());
    assertEquals("GET", seen.get(1).method());
    assertNull(seen.get(1).header("Content-Type"));
    assertEquals("/api/v1/messages?campaign_id=c1&status=failed&page=2&page_size=10", seen.get(1).url());
    assertEquals(10, page.pageSize());
    assertEquals(11, page.total());
    assertFalse(page.hasNext());
    Message message = page.items().get(0);
    assertEquals(MessageStatus.UNRECOGNIZED, message.status());
    assertEquals(OffsetDateTime.parse("2026-11-08T10:15:00+02:00"), message.createdAt());
    assertEquals(1, message.attempts().get(0).partNumber());
    assertEquals(AttemptStatus.IN_FLIGHT, message.attempts().get(0).status());
    assertThrows(UnsupportedOperationException.class, () -> page.items().clear());

    replies.add(new Reply(200, "{\"id\":\"m 1\"}"));
    client().messages().get("m 1");
    assertEquals("/api/v1/messages/m%201", seen.get(2).url());

    replies.add(new Reply(200, "{\"items\":[],\"page\":1,\"page_size\":50,\"total\":0}"));
    client().contacts().list(ContactQuery.builder().tag("vip").search("ana maria").build());
    assertEquals("/api/v1/contacts?tag=vip&search=ana%20maria", seen.get(3).url());

    replies.add(new Reply(200, "{\"items\":[],\"page\":1,\"page_size\":50,\"total\":0}"));
    client().messages().list();
    assertEquals("/api/v1/messages", seen.get(4).url());
  }

  // ------------------------------------------------------------------ contacts
  @Test
  void upsertEncodesTheExternalIdAndKeepsAttributeNames() throws IOException {
    replies.add(new Reply(201, """
        {"id":"k1","external_id":"CLI/42 A","status":"active","attributes":{"data_vencimento":"2026-12-01"},"group_ids":[]}"""));
    Contact contact = client().contacts().upsertByExternalId("CLI/42 A", ContactRequest.builder().name("Ana")
        .phone("841234567").tags("vip", "maputo").attribute("data_vencimento", "2026-12-01").attribute("velho", null)
        .build());
    assertEquals("PUT", seen.get(0).method());
    assertEquals("/api/v1/contacts/external/CLI%2F42%20A", seen.get(0).url());
    assertEquals(json("""
        {"name":"Ana","phone":"841234567","tags":["vip","maputo"],
         "attributes":{"data_vencimento":"2026-12-01","velho":null}}"""), seen.get(0).json());
    assertEquals("CLI/42 A", contact.externalId());
    assertEquals(ContactStatus.ACTIVE, contact.status());
    assertEquals(Map.of("data_vencimento", "2026-12-01"), contact.attributes());
    assertEquals(List.of(), contact.tags());
  }

  @Test
  void upsertIsRetriedButCreateIsNot() {
    replies.add(new Reply(503, "{}"));
    replies.add(new Reply(200, "{\"id\":\"k1\"}"));
    client().contacts().upsertByExternalId("CLI-1", ContactRequest.builder().name("Ana").build());
    assertEquals(2, seen.size());

    replies.add(new Reply(503, "{}"));
    assertThrows(ServerException.class, () -> client().contacts().create(ContactRequest.builder().name("Ana").build()));
    assertEquals(3, seen.size());
  }

  @Test
  void updateSendsOnlyDefinedFieldsAndConsentValidatesTheStatus() throws IOException {
    replies.add(new Reply(200, "{\"id\":\"k1\"}"));
    client().contacts().update("k1", ContactRequest.builder().status(ContactStatus.INACTIVE).tags().build());
    assertEquals("PATCH", seen.get(0).method());
    assertEquals("/api/v1/contacts/k1", seen.get(0).url());
    assertEquals(json("{\"status\":\"inactive\",\"tags\":[]}"), seen.get(0).json());

    replies.add(new Reply(200, "{\"id\":\"k1\",\"consents\":[{\"channel\":\"sms\",\"status\":\"revoked\",\"source\":\"erp\"}]}"));
    Contact contact = client().contacts().recordConsent("k1", Channel.SMS, ConsentStatus.REVOKED, "erp");
    assertEquals("/api/v1/contacts/k1/consents", seen.get(1).url());
    assertEquals(json("{\"channel\":\"sms\",\"status\":\"revoked\",\"source\":\"erp\"}"), seen.get(1).json());
    assertEquals(ConsentStatus.REVOKED, contact.consents().get(0).status());

    assertThrows(IllegalArgumentException.class,
        () -> client().contacts().recordConsent("k1", Channel.SMS, ConsentStatus.UNRECOGNIZED, "erp"));
    assertThrows(IllegalArgumentException.class, () -> client().contacts().recordConsent("k1", null, ConsentStatus.GRANTED, "erp"));
    assertThrows(IllegalArgumentException.class, () -> client().contacts().get(" "));
    assertThrows(IllegalArgumentException.class, () -> ContactRequest.builder().status(ContactStatus.UNRECOGNIZED));
    assertEquals(2, seen.size());
  }

  @Test
  void contactRequestSerialisesOnlyWhatWasSet() throws IOException {
    ContactRequest request = ContactRequest.builder().email("a@b.co").externalId("E1").groupId("g1").groupIds("g2")
        .attributes(Map.of("plano", "ouro")).build();
    assertEquals(json("{\"email\":\"a@b.co\",\"group_ids\":[\"g1\",\"g2\"],\"attributes\":{\"plano\":\"ouro\"},\"external_id\":\"E1\"}"),
        json(Json.MAPPER.writeValueAsString(request.body())));
    assertEquals(json("{}"), json(Json.MAPPER.writeValueAsString(ContactRequest.builder().build().body())));
  }

  // ------------------------------------------------------------------ wallet and campaigns
  @Test
  void walletAndCampaigns() {
    replies.add(new Reply(200, "{\"currency\":\"MZN\",\"balance\":10.25,\"reserved\":1,\"available\":9.25}"));
    assertEquals(new BigDecimal("9.25"), client().wallet().get().available());

    replies.add(new Reply(200, """
        {"items":[{"id":"t1","type":"debit","amount":-1.5,"business_reference":"r1","created_at":"2026-11-08T10:00:00Z"}],
         "page":1,"page_size":20,"total":21}"""));
    Page<WalletTransaction> transactions = client().wallet().transactions(1, 20);
    assertEquals("/api/v1/wallet/transactions?page=1&page_size=20", seen.get(1).url());
    assertEquals("r1", transactions.items().get(0).businessReference());
    assertEquals(TransactionType.DEBIT, transactions.items().get(0).type());
    assertTrue(transactions.hasNext());
    assertThrows(IllegalArgumentException.class, () -> client().wallet().transactions(0, 20));

    replies.add(new Reply(200, "{\"campaign_id\":\"c1\",\"status\":\"completed\",\"messages\":{\"delivered\":3},\"unique_clicks\":1}"));
    CampaignStats stats = client().campaigns().stats("c1");
    assertEquals("/api/v1/campaigns/c1/stats", seen.get(2).url());
    assertEquals(3, stats.messages().get("delivered"));
    assertEquals(1, stats.uniqueClicks());
    assertEquals(CampaignStatus.COMPLETED, stats.status());

    replies.add(new Reply(200, "{\"id\":\"c1\",\"name\":\"Natal\",\"channel\":\"email\",\"ignore_quiet_hours\":true,\"delivered\":4}"));
    var campaign = client().campaigns().get("c1");
    assertEquals("/api/v1/campaigns/c1", seen.get(3).url());
    assertEquals(Channel.EMAIL, campaign.channel());
    assertTrue(campaign.ignoreQuietHours());
    assertEquals(4, campaign.delivered());
    assertNull(campaign.failed());
  }

  // ------------------------------------------------------------------ request builders
  @Test
  void sendMessageRequestValidation() throws IOException {
    assertBuildFails(SendMessageRequest.builder().to("+258841234567"), "entre 1 e 3");
    assertBuildFails(SendMessageRequest.builder().sms("a").sms("b").to("x"), "uma vez");
    assertBuildFails(SendMessageRequest.builder().sms("a"), "destinatário");
    assertBuildFails(SendMessageRequest.builder().sms("  ").to("x"), "SMS");
    assertBuildFails(SendMessageRequest.builder().email("", "x").to("x"), "assunto");
    assertBuildFails(SendMessageRequest.builder().email("a", " ").to("x"), "conteúdo");
    assertBuildFails(SendMessageRequest.builder().whatsapp("").to("x"), "WhatsApp");
    assertBuildFails(SendMessageRequest.builder().whatsappTemplate("", "pt_PT").to("x"), "nome e idioma");
    assertBuildFails(SendMessageRequest.builder().sms("a").email("s", "c").whatsapp("w").sms("b").to("x"), "entre 1 e 3");
    List<String> many = IntStream.range(0, 1001).mapToObj(i -> "+2588400" + i).toList();
    assertBuildFails(SendMessageRequest.builder().sms("a").to(many), "1000");
    assertBuildFails(SendMessageRequest.builder().sms("a").contacts(many), "1000");
    assertThrows(IllegalArgumentException.class, () -> SendMessageRequest.builder().idempotencyKey("com espaço"));
    assertThrows(IllegalArgumentException.class, () -> SendMessageRequest.builder().idempotencyKey("x".repeat(201)));
    assertThrows(IllegalArgumentException.class, () -> SendMessageRequest.builder().idempotencyKey(""));
    assertThrows(IllegalArgumentException.class, () -> SendMessageRequest.builder().to((String) null));
    SendMessageRequest.builder().idempotencyKey("x".repeat(200));

    SendMessageRequest wa = SendMessageRequest.builder().whatsappTemplate("aviso", "pt_PT", "Ana", "FT 1")
        .contacts(List.of("k1", "k2")).build();
    assertEquals(json("""
        {"channels":[{"channel":"whatsapp","whatsapp_template":{"name":"aviso","language":"pt_PT","parameters":["Ana","FT 1"]}}],
         "contact_ids":["k1","k2"]}"""), json(Json.MAPPER.writeValueAsString(wa.body())));
    assertTrue(wa.idempotencyKey().isEmpty());
    assertThrows(UnsupportedOperationException.class, () -> wa.body().channels().clear());
  }

  @Test
  void queryValidation() {
    assertThrows(IllegalArgumentException.class, () -> MessageQuery.builder().pageSize(500));
    assertThrows(IllegalArgumentException.class, () -> ContactQuery.builder().pageSize(0));
    assertThrows(IllegalArgumentException.class, () -> ContactQuery.builder().page(0));
    assertThrows(IllegalArgumentException.class, () -> MessageQuery.builder().channel(Channel.UNRECOGNIZED));
    assertEquals(Map.of("channel", "email", "page_size", "200"),
        MessageQuery.builder().channel(Channel.EMAIL).pageSize(200).build().params());
    assertEquals(Map.of("group_id", "g1", "status", "inactive"),
        ContactQuery.builder().groupId("g1").status(ContactStatus.INACTIVE).build().params());
  }

  private static void assertBuildFails(SendMessageRequest.Builder builder, String fragment) {
    IllegalStateException e = assertThrows(IllegalStateException.class, builder::build);
    assertTrue(e.getMessage().contains(fragment), e.getMessage());
  }

  @Test
  void unknownErrorBodyStillMapsByStatus() {
    replies.add(new Reply(404, "<html>not json</html>"));
    NotFoundException e = assertThrows(NotFoundException.class, () -> client().campaigns().get("c9"));
    assertEquals("HTTP 404", e.getMessage());
    assertInstanceOf(Map.class, e.errors());
    assertTrue(e.retryAfter().isEmpty());
  }
}
