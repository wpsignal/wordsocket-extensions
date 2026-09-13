/** Copied from wp-plugin/src/types (WordSocket 0.21). Update when the contract changes. */
/** Localized by class-wpsignal-client.php */
interface WpSignalConfig {
	/** WordPress version. */
	wpVersion: number;
	/** Whether to use constant credentials. */
	isConstant: boolean;
	/** Whether the WP sync engine is present (Gutenberg plugin until RTC ships in core). */
	isWpRtcAvailable: boolean;
	/** Whether real-time collaboration is enabled (Gutenberg > Experiments toggle, via wp_is_collaboration_enabled()). */
	isWpRtcEnabled: boolean;
	/** Whether to use SSL. */
	isSsl: boolean;
	/** WPSignal dashboard URL (settings page only; derived from the server base URL). */
	dashboardUrl?: string;
	/** Whether to enable debug mode. */
	isDebug: boolean;
	/** WordPress REST URL. */
	restUrl: string;
	/** WordPress REST nonce. */
	nonce: string;
	/** WPSignal server URL. */
	baseUrl: string;
	/** Server-side minted token (present on first load; absent on refresh calls). */
	token?: string;
	channels?: string[];
	exp?: number;
	/** Base64-encoded raw AES-256 key for decrypting incoming "encrypted" messages. Never sent to WPSignal. */
	encryptionKey?: string;
	/** Force use of SSE instead of WebSocket. */
	forceSSE?: boolean;
}

/** Localized by class-wpsignal-admin-page.php (Settings React app) */
interface WpSignalSettings {
	/** URL for the connection endpoint. */
	connectUrl: string;
	/** URL for the OAuth start endpoint. */
	oauthStartUrl: string;
	/** URL for the REST endpoint. */
	restUrl: string;
	/** wp_rest nonce for authenticated REST requests. */
	nonce: string;
	/** Array of public post type objects (value, label) for trigger dropdowns. */
	postTypes: Array< { value: string; label: string } >;
	/** WPSignal server base URL. */
	baseUrl: string;
	/** WPSignal dashboard URL, derived from baseUrl. */
	dashboardUrl: string;
	/** Stored site key (set after either connection flow completes). */
	siteKey: string;
	/** Array of registered trigger instances. */
	triggers: WpSignalTriggerRow[];
}

/** Localized by class-wpsignal-client.php (enqueue_yjs_provider) */
interface WpSignalYjsConfig {
	/** Channel prefix for Yjs channels, e.g. `site:{site_id}:yjs:`. Keeps Yjs channels within the JWT's allowed_channel_prefixes. */
	channelPrefix: string;
}

/** Localized by class-wpsignal-admin-page.php (added to wpsignalSettings for the Explorer tab) */
interface WpSignalTriggerRow {
	event: string;
	hook: string;
	priority: number;
	args: number;
	channel: string;
	condition: boolean;
}

type WPSMessageHandler = ( event: string, data: Record< string, unknown >, channel: string ) => void;
type WPSEventHandler = ( data: Record< string, unknown >, channel: string ) => void;
/** Handler for incoming binary WebSocket frames (e.g. Yjs updates). */
type WPSBinaryHandler = ( channel: string, data: Uint8Array ) => void;
type WPSTransportName = 'ws' | 'sse';
type WPSStatus = {
	name: WPSTransportName | null;
	connected: boolean;
	readyState: number | null;
	canPublish: boolean;
	canPublishBinary: boolean;
	/** Timestamp (ms) of the last inbound frame (including server pings); null when unknown or on SSE. */
	lastMessageAt: number | null;
	/** Why the connection is down, if it is; cleared on the next successful open. */
	lastError: WPSConnectionError | null;
	/** Consecutive failed connection attempts since the last successful open. */
	failures: number;
};

/**
 * Error codes shared with the Yjs provider. They mirror @wordpress/sync's
 * ConnectionErrorCode so the editor can pick its copy without translation.
 */
