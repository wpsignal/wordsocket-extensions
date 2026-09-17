import "./env";
import { expect, test } from "@wordpress/e2e-test-utils-playwright";
import { WP_ROOT, wp } from "./env";
import { spawn } from "node:child_process";

/**
 * One shopper as a background wp-cli process: add to cart, hold for
 * `holdSeconds`, empty the cart. Two wp-cli calls would be two sessions.
 */
function shopperSession(productId: number, holdSeconds: number): Promise<void> {
  /*
   * Zero the 10s per-product publish throttle in this process, or the viewer's
   * own add moments earlier swallows this shopper's `woo.cart.added`.
   */
  const script = `add_filter( 'shopsocket_activity_throttle', '__return_zero' ); WC()->cart->add_to_cart( ${productId}, 1 ); sleep( ${holdSeconds} ); WC()->cart->empty_cart();`;
  return new Promise((resolve, reject) => {
    const child = spawn("php", ["-d", "error_reporting=0", "-d", "memory_limit=512M", "/opt/homebrew/bin/wp", `--path=${WP_ROOT}`, "eval", script], { stdio: "ignore" });
    child.on("exit", (code) => (code === 0 ? resolve() : reject(new Error(`shopper session exited with ${code}`))));
    child.on("error", reject);
  });
}
import { addToCart, anyImageId, createProduct, deleteProduct, visitorContext, waitForLive } from "./helpers";

