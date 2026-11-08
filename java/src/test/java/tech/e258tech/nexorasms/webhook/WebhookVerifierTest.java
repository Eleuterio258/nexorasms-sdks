package tech.e258tech.nexorasms.webhook;

import static org.junit.jupiter.api.Assertions.assertDoesNotThrow;
import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertThrows;
import static org.junit.jupiter.api.Assertions.assertTrue;

import java.math.BigDecimal;
import java.nio.charset.StandardCharsets;
import java.time.Clock;
import java.time.Duration;
import java.time.Instant;
import java.time.OffsetDateTime;
import java.time.ZoneOffset;
import java.util.HexFormat;
import javax.crypto.Mac;
import javax.crypto.spec.SecretKeySpec;
import org.junit.jupiter.api.Test;
import tech.e258tech.nexorasms.error.WebhookVerificationException;
import tech.e258tech.nexorasms.model.Channel;
import tech.e258tech.nexorasms.model.MessageStatus;

class WebhookVerifierTest {
  static final String SECRET = "whsec_test";
  static final String BODY = "{\"id\":\"evt_1\",\"type\":\"ping\",\"created_at\":\"2026-11-08T10:20:00Z\",\"data\":{\"message\":\"ping\"}}";
  static final long T = 1762597200L;
  static final String V1 = "cde593b98434dd7cd2944443131e9bf1bf47bc37630a621aa9481d76ab38b77e";
  static final String HEADER = "t=" + T + ",v1=" + V1;

  static WebhookVerifier verifier(long nowSeconds) {
    return new WebhookVerifier(SECRET, Duration.ofSeconds(300), Clock.fixed(Instant.ofEpochSecond(nowSeconds), ZoneOffset.UTC));
  }

  static String sign(String body) throws Exception {
    Mac mac = Mac.getInstance("HmacSHA256");
    mac.init(new SecretKeySpec(SECRET.getBytes(StandardCharsets.UTF_8), "HmacSHA256"));
    return HexFormat.of().formatHex(mac.doFinal((T + "." + body).getBytes(StandardCharsets.UTF_8)));
  }

  @Test
  void fixedVectorIsValid() throws Exception {
    assertEquals(V1, sign(BODY));
    WebhookEvent event = verifier(T).verify(BODY.getBytes(StandardCharsets.UTF_8), HEADER);
    assertEquals("evt_1", event.id());
    assertEquals(WebhookEventType.PING, event.type());
    assertEquals(OffsetDateTime.parse("2026-11-08T10:20:00Z"), event.createdAt());
    assertEquals("ping", event.data().get("message").asText());
    assertFalse(event.isMessageEvent());
    assertThrows(IllegalStateException.class, event::asMessageEvent);
    assertEquals(event, verifier(T).verify(BODY, " t=" + T + " , v1=" + V1 + " "));
  }

  @Test
  void wrongSignatureExpiredAndMalformedHeader() {
    assertThrows(WebhookVerificationException.class, () -> verifier(T).verify(BODY, "t=" + T + ",v1=" + "0".repeat(64)));
    assertMessage("inválida", () -> verifier(T).verify(BODY + " ", HEADER));
    assertMessage("inválida", () -> verifier(T).verify(BODY, "t=" + (T + 1) + ",v1=" + V1));
    assertMessage("inválida", () -> verifier(T).verify(BODY, "t=" + T + ",v1=" + V1.toUpperCase()));
    assertMessage("janela", () -> verifier(T + 301).verify(BODY, HEADER));
    assertMessage("janela", () -> verifier(T - 301).verify(BODY, HEADER));
    assertDoesNotThrow(() -> verifier(T - 300).verify(BODY, HEADER));
    assertDoesNotThrow(() -> verifier(T + 300).verify(BODY, HEADER));
    assertMessage("mal formado", () -> verifier(T).verify(BODY, "v1=" + V1));
    assertMessage("mal formado", () -> verifier(T).verify(BODY, "t=" + T));
    assertMessage("mal formado", () -> verifier(T).verify(BODY, (String) null));
    assertMessage("mal formado", () -> verifier(T).verify(BODY, "t=abc,v1=" + V1));
    assertMessage("mal formado", () -> verifier(T).verify(BODY, "garbage"));
  }

  @Test
  void messageEvent() throws Exception {
    String body = "{\"id\":\"e2\",\"type\":\"message.delivered\",\"created_at\":\"2026-11-08T10:00:00Z\",\"data\":{"
        + "\"message_id\":\"m1\",\"campaign_id\":\"c1\",\"contact_id\":\"k1\",\"external_id\":\"CLI-1\",\"channel\":\"sms\","
        + "\"status\":\"delivered\",\"cost\":1.5,\"occurred_at\":\"2026-11-08T10:00:00Z\",\"extra\":1}}";
    WebhookEvent event = verifier(T).verify(body, "t=" + T + ",v1=" + sign(body));
    assertTrue(event.isMessageEvent());
    MessageEvent data = event.asMessageEvent();
    assertEquals("m1", data.messageId());
    assertEquals("CLI-1", data.externalId());
    assertEquals(Channel.SMS, data.channel());
    assertEquals(MessageStatus.DELIVERED, data.status());
    assertEquals(new BigDecimal("1.5"), data.cost());
    assertEquals(OffsetDateTime.parse("2026-11-08T10:00:00Z"), data.occurredAt());
  }

  @Test
  void invalidJsonAndConstruction() throws Exception {
    String body = "not json";
    assertMessage("JSON", () -> verifier(T).verify(body, "t=" + T + ",v1=" + sign(body)));
    assertThrows(IllegalArgumentException.class, () -> new WebhookVerifier(""));
    assertThrows(IllegalArgumentException.class, () -> new WebhookVerifier(SECRET, Duration.ofSeconds(-1)));
    WebhookVerifier built = WebhookVerifier.builder().secret(SECRET).tolerance(Duration.ofSeconds(10))
        .clock(Clock.fixed(Instant.ofEpochSecond(T + 11), ZoneOffset.UTC)).build();
    assertMessage("janela", () -> built.verify(BODY, HEADER));
  }

  private static void assertMessage(String fragment, org.junit.jupiter.api.function.Executable call) {
    WebhookVerificationException e = assertThrows(WebhookVerificationException.class, call);
    assertTrue(e.getMessage().contains(fragment), e.getMessage());
  }
}
