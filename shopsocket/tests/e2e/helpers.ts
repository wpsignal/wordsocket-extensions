import { expect, type Browser, type BrowserContext, type Locator, type Page } from "@playwright/test";
import type { RequestUtils } from "@wordpress/e2e-test-utils-playwright";
import { wp } from "./env";

/**
 * Add the page's product to the cart and wait for WooCommerce to confirm: the
 * classic form reloads with a notice, the Interactivity form flips the button
 * to "in cart" without a reload.
 */
export async function addToCart(page: Page): Promise<void> {
  await page.locator(".single_add_to_cart_button").first().click();
  await expect(
    page
      .locator('.woocommerce-message, .wc-block-components-notice-banner.is-success, .single_add_to_cart_button:has-text("in cart")')
      .first(),
  ).toBeVisible({ timeout: 15_000 });
}

/** Wait until window.WPS reports a connection on the current page. */
export async function waitForLive(page: Page): Promise<void> {
  await expect.poll(() => page.evaluate(() => window.WPS?.state.connected ?? false), { timeout: 20_000 }).toBe(true);
}

/** A fresh anonymous browser (own cookies and storage): a new shopper, not the logged-in admin. */
export function visitorContext(browser: Browser, baseURL?: string): Promise<BrowserContext> {
  return browser.newContext({ baseURL, ignoreHTTPSErrors: true, storageState: undefined });
}

/** The number in a tile or cell (digits and dots only, so "$1,234" reads as 1234), as a poll callback. */
export const num = (loc: Locator) => async () => Number((await loc.textContent())?.replace(/[^\d.]/g, "") || 0);

/**
 * Forget the basket rows holding `productId`, or every row when no product is
 * given. The rehearsal site is shared with manual testing, so tests clear only
 * what they created.
 */
export function clearBaskets(productId?: number): void {
  if (productId === undefined) {
    wp("eval", "delete_transient('shopsocket_baskets'); delete_transient('shopsocket_baskets_pub');");
    return;
  }
  wp(
    "eval",
    `$rows = get_transient('shopsocket_baskets'); if (is_array($rows)) { foreach ($rows as $id => $row) { if (in_array(${productId}, (array) ($row['products'] ?? []), true)) { unset($rows[$id]); } } set_transient('shopsocket_baskets', $rows, 2 * DAY_IN_SECONDS); delete_transient('shopsocket_baskets_pub'); }`,
  );
}

/** A managed-stock simple product through the WooCommerce REST API. */
export async function createProduct(requestUtils: RequestUtils, name: string, stock: number, lowStock = 2, imageId?: number) {
  const product = await requestUtils.rest({
    method: "POST",
    path: "/wc/v3/products",
    data: {
      name,
      type: "simple",
      regular_price: "9.99",
      manage_stock: true,
      stock_quantity: stock,
      low_stock_amount: lowStock,
      status: "publish",
      images: imageId ? [{ id: imageId }] : [],
    },
  });
  expect(product.id, JSON.stringify(product)).toBeTruthy();
  return product as { id: number; permalink: string };
}

/** Delete a product for good (no trash) through the WooCommerce REST API. */
export async function deleteProduct(requestUtils: RequestUtils, id: number): Promise<void> {
  await requestUtils.rest({ method: "DELETE", path: `/wc/v3/products/${id}`, params: { force: true } });
}

/** An order for `quantity` of a product, in `status`, through the WooCommerce REST API. */
export async function createOrder(requestUtils: RequestUtils, productId: number, quantity: number, status = "processing") {
  const order = await requestUtils.rest({
    method: "POST",
    path: "/wc/v3/orders",
    data: {
      status,
      billing: { first_name: "Grace", last_name: "Hopper", email: "grace@example.com" },
      line_items: [{ product_id: productId, quantity }],
    },
  });
  expect(order.id, JSON.stringify(order)).toBeTruthy();
  return order as { id: number; number: string; status: string };
}

/** Delete an order for good (no trash) through the WooCommerce REST API. */
export async function deleteOrder(requestUtils: RequestUtils, id: number): Promise<void> {
  await requestUtils.rest({ method: "DELETE", path: `/wc/v3/orders/${id}`, params: { force: true } });
}

/** The id of any image already in the media library, or undefined when there is none. */
export async function anyImageId(requestUtils: RequestUtils): Promise<number | undefined> {
  const media = (await requestUtils.rest({ path: "/wp/v2/media", params: { media_type: "image", per_page: 1 } })) as { id: number }[];
  return media[0]?.id;
}
