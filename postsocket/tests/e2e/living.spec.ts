import "./env";
import { expect, test } from "@wordpress/e2e-test-utils-playwright";
import { deletePost, keepSettings, paragraph, saveSettings, updatePost, visitorContext, waitForLive } from "./helpers";

/**
 * A reader of an ordinary post of an enabled type sees a saved edit and a new title land in place, with the
 * blocks that did not change (an iframe) left alone, a large edit fetched instead of carried, and a
 * post meta change land in the elements its live fields name, outside the content. A post with a refresh
 * selector has that part of its page fetched again and morphed in place for a change
 * outside its content, and a content edit applied inline with no page request. A live field whose element is
 * not on the page is put there by the same refresh, and filled in place from then on. A duotone arrives with the CSS
 * and the SVG filter WordPress would have printed on a page load. A change to a field on the
 * reload list reloads the page once its URL answers a GET, and not while it fails. A post of an enabled type
 * is live only once its Live editing switch is on.
 */
test.describe("living articles", () => {
  let post = 0;
  const iframe = '<!-- wp:html --><iframe src="about:blank" id="postsocket-e2e-frame" width="300" height="80"></iframe><!-- /wp:html -->';

  let restore = (): void => undefined;

  test.beforeAll(async ({ requestUtils }) => {
    restore = keepSettings();
    await saveSettings(requestUtils, { living_types: ["post"] });
    post = ((await requestUtils.rest({
      method: "POST",
      path: "/wp/v2/posts",
      data: { title: "E2E living article", content: paragraph("First version.") + iframe + paragraph("Closing line."), status: "publish", postsocket_live: true },
    })) as { id: number }).id;
  });

  test.afterAll(async ({ requestUtils }) => {
    await deletePost(requestUtils, "posts", post);
    restore();
  });

  test("an edit lands in place and the iframe survives; a large edit is fetched", async ({ browser, requestUtils, baseURL }) => {
    const record = (await requestUtils.rest({ path: `/wp/v2/posts/${post}` })) as { link: string };
    const reader = await (await visitorContext(browser, baseURL)).newPage();
    await reader.goto(record.link);
    await waitForLive(reader);
    const wrapper = reader.locator("[data-postsocket-post]");
    await expect(wrapper).toContainText("First version.");
    await reader.evaluate(() => {
      (document.querySelector("#postsocket-e2e-frame") as HTMLElement & { __keep?: string }).__keep = "kept";
    });

    await updatePost(requestUtils, "posts", post, { content: paragraph("Second version.") + iframe + paragraph("Closing line, edited.") });
    await expect(wrapper).toContainText("Second version.", { timeout: 15_000 });
    await expect(wrapper).toContainText("Closing line, edited.");
    expect(await reader.evaluate(() => (document.querySelector("#postsocket-e2e-frame") as HTMLElement & { __keep?: string })?.__keep)).toBe("kept");

    await updatePost(requestUtils, "posts", post, { title: "E2E living article, renamed" });
    const heading = reader.locator(`[data-postsocket-title="${post}"]`);
    await expect(heading).toHaveText("E2E living article, renamed", { timeout: 15_000 });
    await expect(reader).toHaveTitle(/E2E living article, renamed/);
    await expect(wrapper).toContainText("Closing line, edited.");

    await updatePost(requestUtils, "posts", post, { content: paragraph("Long text. ".repeat(4000) + "THE END") });
    await expect(wrapper).toContainText("THE END", { timeout: 25_000 });
    await reader.context().close();
  });

  test("a duotone applied to an image arrives with its filter", async ({ browser, requestUtils, baseURL }) => {
    const record = (await requestUtils.rest({ path: `/wp/v2/posts/${post}` })) as { link: string };
    const reader = await (await visitorContext(browser, baseURL)).newPage();
    await reader.goto(record.link);
    await waitForLive(reader);
    const image = (duotone: string): string =>
      `<!-- wp:image {"className":"postsocket-e2e-image"${duotone}} --><figure class="wp-block-image postsocket-e2e-image"><img src="${baseURL}/wp-includes/images/w-logo-blue.png" alt=""/></figure><!-- /wp:image -->`;

    await updatePost(requestUtils, "posts", post, { content: paragraph("With a picture.") + image("") });
    const img = reader.locator("[data-postsocket-post] .postsocket-e2e-image img");
    await expect(img).toBeVisible({ timeout: 15_000 });
    expect(await img.evaluate((el) => getComputedStyle(el).filter)).toBe("none");

    await updatePost(requestUtils, "posts", post, { content: paragraph("With a picture.") + image(',"style":{"color":{"duotone":["#000000","#7f7f7f"]}}') });
    await expect.poll(() => img.evaluate((el) => getComputedStyle(el).filter), { timeout: 15_000 }).toContain("url(");
    const filterId = await reader.locator("[data-postsocket-post] .postsocket-e2e-image").evaluate((el) => Array.from(el.classList).find((name) => name.startsWith("wp-duotone-")) ?? "");
    expect(filterId).not.toBe("");
    await expect(reader.locator(`svg filter[id="${filterId}"]`)).toHaveCount(1);
    await reader.context().close();
  });

  test("a post meta change lands in its live fields outside the content", async ({ browser, requestUtils, baseURL }) => {
    const record = (await requestUtils.rest({ path: `/wp/v2/posts/${post}` })) as { link: string };
    const reader = await (await visitorContext(browser, baseURL)).newPage();
    await reader.goto(record.link);
    await waitForLive(reader);
    const note = reader.locator('[data-postsocket-field="postsocket_test_note"]');
    const badge = reader.locator(".postsocket-test-badge");
    await expect(note).toHaveText("");
    await expect(badge).toBeEmpty();
    await reader.evaluate(() => {
      document.addEventListener("postsocket:updated", (event) => {
        (window as unknown as { __updated?: unknown }).__updated = (event as CustomEvent).detail;
      });
    });

    await updatePost(requestUtils, "posts", post, { meta: { postsocket_test_note: "Half time: 1-0" } });
    await expect(note).toHaveText("Half time: 1-0", { timeout: 15_000 });
    await expect(badge.locator("strong")).toHaveText("Noted");
    const detail = (await reader.evaluate(() => (window as unknown as { __updated?: unknown }).__updated)) as { id: number; fields: Record<string, string> };
    expect(detail.id).toBe(post);
    expect(detail.fields.postsocket_test_note).toBe("Half time: 1-0");

    await updatePost(requestUtils, "posts", post, { meta: { postsocket_test_note: "" } });
    await expect(note).toHaveText("", { timeout: 15_000 });
    await expect(badge).toBeEmpty();
    await reader.context().close();
  });

  test("a post with a refresh selector has that part of the page morphed from a fresh copy", async ({ browser, requestUtils, baseURL }) => {
    test.setTimeout(90_000);
    const fresh = (await requestUtils.rest({
      method: "POST",
      path: "/wp/v2/posts",
      data: { title: "E2E refreshed article", content: paragraph("Body stays.") + iframe, status: "publish", postsocket_live: true, meta: { postsocket_test_refresh: true, postsocket_test_note: "Before" } },
    })) as { id: number; link: string };
    try {
      const reader = await (await visitorContext(browser, baseURL)).newPage();
      await reader.goto(fresh.link);
      await waitForLive(reader);
      const outside = reader.locator("main .postsocket-test-outside").first();
      await expect(outside).toHaveText("Before");
      await expect(reader.locator("[data-postsocket-post]")).toHaveAttribute("data-postsocket-refresh", "main");
      await reader.evaluate(() => {
        (window as unknown as { __loaded?: string }).__loaded = "once";
        (document.querySelector("#postsocket-e2e-frame") as HTMLElement & { __keep?: string }).__keep = "kept";
        document.addEventListener("postsocket:updated", (event) => {
          (window as unknown as { __updated?: unknown }).__updated = (event as CustomEvent).detail;
        });
      });

      await updatePost(requestUtils, "posts", fresh.id, { meta: { postsocket_test_note: "After" } });
      await expect(outside).toHaveText("After", { timeout: 30_000 });
      await expect(reader.locator("[data-postsocket-post]")).toContainText("Body stays.");
      const state = await reader.evaluate(() => ({
        loaded: (window as unknown as { __loaded?: string }).__loaded,
        frame: (document.querySelector("#postsocket-e2e-frame") as HTMLElement & { __keep?: string })?.__keep,
        updated: (window as unknown as { __updated?: { refreshed?: boolean } }).__updated,
      }));
      expect(state.loaded).toBe("once");
      expect(state.frame).toBe("kept");
      expect(state.updated?.refreshed).toBe(true);

      await reader.evaluate(() => {
        const seen: boolean[] = [];
        (window as unknown as { __seen?: boolean[] }).__seen = seen;
        document.addEventListener("postsocket:updated", (event) => seen.push(Boolean((event as CustomEvent).detail.refreshed)));
      });
      let pageRequests = 0;
      reader.on("request", (request) => {
        if (request.url() === fresh.link) pageRequests++;
      });
      await updatePost(requestUtils, "posts", fresh.id, { content: paragraph("Body edited.") + iframe });
      await expect(reader.locator("[data-postsocket-post]")).toContainText("Body edited.", { timeout: 15_000 });
      await reader.waitForTimeout(11_000);
      expect(pageRequests, "a content edit fetches no page").toBe(0);
      expect(await reader.evaluate(() => (window as unknown as { __seen?: boolean[] }).__seen)).toEqual([false]);
      await reader.context().close();
    } finally {
      await deletePost(requestUtils, "posts", fresh.id);
    }
  });

  test("a live field with no element on the page is put there by a refresh", async ({ browser, requestUtils, baseURL }) => {
    test.setTimeout(90_000);
    const fresh = (await requestUtils.rest({
      method: "POST",
      path: "/wp/v2/posts",
      data: { title: "E2E flagged article", content: paragraph("Body stays."), status: "publish", postsocket_live: true, meta: { postsocket_test_refresh: true } },
    })) as { id: number; link: string };
    try {
      const reader = await (await visitorContext(browser, baseURL)).newPage();
      await reader.goto(fresh.link);
      await waitForLive(reader);
      const flag = reader.locator("main .postsocket-test-flag").first();
      await expect(flag).toHaveCount(0);
      await reader.evaluate(() => {
        const seen: boolean[] = [];
        (window as unknown as { __seen?: boolean[] }).__seen = seen;
        document.addEventListener("postsocket:updated", (event) => seen.push(Boolean((event as CustomEvent).detail.refreshed)));
      });

      await updatePost(requestUtils, "posts", fresh.id, { meta: { postsocket_test_flag: "Breaking" } });
      await expect(flag).toHaveText("Breaking", { timeout: 30_000 });
      expect(await reader.evaluate(() => (window as unknown as { __seen?: boolean[] }).__seen)).toEqual([false, true]);

      let pageRequests = 0;
      reader.on("request", (request) => {
        if (request.url() === fresh.link) pageRequests++;
      });
      await updatePost(requestUtils, "posts", fresh.id, { meta: { postsocket_test_flag: "Developing" } });
      await expect(flag).toHaveText("Developing", { timeout: 15_000 });
      await reader.waitForTimeout(11_000);
      expect(pageRequests, "a field with an element fetches no page").toBe(0);
      await reader.context().close();
    } finally {
      await deletePost(requestUtils, "posts", fresh.id);
    }
  });

  test("a change to a reload field reloads the reader's page once its URL answers", async ({ browser, requestUtils, baseURL }) => {
    test.setTimeout(120_000);
    const record = (await requestUtils.rest({ path: `/wp/v2/posts/${post}` })) as { link: string; comment_status: string };
    const reader = await (await visitorContext(browser, baseURL)).newPage();
    await reader.goto(record.link);
    await waitForLive(reader);
    await reader.evaluate(() => {
      (window as unknown as { __loaded?: string }).__loaded = "once";
    });
    const loaded = () => reader.evaluate(() => (window as unknown as { __loaded?: string }).__loaded ?? "reloaded").catch(() => "navigating");

    /* While the page's URL fails, the reader stays where they are. */
    let refused = 0;
    await reader.route(record.link, (route) => {
      refused++;
      return route.fulfill({ status: 503, body: "down" });
    });
    await updatePost(requestUtils, "posts", post, { comment_status: record.comment_status === "open" ? "closed" : "open" });
    await expect.poll(() => refused, { timeout: 20_000 }).toBeGreaterThan(0);
    expect(await loaded()).toBe("once");

    /* Once it answers, the next attempt reloads. */
    await reader.unroute(record.link);
    await expect.poll(loaded, { timeout: 60_000 }).toBe("reloaded");
    await expect(reader.locator("[data-postsocket-post]")).toBeVisible();
    await reader.context().close();
  });

  test("a post type that is not enabled, and a post without live editing, carry no wrapper", async ({ browser, requestUtils, baseURL }) => {
    const quiet = ((await requestUtils.rest({
      method: "POST",
      path: "/wp/v2/posts",
      data: { title: "E2E quiet post", content: paragraph("Not live."), status: "publish" },
    })) as { id: number; link: string; postsocket_live: boolean });
    try {
      expect(quiet.postsocket_live).toBe(false);
      const reader = await (await visitorContext(browser, baseURL)).newPage();
      await reader.goto(quiet.link);
      await expect(reader.locator("[data-postsocket-post]")).toHaveCount(0);
      await reader.context().close();
    } finally {
      await deletePost(requestUtils, "posts", quiet.id);
    }

    const page = ((await requestUtils.rest({
      method: "POST",
      path: "/wp/v2/pages",
      data: { title: "E2E plain page", content: paragraph("Static."), status: "publish" },
    })) as { id: number; link: string });
    try {
      const reader = await (await visitorContext(browser, baseURL)).newPage();
      await reader.goto(page.link);
      await expect(reader.locator("[data-postsocket-post]")).toHaveCount(0);
      await reader.context().close();
    } finally {
      await deletePost(requestUtils, "pages", page.id);
    }
  });
});
