/**
 * ShopSocket for WooCommerce: storefront live features as an Interactivity
 * API store (`shopsocket/storefront`). Written in TypeScript and compiled to
 * an ES module (`build/storefront/storefront.js`) by `tsconfig.storefront.json`;
 * the `@wordpress/interactivity` import stays a bare specifier that WordPress
 * resolves through the script-module import map, so nothing is bundled.
 *
 * The server renders the initial figures (stock text, in-cart count) into
 * directives and seeds this store's state with `wp_interactivity_state()`;
 * WordSocket's client dispatches `wpsignal:<event>` DOM events, which the
 * actions below turn into state changes:
 *
 *   woo.stock.changed  state.stock[key], and WooCommerce's own product store
 *   woo.cart.added     state.carts[productId], and a toast for other shoppers
 *   woo.cart.removed   state.carts[productId]
 *
 * Markup WooCommerce injects after load (the classic variation form swaps the
 * availability paragraph on every selection) is not hydrated by the runtime,
 * so `syncInjected` patches those nodes directly.
 */
import { store, getContext } from "@wordpress/interactivity";

const reduceMotion = window.matchMedia?.("(prefers-reduced-motion: reduce)").matches ?? false;
const PULSE_MS = 1500;
const TOAST_MS = 6000;

interface StockEntry {
  text: string;
  class: string;
  status?: string;
  purchasable: boolean;
}

/** The per-element context (`data-wp-context`) the directives render into. */
interface Ctx {
  productId: number;
  variationId?: number;
  text?: string;
  class?: string;
  classes?: string;
}

interface I18n {
  one?: string;
  many?: string;
  addedThis?: string;
  addedOther?: string;
}

/** Payload of `woo.cart.added` / `woo.cart.removed` events. */
interface CartEventData {
  product_id?: number | string;
  in_carts?: number;
  actor?: string;
  name?: string;
  permalink?: string;
  image?: string;
}

/** Payload of `woo.stock.changed` events. */
interface StockEventData {
  product_id?: number | string;
  variation_id?: number | string;
  availability_text?: string;
  availability_class?: string;
  stock_status?: string;
  purchasable?: boolean;
}

/** This shopper's own state, from `GET /shopsocket/v1/basket-id`. */
interface ViewerState {
  id?: string;
  products?: number[];
  count?: number;
}

interface StorefrontState {
  // Seeded by the server (absent from the literal below, hence optional).
  productId?: number;
  toasts?: boolean;
  inCarts?: boolean;
  i18n?: I18n;
  carts?: Record<number, number>;
  selectedVariation?: number;
  presenceChannel?: string;
  basketIdUrl?: string;
  nonce?: string;
  // Client-owned.
  stock: Record<string, StockEntry>;
  pulses: Record<string, boolean>;
  toast: { message: string; href: string; visible: boolean; image: string };
  // Derived (getters).
  readonly inCartsCount: number;
  readonly inCartsText: string;
  readonly inCartsPulse: boolean;
  readonly stockEntry: StockEntry | undefined;
  readonly stockText: string;
  readonly stockClass: string;
  readonly stockEmpty: boolean;
  readonly stockClassName: string;
  readonly indicatorClassName: string;
  readonly stockPulse: boolean;
}

interface StorefrontActions {
  dismissToast(): void;
  showToast(message: string, href?: string, image?: string): void;
  stockChanged(data: StockEventData): void;
  cartAdded(data: CartEventData): void;
  cartRemoved(data: CartEventData): void;
}

/** `state.stock` key for a product or one of its variations. */
const stockKey = (productId: number, variationId?: number): string => `${productId}:${variationId || 0}`;

