/**
 * Shapes shared by the storefront modules: the store's state and actions, the
 * element context the directives render into, and the event payloads.
 */

/** Availability of one product or variation, as the store keeps it. */
export interface StockEntry {
  text: string;
  class: string;
  status?: string;
  purchasable: boolean;
}

/** The per-element context (`data-wp-context`) the directives render into. */
export interface Ctx {
  productId: number;
  variationId?: number;
  text?: string;
  class?: string;
  classes?: string;
}

export interface I18n {
  one?: string;
  many?: string;
  addedThis?: string;
  addedOther?: string;
}

/** Payload of `woo.cart.added` / `woo.cart.removed` events. */
export interface CartEventData {
  product_id?: number | string;
  in_carts?: number;
  actor?: string;
  name?: string;
  permalink?: string;
  image?: string;
}

/** Payload of `woo.stock.changed` events. */
export interface StockEventData {
  product_id?: number | string;
  variation_id?: number | string;
  availability_text?: string;
  availability_class?: string;
  stock_status?: string;
  purchasable?: boolean;
}

export interface StorefrontState {
  // Seeded by the server, so optional here.
  productId?: number;
  toasts?: boolean;
  inCarts?: boolean;
  i18n?: I18n;
  carts?: Record<number, number>;
  selectedVariation?: number;
  presenceChannel?: string;
  channels?: { stock?: string; activity?: string };
  basketIdUrl?: string;
  nonce?: string;
  stock: Record<string, StockEntry>;
  pulses: Record<string, boolean>;
  toast: { message: string; href: string; visible: boolean; image: string };
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

export interface StorefrontActions {
  dismissToast(): void;
  showToast(message: string, href?: string, image?: string): void;
  stockChanged(data: StockEventData): void;
  cartAdded(data: CartEventData): void;
  cartRemoved(data: CartEventData): void;
}
