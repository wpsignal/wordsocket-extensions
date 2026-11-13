/**
 * The live feed on a live blog's page.
 *
 * - Subscribes to `postsocket:liveblog:{id}`; inserts, replaces, re-pins and removes entries by id.
 * - A new entry lands at the top at once while the reader is at the top, otherwise waits behind the "N new updates" pill.
 * - Loads older entries from the entries route; catches up from its first page when the page was served stale and on every reconnect.
 * - For a signed-in editor, fetches the live blog's unpublished entries and shows them in place, labelled, and again whenever the desk channel says one changed.
 *
 * @since 0.1.0
 */
import { store } from "@wordpress/interactivity";
import { activateMarkup, ensureAssets, fragment } from "postsocket/reader/assets";
import { highlight, transition } from "postsocket/reader/morph";
import { debug, jitter, onRelay, subscribe } from "postsocket/reader/relay";

const STORE = "postsocket/feed";
const STALE_PAGE_MS = 60_000;
const CATCH_UP_JITTER_MS = 5_000;
const LEAVE_MS = 400;
const FLIP_MS = 350;
const SCROLLED_PAST_PX = 120;

interface FeedState {
  channel: string;
  entriesUrl: string;
  draftsUrl: string;
  nonceUrl: string;
  deskChannel: string;
  perPage: number;
  i18n: { live: string; ended: string; pending: string; one: string };
  ended: boolean;
  more: boolean;
  pending: number;
  loading: boolean;
  readonly badge: string;
  readonly pendingLabel: string;
}

const feed = document.querySelector<HTMLElement>("[data-postsocket-feed]");
const pinnedList = feed?.querySelector<HTMLElement>("[data-postsocket-pinned-list]") ?? null;
const entryList = feed?.querySelector<HTMLElement>("[data-postsocket-entry-list]") ?? null;
const liveblogId = Number(feed?.dataset.postsocketFeed ?? 0);
let oldest = feed?.dataset.postsocketOldest ?? "";
let oldestId = Number(feed?.dataset.postsocketOldestId ?? 0);
const queue: HTMLElement[] = [];
const leaving = new Map<number, number>();
let refreshing = false;

interface FeedStore {
  state: FeedState;
  actions: { showPending(): void; loadOlder(): Promise<void> };
}

const { state } = store<FeedStore>(STORE, {
  state: {
    get badge(): string {
      return state.ended ? state.i18n.ended : state.i18n.live;
    },
    get pendingLabel(): string {
      return state.pending === 1 ? state.i18n.one : state.i18n.pending.replace("%d", String(state.pending));
    },
  },
  actions: {
    showPending(): void {
      const held = queue.splice(0, queue.length);
      state.pending = 0;
      if (!held.length || !feed) return;
      void transition(() => flip(() => held.forEach((el) => place(el))));
      feed.scrollIntoView({ behavior: "smooth", block: "start" });
    },
    async loadOlder(): Promise<void> {
      if (state.loading || !oldest) return;
      state.loading = true;
      try {
        const data = await getEntries({ before: oldest, beforeId: oldestId, limit: state.perPage });
        if (!data) return;
        for (const item of data.entries) {
          await ensureAssets(item.assets);
          const el = element(item.html);
          if (el && !find(item.id)) {
            (item.pinned ? pinnedList : entryList)?.appendChild(el);
            activateMarkup(el);
          }
        }
        const last = data.entries[data.entries.length - 1];
        if (last) {
          oldest = last.date;
          oldestId = last.id;
        }
        state.more = data.more;
      } finally {
        state.loading = false;
      }
    },
  },
});

/** The element an entry's HTML parses to. */
function element(html: string): HTMLElement | null {
  const el = fragment(html).firstElementChild;
  return el instanceof HTMLElement ? el : null;
}

function find(id: number): HTMLElement | null {
  return feed?.querySelector<HTMLElement>(`[data-postsocket-entry="${id}"]`) ?? null;
}

/** Whether the reader is still at the top of the feed, where a new entry may land without moving what they read. */
function atTop(): boolean {
  if (!feed) return true;
  return feed.getBoundingClientRect().top > -SCROLLED_PAST_PX;
}

