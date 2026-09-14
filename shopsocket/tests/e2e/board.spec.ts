import "./env";
import { expect, test } from "@wordpress/e2e-test-utils-playwright";
import { clearBaskets, createOrder, createProduct, deleteOrder, deleteProduct, num, visitorContext, waitForLive } from "./helpers";

const PAGE = "page=shopsocket";

test.describe("ShopSocket dashboard", () => {
  test("live and abandoned follow the shopper's connection", async ({ admin, page, browser, baseURL, requestUtils }) => {
    await admin.visitAdminPage("admin.php", PAGE);
    await expect(page.getByRole("heading", { name: "ShopSocket", level: 1 })).toBeVisible();
    await waitForLive(page);
    await expect(page.locator(".shopsocket-conn")).toHaveText("Live");

    const onlineTile = page.locator(".shopsocket-tile.is-connections .shopsocket-tile__value");
    await expect(onlineTile).toHaveText(/^\d+$/);
    const rows = page.locator(".shopsocket-baskets tbody tr");
    await expect(rows).toHaveCount(3);
    const liveShoppers = rows.nth(0).locator("td.is-live");
    const abandonedShoppers = rows.nth(0).locator("td.is-abandoned");
    const liveRevenue = rows.nth(2).locator("td.is-live");

    // Other browsers may share the rehearsal stack, so assert deltas.
    const live0 = await num(liveShoppers)();
    const abandoned0 = await num(abandonedShoppers)();
    const revenue0 = await num(liveRevenue)();

    const product = await createProduct(requestUtils, "E2E Basket Widget", 20);

    // One visitor context (keeps the session cookie so a reopened tab is the
    // same basket), a page that adds the product and enters presence.
    const visitor = await visitorContext(browser, baseURL);
    try {
      const shop = await visitor.newPage();
      await shop.goto(product.permalink);
      await waitForLive(shop);
      await shop.locator(".single_add_to_cart_button").first().click();
      await expect(shop.locator(".woocommerce-message, .wc-block-components-notice-banner").first()).toBeVisible({ timeout: 15_000 });

      // The basket is live, with its revenue, and nothing new is abandoned.
      await expect.poll(num(liveShoppers), { timeout: 15_000 }).toBeGreaterThanOrEqual(live0 + 1);
      await expect.poll(num(liveRevenue), { timeout: 10_000 }).toBeGreaterThan(revenue0);
      await expect.poll(num(abandonedShoppers), { timeout: 5_000 }).toBe(abandoned0);
      const liveWithShopper = await num(liveShoppers)();

      // Closing the tab drops the connection: the relay reports the leave and the
      // basket moves to abandoned at once, no beacon and no timeout.
      await shop.close();
      await expect.poll(num(abandonedShoppers), { timeout: 10_000 }).toBeGreaterThanOrEqual(abandoned0 + 1);
      await expect.poll(num(liveShoppers), { timeout: 10_000 }).toBeLessThanOrEqual(liveWithShopper - 1);

      // Reopening the tab (same session, same basket) reconnects and it goes live
      // again, without touching the cart.
      const reopened = await visitor.newPage();
      await reopened.goto(product.permalink);
      await waitForLive(reopened);
      await expect.poll(num(liveShoppers), { timeout: 10_000 }).toBeGreaterThanOrEqual(liveWithShopper);
      await expect.poll(num(abandonedShoppers), { timeout: 10_000 }).toBe(abandoned0);
    } finally {
      await visitor.close();
      await deleteProduct(requestUtils, product.id);
      clearBaskets();
    }
  });

  test("a logged-in shopper's own basket shows live, not abandoned", async ({ admin, page, requestUtils }) => {
    clearBaskets();
    const product = await createProduct(requestUtils, "E2E LoggedIn Basket", 20);
    await admin.visitAdminPage("admin.php", PAGE);
    await waitForLive(page);
    const rows = page.locator(".shopsocket-baskets tbody tr");
    const liveShoppers = rows.nth(0).locator("td.is-live");
    const abandonedShoppers = rows.nth(0).locator("td.is-abandoned");
    const live0 = await num(liveShoppers)();
    const abandoned0 = await num(abandonedShoppers)();

    // Same logged-in browser, a second tab is the shop.
    const shop = await page.context().newPage();
    try {
      await shop.goto(product.permalink);
      await waitForLive(shop);
      await shop.locator(".single_add_to_cart_button").first().click();
      await expect(shop.locator(".woocommerce-message, .wc-block-components-notice-banner").first()).toBeVisible({ timeout: 15_000 });

      // The logged-in shopper's own basket is live, and nothing new is abandoned.
      await expect.poll(num(liveShoppers), { timeout: 15_000 }).toBeGreaterThanOrEqual(live0 + 1);
      await expect.poll(num(abandonedShoppers), { timeout: 5_000 }).toBe(abandoned0);
    } finally {
      await shop.close();
      await deleteProduct(requestUtils, product.id);
      clearBaskets();
    }
  });

  test("users online counts distinct visitors and dedupes a shopper's tabs", async ({ admin, page, browser, baseURL }) => {
    await admin.visitAdminPage("admin.php", PAGE);
    await waitForLive(page);

    const usersTile = page.locator(".shopsocket-tile.is-users .shopsocket-tile__value");
    const users = async () => Number((await usersTile.textContent()) || 0);
    const users0 = await users();

    // One browser (one localStorage) = one visitor id, shared across its tabs.
    const shopper = await visitorContext(browser, baseURL);
    try {
      const tab1 = await shopper.newPage();
      await tab1.goto("/shop/");
      await waitForLive(tab1);
      await expect.poll(users, { timeout: 10_000 }).toBe(users0 + 1);
      const withShopper = await users();

      // A second tab in the same browser shares the visitor id: no new user.
      const tab2 = await shopper.newPage();
      await tab2.goto("/shop/");
      await waitForLive(tab2);
      await page.waitForTimeout(2500);
      expect(await users(), "a shopper's second tab is not a new user").toBe(withShopper);

      // A different browser is a different visitor id: one more user.
      const other = await visitorContext(browser, baseURL);
      const otherTab = await other.newPage();
      await otherTab.goto("/shop/");
      await waitForLive(otherTab);
      await expect.poll(users, { timeout: 10_000 }).toBe(withShopper + 1);

      // Closing the first browser (both its tabs) drops exactly one user.
      await shopper.close();
      await expect.poll(users, { timeout: 10_000 }).toBe(withShopper);
      await other.close();
    } finally {
      if (shopper.pages().length) await shopper.close().catch(() => {});
    }
  });

  test("a new order lands on the board and status changes update it in place", async ({ admin, page, requestUtils }) => {
    await admin.visitAdminPage("admin.php", PAGE);
    await waitForLive(page);

    const product = await createProduct(requestUtils, "E2E Board Widget", 10);
    const order = await createOrder(requestUtils, product.id, 2);
    try {
      const row = page.getByRole("row").filter({ has: page.locator(`[data-order-id="${order.id}"]`) });
      await expect(row).toBeVisible({ timeout: 15_000 });
      await expect(row).toContainText("Grace H.");
      await expect(row.locator(".shopsocket-status")).toHaveText("Processing");
      await expect(page.locator("[data-order-id]").first()).toHaveAttribute("data-order-id", String(order.id));

      await requestUtils.rest({ method: "PUT", path: `/wc/v3/orders/${order.id}`, data: { status: "completed" } });
      await expect(row.locator(".shopsocket-status")).toHaveText("Completed", { timeout: 15_000 });

      // DataViews: the search box narrows the board to the customer.
      await page.getByRole("searchbox", { name: "Search orders" }).fill("Grace");
      await expect(page.getByRole("row").filter({ has: page.locator("[data-order-id]") })).toHaveCount(1);
    } finally {
      await deleteOrder(requestUtils, order.id);
      await deleteProduct(requestUtils, product.id);
    }
  });

  test("selling out a product raises the stock strip", async ({ admin, page, requestUtils }) => {
    await admin.visitAdminPage("admin.php", PAGE);
    await waitForLive(page);
    const product = await createProduct(requestUtils, "E2E Scarce Widget", 2, 2);
    const order = await createOrder(requestUtils, product.id, 2);
    try {
      const strip = page.locator(".shopsocket-board__stock");
      await expect(strip).toContainText("E2E Scarce Widget is out of stock", { timeout: 15_000 });

      // A restock clears it: stock changes are public events staff also receive.
      await requestUtils.rest({ method: "PUT", path: `/wc/v3/products/${product.id}`, data: { stock_quantity: 5 } });
      await expect(strip).toHaveCount(0, { timeout: 15_000 });
    } finally {
      await deleteOrder(requestUtils, order.id);
      await deleteProduct(requestUtils, product.id);
    }
  });
});
