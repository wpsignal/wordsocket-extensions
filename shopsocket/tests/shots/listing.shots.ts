/**
 * The first two WordPress.org screenshots, staged rather than drawn: a quiet
 * board, then a busy one built from scripted shoppers on the storefront and
 * demo orders. Written to `wporg-assets/` under the names `bin/dist.sh` picks
 * up. Everything it creates is deleted afterwards, and the orders and cart
 * rows the site already had are put back.
 *
 * Run with `npm run shots` (the plain-HTTP site and the rehearsal relay, as
 * `npm run test:e2e:http`). Never against a site on the live relay.
 */
import "../e2e/env";
import { resolve } from "node:path";
import { expect, test } from "@wordpress/e2e-test-utils-playwright";
import type { BrowserContext, Page } from "@playwright/test";
import { wp } from "../e2e/env";
import { addToCart, deleteOrder, deleteProduct, num, visitorContext, waitForLive } from "../e2e/helpers";

const ASSETS = resolve(__dirname, "../../wporg-assets");

const PRODUCTS = [
  { name: "Summit Thermos", price: "48.00" },
  { name: "Alpine Carafe", price: "62.00" },
  { name: "Basecamp Mug", price: "18.00" },
  { name: "Trail Lantern", price: "74.00" },
  { name: "Ridge Blanket", price: "89.00" },
  { name: "Canyon Kettle", price: "56.00" },
] as const;

/* Newest first, as the board lists them. `ago` is minutes before the shot. */
const ORDERS = [
  { first: "Maya", last: "Okafor", items: [[0, 1], [2, 2]], status: "processing", payment: "Credit card", ago: 1 },
  { first: "Liam", last: "Petrov", items: [[2, 4]], status: "processing", payment: "Cash on delivery", ago: 4 },
  { first: "Sofia", last: "Marin", items: [[1, 1], [2, 1]], status: "processing", payment: "Credit card", ago: 9 },
  { first: "Noah", last: "Lindqvist", items: [[4, 1]], status: "on-hold", payment: "Bank transfer", ago: 15 },
  { first: "Aiko", last: "Tanaka", items: [[3, 1], [2, 1]], status: "completed", payment: "Credit card", ago: 22 },
  { first: "Elena", last: "Rossi", items: [[5, 1]], status: "completed", payment: "Credit card", ago: 31 },
  { first: "Omar", last: "Haddad", items: [[0, 2]], status: "completed", payment: "Credit card", ago: 44 },
  { first: "Grace", last: "Nwosu", items: [[1, 1]], status: "completed", payment: "Cash on delivery", ago: 58 },
] as const;

/* Each live shopper's cart as [product index, quantity]; the last one browses without a cart. */
const LIVE_CARTS = [[[0, 2], [2, 1]], [[1, 1]], [[0, 1], [3, 1]], []] as const;
const ABANDONED_CARTS = [[[4, 1]], [[2, 2]], [[5, 1], [2, 1]]] as const;

/** Hide the toolbar and anything else that is the test site's rather than the plugin's. */
const CHROME = `
  #wpadminbar, #wpfooter, .notice, .update-nag, .woocommerce-layout__header, .woocommerce-store-alerts { display: none !important; }
  html.wp-toolbar { padding-top: 0 !important; }
  #adminmenu .update-plugins, #adminmenu .awaiting-mod, #adminmenu .menu-counter { display: none !important; }
`;

/*
 * The rehearsal account that owns the test site is on the unlimited plan, so
 * the Open Tabs tile reads "of N/999999". A new store is on the free plan:
 * show its limit instead. This is the one figure on the board that is edited
 * rather than produced; everything else is what the scripted store did.
 */
const FREE_PLAN_CONNECTIONS = "50";
async function showFreePlanLimit(page: Page): Promise<void> {
  await page.locator(".shopsocket-tile.is-connections .shopsocket-tile__detail").evaluate((el, limit) => {
    el.textContent = (el.textContent ?? "").replace(/\/\d+/, `/${limit}`);
  }, FREE_PLAN_CONNECTIONS);
}

async function fillCart(context: BrowserContext, permalinks: string[], cart: readonly (readonly [number, number])[]): Promise<Page> {
  const page = await context.newPage();
  if (cart.length === 0) {
    await page.goto(permalinks[0]);
    await waitForLive(page);
    return page;
  }
  for (const [index, quantity] of cart) {
    await page.goto(permalinks[index]);
    await waitForLive(page);
    const qty = page.locator("form.cart input.qty").first();
    if (quantity > 1 && (await qty.count())) await qty.fill(String(quantity));
    await addToCart(page);
  }
  return page;
}

