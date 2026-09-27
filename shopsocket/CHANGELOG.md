**0.4.0** - Renamed "basket" to "cart" throughout (code, events, and text). Breaking for anyone listening directly to the relay: the `woo.baskets` event is now `woo.carts`, the `GET /shopsocket/v1/basket-id` route is now `/cart-id`, and the `shopsocket_baskets` / `shopsocket_baskets_pub` transients are now `shopsocket_carts` / `shopsocket_carts_pub`. Storefront modules and the storefront stylesheet now ship with content-hash asset manifests (`build/storefront/*.asset.php`), so a rebuild reaches browsers that cached the previous build; the stylesheet is built into `build/storefront/` too, where before it was enqueued from `src/`, which the release zip excludes. The board holds a lower Open Tabs figure for the presence grace before showing it, so a shopper navigating between pages no longer reads as a tab closing and reopening.

**0.3.2** - The catalogue entry registers on `init`, so its translated strings no longer load the text domain before WordPress is ready.

**0.3.1** - A shopper who buys the last unit is no longer told it has sold out, and the board's logo no longer flashes before its styles load.

**0.3.0** - The plugin's row sits under WordSocket on the Plugins screen (needs WordSocket 0.24).

**0.2.1** - Requires WordSocket 0.23: the visitor id and channel check now come from WordSocket.

**0.2.0** - First public release: the Realtime board under WooCommerce Analytics (users online, live and abandoned baskets, products in live baskets, live orders), live stock and sell-out notices on the storefront, added-to-basket toasts, the Live Stock block, and a card on WordSocket's Extensions tab.

**0.1.1** - Test the release pipeline.

**0.1.0** - In development: the live orders board, live and abandoned baskets, live stock, sell-out notices, and the Live Stock block.
