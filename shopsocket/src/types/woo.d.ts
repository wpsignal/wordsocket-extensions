/** Payloads and localized config for ShopSocket. Ambient: no imports or exports. */

interface ShopSocketBoardConfig {
	/** `GET` here returns fresh WooDashboardData (inc/dashboard.php). */
	dashboardUrl: string;
	/** `GET ?ids=1,2,3` here returns `{ products: WooProductRef[] }` for the board's tables. */
	productsUrl: string;
	/**
	 * `GET ?steps=page:3,category:7` here returns `{ pages: WooPageRef[] }` for the shoppers table.
	 *
	 * @since 0.8.0
	 */
	pagesUrl: string;
	/**
	 * `GET ?v=a,b` here returns `{ guests: WooGuestRef[] }`, the best guesses at who the guests are.
	 *
	 * @since 0.8.0
	 */
	guestsUrl: string;
	/**
	 * The storefront's front page; a page link is only followed when it points onto this site.
	 *
	 * @since 0.8.0
	 */
	homeUrl: string;
	/**
	 * The shop page.
	 *
	 * @since 0.8.0
	 */
	shopUrl: string;
	/**
	 * The cart page.
	 *
	 * @since 0.8.0
	 */
	cartUrl: string;
	/**
	 * The checkout page.
	 *
	 * @since 0.8.0
	 */
	checkoutUrl: string;
	/** The wp-admin base; an order link is only followed when it points there. */
	adminUrl: string;
	/** WordSocket's Journeys tab, which the leak sentence links into with its filters (0.8). */
	journeysUrl: string;
	nonce: string;
	/** The channel each event is trusted from (inc/channels.php). */
	channels: { orders: string; stock: string; presence: string; staff: string; connections: string };
	/** Orders to render before the first live event: those placed in the last `boardHours`. */
	orders: WooOrderEvent[];
	/** How far back the board's orders go, and its abandoned carts until another time frame is chosen, in hours (0.8). */
	boardHours: number;
	/** The time frames the abandoned carts offer, in hours (0.8). */
	abandonedHours: number[];
	/** Statuses the board shows, in display order. */
	statuses: Array< { slug: string; label: string } >;
	/** Figures rendered on first paint (cart rows + online). */
	snapshot: WooDashboardData;
	currencySymbol: string;
}

/** One live cart from the server (inc/carts.php `cart_rows()`, an open journey carrying a cart). */
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
	/**
	 * The shopper's account when they were logged in; absent on a payload from before 0.8.
	 *
	 * @since 0.8.0
	 */
	user_id?: number | null;
	/**
	 * The account's display name, null for a guest.
	 *
	 * @since 0.8.0
	 */
	customer?: string | null;
	/**
	 * The account's profile screen; followed only into this site's admin.
	 *
	 * @since 0.8.0
	 */
	customer_url?: string;
}

/** A cart whose shopper left in the time frame asked for (inc/history.php `abandoned_rows()`, its newest left journey), newest leave first. */
interface WooAbandonedRow extends WooCartRow {
	/** The visitor who left the cart, the id a best guess is asked by; '' when the journey names none. */
	v?: string;
	/** The journey the cart was left in, `kind:id:s` steps joined by `>`, the time in whole seconds. */
	journey: string;
	/** When the shopper left, RFC 3339 UTC. */
	left_at: string;
	converted_at: string | null;
	/** Set when the cart later became an order. */
	order_id: number | null;
	/** The order's edit screen, built without loading the order; followed only into this site's admin. */
	order_url: string;
	/** What that order was worth, null without one; absent on a payload from before 0.8. */
	order_value?: number | null;
}

