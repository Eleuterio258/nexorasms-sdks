package tech.e258tech.nexorasms.internal;

import com.fasterxml.jackson.annotation.JsonInclude;
import com.fasterxml.jackson.databind.DeserializationFeature;
import com.fasterxml.jackson.databind.ObjectMapper;
import com.fasterxml.jackson.databind.PropertyNamingStrategies;
import com.fasterxml.jackson.databind.SerializationFeature;
import com.fasterxml.jackson.databind.json.JsonMapper;
import com.fasterxml.jackson.datatype.jsr310.JavaTimeModule;

/**
 * Internal: the shared Jackson configuration of the SDK. Not part of the public API; may change without notice.
 *
 * <p>snake_case names, unknown properties ignored, unknown enum values mapped to {@code UNRECOGNIZED}, null
 * properties omitted (but null values inside maps, such as contact attributes, are written), ISO-8601 dates with
 * their offset preserved.
 */
public final class Json {
  private Json() {}

  /** Thread-safe once configured; never reconfigure it. */
  public static final ObjectMapper MAPPER = JsonMapper.builder()
      .propertyNamingStrategy(PropertyNamingStrategies.SNAKE_CASE)
      .addModule(new JavaTimeModule())
      .disable(DeserializationFeature.FAIL_ON_UNKNOWN_PROPERTIES)
      .enable(DeserializationFeature.READ_UNKNOWN_ENUM_VALUES_USING_DEFAULT_VALUE)
      .enable(DeserializationFeature.USE_BIG_DECIMAL_FOR_FLOATS)
      .disable(DeserializationFeature.ADJUST_DATES_TO_CONTEXT_TIME_ZONE)
      .disable(SerializationFeature.WRITE_DATES_AS_TIMESTAMPS)
      .defaultPropertyInclusion(JsonInclude.Value.construct(JsonInclude.Include.NON_NULL, JsonInclude.Include.ALWAYS))
      .build();
}