/** Put an entry in its list, newest first by date, replacing the element with the same id. */
function place(el: HTMLElement): void {
  const id = Number(el.dataset.postsocketEntry);
  const list = el.dataset.postsocketPinned === "1" ? pinnedList : entryList;
  if (!list) return;
  const timer = leaving.get(id);
  if (timer !== undefined) {
    clearTimeout(timer);
    leaving.delete(id);
  }
  const existing = find(id);
  if (existing && existing.outerHTML === el.outerHTML && existing.parentElement === list) return;
  existing?.remove();
  const date = el.dataset.postsocketDate ?? "";
  let before: Element | null = null;
  for (const sibling of Array.from(list.children)) {
    const siblingDate = (sibling as HTMLElement).dataset.postsocketDate ?? "";
    const siblingId = Number((sibling as HTMLElement).dataset.postsocketEntry);
    if (siblingDate < date || (siblingDate === date && siblingId < id)) {
      before = sibling;
      break;
    }
  }
  list.insertBefore(el, before);
  activateMarkup(el);
  highlight(el);
}

/** Run `mutate` and slide every entry from where it was to where it is. */
function flip(mutate: () => void): void {
  if (!feed || window.matchMedia("(prefers-reduced-motion: reduce)").matches) {
    mutate();
    return;
  }
  const before = new Map<Element, DOMRect>();
  feed.querySelectorAll("[data-postsocket-entry]").forEach((el) => before.set(el, el.getBoundingClientRect()));
  mutate();
  feed.querySelectorAll("[data-postsocket-entry]").forEach((el) => {
    const from = before.get(el);
    if (!from) return;
    const to = el.getBoundingClientRect();
    const dy = from.top - to.top;
    if (Math.abs(dy) < 1) return;
    el.animate([{ transform: `translateY(${dy}px)` }, { transform: "translateY(0)" }], { duration: FLIP_MS, easing: "ease-out" });
  });
}

async function upsert(data: EntryUpdated): Promise<void> {
  if (data.liveblog !== liveblogId) return;
  if (!data.html || data.fetch) {
    void refresh();
    return;
  }
  await ensureAssets(data.assets);
  const el = element(data.html);
  if (!el) return;
  const existing = find(data.id);
  const newAtTop = !existing && !data.pinned;
  if (newAtTop && !atTop()) {
    const held = queue.findIndex((q) => q.dataset.postsocketEntry === String(data.id));
    if (held >= 0) queue.splice(held, 1, el);
    else queue.push(el);
    state.pending = queue.length;
    return;
  }
  await transition(() => flip(() => place(el)));
}

function remove(id: number): void {
  const held = queue.findIndex((q) => q.dataset.postsocketEntry === String(id));
  if (held >= 0) {
    queue.splice(held, 1);
    state.pending = queue.length;
  }
  const el = find(id);
  if (!el || leaving.has(id)) return;
  el.classList.add("is-leaving");
  leaving.set(
    id,
    window.setTimeout(() => {
      leaving.delete(id);
      flip(() => el.remove());
    }, LEAVE_MS),
  );
}

async function getEntries(params: { before?: string; beforeId?: number; limit?: number; v?: string }): Promise<EntriesResponse | null> {
  const url = new URL(state.entriesUrl, window.location.href);
  if (params.before) url.searchParams.set("before", params.before);
  if (params.beforeId) url.searchParams.set("before_id", String(params.beforeId));
  if (params.limit) url.searchParams.set("limit", String(params.limit));
  if (params.v) url.searchParams.set("v", params.v);
  try {
    const response = await fetch(url.toString(), { credentials: "omit" });
    if (!response.ok) return null;
    return (await response.json()) as EntriesResponse;
  } catch (err) {
    debug("entries fetch failed", err);
    return null;
  }
}

