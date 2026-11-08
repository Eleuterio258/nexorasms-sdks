package tech.e258tech.nexorasms.model;

import com.fasterxml.jackson.annotation.JsonEnumDefaultValue;
import com.fasterxml.jackson.annotation.JsonValue;

/** Status of a campaign. Unknown values sent by newer API versions map to {@link #UNRECOGNIZED}. */
public enum CampaignStatus {
  DRAFT("draft"),
  SCHEDULED("scheduled"),
  RUNNING("running"),
  PAUSED("paused"),
  COMPLETED("completed"),
  CANCELLED("cancelled"),
  FAILED("failed"),
  /** A value this SDK version does not know yet; never send it to the API. */
  @JsonEnumDefaultValue
  UNRECOGNIZED("unrecognized");

  private final String value;

  CampaignStatus(String value) {
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
