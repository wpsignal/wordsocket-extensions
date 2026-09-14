import "./env";
import { expect, test } from "@wordpress/e2e-test-utils-playwright";
import { WP_ROOT, wp } from "./env";
import { spawn } from "node:child_process";

/**
 * One shopper's session, as a background wp-cli process: add to cart, keep
 * the session alive for `holdSeconds`, then empty the cart. Two separate
 * wp-cli calls would be two WooCommerce sessions, not one shopper.
 */
function shopperSession(productId: number, holdSeconds: number): Promise<void> {
  // Zero the per-product publish throttle for this add so the event always fires:
  // the viewer's own add moments earlier would otherwise consume the 10s window
  // and suppress this shopper's `woo.cart.added`. The filter lives only in this
  // wp-cli process, where `should_publish_cart_add` runs during add_to_cart.
  const script = `add_filter( 'shopsocket_activity_throttle', '__return_zero' ); WC()->cart->add_to_cart( ${productId}, 1 ); sleep( ${holdSeconds} ); WC()->cart->empty_cart();`;
  return new Promise((resolve, reject) => {
    const child = spawn("php", ["-d", "error_reporting=0", "-d", "memory_limit=512M", "/opt/homebrew/bin/wp", `--path=${WP_ROOT}`, "eval", script], { stdio: "ignore" });
    child.on("exit", (code) => (code === 0 ? resolve() : reject(new Error(`shopper session exited with ${code}`))));
    child.on("error", reject);
  });
}
import { anyImageId, createProduct, deleteProduct, visitorContext, waitForLive } from "./helpers";

/**
 * The shopper's view: an anonymous visitor on a product page sees other
 * shoppers' activity and stock changes without a refresh. The page is driven
 * by the `shopsocket/storefront` Interactivity store.
 */
