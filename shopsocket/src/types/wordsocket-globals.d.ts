/**
 * The part of WordSocket's client API (`window.WPS`, wp-plugin/src/types,
 * WordSocket 0.31) this plugin uses. Update when the contract changes.
 */

type WPSEventHandler = (data: Record<string, unknown>, channel: string) => void;
type WPSTransportName = "ws" | "sse";

/**
 * Error codes shared with the Yjs provider. They mirror @wordpress/sync's
 * ConnectionErrorCode so the editor can pick its copy without translation.
 */
type WPSConnectionErrorCode =
  | "authentication-failed"
  | "connection-expired"
  | "connection-limit-exceeded"
  | "document-size-limit-exceeded"
  | "protocol-mismatch"
  | "unknown-error";

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
  on(event: string, handler: WPSEventHandler): () => void;
  /** Register a handler for the full connection state. Returns unsubscribe fn. */
  onStateChange(handler: (state: WPSConnectionState) => void): () => void;
  /** The current connection state, as delivered to onStateChange. */
  readonly state: WPSConnectionState;
  /**
   * Connection-scoped presence: join or update membership on a channel with an
   * opaque state object, or leave it with `null`. The relay drops membership
   * when the socket closes and the client re-sends on reconnect.
   */
  setPresence(channel: string, state: Record<string, unknown> | null): void;
  /** A version-4 UUID that also works on plain HTTP pages. */
  uuid(): string;
  /** This browser's stable id, shared by every tab and extension on the site. */
  visitorId(): string;
  /** Whether `channel` is `expected`, allowing the qualified `site:{id}:` spelling. */
  onChannel(channel: string, expected: string): boolean;
  /** Visitor tracking over presence (WordSocket 0.30); absent on older versions. */
  readonly presence?: WPSPresenceApi;
  /** Where the visitor's attention is (WordSocket 0.31); absent on older versions. */
  readonly attention?: WPSAttentionApi;
  /** The prompt surfaces (WordSocket 0.31); absent on older versions. */
  readonly recovery?: WPSRecoveryApi;
  /** The rules engine and registration API (WordSocket 0.31); absent on older versions. */
  readonly rules?: WPSRulesApi;
}

type WPSAttentionState = "page" | "window" | "hidden";
interface WPSAttentionApi {
  readonly state: WPSAttentionState;
  /** When the tab became hidden (ms since the epoch), null while it is visible. */
  readonly hiddenAt: number | null;
  on(
    handler: (state: WPSAttentionState, previous: WPSAttentionState) => void,
  ): () => void;
}
interface WPSPromptContent {
  text?: string;
  node?: Node;
  pattern?: number;
}
type WPSPresentationMount = (
  root: HTMLElement,
  close: () => void,
) => { open(node: Node): void; close(): void };
interface WPSShowOptions {
  rule?: string;
  context?: Record<string, unknown>;
  pattern?: Node | null;
}
interface WPSRecoveryApi {
  show(
    content: WPSPromptContent,
    presentation: string,
    options?: WPSShowOptions,
  ): boolean;
  presentation(key: string, mount: WPSPresentationMount): void;
  flashTitle(text: string): { stop(): void };
  badge(): Promise<boolean>;
  clearBadge(): void;
  sound(): boolean;
  onReturn(handler: () => void): () => void;
  onClose(handler: () => void): () => void;
  /** Called each time a prompt opens, with the moment (`id:rev`) that showed it, if any. */
  onShow(handler: (rule?: string) => void): () => void;
  readonly open: boolean;
  readonly shownBy: string[];
  close(rule?: string): void;
}
interface WPSRuleCondition {
  condition: string;
  op: string;
  value?: unknown;
}
interface WPSRuleGroup {
  all?: Array<WPSRuleCondition | WPSRuleGroup>;
  any?: Array<WPSRuleCondition | WPSRuleGroup>;
}
interface WPSRuleAction {
  action: string;
  [param: string]: unknown;
}
interface WPSRule {
  id: string;
  name: string;
  enabled: boolean;
  frequency: "always" | "page" | "visit" | "visitor";
  rev: number;
  attention?: { enabled: boolean; title: string };
  source?: string;
  conditions: WPSRuleGroup;
  actions: WPSRuleAction[];
}
type WPSRuleContext = Record<string, unknown> & {
  page: {
    kind: string;
    id: number;
    title: string;
    dwell: number;
    hidden: number;
    taxonomy: boolean;
  };
  journey: {
    current: string;
    pagesViewed: number;
    visitTime: number;
    returned: number;
    kinds: Record<string, number>;
  };
  attention: { state: WPSAttentionState };
  clock: { hour: number; weekday: number; minute: number; siteHour: number };
  visitor: {
    id: string;
    isNew: boolean;
    loggedIn: boolean;
    device: "desktop" | "tablet" | "mobile";
  };
  site: { name: string };
};
interface WPSFiring {
  rule: string;
  action: string;
  step: number;
  data?: Record<string, unknown>;
}
interface WPSHistoryJourney {
  journey: string;
  started_at?: string;
  outcome?: string;
  value?: number;
  context?: Record<string, unknown>;
}
interface WPSHistoryProvider {
  load(options: {
    days?: number;
    limit?: number;
  }): Promise<WPSHistoryJourney[]>;
  outcomes?: boolean;
}
interface WPSRulesApi {
  condition(
    key: string,
    test: (ctx: WPSRuleContext, condition: WPSRuleCondition) => boolean,
  ): void;
  context(key: string, read: () => unknown): () => void;
  action(
    key: string,
    run: (
      ctx: WPSRuleContext,
      params: Record<string, unknown>,
      recovery: WPSRecoveryApi,
      rule: WPSRule,
    ) => void | Promise<void>,
  ): void;
  history(provider: WPSHistoryProvider): void;
  onFire(handler: (firing: WPSFiring, ctx: WPSRuleContext) => void): () => void;
  replay(
    rule: WPSRule,
    journeys: WPSHistoryJourney[],
  ): {
    count: number;
    fired: Array<{ index: number; step: number }>;
    unanswerable: string[];
  };
  readonly current: { version: number; rules: WPSRule[] };
  readonly historyProvider: WPSHistoryProvider | null;
}

/** Options for `WPS.presence.track()`. */
interface WPSTrackOptions {
  /** The plugin's own fields, carried in presence state and in every report (this plugin's cart id `b`). */
  state?: Record<string, unknown>;
  /** Whether to report visits and leaves to WordPress. Default true. */
  report?: boolean;
}

/** What `WPS.presence.track()` returns. */
interface WPSPresenceTracker {
  readonly visitor: string;
  journeyId(): string;
  journey(): string;
  /** Replace the plugin's fields; presence is announced again at once. */
  update(state: Record<string, unknown>): void;
  onChange(handler: (journey: string, journeyId: string) => void): () => void;
  stop(): void;
}

interface WPSPresenceApi {
  /** Track this visitor on a channel: presence as `{ v, n, j, ...state }`, visits and leaves reported to WordPress. */
  track(channel: string, options?: WPSTrackOptions): WPSPresenceTracker;
}
