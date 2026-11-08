package tech.e258tech.nexorasms.model;

import com.fasterxml.jackson.annotation.JsonEnumDefaultValue;
import com.fasterxml.jackson.annotation.JsonValue;

/** SMS encoding used by an estimate. Unknown values sent by newer API versions map to {@link #UNRECOGNIZED}. */
public enum Encoding {
  GSM7("gsm7"),
  UCS2("ucs2"),
  UTF8("utf8"),
  /** A value this SDK version does not know yet; never send it to the API. */
  @JsonEnumDefaultValue
  UNRECOGNIZED("unrecognized");

  private final String value;

  Encoding(String value) {
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