type WPSConnectionErrorCode =
	| 'authentication-failed'
	| 'connection-expired'
	| 'connection-limit-exceeded'
	| 'document-size-limit-exceeded'
	| 'protocol-mismatch'
	| 'unknown-error';

interface WPSConnectionError {
	code: WPSConnectionErrorCode;
	message: string;
}

/** Full connection state delivered to `onStateChange` handlers. */
interface WPSConnectionState {
	connected: boolean;
	transport: WPSTransportName | null;
	/** Present while disconnected because of an error. */
	error?: WPSConnectionError;
	/** Present while an automatic retry is scheduled: milliseconds until it fires. */
	retryInMs?: number;
	/** Consecutive failed attempts since the last successful open. */
	failures: number;
}

/** Public API exposed by the WordSocket client on window.WPS */
interface WPSApi {
	/** Subscribe to additional channels on the shared connection. */
	subscribe( channels: string[] ): void;
	/** Unsubscribe from channels. */
	unsubscribe( channels: string[] ): void;
	/** Publish a JSON message through the WebSocket. No-ops on SSE (one-way transport). */
	publish( channel: string, event: string, data?: Record< string, unknown > ): void;
	/**
	 * Send a raw binary frame to the server over the WebSocket.
	 * Frame format: 2-byte BE channel name length + channel bytes + payload.
	 * No-ops on SSE (one-way transport) or when not connected.
	 */
	publishBinary( channel: string, data: Uint8Array ): void;
	/** Register a handler for a specific event name. Returns unsubscribe fn. */
	on( event: string, handler: WPSEventHandler ): () => void;
	/** Register a catch-all handler for incoming JSON messages. Returns unsubscribe fn. */
	onMessage( handler: WPSMessageHandler ): () => void;
	/** Register a handler for incoming binary frames. Returns unsubscribe fn. */
	onBinaryMessage( handler: WPSBinaryHandler ): () => void;
	/** Whether the connection is currently open. */
	readonly connected: boolean;
	/** Current transport layer, or null while still connecting. */
	readonly transport: WPSTransportName | null;
	/** Current transport state and capabilities. */
	readonly status: WPSStatus;
	/** Register a callback for connection state changes. Returns unsubscribe fn. */
	onConnectionChange( handler: ( connected: boolean ) => void ): () => void;
	/** Register a callback receiving the full connection state (error, retry countdown, failure count). Returns unsubscribe fn. */
	onStateChange( handler: ( state: WPSConnectionState ) => void ): () => void;
	/** The current connection state, as delivered to onStateChange. */
	readonly state: WPSConnectionState;
}

// ---------------------------------------------------------------------------
// Ambient module declarations for WordPress externals used by the Yjs provider.
// Runtime values are provided by WordPress core (wp.sync, wp.hooks).
// ---------------------------------------------------------------------------

declare module '@wordpress/sync' {
	/** Yjs library re-exported by @wordpress/sync. Import from here, not from 'yjs', to avoid duplicate instances. */
	export const Y: {
		/** v1 — kept for reference; WordPress uses v2 internally. */
		applyUpdate( doc: unknown, update: Uint8Array, origin?: unknown ): void;
		/** @param encodedTargetStateVector — if provided, encodes only the updates the target is missing. */
		encodeStateAsUpdate( doc: unknown, encodedTargetStateVector?: Uint8Array ): Uint8Array;
		encodeStateVector( doc: unknown ): Uint8Array;
		/** v2 — used by WordPress 7.0 sync observers. Always prefer these. */
		applyUpdateV2( doc: unknown, update: Uint8Array, origin?: unknown ): void;
		encodeStateAsUpdateV2( doc: unknown, encodedTargetStateVector?: Uint8Array ): Uint8Array;
		encodeStateVectorFromUpdateV2( update: Uint8Array ): Uint8Array;
	};
}

declare module '@wordpress/hooks' {
	export function addFilter(
		hookName: string,
		namespace: string,
		callback: ( ...args: unknown[] ) => unknown,
		priority?: number
	): void;
}
