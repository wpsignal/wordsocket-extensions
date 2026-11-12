/**
 * Swap a container's children for new markup while keeping the blocks that did not change.
 *
 * - Children are matched by their markup, loading hints aside; an unchanged block (an iframe, an embed, a long image) is never touched.
 * - Changed blocks are replaced in place, new ones inserted, missing ones removed, in a view transition where the browser has one.
 * - `morphElement()` does the same for a whole part of the page from a fresh copy of it, descending into what differs.
 * - A live feed is never re-rendered: the relay render marks its place with a slot, blocks compare as if the
 *   page's feed were that slot, and a changed block that holds one gets the page's feed node moved into it.
 *
 * @since 0.1.0
 */
import { fragment } from "postsocket/reader/assets";

const HIGHLIGHT_CLASS = "postsocket-changed";
const HIGHLIGHT_MS = 2500;

const FEED = "[data-postsocket-feed]";
const SLOT = "[data-postsocket-feed-slot]";

/** The slot a relay render puts where the feed for `liveblog` sits. */
function slotFor(liveblog: string): HTMLElement {
  const slot = document.createElement("div");
  slot.setAttribute("data-postsocket-feed-slot", liveblog);
  slot.hidden = true;
  return slot;
}

/** What a block is compared by: its markup with any live feed read as its slot, without the loading hints the front end adds. */
function identity(el: Element): string {
  let node: Element = el;
  if (el.matches(FEED)) {
    node = slotFor(el.getAttribute("data-postsocket-feed") ?? "");
  } else if (el.querySelector(FEED)) {
    node = el.cloneNode(true) as Element;
    for (const feed of node.querySelectorAll(FEED)) feed.replaceWith(slotFor(feed.getAttribute("data-postsocket-feed") ?? ""));
  }
  return node.outerHTML.replace(/\s(?:loading|decoding|fetchpriority)="[^"]*"/g, "").replace(/\s+/g, " ");
}

function nextElement(node: Element | null): Element | null {
  return node ? node.nextElementSibling : null;
}

function reducedMotion(): boolean {
  return window.matchMedia("(prefers-reduced-motion: reduce)").matches;
}

/**
 * Run `apply` inside a view transition when the page has one and motion is welcome.
 *
 * @since 0.1.0
 */
export function transition(apply: () => void): Promise<void> {
  const doc = document as Document & { startViewTransition?: (cb: () => void) => { finished: Promise<void> } };
  if (typeof doc.startViewTransition === "function" && !reducedMotion()) {
    return doc.startViewTransition(apply).finished.catch(() => undefined);
  }
  apply();
  return Promise.resolve();
}

/**
 * Flag an element as freshly changed for a moment, for the stylesheet's highlight.
 *
 * @since 0.1.0
 */
export function highlight(el: Element): void {
  if (reducedMotion()) return;
  el.classList.add(HIGHLIGHT_CLASS);
  setTimeout(() => el.classList.remove(HIGHLIGHT_CLASS), HIGHLIGHT_MS);
}

/**
 * Reconcile `container`'s element children with `html`, keeping the page's live feeds wherever they sit. Returns the elements that were inserted or replaced.
 *
 * @since 0.1.0
 */
export function morphChildren(container: Element, html: string): Element[] {
  const feeds = new Map<string, Element>();
  for (const feed of container.querySelectorAll(FEED)) feeds.set(feed.getAttribute("data-postsocket-feed") ?? "", feed);

  const incoming = Array.from(fragment(html).children);
  const old = Array.from(container.children);
  const pool = new Map<string, Element[]>();
  for (const el of old) {
    const list = pool.get(identity(el)) ?? [];
    list.push(el);
    pool.set(identity(el), list);
  }

  const changed: Element[] = [];
  const ordered: Element[] = [];
  for (const el of incoming) {
    const match = pool.get(identity(el))?.shift();
    if (match) {
      ordered.push(match);
    } else {
      ordered.push(el);
      if (!el.matches(SLOT)) changed.push(el);
    }
  }

  // A new block that holds a feed's slot gets the page's feed node, so its entries and state carry over.
  for (const el of ordered) {
    const slots = el.matches(SLOT) ? [el] : Array.from(el.querySelectorAll(SLOT));
    for (const slot of slots) {
      const feed = feeds.get(slot.getAttribute("data-postsocket-feed-slot") ?? "");
      if (!feed) continue;
      if (slot === el) ordered[ordered.indexOf(el)] = feed;
      else slot.replaceWith(feed);
    }
  }

  const wanted = new Set(ordered);
  let cursor = container.firstElementChild;
  for (const target of ordered) {
    while (cursor && !wanted.has(cursor)) {
      const gone = cursor;
      cursor = nextElement(cursor);
      gone.remove();
    }
    if (cursor === target) {
      cursor = nextElement(cursor);
      continue;
    }
    container.insertBefore(target, cursor);
  }
  while (cursor) {
    const gone = cursor;
    cursor = nextElement(cursor);
    if (!wanted.has(gone)) gone.remove();
  }
  return changed;
}

const POST = "[data-postsocket-post]";
const UNTOUCHED = "script, style, noscript, template, #wpadminbar";

/** Whether an element holds text of its own beside any child elements. */
function hasOwnText(el: Element): boolean {
  return Array.from(el.childNodes).some((node) => node.nodeType === Node.TEXT_NODE && (node.textContent ?? "").trim() !== "");
}

/** Give `target` the attributes of `source`. */
function syncAttributes(target: Element, source: Element): void {
  for (const { name } of Array.from(target.attributes)) {
    if (!source.hasAttribute(name)) target.removeAttribute(name);
  }
  for (const { name, value } of Array.from(source.attributes)) {
    if (target.getAttribute(name) !== value) target.setAttribute(name, value);
  }
}

/**
 * Make `target` match `source`, a fresh copy of the same part of the page, changing only what differs. Returns the elements that were inserted, replaced or refilled.
 *
 * - Live feeds, scripts, styles and the admin bar are left as they are.
 * - The living wrapper keeps its node and has its blocks reconciled as a content update does.
 *
 * @since 0.1.0
 */
export function morphElement(target: Element, source: Element): Element[] {
  if (target.matches(FEED) || target.matches(UNTOUCHED) || target.isEqualNode(source)) return [];
  syncAttributes(target, source);

  const olds = Array.from(target.children);
  const news = Array.from(source.children);
  if (!news.length || !olds.length || hasOwnText(target) || hasOwnText(source)) {
    if (target.innerHTML === source.innerHTML) return [];
    target.innerHTML = source.innerHTML;
    return [target];
  }
  const sameShape = olds.length === news.length && olds.every((el, i) => el.tagName === news[i].tagName);
  if (target.matches(POST) || !sameShape) {
    const copy = source.cloneNode(true) as Element;
    for (const feed of copy.querySelectorAll(FEED)) feed.replaceWith(slotFor(feed.getAttribute("data-postsocket-feed") ?? ""));
    return morphChildren(target, copy.innerHTML);
  }
  return olds.flatMap((el, i) => morphElement(el, news[i]));
}

