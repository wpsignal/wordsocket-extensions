import { wp } from "./env";
import { expect, test } from "@wordpress/e2e-test-utils-playwright";
import {
  addToCart,
  cartOf,
  clearCarts,
  createOrder,
  createProduct,
  deleteOrder,
  deleteProduct,
  leaveCart,
  num,
  shopperCart,
  stat,
  visitorContext,
  visitorOf,
  waitForLive,
} from "./helpers";

const PAGE = "page=shopsocket";

/** WordSocket's History holds the carts (0.8), so the board has nothing to show without it. */
const HISTORY = "wpsignal_history_enabled";
let historyBefore = "";

test.describe("ShopSocket dashboard", () => {
  test.beforeAll(() => {
    try {
      historyBefore = wp("option", "get", HISTORY);
    } catch {
      historyBefore = ""; // the option does not exist: a site on its defaults
    }
    wp("option", "update", HISTORY, "1");
  });
  test.afterAll(() => {
    if (historyBefore) wp("option", "update", HISTORY, historyBefore);
    else wp("option", "delete", HISTORY);
  });
  test("the shoppers table shows the page each shopper is on, and a hidden tab", async ({
    admin,
    page,
    browser,
    baseURL,
    requestUtils,
  }) => {
    await admin.visitAdminPage("admin.php", PAGE);
    await waitForLive(page);
    const product = await createProduct(requestUtils, "E2E Page Widget", 5);
    const visitor = await visitorContext(browser, baseURL);
    try {
      const shop = await visitor.newPage();
      await shop.goto(product.permalink);
      await waitForLive(shop);
      const v = await shop.evaluate(() => window.WPS?.visitorId() ?? "");
      expect(v).not.toBe("");

      // A guest on the product page: their row names the product and links to its page.
      const row = page
        .locator(`.shopsocket-shoppers [data-visitor="${v}"]`)
        .locator("xpath=ancestor::tr[1]");
      await expect(row).toBeVisible({ timeout: 15_000 });
      await expect(row).toContainText("Guest");
      await expect(row.locator(".shopsocket-shoppers__page")).not.toHaveClass(/is-away/);
      const link = row.getByRole("link", { name: /E2E Page Widget/ });
      await expect(link).toBeVisible({ timeout: 15_000 });
      expect(new URL((await link.getAttribute("href")) ?? "").pathname).toBe(
        new URL(product.permalink).pathname,
      );

      // A hop to the shop, hidden at once (the merchant switching to the board): the row follows
      // the new page well within the old connection's leave grace, and the page is marked hidden.
      await shop.goto("/shop/");
      await waitForLive(shop);
      await shop.evaluate(() => {
        Object.defineProperty(document, "hidden", {
          configurable: true,
          get: () => true,
        });
        Object.defineProperty(document, "visibilityState", {
          configurable: true,
          get: () => "hidden",
        });
        document.dispatchEvent(new Event("visibilitychange"));
      });
      await expect(row.getByRole("link", { name: "Shop" })).toHaveAttribute(
        "href",
        /\/shop\/?$/,
        { timeout: 3_000 },
      );
      await expect(
        row.getByRole("link", { name: /E2E Page Widget/ }),
      ).toHaveCount(0);
      await expect(row.locator(".shopsocket-shoppers__page")).toHaveClass(/is-away/, { timeout: 3_000 });
      // The routes diagram: the guest's route runs from the product to Shop, dotted while hidden.
      const route = page.locator(`.shopsocket-journeys [data-visitor="${v}"]`);
      await expect(route.locator("title")).toHaveText(/Guest \u00b7 E2E Page Widget \u203a Shop/);
      await expect(route.locator(".shopsocket-journeys__tab")).toHaveClass(/is-away/);
      await shop.evaluate(() => {
        Object.defineProperty(document, "hidden", {
          configurable: true,
          get: () => false,
        });
        Object.defineProperty(document, "visibilityState", {
          configurable: true,
          get: () => "visible",
        });
        document.dispatchEvent(new Event("visibilitychange"));
      });
      await expect(row.locator(".shopsocket-shoppers__page")).not.toHaveClass(/is-away/, { timeout: 15_000 });
      // Back on the same page: the route is as it was, undotted, with no step added.
      await expect(route.locator(".shopsocket-journeys__tab")).not.toHaveClass(/is-away/);
      await expect(route.locator("title")).toHaveText(/Guest \u00b7 E2E Page Widget \u203a Shop$/);
    } finally {
      await visitor.close();
      await deleteProduct(requestUtils, product.id);
    }
  });

  test("a guest whose browser remembers a past order's email is named by it, on the site and once gone with a cart", async ({
    admin,
    page,
    browser,
    baseURL,
    requestUtils,
  }) => {
    test.setTimeout(120_000);
    await admin.visitAdminPage("admin.php", PAGE);
    await waitForLive(page);
    const product = await createProduct(requestUtils, "E2E Guess Widget", 5);
    const order = await createOrder(requestUtils, product.id, 1);
    const visitor = await visitorContext(browser, baseURL);
    try {
      // What WooCommerce Blocks leaves behind after a checkout: the form's addresses in localStorage.
      await visitor.addInitScript(() => {
        window.localStorage.setItem(
          "storeApiCartData",
          JSON.stringify({ billingAddress: { email: "Grace@example.com" } }),
        );
      });
      const shop = await visitor.newPage();
      await shop.goto(product.permalink);
      await waitForLive(shop);
      const v = await shop.evaluate(() => window.WPS?.visitorId() ?? "");
      const cell = page.locator(`.shopsocket-shoppers [data-visitor="${v}"]`);
      await expect(cell).toBeVisible({ timeout: 15_000 });
      // The remembered email, flagged, and never a link: a guess is not an account.
      await expect(cell).toContainText("grace@example.com", { timeout: 15_000 });
      await expect(cell).toContainText("Guest");
      await expect(cell).toContainText("1 order");
      await expect(cell.locator("a")).toHaveCount(0);
      await expect(
        page.locator(`.shopsocket-journeys [data-visitor="${v}"] title`),
      ).toHaveText(/Grace Hopper \(best guess\)/);

      // Gone with a cart: the abandoned carts table names them the same way.
      await addToCart(shop);
      await shop.close();
      const left = page.locator(`.shopsocket-abandoned [data-visitor="${v}"]`);
      await expect(left).toBeVisible({ timeout: 45_000 });
      await expect(left).toContainText("grace@example.com");
      await expect(left).toContainText("1 order");
      await expect(left.locator("a")).toHaveCount(0);
    } finally {
      await visitor.close();
      await deleteOrder(requestUtils, order.id);
      await deleteProduct(requestUtils, product.id);
    }
  });

  test("the shoppers table and the routes diagram are tabs, and so are the abandoned carts and products, the tables in view first", async ({
    admin,
    page,
    browser,
    baseURL,
    requestUtils,
  }) => {
    await admin.visitAdminPage("admin.php", PAGE);
    await waitForLive(page);
    const product = await createProduct(requestUtils, "E2E Panel Widget", 5);
    const visitor = await visitorContext(browser, baseURL);
    try {
      const shop = await visitor.newPage();
      await shop.goto(product.permalink);
      await waitForLive(shop);
      const v = await shop.evaluate(() => window.WPS?.visitorId() ?? "");
      const row = page.locator(`.shopsocket-shoppers [data-visitor="${v}"]`);
      const route = page.locator(`.shopsocket-journeys [data-visitor="${v}"]`);
      const diagram = page.locator(".shopsocket-journeys svg");
      const shoppers = page.getByRole("tab", { name: "Shoppers", exact: true });
      const routes = page.getByRole("tab", { name: "Journeys", exact: true });

      // The table is the tab in view; the diagram follows the shopper out of sight.
      await expect(shoppers).toHaveAttribute("aria-selected", "true");
      await expect(row).toBeVisible({ timeout: 15_000 });
      await expect(route).toHaveCount(1);
      await expect(diagram).toBeHidden();

      // The other tab swaps them, and back again.
      await routes.click();
      await expect(routes).toHaveAttribute("aria-selected", "true");
      await expect(diagram).toBeVisible();
      await expect(row).toBeHidden();
      await shoppers.click();
      await expect(row).toBeVisible();
      await expect(diagram).toBeHidden();

      // Right now holds nothing about a cart left behind: that is a section of its own.
      await expect(
        page.getByRole("tab", { name: "Left behind", exact: true }),
      ).toHaveCount(0);
      const section = page.locator(".shopsocket-board__abandoned");
      await expect(section.locator(".shopsocket-stat.is-abandoned-24")).toBeVisible();
      const carts = section.getByRole("tab", { name: "Carts", exact: true });
      const products = section.getByRole("tab", {
        name: "Products",
        exact: true,
      });
      const recovered = section.getByRole("tab", {
        name: "Recovered",
        exact: true,
      });
      await expect(carts).toHaveAttribute("aria-selected", "true");
      await expect(section.locator(".shopsocket-abandoned")).toBeVisible();
      await expect(section.locator(".shopsocket-left-products")).toBeHidden();
      // An empty table is a sentence, so the recovered orders are their table or the words that there are none.
      const recoveredOrders = section
        .locator(".shopsocket-recovered")
        .or(section.getByText(/^No cart left in .+ has become an order\.$/));
      await expect(recoveredOrders).toBeHidden();
      await recovered.click();
      await expect(recovered).toHaveAttribute("aria-selected", "true");
      await expect(recoveredOrders).toBeVisible();
      await expect(section.locator(".shopsocket-abandoned")).toBeHidden();
      await products.click();
      await expect(products).toHaveAttribute("aria-selected", "true");
      await expect(section.locator(".shopsocket-left-products")).toBeVisible();
      await expect(section.locator(".shopsocket-abandoned")).toBeHidden();
      // The pages most left from: their table, or the words that nobody left.
      const pages = section.getByRole("tab", { name: "Pages", exact: true });
      const leftPages = section
        .locator(".shopsocket-left-pages")
        .or(section.getByText(/^No shopper has left with a cart in .+\.$/));
      await expect(leftPages).toBeHidden();
      await pages.click();
      await expect(pages).toHaveAttribute("aria-selected", "true");
      await expect(leftPages).toBeVisible();
      await expect(section.locator(".shopsocket-left-products")).toBeHidden();
    } finally {
      await visitor.close();
      await deleteProduct(requestUtils, product.id);
    }
  });

  test("a shopper with two tabs is one row listing both pages, and one shopper on the diagram with two routes", async ({
    admin,
    page,
    browser,
    baseURL,
    requestUtils,
  }) => {
    await admin.visitAdminPage("admin.php", PAGE);
    await waitForLive(page);
    const product = await createProduct(requestUtils, "E2E Tabs Widget", 5);
    const visitor = await visitorContext(browser, baseURL);
    try {
      const first = await visitor.newPage();
      await first.goto(product.permalink);
      await waitForLive(first);
      const v = await first.evaluate(() => window.WPS?.visitorId() ?? "");
      const row = page
        .locator(`.shopsocket-shoppers [data-visitor="${v}"]`)
        .locator("xpath=ancestor::tr[1]");
      const pages = row.locator(".shopsocket-shoppers__page");
      await expect(pages).toHaveCount(1, { timeout: 15_000 });

      // A second tab of the same browser on the shop: still one row, now with both pages.
      const second = await visitor.newPage();
      await second.goto("/shop/");
      await waitForLive(second);
      await expect(pages).toHaveCount(2, { timeout: 15_000 });
      await expect(page.locator(`.shopsocket-shoppers [data-visitor="${v}"]`)).toHaveCount(1);
      await expect(row.getByRole("link", { name: /E2E Tabs Widget/ })).toBeVisible();
      await expect(row.getByRole("link", { name: "Shop" })).toBeVisible();
      const shopper = page.locator(`.shopsocket-journeys [data-visitor="${v}"]`);
      await expect(shopper).toHaveCount(1);
      await expect(shopper.locator(".shopsocket-journeys__tab")).toHaveCount(2);

      // The second tab hidden: its page is dashed and its route dotted, the other page as it was.
      await second.evaluate(() => {
        Object.defineProperty(document, "hidden", {
          configurable: true,
          get: () => true,
        });
        Object.defineProperty(document, "visibilityState", {
          configurable: true,
          get: () => "hidden",
        });
        document.dispatchEvent(new Event("visibilitychange"));
      });
      await expect(pages.filter({ hasText: "Shop" })).toHaveClass(/is-away/, { timeout: 5_000 });
      await expect(pages.filter({ hasText: "E2E Tabs Widget" })).not.toHaveClass(/is-away/);
      await expect(shopper.locator(".shopsocket-journeys__tab.is-away")).toHaveCount(1);

      // The second tab closed: one page again once the relay's leave has passed the grace.
      await second.close();
      await expect(pages).toHaveCount(1, { timeout: 15_000 });
    } finally {
      await visitor.close();
      await deleteProduct(requestUtils, product.id);
    }
  });

  test("the extension has a card on WordSocket's Extensions tab", async ({
    admin,
    page,
  }) => {
    await admin.visitAdminPage("admin.php", "page=wordsocket&tab=extensions");
    const card = page.locator(
      '.wpsignal-extension[data-extension="shopsocket"]',
    );
    await expect(card).toBeVisible();
    await expect(
      card.getByRole("heading", { name: "ShopSocket" }),
    ).toBeVisible();
    await expect(
      card.getByRole("link", { name: "View ShopSocket" }),
    ).toHaveAttribute("href", /page=shopsocket$/);
    // The site is connected to the rehearsal relay, so the card goes live.
    await expect(card.locator(".shopsocket-settings__status")).toContainText(
      "Live",
      { timeout: 20_000 },
    );
  });

  test("the sound button shows whether a new order chimes, and the browser remembers it", async ({
    admin,
    page,
  }) => {
    await admin.visitAdminPage("admin.php", PAGE);
    await waitForLive(page);
    const sound = page.locator(".shopsocket-board__sound");
    const stored = () =>
      page.evaluate(() => window.localStorage.getItem("shopsocket-sound"));
    try {
      // On until this browser turns it off: the button offers to mute.
      await expect(sound).toHaveAccessibleName("Mute the new order sound");
      const on = await sound.locator("svg").innerHTML();

      await sound.click();
      await expect(sound).toHaveAccessibleName("Play a sound on a new order");
      expect(await sound.locator("svg").innerHTML()).not.toBe(on);
      expect(await stored()).toBe("0");

      // Muted still after a reload.
      await page.reload();
      await waitForLive(page);
      await expect(sound).toHaveAccessibleName("Play a sound on a new order");

      await sound.click();
      await expect(sound).toHaveAccessibleName("Mute the new order sound");
      expect(await sound.locator("svg").innerHTML()).toBe(on);
    } finally {
      await page.evaluate(() =>
        window.localStorage.removeItem("shopsocket-sound"),
      );
    }
  });

  test("the abandoned carts follow the time frame chosen", async ({
    admin,
    page,
    requestUtils,
  }) => {
    const product = await createProduct(requestUtils, "E2E Frame Widget", 5);
    try {
      await admin.visitAdminPage("admin.php", PAGE);
      await waitForLive(page);
      const section = page.locator(".shopsocket-board__abandoned");
      const frame = (name: string) =>
        section.getByRole("radio", { name, exact: true });
      const figure = (hours: number) => num(stat(page, `abandoned-${hours}`));
      const row = section.locator(".shopsocket-abandoned tbody tr", {
        hasText: "E2E Frame Widget",
      });
      /*
       * The table lists ten carts a page, newest first: page forward until the cart is listed, and
       * back to the first page when it is not, so the next look reads every page again.
       */
      const turn = async (name: string) => {
        const button = section.getByRole("button", { name });
        if ((await button.count()) === 0 || !(await button.isEnabled()))
          return false;
        await button.click();
        return true;
      };
      const listed = async () => {
        for (let pages = 0; pages < 40; pages++) {
          if ((await row.count()) > 0) return true;
          if (!(await turn("Next page"))) break;
        }
        for (let pages = 0; pages < 40; pages++) {
          if (!(await turn("Previous page"))) break;
        }
        return false;
      };
      const choose = async (name: string) => {
        await frame(name).click();
        await expect(frame(name)).toBeChecked();
        await expect(
          section.locator(".shopsocket-abandoned-summary"),
        ).toHaveAttribute("aria-busy", "false");
      };

      // Every time frame has a figure of its own, the last 24 hours chosen. The site is shared, so the case asserts deltas.
      await expect(section.getByRole("radio")).toHaveCount(3);
      await expect(frame("Last 24 hours")).toBeChecked();
      const day = await figure(24)();
      const two = await figure(48)();
      const week = await figure(168)();

      // One cart left thirty hours ago: outside the last 24 hours, inside the last 48 and the last 7 days.
      leaveCart(product.id, 30);
      await page.reload();
      await waitForLive(page);
      await expect(frame("Last 24 hours")).toBeChecked();
      await expect.poll(figure(24)).toBe(day);
      await expect.poll(figure(48)).toBe(two + 1);
      await expect.poll(figure(168)).toBe(week + 1);
      await expect(row).toHaveCount(0);

      // Choosing a figure asks for its carts: the table then lists the cart.
      const asked = page.waitForRequest((request) =>
        /shopsocket\/v1\/dashboard.*[?&]hours=48\b/.test(
          decodeURIComponent(request.url()),
        ),
      );
      const caption = section.locator(".shopsocket-caption.is-carts");
      await expect(caption).toContainText("in the last 24 hours.");
      await choose("Last 48 hours");
      await asked;
      await expect(caption).toHaveText(
        new RegExp(`\\b${two + 1} carts left in the last 48 hours\\.$`),
      );
      await expect.poll(listed, { timeout: 15_000 }).toBe(true);
      await expect.poll(figure(24)).toBe(day);
      await expect.poll(figure(48)).toBe(two + 1);

      // A click anywhere on a figure chooses it too, and a progress bar shows while its carts are fetched.
      const week168 = (url: URL) =>
        /shopsocket\/v1\/dashboard.*[?&]hours=168\b/.test(
          decodeURIComponent(url.href),
        );
      await page.route(week168, async (route) => {
        await new Promise((done) => setTimeout(done, 1_500));
        await route.continue();
      });
      const loading = section.getByRole("progressbar", {
        name: "Loading the carts left in the last 7 days",
      });
      await expect(loading).toHaveCount(0);
      await page.locator(".shopsocket-stat.is-abandoned-168").click();
      await expect(frame("Last 7 days")).toBeChecked();
      await expect(loading).toBeVisible();
      await expect(loading).toHaveCount(0, { timeout: 15_000 });
      await page.unroute(week168);
      await expect(
        section.locator(".shopsocket-abandoned-summary"),
      ).toHaveAttribute("aria-busy", "false");
      await expect(caption).toHaveText(
        new RegExp(`\\b${week + 1} carts left in the last 7 days\\.$`),
      );
      await expect.poll(listed, { timeout: 15_000 }).toBe(true);
      await expect.poll(figure(168)).toBe(week + 1);

      await choose("Last 24 hours");
      await expect(row).toHaveCount(0);
      await expect.poll(figure(24)).toBe(day);
    } finally {
      clearCarts(product.id);
      await deleteProduct(requestUtils, product.id);
    }
  });

  test("the Left behind figure counts every cart left, past the fifty the table lists, less a shopper who is back", async ({
    admin,
    page,
    browser,
    baseURL,
    requestUtils,
  }) => {
    // A wait of up to the grace after the shopper's tab closes.
    test.setTimeout(120_000);
    const product = await createProduct(requestUtils, "E2E Count Widget", 5);
    const visitor = await visitorContext(browser, baseURL);
    /** The carts the server counted for the last 24 hours, as the page was printed. */
    const counted = () =>
      page.evaluate(
        () => window.shopSocket?.snapshot.abandoned_totals?.["24"]?.carts ?? 0,
      );
    try {
      await admin.visitAdminPage("admin.php", PAGE);
      await waitForLive(page);
      const leftBehind = stat(page, "abandoned-24");
      const worth = stat(page, "abandoned-24", "money");
      const carts0 = await num(leftBehind)();
      const worth0 = await num(worth)();

      // Sixty carts of $20 left two hours ago: ten more than the server lists for the last 24 hours.
      leaveCart(product.id, 2, 60);
      await page.reload();
      await waitForLive(page);
      await expect.poll(num(leftBehind), { timeout: 15_000 }).toBe(carts0 + 60);
      await expect(leftBehind).toHaveText(/^\d+$/);
      expect(await num(worth)()).toBe(worth0 + 1200);
      await expect(page.locator(".shopsocket-caption.is-carts")).toHaveText(
        new RegExp(
          `^The newest \\d+ of ${carts0 + 60} carts left in the last 24 hours\\.$`,
        ),
      );
      const counted0 = await counted();

      // A shopper on the site whose cart was also left three hours ago, past the rows listed: counted by the server, not left behind.
      const shop = await visitor.newPage();
      await shop.goto(product.permalink);
      await waitForLive(shop);
      await addToCart(shop);
      const v = await visitorOf(shop);
      await expect(shopperCart(page, v)).toBeVisible({ timeout: 15_000 });
      await expect.poll(() => cartOf(v), { timeout: 15_000 }).not.toBe("");
      leaveCart(product.id, 3, 1, cartOf(v));
      await page.reload();
      await waitForLive(page);
      await expect(shopperCart(page, v)).toBeVisible({ timeout: 15_000 });
      expect(await counted()).toBe(counted0 + 1);
      await expect.poll(num(leftBehind), { timeout: 15_000 }).toBe(carts0 + 60);
      expect(await num(worth)()).toBe(worth0 + 1200);

      // Their tab closes: the cart is left behind once more.
      await shop.close();
      await expect.poll(num(leftBehind), { timeout: 45_000 }).toBe(carts0 + 61);
    } finally {
      await visitor.close();
      clearCarts(product.id);
      await deleteProduct(requestUtils, product.id);
    }
  });

  test("live and abandoned follow the shopper's connection", async ({
    admin,
    page,
    browser,
    baseURL,
    requestUtils,
  }) => {
    await admin.visitAdminPage("admin.php", PAGE);
    await expect(
      page.getByRole("heading", { name: "ShopSocket", level: 1 }),
    ).toBeVisible();
    await waitForLive(page);
    await expect(page.locator(".shopsocket-connection-badge")).toHaveText("Live");

    const onlineTile = stat(page, "connections");
    await expect(onlineTile).toHaveText(/^\d+$/);
    await expect(page.locator(".shopsocket-stat.is-revenue")).toBeVisible();
    const liveShoppers = stat(page, "revenue", "detail");
    const abandonedShoppers = stat(page, "abandoned-24");
    const liveRevenue = stat(page, "revenue");

    // Other browsers may share the rehearsal stack, so assert deltas.
    const live0 = await num(liveShoppers)();
    const abandoned0 = await num(abandonedShoppers)();
    const revenue0 = await num(liveRevenue)();

    const product = await createProduct(requestUtils, "E2E Cart Widget", 20);

    /*
     * One visitor context (keeps the session cookie so a reopened tab is the
     * same cart), a page that adds the product and enters presence.
     */
    const visitor = await visitorContext(browser, baseURL);
    try {
      const shop = await visitor.newPage();
      await shop.goto(product.permalink);
      await waitForLive(shop);
      await addToCart(shop);

      // The cart is live, with its revenue, and nothing new is abandoned.
      await expect
        .poll(num(liveShoppers), { timeout: 15_000 })
        .toBeGreaterThanOrEqual(live0 + 1);
      await expect
        .poll(num(liveRevenue), { timeout: 10_000 })
        .toBeGreaterThan(revenue0);
      await expect
        .poll(num(abandonedShoppers), { timeout: 5_000 })
        .toBe(abandoned0);
      const liveWithShopper = await num(liveShoppers)();

      // The shopper's own row in the shoppers table holds the cart: one product, at its price of 9.99.
      const liveCart = shopperCart(page, await visitorOf(shop));
      await expect(liveCart).toContainText(/1 product, \D*10\b/, {
        timeout: 15_000,
      });

      // The top lists name the page the shopper is on and the product their cart holds, each linked.
      const topPage = page.locator(".shopsocket-top.is-pages li", {
        hasText: "E2E Cart Widget",
      });
      await expect(topPage).toBeVisible({ timeout: 15_000 });
      await expect(topPage.locator("a")).toHaveAttribute("href", product.permalink);
      const topProduct = page.locator(".shopsocket-top.is-products li", {
        hasText: "E2E Cart Widget",
      });
      await expect(topProduct).toBeVisible({ timeout: 15_000 });
      await expect(topProduct.locator(".shopsocket-top__count")).toHaveText("1");
      await expect(topProduct.locator("a")).toHaveAttribute("href", /post=\d+&action=edit/);

      // A second unit of the same product: still one product, worth twice as much.
      await shop.locator(".single_add_to_cart_button").first().click();
      await expect(liveCart).toContainText(/1 product, \D*20\b/, {
        timeout: 15_000,
      });

      /*
       * Browsing on does not flicker the board: each navigation closes the
       * socket before the next page rejoins, and the board holds the member
       * through that gap. Sample the live figure while the shopper moves.
       */
      const samples: number[] = [];
      const sampling = (async () => {
        for (let i = 0; i < 16; i++) {
          samples.push(await num(liveShoppers)());
          await page.waitForTimeout(250);
        }
      })();
      await shop.goto("/my-account/");
      await shop.goto("/");
      await sampling;
      await waitForLive(shop);
      expect(
        Math.min(...samples),
        `live shoppers dipped while browsing: ${samples.join(",")}`,
      ).toBeGreaterThanOrEqual(liveWithShopper);
      await expect(liveCart).toBeVisible();

      /*
       * Closing the tab drops the connection: the relay reports the leave, the
       * board waits out the grace in case it is a navigation, then the cart
       * moves to abandoned. No beacon and no server-side timeout.
       */
      await shop.close();
      await expect
        .poll(num(abandonedShoppers), { timeout: 20_000 })
        .toBeGreaterThanOrEqual(abandoned0 + 1);
      await expect
        .poll(num(liveShoppers), { timeout: 20_000 })
        .toBeLessThanOrEqual(liveWithShopper - 1);
      await expect(liveCart).toHaveCount(0, { timeout: 20_000 });
      await expect(topProduct).toHaveCount(0);

      /*
       * Reopening the tab (same session, same cart) reconnects and it goes live
       * again, without touching the cart.
       */
      const reopened = await visitor.newPage();
      await reopened.goto(product.permalink);
      await waitForLive(reopened);
      await expect
        .poll(num(liveShoppers), { timeout: 10_000 })
        .toBeGreaterThanOrEqual(liveWithShopper);
      await expect
        .poll(num(abandonedShoppers), { timeout: 10_000 })
        .toBe(abandoned0);
      await expect(liveCart).toBeVisible({ timeout: 10_000 });

      /*
       * The row store is a cache: lose this shopper's row, and the next page
       * load writes it back from their real cart, so the cart is on their row again.
       */
      clearCarts(product.id);
      // The board re-reads its rows when the tab comes back into view; no need to wait for its poll.
      await page.evaluate(() =>
        document.dispatchEvent(new Event("visibilitychange")),
      );
      await expect(liveCart).toHaveCount(0, { timeout: 10_000 });
      await reopened.reload();
      await waitForLive(reopened);
      await expect(liveCart).toBeVisible({ timeout: 15_000 });
    } finally {
      await visitor.close();
      clearCarts(product.id);
      await deleteProduct(requestUtils, product.id);
    }
  });

  test("emptying the cart from the drawer takes it out of the carts held without a reload", async ({
    admin,
    page,
    browser,
    baseURL,
    requestUtils,
  }) => {
    await admin.visitAdminPage("admin.php", PAGE);
    await waitForLive(page);
    const liveShoppers = stat(page, "revenue", "detail");
    const live0 = await num(liveShoppers)();
    const product = await createProduct(requestUtils, "E2E Emptied Widget", 12);
    const visitor = await visitorContext(browser, baseURL);
    try {
      const shop = await visitor.newPage();
      await shop.goto(product.permalink);
      await waitForLive(shop);
      await addToCart(shop);
      await expect
        .poll(num(liveShoppers), { timeout: 15_000 })
        .toBeGreaterThanOrEqual(live0 + 1);

      /*
       * The shopper empties the cart from the mini-cart drawer on the same
       * page, the Interactivity store's path with no wp.data on the page: its
       * presence drops the cart id, so the board lets the shopper go without
       * any page reloading.
       */
      await shop.locator(".wc-block-mini-cart__button").first().click();
      await shop
        .locator(".wc-block-mini-cart__drawer")
        .getByRole("button", { name: /Remove/ })
        .first()
        .click();
      await expect
        .poll(num(liveShoppers), { timeout: 15_000 })
        .toBeLessThanOrEqual(live0);
      await expect(shopperCart(page, await visitorOf(shop))).toHaveCount(0, {
        timeout: 15_000,
      });
    } finally {
      await visitor.close();
      clearCarts(product.id);
      await deleteProduct(requestUtils, product.id);
    }
  });

  test("a logged-in shopper's own cart shows live, not abandoned", async ({
    admin,
    page,
    requestUtils,
  }) => {
    const product = await createProduct(requestUtils, "E2E LoggedIn Cart", 20);
    await admin.visitAdminPage("admin.php", PAGE);
    await waitForLive(page);
    const liveShoppers = stat(page, "revenue", "detail");
    const abandonedShoppers = stat(page, "abandoned-24");
    const live0 = await num(liveShoppers)();
    const abandoned0 = await num(abandonedShoppers)();

    // Same logged-in browser, a second tab is the shop.
    const shop = await page.context().newPage();
    try {
      await shop.goto(product.permalink);
      await waitForLive(shop);
      await addToCart(shop);

      // The logged-in shopper's own cart is live, and nothing new is abandoned.
      await expect
        .poll(num(liveShoppers), { timeout: 15_000 })
        .toBeGreaterThanOrEqual(live0 + 1);
      await expect
        .poll(num(abandonedShoppers), { timeout: 5_000 })
        .toBe(abandoned0);
    } finally {
      await shop.close();
      clearCarts(product.id);
      await deleteProduct(requestUtils, product.id);
    }
  });

  test("users online counts distinct visitors and dedupes a shopper's tabs", async ({
    admin,
    page,
    browser,
    baseURL,
  }) => {
    await admin.visitAdminPage("admin.php", PAGE);
    await waitForLive(page);

    const usersTile = stat(page, "users");
    // The users-online tile as a number, as a poll callback.
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
      expect(await users(), "a shopper's second tab is not a new user").toBe(
        withShopper,
      );

      // A different browser is a different visitor id: one more user.
      const other = await visitorContext(browser, baseURL);
      const otherTab = await other.newPage();
      await otherTab.goto("/shop/");
      await waitForLive(otherTab);
      await expect.poll(users, { timeout: 10_000 }).toBe(withShopper + 1);

      // Closing the first browser (both its tabs) drops exactly one user, once the leave grace is out.
      await shopper.close();
      await expect.poll(users, { timeout: 20_000 }).toBe(withShopper);
      await other.close();
    } finally {
      if (shopper.pages().length) await shopper.close().catch(() => {});
    }
  });

  test("a new order lands on the board and among the alerts, which minimize to a count, and status changes update it in place", async ({
    admin,
    page,
    requestUtils,
  }) => {
    await admin.visitAdminPage("admin.php", PAGE);
    await waitForLive(page);

    const product = await createProduct(requestUtils, "E2E Board Widget", 10);
    const order = await createOrder(requestUtils, product.id, 2);
    try {
      const row = page
        .getByRole("row")
        .filter({ has: page.locator(`[data-order-id="${order.id}"]`) });
      await expect(row).toBeVisible({ timeout: 15_000 });
      await expect(row).toContainText("Grace H.");
      await expect(row.locator(".shopsocket-status")).toHaveText("Processing");
      await expect(page.locator("[data-order-id]").first()).toHaveAttribute(
        "data-order-id",
        String(order.id),
      );

      // The order is an alert at the top too, with who placed it.
      const notice = page.locator(".shopsocket-board__order", {
        hasText: `#${order.id}`,
      });
      await expect(notice).toBeVisible();
      await expect(notice).toContainText("Grace H.");
      await expect(notice).toContainText("2 items");
      await expect(notice, "the total as the order stands").toContainText("19.98");
      // The alerts minimize to their count beside the bell, and come back.
      const toggle = page.locator(".shopsocket-board__alerts-toggle");
      await expect(toggle).toHaveAttribute("aria-expanded", "true");
      await expect(toggle).toHaveAttribute("aria-label", /^Hide \d+ alerts?$/);
      await expect(toggle).not.toContainText("(");
      await toggle.click();
      await expect(notice).toHaveCount(0);
      await expect(toggle).toHaveText(/^\s*\(\d+\)$/);
      await expect(toggle).toHaveAttribute("aria-expanded", "false");
      await expect(toggle).toHaveAttribute("aria-label", /^Show \d+ alerts?$/);
      await toggle.click();
      await expect(notice).toBeVisible();
      // Dismissed, the order is one alert fewer and still on the table.
      await notice.getByRole("button", { name: /dismiss|close/i }).click();
      await expect(notice).toHaveCount(0);
      await expect(row).toBeVisible();

      await requestUtils.rest({
        method: "PUT",
        path: `/wc/v3/orders/${order.id}`,
        data: { status: "completed" },
      });
      await expect(row.locator(".shopsocket-status")).toHaveText("Completed", {
        timeout: 15_000,
      });

      // DataViews: the search box narrows the board to the customer.
      await page
        .getByRole("searchbox", { name: "Search orders" })
        .fill("Grace");
      await expect(
        page.getByRole("row").filter({ has: page.locator("[data-order-id]") }),
      ).toHaveCount(1);
    } finally {
      await deleteOrder(requestUtils, order.id);
      await deleteProduct(requestUtils, product.id);
    }
  });

  /*
   * The relay stores no events, so an order placed while the board could not
   * hear (its connection down, or the publish never leaving WordPress) never
   * arrives as one. The board's refresh asks the server for what changed since
   * its last look instead. Here the publish is made to fail and the board's
   * refreshes are cut off while the order is placed, which is the same silence
   * from where the board sits; then the refreshes are let through again.
   */
  test("an order the board never heard about appears on its next refresh", async ({
    admin,
    page,
    requestUtils,
  }) => {
    await admin.visitAdminPage("admin.php", PAGE);
    await waitForLive(page);

    const product = await createProduct(requestUtils, "E2E Missed Widget", 10);
    const refreshes = /shopsocket\/v1\/dashboard/;
    let orderId = 0;
    try {
      await page.route(refreshes, (route) => route.abort());
      orderId = Number(
        wp(
          "eval",
          `add_filter( 'pre_http_request', static fn() => new WP_Error( 'offline', 'relay unreachable' ) );
          $order = wc_create_order( array( 'customer_id' => 0 ) );
          $order->add_product( wc_get_product( ${product.id} ), 1 );
          $order->set_billing_first_name( 'Missed' );
          $order->set_billing_last_name( 'Order' );
          $order->calculate_totals();
          $order->set_status( 'processing' );
          $order->save();
          echo $order->get_id();`,
        ),
      );
      expect(orderId).toBeGreaterThan(0);

      const row = page
        .getByRole("row")
        .filter({ has: page.locator(`[data-order-id="${orderId}"]`) });
      // No event reached the board and it cannot refresh, so nothing shows.
      await page.evaluate(() =>
        document.dispatchEvent(new Event("visibilitychange")),
      );
      await page.waitForTimeout(3_000);
      await expect(row).toHaveCount(0);

      // Back in touch: coming back to the tab refreshes at once, as a reconnect and the 30 second poll do.
      await page.unroute(refreshes);
      await page.evaluate(() =>
        document.dispatchEvent(new Event("visibilitychange")),
      );
      await expect(row).toBeVisible({ timeout: 15_000 });
      await expect(row).toContainText("Missed O.");
      await expect(row.locator(".shopsocket-status")).toHaveText("Processing");
      await expect(page.locator("[data-order-id]").first()).toHaveAttribute(
        "data-order-id",
        String(orderId),
      );

      // A status change the board also never heard is picked up the same way, in place.
      wp(
        "eval",
        `add_filter( 'pre_http_request', static fn() => new WP_Error( 'offline', 'relay unreachable' ) ); wc_get_order( ${orderId} )->update_status( 'completed' );`,
      );
      await page.evaluate(() =>
        document.dispatchEvent(new Event("visibilitychange")),
      );
      await expect(row.locator(".shopsocket-status")).toHaveText("Completed", {
        timeout: 15_000,
      });
      await expect(page.locator(`[data-order-id="${orderId}"]`)).toHaveCount(1);
    } finally {
      await page.unroute(refreshes).catch(() => {});
      if (orderId) await deleteOrder(requestUtils, orderId);
      await deleteProduct(requestUtils, product.id);
    }
  });

  test("selling out a product raises a stock notice of its own, dismissed until it runs out again", async ({
    admin,
    page,
    requestUtils,
  }) => {
    await admin.visitAdminPage("admin.php", PAGE);
    await waitForLive(page);
    const product = await createProduct(
      requestUtils,
      "E2E Scarce Widget",
      2,
      2,
    );
    const orders = [await createOrder(requestUtils, product.id, 2)];
    try {
      const notice = page.locator(".shopsocket-board__stock", {
        hasText: "E2E Scarce Widget",
      });
      await expect(notice).toContainText("E2E Scarce Widget is out of stock", {
        timeout: 15_000,
      });
      await expect(notice.getByRole("link")).toHaveAttribute(
        "href",
        new RegExp(`/wp-admin/post\\.php\\?post=${product.id}&action=edit$`),
      );

      // Dismissed: the notice goes, and nothing brings it back but the product's next stock event.
      await notice.getByRole("button", { name: /dismiss|close/i }).click();
      await expect(notice).toHaveCount(0);

      // Restocked and sold out again: the notice is back.
      await requestUtils.rest({
        method: "PUT",
        path: `/wc/v3/products/${product.id}`,
        data: { stock_quantity: 2 },
      });
      orders.push(await createOrder(requestUtils, product.id, 2));
      await expect(notice).toContainText("E2E Scarce Widget is out of stock", {
        timeout: 15_000,
      });

      // A restock clears it: stock changes are public events staff also receive.
      await requestUtils.rest({
        method: "PUT",
        path: `/wc/v3/products/${product.id}`,
        data: { stock_quantity: 5 },
      });
      await expect(notice).toHaveCount(0, { timeout: 15_000 });
    } finally {
      for (const order of orders) await deleteOrder(requestUtils, order.id);
      await deleteProduct(requestUtils, product.id);
    }
  });

  // Cart history (0.7): the Away figure, the Left behind figure, and the order that takes the cart off the board.
  test("a hidden tab moves the shopper to Tab hidden, a closed tab moves the cart to Left behind, and its order moves it to Recovered orders", async ({
    admin,
    page,
    browser,
    baseURL,
    requestUtils,
  }) => {
    // Three waits of up to the grace plus a checkout: well past the default 30 s.
    test.setTimeout(120_000);
    await admin.visitAdminPage("admin.php", PAGE);
    await waitForLive(page);
    const liveShoppers = stat(page, "revenue", "detail");
    const awayShoppers = stat(page, "idle");
    const away0 = await num(awayShoppers)();
    const leftBehind = stat(page, "abandoned-24");
    const left0 = await num(leftBehind)();
    const product = await createProduct(requestUtils, "E2E History Widget", 20);

    const visitor = await visitorContext(browser, baseURL);
    let orderId = 0;
    try {
      const shop = await visitor.newPage();
      await shop.goto(product.permalink);
      await waitForLive(shop);
      await addToCart(shop);
      await expect(shopperCart(page, await visitorOf(shop))).toBeVisible({
        timeout: 15_000,
      });

      // Hidden: the journey's last step is `away`, so the shopper counts under Tab hidden and still holds the cart.
      const liveWithShopper = await num(liveShoppers)();
      await shop.evaluate(() => {
        Object.defineProperty(document, "hidden", {
          configurable: true,
          get: () => true,
        });
        Object.defineProperty(document, "visibilityState", {
          configurable: true,
          get: () => "hidden",
        });
        document.dispatchEvent(new Event("visibilitychange"));
      });
      await expect.poll(num(awayShoppers), { timeout: 15_000 }).toBe(away0 + 1);
      expect(await num(liveShoppers)()).toBe(liveWithShopper);
      await shop.evaluate(() => {
        Object.defineProperty(document, "hidden", {
          configurable: true,
          get: () => false,
        });
        Object.defineProperty(document, "visibilityState", {
          configurable: true,
          get: () => "visible",
        });
        document.dispatchEvent(new Event("visibilitychange"));
      });
      await expect.poll(num(awayShoppers), { timeout: 15_000 }).toBe(away0);

      // Closed: after the grace the cart counts as left behind.
      await shop.close();
      await expect
        .poll(num(leftBehind), { timeout: 45_000 })
        .toBeGreaterThanOrEqual(left0 + 1);
      // The abandoned carts table lists the cart: what it held, who, its value and when. No order has recovered it.
      const row = page.locator(".shopsocket-abandoned tbody tr", {
        hasText: "E2E History Widget",
      });
      await expect(row).toBeVisible({ timeout: 15_000 });
      await expect(row.locator("td").nth(2)).not.toBeEmpty();
      await expect(row.locator("td")).toHaveCount(4);
      const recoveredRow = page.locator(".shopsocket-recovered tbody tr", {
        hasText: "E2E History Widget",
      });
      await expect(recoveredRow).toHaveCount(0);

      // The shopper comes back and checks out: the cart is an order, and no longer left behind.
      const back = await visitor.newPage();
      await back.goto("/checkout/");
      await back
        .getByLabel(/first name/i)
        .first()
        .fill("Grace");
      await back
        .getByLabel(/last name/i)
        .first()
        .fill("Hopper");
      await back
        .getByLabel(/^address$/i)
        .first()
        .fill("1 Main St");
      await back.getByLabel(/city/i).first().fill("Arlington");
      await back
        .getByLabel(/postcode|zip/i)
        .first()
        .fill("22201");
      await back.getByLabel(/email/i).first().fill("grace@example.com");
      await back.getByRole("button", { name: /place order/i }).click();
      await expect(back).toHaveURL(/order-received/, { timeout: 30_000 });
      orderId = Number(back.url().match(/order-received\/(\d+)/)?.[1] ?? 0);
      await back.close();
      await expect(page.locator(`[data-order-id="${orderId}"]`)).toBeVisible({
        timeout: 45_000,
      });
      await expect
        .poll(num(leftBehind), { timeout: 45_000 })
        .toBeLessThanOrEqual(left0);
      // The cart is no longer among those left: it is under Recovered orders, with the order it became and what that was worth.
      await expect(row).toHaveCount(0, { timeout: 45_000 });
      await page
        .locator(".shopsocket-board__abandoned")
        .getByRole("tab", { name: "Recovered", exact: true })
        .click();
      await expect(recoveredRow).toBeVisible({ timeout: 15_000 });
      await expect(recoveredRow.locator("td").first().locator("a")).toHaveText(
        `#${orderId}`,
      );
      await expect(recoveredRow.locator("td").nth(3)).toHaveText(/\d/);
      await expect(
        page.locator(".shopsocket-caption.is-recovered"),
      ).toContainText("in the last 24 hours");
    } finally {
      await visitor.close();
      clearCarts(product.id);
      // The order too, or the next run's "Grace" search finds more than one.
      if (orderId) await deleteOrder(requestUtils, orderId);
      await deleteProduct(requestUtils, product.id);
    }
  });
});
