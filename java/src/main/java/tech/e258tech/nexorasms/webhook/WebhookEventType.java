package tech.e258tech.nexorasms.webhook;

/** Values of {@link WebhookEvent#type()}. */
public final class WebhookEventType {
  public static final String MESSAGE_DELIVERED = "message.delivered";
  public static final String MESSAGE_FAILED = "message.failed";
  public static final String CAMPAIGN_COMPLETED = "campaign.completed";
  public static final String CAMPAIGN_PAUSED = "campaign.paused";
  public static final String CONTACT_OPTED_OUT = "contact.opted_out";
  public static final String PING = "ping";

  private WebhookEventType() {}
}
