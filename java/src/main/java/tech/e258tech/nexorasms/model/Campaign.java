package tech.e258tech.nexorasms.model;

import java.time.OffsetDateTime;

/**
 * A campaign. Optional fields are {@code null} when absent.
 *
 * @param id campaign id
 * @param name name
 * @param channel channel
 * @param status status
 * @param templateId template id, or {@code null}
 * @param subject email subject, or {@code null}
 * @param content content
 * @param recipients number of recipients
 * @param scheduledAt scheduled time, or {@code null}
 * @param createdAt creation time
 * @param ignoreQuietHours whether quiet hours are ignored
 * @param pauseReason reason for the pause, or {@code null}
 * @param sender sender, or {@code null}
 * @param messages number of messages, or {@code null}
 * @param delivered number delivered, or {@code null}
 * @param failed number failed, or {@code null}
 */
public record Campaign(
    String id,
    String name,
    Channel channel,
    CampaignStatus status,
    String templateId,
    String subject,
    String content,
    int recipients,
    OffsetDateTime scheduledAt,
    OffsetDateTime createdAt,
    boolean ignoreQuietHours,
    String pauseReason,
    String sender,
    Integer messages,
    Integer delivered,
    Integer failed) {}
