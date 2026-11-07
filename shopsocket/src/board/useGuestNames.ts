/*
 * The best guesses at who the guests on the board are, asked of the server by visitor id, once per
 * page per visitor; a visitor without a guess is asked about again after a minute, since their
 * browser may offer one after the board first saw them.
 */
import { useEffect, useMemo, useRef, useState } from "@wordpress/element";
import apiFetch from "@wordpress/api-fetch";

const DEBOUNCE_MS = 300;
const BATCH = 100;
/** How long "no guess" is believed before the visitor is asked about again. */
const MISS_TTL_MS = 60_000;

type Config = Pick<ShopSocketBoardConfig, "guestsUrl" | "nonce">;

/**
 * A lookup from visitor id to the guess, null when there is none, undefined while unknown.
 *
 * @since 0.8.0
 */
export function useGuestNames(visitors: string[], config: Config) {
  const cache = useRef(new Map<string, { ref: WooGuestRef | null; at: number }>());
  const pending = useRef(new Set<string>());
  const mounted = useRef(true);
  const [version, setVersion] = useState(0);
  const key = visitors.join(",");

  useEffect(
    () => () => {
      mounted.current = false;
    },
    [],
  );

  useEffect(() => {
    const now = Date.now();
    const missing = visitors
      .filter((v) => {
        const held = cache.current.get(v);
        return (
          !pending.current.has(v) &&
          (!held || (held.ref === null && now - held.at > MISS_TTL_MS))
        );
      })
      .slice(0, BATCH);
    if (missing.length === 0 || !config.guestsUrl) return undefined;
    missing.forEach((v) => pending.current.add(v));
    let fired = false;
    const timer = setTimeout(async () => {
      fired = true;
      const url = new URL(config.guestsUrl, window.location.origin);
      url.searchParams.set("v", missing.join(","));
      try {
        const res = await apiFetch<{ guests?: WooGuestRef[] }>({
          url: url.toString(),
          headers: { "X-WP-Nonce": config.nonce },
        });
        const found = new Map((res.guests ?? []).map((g) => [g.v, g]));
        const at = Date.now();
        missing.forEach((v) =>
          cache.current.set(v, { ref: found.get(v) ?? null, at }),
        );
        if (mounted.current) setVersion((n) => n + 1);
      } catch {
        // The rows keep reading Guest; the next change of visitors asks again.
      } finally {
        missing.forEach((v) => pending.current.delete(v));
      }
    }, DEBOUNCE_MS);
    return () => {
      clearTimeout(timer);
      if (!fired) missing.forEach((v) => pending.current.delete(v));
    };
    // The ids are keyed by their joined string so a new array with the same ids does not refetch.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key, config.guestsUrl, config.nonce]);

  // eslint-disable-next-line react-hooks/exhaustive-deps
  return useMemo(() => (v: string) => cache.current.get(v)?.ref, [version]);
}
