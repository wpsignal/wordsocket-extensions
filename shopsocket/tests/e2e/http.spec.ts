import "./env";
import { expect, test } from "@wordpress/e2e-test-utils-playwright";
import { addToCart, createProduct, deleteProduct, visitorContext, waitForLive } from "./helpers";
import { wp } from "./env";

/**
 * Plain HTTP, where developers try the plugin first. `http://something.local`
 * is not a secure context: no SubtleCrypto, no `crypto.randomUUID`, and
 * WordSocket sends payloads unencrypted. Everything must still work. The rest
 * of the suite runs on this site too (`npm run test:e2e:http`); these tests
 * pin the parts that only differ over HTTP, and skip on an HTTPS target.
 */
test.describe("Plain HTTP site", () => {
  test.beforeEach(({ baseURL }) => {
    test.skip(!baseURL?.startsWith("http://"), "HTTP-only: run with npm run test:e2e:http");
  });

  test("an insecure context still connects, keeps a visitor id, and follows stock", async ({ browser, baseURL, requestUtils }) => {
    const product = await createProduct(requestUtils, "E2E Http Widget", 6);
    const visitor = await visitorContext(browser, baseURL);
    const page = await visitor.newPage();
    try {
      await page.goto(product.permalink);
      const context = await page.evaluate(() => ({
        secure: window.isSecureContext,
        randomUUID: typeof window.crypto?.randomUUID,
        subtle: typeof window.crypto?.subtle,
      }));
      expect(context.secure, "the point of this site is that it is not a secure context").toBe(false);
      expect(context.randomUUID).toBe("undefined");

      // The relay socket is wss:// even from an http:// page, and it connects.
      await waitForLive(page);
      expect(await page.evaluate(() => window.WPS?.state.transport)).toBe("ws");

      // The visitor id is still a real v4 UUID: built from getRandomValues, which HTTP does allow.
      await expect
        .poll(() => page.evaluate(() => window.localStorage.getItem("wordsocket-visitor") ?? ""), { timeout: 10_000 })
        .toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);

      // Unencrypted events arrive and are read like any other: stock moves without a reload.
      await expect(page.locator(".shopsocket-stock").first()).toContainText("6 in stock");
      wp("eval", `wc_update_product_stock( ${product.id}, 2 );`);
      await expect(page.locator(".shopsocket-stock").first()).toContainText("2 in stock", { timeout: 15_000 });
    } finally {
      await visitor.close();
      await deleteProduct(requestUtils, product.id);
    }
  });

  test("a shopper on HTTP is live on the board, under the same visitor id across pages", async ({ admin, page, browser, baseURL, requestUtils }) => {
    await admin.visitAdminPage("admin.php", "page=shopsocket");
    await waitForLive(page);
    const product = await createProduct(requestUtils, "E2E Http Basket", 9);
    const visitor = await visitorContext(browser, baseURL);
    const shop = await visitor.newPage();
    try {
      await shop.goto(product.permalink);
      await waitForLive(shop);
      await addToCart(shop);
      const liveLink = page.locator(".shopsocket-live-products a", { hasText: "E2E Http Basket" });
      await expect(liveLink).toBeVisible({ timeout: 15_000 });

      // The fallback id is stored, so the next page is the same visitor, not a new one.
      const before = await shop.evaluate(() => window.localStorage.getItem("wordsocket-visitor"));
      expect(before, "the shopper has a visitor id").toMatch(/\S{8,}/);
      await shop.goto("/my-account/");
      await waitForLive(shop);
      expect(await shop.evaluate(() => window.localStorage.getItem("wordsocket-visitor"))).toBe(before);
      await expect(liveLink).toBeVisible();
    } finally {
      await visitor.close();
      await deleteProduct(requestUtils, product.id);
    }
  });
});
