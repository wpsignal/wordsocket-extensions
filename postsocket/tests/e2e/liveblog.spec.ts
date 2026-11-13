import "./env";
import { expect, test } from "@wordpress/e2e-test-utils-playwright";
import { createEntry, createHost, createLiveblog, deleteLiveblog, deletePost, feedBlock, keepSettings, paragraph, saveSettings, updateLiveblog, updatePost, visitorContext, waitForLive } from "./helpers";

/**
 * A reader of a post showing a live blog sees entries arrive, change, pin, and leave without a refresh,
 * waits behind the pill when scrolled down, and loads older entries on request. An editor also sees the
 * live blog's unpublished entries there, labelled, which a visitor never receives.
 */
test.describe("live blog", () => {
  let liveblog = 0;
  let link = "";
  let host = 0;
  const entries: number[] = [];

  let restore = (): void => undefined;

  test.beforeAll(async ({ requestUtils }) => {
    restore = keepSettings();
    liveblog = (await createLiveblog(requestUtils, "E2E match day")).id;
    const post = await createHost(requestUtils, liveblog, "E2E match day");
    host = post.id;
    link = post.link;
  });

  test.afterAll(async ({ requestUtils }) => {
    restore();
    for (const id of entries) await deletePost(requestUtils, "postsocket_entry", id);
    await deletePost(requestUtils, "posts", host);
    await deleteLiveblog(requestUtils, liveblog);
  });

  test("entries arrive, change, pin and leave live", async ({ browser, requestUtils, baseURL }) => {
    const first = await createEntry(requestUtils, liveblog, paragraph("Teams are out."), { title: "Warm-up" });
    entries.push(first.id);

    const reader = await (await visitorContext(browser, baseURL)).newPage();
    await reader.goto(link);
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

    const styled = await createEntry(
      requestUtils,
      liveblog,
      '<!-- wp:group {"layout":{"type":"flex","flexWrap":"nowrap"}} --><div class="wp-block-group"><!-- wp:paragraph --><p>Side by side.</p><!-- /wp:paragraph --></div><!-- /wp:group -->',
      { title: "Styled" },
    );
    entries.push(styled.id);
    const group = feed.locator(`[data-postsocket-entry="${styled.id}"] .wp-block-group`);
    await expect(group).toBeVisible({ timeout: 15_000 });
    await expect.poll(() => group.evaluate((el) => getComputedStyle(el).flexWrap)).toBe("nowrap");
    await deletePost(requestUtils, "postsocket_entry", styled.id);
    await expect(feed.locator(`[data-postsocket-entry="${styled.id}"]`)).toHaveCount(0, { timeout: 15_000 });

    await updatePost(requestUtils, "postsocket_entry", goal.id, { content: paragraph("GOAL! One nil, VAR confirms.") });
    await expect(landed).toContainText("VAR confirms", { timeout: 15_000 });

    await updatePost(requestUtils, "postsocket_entry", goal.id, { meta: { _postsocket_pinned: true } });
    await expect(feed.locator(`[data-postsocket-pinned-list] [data-postsocket-entry="${goal.id}"]`)).toBeVisible({ timeout: 15_000 });
    await expect(landed).toHaveClass(/is-pinned/);

    await updateLiveblog(requestUtils, liveblog, { meta: { _postsocket_state: "ended", _postsocket_ended: Math.floor(Date.now() / 1000) } });
    await expect(feed.locator(".postsocket-feed__badge")).toHaveText("Ended", { timeout: 15_000 });

    await deletePost(requestUtils, "postsocket_entry", first.id);
    await expect(feed.locator(`[data-postsocket-entry="${first.id}"]`)).toHaveCount(0, { timeout: 15_000 });
    await reader.context().close();
  });

  test("an editor sees drafts in the feed, labelled and live, and a visitor never does", async ({ page, browser, requestUtils, baseURL }) => {
    const draft = await createEntry(requestUtils, liveblog, paragraph("Still being written."), { title: "Draft entry", status: "draft" });
    entries.push(draft.id);
    const visitor = await (await visitorContext(browser, baseURL)).newPage();
    await visitor.goto(link);
    await waitForLive(visitor);
    await page.goto(link);
    const mine = page.locator(`[data-postsocket-feed] [data-postsocket-entry="${draft.id}"]`);
    await expect(mine).toContainText("Still being written.", { timeout: 15_000 });
    await expect(mine.locator(".postsocket-entry__status")).toHaveText("Draft");
    expect(await page.content()).toContain("Still being written.");
    const theirs = visitor.locator(`[data-postsocket-entry="${draft.id}"]`);
    await expect(theirs).toHaveCount(0);

    await updatePost(requestUtils, "postsocket_entry", draft.id, { content: paragraph("Rewritten before publishing.") });
    await expect(mine).toContainText("Rewritten before publishing.", { timeout: 15_000 });
    await expect(theirs).toHaveCount(0);

    await updatePost(requestUtils, "postsocket_entry", draft.id, { status: "publish" });
    await expect(theirs).toContainText("Rewritten before publishing.", { timeout: 15_000 });
    await expect(mine.locator(".postsocket-entry__status")).toHaveCount(0, { timeout: 15_000 });

    await updatePost(requestUtils, "postsocket_entry", draft.id, { status: "draft" });
    await expect(theirs).toHaveCount(0, { timeout: 15_000 });
    await expect(mine.locator(".postsocket-entry__status")).toHaveText("Draft", { timeout: 15_000 });

    await deletePost(requestUtils, "postsocket_entry", draft.id);
    await expect(mine).toHaveCount(0, { timeout: 15_000 });
    await visitor.context().close();
  });

  test("a scrolled reader gets a pill, and older entries load on request", async ({ browser, requestUtils, baseURL }) => {
    await saveSettings(requestUtils, { entries_per_page: 5 });
    for (let i = 1; i <= 7; i++) {
      entries.push((await createEntry(requestUtils, liveblog, paragraph(`Update number ${i}. `.repeat(12)), { title: `Update ${i}` })).id);
    }

    const reader = await (await visitorContext(browser, baseURL)).newPage();
    await reader.setViewportSize({ width: 1000, height: 600 });
    await reader.goto(link);
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

  test("editing a post keeps its feed, even inside a group", async ({ browser, requestUtils, baseURL }) => {
    const grouped = (content: string): string =>
      `<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group">${content}${feedBlock(liveblog)}</div><!-- /wp:group -->`;
    const post = (await requestUtils.rest({
      method: "POST",
      path: "/wp/v2/posts",
      data: { title: "E2E grouped feed", content: grouped(paragraph("Intro one.")), status: "publish" },
    })) as { id: number; link: string };
    try {
      const reader = await (await visitorContext(browser, baseURL)).newPage();
      await reader.goto(post.link);
      await waitForLive(reader);
      const feed = reader.locator("[data-postsocket-feed]");
      await expect(feed).toHaveCount(1);
      await reader.evaluate(() => {
        (document.querySelector("[data-postsocket-feed]") as HTMLElement & { __keep?: string }).__keep = "kept";
      });

      await updatePost(requestUtils, "posts", post.id, { content: grouped(paragraph("Intro two.")) });
      await expect(reader.locator("[data-postsocket-post]")).toContainText("Intro two.", { timeout: 15_000 });
      await expect(feed).toHaveCount(1);
      expect(await reader.evaluate(() => (document.querySelector("[data-postsocket-feed]") as HTMLElement & { __keep?: string })?.__keep)).toBe("kept");

      const after = await createEntry(requestUtils, liveblog, paragraph("Still live."), { title: "After the edit" });
      entries.push(after.id);
      await expect(feed.locator(`[data-postsocket-entry="${after.id}"]`)).toBeVisible({ timeout: 15_000 });
      await reader.context().close();
    } finally {
      await deletePost(requestUtils, "posts", post.id);
    }
  });

  test("a live entry's block script loads with its dependencies and starts on the inserted markup", async ({ browser, requestUtils, baseURL }) => {
    const reader = await (await visitorContext(browser, baseURL)).newPage();
    await reader.goto(link);
    await waitForLive(reader);
    expect(await reader.evaluate(() => Boolean(document.getElementById("postsocket-test-ready-js")))).toBe(false);

    const entry = await createEntry(requestUtils, liveblog, "<!-- wp:postsocket-test/ready /-->", { title: "Scripted" });
    entries.push(entry.id);
    const block = reader.locator(`[data-postsocket-entry="${entry.id}"] .postsocket-test-ready`);
    await expect(block).toHaveText("ready:dep", { timeout: 15_000 });

    const second = await createEntry(requestUtils, liveblog, "<!-- wp:postsocket-test/ready /-->", { title: "Scripted again" });
    entries.push(second.id);
    await expect(reader.locator(`[data-postsocket-entry="${second.id}"] .postsocket-test-ready`)).toHaveText("ready:dep", { timeout: 15_000 });
    await expect(block).toHaveAttribute("data-runs", "1");
    await reader.context().close();
  });

  test("a live entry's Interactivity API block is hydrated with the state its render set", async ({ browser, requestUtils, baseURL }) => {
    const reader = await (await visitorContext(browser, baseURL)).newPage();
    await reader.goto(link);
    await waitForLive(reader);
    const entry = await createEntry(requestUtils, liveblog, "<!-- wp:postsocket-test/counter /-->", { title: "Interactive" });
    entries.push(entry.id);
    const button = reader.locator(`[data-postsocket-entry="${entry.id}"] .postsocket-test-counter button`);
    await expect(button).toHaveText("5", { timeout: 15_000 });
    await button.click();
    await expect(button).toHaveText("6");
    await reader.context().close();
  });
});
