package tech.e258tech.nexorasms.error;

/** A webhook whose signature, timestamp or body could not be verified. Answer such requests with HTTP 400. */
public class WebhookVerificationException extends RuntimeException {
  private static final long serialVersionUID = 1L;

  /** @param message reason */
  public WebhookVerificationException(String message) {
    super(message);
  }

  /**
   * @param message reason
   * @param cause underlying cause
   */
  public WebhookVerificationException(String message, Throwable cause) {
    super(message, cause);
  }
}
