package tech.e258tech.nexorasms.model;

import java.math.BigDecimal;
import java.util.List;

/**
 * Result of {@code messages().estimate(...)}: cost per channel, total and available balance. Nothing is sent.
 *
 * @param channels estimate per channel
 * @param total total cost
 * @param available available wallet balance
 * @param invalid recipients the API could not use
 */
public record EstimateResult(List<ChannelEstimate> channels, BigDecimal total, BigDecimal available, List<String> invalid) {
  public EstimateResult {
    channels = Copies.list(channels);
    invalid = Copies.list(invalid);
  }

  /**
   * Estimate for one channel.
   *
   * @param channel the channel
   * @param recipients number of valid recipients
   * @param segments SMS segments per message
   * @param encoding SMS encoding
   * @param unitPrice price per segment/message
   * @param cost cost for the channel
   */
  public record ChannelEstimate(Channel channel, int recipients, int segments, Encoding encoding, BigDecimal unitPrice, BigDecimal cost) {}
}
