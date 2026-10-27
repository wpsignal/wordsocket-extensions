/*
 * This shopper, as the server and the relay know them.
 *
 * - The cart id comes from the server and rides in presence as `b`.
 * - Presence is WordSocket's `WPS.presence.track()`, which carries `v`, `n` and `j` and reports
 *   visits and leaves; this module adds `b`, reads the shopper's state from the visit report's
 *   answer, and falls back to plain `setPresence` on an older WordSocket.
 */

export interface ViewerConfig {
  cartIdUrl?: string;
  nonce?: string;
  productId?: number;
  presenceChannel?: string;
}

/** What the rules' cart context reads, from the last sync. */
export interface CartSnapshot {
  count: number;
  total: number;
  currency: string;
  products: Array<{
    id: number;
    name: string;
    qty: number;
    categories: number[];
  }>;
  /** The first product's name, for `{cart.product}`. */
  product: string;
}

export interface Viewer {
  /** The shopper's cart id once known, so their own events can be told apart. */
  readonly cartId: string | null;
  /** The cart as last synced, for the rules' context. */
  readonly cart: CartSnapshot;
  /** Called after every sync, when the cart may have changed. Returns the unsubscribe. */
  onCart(handler: (cart: CartSnapshot) => void): () => void;
  /** Re-read this shopper's cart from the server. */
  sync(): void;
  /** Whether this shopper holds the product. */
  holds(productId: number): boolean;
  /** How many units of the product this shopper holds, 0 when none. */
  held(productId: number): number;
}

/** This shopper's own state, from `GET /shopsocket/v1/cart-id`. */
interface ViewerState {
  id?: string;
  products?: number[];
  quantities?: Record<string, number>;
  count?: number;
  total?: number;
  currency?: string;
  items?: CartSnapshot["products"];
}

/**
 * Start following this shopper: one sync on load, then on every change to
 * their own cart and on every recovered connection. `onCount` receives the
 * live count for the page's product whenever a sync returns one.
 *
 * @since 0.8.0 Reads the shopper's state from the visit answer and reports only with a cart.
 */