// Split from the destructuring so `state` takes its type from the explicit
// generic rather than from the getters that reference it (which would be circular).
const wooStore = store<{ state: StorefrontState; actions: StorefrontActions }>("shopsocket/storefront", {
  state: {
    stock: {},
    pulses: {},
    toast: { message: "", href: "", visible: false, image: "" },

    /* In-cart counter, in the context of one product. */
    get inCartsCount(): number {
      const { productId } = getContext<Ctx>();
      return state.carts?.[productId] ?? 0;
    },
    get inCartsText(): string {
      const count = state.inCartsCount;
      return (count === 1 ? state.i18n?.one ?? "%d" : state.i18n?.many ?? "%d").replace("%d", String(count));
    },
    get inCartsPulse(): boolean {
      const { productId } = getContext<Ctx>();
      return Boolean(state.pulses?.[`cart:${productId}`]);
    },

    /* Stock, in the context of one product or variation. The context carries
       the server-rendered text and class as the value until an event arrives. */
    get stockEntry(): StockEntry | undefined {
      const { productId, variationId } = getContext<Ctx>();
      return state.stock[stockKey(productId, variationId)];
    },
    get stockText(): string {
      const ctx = getContext<Ctx>();
      return state.stockEntry?.text ?? ctx.text ?? "";
    },
    get stockClass(): string {
      const ctx = getContext<Ctx>();
      return state.stockEntry?.class ?? ctx.class ?? "";
    },
    get stockEmpty(): boolean {
      return state.stockText === "";
    },
    /** WooCommerce's classic markup: `<p class="stock in-stock">`. */
    get stockClassName(): string {
      return `stock ${state.stockClass} shopsocket-stock${state.stockPulse ? " shopsocket-stock--updated" : ""}`;
    },
    /** The Product Stock Indicator block: a `--{class}` modifier on its base classes. */
    get indicatorClassName(): string {
      const ctx = getContext<Ctx>();
      return `${ctx.classes ?? ""} wc-block-components-product-stock-indicator--${state.stockClass} shopsocket-stock${state.stockPulse ? " shopsocket-stock--updated" : ""}`;
    },
    get stockPulse(): boolean {
      const { productId, variationId } = getContext<Ctx>();
      return Boolean(state.pulses?.[`stock:${stockKey(productId, variationId)}`]);
    },
  },

  actions: {
    dismissToast() {
      state.toast.visible = false;
    },

    showToast(message, href, image) {
      if (!message) return;
      state.toast = { message, href: href ?? "", visible: true, image: image ?? "" };
      clearTimeout(toastTimer);
      toastTimer = setTimeout(() => {
        state.toast.visible = false;
      }, TOAST_MS);
    },

    stockChanged(data) {
      const productId = Number(data.product_id);
      const variationId = Number(data.variation_id) || 0;
      if (!productId) return;
      const entry: StockEntry = {
        text: data.availability_text ?? "",
        class: data.availability_class ?? "",
        status: data.stock_status,
        purchasable: data.purchasable !== false,
      };
      const key = stockKey(productId, variationId);
      state.stock[key] = entry;
      pulse(`stock:${key}`);
      // A variation's event also speaks for the parent's element while that variation is selected.
      if (variationId && variationId === Number(state.selectedVariation)) {
        state.stock[stockKey(productId, 0)] = entry;
        pulse(`stock:${stockKey(productId, 0)}`);
      }
      feedWooCommerceStore(productId, variationId, entry);
      syncInjected(productId, variationId, entry);
      syncAddToCart(productId, variationId, entry);
    },

    cartAdded(data) {
      const productId = Number(data.product_id);
      if (!productId) return;
      // This event is always another shopper's add (the relay never echoes a
      // shopper's own add back to them); the viewer's own adds are picked up by
      // watchOwnCart below. So update the count from the payload and toast if held.
      if (state.inCarts && typeof data.in_carts === "number") {
        setInCarts(productId, data.in_carts);
      }
      if (!state.toasts) return;
      if (myBasketId && data.actor === myBasketId) return; // the shopper's own session (any tab)
      if (!myProducts.has(productId)) return; // only items this shopper also holds
      const onOwnPage = productId === Number(state.productId);
      actions.showToast(
        onOwnPage ? state.i18n?.addedThis ?? "" : (state.i18n?.addedOther ?? "%s").replace("%s", String(data.name ?? "")),
        onOwnPage ? "" : data.permalink,
        data.image,
      );
    },

    cartRemoved(data) {
      const productId = Number(data.product_id);
      if (state.inCarts && productId && typeof data.in_carts === "number") {
        setInCarts(productId, data.in_carts);
      }
    },
  },
});
const state = wooStore.state;
const actions = wooStore.actions;

/* -------------------------------------------------------------------------- */
/* State helpers                                                              */
/* -------------------------------------------------------------------------- */

let toastTimer: ReturnType<typeof setTimeout>;

function setInCarts(productId: number, count: number): void {
  if (!state.carts) state.carts = {};
  state.carts[productId] = count;
  if (count > 0) pulse(`cart:${productId}`);
}

const pulseTimers = new Map<string, ReturnType<typeof setTimeout>>();

/** A short-lived flag that drives the highlight animations. */
function pulse(key: string): void {
  if (reduceMotion) return;
  state.pulses[key] = true;
  clearTimeout(pulseTimers.get(key));
  pulseTimers.set(
    key,
    setTimeout(() => {
      state.pulses[key] = false;
    }, PULSE_MS),
  );
}

/* -------------------------------------------------------------------------- */
/* WooCommerce integration                                                    */
/* -------------------------------------------------------------------------- */

/**
 * WooCommerce's block storefront keeps product data in its own Interactivity
 * store (`woocommerce/products`): the Product Stock Indicator block on
 * variable products binds `state.productInContext.stock_availability.text`.
 * Writing the new availability there updates every bound element at once.
 * The store is marked private by WooCommerce, hence the acknowledgement
 * string it requires; a store release could change its shape, so this is
 * best effort and the directives above stay authoritative.
 */
