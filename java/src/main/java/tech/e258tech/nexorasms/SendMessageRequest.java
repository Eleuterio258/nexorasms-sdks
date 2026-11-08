package tech.e258tech.nexorasms;

import java.util.ArrayList;
import java.util.Arrays;
import java.util.Collection;
import java.util.HashSet;
import java.util.List;
import java.util.Optional;
import java.util.Set;
import tech.e258tech.nexorasms.model.Channel;

/**
 * An immediate send to one or more channels (body of {@code POST /api/v1/messages} and {@code /estimate}).
 * Immutable; create it with {@link #builder()}.
 */
public final class SendMessageRequest {
  static final int MAX_LIST = 1000;

  private final Body body;
  private final String idempotencyKey;

  private SendMessageRequest(Body body, String idempotencyKey) {
    this.body = body;
    this.idempotencyKey = idempotencyKey;
  }

  /** Starts a new request. */
  public static Builder builder() {
    return new Builder();
  }

  /** The Idempotency-Key chosen by the caller; when empty, {@code send} generates a UUID per call. */
  public Optional<String> idempotencyKey() {
    return Optional.ofNullable(idempotencyKey);
  }

  Body body() {
    return body;
  }

  // JSON shapes (snake_case through the shared mapper; null fields are omitted).
  record Body(List<ChannelBody> channels, List<String> recipients, List<String> contactIds, Boolean ignoreQuietHours) {}

  record ChannelBody(Channel channel, String subject, String content, String sender, Template whatsappTemplate) {}

  record Template(String name, String language, List<String> parameters) {}

  /** Builder of {@link SendMessageRequest}; validates in {@link #build()}. Not thread-safe. */
  public static final class Builder {
    private final List<ChannelBody> channels = new ArrayList<>();
    private final List<String> recipients = new ArrayList<>();
    private final List<String> contactIds = new ArrayList<>();
    private Boolean ignoreQuietHours;
    private String idempotencyKey;

    private Builder() {}

    /** SMS using the account's default Sender ID. */
    public Builder sms(String content) {
      return sms(content, null);
    }

    /** SMS with an approved Sender ID ({@code null} for the default). */
    public Builder sms(String content, String sender) {
      channels.add(new ChannelBody(Channel.SMS, null, content, sender, null));
      return this;
    }

    /** Email (HTML content) from the account's default sender. */
    public Builder email(String subject, String content) {
      return email(subject, content, null);
    }

    /** Email (HTML content) with a sender at a verified domain ({@code null} for the default). */
    public Builder email(String subject, String content, String sender) {
      channels.add(new ChannelBody(Channel.EMAIL, subject, content, sender, null));
      return this;
    }

    /** WhatsApp free text (accepted only within 24 h of the customer's last message). */
    public Builder whatsapp(String content) {
      channels.add(new ChannelBody(Channel.WHATSAPP, null, content, null, null));
      return this;
    }

    /** WhatsApp with a template approved by Meta; the parameters fill {{1}}, {{2}}… in order. */
    public Builder whatsappTemplate(String name, String language, String... parameters) {
      return whatsappTemplate(name, language, Arrays.asList(parameters));
    }

    /** WhatsApp with a template approved by Meta; the parameters fill {{1}}, {{2}}… in order. */
    public Builder whatsappTemplate(String name, String language, List<String> parameters) {
      List<String> copy = List.copyOf(nonNullItems(parameters, "parameters"));
      channels.add(new ChannelBody(Channel.WHATSAPP, null, null, null, new Template(name, language, copy)));
      return this;
    }

    /** Adds an E.164 phone number or an email address. */
    public Builder to(String recipient) {
      return to(List.of(nonNull(recipient, "recipient")));
    }

    /** Adds E.164 phone numbers and/or email addresses (accumulates). */
    public Builder to(String... recipients) {
      return to(Arrays.asList(recipients));
    }

    /** Adds E.164 phone numbers and/or email addresses (accumulates). */
    public Builder to(Collection<String> recipients) {
      this.recipients.addAll(nonNullItems(recipients, "recipients"));
      return this;
    }

    /** Adds an existing contact id. */
    public Builder contact(String id) {
      return contacts(List.of(nonNull(id, "contact")));
    }

    /** Adds existing contact ids (accumulates). */
    public Builder contacts(String... ids) {
      return contacts(Arrays.asList(ids));
    }

    /** Adds existing contact ids (accumulates). */
    public Builder contacts(Collection<String> ids) {
      this.contactIds.addAll(nonNullItems(ids, "contacts"));
      return this;
    }

    /** Sends even during the account's quiet hours. */
    public Builder ignoreQuietHours(boolean value) {
      this.ignoreQuietHours = value;
      return this;
    }

    /** Your own Idempotency-Key (1 to 200 visible ASCII characters), e.g. {@code invoice-123}. */
    public Builder idempotencyKey(String key) {
      this.idempotencyKey = Checks.idempotencyKey(key);
      return this;
    }

    /**
     * Validates and builds the request.
     *
     * @throws IllegalStateException when channels, recipients or contents are missing or invalid
     */
    public SendMessageRequest build() {
      if (channels.isEmpty() || channels.size() > 3) throw new IllegalStateException("Indique entre 1 e 3 canais.");
      Set<Channel> seen = new HashSet<>();
      for (ChannelBody c : channels) {
        if (!seen.add(c.channel())) throw new IllegalStateException("Cada canal só pode aparecer uma vez.");
        switch (c.channel()) {
          case SMS -> {
            if (!Checks.nonEmpty(c.content())) throw new IllegalStateException("O SMS precisa de conteúdo.");
          }
          case EMAIL -> {
            if (!Checks.nonEmpty(c.subject())) throw new IllegalStateException("O email precisa de assunto.");
            if (!Checks.nonEmpty(c.content())) throw new IllegalStateException("O email precisa de conteúdo.");
          }
          default -> {
            Template t = c.whatsappTemplate();
            if (!Checks.nonEmpty(c.content()) && t == null)
              throw new IllegalStateException("O WhatsApp precisa de conteúdo ou de um template.");
            if (t != null && (!Checks.nonEmpty(t.name()) || !Checks.nonEmpty(t.language())))
              throw new IllegalStateException("O template do WhatsApp precisa de nome e idioma.");
          }
        }
      }
      if (recipients.isEmpty() && contactIds.isEmpty())
        throw new IllegalStateException("Indique pelo menos um destinatário ou contacto.");
      if (recipients.size() > MAX_LIST) throw new IllegalStateException("No máximo " + MAX_LIST + " destinatários.");
      if (contactIds.size() > MAX_LIST) throw new IllegalStateException("No máximo " + MAX_LIST + " contactos.");

      Body body = new Body(
          List.copyOf(channels),
          recipients.isEmpty() ? null : List.copyOf(recipients),
          contactIds.isEmpty() ? null : List.copyOf(contactIds),
          ignoreQuietHours);
      return new SendMessageRequest(body, idempotencyKey);
    }

    private static String nonNull(String value, String name) {
      if (value == null) throw new IllegalArgumentException(name + " não pode ser nulo.");
      return value;
    }

    private static Collection<String> nonNullItems(Collection<String> values, String name) {
      if (values == null) throw new IllegalArgumentException(name + " não pode ser nulo.");
      for (String value : values) nonNull(value, name);
      return values;
    }
  }
}
