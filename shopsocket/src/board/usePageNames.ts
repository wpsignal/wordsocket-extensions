/**
 * Titles and URLs for the pages the shoppers table names, resolved from the
 * open step of each shopper's journey. The board asks the server once per
 * page per step; a step that names nothing public is remembered as null so it
 * is never asked for again. The kinds that need no lookup are labelled here.
 */
import { __, sprintf } from "@wordpress/i18n";
import { useEffect, useMemo, useRef, useState } from "@wordpress/element";
import apiFetch from "@wordpress/api-fetch";
import { productLabel } from "./useProductNames";

const DEBOUNCE_MS = 300;
const BATCH = 100;

type Config = Pick<
  ShopSocketBoardConfig,
  "pagesUrl" | "homeUrl" | "shopUrl" | "cartUrl" | "checkoutUrl" | "nonce"
>;

/** The kinds the board labels and links by itself, without a lookup. */
const KNOWN: Record<string, { label: () => string; url?: keyof Config }> = {
  home: { label: () => __("Home", "shopsocket"), url: "homeUrl" },
  shop: { label: () => __("Shop", "shopsocket"), url: "shopUrl" },
  cart: { label: () => __("Cart", "shopsocket"), url: "cartUrl" },
  checkout: { label: () => __("Checkout", "shopsocket"), url: "checkoutUrl" },
  thanks: { label: () => __("Order received", "shopsocket") },
  search: { label: () => __("Search", "shopsocket") },
  archive: { label: () => __("Archive", "shopsocket") },
  other: { label: () => __("Another page", "shopsocket") },
  admin: { label: () => __("Admin", "shopsocket") },
  away: { label: () => __("Away", "shopsocket") },
};

/**
 * Whether a step kind is resolved through the pages route: a post type, a
 * taxonomy or a post type archive. Products are named through the product
 * names the board already holds.
 *
 * @since 0.8.0
 */
export function needsLookup(kind: string): boolean {
  return !(kind in KNOWN) && kind !== "product";
}

/** A kind as words: underscores as spaces, the first letter capital. */
function kindWords(kind: string): string {
  const words = kind.replace(/_/g, " ");
  return words.charAt(0).toUpperCase() + words.slice(1);
}

/**
 * What a shopper's page cell says: the known kinds' own words, a resolved
 * page's title, a product's name, and the kind with its id while a lookup is
 * pending or answered nothing.
 *
 * @since 0.8.0
 */
export function pageLabel(
  kind: string,
  id: number,
  page: WooPageRef | null | undefined,
  product: WooProductRef | null | undefined,
): string {
  if (kind in KNOWN) return KNOWN[kind].label();
  if (kind === "product") return productLabel(product, id);
  if (page) return page.title;
  if (kind.endsWith("_archive")) return kindWords(kind);
  /* translators: 1: the kind of page, 2: its id */
  return sprintf(__("%1$s #%2$d", "shopsocket"), kindWords(kind), id);
}

/**
 * Where a shopper's page cell links: the config's URL for a known kind, the
 * product's page, or the resolved page's URL; null when there is none.
 *
 * @since 0.8.0
 */
export function pageUrl(
  kind: string,
  page: WooPageRef | null | undefined,
  product: WooProductRef | null | undefined,
  config: Config,
): string | null {
  const known = KNOWN[kind];
  if (known) return known.url ? config[known.url] || null : null;
  if (kind === "product") return product?.url || null;
  return page?.url || null;
}

/**
 * A lookup from `kind:id` to its page ref, null when it names nothing public,
 * undefined while unknown.
 *
 * @since 0.8.0
 */
export function usePageNames(steps: string[], config: Config) {
  const cache = useRef(new Map<string, WooPageRef | null>());
  const pending = useRef(new Set<string>());
  const mounted = useRef(true);
  const [version, setVersion] = useState(0);
  const key = steps.join(",");

  useEffect(
    () => () => {
      mounted.current = false;
    },
    [],
  );

  useEffect(() => {
    const missing = steps
      .filter((s) => !cache.current.has(s) && !pending.current.has(s))
      .slice(0, BATCH);
    if (missing.length === 0 || !config.pagesUrl) return undefined;
    missing.forEach((s) => pending.current.add(s));
    let fired = false;
    const timer = setTimeout(async () => {
      fired = true;
      const url = new URL(config.pagesUrl, window.location.origin);
      url.searchParams.set("steps", missing.join(","));
      try {
        const res = await apiFetch<{ pages?: WooPageRef[] }>({
          url: url.toString(),
          headers: { "X-WP-Nonce": config.nonce },
        });
        const found = new Map((res.pages ?? []).map((p) => [p.step, p]));
        missing.forEach((s) => cache.current.set(s, found.get(s) ?? null));
        if (mounted.current) setVersion((v) => v + 1);
      } catch {
        // The cells keep their kind and id; the next change of steps asks again.
      } finally {
        missing.forEach((s) => pending.current.delete(s));
      }
    }, DEBOUNCE_MS);
    return () => {
      clearTimeout(timer);
      if (!fired) missing.forEach((s) => pending.current.delete(s));
    };
    // The steps are keyed by their joined string so a new array with the same steps does not refetch.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key, config.pagesUrl, config.nonce]);

  // eslint-disable-next-line react-hooks/exhaustive-deps
  return useMemo(() => (step: string) => cache.current.get(step), [version]);
}
