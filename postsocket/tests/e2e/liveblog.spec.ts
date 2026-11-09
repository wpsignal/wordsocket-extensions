import "./env";
import { expect, test } from "@wordpress/e2e-test-utils-playwright";
import { createEntry, createLiveblog, deletePost, paragraph, saveSettings, updatePost, visitorContext, waitForLive } from "./helpers";

/**
 * A reader on a live blog's page sees entries arrive, change, pin, and leave without a refresh,
 * waits behind the pill when scrolled down, and loads older entries on request.
 */
test.describe("live blog", () => {
  let liveblog = 0;
  const entries: number[] = [];

  test.beforeAll(async ({ requestUtils }) => {
    liveblog = (await createLiveblog(requestUtils, "E2E match day")).id;
  });

  test.afterAll(async ({ requestUtils }) => {
    await saveSettings(requestUtils, { entries_per_page: 20 });
    for (const id of entries) await deletePost(requestUtils, "postsocket_entry", id);
    await deletePost(requestUtils, "postsocket_liveblog", liveblog);
  });

  test("entries arrive, change, pin and leave live", async ({ browser, requestUtils, baseURL }) => {
    const first = await createEntry(requestUtils, liveblog, paragraph("Teams are out."), { title: "Warm-up" });
    entries.push(first.id);
    const blog = (await requestUtils.rest({ path: `/wp/v2/postsocket_liveblog/${liveblog}` })) as { link: string };

    const reader = await (await visitorContext(browser, baseURL)).newPage();
    await reader.goto(blog.link);
    await waitForLive(reader);
    const feed = reader.locator("[data-postsocket-feed]");
    await expect(feed).toBeVisible();
    await expect(feed.locator(`[data-postsocket-entry="${first.id}"]`)).toContainText("Teams are out.");
    await expect(feed.locator(".postsocket-feed__badge")).toHaveText("Live");

    const goal = await createEntry(requestUtils, liveblog, paragraph("GOAL! One nil.") + '<!-- wp:quote --><blockquote class="wp-block-quote"><p>Unbelievable.</p></blockquote><!-- /wp:quote -->', { title: "Goal" });
    entries.push(goal.id);
    const landed = feed.locator(`[data-postsocket-entry="${goal.id}"]`);
    await expect(landed).toContainText("One nil.", { timeout: 15_000 });
    await expect(feed.locator("[data-postsocket-entry-list] [data-postsocket-entry]").first()).toHaveAttribute("data-postsocket-entry", String(goal.id));
    await expect.poll(() => reader.evaluate(() => Boolean(document.querySelector('link[href*="blocks/quote/style"]')))).toBe(true);

    await updatePost(requestUtils, "postsocket_entry", goal.id, { content: paragraph("GOAL! One nil, VAR confirms.") });
    await expect(landed).toContainText("VAR confirms", { timeout: 15_000 });

    await updatePost(requestUtils, "postsocket_entry", goal.id, { meta: { _postsocket_pinned: true } });
    await expect(feed.locator(`[data-postsocket-pinned-list] [data-postsocket-entry="${goal.id}"]`)).toBeVisible({ timeout: 15_000 });
    await expect(landed).toHaveClass(/is-pinned/);

    await updatePost(requestUtils, "postsocket_liveblog", liveblog, { meta: { _postsocket_state: "ended", _postsocket_ended: Math.floor(Date.now() / 1000) } });
    await expect(feed.locator(".postsocket-feed__badge")).toHaveText("Ended", { timeout: 15_000 });

    await deletePost(requestUtils, "postsocket_entry", first.id);
    await expect(feed.locator(`[data-postsocket-entry="${first.id}"]`)).toHaveCount(0, { timeout: 15_000 });
    await reader.context().close();
  });

  test("a scrolled reader gets a pill, and older entries load on request", async ({ browser, requestUtils, baseURL }) => {
    await saveSettings(requestUtils, { entries_per_page: 5 });
    for (let i = 1; i <= 7; i++) {
      entries.push((await createEntry(requestUtils, liveblog, paragraph(`Update number ${i}. `.repeat(12)), { title: `Update ${i}` })).id);
    }
    const blog = (await requestUtils.rest({ path: `/wp/v2/postsocket_liveblog/${liveblog}` })) as { link: string };

    const reader = await (await visitorContext(browser, baseURL)).newPage();
    await reader.setViewportSize({ width: 1000, height: 600 });
    await reader.goto(blog.link);
    await waitForLive(reader);
    const feed = reader.locator("[data-postsocket-feed]");
    const listed = feed.locator("[data-postsocket-entry-list] [data-postsocket-entry]");
    await expect(listed).toHaveCount(5);

    await feed.locator(".postsocket-feed__older").click();
    await expect(listed).toHaveCount(7, { timeout: 15_000 });
    await expect(feed.locator(".postsocket-feed__older")).toBeHidden();

    await reader.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
    await expect.poll(() => reader.evaluate(() => document.querySelector("[data-postsocket-feed]")!.getBoundingClientRect().top)).toBeLessThan(-200);
    const late = await createEntry(requestUtils, liveblog, paragraph("Late update."), { title: "Late" });
    entries.push(late.id);
    const pill = feed.locator(".postsocket-feed__pending");
    await expect(pill).toBeVisible({ timeout: 15_000 });
    await expect(pill).toHaveText("1 new update");
    await expect(feed.locator(`[data-postsocket-entry="${late.id}"]`)).toHaveCount(0);

    await pill.click();
    await expect(feed.locator(`[data-postsocket-entry="${late.id}"]`)).toBeVisible();
    await expect(pill).toBeHidden();
    await expect(listed.first()).toHaveAttribute("data-postsocket-entry", String(late.id));
    await reader.context().close();
  });
});
