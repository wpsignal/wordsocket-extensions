/*
 * The board's "Abandoned carts" section: everything about carts whose shopper left without ordering.
 * - a figure for each time frame: every cart left in it and not ordered since, with their products and value;
 * - the chosen time frame, a click on its figure, is the one the rest is of, under a progress bar while it is fetched;
 * - four tabs, each under a line saying what it lists: the carts left, those an order recovered, the products left most often and the pages most left from;
 * - where most leavers left from;
 */
import { __, _n, sprintf } from "@wordpress/i18n";
import { useMemo } from "@wordpress/element";
import { Link, Progress, Radio, RadioGroup, Tabs, Text } from "@wordpress/ui";
import { money } from "./format";
import { adminUrlOrNull, siteUrlOrNull } from "./trust";
import { useGuestNames } from "./useGuestNames";
import {
  kindLabel,
  needsLookup,
  pageLabel,
  pageUrl,
  usePageNames,
} from "./usePageNames";
import {
  LIVE_PRODUCTS,
  productLabel,
  useProductNames,
} from "./useProductNames";
import {
  BoardTable,
  Section,
  Stat,
  productColumn,
  shopperColumn,
  type Column,
} from "./ui";

/** A cart's id as its table key. */
const byCart = (row: { id: string }) => row.id;

/** A product's id as its table key. */
const byProduct = (row: { id: number }) => String(row.id);

