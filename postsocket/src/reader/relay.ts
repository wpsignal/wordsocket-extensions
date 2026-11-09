/**
 * The relay seam for the reader modules: wait for WordSocket's client, subscribe, read events by channel.
 *
 * @since 0.1.0
 */

const WPS_WAIT_MS = 15000;

/**
 * Resolve with `window.WPS` once WordSocket's client exists, or null after the wait.
 *
 * @since 0.1.0
 */
export function whenWPS(): Promise<WPSApi | null> {
  if (window.WPS) return Promise.resolve(window.WPS);
  return new Promise((resolve) => {
    const started = Date.now();
    const poll = (): void => {
      if (window.WPS) {
        resolve(window.WPS);
      } else if (Date.now() - started > WPS_WAIT_MS) {
        resolve(null);
      } else {
        setTimeout(poll, 100);
      }
    };
    document.addEventListener("DOMContentLoaded", poll, { once: true });
    poll();
  });
}

/**
 * Subscribe to a channel once the client is there. Resolves with the client, or null when it never came.
 *
 * @since 0.1.0
 */
export async function subscribe(channel: string): Promise<WPSApi | null> {
  const wps = await whenWPS();
  wps?.subscribe([channel]);
  return wps;
}

/**
 * The `data` of a `wpsignal:<event>` DOM event when it arrived on `expected` (any spelling), else null.
 *
 * @since 0.1.0
 */
export function relayed<T>(e: Event, expected: string): T | null {
  const detail = (e as CustomEvent<{ data?: T; channel?: string }>).detail;
  const channel = detail?.channel ?? "";
  if (!window.WPS?.onChannel(channel, expected)) return null;
  return (detail?.data ?? {}) as T;
}

/**
 * Listen for a relay event on one channel. Returns the unsubscribe function.
 *
 * @since 0.1.0
 */
export function onRelay<T>(event: string, channel: string, handler: (data: T) => void): () => void {
  const listener = (e: Event): void => {
    const data = relayed<T>(e, channel);
    if (data) handler(data);
  };
  document.addEventListener(`wpsignal:${event}`, listener);
  return () => document.removeEventListener(`wpsignal:${event}`, listener);
}

/**
 * A random delay of up to `maxMs`, so a crowd of readers spreads its fetches.
 *
 * @since 0.1.0
 */
export function jitter(maxMs: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, Math.floor(Math.random() * maxMs)));
}

/**
 * Log through WordSocket's debug channel when it is on.
 *
 * @since 0.1.0
 */
export function debug(...args: unknown[]): void {
  window.WPS?.debug?.("[PostSocket]", ...args);
}
