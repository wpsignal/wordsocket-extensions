import "./env";
import { expect, test } from "@wordpress/e2e-test-utils-playwright";
import { deletePost, paragraph, saveSettings, updatePost, visitorContext, waitForLive } from "./helpers";

/**
 * A reader of an ordinary post of an enabled type sees a saved edit land in place, with the
 * blocks that did not change (an iframe) left alone, and a large edit fetched instead of carried.
 */
test.describe("living articles", () => {
  let post = 0;
  const iframe = '<!-- wp:html --><iframe src="about:blank" id="postsocket-e2e-frame" width="300" height="80"></iframe><!-- /wp:html -->';

  test.beforeAll(async ({ requestUtils }) => {
    await saveSettings(requestUtils, { living_types: ["post"] });
    post = ((await requestUtils.rest({
      method: "POST",
      path: "/wp/v2/posts",
      data: { title: "E2E living article", content: paragraph("First version.") + iframe + paragraph("Closing line."), status: "publish" },
    })) as { id: number }).id;
  });

  test.afterAll(async ({ requestUtils }) => {
    await deletePost(requestUtils, "posts", post);
    await saveSettings(requestUtils, { living_types: [] });
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

    await updatePost(requestUtils, "posts", post, { content: paragraph("Long text. ".repeat(4000) + "THE END") });
    await expect(wrapper).toContainText("THE END", { timeout: 25_000 });
    await reader.context().close();
  });

  test("a post type that is not enabled carries no wrapper", async ({ browser, requestUtils, baseURL }) => {
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
