import "./env";
import type { Page } from "@playwright/test";
import { expect, test } from "@wordpress/e2e-test-utils-playwright";
import { createEntry, createHost, createLiveblog, deleteLiveblog, deletePost, paragraph, visitorContext, waitForLive } from "./helpers";

/**
 * The Live Desk: a live blog is created and placed on a page from it, an entry is added in the
 * editor and found on the desk again, a pin from the desk reaches readers, a publish without
 * a live blog is refused, a new entry opened without one asks for it in a dialog, unpublishing and trashing ask first, a trashed entry is restored from
 * the Trash tab, the entries of a deleted live blog are restored into another from the list's Trash tab, and a new entry open in the editor shows on the desk with who is editing it.
 */
test.describe("live desk", () => {
  const liveblogs: number[] = [];
  const posts: number[] = [];
  const entries: number[] = [];

  test.afterAll(async ({ requestUtils }) => {
    for (const id of entries) await deletePost(requestUtils, "postsocket_entry", id);
    for (const id of posts) await deletePost(requestUtils, "posts", id);
    for (const id of liveblogs) await deleteLiveblog(requestUtils, id);
  });

  function currentPostId(page: Page): Promise<number> {
    return page.evaluate(() => (window as unknown as { wp: { data: { select: (s: string) => { getCurrentPostId: () => number } } } }).wp.data.select("core/editor").getCurrentPostId());
  }

  async function openPanel(page: Page, title: string) {
    const settings = page.getByRole("button", { name: "Settings", exact: true }).first();
    await expect(settings).toBeVisible({ timeout: 15_000 });
    if ((await settings.getAttribute("aria-pressed")) !== "true") await settings.click();
    const postTab = page.getByRole("tab", { name: /^(Post|Live Blog Entry)$/ }).first();
    if (await postTab.isVisible().catch(() => false)) await postTab.click();
    const toggle = page.locator(".components-panel__body-toggle", { hasText: new RegExp(`^${title}$`) }).first();
    await expect(toggle).toBeVisible({ timeout: 15_000 });
    if ((await toggle.getAttribute("aria-expanded")) === "false") await toggle.click();
    return toggle.locator("xpath=ancestor::div[contains(@class,'components-panel__body')]");
  }

  test("a live blog is created on the desk and placed on a new post", async ({ admin, editor, page }) => {
    const name = `E2E desk blog ${Date.now().toString(36)}`;
    await admin.visitAdminPage("admin.php", "page=postsocket");
    await expect(page.getByRole("heading", { name: "Live Blogs", exact: true, level: 1 })).toHaveCount(1, { timeout: 15_000 });
    const links = page.getByRole("navigation", { name: "External links" });
    await expect(links.getByRole("link", { name: /Documentation/ })).toBeVisible();
    await expect(links.getByRole("link", { name: /PostSocket home/ }).locator("svg")).toBeVisible();
    const menu = page.locator("#toplevel_page_postsocket");
    await expect(menu.locator(".wp-submenu li.current a")).toHaveText("All Live Blogs");
    await menu.getByRole("link", { name: "Add Live Blog Entry" }).click();
    await expect(page).toHaveURL(/post-new\.php\?post_type=postsocket_entry/);
    await expect(page.locator("#toplevel_page_postsocket .wp-submenu li.current a")).toHaveText("Add Live Blog Entry");
    await admin.visitAdminPage("admin.php", "page=postsocket");
    await page.getByRole("button", { name: "New live blog" }).click();
    const dialog = page.getByRole("dialog");
    await dialog.getByLabel("Name").fill(name);
    await dialog.getByRole("button", { name: "Create" }).click();

    await expect(page.getByRole("heading", { name })).toBeVisible({ timeout: 15_000 });
    const id = Number(new URL(page.url()).searchParams.get("liveblog"));
    expect(id).toBeGreaterThan(0);
    liveblogs.push(id);
    await expect(page.getByText("No published post shows this live blog yet.")).toBeVisible();

    await page.getByRole("link", { name: "Create page" }).click();
    await editor.setPreferences("core/edit-post", { welcomeGuide: false });
    await expect(editor.canvas.locator(".postsocket-feed-placeholder")).toBeVisible({ timeout: 15_000 });
    const content = await page.evaluate(() => (window as unknown as { wp: { data: { select: (s: string) => { getEditedPostContent: () => string } } } }).wp.data.select("core/editor").getEditedPostContent());
    expect(content).toContain(`"liveblog":${id}`);
    await editor.publishPost();
    posts.push(await currentPostId(page));

    await admin.visitAdminPage("admin.php", `page=postsocket&liveblog=${id}`);
    await expect(page.getByRole("link", { name })).toBeVisible({ timeout: 15_000 });
  });

  test("an entry added from the desk is preselected, publishes, and is listed back on the desk", async ({ admin, editor, page, requestUtils }) => {
    const liveblog = await createLiveblog(requestUtils, "E2E add entry");
    liveblogs.push(liveblog.id);

    await admin.visitAdminPage("admin.php", `page=postsocket&liveblog=${liveblog.id}`);
    await page.getByRole("link", { name: "Add entry" }).first().click();
    await editor.setPreferences("core/edit-post", { welcomeGuide: false });
    const panel = await openPanel(page, "Live blog");
    await expect(panel.getByRole("combobox", { name: "Belongs to" })).toContainText(liveblog.name, { timeout: 15_000 });
    await panel.getByRole("switch", { name: "Pin to the top" }).click();
    await expect(panel.getByRole("link", { name: "Back to the desk" })).toHaveAttribute("href", new RegExp(`admin\\.php\\?page=postsocket&liveblog=${liveblog.id}$`));

    await editor.canvas.locator('[aria-label="Add title"], .editor-post-title__input').first().fill("Desk entry");
    await editor.insertBlock({ name: "core/paragraph", attributes: { content: "Typed in the editor." } });
    await editor.publishPost();
    const id = await currentPostId(page);
    entries.push(id);
    const record = (await requestUtils.rest({ path: `/wp/v2/postsocket_entry/${id}`, params: { context: "edit" } })) as { liveblogs: number[]; status: string; meta: Record<string, unknown> };
    expect(record.status).toBe("publish");
    expect(record.liveblogs).toEqual([liveblog.id]);
    expect(record.meta._postsocket_pinned).toBe(true);

    await admin.visitAdminPage("admin.php", `page=postsocket&liveblog=${liveblog.id}`);
    await expect(page.getByRole("link", { name: "Desk entry" })).toBeVisible({ timeout: 15_000 });
    await expect(page.getByText("Pinned", { exact: true }).last()).toBeVisible();
  });

  test("a pin from the desk reaches an open reader", async ({ admin, browser, page, requestUtils, baseURL }) => {
    const liveblog = await createLiveblog(requestUtils, "E2E desk pin");
    liveblogs.push(liveblog.id);
    const host = await createHost(requestUtils, liveblog.id, "E2E desk pin");
    posts.push(host.id);
    const entry = await createEntry(requestUtils, liveblog.id, paragraph("Pin me."), { title: "Pin me" });
    entries.push(entry.id);

    const reader = await (await visitorContext(browser, baseURL)).newPage();
    await reader.goto(host.link);
    await waitForLive(reader);
    const feed = reader.locator("[data-postsocket-feed]");
    await expect(feed.locator(`[data-postsocket-entry-list] [data-postsocket-entry="${entry.id}"]`)).toBeVisible();

    await admin.visitAdminPage("admin.php", `page=postsocket&liveblog=${liveblog.id}`);
    const row = page.getByRole("row", { name: /Pin me/ });
    await expect(row).toBeVisible({ timeout: 15_000 });
    await row.getByRole("button", { name: "Actions" }).click();
    await page.getByRole("menuitem", { name: "Pin to the top" }).click();

    await expect(feed.locator(`[data-postsocket-pinned-list] [data-postsocket-entry="${entry.id}"]`)).toBeVisible({ timeout: 15_000 });
    await reader.context().close();
  });

  test("a new entry opened without a live blog asks for one, and takes the answer", async ({ admin, editor, page, requestUtils }) => {
    const liveblog = await createLiveblog(requestUtils, `E2E asked ${Date.now().toString(36)}`);
    liveblogs.push(liveblog.id);
    await admin.createNewPost({ postType: "postsocket_entry", title: "Asked entry", content: "Chosen in the dialog." });
    await editor.setPreferences("core/edit-post", { welcomeGuide: false });
    const ask = page.getByRole("dialog", { name: "Which live blog is this entry for?" });
    await expect(ask).toBeVisible({ timeout: 15_000 });
    await expect(ask.getByRole("button", { name: "Continue" })).toBeDisabled();
    await ask.getByRole("combobox", { name: "Live blog" }).click();
    await page.getByText(liveblog.name, { exact: true }).click();
    await ask.getByRole("button", { name: "Continue" }).click();
    await expect(ask).toHaveCount(0);
    const panel = await openPanel(page, "Live blog");
    await expect(panel.getByRole("combobox", { name: "Belongs to" })).toContainText(liveblog.name);
    await editor.publishPost();
    const id = await currentPostId(page);
    entries.push(id);
    const saved = (await requestUtils.rest({ path: `/wp/v2/postsocket_entry/${id}` })) as { liveblogs: number[] };
    expect(saved.liveblogs).toEqual([liveblog.id]);
  });

  test("publishing an entry without a live blog is refused", async ({ admin, editor, page }) => {
    await admin.createNewPost({ postType: "postsocket_entry", title: "Orphan entry", content: "No home." });
    await editor.setPreferences("core/edit-post", { welcomeGuide: false });
    const ask = page.getByRole("dialog", { name: "Which live blog is this entry for?" });
    await expect(ask).toBeVisible({ timeout: 15_000 });
    await ask.getByRole("button", { name: "Not now" }).click();
    await expect(ask).toHaveCount(0);
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

  test("unpublish and trash ask first, and a trashed entry comes back from the Trash tab", async ({ admin, page, requestUtils }) => {
    const liveblog = await createLiveblog(requestUtils, "E2E desk trash");
    liveblogs.push(liveblog.id);
    const entry = await createEntry(requestUtils, liveblog.id, paragraph("Ask first."), { title: "Ask first" });
    entries.push(entry.id);
    const status = async () => ((await requestUtils.rest({ path: `/wp/v2/postsocket_entry/${entry.id}`, params: { context: "edit" } })) as { status: string }).status;

    await admin.visitAdminPage("admin.php", `page=postsocket&liveblog=${liveblog.id}`);
    const row = page.getByRole("row", { name: /Ask first/ });
    await expect(row).toBeVisible({ timeout: 15_000 });
    const act = async (name: string) => {
      await row.getByRole("button", { name: "Actions" }).click();
      await page.getByRole("menuitem", { name }).click();
    };
    const dialog = page.getByRole("alertdialog");

    await act("Unpublish");
    await expect(dialog).toContainText("Unpublish “Ask first”?");
    await dialog.getByRole("button", { name: "Cancel" }).click();
    await expect(dialog).toBeHidden();
    expect(await status()).toBe("publish");

    await act("Unpublish");
    await dialog.getByRole("button", { name: "Unpublish" }).click();
    await expect(row.getByText("Draft", { exact: true })).toBeVisible();
    await expect.poll(status).toBe("draft");

    await act("Move to trash");
    await expect(dialog).toContainText("Move “Ask first” to the trash?");
    await dialog.getByRole("button", { name: "Move to trash" }).click();
    await expect(row).toBeHidden();
    await expect.poll(status).toBe("trash");

    await page.getByRole("tab", { name: "Trash (1)" }).click();
    await expect(row.getByText("Trashed", { exact: true })).toBeVisible();
    await act("Restore");
    await expect(row).toBeHidden();
    await expect(page.getByRole("tab", { name: "Trash (0)" })).toBeVisible();
    await page.getByRole("tab", { name: "Entries" }).click();
    await expect(row.getByText("Draft", { exact: true })).toBeVisible();
    expect(await status()).toBe("draft");
  });

  test("the entries of a deleted live blog are restored from the list's Trash tab into another", async ({ admin, page, requestUtils }) => {
    const stamp = Date.now().toString(36);
    const gone = await createLiveblog(requestUtils, `E2E gone ${stamp}`);
    const kept = await createLiveblog(requestUtils, `E2E kept ${stamp}`);
    liveblogs.push(kept.id);
    const title = `Stranded ${stamp}`;
    const entry = await createEntry(requestUtils, gone.id, paragraph("Left behind."), { title });
    entries.push(entry.id);
    await deleteLiveblog(requestUtils, gone.id);

    await admin.visitAdminPage("admin.php", "page=postsocket");
    await page.getByRole("tab", { name: /^Trash \(\d+\)$/ }).click();
    const row = page.getByRole("row").filter({ hasText: title });
    await expect(row).toBeVisible({ timeout: 15_000 });
    await row.getByRole("button", { name: "Restore" }).click();
    const dialog = page.getByRole("dialog", { name: "Restore 1 entry" });
    await dialog.getByRole("combobox", { name: "Restore into" }).click();
    await page.getByText(kept.name, { exact: true }).click();
    await dialog.getByRole("button", { name: "Restore" }).click();
    await expect(dialog).toHaveCount(0, { timeout: 15_000 });
    await expect(page.getByText(title)).toHaveCount(0, { timeout: 15_000 });
    const saved = (await requestUtils.rest({ path: `/wp/v2/postsocket_entry/${entry.id}`, params: { context: "edit" } })) as { status: string; liveblogs: number[] };
    expect(saved.status).toBe("draft");
    expect(saved.liveblogs).toEqual([kept.id]);
  });

  test("a new entry open in the editor shows on the desk with who is editing, and leaves when the editor closes", async ({ admin, page, requestUtils }) => {
    test.setTimeout(90_000);
    const liveblog = await createLiveblog(requestUtils, "E2E desk editing");
    liveblogs.push(liveblog.id);

    await admin.visitAdminPage("admin.php", `page=postsocket&liveblog=${liveblog.id}`);
    await expect(page.getByText("No entries yet")).toBeVisible({ timeout: 15_000 });
    await waitForLive(page);

    const editorPage = await page.context().newPage();
    await editorPage.goto(`/wp-admin/post-new.php?post_type=postsocket_entry&liveblog=${liveblog.id}`);
    const row = page.getByRole("row", { name: /New entry/ });
    await expect(row).toBeVisible({ timeout: 20_000 });
    await expect(row).toContainText(/ is editing/);
    await expect(row.getByText("Not saved yet", { exact: true })).toBeVisible();

    await expect(editorPage.locator(".editor-visual-editor, .edit-post-visual-editor").first()).toBeVisible({ timeout: 20_000 });
    await editorPage.goto("/wp-admin/index.php");
    await expect(row).toBeHidden({ timeout: 30_000 });
    await editorPage.close();
  });
});