/** The raw dashboard payload from the server (rows + online). */
interface WooDashboardData {
	carts: WooCartRow[];
	/** The carts whose shopper left in the time frame the request named, `boardHours` when it named none, newest leave first; absent on a payload from before 0.7. */
	abandoned?: WooAbandonedRow[];
	/** The carts left and not ordered since, counted in full, by time frame in hours (inc/history.php `abandoned_count()`); absent on a payload from before 0.8. */
	abandoned_totals?: WooAbandonedTotals;
	/** Those of the counted carts whose shopper has a journey that has not ended, by cart id; absent on a payload from before 0.8. */
	abandoned_returning?: WooAbandonedReturning;
	/** What those carts tell (inc/strip.php `abandonment()`); null with history off, absent on a payload from before 0.8. */
	abandonment?: WooAbandonment | null;
	/** This day's strip (inc/strip.php); null with history off, absent on a payload from before 0.8. */
	strip?: WooDayStrip | null;
	/** Null when WordSocket is older than 0.22 or the server could not be reached. */
	online: { active_connections: number; max_connections: number } | null;
	/** The server's clock (Unix seconds) when the figures were read; sent back as `since` on the next refresh. */
	as_of?: number;
	/** Orders created or changed since the `since` the request named; absent when it named none. */
	orders?: WooOrderEvent[];
}

/** The board's "This day" strip (inc/strip.php `day_strip()`): the last 24 hours, each figure against its daily average over the last seven days. */
interface WooDayStrip {
	/** Start of the window, RFC 3339 UTC. */
	since: string;
	/** Whether WordSocket keeps the journeys the conversion and the recovered orders read (0.8). */
	journeys: boolean;
	/** When each day of the figures' `days` series ends, RFC 3339 UTC, oldest first: a day is the 24 hours up to then, the last one up to now. */
	trend_days: string[];
	/** Paid orders placed in the last day and what they came to; `average` and `days` are what a day came to. */
	sales: { count: number; value: number; currency: string; average: number; days: number[] };
	/** Carts whose shopper left and that never became an order; `average` is the count a day over the series, `days` the count per day. */
	left: { count: number; value: number; currency: string; average: number; days: number[] };
	/** Orders credited to a moment that fired, with the count a day over the series and per day. */
	recovered: { count: number; value: number; average: number; days: number[] };
	/** Orders against journeys that reached checkout, as a percentage, with the percentage over the whole series and per day. */
	conversion: { reached: number; orders: number; percent: number; average_percent: number; days: number[] };
}

/** The carts left, by time frame in hours: those not ordered since (how many, their distinct products, what they are worth, the shop's currency) and how many an order recovered. */
type WooAbandonedTotals = Record< string, { carts: number; products: number; value: number; currency: string; recovered?: number } >;

/** Counted carts whose shopper may be back, by cart id: when each was left (RFC 3339 UTC) and what it was worth. */
type WooAbandonedReturning = Record< string, { left_at: string; value: number } >;

/** What the carts left in a time frame tell the board (inc/strip.php `abandonment()`). */
interface WooAbandonment {
	/** The time frame, in hours. */
	hours: number;
	/** Where most carted leavers left from; null when nobody left. */
	leak: { kind: string; count: number; leavers: number; median_s: number } | null;
	/** The pages most leavers left from, with how many left from each and the median time there; pages that are gone are one row, `deleted` with id 0. */
	pages?: Array< { kind: string; id: number; left: number; median_s: number } >;
	/** How many pages leavers left from, listed above or not. */
	pages_total?: number;
	/** The products most often in a left cart, with how many carts bought each. */
	products: Array< { id: number; left: number; bought: number } >;
	/** How many products were in a left cart, listed above or not. */
	products_total?: number;
}

/** One cart segment, computed on the board by crossing rows with presence. */
interface WooCartSegment {
	shoppers: number;
	products: number;
	revenue: number;
	currency: string;
}

/** The carts left behind in one time frame, in hours. */
interface WooLeftBehind extends WooCartSegment {
	hours: number;
}

