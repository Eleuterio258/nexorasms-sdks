package tech.e258tech.nexorasms.model;

import com.fasterxml.jackson.annotation.JsonEnumDefaultValue;
import com.fasterxml.jackson.annotation.JsonValue;

/** Delivery channel. Unknown values sent by newer API versions map to {@link #UNRECOGNIZED}. */
public enum Channel {
  SMS("sms"),
  WHATSAPP("whatsapp"),
  EMAIL("email"),
  /** A value this SDK version does not know yet; never send it to the API. */
  @JsonEnumDefaultValue
  UNRECOGNIZED("unrecognized");

  private final String value;

  Channel(String value) {
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
