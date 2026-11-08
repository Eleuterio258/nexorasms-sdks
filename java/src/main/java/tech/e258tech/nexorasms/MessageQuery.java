package tech.e258tech.nexorasms;

import java.util.Collections;
import java.util.LinkedHashMap;
import java.util.Map;
import tech.e258tech.nexorasms.model.Channel;
import tech.e258tech.nexorasms.model.MessageStatus;

/** Filters for {@code messages().list(...)}. Immutable; create it with {@link #builder()}. */
public final class MessageQuery {
  private final Map<String, String> params;

  private MessageQuery(Map<String, String> params) {
    this.params = Collections.unmodifiableMap(new LinkedHashMap<>(params));
  }

  public static Builder builder() {
    return new Builder();
  }

  Map<String, String> params() {
    return params;
  }

  /** Builder of {@link MessageQuery}. Not thread-safe. */
  public static final class Builder {
    private final Map<String, String> params = new LinkedHashMap<>();

    private Builder() {}

    public Builder campaignId(String value) {
      return set("campaign_id", value);
    }

    public Builder status(MessageStatus value) {
      return set("status", value == null ? null : Checks.known(value, "status").value());
    }

    public Builder channel(Channel value) {
      return set("channel", value == null ? null : Checks.known(value, "channel").value());
    }

    /** Free-text search (destination, content…). */
    public Builder search(String value) {
      return set("search", value);
    }

    /** Page number, from 1. */
    public Builder page(int value) {
      return set("page", Integer.toString(Checks.page(value)));
    }

    /** Page size, 1 to 200. */
    public Builder pageSize(int value) {
      return set("page_size", Integer.toString(Checks.pageSize(value)));
    }

    public MessageQuery build() {
      return new MessageQuery(params);
    }

    private Builder set(String key, String value) {
      if (value == null) params.remove(key);
      else params.put(key, value);
      return this;
    }
  }
}
