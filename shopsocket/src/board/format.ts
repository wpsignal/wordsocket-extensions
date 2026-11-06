/*
 * Number formatting shared by the board's panels.
 */

/**
 * A whole-unit amount in the browser's locale, or a rounded number when the currency is unknown.
 *
 * @since 0.8.0
 */
export function money(amount: number, currency: string): string {
  try {
    return new Intl.NumberFormat(undefined, {
      style: "currency",
      currency,
      maximumFractionDigits: 0,
    }).format(amount);
  } catch {
    return String(Math.round(amount));
  }
}

/**
 * An amount to the cent in the browser's locale, or `symbol` and two decimals when the currency is unknown.
 *
 * @since 0.8.0
 */
export function price(amount: number, currency: string, symbol: string): string {
  try {
    return new Intl.NumberFormat(undefined, {
      style: "currency",
      currency,
    }).format(amount);
  } catch {
    return `${symbol}${amount.toFixed(2)}`;
  }
}
