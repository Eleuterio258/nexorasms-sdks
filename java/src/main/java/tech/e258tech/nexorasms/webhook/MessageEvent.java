package tech.e258tech.nexorasms.webhook;

import java.math.BigDecimal;
import java.time.OffsetDateTime;
import tech.e258tech.nexorasms.model.Channel;
import tech.e258tech.nexorasms.model.MessageStatus;

/**
 * {@code data} of {@code message.delivered} and {@code message.failed} events.
 *
 * @param messageId message id
 * @param campaignId campaign id
 * @param contactId contact id
 * @param externalId contact id in your system, or {@code null}
 * @param channel channel
 * @param status final status
 * @param cost cost
 * @param occurredAt when it happened (events may arrive out of order)
 */
public record MessageEvent(
    String messageId,
    String campaignId,
    String contactId,
    String externalId,
    Channel channel,
    MessageStatus status,
    BigDecimal cost,
    OffsetDateTime occurredAt) {}
