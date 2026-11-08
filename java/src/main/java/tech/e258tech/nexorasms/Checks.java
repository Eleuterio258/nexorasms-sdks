package tech.e258tech.nexorasms;

import java.util.regex.Pattern;

/** Shared argument checks (messages in Portuguese, as shown to integrators). */
final class Checks {
  private static final Pattern VISIBLE_ASCII = Pattern.compile("[\\x21-\\x7E]{1,200}");

  private Checks() {}

  static String idempotencyKey(String key) {
    if (key == null || !VISIBLE_ASCII.matcher(key).matches())
      throw new IllegalArgumentException("idempotencyKey deve ter de 1 a 200 caracteres ASCII visíveis.");
    return key;
  }

  static int page(int page) {
    if (page < 1) throw new IllegalArgumentException("page deve ser um inteiro a partir de 1.");
    return page;
  }

  static int pageSize(int pageSize) {
    if (pageSize < 1 || pageSize > 200) throw new IllegalArgumentException("pageSize deve ser um inteiro entre 1 e 200.");
    return pageSize;
  }

  static String id(String id) {
    if (id == null || id.isBlank()) throw new IllegalArgumentException("Identificador em falta.");
    return id;
  }

  static boolean nonEmpty(String value) {
    return value != null && !value.isBlank();
  }

  static <E extends Enum<E>> E known(E value, String name) {
    if (value == null || "UNRECOGNIZED".equals(value.name()))
      throw new IllegalArgumentException(name + " em falta ou inválido.");
    return value;
  }
}
