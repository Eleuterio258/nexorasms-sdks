package tech.e258tech.nexorasms;

import com.fasterxml.jackson.core.JsonProcessingException;
import com.fasterxml.jackson.databind.JavaType;
import java.io.IOException;
import java.net.URI;
import java.net.URISyntaxException;
import java.net.URLEncoder;
import java.net.http.HttpClient;
import java.net.http.HttpHeaders;
import java.net.http.HttpRequest;
import java.net.http.HttpResponse;
import java.net.http.HttpTimeoutException;
import java.nio.charset.StandardCharsets;
import java.time.Clock;
import java.time.Duration;
import java.time.ZonedDateTime;
import java.time.format.DateTimeFormatter;
import java.time.format.DateTimeParseException;
import java.util.Map;
import java.util.StringJoiner;
import java.util.concurrent.ThreadLocalRandom;
import tech.e258tech.nexorasms.error.ConnectionException;
import tech.e258tech.nexorasms.error.NexoraSmsException;
import tech.e258tech.nexorasms.internal.Json;

/**
 * Client of the NexoraSMS API. Create it with {@link #builder()}; it is immutable and safe to share between threads.
 *
 * <pre>{@code
 * NexoraSmsClient client = NexoraSmsClient.builder()
 *     .publicKey(System.getenv("NEXORASMS_PUBLIC_KEY"))
 *     .apiKey(System.getenv("NEXORASMS_API_KEY"))
 *     .build();
 * }</pre>
 */
public final class NexoraSmsClient {
  /** SDK version. */
  public static final String VERSION = "0.1.0";
  /** Production API address. */
  public static final String DEFAULT_BASE_URL = "https://sms.e258tech.tech";

  static final String PUBLIC_PREFIX = "e258_nex_sms_pk_";
  static final String SECRET_PREFIX = "e258_nex_sms_ak_";
  private static final Duration MAX_RETRY_AFTER = Duration.ofSeconds(30);
  private static final long MAX_BACKOFF_MS = 8_000;

  private final String publicKey;
  private final String apiKey;
  private final String baseUrl;
  private final Duration timeout;
  private final int maxRetries;
  private final String userAgent;
  private final HttpClient http;
  private final Sleeper sleeper;
  private final Clock clock;

  private final MessagesApi messages;
  private final ContactsApi contacts;
  private final WalletApi wallet;
  private final CampaignsApi campaigns;

  private NexoraSmsClient(Builder b) {
    this.publicKey = b.publicKey;
    this.apiKey = b.apiKey;
    this.baseUrl = b.baseUrl;
    this.timeout = b.timeout;
    this.maxRetries = b.maxRetries;
    this.userAgent = "nexorasms-java/" + VERSION + (b.userAgent == null ? "" : " " + b.userAgent);
    this.http = b.httpClient != null ? b.httpClient : HttpClient.newBuilder().connectTimeout(b.timeout).build();
    this.sleeper = b.sleeper;
    this.clock = b.clock;
    this.messages = new MessagesApi(this);
    this.contacts = new ContactsApi(this);
    this.wallet = new WalletApi(this);
    this.campaigns = new CampaignsApi(this);
  }

  /** Starts a new client builder. */
  public static Builder builder() {
    return new Builder();
  }

  /** Messages: send, estimate, get and list. */
  public MessagesApi messages() {
    return messages;
  }

  /** Contacts: upsert by external id, create, get, update, list and consents. */
  public ContactsApi contacts() {
    return contacts;
  }

  /** Wallet balance and transactions. */
  public WalletApi wallet() {
    return wallet;
  }

  /** Campaigns and their statistics. */
  public CampaignsApi campaigns() {
    return campaigns;
  }

  /** The API address in use (without a trailing slash). */
  public String baseUrl() {
    return baseUrl;
  }

  /** Per-attempt timeout. */
  public Duration timeout() {
    return timeout;
  }

  /** Maximum number of retries per call. */
  public int maxRetries() {
    return maxRetries;
  }

  /** The full User-Agent sent with every request. */
  public String userAgent() {
    return userAgent;
  }

  // ------------------------------------------------------------------ transport

  record Result<T>(T data, HttpHeaders headers) {}

  JavaType type(Class<?> raw, Class<?>... parameters) {
    return parameters.length == 0
        ? Json.MAPPER.constructType(raw)
        : Json.MAPPER.getTypeFactory().constructParametricType(raw, parameters);
  }

