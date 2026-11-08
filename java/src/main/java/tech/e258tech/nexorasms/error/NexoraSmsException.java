package tech.e258tech.nexorasms.error;

import java.time.Duration;
import java.util.ArrayList;
import java.util.Collections;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.Optional;

/**
 * Base exception for every failure talking to the NexoraSMS API. API failures carry the ProblemDetails fields
 * returned by the API; the message is the {@code title} (or the {@code detail}, or {@code HTTP <status>}).
 */
public class NexoraSmsException extends RuntimeException {
  private static final long serialVersionUID = 1L;

  private final int status;
  private final String title;
  private final String detail;
  private final String traceId;
  private final Map<String, List<String>> errors;
  private final Duration retryAfter;

  /**
   * @param message exception message
   * @param status HTTP status, or 0 when there was no HTTP response
   * @param title ProblemDetails {@code title}, may be {@code null}
   * @param detail ProblemDetails {@code detail}, may be {@code null}
   * @param traceId ProblemDetails {@code traceId}, may be {@code null}
   * @param errors validation errors per field, may be {@code null}
   * @param retryAfter value of the {@code Retry-After} header, may be {@code null}
   * @param cause underlying cause, may be {@code null}
   */
  public NexoraSmsException(String message, int status, String title, String detail, String traceId,
      Map<String, List<String>> errors, Duration retryAfter, Throwable cause) {
    super(message, cause);
    this.status = status;
    this.title = title;
    this.detail = detail;
    this.traceId = traceId;
    this.errors = copy(errors);
    this.retryAfter = retryAfter;
  }

  /** HTTP status of the response, or {@code 0} when there was none (network error or timeout). */
  public int status() {
    return status;
  }

  /** ProblemDetails {@code title}, or {@code null}. */
  public String title() {
    return title;
  }

  /** ProblemDetails {@code detail}, or {@code null}. */
  public String detail() {
    return detail;
  }

  /** ProblemDetails {@code traceId} (quote it when contacting support), or {@code null}. */
  public String traceId() {
    return traceId;
  }

  /** Validation errors per field (empty when none). */
  public Map<String, List<String>> errors() {
    return errors;
  }

  /** How long the API asked to wait ({@code Retry-After}), when it said so. */
  public Optional<Duration> retryAfter() {
    return Optional.ofNullable(retryAfter);
  }

  private static Map<String, List<String>> copy(Map<String, List<String>> source) {
    if (source == null || source.isEmpty()) return Map.of();
    Map<String, List<String>> result = new LinkedHashMap<>();
    source.forEach((key, value) ->
        result.put(key, value == null ? List.of() : Collections.unmodifiableList(new ArrayList<>(value))));
    return Collections.unmodifiableMap(result);
  }
}
