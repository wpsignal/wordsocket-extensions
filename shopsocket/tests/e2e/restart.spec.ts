import "./env";
import { expect, test } from "@wordpress/e2e-test-utils-playwright";
import { execSync, spawn, type ChildProcess } from "node:child_process";
import { request } from "@playwright/test";
import { WP_ROOT, WPS_API_URL, WPS_E2E_EMAIL, WPS_E2E_PASSWORD } from "./env";
import { addToCart, clearBaskets, createProduct, deleteProduct, num, visitorContext, waitForLive } from "./helpers";

const PAGE = "page=shopsocket";

/** Start the rehearsal relay the way the Playwright config does, detached, and wait for it. */
async function startRelay(): Promise<ChildProcess> {
  const relay = spawn(process.env.WPS_LOCAL_TLS!, ["--e2e-site", WP_ROOT, "--seed-e2e"], {
    detached: true,
    stdio: "ignore",
    env: { ...process.env, E2E_EMAIL: WPS_E2E_EMAIL, E2E_PASSWORD: WPS_E2E_PASSWORD },
  });
  const ctx = await request.newContext({ ignoreHTTPSErrors: true });
  try {
    await expect
      .poll(async () => (await ctx.get(`${WPS_API_URL}/healthz`).catch(() => null))?.ok() ?? false, { timeout: 120_000 })
      .toBe(true);
  } finally {
    await ctx.dispose();
  }
  return relay;
}

/**
 * The relay dies and comes back while a shopper holds a basket and the board
 * is open. Both clients fall back to SSE while it is down and return to
 * WebSocket as soon as the stream opens, so the shopper's basket is live
 * again within seconds and with no page reload. Opt in: it restarts the shared
 * rehearsal relay and takes about two minutes.
 */
test.describe("Relay restart", () => {
  test("a live basket comes back after the relay restarts", async ({ admin, page, browser, baseURL, requestUtils }) => {
    test.skip(!process.env.SHOPSOCKET_RESTART, "set SHOPSOCKET_RESTART=1 to run this slow relay-restart test");
    test.setTimeout(5 * 60_000);

    await admin.visitAdminPage("admin.php", PAGE);
    await waitForLive(page);
    const product = await createProduct(requestUtils, "E2E Restart Widget", 20);
    const visitor = await visitorContext(browser, baseURL);
    let relay: ChildProcess | null = null;
    const transportOf = (p: typeof page) => () => p.evaluate(() => window.WPS?.state.transport ?? null);
    const connectedOf = (p: typeof page) => () => p.evaluate(() => window.WPS?.state.connected ?? false);
    try {
      const shop = await visitor.newPage();
      await shop.goto(product.permalink);
      await waitForLive(shop);
      await addToCart(shop);
      const liveLink = page.locator(".shopsocket-live-products a", { hasText: "E2E Restart Widget" });
      await expect(liveLink).toBeVisible({ timeout: 15_000 });

      // The relay dies: both clients notice.
      execSync("pkill -f target/release/wpsignal-server");
      await expect.poll(connectedOf(page), { timeout: 30_000 }).toBe(false);
      await expect.poll(connectedOf(shop), { timeout: 30_000 }).toBe(false);
      await page.waitForTimeout(20_000);

      /*
       * It comes back. Whichever transport opens first, an open stream proves
       * the relay is reachable and the client probes the socket at once, so
       * both are back on WebSocket within seconds, not at the token refresh.
       */
      relay = await startRelay();
      await expect.poll(connectedOf(page), { timeout: 90_000 }).toBe(true);

      /*
       * The board is back before the shopper: a reloaded board shows the basket
       * abandoned. The shopper then adds another unit in the still-open tab,
       * whatever state their client is in; that must bring the basket back
       * live (presence is re-sent on the next connection if not at once).
       */
      await page.reload();
      await waitForLive(page);
      const abandonedShoppers = page.locator(".shopsocket-baskets tbody tr").nth(0).locator("td.is-abandoned");
      const liveShoppers = page.locator(".shopsocket-baskets tbody tr").nth(0).locator("td.is-live");
      const shopperTransportBefore = await transportOf(shop)();
      await addToCart(shop);
      await expect(liveLink, `live again after adding (shopper was on ${shopperTransportBefore})`).toBeVisible({ timeout: 60_000 });
      await expect.poll(async () => (await num(liveShoppers)()) >= 1, { timeout: 30_000 }).toBe(true);

      await expect.poll(connectedOf(shop), { timeout: 90_000 }).toBe(true);
      await expect.poll(transportOf(shop), { timeout: 30_000 }).toBe("ws");
      await expect.poll(transportOf(page), { timeout: 30_000 }).toBe("ws");
      await expect(liveLink).toBeVisible({ timeout: 30_000 });
      await expect.poll(num(abandonedShoppers), { timeout: 30_000 }).toBe(0);
    } finally {
      await visitor.close();
      clearBaskets(product.id);
      await deleteProduct(requestUtils, product.id);
      if (relay?.pid) {
        /*
         * The launcher is a process-group leader (detached), so this reaches
         * the server it started; then make sure the port really went quiet.
         */
        for (const signal of ["SIGTERM", "SIGKILL"] as const) {
          try {
            process.kill(-relay.pid, signal);
          } catch {
            // already gone
          }
          const ctx = await request.newContext({ ignoreHTTPSErrors: true });
          try {
            const gone = await expect
              .poll(async () => (await ctx.get(`${WPS_API_URL}/healthz`).catch(() => null)) === null, { timeout: 10_000 })
              .toBe(true)
              .then(() => true)
              .catch(() => false);
            if (gone) break;
          } finally {
            await ctx.dispose();
          }
        }
      }
    }
  });
});
