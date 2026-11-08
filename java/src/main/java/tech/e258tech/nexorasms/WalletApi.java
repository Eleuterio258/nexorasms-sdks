package tech.e258tech.nexorasms;

import java.util.LinkedHashMap;
import java.util.Map;
import tech.e258tech.nexorasms.model.Page;
import tech.e258tech.nexorasms.model.Wallet;
import tech.e258tech.nexorasms.model.WalletTransaction;

/** Wallet resource: {@code client.wallet()}. */
public final class WalletApi {
  private final NexoraSmsClient client;

  WalletApi(NexoraSmsClient client) {
    this.client = client;
  }

  /** Current balance. */
  public Wallet get() {
    return client.<Wallet>call("GET", "/api/v1/wallet", null, null, null, true, client.type(Wallet.class)).data();
  }

  /** First page of transactions. */
  public Page<WalletTransaction> transactions() {
    return transactions(null);
  }

  /** One page of transactions (page from 1; pageSize 1 to 200). */
  public Page<WalletTransaction> transactions(int page, int pageSize) {
    Map<String, String> query = new LinkedHashMap<>();
    query.put("page", Integer.toString(Checks.page(page)));
    query.put("page_size", Integer.toString(Checks.pageSize(pageSize)));
    return transactions(query);
  }

  private Page<WalletTransaction> transactions(Map<String, String> query) {
    return client.<Page<WalletTransaction>>call("GET", "/api/v1/wallet/transactions", query, null, null, true,
        client.type(Page.class, WalletTransaction.class)).data();
  }
}
