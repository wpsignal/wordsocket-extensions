declare global {
	interface Window {
		wpSignalConfig?: WpSignalConfig;
		WPS?: WPSApi;
		wordsocket?: WordSocketExtensionsApi;
		/** Localized by inc/admin-board.php. */
		wordsocketWoo?: WordSocketWooBoardConfig;
	}
}

export {};
