import { expect, type Browser, type BrowserContext, type Page } from "@playwright/test";
import type { RequestUtils } from "@wordpress/e2e-test-utils-playwright";

/** Wait until window.WPS reports a connection on the current page, then move the pointer so a journey begins. */
export async function waitForLive(page: Page): Promise<void> {
  await expect.poll(() => page.evaluate(() => window.WPS?.state.connected ?? false), { timeout: 20_000 }).toBe(true);
  await page.mouse.move(12, 12);
  await page.mouse.move(48, 48);
}

/** A fresh anonymous browser (own cookies and storage): a reader, not the logged-in admin. */
export function visitorContext(browser: Browser, baseURL?: string): Promise<BrowserContext> {
  return browser.newContext({ baseURL, ignoreHTTPSErrors: true, storageState: undefined });
}

interface PostRecord {
  id: number;
  link: string;
  status: string;
}

/** Create a published live blog through REST. */
export async function createLiveblog(requestUtils: RequestUtils, title: string, content = "<!-- wp:paragraph --><p>Kick-off at three.</p><!-- /wp:paragraph -->"): Promise<PostRecord> {
  return (await requestUtils.rest({
    method: "POST",
    path: "/wp/v2/postsocket_liveblog",
    data: { title, content, status: "publish" },
  })) as PostRecord;
}

/** Create a published entry on a live blog through REST. */
export async function createEntry(requestUtils: RequestUtils, liveblog: number, content: string, extra: Record<string, unknown> = {}): Promise<PostRecord> {
  return (await requestUtils.rest({
    method: "POST",
    path: "/wp/v2/postsocket_entry",
    data: { liveblog, content, status: "publish", ...extra },
  })) as PostRecord;
}

/** Update a post of either type through REST. */
export async function updatePost(requestUtils: RequestUtils, type: string, id: number, data: Record<string, unknown>): Promise<PostRecord> {
  return (await requestUtils.rest({ method: "POST", path: `/wp/v2/${type}/${id}`, data })) as PostRecord;
}

/** Force-delete a post of either type through REST, ignoring one that is gone. */
export async function deletePost(requestUtils: RequestUtils, type: string, id: number): Promise<void> {
  await requestUtils.rest({ method: "DELETE", path: `/wp/v2/${type}/${id}`, params: { force: true } }).catch(() => undefined);
}

/** Read or write PostSocket's settings through its route. */
export async function saveSettings(requestUtils: RequestUtils, settings: Record<string, unknown>): Promise<void> {
  await requestUtils.rest({ method: "POST", path: "/postsocket/v1/settings", data: settings });
}

/** A paragraph block. */
export function paragraph(text: string): string {
  return `<!-- wp:paragraph --><p>${text}</p><!-- /wp:paragraph -->`;
}
