package tech.e258tech.nexorasms.model;

import java.math.BigDecimal;
import java.util.List;

/**
 * Result of {@code messages().send(...)}: one campaign per channel plus the recipients the API rejected.
 *
 * @param channels one entry per channel sent
 * @param invalid recipients the API could not use (bad phone or email)
 * @param idempotencyKey the Idempotency-Key used for this call (set by the client, not by the JSON)
 * @param replayed {@code true} when the API answered with {@code Idempotent-Replayed: true}
 */
public record SendMessageResult(List<ChannelResult> channels, List<String> invalid, String idempotencyKey, boolean replayed) {
  public SendMessageResult {
    channels = Copies.list(channels);
    invalid = Copies.list(invalid);
  }

  /** Returns a copy carrying the client-side idempotency information. */
  public SendMessageResult withIdempotency(String key, boolean wasReplayed) {
    return new SendMessageResult(channels, invalid, key, wasReplayed);
  }

  /**
   * The campaign created for one channel.
   *
   * @param channel the channel
   * @param campaignId the campaign created for it
   * @param recipients number of valid recipients
   * @param cost amount reserved
   * @param status campaign status
   */
  public record ChannelResult(Channel channel, String campaignId, int recipients, BigDecimal cost, CampaignStatus status) {}
}