  /**
   * Sends a request, retrying when {@code retryable} and the failure is transient (network error, 429, 502–504,
   * 409 with Retry-After).
   */
  <T> Result<T> call(String method, String path, Map<String, String> query, Object body, Map<String, String> headers,
      boolean retryable, JavaType type) {
    URI uri = uri(path, query);
    HttpRequest.Builder builder = HttpRequest.newBuilder(uri)
        .timeout(timeout)
        .header("X-Public-Key", publicKey)
        .header("X-Api-Key", apiKey)
        .header("Accept", "application/json")
        .header("User-Agent", userAgent);
    if (body != null) {
      byte[] payload;
      try {
        payload = Json.MAPPER.writeValueAsBytes(body);
      } catch (JsonProcessingException e) {
        throw new IllegalStateException("Não foi possível serializar o pedido.", e);
      }
      builder.header("Content-Type", "application/json").method(method, HttpRequest.BodyPublishers.ofByteArray(payload));
    } else {
      builder.method(method, HttpRequest.BodyPublishers.noBody());
    }
    if (headers != null) headers.forEach(builder::header);
    HttpRequest request = builder.build();

    for (int attempt = 0; ; attempt++) {
      HttpResponse<String> response;
      try {
        response = http.send(request, HttpResponse.BodyHandlers.ofString(StandardCharsets.UTF_8));
      } catch (IOException e) {
        if (retryable && attempt < maxRetries) {
          pause(backoff(attempt));
          continue;
        }
        String host = uri.getAuthority();
        throw new ConnectionException(e instanceof HttpTimeoutException
            ? "Tempo limite excedido ao contactar " + host + "."
            : "Não foi possível contactar " + host + ".", e);
      } catch (InterruptedException e) {
        Thread.currentThread().interrupt();
        throw new ConnectionException("Pedido interrompido.", e);
      }

      int status = response.statusCode();
      if (status >= 200 && status < 300) return new Result<>(parse(response.body(), type, status), response.headers());

      Duration retryAfter = parseRetryAfter(response.headers().firstValue("Retry-After").orElse(null));
      boolean transientFailure = status == 429 || status == 502 || status == 503 || status == 504
          || (status == 409 && retryAfter != null);
      if (retryable && transientFailure && attempt < maxRetries) {
        pause(retryAfter != null ? min(retryAfter, MAX_RETRY_AFTER) : backoff(attempt));
        continue;
      }
      throw Errors.forResponse(status, response.body(), retryAfter);
    }
  }

  private <T> T parse(String body, JavaType type, int status) {
    if (body == null || body.isBlank()) return null;
    try {
      return Json.MAPPER.readValue(body, type);
    } catch (JsonProcessingException e) {
      throw new NexoraSmsException("Resposta inválida da API.", status, null, null, null, null, null, e);
    }
  }

  private URI uri(String path, Map<String, String> query) {
    StringBuilder url = new StringBuilder(baseUrl).append(path);
    if (query != null && !query.isEmpty()) {
      StringJoiner joiner = new StringJoiner("&", "?", "");
      query.forEach((key, value) -> {
        if (value != null) joiner.add(encode(key) + "=" + encode(value));
      });
      if (joiner.length() > 1) url.append(joiner);
    }
    return URI.create(url.toString());
  }

  /** Percent-encodes a path segment or query component (spaces as %20). */
  static String encode(String value) {
    return URLEncoder.encode(value, StandardCharsets.UTF_8).replace("+", "%20");
  }

  private void pause(Duration duration) {
    try {
      sleeper.sleep(duration);
    } catch (InterruptedException e) {
      Thread.currentThread().interrupt();
      throw new ConnectionException("Pedido interrompido.", e);
    }
  }

  private static Duration backoff(int attempt) {
    long base = Math.min(500L << Math.min(attempt, 20), MAX_BACKOFF_MS);
    double jitter = 0.8 + ThreadLocalRandom.current().nextDouble() * 0.4;
    return Duration.ofMillis(Math.round(base * jitter));
  }

  private static Duration min(Duration a, Duration b) {
    return a.compareTo(b) <= 0 ? a : b;
  }

