package tech.e258tech.nexorasms.model;

import java.math.BigDecimal;
import java.util.Map;

/**
 * Campaign statistics.
 *
 * @param campaignId campaign id
 * @param status status
 * @param recipients number of recipients
 * @param messages number of messages per status (keys are API status values, e.g. {@code delivered})
 * @param smsSegments SMS segments
 * @param currency currency
 * @param estimatedCost estimated cost
 * @param reserved amount reserved
 * @param debited amount debited
 * @param clicks link clicks
 * @param uniqueClicks unique link clicks
 */
public record CampaignStats(
    String campaignId,
    CampaignStatus status,
    int recipients,
    Map<String, Integer> messages,
    long smsSegments,
    String currency,
    BigDecimal estimatedCost,
    BigDecimal reserved,
    BigDecimal debited,
    long clicks,
    long uniqueClicks) {

  public CampaignStats {
    messages = Copies.map(messages);
  }
}
