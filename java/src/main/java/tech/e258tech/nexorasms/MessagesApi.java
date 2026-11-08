package tech.e258tech.nexorasms;

import java.util.Map;
import java.util.UUID;
import tech.e258tech.nexorasms.model.EstimateResult;
import tech.e258tech.nexorasms.model.Message;
import tech.e258tech.nexorasms.model.Page;
import tech.e258tech.nexorasms.model.SendMessageResult;

/** Messages resource: {@code client.messages()}. */
public final class MessagesApi {
  private final NexoraSmsClient client;

  MessagesApi(NexoraSmsClient client) {
    this.client = client;
  }

  /**
   * Sends now (one campaign per channel). Uses the request's Idempotency-Key or a new UUID v4, generated once per
   * call and reused on every retry of that call.
   *
   * @throws tech.e258tech.nexorasms.error.NexoraSmsException on API or network failure
   */
  public SendMessageResult send(SendMessageRequest request) {
    if (request == null) throw new IllegalArgumentException("Use SendMessageRequest.builder() para construir o envio.");
    String key = request.idempotencyKey().orElseGet(() -> UUID.randomUUID().toString());
    NexoraSmsClient.Result<SendMessageResult> result = client.call("POST", "/api/v1/messages", null, request.body(),
        Map.of("Idempotency-Key", key), true, client.type(SendMessageResult.class));
    boolean replayed = result.headers().firstValue("Idempotent-Replayed").map("true"::equalsIgnoreCase).orElse(false);
    SendMessageResult data = result.data() != null ? result.data() : new SendMessageResult(null, null, null, false);
    return data.withIdempotency(key, replayed);
  }

  /** Cost, segments and available balance. Sends nothing. */
  public EstimateResult estimate(SendMessageRequest request) {
    if (request == null) throw new IllegalArgumentException("Use SendMessageRequest.builder() para construir o envio.");
    return client.<EstimateResult>call("POST", "/api/v1/messages/estimate", null, request.body(), null, true,
        client.type(EstimateResult.class)).data();
  }

  /** One message with its delivery attempts. */
  public Message get(String id) {
    return client.<Message>call("GET", "/api/v1/messages/" + NexoraSmsClient.encode(Checks.id(id)), null, null, null,
        true, client.type(Message.class)).data();
  }

  /** First page of messages, newest first. */
  public Page<Message> list() {
    return list(null);
  }

  /** Messages matching the query ({@code null} for no filters). */
  public Page<Message> list(MessageQuery query) {
    return client.<Page<Message>>call("GET", "/api/v1/messages", query == null ? null : query.params(), null, null,
        true, client.type(Page.class, Message.class)).data();
  }
}
