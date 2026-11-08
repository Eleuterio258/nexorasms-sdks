package tech.e258tech.nexorasms.webhook;

import java.io.IOException;
import java.nio.charset.StandardCharsets;
import java.security.InvalidKeyException;
import java.security.MessageDigest;
import java.security.NoSuchAlgorithmException;
import java.time.Clock;
import java.time.Duration;
import java.util.HashMap;
import java.util.HexFormat;
import java.util.Map;
import javax.crypto.Mac;
import javax.crypto.spec.SecretKeySpec;
import tech.e258tech.nexorasms.error.WebhookVerificationException;
import tech.e258tech.nexorasms.internal.Json;

/**
 * Verifies {@code X-Nexora-Signature: t=<unix>,v1=<hex HMAC-SHA256(secret, "<t>.<body>")>} and parses the event.
 * Pass the raw body exactly as received, before any JSON parsing. Immutable and thread-safe.
 */
public final class WebhookVerifier {
  /** Name of the signature header. */
  public static final String SIGNATURE_HEADER = "X-Nexora-Signature";
  /** Default tolerance between the signature time and now. */
  public static final Duration DEFAULT_TOLERANCE = Duration.ofSeconds(300);

  private final byte[] secret;
  private final Duration tolerance;
  private final Clock clock;

  /** Verifier with the default tolerance (300 s) and the system clock. */
  public WebhookVerifier(String secret) {
    this(secret, DEFAULT_TOLERANCE, Clock.systemUTC());
  }

  /** Verifier with a custom tolerance and the system clock. */
  public WebhookVerifier(String secret, Duration tolerance) {
    this(secret, tolerance, Clock.systemUTC());
  }

  /** Verifier with a custom tolerance and clock (tests). */
  public WebhookVerifier(String secret, Duration tolerance, Clock clock) {
    if (secret == null || secret.isEmpty()) throw new IllegalArgumentException("Indique o segredo do webhook (whsec_…).");
    if (tolerance == null || tolerance.isNegative()) throw new IllegalArgumentException("A tolerância não pode ser negativa.");
    if (clock == null) throw new IllegalArgumentException("clock não pode ser nulo.");
    this.secret = secret.getBytes(StandardCharsets.UTF_8);
    this.tolerance = tolerance;
    this.clock = clock;
  }

  /** Starts a builder: {@code WebhookVerifier.builder().secret("whsec_…").tolerance(...).build()}. */
  public static Builder builder() {
    return new Builder();
  }

  /** Verifies a body received as text (UTF-8). */
  public WebhookEvent verify(String rawBody, String signatureHeader) {
    return verify(rawBody == null ? new byte[0] : rawBody.getBytes(StandardCharsets.UTF_8), signatureHeader);
  }

  /**
   * Verifies the signature and timestamp and returns the event.
   *
   * @throws WebhookVerificationException when the header is malformed, the signature does not match, the timestamp
   *     is outside the tolerance or the body is not valid JSON
   */
  public WebhookEvent verify(byte[] rawBody, String signatureHeader) {
    byte[] body = rawBody == null ? new byte[0] : rawBody;
    Map<String, String> parts = new HashMap<>();
    if (signatureHeader != null) {
      for (String piece : signatureHeader.split(",")) {
        int index = piece.indexOf('=');
        if (index > 0) parts.put(piece.substring(0, index).trim(), piece.substring(index + 1).trim());
      }
    }
    String t = parts.get("t");
    String v1 = parts.get("v1");
    long timestamp;
    try {
      if (t == null || t.isEmpty() || v1 == null || v1.isEmpty()) throw new NumberFormatException();
      timestamp = Long.parseLong(t);
    } catch (NumberFormatException e) {
      throw new WebhookVerificationException("Cabeçalho X-Nexora-Signature mal formado.");
    }

    byte[] expected = HexFormat.of().formatHex(sign(t, body)).getBytes(StandardCharsets.US_ASCII);
    if (!MessageDigest.isEqual(expected, v1.getBytes(StandardCharsets.UTF_8)))
      throw new WebhookVerificationException("Assinatura do webhook inválida.");
    long skewMillis = Math.abs(clock.millis() - timestamp * 1000);
    if (skewMillis > tolerance.toMillis())
      throw new WebhookVerificationException("Webhook fora da janela de tempo permitida.");

    try {
      return Json.MAPPER.readValue(body, WebhookEvent.class);
    } catch (IOException e) {
      throw new WebhookVerificationException("Corpo do webhook não é JSON válido.", e);
    }
  }

  private byte[] sign(String timestamp, byte[] body) {
    try {
      Mac mac = Mac.getInstance("HmacSHA256");
      mac.init(new SecretKeySpec(secret, "HmacSHA256"));
      mac.update((timestamp + ".").getBytes(StandardCharsets.UTF_8));
      return mac.doFinal(body);
    } catch (NoSuchAlgorithmException | InvalidKeyException e) {
      throw new IllegalStateException("HmacSHA256 indisponível.", e);
    }
  }

  /** Builder of {@link WebhookVerifier}. */
  public static final class Builder {
    private String secret;
    private Duration tolerance = DEFAULT_TOLERANCE;
    private Clock clock = Clock.systemUTC();

    private Builder() {}

    /** The endpoint's signing secret ({@code whsec_…}). Required. */
    public Builder secret(String value) {
      this.secret = value;
      return this;
    }

    /** Maximum difference between the signature time and now (default 300 s). */
    public Builder tolerance(Duration value) {
      this.tolerance = value;
      return this;
    }

    /** Clock used for the time window (tests). */
    public Builder clock(Clock value) {
      this.clock = value;
      return this;
    }

    public WebhookVerifier build() {
      return new WebhookVerifier(secret, tolerance, clock);
    }
  }
}
