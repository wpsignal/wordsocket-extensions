**0.5.1** - The board recovers orders it never heard about. The relay stores no events, so an order placed (or a status changed) while the board's connection was down, or whose publish never left WordPress, did not appear until the page was reloaded: the board's refresh covered carts and the online count only. `GET /shopsocket/v1/dashboard` now answers with `as_of` (the server clock) and, when the request carries `?since=<as_of>`, with `orders`: those created or changed since then, at most 50, in the shape of an order event (`board_orders()` in `inc/dashboard.php`, which also serves the first paint; `BOARD_ORDERS` moved there from `admin-board.php`). The board sends the `as_of` of its last successful refresh on every one (the 30 second poll, 1.5 seconds after a reconnect, and on returning to the tab) and merges what comes back (`reconcile` in `useLiveOrders.ts`): a missing order is added at the top and chimes once per refresh if it was created in that window, a changed one updates in place, and a row an event touched after the request left is not overwritten by the older response.

**0.5.0** - Storefront settings: 
- a ShopSocket tab on WordSocket's settings page (needs WordSocket 0.28, `window.wordsocket.registerTab`) with site-wide switches for the in-cart count and the added-to-cart notification, each with its own excluded product categories, and a per-product Default/On/Off choice for each feature on a ShopSocket tab of the Product data box (On and Off beat the site switch and the categories; the site switch is the default, not a master switch). 
- One option, `shopsocket_storefront` (`in_carts_enabled`, `activity_enabled`, `in_carts_excluded_categories`, `activity_excluded_categories`), read and written through `/wp/v2/settings`; two product meta keys, `_shopsocket_in_carts` and `_shopsocket_activity`; `feature_enabled()` in `inc/settings.php` combines them, and the `shopsocket_in_carts_enabled` / `shopsocket_activity_enabled` filters run last with the product ID as a second argument. 
- The in-cart counter personalises itself once the viewer's cart is known (`state.mine` from the viewer's sync, or the shopper's own `woo.cart.added` from another tab): "You have this in your cart right now", "You and 1 other shopper have this in your carts right now", "You and %d other shoppers ..."; the server still renders the impersonal form because a page cache may serve it to anyone. 
- `woo.cart.removed` now carries `actor` (the remover's cart id), so a shopper's own pages drop the "You" the moment they remove the item from any tab; without an actor the page re-checks its cart instead of trusting a stale belief. 
- The block-cart watcher also waits for `hasPendingItemsOperations()` to clear before re-reading the cart, because the store updates optimistically and a sync on the item change alone read the old cart. 
- The `woo.cart.added` event now carries `activity` (whether a toast may show) and keeps publishing while either feature applies to the product, so switching toasts off no longer freezes other shoppers' counters; a product with both off publishes nothing.

**0.4.0** - Renamed "basket" to "cart" throughout (code, events, and text). Breaking for anyone listening directly to the relay: the `woo.baskets` event is now `woo.carts`, the `GET /shopsocket/v1/basket-id` route is now `/cart-id`, and the `shopsocket_baskets` / `shopsocket_baskets_pub` transients are now `shopsocket_carts` / `shopsocket_carts_pub`. Storefront modules and the storefront stylesheet now ship with content-hash asset manifests (`build/storefront/*.asset.php`), so a rebuild reaches browsers that cached the previous build; the stylesheet is built into `build/storefront/` too, where before it was enqueued from `src/`, which the release zip excludes. The board holds a lower Open Tabs figure for the presence grace before showing it, so a shopper navigating between pages no longer reads as a tab closing and reopening.

**0.3.2** - The catalogue entry registers on `init`, so its translated strings no longer load the text domain before WordPress is ready.

**0.3.1** - A shopper who buys the last unit is no longer told it has sold out, and the board's logo no longer flashes before its styles load.

**0.3.0** - The plugin's row sits under WordSocket on the Plugins screen (needs WordSocket 0.24).

**0.2.1** - Requires WordSocket 0.23: the visitor id and channel check now come from WordSocket.

**0.2.0** - First public release: the Realtime board under WooCommerce Analytics (users online, live and abandoned baskets, products in live baskets, live orders), live stock and sell-out notices on the storefront, added-to-basket toasts, the Live Stock block, and a card on WordSocket's Extensions tab.

**0.1.1** - Test the release pipeline.

**0.1.0** - In development: the live orders board, live and abandoned baskets, live stock, sell-out notices, and the Live Stock block.
