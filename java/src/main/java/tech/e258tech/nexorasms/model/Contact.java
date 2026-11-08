package tech.e258tech.nexorasms.model;

import java.time.OffsetDateTime;
import java.util.List;
import java.util.Map;

/**
 * A contact. Optional fields are {@code null} when absent.
 *
 * @param id contact id
 * @param name name, or {@code null}
 * @param phone phone (E.164), or {@code null}
 * @param email email, or {@code null}
 * @param status status
 * @param groupIds groups the contact belongs to
 * @param tags tags
 * @param consents consent records
 * @param createdAt creation time
 * @param attributes custom fields (names kept exactly as defined)
 * @param externalId id in your system, or {@code null}
 */
public record Contact(
    String id,
    String name,
    String phone,
    String email,
    ContactStatus status,
    List<String> groupIds,
    List<String> tags,
    List<Consent> consents,
    OffsetDateTime createdAt,
    Map<String, String> attributes,
    String externalId) {

  public Contact {
    groupIds = Copies.list(groupIds);
    tags = Copies.list(tags);
    consents = Copies.list(consents);
    attributes = Copies.map(attributes);
  }

  /**
   * A consent record for one channel.
   *
   * @param channel channel
   * @param status granted or revoked
   * @param source where the consent came from
   * @param recordedAt when it was recorded
   */
  public record Consent(Channel channel, ConsentStatus status, String source, OffsetDateTime recordedAt) {}
}
