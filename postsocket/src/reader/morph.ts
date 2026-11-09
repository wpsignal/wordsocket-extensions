/**
 * Swap a container's children for new markup while keeping the blocks that did not change.
 *
 * - Children are matched by their markup, loading hints aside; an unchanged block (an iframe, an embed, a long image) is never touched.
 * - Changed blocks are replaced in place, new ones inserted, missing ones removed, in a view transition where the browser has one.
 * - Children matching `keep` stay where they are and are never removed or duplicated.
 *
 * @since 0.1.0
 */
import { fragment } from "postsocket/reader/assets";

const HIGHLIGHT_CLASS = "postsocket-changed";
const HIGHLIGHT_MS = 2500;

/** What a block is compared by: its markup without the loading hints the front end adds and a save does not. */
function identity(el: Element): string {
  return el.outerHTML.replace(/\s(?:loading|decoding|fetchpriority)="[^"]*"/g, "").replace(/\s+/g, " ");
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
 * Reconcile `container`'s element children with `html`. Returns the elements that were inserted or replaced.
 *
 * @since 0.1.0
 */
export function morphChildren(container: Element, html: string, keep = "[data-postsocket-feed]"): Element[] {
  const incoming = Array.from(fragment(html).children).filter((el) => !el.matches(keep));
  const kept = Array.from(container.children).filter((el) => el.matches(keep));
  const old = Array.from(container.children).filter((el) => !el.matches(keep));

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
      changed.push(el);
    }
  }

  // A kept node holds its place by the number of siblings that were before it.
  for (const el of kept) {
    const index = Array.from(container.children).indexOf(el);
    ordered.splice(Math.min(index, ordered.length), 0, el);
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
