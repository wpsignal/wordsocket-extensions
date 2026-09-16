/**
 * The part of WordSocket's client API (`window.WPS`, wp-plugin/src/types,
 * WordSocket 0.21) this plugin uses. Update when the contract changes.
 */

type WPSEventHandler = ( data: Record< string, unknown >, channel: string ) => void;
type WPSTransportName = 'ws' | 'sse';

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

/** Public API exposed by the WordSocket client on window.WPS (the members used here). */
interface WPSApi {
	/** Register a handler for a specific event name. Returns unsubscribe fn. */
	on( event: string, handler: WPSEventHandler ): () => void;
	/** Register a handler for the full connection state. Returns unsubscribe fn. */
	onStateChange( handler: ( state: WPSConnectionState ) => void ): () => void;
	/** The current connection state, as delivered to onStateChange. */
	readonly state: WPSConnectionState;
	/**
	 * Connection-scoped presence: join or update membership on a channel with an
	 * opaque state object, or leave it with `null`. The relay drops membership
	 * when the socket closes and the client re-sends on reconnect.
	 */
	setPresence( channel: string, state: Record< string, unknown > | null ): void;
}
