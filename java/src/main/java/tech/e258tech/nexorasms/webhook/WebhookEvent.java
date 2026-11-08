package tech.e258tech.nexorasms.webhook;

import com.fasterxml.jackson.core.JsonProcessingException;
import com.fasterxml.jackson.databind.JsonNode;
import java.time.OffsetDateTime;
import tech.e258tech.nexorasms.error.WebhookVerificationException;
import tech.e258tech.nexorasms.internal.Json;

/**
 * A verified webhook event.
 *
 * @param id event id (use it to ignore duplicates)
 * @param type event type, see {@link WebhookEventType}
 * @param createdAt when the event was created
 * @param data the event payload as raw JSON (snake_case names)
 */
public record WebhookEvent(String id, String type, OffsetDateTime createdAt, JsonNode data) {

  /** {@code true} for {@code message.delivered} and {@code message.failed}. */
  public boolean isMessageEvent() {
    return WebhookEventType.MESSAGE_DELIVERED.equals(type) || WebhookEventType.MESSAGE_FAILED.equals(type);
  }

  /**
   * Reads {@code data} as a {@link MessageEvent}.
   *
   * @throws IllegalStateException when this is not a message event
   */
  public MessageEvent asMessageEvent() {
    if (!isMessageEvent()) throw new IllegalStateException("O evento " + type + " não é um evento de mensagem.");
    return dataAs(MessageEvent.class);
  }

  /** Reads {@code data} as any type (snake_case JSON names, unknown fields ignored). */
  public <T> T dataAs(Class<T> type) {
    if (data == null || data.isNull()) return null;
    try {
      return Json.MAPPER.treeToValue(data, type);
    } catch (JsonProcessingException e) {
      throw new WebhookVerificationException("Dados do webhook inválidos para " + type.getSimpleName() + ".", e);
    }
  }
}
