/**
 * Ambient declarations for `@wordpress/interactivity` (WordPress resolves the
 * bare import through the script-module import map, so nothing is bundled)
 * and for the WooCommerce and jQuery globals the storefront module touches.
 */
declare module "@wordpress/interactivity" {
	/** The per-element context object (from `data-wp-context`). */
	export function getContext< T = Record< string, unknown > >(): T;
	/** Register or read a store; the state the server seeded is deep-merged into `T`. */
	export function store< T extends object >(
		namespace: string,
		storePart?: Partial< T >,
		options?: { lock: boolean | string }
	): T;
}

/** Minimal shape of WooCommerce's block cart data store (`wc/store/cart`). */
interface WooCartStoreSelectors {
	getCartData?(): { items?: Array< { id: number; quantity: number } > };
}

/** Minimal shape of `window.wp.data` used by the storefront. */
interface WooDataRegistry {
	subscribe( listener: () => void, storeName?: string ): () => void;
	select( storeName: string ): WooCartStoreSelectors | undefined;
}

/** Minimal chainable jQuery used for WooCommerce's classic (jQuery) events. */
interface WooJQueryChain {
	on( events: string, selector: string, handler: ( ...args: unknown[] ) => void ): WooJQueryChain;
	on( events: string, handler: ( ...args: unknown[] ) => void ): WooJQueryChain;
}
interface WooJQuery {
	( target: Document | HTMLElement ): WooJQueryChain;
}

interface Window {
	wp?: { data?: WooDataRegistry };
	jQuery?: WooJQuery;
}
