/**
 * Ambient declarations for `@wordpress/interactivity`: WordPress resolves the bare import through the script-module import map, so nothing is bundled.
 *
 * @since 0.1.0
 */
declare module "@wordpress/interactivity" {
  /** A store part: any subset, functions whole. */
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  type StorePart<T> = { [K in keyof T]?: T[K] extends (...args: any[]) => any ? T[K] : T[K] extends object ? StorePart<T[K]> : T[K] };
  /** The per-element context object (from `data-wp-context`). */
  export function getContext<T = Record<string, unknown>>(): T;
  /** The element the current directive runs on. */
  export function getElement(): { ref: HTMLElement | null };
  /** Register or read a store; the state the server seeded is deep-merged into `T`. */
  export function store<T extends object>(namespace: string, storePart?: StorePart<T>, options?: { lock: boolean | string }): T;
  /** WordPress's private hydration helpers, unlocked by a consent string; absent or changed in other versions. */
  export const privateApis: ((lock: string) => unknown) | undefined;
}
