import "./env";
import { expect, test } from "@wordpress/e2e-test-utils-playwright";
import { createLiveblog, deletePost } from "./helpers";

/**
 * The editor panels: an entry opened from a live blog has it preselected and publishes; one
 * without a live blog is refused; the live blog's panel counts entries and offers "Add entry".
 */
test.describe("editor", () => {
  let liveblog = 0;
  const entries: number[] = [];

  test.beforeAll(async ({ requestUtils }) => {
    liveblog = (await createLiveblog(requestUtils, "E2E editor blog")).id;
  });

  test.afterAll(async ({ requestUtils }) => {
    for (const id of entries) await deletePost(requestUtils, "postsocket_entry", id);
    await deletePost(requestUtils, "postsocket_liveblog", liveblog);
  });

  function currentPostId(page: import("@playwright/test").Page): Promise<number> {
    return page.evaluate(() => (window as unknown as { wp: { data: { select: (s: string) => { getCurrentPostId: () => number } } } }).wp.data.select("core/editor").getCurrentPostId());
  }

  async function openPanel(page: import("@playwright/test").Page, title: string): Promise<import("@playwright/test").Locator> {
    if ((await page.locator(".editor-sidebar, .interface-complementary-area").count()) === 0) {
      await page.locator('button[aria-label="Settings"]').first().click();
    }
    const toggle = page.locator(".components-panel__body-toggle", { hasText: new RegExp(`^${title}$`) }).first();
    await expect(toggle).toBeVisible({ timeout: 15_000 });
    if ((await toggle.getAttribute("aria-expanded")) === "false") await toggle.click();
    return toggle.locator("xpath=ancestor::div[contains(@class,'components-panel__body')]");
  }

  test("an entry opened from a live blog is preselected and publishes", async ({ admin, editor, page, requestUtils }) => {
    await admin.visitAdminPage("post-new.php", `post_type=postsocket_entry&liveblog=${liveblog}`);
    await editor.setPreferences("core/edit-post", { welcomeGuide: false });
    const panel = await openPanel(page, "Live blog");
    await expect(panel.getByLabel("Belongs to")).toHaveValue("E2E editor blog", { timeout: 15_000 });
    await panel.getByLabel("Pin to the top").check();

    await editor.canvas.locator('[aria-label="Add title"], .editor-post-title__input').first().fill("Editor entry");
    await editor.insertBlock({ name: "core/paragraph", attributes: { content: "Typed in the editor." } });
    await editor.publishPost();
    const id = await currentPostId(page);
    entries.push(id);
    const record = (await requestUtils.rest({ path: `/wp/v2/postsocket_entry/${id}`, params: { context: "edit" } })) as { liveblog: number; status: string; meta: Record<string, unknown> };
    expect(record.status).toBe("publish");
    expect(record.liveblog).toBe(liveblog);
    expect(record.meta._postsocket_pinned).toBe(true);
  });

  test("publishing an entry without a live blog is refused", async ({ admin, editor, page }) => {
    await admin.createNewPost({ postType: "postsocket_entry", title: "Orphan entry", content: "No home." });
    await editor.setPreferences("core/edit-post", { welcomeGuide: false });
    const panel = await openPanel(page, "Live blog");
    await expect(panel).toContainText("Choose the live blog before publishing.");
    await page.getByRole("button", { name: "Publish", exact: true }).click();
    const publishPanel = page.locator(".editor-post-publish-panel");
    if (await publishPanel.isVisible().catch(() => false)) {
      await publishPanel.getByRole("button", { name: "Publish", exact: true }).click();
    }
    await expect(page.locator(".components-snackbar, .components-notice")).toContainText("Choose the live blog", { timeout: 15_000 });
    entries.push(await currentPostId(page));
  });

  test("the live blog's panel counts entries and links to adding one", async ({ admin, editor, page }) => {
    await admin.editPost(liveblog);
    await editor.setPreferences("core/edit-post", { welcomeGuide: false });
    const panel = await openPanel(page, "Live coverage");
    await expect(panel).toContainText(/\d+ published entr/, { timeout: 15_000 });
    await expect(panel.getByRole("link", { name: "Add entry" })).toHaveAttribute("href", new RegExp(`post-new\\.php\\?post_type=postsocket_entry&liveblog=${liveblog}$`));
    await expect(panel.getByLabel("Coverage has ended")).not.toBeChecked();
  });
});
