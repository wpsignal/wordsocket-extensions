/**
 * ShopSocket storefront: the `shopsocket/storefront` Interactivity API store,
 * compiled by `tsconfig.storefront.json` to ES modules, nothing bundled. The
 * server renders the first figures and seeds the state; WordSocket's
 * `wpsignal:<event>` DOM events drive the actions:
 *
 *   woo.stock.changed  state.stock[key], and WooCommerce's own product store
 *   woo.cart.added     state.carts[productId], and a toast for other shoppers
 *   woo.cart.removed   state.carts[productId]
 *
 * The viewer (basket id, held products, presence) lives in `viewer.ts`; the
 * hooks into WooCommerce's markup and stores in `woocommerce.ts`. Both are
 * registered script modules, imported here by id.
 */
import { store, getContext } from "@wordpress/interactivity";
import { startViewer } from "shopsocket/storefront/viewer";
import { feedWooCommerceStore, syncAddToCart, syncInjected, watchVariationForm } from "shopsocket/storefront/woocommerce";
import type { CartEventData, Ctx, StockEntry, StockEventData, StorefrontActions, StorefrontState } from "./types";

const reduceMotion = window.matchMedia?.("(prefers-reduced-motion: reduce)").matches ?? false;
const PULSE_MS = 1500;
const TOAST_MS = 6000;

/** `state.stock` key for a product or one of its variations. */
const stockKey = (productId: number, variationId?: number): string => `${productId}:${variationId || 0}`;

/*
 * Split from the destructuring so `state` takes its type from the explicit
 * generic rather than from the getters that reference it (which would be circular).
 */
const wooStore = store<{ state: StorefrontState; actions: StorefrontActions }>("shopsocket/storefront", {
  state: {
    stock: {},
    pulses: {},
    toast: { message: "", href: "", visible: false, image: "" },

    /** How many baskets hold this element's product. */
    get inCartsCount(): number {
      const { productId } = getContext<Ctx>();
      return state.carts?.[productId] ?? 0;
    },
    /** That count in the singular or plural template. */
    get inCartsText(): string {
      const count = state.inCartsCount;
      return (count === 1 ? state.i18n?.one ?? "%d" : state.i18n?.many ?? "%d").replace("%d", String(count));
    },
    /** Whether this element's basket count just changed. */
    get inCartsPulse(): boolean {
      const { productId } = getContext<Ctx>();
      return Boolean(state.pulses?.[`cart:${productId}`]);
    },

    // The context carries the server-rendered text and class until an event arrives.
    get stockEntry(): StockEntry | undefined {
      const { productId, variationId } = getContext<Ctx>();
      return state.stock[stockKey(productId, variationId)];
    },
    /** Availability text for this element's product or variation. */
    get stockText(): string {
      const ctx = getContext<Ctx>();
      return state.stockEntry?.text ?? ctx.text ?? "";
    },
    /** Availability class for this element's product or variation. */
    get stockClass(): string {
      const ctx = getContext<Ctx>();
      return state.stockEntry?.class ?? ctx.class ?? "";
    },
    /** Whether this element has no availability text to show. */
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
    /** Whether this element's stock just changed. */
    get stockPulse(): boolean {
      const { productId, variationId } = getContext<Ctx>();
      return Boolean(state.pulses?.[`stock:${stockKey(productId, variationId)}`]);
    },
  },

  actions: {
    /** Hide the toast now. */
    dismissToast() {
      state.toast.visible = false;
    },

    /** Show a toast for TOAST_MS, replacing any on screen. */
    showToast(message, href, image) {
      if (!message) return;
      state.toast = { message, href: href ?? "", visible: true, image: image ?? "" };
      clearTimeout(toastTimer);
      toastTimer = setTimeout(() => {
        state.toast.visible = false;
      }, TOAST_MS);
    },

    /** Record a product's new availability and push it everywhere the page shows it. */
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
      const selected = Number(state.selectedVariation) || 0;
      const key = stockKey(productId, variationId);
      state.stock[key] = entry;
      pulse(`stock:${key}`);
      // A variation's event also speaks for the parent's element while that variation is selected.
      if (variationId && variationId === selected) {
        state.stock[stockKey(productId, 0)] = entry;
        pulse(`stock:${stockKey(productId, 0)}`);
      }
      feedWooCommerceStore(productId, variationId, entry);
      syncInjected(productId, variationId, entry, selected, PULSE_MS);
      syncAddToCart(productId, variationId, entry, Number(state.productId), selected);
    },

    /** Another shopper added a product: update its count, and toast if this shopper holds it too. */
    cartAdded(data) {
      const productId = Number(data.product_id);
      if (!productId) return;
      /*
       * Always another shopper's add: the relay never echoes an add back to the
       * tab that made it, and the viewer picks up this shopper's own changes.
       */
      if (state.inCarts && typeof data.in_carts === "number") {
        setInCarts(productId, data.in_carts);
      }
      if (!state.toasts) return;
      if (viewer.basketId && data.actor === viewer.basketId) return; // the shopper's own session (any tab)
      if (!viewer.holds(productId)) return; // only items this shopper also holds
      const onOwnPage = productId === Number(state.productId);
      /*
       * Links and images come from a public channel: keep them to web URLs, and
       * the link to this site.
       */
      actions.showToast(
        onOwnPage ? state.i18n?.addedThis ?? "" : (state.i18n?.addedOther ?? "%s").replace("%s", String(data.name ?? "")),
        onOwnPage ? "" : webUrl(data.permalink, true),
        webUrl(data.image, false),
      );
    },

    /** Another shopper removed a product: update its count. */
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

let toastTimer: ReturnType<typeof setTimeout>;

/** Store a product's basket count and flash it when non-zero. */
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

/** `value` as an http(s) URL, on this site when `sameOrigin`, else "". */
function webUrl(value: unknown, sameOrigin: boolean): string {
  if (typeof value !== "string" || value === "") return "";
  try {
    const url = new URL(value, window.location.origin);
    if (url.protocol !== "https:" && url.protocol !== "http:") return "";
    if (sameOrigin && url.origin !== window.location.origin) return "";
    return url.href;
  } catch {
    return "";
  }
}

/** The `data` of a `wpsignal:<event>` DOM event, when it arrived on `expected` (any spelling), else null. */
function relayed<T>(e: Event, expected?: string): T | null {
  const detail = (e as CustomEvent<{ data?: T; channel?: string }>).detail;
  const channel = detail?.channel ?? "";
  if (!expected || !(channel === expected || channel.endsWith(`:${expected}`))) return null;
  return (detail?.data ?? {}) as T;
}

document.addEventListener("wpsignal:woo.stock.changed", (e) => {
  const data = relayed<StockEventData>(e, state.channels?.stock);
  if (data) actions.stockChanged(data);
});
document.addEventListener("wpsignal:woo.cart.added", (e) => {
  const data = relayed<CartEventData>(e, state.channels?.activity);
  if (data) actions.cartAdded(data);
});
document.addEventListener("wpsignal:woo.cart.removed", (e) => {
  const data = relayed<CartEventData>(e, state.channels?.activity);
  if (data) actions.cartRemoved(data);
});

watchVariationForm((variationId) => {
  state.selectedVariation = variationId;
});

const viewer = startViewer(
  {
    basketIdUrl: state.basketIdUrl,
    nonce: state.nonce,
    productId: state.productId,
    presenceChannel: state.presenceChannel,
  },
  (productId, count) => {
    if (state.inCarts) setInCarts(productId, count);
  },
);
