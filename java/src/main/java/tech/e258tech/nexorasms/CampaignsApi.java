package tech.e258tech.nexorasms;

import tech.e258tech.nexorasms.model.Campaign;
import tech.e258tech.nexorasms.model.CampaignStats;

/** Campaigns resource: {@code client.campaigns()}. */
public final class CampaignsApi {
  private final NexoraSmsClient client;

  CampaignsApi(NexoraSmsClient client) {
    this.client = client;
  }

  public Campaign get(String id) {
    return client.<Campaign>call("GET", "/api/v1/campaigns/" + NexoraSmsClient.encode(Checks.id(id)), null, null, null,
        true, client.type(Campaign.class)).data();
  }

  /** Counters per status, cost and clicks. */
  public CampaignStats stats(String id) {
    return client.<CampaignStats>call("GET", "/api/v1/campaigns/" + NexoraSmsClient.encode(Checks.id(id)) + "/stats",
        null, null, null, true, client.type(CampaignStats.class)).data();
  }
}
