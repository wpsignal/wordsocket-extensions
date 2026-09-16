/**
 * The parts of the storefront that reach into WooCommerce's own markup and
 * stores: the private product store on block themes, the availability markup
 * the classic variation form injects, the add-to-cart button, and the
 * variation form's selection events.
 */
import { store } from "@wordpress/interactivity";
import type { StockEntry } from "./types";

/**
 * The block storefront binds the Product Stock Indicator on variable products
 * to WooCommerce's private `woocommerce/products` store; writing there updates
 * every bound element. The lock string is what the private store demands.
 */
const WC_PRODUCTS_LOCK = "I acknowledge that using a private store means my plugin will inevitably break on the next store release.";

interface WooProductAvailability {
  stock_availability?: { text: string; class: string };
}
interface WooProductsStoreState {
  products?: Record<number, WooProductAvailability>;
  productVariations?: Record<number, WooProductAvailability>;
}

/** Write the availability into WooCommerce's product store where it exists. */
export function feedWooCommerceStore(productId: number, variationId: number, entry: StockEntry): void {
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
export function syncInjected(
  productId: number,
  variationId: number,
  entry: StockEntry,
  selectedVariation: number,
  highlightMs: number,
): void {
  document.querySelectorAll<HTMLElement>(".woocommerce-variation-availability .shopsocket-stock").forEach((el) => {
    const ctx = readContext(el);
    if (Number(ctx.productId) !== productId) return;
    const elVariation = Number(ctx.variationId) || 0;
    if (elVariation !== variationId && !(elVariation === 0 && selectedVariation === variationId)) return;
    el.textContent = entry.text;
    el.className = `stock ${entry.class} shopsocket-stock shopsocket-stock--updated`;
    el.hidden = entry.text === "";
    setTimeout(() => el.classList.remove("shopsocket-stock--updated"), highlightMs);
  });
}

/** The parsed `data-wp-context` of an element, or an empty object. */
function readContext(el: Element): { productId?: number; variationId?: number } {
  try {
    return JSON.parse(el.getAttribute("data-wp-context") ?? "{}");
  } catch {
    return {};
  }
}

/** The page's own product: keep the add-to-cart button honest. */
export function syncAddToCart(
  productId: number,
  variationId: number,
  entry: StockEntry,
  pageProductId: number,
  selectedVariation: number,
): void {
  if (productId !== pageProductId) return;
  if (variationId !== 0 && variationId !== selectedVariation) return;
  const button = document.querySelector<HTMLButtonElement>("form.cart .single_add_to_cart_button, .wc-block-add-to-cart-form .single_add_to_cart_button");
  if (!button) return;
  const out = entry.status === "outofstock" || !entry.purchasable;
  button.disabled = out;
  button.classList.toggle("disabled", out);
  button.classList.toggle("shopsocket-out-of-stock", out);
}

/**
 * Ask WooCommerce to re-read the cart from the server, so its own notices and
 * quantity limits appear: the block stores re-fetch through wp.data (the Store
 * API answer carries the cart errors), the classic cart, mini cart and
 * checkout through their jQuery events. Unknown events are no-ops.
 */
export function refreshCart(page: string | undefined): void {
  try {
    window.wp?.data?.dispatch("core/data")?.invalidateResolutionForStoreSelector?.("wc/store/cart", "getCartData");
  } catch {
    // No block cart store on this page.
  }
  const jq = window.jQuery;
  if (!jq) return;
  const body = jq(document.body);
  body.trigger("wc_fragment_refresh");
  if (page === "cart") body.trigger("wc_update_cart");
  if (page === "checkout") body.trigger("update_checkout");
}

/** The page product's quantity input follows what can still be bought; a value over the limit is clamped. */
export function setQuantityMax(
  productId: number,
  variationId: number,
  available: number | null,
  pageProductId: number,
  selectedVariation: number,
): void {
  if (productId !== pageProductId) return;
  if (variationId !== 0 && variationId !== selectedVariation) return;
  document.querySelectorAll<HTMLInputElement>("form.cart input.qty, .wc-block-add-to-cart-form input.qty").forEach((input) => {
    if (available === null) {
      input.removeAttribute("max");
      return;
    }
    input.max = String(available);
    if (available > 0 && Number(input.value) > available) {
      input.value = String(available);
      input.dispatchEvent(new Event("change", { bubbles: true }));
    }
  });
}

/**
 * Archive buttons ("Add to cart" or "Read more" on the shop and category pages)
 * follow a product's stock. The theme's own button is never rebuilt: it is
 * hidden and a plain link stands in beside it, and the swap is undone when the
 * stock state returns to what the page was rendered with. Only simple products
 * have an add-to-cart button on archives, so only those are touched.
 */
export function syncArchiveButtons(
  productId: number,
  purchasable: boolean,
  permalink: string,
  labels: { addToCart: string; readMore: string },
): void {
  document.querySelectorAll<HTMLElement>(`[data-product_id="${productId}"]`).forEach((original) => {
    if (original.classList.contains("single_add_to_cart_button") || !original.classList.contains("product_type_simple")) return;
    const originalAdds = original.classList.contains("add_to_cart_button");
    const wanted = purchasable ? "add" : "read";
    const existing = original.nextElementSibling instanceof HTMLAnchorElement && original.nextElementSibling.dataset.shopsocketStandIn === String(productId)
      ? original.nextElementSibling
      : null;
    if ((wanted === "add") === originalAdds) {
      // The page was rendered in this state: show the theme's button again.
      existing?.remove();
      original.hidden = false;
      return;
    }
    if (existing?.dataset.shopsocketKind === wanted) return;
    existing?.remove();
    const link = document.createElement("a");
    link.className = Array.from(original.classList)
      .filter((c) => !["add_to_cart_button", "ajax_add_to_cart", "wc-interactive"].includes(c))
      .concat("shopsocket-stand-in")
      .join(" ");
    link.dataset.shopsocketStandIn = String(productId);
    link.dataset.shopsocketKind = wanted;
    if (permalink) {
      link.href = wanted === "add" ? `${permalink}${permalink.includes("?") ? "&" : "?"}add-to-cart=${productId}` : permalink;
    }
    const style = original.getAttribute("style");
    if (style) link.setAttribute("style", style);
    link.textContent = wanted === "add" ? labels.addToCart : labels.readMore;
    original.hidden = true;
    original.insertAdjacentElement("afterend", link);
  });
}

/** Report the classic variation form's selection (0 when cleared); it is jQuery-driven. */
export function watchVariationForm(onSelect: (variationId: number) => void): void {
  const jq = window.jQuery;
  if (!jq) return;
  jq(document)
    .on("found_variation", "form.variations_form", (_e, variation) => {
      onSelect(Number((variation as { variation_id?: number } | undefined)?.variation_id) || 0);
    })
    .on("reset_data", "form.variations_form", () => onSelect(0));
}