const WC_PRODUCTS_LOCK = "I acknowledge that using a private store means my plugin will inevitably break on the next store release.";

interface WooProductAvailability {
  stock_availability?: { text: string; class: string };
}
interface WooProductsStoreState {
  products?: Record<number, WooProductAvailability>;
  productVariations?: Record<number, WooProductAvailability>;
}

function feedWooCommerceStore(productId: number, variationId: number, entry: StockEntry): void {
  try {
    const wc = store<{ state: WooProductsStoreState }>("woocommerce/products", {}, { lock: WC_PRODUCTS_LOCK }).state;
    const target = variationId ? wc.productVariations?.[variationId] : wc.products?.[productId];
    if (target?.stock_availability) {
      target.stock_availability.text = entry.text;
      target.stock_availability.class = entry.class;
    }
  } catch {
    // Not a block storefront, or the store changed shape: the directives still update.
  }
}

/** Availability markup the classic variation form injected after load. */
function syncInjected(productId: number, variationId: number, entry: StockEntry): void {
  document.querySelectorAll<HTMLElement>(".woocommerce-variation-availability .shopsocket-stock").forEach((el) => {
    const ctx = readContext(el);
    if (Number(ctx.productId) !== productId) return;
    const elVariation = Number(ctx.variationId) || 0;
    if (elVariation !== variationId && !(elVariation === 0 && Number(state.selectedVariation) === variationId)) return;
    el.textContent = entry.text;
    el.className = `stock ${entry.class} shopsocket-stock shopsocket-stock--updated`;
    el.hidden = entry.text === "";
    setTimeout(() => el.classList.remove("shopsocket-stock--updated"), PULSE_MS);
  });
}

function readContext(el: Element): { productId?: number; variationId?: number } {
  try {
    return JSON.parse(el.getAttribute("data-wp-context") ?? "{}");
  } catch {
    return {};
  }
}

/** The page's own product: keep the add-to-cart button honest. */
function syncAddToCart(productId: number, variationId: number, entry: StockEntry): void {
  if (productId !== Number(state.productId)) return;
  if (variationId !== 0 && variationId !== Number(state.selectedVariation)) return;
  const button = document.querySelector<HTMLButtonElement>("form.cart .single_add_to_cart_button, .wc-block-add-to-cart-form .single_add_to_cart_button");
  if (!button) return;
  const out = entry.status === "outofstock" || !entry.purchasable;
  button.disabled = out;
  button.classList.toggle("disabled", out);
  button.classList.toggle("shopsocket-out-of-stock", out);
}

// WooCommerce's classic variation form is jQuery-driven; track the selection when it exists.
const jq = window.jQuery;
if (jq) {
  jq(document)
    .on("found_variation", "form.variations_form", (_e, variation) => {
      state.selectedVariation = Number((variation as { variation_id?: number } | undefined)?.variation_id) || 0;
    })
    .on("reset_data", "form.variations_form", () => {
      state.selectedVariation = 0;
    });
}

/* -------------------------------------------------------------------------- */
/* Wiring                                                                     */
/* -------------------------------------------------------------------------- */

const eventData = <T,>(e: Event): T => ((e as CustomEvent<{ data?: T }>).detail?.data ?? {}) as T;

document.addEventListener("wpsignal:woo.stock.changed", (e) => actions.stockChanged(eventData<StockEventData>(e)));
document.addEventListener("wpsignal:woo.cart.added", (e) => actions.cartAdded(eventData<CartEventData>(e)));
document.addEventListener("wpsignal:woo.cart.removed", (e) => actions.cartRemoved(eventData<CartEventData>(e)));

/* -------------------------------------------------------------------------- */
/* Presence: bind this basket to the live connection                          */
/* -------------------------------------------------------------------------- */

/**
 * A basket is "live" while the shopper's WordSocket connection is open. The
 * page enters relay presence under its basket id; the relay drops the
 * membership the instant the socket closes (tab close, crash, navigation) and
 * WordSocket re-sends it on reconnect, so the dashboard's live/abandoned split
 * follows the connection with no heartbeat and no timeout. The basket id comes
 * from the server (the WooCommerce session cookie is HttpOnly, so the browser
 * asks the `basket-id` endpoint for it), the same id WordPress stores rows under.
 */
let myBasketId: string | null = null;
// Parent product ids in this shopper's own cart, so a toast only fires for items
// they also hold. Seeded from the server, kept fresh on their own adds.
const myProducts = new Set<number>();

/**
 * A stable, anonymous id for this browser, so the dashboard can count distinct
 * visitors online rather than raw connections (multiple tabs share it). It is a
 * random value in localStorage, not derived from anything identifying; if the
 * visitor clears their storage they simply count as a new visitor (there is no
 * legitimate, reliable way to regenerate it, and fingerprinting is off the table).
 */
