/** Payloads and localized config for ShopSocket. Ambient: no imports or exports. */

interface ShopSocketBoardConfig {
	/** `GET` here returns fresh WooDashboardData (inc/dashboard.php). */
	dashboardUrl: string;
	/** `GET ?ids=1,2,3` here returns `{ products: WooProductRef[] }` for the live-products list. */
	productsUrl: string;
	/** The wp-admin base; an order link is only followed when it points there. */
	adminUrl: string;
	nonce: string;
	/** The channel each event is trusted from (inc/channels.php). */
	channels: { orders: string; stock: string; presence: string; staff: string; connections: string };
	/** Orders to render before the first live event. */
	orders: WooOrderEvent[];
	/** Statuses the board shows, in display order. */
	statuses: Array< { slug: string; label: string } >;
	/** Figures rendered on first paint (cart rows + online). */
	snapshot: WooDashboardData;
	currencySymbol: string;
}

/** One cart row from the server (inc/carts.php `cart_rows()`). */
interface WooCartRow {
	/** `cart_id()`: 16 hex of SHA-256 over the user id or the guest session id. */
	id: string;
	/** Parent product IDs in the cart. */
	products: number[];
	/** Units of each parent product, keyed by product id (JSON object keys are strings). */
	quantities: Record< string, number >;
	/** Cart value. */
	value: number;
	/** ISO 4217 code for `value`. */
	currency: string;
}

/** A cart whose shopper left (inc/history.php `abandoned_rows()`), newest leave first. */
interface WooAbandonedRow extends WooCartRow {
	/** The shopper's journey, `kind:id:ms` steps joined by `>`; empty with history off. */
	journey: string;
	/** When the shopper left, RFC 3339 UTC. */
	left_at: string;
	converted_at: string | null;
	/** Set when the cart later became an order. */
	order_id: number | null;
	/** The order's edit screen, built without loading the order; followed only into this site's admin. */
	order_url: string;
}

/** The raw dashboard payload from the server (rows + online). */
interface WooDashboardData {
	carts: WooCartRow[];
	/** The latest carts whose shopper left at some point, newest leave first; absent on a payload from before 0.7. */
	abandoned?: WooAbandonedRow[];
	/** Null when WordSocket is older than 0.22 or the server could not be reached. */
	online: { active_connections: number; max_connections: number } | null;
	/** The server's clock (Unix seconds) when the figures were read; sent back as `since` on the next refresh. */
	as_of?: number;
	/** Orders created or changed since the `since` the request named; absent when it named none. */
	orders?: WooOrderEvent[];
}

/** One cart segment, computed on the board by crossing rows with presence. */
interface WooCartSegment {
	shoppers: number;
	products: number;
	revenue: number;
	currency: string;
}

/** What the board renders: rows split into live and abandoned. */
interface WooDashboardSnapshot {
	carts: {
		/** Carts whose shopper has a visible tab right now. */
		live: WooCartSegment;
		/** Carts whose shopper is connected but has every tab hidden. */
		away: WooCartSegment;
		/** Carts still held by a shopper who has left the site. */
		abandoned: WooCartSegment;
	};
	/** Distinct storefront visitors connected right now (deduped by visitor id across tabs). */
	usersOnline: number;
	/** The staff share of the figures: visitors above who are staff, and open tabs (admin or storefront) that are theirs. */
	staff: { users: number; tabs: number };
	/** Connected visitors whose every tab is hidden. */
	awayUsers: number;
	/** Left carts whose shopper is not back and whose leave is older than the grace, newest first. */
	abandoned: WooAbandonedRow[];
	online: { active_connections: number; max_connections: number } | null;
	/** Products in live carts: how many of those carts hold each, and the units across them, unordered. */
	liveProducts: Array< { id: number; carts: number; units: number } >;
}

/** A product the board names (inc/dashboard.php `products_for_board()`). */
interface WooProductRef {
	id: number;
	name: string;
	/** The wp-admin edit screen; the board follows it only into this site's admin. */
	edit_url: string;
}

/** Payload of woo.order.* events (inc/payloads.php). */
interface WooOrderEvent {
	order_id: number;
	number: string;
	status: string;
	total: number;
	currency: string;
	item_count: number;
	customer: string;
	payment_method: string;
	created_at: string | null;
	edit_url: string;
	from?: string;
	to?: string;
	transaction_id?: string;
}

/** Payload of woo.stock.* events. */
interface WooStockEvent {
	product_id: number;
	variation_id: number;
	name: string;
	stock_quantity: number | null;
	stock_status: string;
	purchasable: boolean;
	/** Availability in WooCommerce's own words, and its class (`in-stock`, `out-of-stock`, ...). */
	availability_text: string;
	availability_class: string;
}

/** Payload of `woo.alert`: the Alert staff rule action, published by PHP (inc/rules.php). */
interface WooAlertEvent {
	visitor: string;
	cart_id: string;
	message: string;
	/** The journey step kind the shopper was on. */
	step: string;
	products: number[];
	value: number;
	currency: string;
	wants_chat: boolean;
	/** Unix seconds when it was raised. */
	at: number;
}
