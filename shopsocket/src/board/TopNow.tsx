/*
 * The board's top lists, beside the shoppers' figures in "Right now":
 * - the pages most shoppers are on, counted once per shopper whatever their tabs;
 * - the products in the most carts of the shoppers on the site.
 */
import { __ } from "@wordpress/i18n";
import { useMemo } from "@wordpress/element";
import { Link, Text } from "@wordpress/ui";
import { adminUrlOrNull, siteUrlOrNull } from "./trust";
import { productLabel, useProductNames } from "./useProductNames";
import type { ShopperNames } from "./Dashboard";

/** The most rows a top list shows. */
const TOP = 5;

/** One row of a top list: what it names, where its link goes, and its count. */
interface TopRow {
  key: string;
  label: string;
  href: string | null;
  count: number;
}

/** The rows with the highest counts, ties in key order so a list holds still. */
function top<T extends { key: string; count: number }>(rows: T[]): T[] {
  return rows
    .sort((a, b) => b.count - a.count || a.key.localeCompare(b.key))
    .slice(0, TOP);
}

/** A titled list of rows, each drawn over a bar as wide as its count among the others. */
function TopList({
  name,
  title,
  unit,
  empty,
  rows,
  openInNewTab,
}: {
  name: string;
  title: string;
  unit: string;
  empty: string;
  rows: TopRow[];
  openInNewTab?: boolean;
}) {
  const most = Math.max(1, ...rows.map((row) => row.count));
  return (
    <div className={`shopsocket-top is-${name}`}>
      <div className="shopsocket-top__head">
        <Text variant="body-md" render={<h3 />}>
          {title}
        </Text>
        <Text variant="body-sm" className="shopsocket-muted">
          {unit}
        </Text>
      </div>
      {rows.length === 0 ? (
        <Text variant="body-sm" className="shopsocket-muted" render={<p />}>
          {empty}
        </Text>
      ) : (
        <ol className="shopsocket-top__list">
          {rows.map((row) => (
            <li
              key={row.key}
              data-key={row.key}
              style={{ "--shopsocket-share": row.count / most } as never}
            >
              <span className="shopsocket-top__name">
                {row.href ? (
                  <Link href={row.href} openInNewTab={openInNewTab}>
                    {row.label}
                  </Link>
                ) : (
                  row.label
                )}
              </span>
              <span className="shopsocket-top__count">{row.count}</span>
            </li>
          ))}
        </ol>
      )}
    </div>
  );
}

/**
 * The pages most shoppers are on and the products in the most of their carts, right now.
 *
 * @since 0.8.0
 */
export function TopNow({
  shoppers,
  names,
  config,
}: {
  shoppers: WooShopper[];
  names: ShopperNames;
  config: ShopSocketBoardConfig;
}) {
  const pageCounts = useMemo(() => {
    const counts = new Map<
      string,
      { key: string; kind: string; id: number; count: number }
    >();
    for (const shopper of shoppers) {
      const steps = new Map(
        shopper.tabs.map((tab) => [`${tab.kind}:${tab.id}`, tab]),
      );
      steps.forEach((tab, key) => {
        const row = counts.get(key) ?? {
          key,
          kind: tab.kind,
          id: tab.id,
          count: 0,
        };
        row.count += 1;
        counts.set(key, row);
      });
    }
    return top([...counts.values()]);
  }, [shoppers]);
  const pages = useMemo<TopRow[]>(
    () =>
      pageCounts.map(({ key, kind, id, count }) => {
        const url = names.url(kind, id);
        return {
          key,
          label: names.label(kind, id),
          href: url ? siteUrlOrNull(url, config.homeUrl) : null,
          count,
        };
      }),
    [pageCounts, names, config.homeUrl],
  );

  const productCounts = useMemo(() => {
    const counts = new Map<number, number>();
    const carts = new Set<string>();
    for (const { cart } of shoppers) {
      if (!cart || carts.has(cart.id)) continue;
      carts.add(cart.id);
      cart.products.forEach((id) => counts.set(id, (counts.get(id) ?? 0) + 1));
    }
    return top(
      [...counts.entries()].map(([id, count]) => ({
        key: String(id),
        id,
        count,
      })),
    );
  }, [shoppers]);
  const ids = useMemo(
    () => productCounts.map((row) => row.id),
    [productCounts],
  );
  const product = useProductNames(ids, config);
  const products = useMemo<TopRow[]>(
    () =>
      productCounts.map(({ key, id, count }) => {
        const ref = product(id);
        return {
          key,
          label: productLabel(ref, id),
          href: ref ? adminUrlOrNull(ref.edit_url, config.adminUrl) : null,
          count,
        };
      }),
    [productCounts, product, config.adminUrl],
  );

  return (
    <div className="shopsocket-group is-top" aria-live="off">
      <TopList
        name="pages"
        title={__("Top pages", "shopsocket")}
        unit={__("Shoppers", "shopsocket")}
        empty={__("No shopper is on the site right now.", "shopsocket")}
        rows={pages}
        openInNewTab
      />
      <TopList
        name="products"
        title={__("Top products in carts", "shopsocket")}
        unit={__("Carts", "shopsocket")}
        empty={__("No shopper on the site holds a cart.", "shopsocket")}
        rows={products}
      />
    </div>
  );
}