const VISITOR_KEY = "shopsocket-visitor";
let cachedVisitorId: string | null = null;

function randomId(): string {
  if (window.crypto?.randomUUID) return window.crypto.randomUUID();
  return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`;
}

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
    // Storage blocked: fall back to a per-load id (this visitor's tabs may
    // over-count, but the metric still works).
    cachedVisitorId = randomId();
  }
  return cachedVisitorId;
}

let viewerInFlight = false;
let viewerDirty = false;

/**
 * Pull this shopper's own state from the server: their basket id (for relay
 * presence), the parent products in their cart (so a toast only fires for items
 * they hold), and the current count for this page's product (so their own add,
 * which the server never echoes back to them, still shows on the counter). Runs
 * once on load and whenever this shopper changes their own cart, never on a
 * timer. Other shoppers' adds and removes update the counter instantly via
 * events; the on-load fetch also returns a live count that overwrites the
 * server-rendered number, so a stale full-page-cached page corrects itself at
 * once (the endpoint sends no-store, so the fetch itself is never cached).
 * Calls that arrive mid-flight set a dirty flag so one more sync runs
 * afterwards, catching a cart change that lands during a fetch.
 */
async function syncViewer(): Promise<void> {
  if (!state.basketIdUrl) return;
  if (viewerInFlight) {
    viewerDirty = true;
    return;
  }
  viewerInFlight = true;
  try {
    const url = new URL(state.basketIdUrl, window.location.origin);
    if (state.productId) {
      url.searchParams.set("product", String(state.productId));
    }
    const res = await fetch(url.toString(), {
      credentials: "same-origin",
      headers: state.nonce ? { "X-WP-Nonce": state.nonce } : {},
    });
    if (!res.ok) return;
    const viewer: ViewerState = await res.json();
    (viewer?.products ?? []).forEach((id) => myProducts.add(Number(id)));
    if (viewer?.id) myBasketId = viewer.id;
    if (state.inCarts && state.productId && typeof viewer?.count === "number") {
      setInCarts(Number(state.productId), viewer.count);
    }
    const wps = window.WPS;
    if (wps && state.presenceChannel) {
      // Every storefront visitor is a "user online" (v); cart holders also carry
      // a basket id (b) so the dashboard splits live vs abandoned baskets. The
      // relay drops the membership when the socket closes and WordSocket re-sends
      // it on reconnect; deduping by v across a shopper's tabs is done on the board.
      wps.setPresence(state.presenceChannel, { v: visitorId(), b: viewer?.id ?? null });
    }
  } catch {
    // Offline or blocked: the seeded values and live events still apply.
  } finally {
    viewerInFlight = false;
    if (viewerDirty) {
      viewerDirty = false;
      syncViewer();
    }
  }
}

/**
 * A shopper never receives their own `woo.cart.added` event, and a block-theme
 * add fires no document-level click, so the only reliable local signal that this
 * shopper changed their own cart is WooCommerce's own client state. Watch every
 * signal WooCommerce gives and re-sync from the server on any of them, so the
 * viewer's own add shows on the counter and seeds `myProducts` at once:
 *
 *   - classic themes: the jQuery `added_to_cart` / `removed_from_cart` events;
 *   - block themes: the `wc-blocks_added_to_cart` / `wc-blocks_removed_from_cart`
 *     CustomEvents WooCommerce dispatches on `document.body` from its
 *     Interactivity cart store (shop archive and single product buttons) and
 *     from its wp.data cart store (mini cart, cart and checkout blocks). Both
 *     stores are private, so their events are the supported surface;
 *   - the wp.data `wc/store/cart` store itself, when it is on the page, for
 *     changes that dispatch no event.
 *
 * A change may arrive on more than one of these at once; `syncViewer` coalesces
 * overlapping calls into at most one follow-up fetch.
 */
function watchOwnCart(): void {
  if (jq) {
    jq(document.body).on("added_to_cart removed_from_cart", () => syncViewer());
  }
  for (const type of ["wc-blocks_added_to_cart", "wc-blocks_removed_from_cart"]) {
    document.body.addEventListener(type, () => syncViewer());
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
      syncViewer();
    }
  }, "wc/store/cart");
}

// One fetch on load (identity, presence, and a fresh count that busts a stale
// full-page-cached page), then live events for others and this shopper's own
// cart changes keep it current. A returning shopper may hold a cart and never
// add anything this visit, so this is also what makes them show live and hold
// their items. WordSocket's client is a classic footer script and this module
// runs after it, so `window.WPS` is already there for presence. No timer, no
// visibility poll: nothing here runs on a schedule.
syncViewer();
watchOwnCart();
