import "./env";
import { expect, test } from "@wordpress/e2e-test-utils-playwright";
import { WP_ROOT, wp } from "./env";
import { spawn } from "node:child_process";
import { addToCart, createProduct, deleteProduct, visitorContext, waitForLive } from "./helpers";

/**
 * The storefront settings (0.5): the ShopSocket tab on WordSocket's settings
 * page, the category exclusions, and the per-product choice. Needs WordSocket
 * 0.28 for the tab.
 */

const OPTION = "shopsocket_storefront";

/** One shopper as a background wp-cli process: add to cart, hold, empty. */
function shopperSession(productId: number, holdSeconds: number): Promise<void> {
  const script = `add_filter( 'shopsocket_activity_throttle', '__return_zero' ); WC()->cart->add_to_cart( ${productId}, 1 ); sleep( ${holdSeconds} ); WC()->cart->empty_cart();`;
  return new Promise((resolve, reject) => {
    const child = spawn("php", ["-d", "error_reporting=0", "-d", "memory_limit=512M", "/opt/homebrew/bin/wp", `--path=${WP_ROOT}`, "eval", script], { stdio: "ignore" });
    child.on("exit", (code) => (code === 0 ? resolve() : reject(new Error(`shopper session exited with ${code}`))));
    child.on("error", reject);
  });
}

function setProductChoice(productId: number, feature: "in_carts" | "activity", value: "on" | "off" | ""): void {
  const key = `_shopsocket_${feature}`;
  if (value === "") {
    wp("post", "meta", "delete", String(productId), key);
  } else {
    wp("post", "meta", "update", String(productId), key, value);
  }
}

test.describe("Storefront settings", () => {
  test.afterEach(() => {
    wp("option", "delete", OPTION);
  });

  test("the ShopSocket tab saves the site-wide switches and reloads them", async ({ admin, page }) => {
    await admin.visitAdminPage("admin.php", "page=wordsocket&tab=shopsocket");
    await expect(page.getByRole("tab", { name: "ShopSocket" })).toHaveAttribute("aria-selected", "true");

    // The two site-wide toggles, in order: the in-cart count, then the notification.
    // Found by position, since their labels change with their state.
    const toggles = () => page.locator(".shopsocket-tab").getByRole("checkbox");
    await expect(toggles()).toHaveCount(2);
    await expect(toggles().nth(0)).toBeChecked();
    await expect(toggles().nth(1)).toBeChecked();

    await toggles().nth(0).uncheck();
    await page.getByRole("button", { name: "Save changes" }).click();
    await expect(page.locator(".components-notice").getByText("Settings saved.")).toBeVisible();

    await page.reload();
    await expect(toggles()).toHaveCount(2);
    await expect(toggles().nth(0)).not.toBeChecked();
    await expect(toggles().nth(1)).toBeChecked();
    expect(JSON.parse(wp("option", "get", OPTION, "--format=json")).in_carts_enabled).toBe(false);
  });

  test("a product set to Off shows no count and raises no toast, even for a shopper who holds it", async ({ browser, baseURL, requestUtils }) => {
    const product = await createProduct(requestUtils, "E2E Quiet Widget", 8);
    setProductChoice(product.id, "in_carts", "off");
    setProductChoice(product.id, "activity", "off");
    const visitor = await visitorContext(browser, baseURL);
    const page = await visitor.newPage();
    try {
      await page.goto(product.permalink);
      await waitForLive(page);
      await expect(page.locator(".shopsocket-in-carts"), "the counter is not rendered at all").toHaveCount(0);

      // The viewer holds it, so a toast would normally follow another shopper's add.
      await addToCart(page);
      const shopper = shopperSession(product.id, 5);
      await page.waitForTimeout(4000);
      await expect(page.locator(".shopsocket-toast")).toBeHidden();
      await shopper;
    } finally {
      await visitor.close();
      await deleteProduct(requestUtils, product.id);
    }
  });

  test("a category excluded for both features hides them, and a product set to On brings them back", async ({ browser, baseURL, requestUtils }) => {
    const product = await createProduct(requestUtils, "E2E Category Widget", 8);
    const termId = Number(wp("term", "create", "product_cat", `E2E Quiet ${Date.now()}`, "--porcelain"));
    wp("post", "term", "set", String(product.id), "product_cat", String(termId), "--by=id");
    wp("option", "update", OPTION, JSON.stringify({ in_carts_enabled: true, activity_enabled: true, in_carts_excluded_categories: [termId], activity_excluded_categories: [termId] }), "--format=json");
    const visitor = await visitorContext(browser, baseURL);
    const page = await visitor.newPage();
    try {
      await page.goto(product.permalink);
      await expect(page.locator(".shopsocket-in-carts"), "excluded by category").toHaveCount(0);

      setProductChoice(product.id, "in_carts", "on");
      await page.reload();
      await waitForLive(page);
      const counter = page.locator(".shopsocket-in-carts");
      await expect(counter, "the product's own On beats the category").toHaveCount(1);
      const shopper = shopperSession(product.id, 5);
      await expect(counter).toContainText("1 shopper has this in their cart right now", { timeout: 15_000 });
      await shopper;
    } finally {
      await visitor.close();
      await deleteProduct(requestUtils, product.id);
      wp("term", "delete", "product_cat", String(termId));
    }
  });
});
