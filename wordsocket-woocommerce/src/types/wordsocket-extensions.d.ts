/** Copied from wp-plugin/src/types (WordSocket 0.21). Update when the contract changes. */
/**
 * The API WordSocket exposes to extensions on `window.wordsocket`.
 *
 * An extension is a separate plugin whose settings render inside WordSocket's
 * settings page. Its script is enqueued on the `wordsocket_settings_enqueue`
 * action with `wpsignal-settings` as a dependency, registers with
 * `wp.plugins.registerPlugin( slug, { scope: 'wordsocket', render } )`, and
 * renders an `ExtensionPanel` from `render`. Both bundles share React through
 * the `wp.*` globals, which is what lets one plugin's components render inside
 * another's tree.
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
	/** One line rendered in the Connect tab's status area (for example "Live stock paused"). */
	ConnectionStatusFill: React.ComponentType< { children?: React.ReactNode } >;
	/** The settings app's view of the WordSocket connection. */
	useConnection: () => WordSocketConnection;
	/** `window.WPS.state`, subscribed through `onStateChange`. */
	useClientState: () => WPSConnectionState | null;
}

interface WordSocketExtensionInfo {
	slug: string;
	title: string;
	description: string;
	version: string;
	docs_url: string;
	installed: boolean;
	/** Catalogue entries only: whether the extension can be installed today. */
	available?: boolean;
	/** Labels of required plugins that are not active. */
	missing: string[];
}