/** Fetch the newest page and reconcile: every entry in it is upserted, and an entry it should hold but does not is removed. */
async function refresh(): Promise<void> {
  if (refreshing || !feed) return;
  refreshing = true;
  try {
    await jitter(CATCH_UP_JITTER_MS);
    const data = await getEntries({ limit: state.perPage, v: String(Math.floor(Date.now() / 60_000)) });
    if (!data) return;
    state.ended = data.state === "ended";
    const ids = new Set(data.entries.map((e) => e.id));
    const floor = data.entries.filter((e) => !e.pinned).map((e) => e.date).sort()[0] ?? "";
    for (const item of data.entries) {
      await ensureAssets(item.assets);
      const el = element(item.html);
      const existing = find(item.id);
      if (el && (!existing || existing.outerHTML !== el.outerHTML)) await transition(() => flip(() => place(el)));
    }
    feed.querySelectorAll<HTMLElement>("[data-postsocket-entry]").forEach((el) => {
      const id = Number(el.dataset.postsocketEntry);
      const date = el.dataset.postsocketDate ?? "";
      if (el.dataset.postsocketStatus) return;
      if (!ids.has(id) && (el.dataset.postsocketPinned === "1" || date >= floor)) remove(id);
    });
    if (!oldest && data.entries.length) {
      oldest = data.entries[data.entries.length - 1].date;
      oldestId = data.entries[data.entries.length - 1].id;
    }
    if (data.more) state.more = true;
  } finally {
    refreshing = false;
  }
}

let draftsLoading = false;
let draftsAgain = false;
let watchingDesk = false;

/** Fetch the unpublished entries a signed-in editor may see and make the feed match: each placed, and one no longer among them taken out. */
async function loadDrafts(): Promise<void> {
  if (!feed || !state.draftsUrl || !document.body.classList.contains("logged-in")) return;
  if (draftsLoading) {
    draftsAgain = true;
    return;
  }
  draftsLoading = true;
  try {
    draftsAgain = false;
    const asked = await fetch(state.nonceUrl, { credentials: "same-origin", cache: "no-store" });
    const nonce = asked.ok ? (await asked.text()).trim() : "";
    if (!nonce || nonce === "0") return;
    const response = await fetch(state.draftsUrl, { credentials: "same-origin", cache: "no-store", headers: { "X-WP-Nonce": nonce } });
    if (!response.ok) return;
    const data = (await response.json()) as DraftsResponse;
    const ids = new Set(data.entries.map((item) => item.id));
    for (const item of data.entries) {
      await ensureAssets(item.assets);
      const el = element(item.html);
      if (el) await transition(() => flip(() => place(el)));
    }
    feed.querySelectorAll<HTMLElement>("[data-postsocket-entry][data-postsocket-status]").forEach((el) => {
      const id = Number(el.dataset.postsocketEntry);
      if (!ids.has(id)) remove(id);
    });
    if (!watchingDesk && state.deskChannel) {
      watchingDesk = true;
      onRelay("postsocket.desk.changed", state.deskChannel, () => void loadDrafts());
      void subscribe(state.deskChannel);
    }
  } catch (err) {
    debug("drafts fetch failed", err);
  } finally {
    draftsLoading = false;
    if (draftsAgain) void loadDrafts();
  }
}

function boot(): void {
  if (!feed || !liveblogId) return;
  const channel = state.channel || `postsocket:liveblog:${liveblogId}`;
  const renderedAt = Number(feed.dataset.postsocketRendered ?? 0) * 1000;

  onRelay<EntryUpdated>("postsocket.entry.updated", channel, (data) => void upsert(data));
  onRelay<EntryRemoved>("postsocket.entry.removed", channel, (data) => {
    if (data.liveblog === liveblogId) remove(data.id);
  });
  onRelay<LiveblogState>("postsocket.liveblog.state", channel, (data) => {
    if (data.id !== liveblogId) return;
    state.ended = data.state === "ended";
    feed.dataset.postsocketState = data.state;
  });

  void subscribe(channel).then((wps) => {
    if (!wps) return;
    if (renderedAt > 0 && Date.now() - renderedAt > STALE_PAGE_MS) void refresh();
    wps.onReconnect(() => {
      void refresh();
      void loadDrafts();
    });
  });
  void loadDrafts();
}

boot();