test.describe("Storefront", () => {
  test("a shopper who holds an item sees the count, a toast when another shopper adds it, and a sell-out", async ({ browser, baseURL, requestUtils }) => {
    const product = await createProduct(requestUtils, "E2E Live Widget", 8);
    const visitor = await visitorContext(browser, baseURL);
    const page = await visitor.newPage();
    try {
      await page.goto(product.permalink);
      await waitForLive(page);
      expect((await page.evaluate(() => window.WPS!.state)).connected).toBe(true);

      const counter = page.locator(".shopsocket-in-carts");
      const toast = page.locator(".shopsocket-toast");
      await expect(counter).toHaveCount(1);
      await expect(counter).toBeHidden();
      await expect(page.locator(".shopsocket-stock")).toContainText("8 in stock");

      // The shopper adds it themselves: the counter reflects them at once (their
      // own add is never echoed back to them), with no toast for their own action.
      await page.locator(".single_add_to_cart_button").first().click();
      await expect(page.locator(".woocommerce-message, .wc-block-components-notice-banner").first()).toBeVisible({ timeout: 15_000 });
      await expect(counter).toBeVisible();
      await expect(counter).toContainText("1 shopper has this in their cart right now");
      await page.waitForTimeout(1500);
      await expect(toast).toBeHidden();

      // Another shopper adds the same product: now a toast fires (the viewer holds it)
      // and the count rises to two.
      const shopper = shopperSession(product.id, 6);
      await expect(toast).toBeVisible({ timeout: 15_000 });
      await expect(toast).toContainText("Someone just added this to their basket");
      await expect(toast.locator(".shopsocket-toast__image"), "no thumbnail for a product without an image").toBeHidden();
      await expect(counter).toContainText("2 shoppers have this in their cart right now");

      // When they empty it, the count drops back to just the viewer.
      await shopper;
      await expect(counter).toContainText("1 shopper has this in their cart right now", { timeout: 15_000 });

      // The product sells out elsewhere, then comes back.
      wp("eval", `wc_update_product_stock( ${product.id}, 0 );`);
      await expect(page.locator(".shopsocket-stock")).toContainText("Out of stock", { timeout: 15_000 });
      await expect(page.locator(".shopsocket-stock")).toHaveClass(/out-of-stock/);
      await expect(page.locator(".single_add_to_cart_button").first()).toBeDisabled();
      wp("eval", `wc_update_product_stock( ${product.id}, 3 );`);
      await expect(page.locator(".shopsocket-stock")).toContainText("3 in stock", { timeout: 15_000 });
      await expect(page.locator(".single_add_to_cart_button").first()).toBeEnabled();
    } finally {
      await visitor.close();
      await deleteProduct(requestUtils, product.id);
    }
  });

  test("the toast shows the product thumbnail when the product has an image", async ({ browser, baseURL, requestUtils }) => {
    const imageId = await anyImageId(requestUtils);
    test.skip(imageId === undefined, "the site has no image in its media library");
    const product = await createProduct(requestUtils, "E2E Pictured Widget", 8, 2, imageId);
    const visitor = await visitorContext(browser, baseURL);
    const page = await visitor.newPage();
    try {
      await page.goto(product.permalink);
      await waitForLive(page);
      const toast = page.locator(".shopsocket-toast");
      const image = toast.locator(".shopsocket-toast__image");

      // Hold it, then another shopper adds it: the toast carries the thumbnail.
      await page.locator(".single_add_to_cart_button").first().click();
      await expect(page.locator(".woocommerce-message, .wc-block-components-notice-banner").first()).toBeVisible({ timeout: 15_000 });
      const shopper = shopperSession(product.id, 4);
      await expect(toast).toBeVisible({ timeout: 15_000 });
      await expect(image).toBeVisible();
      await expect(image).toHaveAttribute("src", /\/wp-content\/uploads\/.+-100x100\./);
      await expect(image).toHaveJSProperty("naturalWidth", 100);
      await shopper;
    } finally {
      await visitor.close();
      await deleteProduct(requestUtils, product.id);
    }
  });

  test("a shopper who adds from the shop archive is armed for the toast without a reload", async ({ browser, baseURL, requestUtils }) => {
    const product = await createProduct(requestUtils, "E2E Archive Widget", 8);
    const visitor = await visitorContext(browser, baseURL);
    const page = await visitor.newPage();
    try {
      // Newest first, so the fixture is on the first page of the archive.
      await page.goto("/shop/?orderby=date");
      await waitForLive(page);
      const toast = page.locator(".shopsocket-toast");

      // The block product button adds through WooCommerce's Interactivity cart
      // store, which fires neither the jQuery event nor a wp.data change; the
      // plugin must still learn that the viewer now holds the product.
      await page.locator(`.add_to_cart_button[data-product_id="${product.id}"]`).click();
      await expect
        .poll(
          () =>
            page.evaluate(async (id) => {
              const res = await fetch("/wp-json/shopsocket/v1/basket-id", { credentials: "same-origin" });
              const viewer = (await res.json()) as { products?: number[] };
              return (viewer.products ?? []).includes(id);
            }, product.id),
          { timeout: 15_000 },
        )
        .toBe(true);

      const shopper = shopperSession(product.id, 4);
      await expect(toast).toBeVisible({ timeout: 15_000 });
      await expect(toast).toContainText("Someone just added E2E Archive Widget to their basket");
      await expect(toast.locator("a")).toHaveAttribute("href", product.permalink);
      await shopper;
    } finally {
      await visitor.close();
      await deleteProduct(requestUtils, product.id);
    }
  });

  test("a shopper who does not hold the item sees the count rise but gets no toast", async ({ browser, baseURL, requestUtils }) => {
    const product = await createProduct(requestUtils, "E2E NoHold Widget", 8);
    const visitor = await visitorContext(browser, baseURL);
    const page = await visitor.newPage();
    try {
      await page.goto(product.permalink);
      await waitForLive(page);
      const counter = page.locator(".shopsocket-in-carts");
      const toast = page.locator(".shopsocket-toast");
      await expect(counter).toBeHidden();

      // Another shopper adds it; the viewer is only browsing, not holding it.
      const shopper = shopperSession(product.id, 6);
      await expect(counter).toContainText("1 shopper has this in their cart right now", { timeout: 15_000 });
      await page.waitForTimeout(1500);
      await expect(toast, "no toast for an item the viewer does not hold").toBeHidden();
      await shopper;
    } finally {
      await visitor.close();
      await deleteProduct(requestUtils, product.id);
    }
  });

  test("a shopper's own add updates the count but never toasts, even before they have a session", async ({ browser, baseURL, requestUtils }) => {
    const product = await createProduct(requestUtils, "E2E Own Widget", 8);
    // A brand-new visitor: no WooCommerce session cookie until the first add,
    // so the server cannot yet tag the event with this shopper's hash.
    const visitor = await visitorContext(browser, baseURL);
    const watching = await visitor.newPage();
    const shopping = await visitor.newPage();
    try {
      await watching.goto(product.permalink);
      await waitForLive(watching);
      await shopping.goto(product.permalink);

      await shopping.locator(".single_add_to_cart_button").first().click();
      await expect(shopping.locator(".woocommerce-message, .wc-block-components-notice-banner").first()).toBeVisible({ timeout: 15_000 });

      const counter = watching.locator(".shopsocket-in-carts");
      await expect(counter).toBeVisible({ timeout: 15_000 });
      await expect(counter).toContainText("1 shopper has this in their cart right now");
      await watching.waitForTimeout(3000);
      await expect(watching.locator(".shopsocket-toast")).toBeHidden();
    } finally {
      await visitor.close();
      await deleteProduct(requestUtils, product.id);
    }
  });

  test("an anonymous visitor can refresh its token, so the connection survives past the 5-minute JWT", async ({ browser, baseURL }) => {
    // The client refreshes by POSTing to the token endpoint with no page context.
    // If that is refused for anonymous visitors, the storefront connection dies
    // when the JWT expires and never returns.
    const visitor = await visitorContext(browser, baseURL);
    try {
      const res = await visitor.request.post("/wp-json/wpsignal/v1/token");
      expect(res.status(), "anonymous token mint/refresh must be allowed on a storefront").toBe(200);
      const token: string = (await res.json()).token;
      const payload = JSON.parse(Buffer.from(token.split(".")[1], "base64url").toString());
      const prefixes = payload.allowed_channel_prefixes as string[];
      expect(payload.user_id).toBe("0");
      expect(prefixes.some((p) => p.includes(":woo:orders"))).toBe(false);
    } finally {
      await visitor.close();
    }
  });

  test("a visitor's token carries only public channels", async ({ browser, baseURL }) => {
    // The client consumes the server-minted token at boot, so read it from the
    // page source, where WordSocket localises it for the first connection.
    const visitor = await visitorContext(browser, baseURL);
    try {
      const html = await (await visitor.request.get("/shop/")).text();
      const match = html.match(/wpSignalConfig = (\{.*?\});/s);
      expect(match, "wpSignalConfig localised for visitors on the shop page").toBeTruthy();
      const token: string = JSON.parse(match![1]).token;
      const payload = JSON.parse(Buffer.from(token.split(".")[1], "base64url").toString());
      const prefixes = payload.allowed_channel_prefixes as string[];
      expect(payload.user_id).toBe("0");
      expect(prefixes.some((p) => p.includes(":woo:orders"))).toBe(false);
      expect(prefixes.some((p) => p.endsWith(":woo:stock"))).toBe(true);
      expect(prefixes.some((p) => p.endsWith(":woo:activity"))).toBe(true);
    } finally {
      await visitor.close();
    }
  });
});
