package tech.e258tech.nexorasms.model;

import java.util.ArrayList;
import java.util.Collections;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

/** Defensive, null-tolerant immutable copies for the records of this package. */
final class Copies {
  private Copies() {}

  static <T> List<T> list(List<T> source) {
    return source == null ? List.of() : Collections.unmodifiableList(new ArrayList<>(source));
  }

  static <K, V> Map<K, V> map(Map<K, V> source) {
    return source == null ? Map.of() : Collections.unmodifiableMap(new LinkedHashMap<>(source));
  }
}
