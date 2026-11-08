package tech.e258tech.nexorasms;

import java.time.Duration;

/** Waits between retries. Injectable (package-private) so tests do not wait in real time. */
@FunctionalInterface
interface Sleeper {
  Sleeper REAL = duration -> Thread.sleep(duration.toMillis());

  void sleep(Duration duration) throws InterruptedException;
}