export function startViewer(
  config: ViewerConfig,
  onCount: (productId: number, count: number) => void,
  onHeld?: (productIds: number[]) => void,
): Viewer {
  let cartId: string | null = null;
  // Units held per parent product; replaced on every sync, since the server's answer is the truth.
  const quantities = new Map<number, number>();
  let inFlight = false;
  let dirty = false;
  let tracker: WPSPresenceTracker | null = null;
  let cart: CartSnapshot = {
    count: 0,
    total: 0,
    currency: "",
    products: [],
    product: "",
  };
  const cartHandlers = new Set<(cart: CartSnapshot) => void>();

  /*
   * `v` (WordSocket's per-browser visitor id) counts the visitor as online,
   * `b` ties the cart to this connection; the board dedupes `v` across tabs.
   * The tracker adds `n` and `j` and keeps the journey; it is started once,
   * on the first sync, and updated with the cart id after each.
   */
  function announce(b: string | null): void {
    const wps = window.WPS;
    if (!wps || !config.presenceChannel) return;
    if (wps.presence) {
      if (tracker) tracker.update({ b });
      else
        tracker = wps.presence.track(config.presenceChannel, { state: { b } });
      return;
    }
    wps.setPresence(config.presenceChannel, { v: wps.visitorId(), b });
  }

  /** Take in this shopper's state as the server answered it, from either route. */
  function apply(viewer: ViewerState): void {
    quantities.clear();
    (viewer?.products ?? []).forEach((id) => {
      quantities.set(
        Number(id),
        Math.max(1, Number(viewer?.quantities?.[String(id)]) || 1),
      );
    });
    if (viewer?.id) cartId = viewer.id;
    const items = viewer?.items ?? [];
    cart = {
      count: items.reduce(
        (n, item) => n + Math.max(1, Number(item.qty) || 1),
        0,
      ),
      total: Number(viewer?.total) || 0,
      currency: viewer?.currency ?? "",
      products: items,
      product: items[0]?.name ?? "",
    };
    cartHandlers.forEach((fn) => fn(cart));
    onHeld?.(Array.from(quantities.keys()));
    if (config.productId && typeof viewer?.count === "number") {
      onCount(Number(config.productId), viewer.count);
    }
    announce(viewer?.id ?? null);
  }

  /**
   * Bring this shopper's cart id, held products, and this page's live count
   * up to date: the tracker reports the visit again, which lands the cart on
   * the journey's row and answers with the state, or without one the server
   * is asked. Never on a timer; a fetch that lands mid-flight queues exactly
   * one more.
   */
  async function sync(): Promise<void> {
    if (tracker && typeof tracker.report === "function") {
      tracker.report();
      return;
    }
    if (!config.cartIdUrl) return;
    if (inFlight) {
      dirty = true;
      return;
    }
    inFlight = true;
    try {
      const url = new URL(config.cartIdUrl, window.location.origin);
      if (config.productId) {
        url.searchParams.set("product", String(config.productId));
      }
      const res = await fetch(url.toString(), {
        credentials: "same-origin",
        headers: config.nonce ? { "X-WP-Nonce": config.nonce } : {},
      });
      if (!res.ok) return;
      apply((await res.json()) as ViewerState);
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
    for (const type of [
      "wc-blocks_added_to_cart",
      "wc-blocks_removed_from_cart",
    ]) {
      document.body.addEventListener(type, () => sync());
    }
    const data = window.wp?.data;
    if (!data?.subscribe || !data.select) return;
    let last: string | null = null;
    let wasPending = false;
    data.subscribe(() => {
      const cart = data.select("wc/store/cart");
      const items = cart?.getCartData?.()?.items;
      if (!Array.isArray(items)) return;
      const pending = Boolean(
        cart?.hasPendingItemsOperations?.() || cart?.isCustomerDataUpdating?.(),
      );
      const key = items.map((i) => `${i.id}:${i.quantity}`).join(",");
      if (last === null) {
        last = key;
        wasPending = pending;
        return;
      }
      const changed = key !== last;
      last = key;
      if (wasPending && !pending) {
        sync();
      } else if (changed && !pending) {
        sync();
      }
      wasPending = pending;
    }, "wc/store/cart");
  }

  /**
   * Whether this shopper has a cart: the `woocommerce_items_in_cart` cookie, or a synced cart with items.
   *
   * @since 0.8.0
   */
  function hasCart(): boolean {
    return (
      cart.count > 0 ||
      /(?:^|;\s*)woocommerce_items_in_cart=1(?:;|$)/.test(document.cookie)
    );
  }

  /**
   * Start presence and read this shopper's state from the visit report's answer; false when nothing will answer.
   *
   * @since 0.8.0
   */
  function startTracked(): boolean {
    const wps = window.WPS;
    if (!wps?.presence || !config.presenceChannel || !config.cartIdUrl)
      return false;
    tracker = wps.presence.track(config.presenceChannel, {
      state: { b: null },
      report: hasCart,
    });
    if (typeof tracker.onVisit !== "function") return false;
    tracker.onVisit((answer) => {
      const mine = answer?.shopsocket;
      if (mine && typeof mine === "object") apply(mine as ViewerState);
      else if (hasCart()) sync();
      /* A cartless visitor gets no answer and fetches nothing. */
    });
    return true;
  }

  /*
   * One answer on load (the visit report's, or a fetch), then events and the
   * shopper's own cart changes keep it current. WordSocket's client is a classic
   * footer script that runs before this module, so `window.WPS` is already there.
   */
  if (!startTracked() && hasCart()) sync();
  watchOwnCart();

  /*
   * A recovered connection is a recovery step: the sync lands this shopper's
   * cart on their journey again and the client re-enters presence, so the
   * board sees them live again without waiting for a cart change.
   */
  let hasConnected = false;
  window.WPS?.onStateChange((s) => {
    if (!s.connected) return;
    if (hasConnected) sync();
    hasConnected = true;
  });

  return {
    get cartId() {
      return cartId;
    },
    get cart() {
      return cart;
    },
    onCart(handler) {
      cartHandlers.add(handler);
      return () => {
        cartHandlers.delete(handler);
      };
    },
    sync: () => {
      void sync();
    },
    holds: (productId) => quantities.has(productId),
    held: (productId) => quantities.get(productId) ?? 0,
  };
}
