/**
 * Living articles: swap the page's content when the post is saved.
 *
 * - Subscribes to `postsocket:post:{id}` and applies `postsocket.post.updated`, inline or by fetching the content route.
 * - Catches up once when the page was served stale (a full-page cache) and on every reconnect.
 * - Applies only content whose hash differs from what the page shows.
 * - Renames the post's title in the headings `postsocket_title_selector` names (a block theme's marked Post Title by default) and in the tab title.
 * - Fills the post's live fields, each in the elements its selector names, `[data-postsocket-field="{name}"]` by default.
 * - Where `postsocket_refresh_selector` names a part of the page, fetches the page again and morphs that part in place for a change that touched neither the title nor the content, and for a live field whose new value has no element on the page.
 * - Reloads the page for a change `postsocket_reload_live_fields` or `postsocket_reload_live_taxonomies` names, once the page's URL has answered a GET successfully and the tab is in view.
 * - Dispatches `postsocket:updated` on `document` after each applied update.
 *
 * @since 0.1.0
 */
import { activateMarkup, ensureAssets } from "postsocket/reader/assets";
import {
  highlight,
  morphChildren,
  morphElement,
  transition,
} from "postsocket/reader/morph";
import { debug, jitter, onRelay, subscribe } from "postsocket/reader/relay";

const STALE_PAGE_MS = 60_000;
const FETCH_JITTER_MS = 10_000;
const RELOAD_RETRY_MS = [5_000, 15_000, 30_000];

/** A version shared by everyone fetching in the same minute, so a page cache serves the crowd. */
function minute(): string {
  return String(Math.floor(Date.now() / 60_000));
}