/** Seconds as "48 s" or "2 min 10 s". */
function duration(s: number): string {
  const total = Math.round(s);
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

/** WordSocket's Journeys tab with the network on the leavers: outcome left, through the leak's page, least 2 per edge. */
function leaversUrl(base: string, kind: string): string {
  const url = new URL(base, window.location.origin);
  url.searchParams.set("outcome", "left");
  url.searchParams.set("through", kind);
  url.searchParams.set("min", "2");
  return url.toString();
}

/** The hours from which a time frame is named in days. */
const DAYS_FROM = 72;

/** A time frame as its figure names it: "Last 24 hours", "Last 7 days". */
function frameLabel(hours: number): string {
  if (hours < DAYS_FROM)
    /* translators: %d: hours */
    return sprintf(
      _n("Last %d hour", "Last %d hours", hours, "shopsocket"),
      hours,
    );
  const days = Math.round(hours / 24);
  /* translators: %d: days */
  return sprintf(_n("Last %d day", "Last %d days", days, "shopsocket"), days);
}

/** The same time frame inside a sentence: "the last 24 hours", "the last 7 days". */
function framePhrase(hours: number): string {
  if (hours < DAYS_FROM)
    /* translators: %d: hours */
    return sprintf(
      _n("the last %d hour", "the last %d hours", hours, "shopsocket"),
      hours,
    );
  const days = Math.round(hours / 24);
  /* translators: %d: days */
  return sprintf(
    _n("the last %d day", "the last %d days", days, "shopsocket"),
    days,
  );
}

/** A moment in the browser's locale, with the date only when it is not today. */
function timeOf(iso: string): string {
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return iso;
  const today = new Date().toDateString() === date.toDateString();
  return new Intl.DateTimeFormat(
    undefined,
    today
      ? { timeStyle: "short" }
      : { dateStyle: "medium", timeStyle: "short" },
  ).format(date);
}

/** A line above a tab's table saying what it lists; `name` marks it as `is-<name>`. */
function Caption({ name, children }: { name: string; children: string }) {
  return (
    <Text
      variant="body-sm"
      className={`shopsocket-muted shopsocket-caption is-${name}`}
      render={<p />}
    >
      {children}
    </Text>
  );
}

/** What the carts left table lists: every cart counted, or the newest of them when more were counted than listed. */
function leftCaption(listed: number, total: number, hours: number): string {
  if (total > listed)
    return sprintf(
      /* translators: 1: carts listed, 2: carts counted, 3: a time frame, such as "the last 24 hours" */
      __("The newest %1$d of %2$d carts left in %3$s.", "shopsocket"),
      listed,
      total,
      framePhrase(hours),
    );
  return sprintf(
    /* translators: 1: carts, 2: a time frame, such as "the last 24 hours" */
    _n(
      "%1$d cart left in %2$s.",
      "%1$d carts left in %2$s.",
      total,
      "shopsocket",
    ),
    total,
    framePhrase(hours),
  );
}

/** What the recovered orders table lists, the same way. */
function recoveredCaption(
  listed: number,
  total: number,
  hours: number,
): string {
  if (total > listed)
    return sprintf(
      /* translators: 1: carts listed, 2: carts counted, 3: a time frame, such as "the last 24 hours" */
      __(
        "The newest %1$d of %2$d carts left in %3$s that became orders.",
        "shopsocket",
      ),
      listed,
      total,
      framePhrase(hours),
    );
  return sprintf(
    /* translators: 1: carts, 2: a time frame, such as "the last 24 hours" */
    _n(
      "%1$d cart left in %2$s became an order.",
      "%1$d carts left in %2$s became orders.",
      total,
      "shopsocket",
    ),
    total,
    framePhrase(hours),
  );
}

/** What the products table lists, the same way: every product in a cart left, or the most often left of them. */
function productsCaption(listed: number, total: number, hours: number): string {
  if (total > listed)
    return sprintf(
      /* translators: 1: products listed, 2: products counted, 3: a time frame, such as "the last 24 hours" */
      __(
        "The top %1$d of %2$d products in the carts left in %3$s.",
        "shopsocket",
      ),
      listed,
      total,
      framePhrase(hours),
    );
  return sprintf(
    /* translators: 1: products, 2: a time frame, such as "the last 24 hours" */
    _n(
      "%1$d product in the carts left in %2$s.",
      "%1$d products in the carts left in %2$s.",
      total,
      "shopsocket",
    ),
    total,
    framePhrase(hours),
  );
}

/** What the pages table lists, the same way: every page left from, or the most left from of them. */
function pagesCaption(listed: number, total: number, hours: number): string {
  if (total > listed)
    return sprintf(
      /* translators: 1: pages listed, 2: pages counted, 3: a time frame, such as "the last 24 hours" */
      __(
        "The top %1$d of %2$d pages shoppers left from with a cart in %3$s.",
        "shopsocket",
      ),
      listed,
      total,
      framePhrase(hours),
    );
  return sprintf(
    /* translators: 1: pages, 2: a time frame, such as "the last 24 hours" */
    _n(
      "%1$d page shoppers left from with a cart in %2$s.",
      "%1$d pages shoppers left from with a cart in %2$s.",
      total,
      "shopsocket",
    ),
    total,
    framePhrase(hours),
  );
}

/**
 * Carts whose shopper left in the time frame, newest first: what they held, who they are and when.
 * Those not ordered since with their value; with `recovered`, those an order followed, with the
 * order, its value and when it was placed.
 */
function LeftCarts({
  rows,
  hours,
  config,
  recovered,
}: {
  rows: WooAbandonedRow[];
  hours: number;
  config: ShopSocketBoardConfig;
  recovered?: boolean;
}) {
  const ids = useMemo(
    () =>
      Array.from(new Set(rows.flatMap((r) => r.products))).slice(
        0,
        LIVE_PRODUCTS,
      ),
    [rows],
  );
  const ref = useProductNames(ids, config);
  /* A guest who left may have a best guess waiting on the server. */
  const unnamed = useMemo(
    () =>
      Array.from(
        new Set(rows.filter((r) => !r.customer && r.v).map((r) => r.v ?? "")),
      ),
    [rows],
  );
  const guests = useGuestNames(unnamed, config);
  const columns = useMemo<Column<WooAbandonedRow>[]>(() => {
    const products = (row: WooAbandonedRow) =>
      row.products
        .map(
          (id) =>
            `${productLabel(ref(id), id)}${(row.quantities?.[id] ?? 1) > 1 ? ` \u00d7${row.quantities[id]}` : ""}`,
        )
        .join(", ");
    const cart: Column<WooAbandonedRow> = {
      id: "products",
      label: __("Cart", "shopsocket"),
      value: products,
    };
    const shopper = shopperColumn<WooAbandonedRow>(
      (row) => ({
        v: row.v,
        customer: row.customer,
        customerUrl: row.customer_url,
        guess: guests(row.v ?? ""),
      }),
      config.adminUrl,
    );
    const left: Column<WooAbandonedRow> = {
      id: "left",
      label: __("Left", "shopsocket"),
      value: (row) => row.left_at,
      render: (row) => <span title={row.left_at}>{timeOf(row.left_at)}</span>,
    };
    if (!recovered)
      return [
        cart,
        shopper,
        {
          id: "value",
          label: __("Value", "shopsocket"),
          value: (row) => row.value,
          render: (row) =>
            money(row.value, row.currency || config.currencySymbol),
          numeric: true,
        },
        left,
      ];
    return [
      {
        id: "order",
        label: __("Order", "shopsocket"),
        value: (row) => row.order_id ?? 0,
        render: (row) => {
          const href = row.order_url
            ? adminUrlOrNull(row.order_url, config.adminUrl)
            : null;
          const label = row.order_id ? `#${row.order_id}` : "";
          return href ? <Link href={href}>{label}</Link> : label;
        },
      },
      cart,
      shopper,
      {
        id: "value",
        label: __("Order value", "shopsocket"),
        value: (row) => row.order_value ?? row.value,
        render: (row) =>
          money(
            row.order_value ?? row.value,
            row.currency || config.currencySymbol,
          ),
        numeric: true,
      },
      left,
      {
        id: "ordered",
        label: __("Ordered", "shopsocket"),
        value: (row) => row.converted_at ?? "",
        render: (row) =>
          row.converted_at ? (
            <span title={row.converted_at}>{timeOf(row.converted_at)}</span>
          ) : (
            ""
          ),
      },
    ];
  }, [ref, guests, config, recovered]);
  return (
    <BoardTable
      perPage={10}
      className={recovered ? "shopsocket-recovered" : "shopsocket-abandoned"}
      rows={rows}
      rowId={byCart}
      empty={sprintf(
        recovered
          ? /* translators: %s: a time frame, such as "the last 24 hours" */
            __("No cart left in %s has become an order.", "shopsocket")
          : /* translators: %s: a time frame, such as "the last 24 hours" */
            __("No shopper has left a cart behind in %s.", "shopsocket"),
        framePhrase(hours),
      )}
      columns={columns}
    />
  );
}

/** The products most often in a cart left in the time frame, with how many carts bought each. */
function LeftProducts({
  rows,
  hours,
  config,
}: {
  rows: WooAbandonment["products"];
  hours: number;
  config: ShopSocketBoardConfig;
}) {
  const ids = useMemo(() => rows.map((p) => p.id), [rows]);
  const product = useProductNames(ids, config);
  const columns = useMemo<Column<WooAbandonment["products"][number]>[]>(
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
  return (
    <BoardTable
      className="shopsocket-left-products"
      rows={rows}
      rowId={byProduct}
      empty={sprintf(
        /* translators: %s: a time frame, such as "the last 24 hours" */
        __("No cart was left behind in %s.", "shopsocket"),
        framePhrase(hours),
      )}
      columns={columns}
    />
  );
}

/** A page left from, as the `kind:id` step it is. */
type LeftPage = NonNullable<WooAbandonment["pages"]>[number];

/** A page's step as its table key and the key its name is asked by. */
const byPage = (row: LeftPage) => `${row.kind}:${row.id}`;

/**
 * The pages most leavers left from in the time frame: the page, its kind, how many left from it, their
 * share of the `leavers` and the typical time they spent there.
 */
function LeftPages({
  rows,
  leavers,
  hours,
  config,
}: {
  rows: LeftPage[];
  leavers: number;
  hours: number;
  config: ShopSocketBoardConfig;
}) {
  const steps = useMemo(
    () => rows.filter((row) => needsLookup(row.kind)).map(byPage),
    [rows],
  );
  const page = usePageNames(steps, config);
  const ids = useMemo(
    () => rows.filter((row) => row.kind === "product").map((row) => row.id),
    [rows],
  );
  const product = useProductNames(ids, config);
  const columns = useMemo<Column<LeftPage>[]>(() => {
    const label = (row: LeftPage) =>
      pageLabel(row.kind, row.id, page(byPage(row)), product(row.id));
    return [
      {
        id: "page",
        label: __("Page", "shopsocket"),
        value: label,
        render: (row) => {
          const url = pageUrl(
            row.kind,
            page(byPage(row)),
            product(row.id),
            config,
          );
          const href = url ? siteUrlOrNull(url, config.homeUrl) : null;
          return href ? (
            <Link href={href} openInNewTab>
              {label(row)}
            </Link>
          ) : (
            label(row)
          );
        },
      },
      {
        id: "kind",
        label: __("Type", "shopsocket"),
        value: (row) => (row.kind === "deleted" ? "" : kindLabel(row.kind)),
      },
      {
        id: "left",
        label: __("Shoppers left", "shopsocket"),
        value: (row) => row.left,
        numeric: true,
      },
      {
        id: "share",
        label: __("Share", "shopsocket"),
        value: (row) => row.left / Math.max(1, leavers),
        render: (row) =>
          `${Math.round((100 * row.left) / Math.max(1, leavers))}%`,
        numeric: true,
      },
      {
        id: "time",
        label: __("Typical time there", "shopsocket"),
        value: (row) => row.median_s,
        render: (row) => duration(row.median_s),
        numeric: true,
      },
    ];
  }, [page, product, leavers, config]);
  return (
    <BoardTable
      className="shopsocket-left-pages"
      rows={rows}
      rowId={byPage}
      empty={sprintf(
        /* translators: %s: a time frame, such as "the last 24 hours" */
        __("No shopper has left with a cart in %s.", "shopsocket"),
        framePhrase(hours),
      )}
      columns={columns}
    />
  );
}

/** No products while history is off. */
const NO_PRODUCTS: WooAbandonment["products"] = [];

/** No pages while history is off. */
const NO_PAGES: LeftPage[] = [];

/**
 * The "Abandoned carts" card: the carts left and not ordered since as a figure for each time frame,
 * then, for the one chosen, where leavers leave from, the carts left, those an order recovered and
 * the products left most often.
 *
 * @since 0.8.0
 */
export function AbandonedCarts({
  snapshot,
  config,
  hours,
  onHours,
}: {
  snapshot: WooDashboardSnapshot;
  config: ShopSocketBoardConfig;
  /** The time frame chosen, in hours. */
  hours: number;
  onHours: (hours: number) => void;
}) {
  /* The sentence and the tables are of the time frame the rows are of, which trails the choice by one request. */
  const shown = snapshot.abandonedHours;
  const leak = snapshot.abandonment?.leak;
  const left =
    snapshot.leftBehind.find((held) => held.hours === shown)?.shoppers ??
    snapshot.abandoned.length;
  const products = snapshot.abandonment?.products ?? NO_PRODUCTS;
  const pages = snapshot.abandonment?.pages ?? NO_PAGES;
  return (
    <Section
      title={__("Abandoned carts", "shopsocket")}
      className="shopsocket-board__abandoned"
      description={__(
        "Carts whose shopper left without ordering, those an order recovered, the products left most often and the pages most left from.",
        "shopsocket",
      )}
    >
      <div
        className="shopsocket-abandoned-summary"
        aria-live="polite"
        aria-busy={hours !== shown}
      >
        <div className="shopsocket-frames">
          <RadioGroup
            aria-label={__("Time frame", "shopsocket")}
            value={hours}
            onValueChange={(frame) => onHours(Number(frame))}
            render={<div className="shopsocket-stats" />}
          >
            {snapshot.leftBehind.map((held) => (
              <div
                key={held.hours}
                className={`shopsocket-frame${held.hours === hours ? " is-chosen" : ""}`}
                onClick={() => onHours(held.hours)}
              >
                <Stat
                  name={`abandoned-${held.hours}`}
                  compact
                  value={String(held.shoppers)}
                  label={frameLabel(held.hours)}
                  lead={
                    <Radio
                      value={held.hours}
                      aria-label={frameLabel(held.hours)}
                    />
                  }
                  detail={
                    <>
                      {sprintf(
                        /* translators: %d: products in those carts */
                        _n(
                          "%d product",
                          "%d products",
                          held.products,
                          "shopsocket",
                        ),
                        held.products,
                      )}
                      {", "}
                      <span className="shopsocket-stat__money">
                        {held.currency
                          ? money(held.revenue, held.currency)
                          : `${config.currencySymbol}0`}
                      </span>
                    </>
                  }
                />
              </div>
            ))}
          </RadioGroup>
          {hours !== shown && (
            <Progress.Root
              className="shopsocket-frames__loading"
              value={null}
              aria-label={sprintf(
                /* translators: %s: a time frame, such as "the last 24 hours" */
                __("Loading the carts left in %s", "shopsocket"),
                framePhrase(hours),
              )}
            >
              <Progress.Track>
                <Progress.Indicator />
              </Progress.Track>
            </Progress.Root>
          )}
        </div>
        {leak && (
          <Text variant="body-md" render={<p />} className="shopsocket-leak">
            {sprintf(
              /* translators: 1: where most leavers left from, 2: the typical time there, 3: how many, 4: their share of everyone who left, 5: a time frame, such as "the last 24 hours" */
              __(
                "Most shoppers who leave with a cart do so from %1$s, usually after %2$s there: %3$d in %5$s, %4$d%% of everyone who left.",
                "shopsocket",
              ),
              placeOf(leak.kind),
              duration(leak.median_s),
              leak.count,
              Math.round((100 * leak.count) / Math.max(1, leak.leavers)),
              framePhrase(shown),
            )}{" "}
            {config.journeysUrl && (
              <Link href={leaversUrl(config.journeysUrl, leak.kind)}>
                {__("See their journeys", "shopsocket")}
              </Link>
            )}
          </Text>
        )}
      </div>
      <Tabs.Root defaultValue="carts" className="shopsocket-tabs">
        <Tabs.List
          variant="minimal"
          aria-label={__("The carts left behind", "shopsocket")}
        >
          <Tabs.Tab value="carts">{__("Carts", "shopsocket")}</Tabs.Tab>
          <Tabs.Tab value="recovered">{__("Recovered", "shopsocket")}</Tabs.Tab>
          <Tabs.Tab value="products">{__("Products", "shopsocket")}</Tabs.Tab>
          <Tabs.Tab value="pages">{__("Pages", "shopsocket")}</Tabs.Tab>
        </Tabs.List>
        <Tabs.Panel value="carts" tabIndex={-1} keepMounted>
          {left > 0 && (
            <Caption name="carts">
              {leftCaption(snapshot.abandoned.length, left, shown)}
            </Caption>
          )}
          <LeftCarts rows={snapshot.abandoned} hours={shown} config={config} />
        </Tabs.Panel>
        <Tabs.Panel value="recovered" tabIndex={-1} keepMounted>
          {snapshot.recoveredCount > 0 && (
            <Caption name="recovered">
              {recoveredCaption(
                snapshot.recovered.length,
                snapshot.recoveredCount,
                shown,
              )}
            </Caption>
          )}
          <LeftCarts
            rows={snapshot.recovered}
            hours={shown}
            config={config}
            recovered
          />
        </Tabs.Panel>
        <Tabs.Panel value="products" tabIndex={-1} keepMounted>
          {products.length > 0 && (
            <Caption name="products">
              {productsCaption(
                products.length,
                Math.max(
                  products.length,
                  snapshot.abandonment?.products_total ?? 0,
                ),
                shown,
              )}
            </Caption>
          )}
          <LeftProducts rows={products} hours={shown} config={config} />
        </Tabs.Panel>
        <Tabs.Panel value="pages" tabIndex={-1} keepMounted>
          {pages.length > 0 && (
            <Caption name="pages">
              {pagesCaption(
                pages.length,
                Math.max(pages.length, snapshot.abandonment?.pages_total ?? 0),
                shown,
              )}
            </Caption>
          )}
          <LeftPages
            rows={pages}
            leavers={leak?.leavers ?? 0}
            hours={shown}
            config={config}
          />
        </Tabs.Panel>
      </Tabs.Root>
    </Section>
  );
}
