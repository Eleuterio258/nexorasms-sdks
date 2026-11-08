package tech.e258tech.nexorasms;

import java.util.Collections;
import java.util.LinkedHashMap;
import java.util.Map;
import tech.e258tech.nexorasms.model.ContactStatus;

/** Filters for {@code contacts().list(...)}. Immutable; create it with {@link #builder()}. */
public final class ContactQuery {
  private final Map<String, String> params;

  private ContactQuery(Map<String, String> params) {
    this.params = Collections.unmodifiableMap(new LinkedHashMap<>(params));
  }

  public static Builder builder() {
    return new Builder();
  }

  Map<String, String> params() {
    return params;
  }

  /** Builder of {@link ContactQuery}. Not thread-safe. */
  public static final class Builder {
    private final Map<String, String> params = new LinkedHashMap<>();

    private Builder() {}

    /** Free-text search (name, phone, email…). */
    public Builder search(String value) {
      return set("search", value);
    }

    public Builder groupId(String value) {
      return set("group_id", value);
    }

    public Builder tag(String value) {
      return set("tag", value);
    }

    public Builder status(ContactStatus value) {
      return set("status", value == null ? null : Checks.known(value, "status").value());
    }

    /** Page number, from 1. */
    public Builder page(int value) {
      return set("page", Integer.toString(Checks.page(value)));
    }

    /** Page size, 1 to 200. */
    public Builder pageSize(int value) {
      return set("page_size", Integer.toString(Checks.pageSize(value)));
    }

    public ContactQuery build() {
      return new ContactQuery(params);
    }

    private Builder set(String key, String value) {
      if (value == null) params.remove(key);
      else params.put(key, value);
      return this;
    }
  }
}