/**
 * The shopper's view: an anonymous visitor on a product page sees other
 * shoppers' activity and stock changes without a refresh.
 *
 * WooCommerce's own add-to-cart button and quantity input are fed on a best
 * effort basis and not asserted here: their markup is WooCommerce's and varies
 * by theme. The Live Stock block is the markup this plugin owns.
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
      await expect(page.locator(".shopsocket-stock").first()).toContainText("8 in stock");

      // Their own add is never echoed back, yet the counter shows them at once, and no toast.
      await addToCart(page);
      await expect(counter).toBeVisible();
      await expect(counter).toContainText("1 shopper has this in their cart right now");
      await page.waitForTimeout(1500);
      await expect(toast).toBeHidden();

      // Another shopper adds the same product: the viewer holds it, so a toast, and two in carts.
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
      await expect(page.locator(".shopsocket-stock").first()).toContainText("Out of stock", { timeout: 15_000 });
      await expect(page.locator(".shopsocket-stock").first()).toHaveClass(/out-of-stock/);
      // The viewer holds it, so they are told at once, sticky, with a way to the cart.
      await expect(toast).toBeVisible();
      await expect(toast).toHaveClass(/is-error/);
      await expect(toast).toContainText("E2E Live Widget just sold out");
      await expect(toast.locator("a")).toHaveAttribute("href", /\/cart\/?$/);
      wp("eval", `wc_update_product_stock( ${product.id}, 3 );`);
      await expect(page.locator(".shopsocket-stock").first()).toContainText("3 in stock", { timeout: 15_000 });
      await expect(toast, "enough stock again clears the notice").toBeHidden();
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
      await addToCart(page);
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

      /*
       * The block button adds through WooCommerce's Interactivity cart store,
       * which fires neither the jQuery event nor a wp.data change.
       */
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

  test("a sell-out reaches the block cart as WooCommerce's own notice, without a reload", async ({ browser, baseURL, requestUtils }) => {
    const product = await createProduct(requestUtils, "E2E Gone Widget", 3);
    const visitor = await visitorContext(browser, baseURL);
    const page = await visitor.newPage();
    try {
      await page.goto(product.permalink);
      await waitForLive(page);
      await addToCart(page);

      await page.goto("/cart/");
      await waitForLive(page);
      await expect(page.getByText("E2E Gone Widget")).toBeVisible();

      // Someone else buys the last units: the cart re-reads itself and shows Woo's notice.
      wp("eval", `wc_update_product_stock( ${product.id}, 0 );`);
      await expect(page.locator(".wc-block-components-notice-banner.is-error")).toContainText("is out of stock and cannot be purchased", { timeout: 15_000 });
      await expect(page.locator(".shopsocket-toast")).toContainText("E2E Gone Widget just sold out");

      // WooCommerce 11.1 leaves the button enabled and refuses at checkout instead.
      await page.getByRole("link", { name: "Proceed to Checkout" }).click();
      await expect(page.locator(".wc-block-components-notice-banner.is-error").first()).toContainText(/out of stock|issues with the items/i, { timeout: 15_000 });
    } finally {
      await visitor.close();
      await deleteProduct(requestUtils, product.id);
    }
  });

  test("the Live Stock block follows stock and baskets on any page it is placed on", async ({ browser, baseURL, requestUtils }) => {
    const product = await createProduct(requestUtils, "E2E Block Widget", 8);
    const pageId = (await requestUtils.rest({
      method: "POST",
      path: "/wp/v2/pages",
      data: { title: "E2E Live Stock", status: "publish", content: `<!-- wp:shopsocket/live-stock {"productId":${product.id}} /-->` },
    })) as { id: number; link: string };
    const visitor = await visitorContext(browser, baseURL);
    const page = await visitor.newPage();
    try {
      await page.goto(pageId.link);
      await waitForLive(page);
      const block = page.locator(".shopsocket-live-stock");
      const availability = block.locator(".shopsocket-live-stock__availability");
      const left = block.locator(".shopsocket-live-stock__left");
      const counter = block.locator(".shopsocket-in-carts");
      await expect(availability).toHaveText("8 in stock");
      await expect(left).toHaveText("8 left");
      await expect(counter).toBeHidden();

      // Stock moves without a reload, including the units left.
      wp("eval", `wc_update_product_stock( ${product.id}, 2 );`);
      await expect(availability).toHaveText("2 in stock", { timeout: 15_000 });
      await expect(left).toHaveText("2 left");

      // Another shopper picks it up: the counter appears in the block.
      const shopper = shopperSession(product.id, 10);
      await expect(counter).toBeVisible({ timeout: 15_000 });
      await expect(counter).toContainText("1 shopper has this in their cart right now");

      /*
       * And it is there on load, not only after an event: on a page that is not
       * the product's own, the count the block rendered is the only source, and
       * the main state seeding used to reset it on block themes.
       */
      await page.reload();
      await expect(counter).toBeVisible();
      await expect(counter).toContainText("1 shopper has this in their cart right now");
      await shopper;

      // Sold out: the line says so and the units left go away.
      wp("eval", `wc_update_product_stock( ${product.id}, 0 );`);
      await expect(availability).toHaveText("Out of stock", { timeout: 15_000 });
      await expect(availability).toHaveClass(/out-of-stock/);
      await expect(left).toBeHidden();
    } finally {
      await visitor.close();
      await requestUtils.rest({ method: "DELETE", path: `/wp/v2/pages/${pageId.id}`, params: { force: true } });
      await deleteProduct(requestUtils, product.id);
    }
  });

  test("archive buttons switch between Add to cart and Read more as stock changes", async ({ browser, baseURL, requestUtils }) => {
    const product = await createProduct(requestUtils, "E2E Shelf Widget", 3);
    const visitor = await visitorContext(browser, baseURL);
    const page = await visitor.newPage();
    try {
      await page.goto("/shop/?orderby=date");
      await waitForLive(page);
      const original = page.locator(`[data-product_id="${product.id}"]`).first();
      const standIn = page.locator(`a[data-shopsocket-stand-in="${product.id}"]`);
      await expect(original).toContainText("Add to cart");

      // Sold out: the theme's button steps aside for a Read more link to the product.
      wp("eval", `wc_update_product_stock( ${product.id}, 0 );`);
      await expect(standIn).toHaveText("Read more", { timeout: 15_000 });
      await expect(standIn).toHaveAttribute("href", product.permalink);
      await expect(original).toBeHidden();

      // Back in stock: the theme's own button returns and the stand-in goes.
      wp("eval", `wc_update_product_stock( ${product.id}, 2 );`);
      await expect(original).toBeVisible({ timeout: 15_000 });
      await expect(standIn).toHaveCount(0);
    } finally {
      await visitor.close();
      await deleteProduct(requestUtils, product.id);
    }
  });

  test("a product that was out of stock at load gets an Add to cart link when it returns", async ({ browser, baseURL, requestUtils }) => {
    const product = await createProduct(requestUtils, "E2E Restock Widget", 0);
    const visitor = await visitorContext(browser, baseURL);
    const page = await visitor.newPage();
    try {
      await page.goto("/shop/?orderby=date");
      await waitForLive(page);
      const original = page.locator(`[data-product_id="${product.id}"]`).first();
      const standIn = page.locator(`a[data-shopsocket-stand-in="${product.id}"]`);
      await expect(original).toContainText("Read more");

      wp("eval", `wc_update_product_stock( ${product.id}, 2 );`);
      await expect(standIn).toHaveText("Add to cart", { timeout: 15_000 });
      await expect(standIn).toHaveAttribute("href", new RegExp(`add-to-cart=${product.id}$`));
      await expect(original).toBeHidden();
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
    /*
     * A brand-new visitor has no session cookie until the first add, so the
     * server cannot yet tag the event with this shopper's basket id.
     */
    const visitor = await visitorContext(browser, baseURL);
    const watching = await visitor.newPage();
    const shopping = await visitor.newPage();
    try {
      await watching.goto(product.permalink);
      await waitForLive(watching);
      await shopping.goto(product.permalink);

      await addToCart(shopping);

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
    /*
     * A refresh is a bare POST with no page context; refuse it for visitors and
     * the storefront connection dies when the JWT expires.
     */
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
    // The first token is localised into the page source, so read it from there.
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
      /*
       * Writing is presence on the carts namespace, nothing else: the public
       * channels cannot be spoofed from a browser.
       */
      const publish = payload.allowed_publish_prefixes as string[];
      expect(publish.map((p) => p.replace(/^site:[^:]+:/, ""))).toEqual(["woo:carts:"]);
    } finally {
      await visitor.close();
    }
  });
});