/** What the board renders: the open rows of connected shoppers split by whether a tab is in view, and the carts left behind. */
interface WooDashboardSnapshot {
	carts: {
		/** Carts whose shopper has a visible tab right now. */
		live: WooCartSegment;
		/** Carts whose shopper is connected but has every tab hidden. */
		away: WooCartSegment;
	};
	/** Distinct storefront visitors connected right now (deduped by visitor id across tabs). */
	usersOnline: number;
	/** The staff share of the figures: visitors above who are staff, and open tabs (admin or storefront) that are theirs. */
	staff: { users: number; tabs: number };
	/** Connected visitors whose every tab is hidden. */
	awayUsers: number;
	/** The time frame the abandoned carts below are of, in hours. */
	abandonedHours: number;
	/** Carts left in that time frame and not ordered since, whose shopper is not back and whose leave is older than the grace, newest first. */
	abandoned: WooAbandonedRow[];
	/** Carts left in that time frame that an order followed, newest leave first. */
	recovered: WooAbandonedRow[];
	/** How many carts left in that time frame an order followed, listed above or not. */
	recoveredCount: number;
	/** For each time frame the board offers: every cart left in it and not ordered since, listed above or not, whose shopper is not back. */
	leftBehind: WooLeftBehind[];
	/** What those carts tell; null with history off. */
	abandonment: WooAbandonment | null;
	online: { active_connections: number; max_connections: number } | null;
	/** This day's strip; null with history off. */
	strip: WooDayStrip | null;
	/**
	 * One row per connected visitor: every tab of theirs, the leading one (their visible tab, or the
	 * last announced when every tab is hidden) first, and the cart row for their cart id.
	 *
	 * @since 0.8.0
	 */
	shoppers: WooShopper[];
}

/**
 * One open tab of a connected visitor, with the journey of its own it carries.
 *
 * @since 0.8.0
 */
interface WooTab {
	/** The connection id the relay gave the tab. */
	key: string;
	/** The open step's kind, the page before `away` when the tab is hidden. */
	kind: string;
	/** The open step's id, 0 when the kind has none. */
	id: number;
	/** The journey as the tracker announced it, `kind:id:s` steps joined by `>`. */
	j: string;
	/** Whether the tab is hidden. */
	away: boolean;
}

/**
 * A connected visitor as the shoppers table lists them: `kind`, `id` and `j` are the leading tab's.
 *
 * @since 0.8.0
 */
interface WooShopper {
	/** The visitor id (WordSocket's, one per browser). */
	v: string;
	/** The cart id, null without a cart. */
	b: string | null;
	/** The leading tab's open step kind, the page before `away` when the tab is hidden. */
	kind: string;
	/** The leading tab's open step id, 0 when the kind has none. */
	id: number;
	/**
	 * The leading tab's journey as the tracker announced it, `kind:id:s` steps joined by `>`.
	 *
	 * @since 0.8.0
	 */
	j: string;
	/**
	 * Every open tab of theirs, the leading one first, then the rest by their state and then by
	 * how recently they announced.
	 *
	 * @since 0.8.0
	 */
	tabs: WooTab[];
	/** Whether every tab of theirs is hidden. */
	away: boolean;
	/** Whether the visitor is a staff member browsing the store. */
	staff: boolean;
	/** The cart row for `b`, null when there is none. */
	cart: WooCartRow | null;
	/**
	 * The best guess at who a guest is, null when there is none or they are named already.
	 *
	 * @since 0.8.0
	 */
	guess?: WooGuestRef | null;
}

/**
 * A best guess at who a guest is: the email their browser remembers from a paid order here (inc/guests.php).
 *
 * @since 0.8.0
 */
interface WooGuestRef {
	v: string;
	email: string;
	/** The billing name on the newest order, '' when it has none. */
	name: string;
	/** Paid orders placed with the email. */
	orders: number;
}

/** A product the board names (inc/dashboard.php `products_for_board()`). */
interface WooProductRef {
	id: number;
	name: string;
	/** The wp-admin edit screen; the board follows it only into this site's admin. */
	edit_url: string;
	/**
	 * The storefront product page; followed only onto this site.
	 *
	 * @since 0.8.0
	 */
	url: string;
}

/**
 * A page the board names (inc/dashboard.php `pages_for_board()`): a post, a term archive or a post type archive.
 *
 * @since 0.8.0
 */
interface WooPageRef {
	/** The journey step, `kind:id`. */
	step: string;
	title: string;
	/** The public URL; followed only onto this site. */
	url: string;
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
	permalink: string;
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
	/** The shopper's account, 0 for a guest; absent on a frame from before 0.8. */
	user_id?: number;
	/** The account's display name, empty for a guest. */
	customer?: string;
	/** Unix seconds when it was raised. */
	at: number;
}
