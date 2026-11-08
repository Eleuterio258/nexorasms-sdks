package tech.e258tech.nexorasms;

import tech.e258tech.nexorasms.model.Channel;
import tech.e258tech.nexorasms.model.ConsentStatus;
import tech.e258tech.nexorasms.model.Contact;
import tech.e258tech.nexorasms.model.Page;

/** Contacts resource: {@code client.contacts()}. */
public final class ContactsApi {
  private static final String BASE = "/api/v1/contacts";

  private final NexoraSmsClient client;

  ContactsApi(NexoraSmsClient client) {
    this.client = client;
  }

  /** Creates (201) or updates (200) the contact with this id from your system. Retried on transient failures. */
  public Contact upsertByExternalId(String externalId, ContactRequest request) {
    return client.<Contact>call("PUT", BASE + "/external/" + NexoraSmsClient.encode(Checks.id(externalId)), null,
        body(request), null, true, client.type(Contact.class)).data();
  }

  /** Creates a contact. Never retried (not idempotent). */
  public Contact create(ContactRequest request) {
    return client.<Contact>call("POST", BASE, null, body(request), null, false, client.type(Contact.class)).data();
  }

  public Contact get(String id) {
    return client.<Contact>call("GET", BASE + "/" + NexoraSmsClient.encode(Checks.id(id)), null, null, null, true,
        client.type(Contact.class)).data();
  }

  /** Updates only the fields set in the request. Never retried. */
  public Contact update(String id, ContactRequest request) {
    return client.<Contact>call("PATCH", BASE + "/" + NexoraSmsClient.encode(Checks.id(id)), null, body(request), null,
        false, client.type(Contact.class)).data();
  }

  /** First page of contacts. */
  public Page<Contact> list() {
    return list(null);
  }

  /** Contacts matching the query ({@code null} for no filters). */
  public Page<Contact> list(ContactQuery query) {
    return client.<Page<Contact>>call("GET", BASE, query == null ? null : query.params(), null, null, true,
        client.type(Page.class, Contact.class)).data();
  }

  /** Records a consent (granted/revoked) for a channel. Never retried. */
  public Contact recordConsent(String id, Channel channel, ConsentStatus status, String source) {
    ConsentBody body = new ConsentBody(Checks.known(channel, "channel"), Checks.known(status, "status"), source);
    return client.<Contact>call("POST", BASE + "/" + NexoraSmsClient.encode(Checks.id(id)) + "/consents", null, body,
        null, false, client.type(Contact.class)).data();
  }

  record ConsentBody(Channel channel, ConsentStatus status, String source) {}

  private static ContactRequest.Body body(ContactRequest request) {
    if (request == null) throw new IllegalArgumentException("Use ContactRequest.builder() para construir o contacto.");
    return request.body();
  }
}
