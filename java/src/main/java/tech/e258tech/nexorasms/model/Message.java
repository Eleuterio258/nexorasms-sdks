package tech.e258tech.nexorasms.model;

import java.math.BigDecimal;
import java.time.OffsetDateTime;
import java.util.List;

/**
 * A single message to one destination. Optional fields are {@code null} when absent.
 *
 * @param id message id
 * @param campaignId campaign id
 * @param channel channel
 * @param destination phone number or email address
 * @param status status
 * @param cost cost
 * @param parts number of parts (SMS segments)
 * @param createdAt creation time
 * @param acceptedAt time the provider accepted it, or {@code null}
 * @param deliveredAt delivery time, or {@code null}
 * @param attempts delivery attempts
 * @param campaignName campaign name, or {@code null}
 * @param sender sender, or {@code null}
 * @param subject email subject, or {@code null}
 * @param content content, or {@code null}
 * @param template template name, or {@code null}
 * @param language template language, or {@code null}
 */
public record Message(
    String id,
    String campaignId,
    Channel channel,
    String destination,
    MessageStatus status,
    BigDecimal cost,
    int parts,
    OffsetDateTime createdAt,
    OffsetDateTime acceptedAt,
    OffsetDateTime deliveredAt,
    List<Attempt> attempts,
    String campaignName,
    String sender,
    String subject,
    String content,
    String template,
    String language) {

  public Message {
    attempts = Copies.list(attempts);
  }

  /**
   * One attempt to hand a message part to a provider.
   *
   * @param partNumber part number
   * @param attemptNumber attempt number
   * @param status attempt status
   * @param providerMessageId provider id, or {@code null}
   * @param errorCode provider error code, or {@code null}
   * @param startedAt start time
   * @param completedAt completion time, or {@code null}
   */
  public record Attempt(
      int partNumber,
      int attemptNumber,
      AttemptStatus status,
      String providerMessageId,
      String errorCode,
      OffsetDateTime startedAt,
      OffsetDateTime completedAt) {}
}
