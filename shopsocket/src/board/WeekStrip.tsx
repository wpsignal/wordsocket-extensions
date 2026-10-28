/*
 * The board's "This week" strip: carts left behind, recovered orders and checkout conversion against
 * last week, where leavers go, and the products most often left in a cart.
 */
import { __, _n, sprintf } from "@wordpress/i18n";
import { useMemo } from "@wordpress/element";
import { Link, Text } from "@wordpress/ui";
import { money } from "./format";
import { useProductNames } from "./useProductNames";
import { BoardTable, Section, Stat, productColumn, type Column } from "./ui";

type Config = Pick<
  ShopSocketBoardConfig,
  "productsUrl" | "nonce" | "journeysUrl" | "adminUrl"
>;

/** A row's id as its table key. */
const byId = (row: { id: number }) => String(row.id);

/** "+3 vs last week", "same as last week"; `unit` follows the number. */
function delta(now: number, before: number, unit = ""): string {
  const d = now - before;
  if (d === 0) return __("same as last week", "shopsocket");
  /* translators: %s: a signed difference, such as +3 or -2 */
  return sprintf(
    __("%s vs last week", "shopsocket"),
    `${d > 0 ? "+" : "−"}${Math.abs(d)}${unit}`,
  );
}

/** Milliseconds as "48 s" or "2 min 10 s". */
function duration(ms: number): string {
  const total = Math.round(ms / 1000);
  const minutes = Math.floor(total / 60);
  const seconds = total % 60;
  /* translators: %d: seconds */
  if (minutes === 0) return sprintf(__("%d s", "shopsocket"), seconds);
  /* translators: 1: minutes, 2: seconds */
  return sprintf(__("%1$d min %2$d s", "shopsocket"), minutes, seconds);
}

/** The page kind a journey step names, as a place in a sentence. */
function placeOf(kind: string): string {
  const places: Record<string, string> = {
    product: __("a product page", "shopsocket"),
    category: __("a category page", "shopsocket"),
    cart: __("the cart", "shopsocket"),
    checkout: __("checkout", "shopsocket"),
    home: __("the home page", "shopsocket"),
    shop: __("the shop", "shopsocket"),
    search: __("a search", "shopsocket"),
    thanks: __("the order page", "shopsocket"),
  };
  return places[kind] ?? __("a page", "shopsocket");
}

/** WordSocket's Journeys tab with the network on this week's leavers: outcome left, through the leak's page, least 2 per edge. */
function leaversUrl(base: string, kind: string): string {
  const url = new URL(base, window.location.origin);
  url.searchParams.set("outcome", "left");
  url.searchParams.set("through", kind);
  url.searchParams.set("min", "2");
  return url.toString();
}

/**
 * The "This week" card, or one line pointing at WordSocket's Journeys tab while its History is off; the
 * leak sentence links into that tab.
 *
 * @since 0.8.0
 */
export function WeekStrip({
  strip,
  config,
}: {
  strip: WooWeekStrip | null | undefined;
  config: Config;
}) {
  const ids = useMemo(() => (strip?.products ?? []).map((p) => p.id), [strip]);
  const product = useProductNames(ids, config);
  const columns = useMemo<Column<WooWeekStrip["products"][number]>[]>(
    () => [
      productColumn(product, config.adminUrl),
      {
        id: "left",
        label: __("Left in carts", "shopsocket"),
        value: (row) => row.left,
        numeric: true,
      },
      {
        id: "bought",
        label: __("Bought", "shopsocket"),
        value: (row) => row.bought,
        numeric: true,
      },
    ],
    [product, config.adminUrl],
  );
  const historyOff = (
    <Text variant="body-sm" className="shopsocket-muted" render={<p />}>
      {__(
        "Turn on History in WordSocket to see how this week compares with last week.",
        "shopsocket",
      )}{" "}
      <Link href={config.journeysUrl}>{__("Open Journeys", "shopsocket")}</Link>
    </Text>
  );
  if (!strip) {
    return (
      <Section title={__("This week", "shopsocket")}>{historyOff}</Section>
    );
  }
  const { left, recovered, conversion, leak, products } = strip;
  /* translators: %d: carts on one day */
  const carts = (v: number) =>
    sprintf(_n("%d cart", "%d carts", v, "shopsocket"), v);
  /* translators: %d: orders on one day */
  const orders = (v: number) =>
    sprintf(_n("%d order", "%d orders", v, "shopsocket"), v);
  return (
    <Section
      title={__("This week", "shopsocket")}
      lede={__(
        "Carts, recovered sales and checkouts over the last seven days, compared with the week before.",
        "shopsocket",
      )}
    >
      <div className="shopsocket-stats">
        <Stat
          name="left"
          value={String(left.count)}
          label={__("Carts left behind", "shopsocket")}
          compare={{ now: left.count, before: left.previous, goodWhen: "down" }}
          days={left.days}
          dates={strip.trend_days}
          dayLabel={carts}
          detail={sprintf(
            /* translators: 1: the change against last week, 2: the value of those carts */
            __("%1$s, %2$s in them", "shopsocket"),
            delta(left.count, left.previous),
            money(left.value, left.currency),
          )}
        />
        <Stat
          name="recovered"
          value={String(recovered.count)}
          label={__("Recovered orders", "shopsocket")}
          compare={{
            now: recovered.count,
            before: recovered.previous,
            goodWhen: "up",
          }}
          days={recovered.days}
          dates={strip.trend_days}
          dayLabel={orders}
          detail={sprintf(
            /* translators: 1: the value of the recovered orders, 2: the change against last week */
            __("%1$s in orders after a moment, %2$s", "shopsocket"),
            money(recovered.value, left.currency),
            delta(recovered.count, recovered.previous),
          )}
        />
        <Stat
          name="conversion"
          value={`${conversion.percent}%`}
          label={__("Checkout conversion", "shopsocket")}
          compare={{
            now: conversion.percent,
            before: conversion.previous_percent,
            goodWhen: "up",
          }}
          days={conversion.days}
          dates={strip.trend_days}
          dayLabel={(v) => `${v}%`}
          detail={sprintf(
            /* translators: 1: orders, 2: shoppers who reached checkout, 3: the change against last week in points */
            __("%1$d of %2$d who reached checkout, %3$s", "shopsocket"),
            conversion.orders,
            conversion.reached,
            delta(
              conversion.percent,
              conversion.previous_percent,
              __(" pts", "shopsocket"),
            ),
          )}
        />
      </div>
      {strip.journeys === false && historyOff}
      {leak && (
        <Text variant="body-md" render={<p />} className="shopsocket-leak">
          {sprintf(
            /* translators: 1: where most leavers left from, 2: the typical time there, 3: how many, 4: their share of everyone who left */
            __(
              "Most shoppers who leave with a cart do so from %1$s, usually after %2$s there: %3$d this week, %4$d%% of everyone who left.",
              "shopsocket",
            ),
            placeOf(leak.kind),
            duration(leak.median_ms),
            leak.count,
            Math.round((100 * leak.count) / Math.max(1, leak.leavers)),
          )}{" "}
          {config.journeysUrl && (
            <Link href={leaversUrl(config.journeysUrl, leak.kind)}>
              {__("See their journeys", "shopsocket")}
            </Link>
          )}
        </Text>
      )}
      <Text variant="heading-md" render={<h3 />} className="shopsocket-subhead">
        {__("Products most often left in a cart", "shopsocket")}
      </Text>
      <BoardTable
        className="shopsocket-left-products"
        rows={products}
        rowId={byId}
        empty={__("No cart was left behind this week.", "shopsocket")}
        columns={columns}
      />
    </Section>
  );
}
