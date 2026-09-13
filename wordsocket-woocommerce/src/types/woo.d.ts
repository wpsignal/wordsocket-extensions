/** Payloads and localized config for WordSocket for WooCommerce. Ambient: no imports or exports. */

interface WordSocketWooBoardConfig {
	restUrl: string;
	nonce: string;
	/** Orders to render before the first live event. */
	orders: WooOrderEvent[];
	/** Statuses the board shows, in display order. */
	statuses: Array< { slug: string; label: string } >;
	currencySymbol: string;
	soundDefault: boolean;
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
	managed: boolean;
	purchasable: boolean;
	availability: string;
}

