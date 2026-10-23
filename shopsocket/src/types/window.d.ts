declare global {
	interface Window {
		WPS?: WPSApi;
		/** WordSocket's own inline config; only the page step matters here. */
		wpSignalConfig?: { presence?: { kind: string; id: number } };
		/** Localized by inc/admin-board.php. */
		shopSocket?: ShopSocketBoardConfig;
		/** Localized by inc/admin-board.php on WordSocket's settings screen. */
		shopSocketSettings?: ShopSocketSettingsConfig;
		/** WordSocket's extension API, published by its settings bundle. */
		wordsocket?: WordSocketExtensionsApi;
	}
}

export {};
