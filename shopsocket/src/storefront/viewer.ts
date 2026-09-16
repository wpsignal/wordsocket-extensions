/**
 * This shopper, as the server and the relay know them: their basket id, the
 * products they hold, and their presence on the carts channel. The basket id
 * comes from the server (the WooCommerce session cookie is HttpOnly), and
 * presence under it is what shows the basket as live on the board while this
 * connection is open; the relay drops it the moment the socket closes.
 */

export interface ViewerConfig {
  basketIdUrl?: string;
  nonce?: string;
  productId?: number;
  presenceChannel?: string;
}

export interface Viewer {
  /** The shopper's basket id once known, so their own events can be told apart. */
  readonly basketId: string | null;
  /** Whether this shopper holds the product. */
  holds(productId: number): boolean;
  /** How many units of the product this shopper holds, 0 when none. */
  held(productId: number): number;
}

/** This shopper's own state, from `GET /shopsocket/v1/basket-id`. */
interface ViewerState {
  id?: string;
  products?: number[];
  quantities?: Record<string, number>;
  count?: number;
}

/*
 * A random per-browser id in localStorage, shared by a shopper's tabs, so the
 * board counts visitors rather than connections. Nothing identifying goes into
 * it, and cleared storage simply makes a new visitor.
 */
const VISITOR_KEY = "shopsocket-visitor";
let cachedVisitorId: string | null = null;

/** A UUID, or a time-and-random string where `randomUUID` is missing (HTTP). */
function randomId(): string {
  if (window.crypto?.randomUUID) return window.crypto.randomUUID();
  return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`;
}

/** This browser's visitor id, created and stored on first use. */
function visitorId(): string {
  if (cachedVisitorId) return cachedVisitorId;
  try {
    let id = window.localStorage.getItem(VISITOR_KEY);
    if (!id) {
      id = randomId();
      window.localStorage.setItem(VISITOR_KEY, id);
    }
    cachedVisitorId = id;
  } catch {
    // Storage blocked: a per-load id, so this visitor's tabs may over-count.
    cachedVisitorId = randomId();
  }
  return cachedVisitorId;
}

/**
 * Start following this shopper: one sync on load, then on every change to
 * their own cart and on every recovered connection. `onCount` receives the
 * live count for the page's product whenever a sync returns one.
 */
export function startViewer(config: ViewerConfig, onCount: (productId: number, count: number) => void): Viewer {
  let basketId: string | null = null;
  // Units held per parent product; replaced on every sync, since the server's answer is the truth.
  const quantities = new Map<number, number>();
  let inFlight = false;
  let dirty = false;

  /**
   * Pull this shopper's basket id, held products, and this page's live count
   * from the server. Never on a timer; a call that lands mid-flight queues
   * exactly one more.
   */
  async function sync(): Promise<void> {
    if (!config.basketIdUrl) return;
    if (inFlight) {
      dirty = true;
      return;
    }
    inFlight = true;
    try {
      const url = new URL(config.basketIdUrl, window.location.origin);
      if (config.productId) {
        url.searchParams.set("product", String(config.productId));
      }
      const res = await fetch(url.toString(), {
        credentials: "same-origin",
        headers: config.nonce ? { "X-WP-Nonce": config.nonce } : {},
      });
      if (!res.ok) return;
      const viewer: ViewerState = await res.json();
      quantities.clear();
      (viewer?.products ?? []).forEach((id) => {
        quantities.set(Number(id), Math.max(1, Number(viewer?.quantities?.[String(id)]) || 1));
      });
      if (viewer?.id) basketId = viewer.id;
      if (config.productId && typeof viewer?.count === "number") {
        onCount(Number(config.productId), viewer.count);
      }
      const wps = window.WPS;
      if (wps && config.presenceChannel) {
        /*
         * `v` counts the visitor as online, `b` ties the basket to this connection;
         * the board dedupes `v` across a shopper's tabs.
         */
        wps.setPresence(config.presenceChannel, { v: visitorId(), b: viewer?.id ?? null });
      }
    } catch {
      // Offline or blocked: the seeded values and live events still apply.
    } finally {
      inFlight = false;
      if (dirty) {
        dirty = false;
        sync();
      }
    }
  }

  /**
   * A shopper never receives their own `woo.cart.added`, so re-sync on every cart
   * signal WooCommerce gives: the jQuery events on classic themes, the
   * `wc-blocks_*` CustomEvents block themes dispatch on `document.body`, and the
   * `wc/store/cart` wp.data store for changes that dispatch no event.
   */
  function watchOwnCart(): void {
    const jq = window.jQuery;
    if (jq) {
      jq(document.body).on("added_to_cart removed_from_cart", () => sync());
    }
    for (const type of ["wc-blocks_added_to_cart", "wc-blocks_removed_from_cart"]) {
      document.body.addEventListener(type, () => sync());
    }
    const data = window.wp?.data;
    if (!data?.subscribe || !data.select) return;
    let last: string | null = null;
    data.subscribe(() => {
      const cart = data.select("wc/store/cart");
      const items = cart?.getCartData?.()?.items;
      if (!Array.isArray(items)) return;
      const key = items.map((i) => `${i.id}:${i.quantity}`).join(",");
      if (last === null) {
        last = key;
        return;
      }
      if (key !== last) {
        last = key;
        sync();
      }
    }, "wc/store/cart");
  }

  /*
   * One fetch on load, then events and the shopper's own cart changes keep it
   * current. WordSocket's client is a classic footer script that runs before this
   * module, so `window.WPS` is already there for presence.
   */
  sync();
  watchOwnCart();

  /*
   * A recovered connection is a recovery step: the server rebuilds this shopper's
   * basket row from the sync and the client re-enters presence, so the board
   * sees them live again without waiting for a cart change.
   */
  let hasConnected = false;
  window.WPS?.onStateChange((s) => {
    if (!s.connected) return;
    if (hasConnected) sync();
    hasConnected = true;
  });

  return {
    get basketId() {
      return basketId;
    },
    holds: (productId) => quantities.has(productId),
    held: (productId) => quantities.get(productId) ?? 0,
  };
}
