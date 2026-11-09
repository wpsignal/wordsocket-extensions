/**
 * Living articles: swap the page's content when the post is saved.
 *
 * - Subscribes to `postsocket:post:{id}` and applies `postsocket.post.updated`, inline or by fetching the content route.
 * - Catches up once when the page was served stale (a full-page cache) and on every reconnect.
 * - Applies only content whose hash differs from what the page shows.
 *
 * @since 0.1.0
 */
import { activateScripts, ensureAssets } from "postsocket/reader/assets";
import { highlight, morphChildren, transition } from "postsocket/reader/morph";
import { debug, jitter, onRelay, subscribe } from "postsocket/reader/relay";

const STALE_PAGE_MS = 60_000;
const FETCH_JITTER_MS = 10_000;

/** A version shared by everyone fetching in the same minute, so a page cache serves the crowd. */
function minute(): string {
  return String(Math.floor(Date.now() / 60_000));
}

function boot(): void {
  const wrapper = document.querySelector<HTMLElement>("[data-postsocket-post]");
  if (!wrapper) return;
  const id = Number(wrapper.dataset.postsocketPost);
  const url = wrapper.dataset.postsocketUrl ?? "";
  const channel = `postsocket:post:${id}`;
  const renderedAt = Number(wrapper.dataset.postsocketRendered ?? 0) * 1000;
  let hash = wrapper.dataset.postsocketHash ?? "";
  let fetching = false;

  async function apply(html: string, assets: PostSocketAssets | undefined, incoming: string, when: string): Promise<void> {
    if (incoming === hash) return;
    hash = incoming;
    await ensureAssets(assets);
    await transition(() => {
      for (const el of morphChildren(wrapper!, html)) {
        activateScripts(el);
        highlight(el);
      }
    });
    wrapper!.dataset.postsocketModified = when;
    wrapper!.dataset.postsocketHash = incoming;
    debug("content applied", id, when);
  }

  async function fetchContent(version: string): Promise<void> {
    if (fetching || !url) return;
    fetching = true;
    try {
      await jitter(FETCH_JITTER_MS);
      const response = await fetch(`${url}${url.includes("?") ? "&" : "?"}v=${encodeURIComponent(version)}`, { credentials: "omit" });
      if (!response.ok) return;
      const data = (await response.json()) as ContentResponse;
      await apply(data.html, data.assets, data.hash, data.modified);
    } catch (err) {
      debug("content fetch failed", err);
    } finally {
      fetching = false;
    }
  }

  onRelay<PostUpdated>("postsocket.post.updated", channel, (data) => {
    if (data.id !== id) return;
    if (data.hash === hash) return;
    if (data.html && !data.fetch) {
      void apply(data.html, data.assets, data.hash, data.modified);
    } else {
      void fetchContent(data.hash);
    }
  });

  void subscribe(channel).then((wps) => {
    if (!wps) return;
    if (renderedAt > 0 && Date.now() - renderedAt > STALE_PAGE_MS) void fetchContent(minute());
    wps.onReconnect(() => void fetchContent(minute()));
  });
}

boot();