  Duration parseRetryAfter(String value) {
    if (value == null || value.isBlank()) return null;
    String trimmed = value.trim();
    try {
      long seconds = Long.parseLong(trimmed);
      return seconds >= 0 ? Duration.ofSeconds(seconds) : null;
    } catch (NumberFormatException ignored) {
      // Not delta-seconds: try an HTTP-date.
    }
    try {
      ZonedDateTime date = ZonedDateTime.parse(trimmed, DateTimeFormatter.RFC_1123_DATE_TIME);
      long millis = date.toInstant().toEpochMilli() - clock.millis();
      return Duration.ofSeconds(Math.max(0, (millis + 999) / 1000));
    } catch (DateTimeParseException e) {
      return null;
    }
  }

  // ------------------------------------------------------------------ builder

  /** Builder of {@link NexoraSmsClient}. Not thread-safe; the client it builds is. */
  public static final class Builder {
    private String publicKey;
    private String apiKey;
    private String baseUrl = DEFAULT_BASE_URL;
    private Duration timeout = Duration.ofSeconds(30);
    private int maxRetries = 2;
    private String userAgent;
    private HttpClient httpClient;
    private Sleeper sleeper = Sleeper.REAL;
    private Clock clock = Clock.systemUTC();

    private Builder() {}

    /** Public key (X-Public-Key), {@code e258_nex_sms_pk_…}. Required. */
    public Builder publicKey(String value) {
      this.publicKey = value;
      return this;
    }

    /** Secret key (X-Api-Key), {@code e258_nex_sms_ak_…}. Required; keep it on the server only. */
    public Builder apiKey(String value) {
      this.apiKey = value;
      return this;
    }

    /** API address (default {@value NexoraSmsClient#DEFAULT_BASE_URL}); trailing slashes are removed. */
    public Builder baseUrl(String value) {
      if (value == null) throw new IllegalArgumentException("baseUrl não pode ser nulo.");
      String trimmed = value.trim().replaceAll("/+$", "");
      try {
        URI uri = new URI(trimmed);
        String scheme = uri.getScheme();
        if (uri.getHost() == null || !("http".equalsIgnoreCase(scheme) || "https".equalsIgnoreCase(scheme)))
          throw new IllegalArgumentException("baseUrl inválido: use um endereço http(s) absoluto.");
      } catch (URISyntaxException e) {
        throw new IllegalArgumentException("baseUrl inválido: use um endereço http(s) absoluto.", e);
      }
      this.baseUrl = trimmed;
      return this;
    }

    /** Per-attempt timeout (default 30 s). */
    public Builder timeout(Duration value) {
      if (value == null || value.isZero() || value.isNegative())
        throw new IllegalArgumentException("timeout deve ser uma duração positiva.");
      this.timeout = value;
      return this;
    }

    /** Retries after network errors, 429, 502–504 and 409 with Retry-After (default 2; 0 disables). */
    public Builder maxRetries(int value) {
      if (value < 0) throw new IllegalArgumentException("maxRetries deve ser um inteiro >= 0.");
      this.maxRetries = value;
      return this;
    }

    /** Extra text appended to the SDK's User-Agent (e.g. {@code loja-online/2.3}). */
    public Builder userAgent(String value) {
      this.userAgent = value == null || value.isBlank() ? null : value.trim();
      return this;
    }

    /** Your own {@link HttpClient} (proxy, TLS, executor). The client {@link #timeout(Duration)} still applies per request. */
    public Builder httpClient(HttpClient value) {
      if (value == null) throw new IllegalArgumentException("httpClient não pode ser nulo.");
      this.httpClient = value;
      return this;
    }

    /** Tests: replaces the wait between retries. */
    Builder sleeper(Sleeper value) {
      this.sleeper = value;
      return this;
    }

    /** Tests: clock used to interpret Retry-After dates. */
    Builder clock(Clock value) {
      this.clock = value;
      return this;
    }

    /**
     * Validates the configuration and builds the client.
     *
     * @throws IllegalStateException when a key is missing or has the wrong prefix
     */
    public NexoraSmsClient build() {
      if (publicKey == null || !publicKey.startsWith(PUBLIC_PREFIX))
        throw new IllegalStateException("publicKey em falta ou inválida: deve começar por " + PUBLIC_PREFIX + ".");
      if (apiKey == null || !apiKey.startsWith(SECRET_PREFIX))
        throw new IllegalStateException("apiKey em falta ou inválida: deve começar por " + SECRET_PREFIX + ".");
      return new NexoraSmsClient(this);
    }
  }
}
