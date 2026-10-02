=== ShopSocket ===
Contributors: wpsignal, jaredrethman
Tags: woocommerce, abandoned cart, sales notification, wordsocket, realtime
Requires at least: 6.7
Tested up to: 7.1
Stable tag: 0.5.0
Requires PHP: 8.2
Requires Plugins: wordsocket, woocommerce
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

See your WooCommerce store live: orders as they land, who is shopping, carts and stock. Needs WordSocket and a WPSignal account.

== Description ==

ShopSocket shows your WooCommerce store as it happens. Think Shopify's Live View, for WooCommerce.

https://www.youtube.com/watch?v=BKwID6t_kpQ

**Requires** the [WordSocket](https://wordpress.org/plugins/wordsocket/) plugin and a [WPSignal](https://wpsignal.io/) account. Install WordSocket, click Connect ([video](https://www.youtube.com/watch?v=yS1roK49HEQ)), then install ShopSocket.

**Try it:** [shopsocket.wpsignal.io](https://shopsocket.wpsignal.io/) is a live demo store.

**For you and your team**

* Every order on screen the moment it is placed, with a chime
* Who is shopping right now, what is in their carts, and what it is worth
* Which carts were left behind
* Which products are in carts right now
* Low-stock and sold-out alerts as they happen
* Full-screen mode for a wall display

**For your shoppers**

* Stock on product pages updates without a reload
* "X shoppers have this in their cart right now" under the price
* "Someone just added this to their cart" notices
* A Live Stock block for any page

Each shopper feature can be switched off site-wide, per category, or per product. All are on by default.

**What it does for your store**

*Never miss an order.* Every new order lands on the ShopSocket board the instant it is placed, with a chime, and its status updates in place as it moves along. Keep the board open on a second screen or a wall display and stop refreshing the Orders page.

*Know who is shopping right now.* How many people are on your store this minute, how many are holding a cart, what is in those carts, and what it is worth. Live means the shopper is on your site at this moment, not a guess from a timestamp.

*See which carts were left behind.* A cart turns abandoned the moment its shopper leaves, with the revenue still in it, so you know what walked away and when.

*Spot what is about to sell.* The products sitting in live carts, most held first, with the number of carts and units for each, one click from the product's edit screen.

*Catch stock problems as they happen.* Low-stock and out-of-stock alerts reach the board at once, and the product page itself updates: the stock text changes and the add-to-cart button follows, with no reload. A shopper holding a product that sells out hears straight away, and their cart shows WooCommerce's own notice.

*Give undecided shoppers a nudge.* "3 shoppers have this in their cart right now" under the price, kept current as carts change, and a quiet "someone just added this to their cart" notice for shoppers holding the same product.

*Fits your theme.* The storefront features bind to the classic templates and to the Product Price and Product Stock Indicator blocks. The Live Stock block puts availability, units left, and the in-cart count anywhere you like.

**Where to find it**

The board is under WooCommerce > Analytics > Realtime (WooCommerce > ShopSocket when Analytics is switched off). Settings are on the ShopSocket tab of WordSocket's settings page, and each product has its own choices under Product data > ShopSocket.

**Disclaimer**

WPSignal is an independent service, not affiliated with or endorsed by Shopify. Shopify is a trademark of Shopify Inc., named only for comparison.

= Third-Party Service =

ShopSocket uses the **WPSignal service** (api.wpsignal.io), through the WordSocket plugin, to deliver live updates to the browsers on your store. When an order is placed or changes status, stock changes, or a product is added to a cart, WordSocket sends an encrypted event to WPSignal, which relays it to your store's open browsers. Events are not stored.

**What leaves your site, and who can read it.** Every event is encrypted before it leaves WordPress, with a key made from your site's own WordPress salts, which WPSignal never has. WPSignal relays what it cannot open: it never sees an order total, a customer name, a product, or a stock level.

Inside those encrypted events:

* Order events, visible to staff only: the order number, status, total, item count, payment method, and the customer's first name and last initial. No email, address, or line items.
* Stock and cart events, visible on your storefront: product names, links, thumbnails, and counts, plus an anonymous cart id so a shopper's own adds and purchases are never announced back to them.

What WPSignal does see, because it needs it to deliver messages: channel names, how many browsers are connected, and an anonymous id for each shopper's browser and cart. None of it is personal.

* [Terms of Service](https://wpsignal.io/terms)
* [Privacy Policy](https://wpsignal.io/privacy)

= Source code =

ShopSocket is developed in the open at [github.com/wpsignal/wordsocket-extensions](https://github.com/wpsignal/wordsocket-extensions) under `shopsocket/`.

== Installation ==

1. Install and activate WooCommerce and [WordSocket](https://wordpress.org/plugins/wordsocket/).
2. In WordSocket's settings, click Connect to WPSignal and create an account ([video](https://www.youtube.com/watch?v=yS1roK49HEQ)).
3. Install and activate ShopSocket.
4. Open WooCommerce > Analytics > Realtime.

== Frequently Asked Questions ==

= What do I need? =

WooCommerce, the free [WordSocket](https://wordpress.org/plugins/wordsocket/) plugin, and a [WPSignal](https://wpsignal.io/) account (the free plan is enough to start).

= Does it cost anything? =

ShopSocket and WordSocket are free. WPSignal has a free plan; paid plans for busier stores are at [wpsignal.io/pricing](https://wpsignal.io/pricing/).

= Is there a demo? =

Yes: [shopsocket.wpsignal.io](https://shopsocket.wpsignal.io/). Open a product in two browsers and add it to the cart in one. The demo shows the storefront; the board is in wp-admin.

= Does it work with block themes? =

Yes. For full control, add the Live Stock block to the Single Product template or any page.

= Does it work on a local site over plain HTTP? =

Yes.

= Does it support High-Performance Order Storage? =

Yes.

= Can I turn parts of it off? =

Yes. Site-wide switches and category exclusions are on the ShopSocket tab of WordSocket's settings page (WordSocket 0.28 or later). Each product has its own Default, On, or Off choice under Product data > ShopSocket.

Developers: `shopsocket_storefront`, `shopsocket_in_carts_enabled`, `shopsocket_activity_enabled`, `shopsocket_activity_throttle`, and `shopsocket_publish_stock` filters.

= Who can see the board? =

Anyone who can manage WooCommerce.

== Screenshots ==

1. The Realtime board under Analytics, before the first shopper arrives.
2. The board with a busy store: users online, live and abandoned carts with their revenue, the products in live carts, and orders as they are placed.
3. A product page: live stock, how many shoppers hold the product right now, and a toast when someone else adds it.
4. The cart at the moment a held product sells out elsewhere: WooCommerce's own notice appears without a reload, with ShopSocket's alert beside it.
5. The Live Stock block on an ordinary page: availability, units left, and the in-cart count, all live.

== Changelog ==

= 0.5.0 =
* New: a ShopSocket tab on WordSocket's settings page (WordSocket 0.28 or later) with site-wide switches for the in-cart count and the added-to-cart notification, each with its own list of product categories to exclude
* New: a per-product choice for each feature under Product data > ShopSocket: Default, On, or Off. On and Off win over the site setting and the categories
* New: the in-cart count speaks to a shopper who holds the product: "You have this in your cart right now", "You and 1 other shopper have this in your carts right now". The `woo.cart.removed` event now carries the remover's anonymous cart id
* Changed: switching the toasts off no longer stops the cart-added event, which the in-cart count needs; the event now carries an `activity` flag and the storefront shows no toast when it is false. A product with both features off publishes nothing

= 0.4.0 =
* "Basket" is now "cart" everywhere: on the board, in this readme, and in the code. Breaking if you listen to the relay directly: the `woo.baskets` event is now `woo.carts`, `GET /shopsocket/v1/basket-id` is now `/cart-id`, and the `shopsocket_baskets` transients are now `shopsocket_carts`
* Fixed: the storefront stylesheet was left out of the release package, so the in-cart counter's dot and update flash never showed on installed sites
* Storefront scripts are versioned by their contents, so an update reaches browsers that cached the previous build
* The board holds a lower Open Tabs figure for a few seconds before showing it, so a shopper moving between pages no longer reads as a tab closing and reopening

= 0.3.2 =
* Fixed: the extension registered its name and description before WordPress loads translations, which logged a notice with debugging enabled

= 0.3.1 =
* Fixed: a shopper who buys the last unit is no longer told it has sold out (stock events now carry the buyer's anonymous cart id)
* Fixed: the board's logo no longer flashes at full screen width before its styles load
* The readme spells out what leaves your site and what the relay can read

= 0.3.0 =
* The plugin is listed under WordSocket on the Plugins screen, where WordSocket 0.24 and later keep the family together

= 0.2.1 =
* Requires WordSocket 0.23: the visitor id and channel check now come from WordSocket
* Fixed: the Live Stock block's in-cart count was missing on load on pages other than the product's own
* Works on plain HTTP sites


= 0.2.0 =
* First public release
* The Realtime board under WooCommerce Analytics: users online, live and abandoned carts from relay presence, products in live carts with units, and live orders that update in place
* Live stock on product pages, the shop, and the cart: availability text, add-to-cart buttons, quantity limits, and "N shoppers have this in their cart"
* Sell-out notices: a shopper holding a product that sells out hears at once, and the cart shows WooCommerce's own notice without a reload
* "Someone just added this to their cart" toasts, shown only for products the shopper also holds
* The Live Stock block for the Single Product template or any page
* A card on WordSocket's Extensions tab

== Upgrade Notice ==

= 0.5.0 =
Adds the storefront settings. Update WordSocket to 0.28 first to see the new ShopSocket tab.

= 0.4.0 =
Breaking for direct relay listeners: `woo.baskets` is now `woo.carts` and `basket-id` is now `cart-id`. Everything else is a fix.

= 0.3.2 =
Fixes a premature translation notice logged with debugging enabled.

= 0.3.1 =
Fixes a sold-out notice shown to the shopper who bought the last unit.

= 0.3.0 =
The plugin is listed under WordSocket on the Plugins screen, with WordSocket 0.24 and later.

= 0.2.1 =
Requires WordSocket 0.23: the visitor id and channel check now come from WordSocket

= 0.2.0 =
First public release.
