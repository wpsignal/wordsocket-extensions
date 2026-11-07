/**
 * Names and edit links for the products the board lists. Only the board knows
 * which carts are live, so it picks the ids and asks the server for the words,
 * once per page per id; a product that no longer exists is remembered as null
 * so it is never asked for again.
 */
import { __, sprintf } from "@wordpress/i18n";
import { useEffect, useMemo, useState } from "@wordpress/element";
import apiFetch from "@wordpress/api-fetch";

const DEBOUNCE_MS = 300;
const BATCH = 100;

type Config = Pick<ShopSocketBoardConfig, "productsUrl" | "nonce">;

/* One cache for every instance on the page: an id one table asked for is known to all of them. */
const cache = new Map<number, WooProductRef | null>();
const pending = new Set<number>();
const listeners = new Set<() => void>();

/**
 * The most products one board table asks names for.
 *
 * @since 0.8.0
 */
export const LIVE_PRODUCTS = 100;

/**
 * What a product cell says: the name, "Product #N (removed)" for a ref of null, "#N" while unknown.
 *
 * @since 0.8.0
 */
export function productLabel(
  ref: WooProductRef | null | undefined,
  id: number,
): string {
  if (ref) return ref.name;
  /* translators: %d: product id */
  return ref === null
    ? sprintf(__("Product #%d (removed)", "shopsocket"), id)
    : `#${id}`;
}

/**
 * A lookup from product id to its ref, null when removed, undefined while unknown.
 *
 * @since 0.1.1
 * @since 0.8.0 Every instance shares one cache and hears every answer.
 */
export function useProductNames(ids: number[], config: Config) {
  const [version, setVersion] = useState(0);
  const key = ids.join(",");

  useEffect(() => {
    const listen = () => setVersion((v) => v + 1);
    listeners.add(listen);
    return () => {
      listeners.delete(listen);
    };
  }, []);

  useEffect(() => {
    const missing = ids
      .filter((id) => !cache.has(id) && !pending.has(id))
      .slice(0, BATCH);
    if (missing.length === 0 || !config.productsUrl) return undefined;
    missing.forEach((id) => pending.add(id));
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
        missing.forEach((id) => cache.set(id, found.get(id) ?? null));
        listeners.forEach((listen) => listen());
      } catch {
        // The rows keep showing their ids; the next change of ids asks again.
      } finally {
        missing.forEach((id) => pending.delete(id));
      }
    }, DEBOUNCE_MS);
    return () => {
      clearTimeout(timer);
      if (!fired) missing.forEach((id) => pending.delete(id));
    };
    // The ids are keyed by their joined string so a new array with the same ids does not refetch; an answered batch asks for the next.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key, version, config.productsUrl, config.nonce]);

  // eslint-disable-next-line react-hooks/exhaustive-deps
  return useMemo(() => (id: number) => cache.get(id), [version]);
}
