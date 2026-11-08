package tech.e258tech.nexorasms.error;

/** Network failure or timeout: there is no HTTP response, so {@link #status()} is 0. */
public class ConnectionException extends NexoraSmsException {
  private static final long serialVersionUID = 1L;

  /**
   * @param message exception message
   * @param cause underlying I/O failure
   */
  public ConnectionException(String message, Throwable cause) {
    super(message, 0, null, null, null, null, null, cause);
  }
}
