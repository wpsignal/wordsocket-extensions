=== ShopSocket ===
Contributors: wpsignal
Tags: woocommerce, realtime, live orders, abandoned cart, inventory
Requires at least: 6.7
Tested up to: 7.1
Stable tag: 0.2.1
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
* A shopper holding a product that sells out hears at once, wherever they are, and the cart shows WooCommerce's own notice without a reload
* The Live Stock block: availability, units left, and the in-cart counter as one block for the Single Product template or any page
* A card on WordSocket's Extensions tab with the connection state and a link to the board

**How it works**

WordSocket carries the events over one WebSocket per browser, with an SSE fallback. ShopSocket publishes order, stock, and basket events from WooCommerce's own hooks, and reads presence from the relay to tell live baskets from abandoned ones. Nothing polls: the storefront fetches once per page load and then only reacts to events.

ShopSocket requires WordSocket and a WPSignal account. WPSignal is an independent service and is not affiliated with or endorsed by the WordPress project or by WooCommerce.

**What leaves your site**

* Order events go to a staff-only channel and carry the order number, status, total, item count, payment method, and the customer's first name and last initial. No email, address, or line items.
* Stock and basket-activity events are public to the storefront and carry product names, permalinks, thumbnails, and counts.
* A shopper's basket is identified by a keyed hash of their WooCommerce session, never by anything reversible, and presence carries a random per-browser id plus that hash.
* When the site runs over HTTPS, WordSocket encrypts event payloads before they leave the site.

= Third-Party Service =

ShopSocket relies on the **WPSignal service** at api.wpsignal.io, reached through the WordSocket plugin. ShopSocket opens no connection of its own: everything below travels over the connection WordSocket already holds for your site.

* **Event publishing**: when an order is placed or changes status, stock changes, or a product is added to a basket, WordSocket sends an HMAC-signed HTTP request to the service with the event described under "What leaves your site".
* **Realtime connections**: browsers on your storefront and the staff board connect to the service over WebSocket (or SSE) to receive those events. Shoppers' browsers also announce their presence on the site, which is how the board tells a live basket from an abandoned one.
* **Connection count**: the board asks the service how many browsers are connected to your site right now.

Events are relayed in realtime and are **not stored** on the service. Over HTTPS, payloads are AES-256-GCM encrypted before they leave WordPress, and the service relays ciphertext it cannot read. A WPSignal account is required; the free plan is enough to start.

* [Terms of Service](https://wpsignal.io/terms)
* [Privacy Policy](https://wpsignal.io/privacy)

== Installation ==

1. Install and activate WooCommerce and WordSocket 0.23 or newer, and connect WordSocket to your WPSignal account.
2. Install and activate ShopSocket.
3. Open Analytics > Realtime for the board (WooCommerce > ShopSocket when WooCommerce Analytics is switched off). Product pages start updating on their own.

== Frequently Asked Questions ==

= Does it work with block themes? =

Yes. The storefront features bind to the classic templates and to the Product Price and Product Stock Indicator blocks. For markup that is yours to place, add the Live Stock block to the Single Product template, or to any page with a product chosen.

= Does it work on a local site over plain HTTP? =

Yes. The connection to the relay is always TLS, even from an `http://` page. The one difference is that WordSocket only encrypts event payloads when the site itself runs over HTTPS, so on a plain HTTP site the relay can read the events it forwards. Use HTTPS in production.

= Does it support High-Performance Order Storage? =

Yes. ShopSocket declares HPOS compatibility and reads orders through WooCommerce's order API.

= Can I turn parts of it off? =

Filters: `shopsocket_storefront` controls which pages load the live storefront (every front-end page by default, so a shopper stays live wherever they browse), `shopsocket_in_carts_enabled` the basket counter, `shopsocket_activity_enabled` the added-to-basket toasts, `shopsocket_activity_throttle` how often one product may announce an add, and `shopsocket_publish_stock` whether stock changes are published (they are silent during imports).

= Who can see the board? =

Users with the `manage_woocommerce` capability. Order and basket events travel on a channel only their connection tokens can read.

== Screenshots ==

1. The Realtime board under Analytics, before the first shopper arrives.
2. The board with a busy store: users online, live and abandoned baskets with their revenue, the products in live baskets, and orders as they are placed.
3. A product page: live stock, how many shoppers hold the product right now, and a toast when someone else adds it.
4. The cart at the moment a held product sells out elsewhere: WooCommerce's own notice appears without a reload, with ShopSocket's alert beside it.
5. The Live Stock block on an ordinary page: availability, units left, and the in-cart count, all live.

== Changelog ==

= 0.2.1 =
* Requires WordSocket 0.23: the visitor id and channel check now come from WordSocket
* Fixed: the Live Stock block's in-cart count was missing on load on pages other than the product's own
* Works on plain HTTP sites


= 0.2.0 =
* First public release
* The Realtime board under WooCommerce Analytics: users online, live and abandoned baskets from relay presence, products in live baskets with units, and live orders that update in place
* Live stock on product pages, the shop, and the cart: availability text, add-to-cart buttons, quantity limits, and "N shoppers have this in their cart"
* Sell-out notices: a shopper holding a product that sells out hears at once, and the cart shows WooCommerce's own notice without a reload
* "Someone just added this to their basket" toasts, shown only for products the shopper also holds
* The Live Stock block for the Single Product template or any page
* A card on WordSocket's Extensions tab

== Upgrade Notice ==

= 0.2.1 =
Requires WordSocket 0.23: the visitor id and channel check now come from WordSocket

= 0.2.0 =
First public release.
