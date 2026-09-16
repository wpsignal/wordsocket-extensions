declare global {
	interface Window {
		WPS?: WPSApi;
		/** Localized by inc/admin-board.php. */
		shopSocket?: ShopSocketBoardConfig;
		/** Localized by inc/admin-board.php on WordSocket's settings screen. */
		shopSocketSettings?: ShopSocketSettingsConfig;
		/** WordSocket's extension API, published by its settings bundle. */
		wordsocket?: WordSocketExtensionsApi;
	}
}

export {};