function boot(): void {
  let wrapper = document.querySelector<HTMLElement>("[data-postsocket-post]")!;
  if (!wrapper) return;
  const id = Number(wrapper.dataset.postsocketPost);
  const url = wrapper.dataset.postsocketUrl ?? "";
  const channel = `postsocket:post:${id}`;
  const renderedAt = Number(wrapper.dataset.postsocketRendered ?? 0) * 1000;
  let hash = wrapper.dataset.postsocketHash ?? "";
  let title = wrapper.dataset.postsocketHeadline ?? "";
  let fetching = false;
  let refreshing = false;
  let reloading = false;
  let refreshAgain = false;
  const refreshSelector = wrapper.dataset.postsocketRefresh ?? "";
  const titleSelector =
    wrapper.dataset.postsocketTitleSelector ||
    `[data-postsocket-title="${id}"]`;

  /** The headings the `postsocket_title_selector` filter names, none when it is not a valid selector. */
  function headings(): HTMLElement[] {
    try {
      return Array.from(document.querySelectorAll<HTMLElement>(titleSelector));
    } catch {
      debug("title selector is not valid", titleSelector);
      return [];
    }
  }

  /** The selector of each live field that has one of its own. */
  function fieldSelectors(): Record<string, string> {
    try {
      return JSON.parse(wrapper.dataset.postsocketFields || "{}") as Record<
        string,
        string
      >;
    } catch {
      return {};
    }
  }
  const selectors = fieldSelectors();

  const unplaced: Record<string, string> = {};

  /** Put each live field's HTML in its elements where it differs; `missing` is true when a field with a new value has no element on the page. */
  function applyFields(fields: Record<string, string> | undefined): {
    changed: Element[];
    missing: boolean;
  } {
    const changed: Element[] = [];
    let missing = false;
    for (const [name, html] of Object.entries(fields ?? {})) {
      const selector =
        selectors[name] || `[data-postsocket-field="${CSS.escape(name)}"]`;
      let targets: Element[] = [];
      try {
        targets = Array.from(document.querySelectorAll(selector));
      } catch {
        debug("field selector is not valid", name, selector);
      }
      if (!targets.length) {
        if (html !== (unplaced[name] ?? "")) missing = true;
        unplaced[name] = html;
        continue;
      }
      delete unplaced[name];
      for (const target of targets) {
        if (target.innerHTML === html) continue;
        target.innerHTML = html;
        changed.push(target);
      }
    }
    return { changed, missing };
  }

  /** Put a new title on the marked headings, inside a link when the block links it, and in the tab title. */
  function retitle(next: string | undefined): void {
    if (next === undefined || next === title) return;
    for (const heading of headings()) {
      const target = heading.querySelector<HTMLElement>("a") ?? heading;
      target.textContent = next;
      highlight(heading);
    }
    if (title && document.title.includes(title))
      document.title = document.title.replace(title, next);
    title = next;
    wrapper.dataset.postsocketHeadline = next;
  }

  async function apply(
    data: ContentResponse | PostUpdated,
    html: string,
  ): Promise<void> {
    if (data.hash === hash) return;
    hash = data.hash;
    retitle(data.title);
    await ensureAssets(data.assets);
    let missing = false;
    await transition(() => {
      const placed = applyFields(data.fields);
      missing = placed.missing;
      const changed = [...morphChildren(wrapper!, html), ...placed.changed];
      activateMarkup(changed);
      changed.forEach(highlight);
    });
    wrapper.dataset.postsocketModified = data.modified;
    wrapper.dataset.postsocketHash = data.hash;
    const detail: PostSocketUpdatedDetail = {
      id,
      title,
      fields: data.fields ?? {},
    };
    document.dispatchEvent(new CustomEvent("postsocket:updated", { detail }));
    debug("content applied", id, data.modified);
    if (missing && refreshSelector) {
      debug("a live field has no element, refreshing the page", id);
      void refreshPage();
    }
  }

  async function fetchContent(version: string): Promise<void> {
    if (fetching || !url) return;
    fetching = true;
    try {
      await jitter(FETCH_JITTER_MS);
      const response = await fetch(
        `${url}${url.includes("?") ? "&" : "?"}v=${encodeURIComponent(version)}`,
        { credentials: "omit" },
      );
      if (!response.ok) return;
      const data = (await response.json()) as ContentResponse;
      await apply(data, data.html);
    } catch (err) {
      debug("content fetch failed", err);
    } finally {
      fetching = false;
    }
  }

  /** Fetch the page again and morph the part the refresh selector names, once more when a change arrived meanwhile. */
  async function refreshPage(): Promise<void> {
    if (refreshing) {
      refreshAgain = true;
      return;
    }
    refreshing = true;
    try {
      await jitter(FETCH_JITTER_MS);
      refreshAgain = false;
      const response = await fetch(window.location.href, {
        cache: "no-store",
        credentials: "same-origin",
      });
      if (!response.ok) return;
      const page = new DOMParser().parseFromString(
        await response.text(),
        "text/html",
      );
      const target = refreshSelector
        ? document.querySelector(refreshSelector)
        : document.body;
      console.log({ target, refreshSelector });
      const source = page.querySelector(refreshSelector);
      if (!target || !source) {
        debug("refresh selector matched nothing", refreshSelector);
        return;
      }
      await transition(() => {
        const changed = morphElement(target, source);
        activateMarkup(changed);
        changed.forEach(highlight);
      });
      wrapper =
        document.querySelector<HTMLElement>("[data-postsocket-post]") ??
        wrapper;
      hash = wrapper.dataset.postsocketHash ?? hash;
      title = wrapper.dataset.postsocketHeadline ?? title;
      if (page.title) document.title = page.title;
      const detail: PostSocketUpdatedDetail = {
        id,
        title,
        fields: {},
        refreshed: true,
      };
      document.dispatchEvent(new CustomEvent("postsocket:updated", { detail }));
      debug("page refreshed", id, refreshSelector);
    } catch (err) {
      debug("page refresh failed", err);
    } finally {
      refreshing = false;
      if (refreshAgain) void refreshPage();
    }
  }

  /** Whether the page's URL answers a GET successfully right now. */
  async function pageAnswers(): Promise<boolean> {
    try {
      const response = await fetch(window.location.href, { cache: "no-store", credentials: "same-origin" });
      return response.ok;
    } catch {
      return false;
    }
  }

  /** Reload the page after the usual delay, once its URL has answered a GET successfully and the tab is in view; give up when it never does. */
  async function reloadPage(): Promise<void> {
    if (reloading) return;
    reloading = true;
    await jitter(FETCH_JITTER_MS);
    for (const wait of [0, ...RELOAD_RETRY_MS]) {
      if (wait) await new Promise((resolve) => setTimeout(resolve, wait));
      if (!(await pageAnswers())) continue;
      if (document.visibilityState === "visible") window.location.reload();
      else document.addEventListener("visibilitychange", () => window.location.reload(), { once: true });
      return;
    }
    reloading = false;
    debug("page did not answer, not reloading", id);
  }

  /** Catch up with the post: the page where it refreshes, else its content. */
  const catchUp = (): void =>
    void (refreshSelector ? refreshPage() : fetchContent(minute()));

  onRelay<PostUpdated>("postsocket.post.updated", channel, (data) => {
    console.log({ data });
    if (data.id !== id) return;
    if (data.reload) {
      void reloadPage();
      return;
    }
    if (data.refresh) {
      console.log("FULL REFRESH");
      if (refreshSelector) void refreshPage();
      return;
    }
    if (data.hash === hash) return;
    console.log("APPLYING");
    if (data.html && !data.fetch) void apply(data, data.html);
    else void fetchContent(data.hash);
  });

  void subscribe(channel).then((wps) => {
    if (!wps) return;
    if (renderedAt > 0 && Date.now() - renderedAt > STALE_PAGE_MS) catchUp();
    wps.onReconnect(catchUp);
  });
}

boot();
