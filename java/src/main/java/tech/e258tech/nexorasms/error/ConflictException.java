package tech.e258tech.nexorasms.error;

import java.time.Duration;
import java.util.List;
import java.util.Map;

/** HTTP 409: conflict, e.g. an Idempotency-Key reused with a different body. */
public class ConflictException extends NexoraSmsException {
  private static final long serialVersionUID = 1L;

  /** See {@link NexoraSmsException#NexoraSmsException(String, int, String, String, String, Map, Duration, Throwable)}. */
  public ConflictException(String message, int status, String title, String detail, String traceId,
      Map<String, List<String>> errors, Duration retryAfter, Throwable cause) {
    super(message, status, title, detail, traceId, errors, retryAfter, cause);
  }
}
