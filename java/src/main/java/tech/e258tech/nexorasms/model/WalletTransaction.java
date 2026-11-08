package tech.e258tech.nexorasms.model;

import java.math.BigDecimal;
import java.time.OffsetDateTime;

/**
 * A wallet movement.
 *
 * @param id transaction id
 * @param type type
 * @param amount amount
 * @param businessReference business reference
 * @param messageId related message, or {@code null}
 * @param reversalOfId transaction this one reverses, or {@code null}
 * @param createdAt creation time
 */
public record WalletTransaction(
    String id,
    TransactionType type,
    BigDecimal amount,
    String businessReference,
    String messageId,
    String reversalOfId,
    OffsetDateTime createdAt) {}
