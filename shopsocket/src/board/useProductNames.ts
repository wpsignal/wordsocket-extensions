/**
 * Names and edit links for the products the board lists. Only the board knows
 * which baskets are live, so it picks the ids and asks the server for the words,
 * once per page per id; a product that no longer exists is remembered as null
 * so it is never asked for again.
 */
import { useEffect, useMemo, useRef, useState } from "@wordpress/element";
import apiFetch from "@wordpress/api-fetch";

const DEBOUNCE_MS = 300;
const BATCH = 100;

type Config = Pick<ShopSocketBoardConfig, "productsUrl" | "nonce">;

/** A lookup from product id to its ref, null when removed, undefined while unknown. */
export function useProductNames(ids: number[], config: Config) {
  const cache = useRef(new Map<number, WooProductRef | null>());
  const pending = useRef(new Set<number>());
  const mounted = useRef(true);
  const [version, setVersion] = useState(0);
  const key = ids.join(",");

  useEffect(() => () => {
    mounted.current = false;
  }, []);

  useEffect(() => {
    const missing = ids.filter((id) => !cache.current.has(id) && !pending.current.has(id)).slice(0, BATCH);
    if (missing.length === 0 || !config.productsUrl) return undefined;
    missing.forEach((id) => pending.current.add(id));
    let fired = false;
    const timer = setTimeout(async () => {
      fired = true;
      const url = new URL(config.productsUrl, window.location.origin);
      url.searchParams.set("ids", missing.join(","));
      try {
        const res = await apiFetch<{ products?: WooProductRef[] }>({
          url: url.toString(),
          headers: { "X-WP-Nonce": config.nonce },
        });
        const found = new Map((res.products ?? []).map((p) => [p.id, p]));
        missing.forEach((id) => cache.current.set(id, found.get(id) ?? null));
        if (mounted.current) setVersion((v) => v + 1);
      } catch {
        // The rows keep showing their ids; the next change of ids asks again.
      } finally {
        missing.forEach((id) => pending.current.delete(id));
      }
    }, DEBOUNCE_MS);
    return () => {
      clearTimeout(timer);
      if (!fired) missing.forEach((id) => pending.current.delete(id));
    };
    // The ids are keyed by their joined string so a new array with the same ids does not refetch.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key, config.productsUrl, config.nonce]);

  // eslint-disable-next-line react-hooks/exhaustive-deps
  return useMemo(() => (id: number) => cache.current.get(id), [version]);
}
