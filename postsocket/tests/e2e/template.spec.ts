import "./env";
import { expect, test } from "@wordpress/e2e-test-utils-playwright";
import {
  createEntry,
  createHost,
  createLiveblog,
  deleteLiveblog,
  deletePost,
  keepSettings,
  paragraph,
  updateLiveblog,
  visitorContext,
  waitForLive,
} from "./helpers";

const TEMPLATE =
  '<!-- wp:postsocket/entry {"className":"e2e-card is-style-card"} --><!-- wp:post-date /--><!-- wp:post-title {"level":4} /--><!-- wp:postsocket/entry-content /--><!-- /wp:postsocket/entry -->';

/**
 * A live blog's entries are printed in the synced pattern chosen for it: open readers get the new layout
 * when it is chosen, new entries arrive in it, and the desk's select puts the default layout back.
 */
test.describe("entry templates", () => {
  let liveblog = 0;
  let link = "";
  let host = 0;
  let pattern = 0;
  const entries: number[] = [];

  let restore = (): void => undefined;

  test.beforeAll(async ({ requestUtils }) => {
    restore = keepSettings();
    liveblog = (await createLiveblog(requestUtils, "E2E templates")).id;
    const post = await createHost(requestUtils, liveblog, "E2E templates");
    host = post.id;
    link = post.link;
    pattern = (
      (await requestUtils.rest({
        method: "POST",
        path: "/wp/v2/blocks",
        data: { title: "E2E entry card", content: TEMPLATE, status: "publish" },
      })) as { id: number }
    ).id;
  });

  test.afterAll(async ({ requestUtils }) => {
    restore();
    for (const id of entries)
      await deletePost(requestUtils, "postsocket_entry", id);
    await deletePost(requestUtils, "posts", host);
    await deletePost(requestUtils, "blocks", pattern);
    await deleteLiveblog(requestUtils, liveblog);
  });

  test("a template chosen for a live blog reaches open readers, new entries and the desk", async ({
    admin,
    page,
    browser,
    requestUtils,
    baseURL,
  }) => {
    const first = await createEntry(
      requestUtils,
      liveblog,
      paragraph("Teams are out."),
      { title: "Warm-up" },
    );
    entries.push(first.id);

    const reader = await (await visitorContext(browser, baseURL)).newPage();
    await reader.goto(link);
    await waitForLive(reader);
    const feed = reader.locator("[data-postsocket-feed]");
    const shown = feed.locator(`[data-postsocket-entry="${first.id}"]`);
    await expect(shown.locator(".postsocket-entry__headline")).toHaveText(
      "Warm-up",
    );

    await updateLiveblog(requestUtils, liveblog, {
      meta: { _postsocket_template: pattern },
    });
    await expect(shown).toHaveClass(/e2e-card/, { timeout: 20_000 });
    await expect(shown.locator("h4.wp-block-post-title")).toHaveText("Warm-up");
    await expect(shown.locator(".wp-block-post-date time")).toBeVisible();
    await expect(shown.locator(".postsocket-entry__content")).toContainText(
      "Teams are out.",
    );
    await expect(shown.locator(".postsocket-entry__headline")).toHaveCount(0);

    const goal = await createEntry(
      requestUtils,
      liveblog,
      paragraph("GOAL! One nil."),
      { title: "Goal" },
    );
    entries.push(goal.id);
    const landed = feed.locator(`[data-postsocket-entry="${goal.id}"]`);
    await expect(landed).toHaveClass(/e2e-card/, { timeout: 15_000 });
    await expect(landed.locator("h4.wp-block-post-title")).toHaveText("Goal");
    await expect
      .poll(() =>
        landed.evaluate((el) => getComputedStyle(el).borderTopLeftRadius),
      )
      .toBe("8px");

    await admin.visitAdminPage(
      "admin.php",
      `page=postsocket&liveblog=${liveblog}`,
    );
    const select = page.getByRole("combobox", {
      name: "Entry template",
      exact: true,
    });
    await expect(select).toContainText("E2E entry card", { timeout: 15_000 });
    await expect(
      page.getByRole("link", { name: "Edit: Entry template" }),
    ).toHaveAttribute("href", new RegExp(`post=${pattern}$`));
    await expect(
      page.getByRole("combobox", { name: "Pinned entry template" }),
    ).toContainText("Same as entries");
    await expect(
      page.getByRole("link", { name: "Create template" }),
    ).toHaveAttribute("href", /post_type=wp_block&postsocket_template=1$/);
    await select.click();
    await page.getByText("Default layout", { exact: true }).click();
    await expect(landed.locator(".postsocket-entry__headline")).toHaveText(
      "Goal",
      { timeout: 20_000 },
    );
    await expect(landed).not.toHaveClass(/e2e-card/);
    await reader.context().close();
  });

  test("Create template asks for a name and whether to sync, then holds the entry block and its usual blocks", async ({
    admin,
    editor,
    page,
  }) => {
    await admin.visitAdminPage(
      "post-new.php",
      "post_type=wp_block&postsocket_template=1",
    );
    await editor.setPreferences("core/edit-post", { welcomeGuide: false });
    const prompt = page.getByRole("dialog", { name: "Create pattern" });
    await expect(prompt).toBeVisible({ timeout: 15_000 });
    await prompt.getByLabel("Name").fill("Entry layout");
    await prompt.getByLabel("Synced").uncheck();
    await prompt.getByRole("button", { name: "Create" }).click();
    const entry = editor.canvas.locator('[data-type="postsocket/entry"]');
    await expect(entry).toBeVisible({ timeout: 15_000 });
    await expect(entry).toHaveClass(/is-style-timeline/);
    await expect(
      entry.locator('[data-type="core/post-date"]').first(),
    ).toBeVisible();
    await expect(entry.locator('[data-type="core/post-title"]')).toBeVisible();
    await expect(
      entry.locator('[data-type="postsocket/entry-content"]'),
    ).toContainText("entry's content");
    await expect(editor.canvas.locator(".block-editor-warning")).toHaveCount(0);
    await expect(entry).not.toContainText("Invalid date");
    await expect(page.locator(".editor-document-bar")).toContainText(
      "Entry layout",
    );
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const sync = await page.evaluate(
      () =>
        (window as any).wp.data
          .select("core/editor")
          .getEditedPostAttribute("meta").wp_pattern_sync_status,
    );
    expect(sync).toBe("unsynced");
  });

  test("on a site that limits its blocks, the template's blocks are offered inside the entry block only", async ({
    admin,
    editor,
    page,
  }) => {
    await admin.visitAdminPage(
      "post-new.php",
      "post_type=wp_block&postsocket_template=1&postsocket_test_allowlist=1",
    );
    await editor.setPreferences("core/edit-post", { welcomeGuide: false });
    await expect(
      editor.canvas.locator('[data-type="postsocket/entry"]'),
    ).toBeVisible({ timeout: 15_000 });
    const can = await page.evaluate(() => {
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      const store = (window as any).wp.data.select("core/block-editor");
      const entry = store
        .getBlocks()
        .find((block: { name: string }) => block.name === "postsocket/entry")
        .clientId as string;
      const answer = (name: string): [boolean, boolean] => [
        store.canInsertBlockType(name, entry),
        store.canInsertBlockType(name, ""),
      ];
      return {
        date: answer("core/post-date"),
        image: answer("core/post-featured-image"),
        terms: answer("core/post-terms"),
        content: answer("postsocket/entry-content"),
        paragraph: answer("core/paragraph"),
        quote: answer("core/quote"),
      };
    });
    expect(can.date).toEqual([true, false]);
    expect(can.image).toEqual([true, false]);
    expect(can.terms).toEqual([true, false]);
    expect(can.content).toEqual([true, false]);
    expect(can.paragraph).toEqual([true, true]);
    expect(can.quote).toEqual([false, false]);
    await expect(editor.canvas.locator(".block-editor-warning")).toHaveCount(0);
  });

  test("a template block that a theme unregisters is put back, inside the entry block only", async ({
    admin,
    editor,
    page,
  }) => {
    await admin.visitAdminPage(
      "post-new.php",
      "post_type=wp_block&postsocket_template=1&postsocket_test_unregister=1",
    );
    await editor.setPreferences("core/edit-post", { welcomeGuide: false });
    const entry = editor.canvas.locator('[data-type="postsocket/entry"]');
    await expect(entry).toBeVisible({ timeout: 15_000 });
    await expect(entry.locator('[data-type="core/post-date"] time').first()).toBeVisible();
    await expect(entry).not.toContainText("Invalid date");
    const can = await page.evaluate(() => {
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      const store = (window as any).wp.data.select("core/block-editor");
      const id = store
        .getBlocks()
        .find((block: { name: string }) => block.name === "postsocket/entry")
        .clientId as string;
      const answer = (name: string): [boolean, boolean] => [
        store.canInsertBlockType(name, id),
        store.canInsertBlockType(name, ""),
      ];
      return {
        date: answer("core/post-date"),
        image: answer("core/post-featured-image"),
        title: answer("core/post-title"),
        quote: answer("core/quote"),
      };
    });
    expect(can.date).toEqual([true, false]);
    expect(can.image).toEqual([true, false]);
    expect(can.title).toEqual([true, true]);
    expect(can.quote).toEqual([true, true]);
    await expect(editor.canvas.locator(".block-editor-warning")).toHaveCount(0);
  });

  test("the border controls are switched on for the entry block and the blocks inside it only", async ({
    admin,
    editor,
    page,
  }) => {
    await admin.visitAdminPage(
      "post-new.php",
      "post_type=wp_block&postsocket_template=1",
    );
    await editor.setPreferences("core/edit-post", { welcomeGuide: false });
    await expect(
      editor.canvas.locator('[data-type="postsocket/entry"]'),
    ).toBeVisible({ timeout: 15_000 });
    const radius = await page.evaluate(async () => {
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      const wp = (window as any).wp;
      await wp.data
        .dispatch("core/block-editor")
        .insertBlock(wp.blocks.createBlock("core/paragraph"));
      const blocks = wp.data.select("core/block-editor").getBlocks() as {
        name: string;
        clientId: string;
        innerBlocks: { name: string; clientId: string }[];
      }[];
      const entry = blocks.find((block) => block.name === "postsocket/entry")!;
      const outside = blocks.find((block) => block.name === "core/paragraph")!;
      const answer = (
        block: { name: string; clientId: string },
        path = "border.radius",
      ): unknown =>
        wp.hooks.applyFilters(
          "blockEditor.useSetting.before",
          undefined,
          path,
          block.clientId,
          block.name,
        ) ?? null;
      return {
        entry: answer(entry),
        inside: entry.innerBlocks.map((block) => answer(block)),
        outside: answer(outside),
        paths: ["border.color", "border.style", "border.width"].map((path) =>
          answer(entry, path),
        ),
        other: answer(entry, "spacing.padding"),
      };
    });
    expect(radius.entry).toBe(true);
    expect(radius.inside.length).toBeGreaterThan(0);
    expect(radius.inside.every((value) => value === true)).toBe(true);
    expect(radius.outside).toBeNull();
    expect(radius.paths).toEqual([true, true, true]);
    expect(radius.other).toBeNull();
  });
});
