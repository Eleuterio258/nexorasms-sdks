package tech.e258tech.nexorasms.model;

import java.math.BigDecimal;

/**
 * Wallet balance.
 *
 * @param currency currency (e.g. MZN)
 * @param balance balance
 * @param reserved amount reserved for messages in progress
 * @param available amount available to spend
 */
public record Wallet(String currency, BigDecimal balance, BigDecimal reserved, BigDecimal available) {}
