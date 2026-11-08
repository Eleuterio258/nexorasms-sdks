package tech.e258tech.nexorasms;

import java.util.ArrayList;
import java.util.Arrays;
import java.util.Collection;
import java.util.Collections;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import tech.e258tech.nexorasms.model.ContactStatus;

/**
 * Body to create, upsert or update (PATCH) a contact. Only the fields you set are sent: on update, a field that is
 * not sent is left untouched. Immutable; create it with {@link #builder()}.
 */
public final class ContactRequest {
  private final Body body;

  private ContactRequest(Body body) {
    this.body = body;
  }

  /** Starts a new request. */
  public static Builder builder() {
    return new Builder();
  }

  Body body() {
    return body;
  }

  // attributes keeps null values (null = remove the custom field on update).
  record Body(String name, String phone, String email, ContactStatus status, List<String> groupIds, List<String> tags,
      Map<String, String> attributes, String externalId) {}

  /** Builder of {@link ContactRequest}. Not thread-safe. */
  public static final class Builder {
    private String name;
    private String phone;
    private String email;
    private ContactStatus status;
    private String externalId;
    private List<String> tags;
    private List<String> groupIds;
    private Map<String, String> attributes;

    private Builder() {}

    public Builder name(String value) {
      this.name = value;
      return this;
    }

    /** Phone number (E.164 or a local number the API can normalise). */
    public Builder phone(String value) {
      this.phone = value;
      return this;
    }

    public Builder email(String value) {
      this.email = value;
      return this;
    }

    /** {@code ACTIVE} or {@code INACTIVE} (only meaningful on update). */
    public Builder status(ContactStatus value) {
      this.status = Checks.known(value, "status");
      return this;
    }

    /** The contact's id in your system. */
    public Builder externalId(String value) {
      this.externalId = value;
      return this;
    }

    /** Adds one tag. */
    public Builder tag(String value) {
      return tags(Collections.singletonList(value));
    }

    /** Adds tags; when sent they replace the contact's tags. {@code tags()} with no arguments clears them. */
    public Builder tags(String... values) {
      return tags(Arrays.asList(values));
    }

    /** Adds tags; when sent they replace the contact's tags. An empty collection clears them. */
    public Builder tags(Collection<String> values) {
      if (tags == null) tags = new ArrayList<>();
      tags.addAll(noNulls(values, "tags"));
      return this;
    }

    /** Adds one group. */
    public Builder groupId(String id) {
      return groupIds(Collections.singletonList(id));
    }

    /** Adds groups; when sent they replace the contact's groups. {@code groupIds()} with no arguments clears them. */
    public Builder groupIds(String... ids) {
      return groupIds(Arrays.asList(ids));
    }

    /** Adds groups; when sent they replace the contact's groups. An empty collection clears them. */
    public Builder groupIds(Collection<String> ids) {
      if (groupIds == null) groupIds = new ArrayList<>();
      groupIds.addAll(noNulls(ids, "groupIds"));
      return this;
    }

    /** Sets a custom field; a {@code null} value removes it on update. */
    public Builder attribute(String key, String value) {
      if (key == null || key.isBlank()) throw new IllegalArgumentException("O nome do atributo não pode ser vazio.");
      if (attributes == null) attributes = new LinkedHashMap<>();
      attributes.put(key, value);
      return this;
    }

    /** Sets several custom fields; {@code null} values remove them on update. */
    public Builder attributes(Map<String, String> values) {
      if (values == null) throw new IllegalArgumentException("attributes não pode ser nulo.");
      values.forEach(this::attribute);
      if (attributes == null) attributes = new LinkedHashMap<>();
      return this;
    }

    /** Builds the request with only the fields that were set. */
    public ContactRequest build() {
      return new ContactRequest(new Body(name, phone, email, status,
          groupIds == null ? null : List.copyOf(groupIds),
          tags == null ? null : List.copyOf(tags),
          attributes == null ? null : Collections.unmodifiableMap(new LinkedHashMap<>(attributes)),
          externalId));
    }

    private static Collection<String> noNulls(Collection<String> values, String name) {
      if (values == null) throw new IllegalArgumentException(name + " não pode ser nulo.");
      for (String value : values)
        if (value == null) throw new IllegalArgumentException(name + " não pode conter nulos.");
      return values;
    }
  }
}
