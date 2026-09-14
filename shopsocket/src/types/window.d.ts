declare global {
	interface Window {
		WPS?: WPSApi;
		/** Localized by inc/admin-board.php. */
		shopSocket?: ShopSocketBoardConfig;
	}
}

export {};