test("stage the listing screenshots", async ({ admin, page, browser, baseURL, requestUtils }) => {
  const savedCarts = wp("eval", "echo wp_json_encode( get_transient( 'shopsocket_carts' ) ?: array() );");
  // "id:status" for every order the site already has, so each goes back as it was.
  const parkedOrders = wp(
    "eval",
    "echo implode( ',', array_map( static fn( $o ) => $o->get_id() . ':' . $o->get_status(), wc_get_orders( array( 'limit' => -1 ) ) ) );",
  )
    .split(",")
    .filter(Boolean)
    .map((pair) => pair.split(":") as [string, string]);
  const productIds: number[] = [];
  const orderIds: number[] = [];
  const visitors: BrowserContext[] = [];

  try {
    // A clean slate: the site's own orders step aside (trash is reversible) and old cart rows go.
    for (const [id] of parkedOrders) wp("eval", `add_filter( 'pre_wp_mail', '__return_false' ); wc_get_order( ${id} )->update_status( 'trash' );`);
    wp("eval", "delete_transient( 'shopsocket_carts' ); delete_transient( 'shopsocket_carts_pub' );");

    await admin.visitAdminPage("admin.php", "page=shopsocket");
    await page.addStyleTag({ content: CHROME });
    await expect(page.getByRole("heading", { name: "ShopSocket", level: 1 })).toBeVisible();
    await waitForLive(page);
    await expect(page.locator(".shopsocket-conn")).toHaveText("Live");
    await expect(page.locator(".shopsocket-tile.is-connections .shopsocket-tile__value")).toHaveText(/^\d+$/);

    // 1: the board before the first shopper arrives.
    await page.setViewportSize({ width: 1280, height: 980 });
    await page.waitForTimeout(1_000);
    await showFreePlanLimit(page);
    await page.screenshot({ path: resolve(ASSETS, "wporg-screenshot-01.png") });

    // The store: products, then shoppers who stay, shoppers who leave, and a morning of orders.
    const permalinks: string[] = [];
    for (const { name, price } of PRODUCTS) {
      const product = await requestUtils.rest({
        method: "POST",
        path: "/wc/v3/products",
        data: { name, type: "simple", regular_price: price, status: "publish" },
      });
      productIds.push(product.id);
      permalinks.push(product.permalink);
    }

    for (const cart of LIVE_CARTS) {
      const context = await visitorContext(browser, baseURL);
      visitors.push(context);
      await fillCart(context, permalinks, cart);
    }
    // One live shopper has a second tab open, as people do.
    const second = await visitors[0].newPage();
    await second.goto(permalinks[2]);
    await waitForLive(second);

    for (const cart of ABANDONED_CARTS) {
      const context = await visitorContext(browser, baseURL);
      await fillCart(context, permalinks, cart);
      await context.close();
    }

    for (const order of [...ORDERS].reverse()) {
      const created = await requestUtils.rest({
        method: "POST",
        path: "/wc/v3/orders",
        data: {
          status: order.status,
          payment_method_title: order.payment,
          billing: { first_name: order.first, last_name: order.last, email: `${order.first.toLowerCase()}@example.com` },
          line_items: order.items.map(([index, quantity]) => ({ product_id: productIds[index], quantity })),
        },
      });
      orderIds.push(created.id);
      wp("eval", `$o = wc_get_order( ${created.id} ); $o->set_date_created( time() - ${order.ago * 60} ); $o->save();`);
    }

    // 2: the busy board, reloaded so the orders read in their final order and times.
    await page.setViewportSize({ width: 1280, height: 1180 });
    await admin.visitAdminPage("admin.php", "page=shopsocket");
    await page.addStyleTag({ content: CHROME });
    await waitForLive(page);
    const rows = page.locator(".shopsocket-carts tbody tr");
    await expect.poll(num(rows.nth(0).locator("td.is-live")), { timeout: 30_000 }).toBe(LIVE_CARTS.filter((c) => c.length).length);
    await expect.poll(num(rows.nth(0).locator("td.is-abandoned")), { timeout: 30_000 }).toBe(ABANDONED_CARTS.length);
    await expect(page.locator(".shopsocket-live-products a").first()).toBeVisible({ timeout: 15_000 });
    await expect(page.getByText(`${ORDERS[0].first} ${ORDERS[0].last.charAt(0)}.`)).toBeVisible();
    await page.waitForTimeout(1_500);
    await showFreePlanLimit(page);
    await page.screenshot({ path: resolve(ASSETS, "wporg-screenshot-02.png") });
  } finally {
    for (const context of visitors) await context.close().catch(() => {});
    for (const id of orderIds) await deleteOrder(requestUtils, id).catch(() => {});
    for (const id of productIds) await deleteProduct(requestUtils, id).catch(() => {});
    for (const [id, status] of parkedOrders) {
      wp("eval", `add_filter( 'pre_wp_mail', '__return_false' ); wc_get_order( ${id} )->update_status( '${status}' );`);
    }
    wp(
      "eval",
      `set_transient( 'shopsocket_carts', json_decode( '${savedCarts.replace(/'/g, "")}', true ), 2 * DAY_IN_SECONDS ); delete_transient( 'shopsocket_carts_pub' );`,
    );
  }
});
