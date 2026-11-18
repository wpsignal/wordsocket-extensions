import "./env";
import { expect, test } from "@wordpress/e2e-test-utils-playwright";
import { wp } from "./env";
import { keepSettings } from "./helpers";

/**
 * The PostSocket tab on WordSocket's settings page saves, and the Extensions tab shows the card.
 */
test.describe("settings tab", () => {
  let restore = (): void => undefined;
  test.beforeAll(() => {
    restore = keepSettings();
    wp("option", "update", "postsocket_settings", '{"living_types":[]}', "--format=json");
  });
  test.afterAll(() => restore());

  test("saves the enabled post types and the feed options", async ({ admin, page }) => {
    await admin.visitAdminPage("options-general.php", "page=wordsocket&tab=postsocket");
    await expect(page.getByRole("tab", { name: "PostSocket" })).toBeVisible({ timeout: 15_000 });
    const posts = page.getByRole("checkbox", { name: "Posts", exact: true });
    await expect(posts).toBeVisible({ timeout: 15_000 });
    await posts.click();
    await page.getByRole("switch", { name: "Show who wrote each entry" }).click();
    await page.getByRole("button", { name: "Save changes" }).click();
    await expect(page.locator(".components-snackbar")).toContainText("PostSocket settings saved.", { timeout: 15_000 });

    const saved = JSON.parse(wp("option", "get", "postsocket_settings", "--format=json")) as { living_types: string[]; show_author: boolean };
    expect(saved.living_types).toEqual(["post"]);
    expect(saved.show_author).toBe(false);

    await page.reload();
    await expect(page.getByRole("checkbox", { name: "Posts", exact: true })).toBeChecked({ timeout: 15_000 });
    await page.getByRole("switch", { name: "Show who wrote each entry" }).click();
    await page.getByRole("checkbox", { name: "Posts", exact: true }).click();
    await page.getByRole("button", { name: "Save changes" }).click();
    await expect(page.locator(".components-snackbar").last()).toContainText("PostSocket settings saved.", { timeout: 15_000 });
  });

  test("the Extensions tab shows the PostSocket card", async ({ admin, page }) => {
    await admin.visitAdminPage("options-general.php", "page=wordsocket&tab=extensions");
    const card = page.locator(".wpsignal-extension", { hasText: "PostSocket" }).first();
    await expect(card).toBeVisible({ timeout: 15_000 });
    await expect(card.getByRole("link", { name: "Open the Live Desk" })).toHaveAttribute("href", /admin\.php\?page=postsocket$/);
  });
});
