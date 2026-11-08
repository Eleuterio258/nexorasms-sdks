package tech.e258tech.nexorasms.model;

import com.fasterxml.jackson.annotation.JsonEnumDefaultValue;
import com.fasterxml.jackson.annotation.JsonValue;

/** Status of a delivery attempt to a provider. Unknown values sent by newer API versions map to {@link #UNRECOGNIZED}. */
public enum AttemptStatus {
  IN_FLIGHT("in_flight"),
  ACCEPTED("accepted"),
  REJECTED("rejected"),
  FAILED("failed"),
  UNKNOWN("unknown"),
  /** A value this SDK version does not know yet; never send it to the API. */
  @JsonEnumDefaultValue
  UNRECOGNIZED("unrecognized");

  private final String value;

  AttemptStatus(String value) {
    this.value = value;
  }

  /** The API's wire value (lower-case snake_case). */
  @JsonValue
  public String value() {
    return value;
  }

  @Override
  public String toString() {
    return value;
  }
}
