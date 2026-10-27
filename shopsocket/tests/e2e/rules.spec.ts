import "./env";
import { expect, test } from "@wordpress/e2e-test-utils-playwright";
import { wp } from "./env";
import {
  addToCart,
  clearCarts,
  createProduct,
  deleteProduct,
  visitorContext,
  waitForLive,
} from "./helpers";

/*
 * ShopSocket's vocabulary on WordSocket's rules (0.8): the cart conditions
 * decide, the Alert action runs in the shopper's browser and posts to
 * ShopSocket's route, and the shipped moment is switched on from WordSocket's
 * Moments tab. Needs WordSocket 0.31 (the engine) on the target site.
 */

const RULES = "/wpsignal/v1/rules";
const OPTION = "shopsocket_storefront";

type RuleJson = Record<string, unknown>;

/** A rule that is true on one page kind once the cart holds something. */
function cartRule(
  id: string,
  kind: string,
  action: string,
  params: RuleJson,
  frequency = "visitor",
): RuleJson {
  return {
    id,
    name: `E2E ${id}`,
    enabled: true,
    frequency,
    conditions: {
      all: [
        { condition: "page_kind", op: "is", value: kind },
        { condition: "cart_has_items", op: "is", value: true },
      ],
    },
    actions: [{ action, ...params }],
  };
}

const ns = "\\WPSignal\\Extensions\\ShopSocket";

test.describe("Rules", () => {
  let cartUrl = "";
  let checkoutUrl = "";

  test.beforeAll(() => {
    cartUrl = wp("eval", "echo wc_get_cart_url();");
    checkoutUrl = wp("eval", "echo wc_get_checkout_url();");
  });

  test.afterEach(() => {
    wp("option", "delete", "wpsignal_rules");
    wp("option", "delete", OPTION);
  });

  test("Alert staff reaches the board from the checkout, with the cart", async ({
    admin,
    page,
    browser,
    baseURL,
    requestUtils,
  }) => {
    await requestUtils.rest({
      method: "POST",
      path: RULES,
      data: {
        rules: [
          cartRule("e2e-alert", "checkout", "alert_staff", {
            message: "Help at checkout",
          }),
        ],
      },
    });
    await admin.visitAdminPage("admin.php", "page=shopsocket");
    await waitForLive(page);
    const product = await createProduct(requestUtils, "E2E Alert Widget", 9);
    const visitor = await visitorContext(browser, baseURL);
    const shop = await visitor.newPage();
    try {
      await shop.goto(product.permalink);
      await waitForLive(shop);
      await addToCart(shop);
      await shop.goto(checkoutUrl);
      const alert = page.locator(".shopsocket-board__alert");
      await expect(alert).toBeVisible({ timeout: 20_000 });
      await expect(alert).toContainText("Help at checkout");
      await expect(alert, "where they are and what they hold").toContainText(
        "On checkout with E2E Alert Widget",
      );
      await alert.getByRole("button", { name: /dismiss|close/i }).click();
      await expect(alert).toHaveCount(0);
    } finally {
      await visitor.close();
      clearCarts(product.id);
      await deleteProduct(requestUtils, product.id);
    }
  });

  test("the shipped rule is listed on WordSocket's Moments tab, from ShopSocket, and its switch enables it", async ({
    admin,
    page,
  }) => {
    await admin.visitAdminPage("admin.php", "page=wordsocket&tab=moments");
    const row = page.locator(
      '.wpsignal-rule-row[data-rule-id="shopsocket-hidden-cart"]',
    );
    await expect(row).toBeVisible();
    await expect(row).toContainText("from shopsocket");
    await expect(row.locator(".wpsignal-rule-summary")).toContainText(
      "cart has items",
    );
    const toggle = row.getByRole("checkbox");
    await expect(toggle).not.toBeChecked();
    await toggle.check();
    await expect(
      page.locator(".components-snackbar").getByText("Moments saved."),
    ).toBeVisible();
    const rules = JSON.parse(
      wp("option", "get", "wpsignal_rules", "--format=json"),
    );
    const shipped = rules.rules.find(
      (r: { id: string }) => r.id === "shopsocket-hidden-cart",
    );
    expect(shipped?.enabled).toBe(true);
    expect(shipped?.source).toBe("shopsocket");

    // The ShopSocket tab points at the Moments tab instead of repeating it. Extension tabs register after the
    // first paint, so the URL's tab can lose to Connect; select it by hand.
    await admin.visitAdminPage("admin.php", "page=wordsocket&tab=shopsocket");
    await page.getByRole("tab", { name: "ShopSocket" }).click();
    const tab = page.locator(".shopsocket-tab");
    const link = tab.getByRole("link", { name: "Moments tab" });
    // The link sits in the Cart history card, which may start collapsed.
    if ((await link.count()) === 0)
      await tab.getByText("Cart history", { exact: true }).click();
    await expect(link).toHaveAttribute("href", /tab=moments$/);
    await expect(tab.getByText("Recovery", { exact: true })).toHaveCount(0);
  });
});
