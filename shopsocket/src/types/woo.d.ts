/** Payloads and localized config for ShopSocket for WooCommerce. Ambient: no imports or exports. */

interface ShopSocketBoardConfig {
	/** `GET` here returns fresh WooDashboardData (inc/dashboard.php). */
	dashboardUrl: string;
	nonce: string;
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
	/** Stable, anonymous session id (SHA-256 of the WooCommerce customer id, 16 hex). */
	id: string;
	/** Parent product IDs in the cart. */
	products: number[];
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
