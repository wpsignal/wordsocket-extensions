/** Payloads and localized config for ShopSocket for WooCommerce. Ambient: no imports or exports. */

interface ShopSocketBoardConfig {
	/** `GET` here returns fresh WooDashboardData (inc/dashboard.php). */
	dashboardUrl: string;
	/** `GET ?ids=1,2,3` here returns `{ products: WooProductRef[] }` for the live-products list. */
	productsUrl: string;
	/** The wp-admin base; an order link is only followed when it points there. */
	adminUrl: string;
	nonce: string;
	/** The channel each event is trusted from (inc/channels.php). */
	channels: { orders: string; stock: string; presence: string; connections: string };
	/** Orders to render before the first live event. */
	orders: WooOrderEvent[];
	/** Statuses the board shows, in display order. */
	statuses: Array< { slug: string; label: string } >;
	/** Figures rendered on first paint (basket rows + online). */
	snapshot: WooDashboardData;
	currencySymbol: string;
}

/** One basket row from the server (inc/carts.php `all_baskets()`). */
interface WooBasketRow {
	/** `basket_id()`: 16 hex of SHA-256 over the user id or the guest session id. */
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

/** The raw dashboard payload from the server (rows + online). */
interface WooDashboardData {
	baskets: WooBasketRow[];
	/** Null when WordSocket is older than 0.22 or the server could not be reached. */
	online: { active_connections: number; max_connections: number } | null;
}

/** One basket segment, computed on the board by crossing rows with presence. */
interface WooBasketSegment {
	shoppers: number;
	products: number;
	revenue: number;
	currency: string;
}

/** What the board renders: rows split into live and abandoned. */
interface WooDashboardSnapshot {
	baskets: {
		/** Baskets whose shopper has an open tab (a live connection) right now. */
		live: WooBasketSegment;
		/** Baskets still held by a shopper who has left the site. */
		abandoned: WooBasketSegment;
	};
	/** Distinct storefront visitors connected right now (deduped by visitor id across tabs). */
	usersOnline: number;
	online: { active_connections: number; max_connections: number } | null;
	/** Products in live baskets: how many of those baskets hold each, and the units across them, unordered. */
	liveProducts: Array< { id: number; baskets: number; units: number } >;
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
