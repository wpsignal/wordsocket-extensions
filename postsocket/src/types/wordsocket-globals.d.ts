/**
 * The part of WordSocket's client API (`window.WPS`, wp-plugin/src/types, WordSocket 0.32) this plugin uses.
 *
 * @since 0.1.0
 */

type WPSEventHandler = (data: Record<string, unknown>, channel: string) => void;
type WPSTransportName = "ws" | "sse";

interface WPSConnectionError {
  code: string;
  message: string;
}

/** Full connection state delivered to `onStateChange` handlers. */
interface WPSConnectionState {
  connected: boolean;
  transport: WPSTransportName | null;
  error?: WPSConnectionError;
  retryInMs?: number;
  failures: number;
}

/**
 * Public API exposed by the WordSocket client on window.WPS (the members used here).
 *
 * @since 0.1.0
 */
interface WPSApi {
  /** Register a handler for a specific event name. Returns unsubscribe fn. */
  on(event: string, handler: WPSEventHandler): () => void;
  /** Register a handler for the full connection state. Returns unsubscribe fn. */
  onStateChange(handler: (state: WPSConnectionState) => void): () => void;
  /** Register a handler for every connection after the page's first (WordSocket 0.32). Returns unsubscribe fn. */
  onReconnect(handler: () => void): () => void;
  /** The current connection state. */
  readonly state: WPSConnectionState;
  /** Subscribe to channels, bare or `site:{id}:` qualified; replayed on every reconnect. */
  subscribe(channels: string[]): void;
  unsubscribe(channels: string[]): void;
  /** Whether `channel` is `expected`, allowing the qualified `site:{id}:` spelling. */
  onChannel(channel: string, expected: string): boolean;
  /** This browser's stable id. */
  visitorId(): string;
  /** The read-only view of the site and page (WordSocket 0.31). */
  readonly config?: {
    readonly version: string;
    readonly isDebug: boolean;
    readonly siteName: string;
    readonly page: { readonly kind: string; readonly id: number };
  };
  /** Logs while WordSocket's debug mode is on (WordSocket 0.31). */
  debug?(...args: unknown[]): void;
}
