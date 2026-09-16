/**
 * The API WordSocket exposes to extensions on `window.wordsocket`, copied from
 * wp-plugin/src/types/extensions.d.ts (WordSocket 0.22). Update when the
 * contract changes.
 */

interface WordSocketExtensionPanelProps {
	/** Extension slug, matching the PHP registration. */
	name: string;
	title: string;
	description?: string;
	/** Dashicon name or a React element for the card header. */
	icon?: string | React.ReactNode;
	docsUrl?: string;
	children?: React.ReactNode;
}

interface WordSocketConnection {
	isConnected: boolean;
	siteKey: string;
	fetchStatus: string;
	lastError: { code: string; message: string; detail: string; time: number } | null;
}

interface WordSocketExtensionsApi {
	/** API version, bumped on breaking changes. */
	version: number;
	/** A card in the Extensions tab. One per extension. */
	ExtensionPanel: React.ComponentType< WordSocketExtensionPanelProps >;
	/** One line rendered in the Connect tab's status area. */
	ConnectionStatusFill: React.ComponentType< { children?: React.ReactNode } >;
	/** The settings app's view of the WordSocket connection. */
	useConnection: () => WordSocketConnection;
	/** `window.WPS.state`, subscribed through `onStateChange`. */
	useClientState: () => WPSConnectionState | null;
}

/** Localized by inc/admin-board.php for the WordSocket settings screen. */
interface ShopSocketSettingsConfig {
	boardUrl: string;
	docsUrl: string;
}

/** Core's plugins package, taken from `wp.plugins`; it ships no types here. */
declare module "@wordpress/plugins" {
	export function registerPlugin(
		name: string,
		settings: { scope?: string; render: () => React.ReactNode; icon?: string | React.ReactNode },
	): unknown;
}
