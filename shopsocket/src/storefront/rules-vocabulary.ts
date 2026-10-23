/*
 * The browser halves of ShopSocket's rules vocabulary, shared by the
 * storefront module (where rules fire) and the settings bundle (where the
 * replay runs them over stored journeys). Registered against `WPS.rules`;
 * nothing here runs without it.
 *
 * - Conditions read `ctx.cart`, a `CartSnapshot` the storefront feeds from the
 *   viewer's sync and the replay from the journey row (`has_cart`, `value`).
 * - The one action posts to ShopSocket's alert route through `config`, naming
 *   the moment: PHP takes the message from the saved moment, not from here.
 */
import type { CartSnapshot } from "shopsocket/storefront/viewer";

export interface RulesConfig {
  alertUrl?: string;
  nonce?: string;
}

const num = (value: unknown): number =>
  typeof value === "number" ? value : Number(value) || 0;

function cartOf(ctx: WPSRuleContext): CartSnapshot {
  const cart = (ctx.cart ?? {}) as Partial<CartSnapshot>;
  return {
    count: num(cart.count),
    total: num(cart.total),
    currency: cart.currency ?? "",
    products: cart.products ?? [],
    product: cart.product ?? "",
  };
}

function bounds(op: string, actual: number, wanted: number): boolean {
  if (op === "at_least") return actual >= wanted;
  if (op === "at_most") return actual <= wanted;
  return false;
}

export function registerConditions(rules: WPSRulesApi): void {
  rules.condition(
    "cart_has_items",
    (ctx, c) => c.op === "is" && cartOf(ctx).count > 0 === Boolean(c.value),
  );
  rules.condition("cart_count", (ctx, c) =>
    bounds(c.op, cartOf(ctx).count, num(c.value)),
  );
  rules.condition("cart_total", (ctx, c) =>
    bounds(c.op, cartOf(ctx).total, num(c.value)),
  );
  rules.condition("cart_has_product", (ctx, c) => {
    const has = cartOf(ctx).products.some((p) => p.id === num(c.value));
    return c.op === "includes" ? has : c.op === "excludes" ? !has : false;
  });
  rules.condition("cart_has_category", (ctx, c) => {
    const has = cartOf(ctx).products.some((p) =>
      p.categories.includes(num(c.value)),
    );
    return c.op === "includes" ? has : c.op === "excludes" ? !has : false;
  });
}

/** A post to one of ShopSocket's rule routes; resolves to the response status, 0 when it never left. */
async function post(
  url: string | undefined,
  body: Record<string, unknown>,
  nonce?: string,
): Promise<number> {
  if (!url) return 0;
  const headers: Record<string, string> = {
    "Content-Type": "application/json",
  };
  if (nonce) headers["X-WP-Nonce"] = nonce;
  try {
    const res = await fetch(url, {
      method: "POST",
      credentials: "same-origin",
      headers,
      body: JSON.stringify(body),
    });
    return res.status;
  } catch {
    return 0;
  }
}

export function registerActions(
  rules: WPSRulesApi,
  config: RulesConfig,
  visitor: () => string,
  currentStep: () => string,
): void {
  rules.action("alert_staff", (_ctx, params, _recovery, rule) => {
    void post(
      config.alertUrl,
      {
        v: visitor(),
        rule: rule.id,
        message: String(params.message ?? ""),
        step: currentStep(),
      },
      config.nonce,
    );
  });
}
