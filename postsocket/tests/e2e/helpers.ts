import { expect, type Browser, type BrowserContext, type Page } from "@playwright/test";
import type { RequestUtils } from "@wordpress/e2e-test-utils-playwright";
import { wp } from "./env";

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

interface TermRecord {
  id: number;
  name: string;
}

/** Create a live blog (a term) through REST. */
export async function createLiveblog(requestUtils: RequestUtils, name: string): Promise<TermRecord> {
  return (await requestUtils.rest({
    method: "POST",
    path: "/wp/v2/liveblogs",
    data: { name: `${name} ${Date.now().toString(36)}` },
  })) as TermRecord;
}

/** Update a live blog's fields or meta through REST. */
export async function updateLiveblog(requestUtils: RequestUtils, id: number, data: Record<string, unknown>): Promise<TermRecord> {
  return (await requestUtils.rest({ method: "POST", path: `/wp/v2/liveblogs/${id}`, data })) as TermRecord;
}

/** Delete a live blog through REST, ignoring one that is gone. */
export async function deleteLiveblog(requestUtils: RequestUtils, id: number): Promise<void> {
  await requestUtils.rest({ method: "DELETE", path: `/wp/v2/liveblogs/${id}`, params: { force: true } }).catch(() => undefined);
}

/** The Live Feed block for a live blog. */
export function feedBlock(liveblog: number): string {
  return `<!-- wp:postsocket/live-feed {"liveblog":${liveblog}} /-->`;
}

/** Create a published post that shows a live blog through REST. */
export async function createHost(requestUtils: RequestUtils, liveblog: number, title: string): Promise<PostRecord> {
  return (await requestUtils.rest({
    method: "POST",
    path: "/wp/v2/posts",
    data: { title, content: paragraph("Kick-off at three.") + feedBlock(liveblog), status: "publish" },
  })) as PostRecord;
}

/** Create a published entry on a live blog through REST. */
export async function createEntry(requestUtils: RequestUtils, liveblog: number, content: string, extra: Record<string, unknown> = {}): Promise<PostRecord> {
  return (await requestUtils.rest({
    method: "POST",
    path: "/wp/v2/postsocket_entry",
    data: { liveblogs: [liveblog], content, status: "publish", ...extra },
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

/** Snapshot PostSocket's settings on the site; the returned function puts them back exactly, deleting them when there were none. */
export function keepSettings(): () => void {
  let saved: string | null = null;
  try {
    saved = wp("option", "get", "postsocket_settings", "--format=json").trim() || null;
  } catch {
    saved = null;
  }
  return () => {
    if (saved) wp("option", "update", "postsocket_settings", saved, "--format=json");
    else {
      try {
        wp("option", "delete", "postsocket_settings");
      } catch {
        /* Already gone. */
      }
    }
  };
}
