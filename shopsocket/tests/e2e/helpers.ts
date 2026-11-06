import {
  expect,
  type Browser,
  type BrowserContext,
  type Locator,
  type Page,
} from "@playwright/test";
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
      .locator(
        '.woocommerce-message, .wc-block-components-notice-banner.is-success, .single_add_to_cart_button:has-text("in cart")',
      )
      .first(),
  ).toBeVisible({ timeout: 15_000 });
  /*
   * The Interactivity button says "in cart" optimistically, before the Store
   * API request has finished; navigating away at that moment aborts the add.
   * The cart cookie is WooCommerce's own proof that the server has the item.
   */
  await expect
    .poll(
      async () =>
        (await page.context().cookies()).some(
          (c) => c.name === "woocommerce_items_in_cart",
        ),
      { timeout: 15_000 },
    )
    .toBe(true);
}

/**
 * Wait until window.WPS reports a connection on the current page, then move the pointer: a new
 * journey, and with it presence, begins on the visitor's first interaction (WordSocket 0.31).
 */
export async function waitForLive(page: Page): Promise<void> {
  await expect
    .poll(() => page.evaluate(() => window.WPS?.state.connected ?? false), {
      timeout: 20_000,
    })
    .toBe(true);
  await page.mouse.move(12, 12);
  await page.mouse.move(48, 48);
}

/** A fresh anonymous browser (own cookies and storage): a new shopper, not the logged-in admin. */
export function visitorContext(
  browser: Browser,
  baseURL?: string,
): Promise<BrowserContext> {
  return browser.newContext({
    baseURL,
    ignoreHTTPSErrors: true,
    storageState: undefined,
  });
}

/** A part of the board's stat tile `name` (`is-<name>`): its value, its detail line or its money figure. */
export const stat = (
  page: Page,
  name: string,
  part: "value" | "detail" | "money" = "value",
) => page.locator(`.shopsocket-stat.is-${name} .shopsocket-stat__${part}`);

/** The visitor id a storefront page's browser holds. */
export const visitorOf = (page: Page): Promise<string> =>
  page.evaluate(() => window.WPS?.visitorId() ?? "");

/** A visitor's row in the board's shoppers table while they hold a cart: gone when they leave or the cart empties. */
export const shopperCart = (page: Page, v: string): Locator =>
  page.locator(".shopsocket-shoppers tbody tr", {
    has: page.locator(`[data-visitor="${v}"]`),
    hasText: /\d+ products?, /,
  });

/** The number in a tile or cell (digits and dots only, so "$1,234" reads as 1234), as a poll callback. */
export const num = (loc: Locator) => async () =>
  Number((await loc.textContent())?.replace(/[^\d.]/g, "") || 0);

/**
 * Forget the journeys of the carts holding `productId`, or of every cart when
 * no product is given (WordSocket's table holds them since 0.8). The rehearsal
 * site is shared with manual testing, so tests clear only what they created.
 */
export function clearCarts(productId?: number): void {
  const ns = "\\WPSignal\\Extensions\\ShopSocket";
  const where =
    productId === undefined
      ? "ref IS NOT NULL"
      : `ref IN (SELECT ref FROM (SELECT ref FROM $journeys WHERE data LIKE '%\\"${productId}\\":%') AS held)`;
  wp(
    "eval",
    `global $wpdb; $journeys = ${ns}\\journeys_table(); ` +
      `$wpdb->query("DELETE FROM $journeys WHERE ${where}"); ` +
      `${ns}\\forget('carts_pub');`,
  );
}

/**
 * `count` carts each holding one of `productId` whose shopper left `hoursAgo`
 * hours ago, written straight to WordSocket's journeys, under the cart id `ref`
 * when one is given; `clearCarts( productId )` removes them.
 */
export function leaveCart(
  productId: number,
  hoursAgo: number,
  count = 1,
  ref = "",
): void {
  const id = ref ? `'${ref}'` : `'e2e' . wp_generate_password( 12, false )`;
  wp(
    "eval",
    `for ( $i = 0; $i < ${count}; $i++ ) \\WPSignal\\Journeys::insert_row( array( ` +
      `'journey_id' => wp_generate_uuid4(), 'visitor' => wp_generate_uuid4(), ` +
      `'ref' => ${id}, ` +
      `'journey' => 'product:${productId}:30', 'outcome' => 'left', 'value' => 20, ` +
      `'data' => array( 'c' => get_woocommerce_currency(), 'i' => array( '${productId}' => 1 ) ), ` +
      `'started_at' => gmdate( 'Y-m-d H:i:s', time() - ${hoursAgo} * HOUR_IN_SECONDS ) ) );`,
  );
}

/** The cart id on a visitor's newest journey that has one, '' when none does. */
export function cartOf(visitor: string): string {
  return wp(
    "eval",
    `global $wpdb; echo (string) $wpdb->get_var( $wpdb->prepare( ` +
      `'SELECT ref FROM ' . \\WPSignal\\Extensions\\ShopSocket\\journeys_table() . ` +
      `' WHERE visitor = UNHEX(%s) AND ref IS NOT NULL ORDER BY id DESC LIMIT 1', ` +
      `\\WPSignal\\Journeys::hex( '${visitor}' ) ) );`,
  );
}

/** A managed-stock simple product through the WooCommerce REST API. */
export async function createProduct(
  requestUtils: RequestUtils,
  name: string,
  stock: number,
  lowStock = 2,
  imageId?: number,
) {
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
export async function deleteProduct(
  requestUtils: RequestUtils,
  id: number,
): Promise<void> {
  await requestUtils.rest({
    method: "DELETE",
    path: `/wc/v3/products/${id}`,
    params: { force: true },
  });
}

/** An order for `quantity` of a product, in `status`, through the WooCommerce REST API. */
export async function createOrder(
  requestUtils: RequestUtils,
  productId: number,
  quantity: number,
  status = "processing",
) {
  const order = await requestUtils.rest({
    method: "POST",
    path: "/wc/v3/orders",
    data: {
      status,
      billing: {
        first_name: "Grace",
        last_name: "Hopper",
        email: "grace@example.com",
      },
      line_items: [{ product_id: productId, quantity }],
    },
  });
  expect(order.id, JSON.stringify(order)).toBeTruthy();
  return order as { id: number; number: string; status: string };
}

/** Delete an order for good (no trash) through the WooCommerce REST API. */
export async function deleteOrder(
  requestUtils: RequestUtils,
  id: number,
): Promise<void> {
  await requestUtils.rest({
    method: "DELETE",
    path: `/wc/v3/orders/${id}`,
    params: { force: true },
  });
}

/** The id of any image already in the media library, or undefined when there is none. */
export async function anyImageId(
  requestUtils: RequestUtils,
): Promise<number | undefined> {
  const media = (await requestUtils.rest({
    path: "/wp/v2/media",
    params: { media_type: "image", per_page: 20 },
  })) as {
    id: number;
    slug?: string;
    source_url?: string;
  }[];
  // WooCommerce's own placeholder is an image too, but it has no thumbnail sizes: a fresh site has nothing else.
  return media.find(
    (m) =>
      !/woocommerce-placeholder/.test(`${m.slug ?? ""} ${m.source_url ?? ""}`),
  )?.id;
}
