package tech.e258tech.nexorasms;

import com.fasterxml.jackson.databind.JsonNode;
import java.io.IOException;
import java.time.Duration;
import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import tech.e258tech.nexorasms.error.AuthenticationException;
import tech.e258tech.nexorasms.error.ConflictException;
import tech.e258tech.nexorasms.error.InsufficientFundsException;
import tech.e258tech.nexorasms.error.NexoraSmsException;
import tech.e258tech.nexorasms.error.NotFoundException;
import tech.e258tech.nexorasms.error.PermissionDeniedException;
import tech.e258tech.nexorasms.error.RateLimitException;
import tech.e258tech.nexorasms.error.ServerException;
import tech.e258tech.nexorasms.error.ValidationException;
import tech.e258tech.nexorasms.internal.Json;

/** Maps an HTTP failure (status, ProblemDetails body, Retry-After) to the matching exception. */
final class Errors {
  private Errors() {}

  static NexoraSmsException forResponse(int status, String body, Duration retryAfter) {
    JsonNode problem = parse(body);
    String title = text(problem, "title");
    String detail = text(problem, "detail");
    String traceId = text(problem, "traceId");
    if (traceId == null) traceId = text(problem, "trace_id");
    Map<String, List<String>> errors = errors(problem.get("errors"));
    String message = title != null ? title : detail != null ? detail : "HTTP " + status;

    return switch (status) {
      case 400 -> new ValidationException(message, status, title, detail, traceId, errors, retryAfter, null);
      case 401 -> new AuthenticationException(message, status, title, detail, traceId, errors, retryAfter, null);
      case 402 -> new InsufficientFundsException(message, status, title, detail, traceId, errors, retryAfter, null);
      case 403 -> new PermissionDeniedException(message, status, title, detail, traceId, errors, retryAfter, null);
      case 404 -> new NotFoundException(message, status, title, detail, traceId, errors, retryAfter, null);
      case 409 -> new ConflictException(message, status, title, detail, traceId, errors, retryAfter, null);
      case 429 -> new RateLimitException(message, status, title, detail, traceId, errors, retryAfter, null);
      default -> status >= 500
          ? new ServerException(message, status, title, detail, traceId, errors, retryAfter, null)
          : new NexoraSmsException(message, status, title, detail, traceId, errors, retryAfter, null);
    };
  }

  private static JsonNode parse(String body) {
    if (body == null || body.isBlank()) return Json.MAPPER.createObjectNode();
    try {
      JsonNode node = Json.MAPPER.readTree(body);
      return node != null && node.isObject() ? node : Json.MAPPER.createObjectNode();
    } catch (IOException e) {
      return Json.MAPPER.createObjectNode();
    }
  }

  private static String text(JsonNode node, String field) {
    JsonNode value = node.get(field);
    if (value == null || value.isNull()) return null;
    String text = value.asText();
    return text.isEmpty() ? null : text;
  }

  private static Map<String, List<String>> errors(JsonNode node) {
    Map<String, List<String>> result = new LinkedHashMap<>();
    if (node == null || !node.isObject()) return result;
    node.fields().forEachRemaining(entry -> {
      List<String> messages = new ArrayList<>();
      JsonNode value = entry.getValue();
      if (value.isArray()) value.forEach(item -> messages.add(item.asText()));
      else if (!value.isNull()) messages.add(value.asText());
      result.put(entry.getKey(), messages);
    });
    return result;
  }
}
