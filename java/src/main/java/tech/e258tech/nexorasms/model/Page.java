package tech.e258tech.nexorasms.model;

import java.util.List;

/**
 * One page of a list.
 *
 * @param items the items of this page
 * @param page page number (from 1)
 * @param pageSize page size
 * @param total total number of items
 * @param <T> item type
 */
public record Page<T>(List<T> items, int page, int pageSize, long total) {
  public Page {
    items = Copies.list(items);
  }

  /** {@code true} when there are items after this page. */
  public boolean hasNext() {
    return (long) page * pageSize < total;
  }
}
