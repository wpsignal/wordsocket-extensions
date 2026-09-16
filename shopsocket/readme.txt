=== ShopSocket for WooCommerce ===
Contributors: wpsignal
Tags: woocommerce, realtime, live orders, abandoned cart, stock
Requires at least: 6.7
Tested up to: 7.1
Stable tag: 0.1.0
Requires PHP: 8.2
Requires Plugins: wordsocket, woocommerce
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A live orders board for your team and live stock on product pages, over the WordSocket realtime connection. No polling, no page reloads.

== Description ==

ShopSocket puts what is happening in your store on screen the moment it happens, for staff and shoppers alike.

**For your team: the ShopSocket board**

A screen under WooCommerce that staff keep open all day.

* Live orders: every new order lands on the board the instant it is placed, with a chime, and status changes update the row in place
* Baskets, live and abandoned: how many shoppers hold a basket right now, how many have left with one still held, and the revenue in each
* Products in live baskets: what shoppers on the site are holding at this moment, with the number of baskets and units for each, linking to the product's edit screen
* Users online and open connections, straight from the relay
* Low-stock and out-of-stock alerts as they happen
* Full-screen mode for a wall display

Live and abandoned are not guesses from timestamps. A basket is live exactly while its shopper has an open connection to the site, and flips to abandoned the instant their last tab closes.

**For shoppers: the storefront**

* Stock text on product pages updates in place when the product sells out or comes back, and the add-to-cart button follows
* "N shoppers have this in their cart right now" under the price, kept current as baskets change
* "Someone just added this to their basket" toasts, shown only for products the shopper also holds

**How it works**

WordSocket carries the events over one WebSocket per browser, with an SSE fallback. ShopSocket publishes order, stock, and basket events from WooCommerce's own hooks, and reads presence from the relay to tell live baskets from abandoned ones. Nothing polls: the storefront fetches once per page load and then only reacts to events.

ShopSocket requires WordSocket and a WPSignal account. WPSignal is an independent service and is not affiliated with or endorsed by the WordPress project or by WooCommerce.

**What leaves your site**

* Order events go to a staff-only channel and carry the order number, status, total, item count, payment method, and the customer's first name and last initial. No email, address, or line items.
* Stock and basket-activity events are public to the storefront and carry product names, permalinks, thumbnails, and counts.
* A shopper's basket is identified by a keyed hash of their WooCommerce session, never by anything reversible, and presence carries a random per-browser id plus that hash.
* When the site runs over HTTPS, WordSocket encrypts event payloads before they leave the site.

== Installation ==

1. Install and activate WooCommerce and WordSocket, and connect WordSocket to your WPSignal account.
2. Install and activate ShopSocket for WooCommerce.
3. Open WooCommerce > ShopSocket for the board. Product pages start updating on their own.

== Frequently Asked Questions ==

= Does it work with block themes? =

Yes. The storefront features bind to the classic templates and to the Product Price and Product Stock Indicator blocks, and the add-to-cart buttons of both.

= Does it support High-Performance Order Storage? =

Yes. ShopSocket declares HPOS compatibility and reads orders through WooCommerce's order API.

= Can I turn parts of it off? =

Filters: `shopsocket_storefront` controls which pages load the live storefront (WooCommerce pages, cart and checkout by default), `shopsocket_in_carts_enabled` the basket counter, `shopsocket_activity_enabled` the added-to-basket toasts, `shopsocket_activity_throttle` how often one product may announce an add, and `shopsocket_publish_stock` whether stock changes are published (they are silent during imports).

= Who can see the board? =

Users with the `manage_woocommerce` capability. Order and basket events travel on a channel only their connection tokens can read.

== Changelog ==

= 0.1.0 =
* First release: the live orders board, live and abandoned baskets from relay presence, products in live baskets, live stock and basket counts on product pages, and added-to-basket toasts
