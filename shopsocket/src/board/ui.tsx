/*
 * The board's building blocks, in the look of WordSocket's settings tabs.
 *
 * - `Section`: a card with a title, an optional aside, a one-sentence description and its content.
 * - `Stat`: one figure with its label, an arrow when it is above or below its average, a detail line and a sparkline.
 * - `StatGroup`: a card of figures that belong together, a headline over a bar split into its parts.
 * - `BoardTable`: a sortable DataViews table, paged, with a message when it is empty; search, a filter, a phone layout and rows that open on request.
 * - `useNarrow`: whether the screen is a phone's width.
 * - `productColumn`: a table's product cell, named from its ref and linked to its edit screen.
 * - `shopperColumn`: a table's shopper cell, the customer, a guest's best guess, or Guest or Staff.
 */
import { __, _n, sprintf } from "@wordpress/i18n";
import { useEffect, useMemo, useState } from "@wordpress/element";
import { Badge, Card, Link, Stack, Text } from "@wordpress/ui";
import {
  DataViews,
  filterSortAndPaginate,
  type Field,
  type View,
} from "@wordpress/dataviews";
import { adminUrlOrNull } from "./trust";
import { productLabel } from "./useProductNames";

/**
 * A card on the board: its title, an aside beside the title, the description, then the content.
 *
 * @since 0.8.0
 */
export function Section({
  title,
  aside,
  description,
  className,
  children,
}: {
  title: string;
  aside?: React.ReactNode;
  description?: string;
  className?: string;
  children: React.ReactNode;
}) {
  return (
    <Card.Root
      className={`shopsocket-section${className ? ` ${className}` : ""}`}
    >
      <Card.Header>
        <Stack
          direction="row"
          gap="md"
          align="center"
          justify="space-between"
          wrap="wrap"
        >
          <Card.Title style={{ width: "100%" }}>
            <Stack direction="row" gap="lg" align="center" justify="space-between">
              <Text variant="heading-xl" render={<h2 />}>
                {title}
              </Text>
              {description && (
                <Text
                  variant="body-sm"
                  className="shopsocket-muted"
                  render={<p />}
                >
                  {description}
                </Text>
              )}
            </Stack>
          </Card.Title>
          {aside}
        </Stack>
      </Card.Header>
      <Card.Content>{children}</Card.Content>
    </Card.Root>
  );
}

/** The sparkline's drawing box; the SVG stretches to the tile, the strokes keep their width. */
const SPARK_W = 140;
const SPARK_H = 28;
const SPARK_PAD = 3;
/** A day of the series, named by when it ended on the browser's clock. */
const DAY_FORMAT = new Intl.DateTimeFormat(undefined, {
  weekday: "short",
  day: "numeric",
  month: "short",
});

/**
 * A sparkline under a figure, a point a day: the days before in grey, the step into the last day in the
 * accent, ending in a dot, a flat series along the bottom; each day's value in a tooltip.
 *
 * @since 0.8.0
 */
function Sparkline({
  days,
  dates,
  label,
}: {
  days: number[];
  dates: string[];
  label: (value: number) => string;
}) {
  const n = days.length;
  if (n < 2) return null;
  const max = Math.max(...days);
  const min = Math.min(...days);
  const x = (i: number) => (SPARK_W * i) / (n - 1);
  const y = (v: number) =>
    max === min
      ? SPARK_H - SPARK_PAD
      : SPARK_H -
        SPARK_PAD -
        ((SPARK_H - 2 * SPARK_PAD) * (v - min)) / (max - min);
  const line = (from: number, to: number) =>
    days
      .slice(from, to + 1)
      .map((v, k) => `${k === 0 ? "M" : "L"}${x(from + k)},${y(v)}`)
      .join("");
  const dayName = (i: number) =>
    i === n - 1
      ? __("Last 24 hours", "shopsocket")
      : dates[i]
        ? DAY_FORMAT.format(new Date(dates[i]))
        : "";
  return (
    <svg
      className="shopsocket-spark"
      viewBox={`0 0 ${SPARK_W} ${SPARK_H}`}
      preserveAspectRatio="none"
      role="img"
      aria-label={__("The last seven days, day by day", "shopsocket")}
    >
      <path className="shopsocket-spark__before" d={line(0, n - 2)} />
      <path className="shopsocket-spark__now" d={line(n - 2, n - 1)} />
      <path
        className="shopsocket-spark__end"
        d={`M${x(n - 1)},${y(days[n - 1])}h0`}
      />
      {days.map((v, i) => (
        <rect
          key={i}
          className="shopsocket-spark__hit"
          x={x(i) - SPARK_W / (n - 1) / 2}
          y={0}
          width={SPARK_W / (n - 1)}
          height={SPARK_H}
        >
          <title>{`${dayName(i)}: ${label(v)}`}</title>
        </rect>
      ))}
    </svg>
  );
}

/**
 * How a figure compares with its daily average: both numbers and which way is better.
 *
 * @since 0.8.0
 */
export interface Compare {
  now: number;
  before: number;
  goodWhen: "up" | "down";
}

/**
 * One figure: its label, a chip with an arrow when `compare` says it moved (green when that is for the
 * better, red otherwise, the detail line toned the same), the value, a detail line, and a
 * sparkline when `days` are given over `dates`; `name` marks the tile as `is-<name>`, `compact` draws
 * a smaller figure, `swatch` a square of its share's fill by the label and `lead` an element of the
 * caller's before it.
 *
 * @since 0.8.0
 */
export function Stat({
  name,
  value,
  label,
  detail,
  compare,
  days,
  dates,
  dayLabel,
  compact,
  swatch,
  lead,
}: {
  name: string;
  value: string;
  label: string;
  detail?: React.ReactNode;
  compare?: Compare;
  days?: number[];
  dates?: string[];
  dayLabel?: (value: number) => string;
  compact?: boolean;
  swatch?: boolean;
  lead?: React.ReactNode;
}) {
  const trend =
    !compare || compare.now === compare.before
      ? undefined
      : compare.now > compare.before
        ? "up"
        : "down";
  const tone = compare
    ? (
        compare.goodWhen === "up"
          ? compare.now >= compare.before
          : compare.now <= compare.before
      )
      ? "good"
      : "bad"
    : undefined;
  return (
    <div className={`shopsocket-stat is-${name}`}>
      <Stack direction="row" gap="xs" align="center">
        {lead}
        {swatch && (
          <span className="shopsocket-stat__swatch" aria-hidden="true" />
        )}
        <Text variant="body-md" className="shopsocket-stat__label">
          {label}
        </Text>
        {trend && tone && (
          <span
            className={`shopsocket-stat__chip is-${tone}`}
            aria-label={
              trend === "up"
                ? __("Above the 7-day average", "shopsocket")
                : __("Below the 7-day average", "shopsocket")
            }
          >
            {trend === "up" ? "\u2197" : "\u2198"}
          </span>
        )}
      </Stack>
      <Text
        variant={compact ? "heading-lg" : "heading-2xl"}
        className="shopsocket-stat__value"
      >
        {value}
      </Text>
      {detail && (
        <Text
          variant="body-sm"
          className={`shopsocket-stat__detail ${tone ? `is-${tone}` : "shopsocket-muted"}`}
        >
          {detail}
        </Text>
      )}
      {days && dates && (
        <Sparkline days={days} dates={dates} label={dayLabel ?? String} />
      )}
    </div>
  );
}

/**
 * A share of a group's bar: drawn as `is-<name>` as wide as its `size` among the others, and named
 * in a tooltip.
 *
 * @since 0.8.0
 */
export interface Share {
  name: string;
  size: number;
  title: string;
}

/**
 * A card of figures that belong together: the headline figure, a bar split into `shares` by size
 * (an empty track when every share is zero, `summary` its spoken name), and the parts under it.
 *
 * @since 0.8.0
 */
export function StatGroup({
  name,
  headline,
  shares,
  summary,
  children,
}: {
  name: string;
  headline: React.ReactNode;
  shares: Share[];
  summary: string;
  children: React.ReactNode;
}) {
  return (
    <div className={`shopsocket-group is-${name}`}>
      <div className="shopsocket-group__head">{headline}</div>
      <div className="shopsocket-split" role="img" aria-label={summary}>
        {shares
          .filter((share) => share.size > 0)
          .map((share) => (
            <span
              key={share.name}
              className={`shopsocket-split__share is-${share.name}`}
              style={{ flexGrow: share.size }}
              title={share.title}
            />
          ))}
      </div>
      <div className="shopsocket-group__parts">{children}</div>
    </div>
  );
}

/** Only the table layout is offered, so a board table's view is always a table view. */
type TableView = Extract<View, { type: "table" }>;

/**
 * A column's width and alignment, as DataViews takes them.
 *
 * @since 0.8.0
 */
export type ColumnStyle = NonNullable<
  NonNullable<TableView["layout"]>["styles"]
>[string];

/**
 * A column of a board table: its label, the value it sorts by, and how a cell shows it.
 *
 * @since 0.8.0
 */
export interface Column<T> {
  id: string;
  label: string;
  value: (row: T) => string | number;
  render?: (row: T) => React.ReactNode;
  /** A number: a cell without its own render is a `.shopsocket-table__num`. */
  numeric?: boolean;
  /** A date and time, sorted and filtered as one. */
  datetime?: boolean;
  /** False when the column's header does not sort the table. */
  sortable?: boolean;
  /** Whether the search box reads the column. */
  searchable?: boolean;
  /** The values the column filters by, each with its label. */
  options?: { value: string; label: string }[];
  /** The column's width and alignment; the first column takes what the others leave. */
  style?: ColumnStyle;
  /** The column on a phone: its style there, or false to leave it out. */
  narrow?: ColumnStyle | false;
}

/** A table row as DataViews takes it: its id beside the row. */
interface Item<T> {
  id: string;
  row: T;
}

/** Rows a page of a board table holds unless the caller says otherwise. */
const PER_PAGE = 10;

/** The width WordPress admin turns into its phone layout at. */
const NARROW = "(max-width: 782px)";

/**
 * Whether the screen is a phone's width, followed as it changes.
 *
 * @since 0.8.0
 */
export function useNarrow(): boolean {
  const [narrow, setNarrow] = useState(
    () => window.matchMedia?.(NARROW).matches ?? false,
  );
  useEffect(() => {
    const media = window.matchMedia?.(NARROW);
    if (!media) return undefined;
    const update = () => setNarrow(media.matches);
    update();
    media.addEventListener("change", update);
    return () => media.removeEventListener("change", update);
  }, []);
  return narrow;
}

/** The columns a view lists beside the first and the description, and their styles, at a width. */
function layoutOf<T>(
  columns: Column<T>[],
  description: string | undefined,
  narrow: boolean,
): { fields: string[]; styles: Record<string, ColumnStyle> } {
  const shown = columns
    .slice(1)
    .filter((c) => c.id !== description && !(narrow && c.narrow === false));
  const styles: Record<string, ColumnStyle> = {};
  for (const c of shown) {
    const style = (narrow && c.narrow) || c.style;
    if (style) styles[c.id] = style;
  }
  return { fields: shown.map((c) => c.id), styles };
}

/**
 * A sortable DataViews table of `rows`, the first column naming the row with `description`'s column
 * under it, `perPage` to a page; `empty` when there are none, `noMatch` when a search or a filter leaves
 * none. `columns` and `rowId` are read by identity, so callers keep them stable.
 *
 * @since 0.8.0
 */
export function BoardTable<T>({
  columns,
  rows,
  rowId,
  empty,
  noMatch,
  className,
  description,
  density = "compact",
  isFilterable = true,
  perPage = PER_PAGE,
  searchLabel,
  onOpen,
}: {
  columns: Column<T>[];
  rows: T[];
  rowId: (row: T) => string;
  empty: string;
  noMatch?: string;
  className?: string;
  description?: string;
  density?: NonNullable<TableView["layout"]>["density"];
  isFilterable?: boolean;
  perPage?: number;
  searchLabel?: string;
  onOpen?: (row: T) => void;
}) {
  const narrow = useNarrow();
  const [view, setView] = useState<TableView>(() => {
    const { fields, styles } = layoutOf(columns, description, narrow);
    return {
      type: "table",
      fields,
      titleField: columns[0]?.id,
      descriptionField: description,
      page: 1,
      perPage,
      search: "",
      filters: [],
      layout: { density, styles },
    };
  });
  useEffect(() => {
    setView((v) => {
      const { fields, styles } = layoutOf(columns, description, narrow);
      return { ...v, fields, layout: { ...v.layout, styles } };
    });
  }, [narrow]);
  const fields = useMemo<Field<Item<T>>[]>(
    () =>
      columns.map((c) => {
        let filterBy: Field<Item<T>>["filterBy"];
        if (!isFilterable) filterBy = false;
        else if (c.options) filterBy = { operators: ["isAny"] };
        let type: Field<Item<T>>["type"] = "text";
        if (c.datetime) type = "datetime";
        else if (c.numeric) type = "number";
        return {
          id: c.id,
          label: c.label,
          type,
          enableSorting: c.sortable !== false,
          enableGlobalSearch: c.searchable === true,
          enableHiding: c !== columns[0],
          elements: c.options,
          filterBy,
          getValue: ({ item }) => c.value(item.row),
          render: ({ item }) =>
            c.render ? (
              c.render(item.row)
            ) : (
              <span className={c.numeric ? "shopsocket-table__num" : undefined}>
                {c.value(item.row)}
              </span>
            ),
        };
      }),
    [columns, isFilterable],
  );
  const items = useMemo(
    () => rows.map((row) => ({ id: rowId(row), row })),
    [rows, rowId],
  );
  const { data, paginationInfo } = useMemo(
    () => filterSortAndPaginate(items, view, fields),
    [items, view, fields],
  );
  if (rows.length === 0) {
    return (
      <Text variant="body-sm" className="shopsocket-muted" render={<p />}>
        {empty}
      </Text>
    );
  }
  return (
    <div className={`shopsocket-table${className ? ` ${className}` : ""}`}>
      <DataViews<Item<T>>
        data={data}
        fields={fields}
        view={view}
        onChangeView={(next) => setView(next as TableView)}
        paginationInfo={paginationInfo}
        defaultLayouts={{ table: {} }}
        search={Boolean(searchLabel)}
        searchLabel={searchLabel}
        isItemClickable={onOpen ? () => true : undefined}
        onClickItem={onOpen ? (item) => onOpen(item.row) : undefined}
        empty={
          noMatch ? (
            <Text variant="body-sm" className="shopsocket-muted" render={<p />}>
              {noMatch}
            </Text>
          ) : undefined
        }
      />
    </div>
  );
}

/**
 * A table's product column: the row's product named from its ref and linked to its edit screen when
 * that is on this site's admin.
 *
 * @since 0.8.0
 */
export function productColumn<T extends { id: number }>(
  product: (id: number) => WooProductRef | null | undefined,
  adminUrl: string,
): Column<T> {
  const label = (row: T) => productLabel(product(row.id), row.id);
  return {
    id: "product",
    label: __("Product", "shopsocket"),
    value: label,
    render: (row) => {
      const ref = product(row.id);
      const href = ref ? adminUrlOrNull(ref.edit_url, adminUrl) : null;
      return href ? <Link href={href}>{label(row)}</Link> : label(row);
    },
  };
}

/**
 * Who a row's shopper is, as the shopper column reads them.
 *
 * @since 0.8.0
 */
export interface ShopperRef {
  /** The visitor id, printed on the cell as `data-visitor`. */
  v?: string;
  /** The account's name, when the shopper is logged in. */
  customer?: string | null;
  /** The account's profile screen. */
  customerUrl?: string;
  /** The best guess at who a guest is. */
  guess?: WooGuestRef | null;
  staff?: boolean;
}

/** The name a shopper sorts by: the customer, a guest's guessed email or name, or Guest or Staff. */
function shopperName({ customer, guess, staff }: ShopperRef): string {
  return (
    customer ||
    guess?.email ||
    guess?.name ||
    (staff ? __("Staff", "shopsocket") : __("Guest", "shopsocket"))
  );
}

/**
 * A table's shopper column: the customer linked to their profile when that is on this site's admin,
 * a guest's best guess with its paid orders, or Guest or Staff.
 *
 * @since 0.8.0
 */
export function shopperColumn<T>(
  who: (row: T) => ShopperRef,
  adminUrl: string,
): Column<T> {
  return {
    id: "shopper",
    label: __("Shopper", "shopsocket"),
    value: (row) => shopperName(who(row)),
    render: (row) => {
      const shopper = who(row);
      const { customer, guess } = shopper;
      const profile =
        customer && shopper.customerUrl
          ? adminUrlOrNull(shopper.customerUrl, adminUrl)
          : null;
      let name: React.ReactNode;
      if (customer) {
        name = profile ? <Link href={profile}>{customer}</Link> : customer;
      } else if (guess) {
        name = (
          <span title={guess.email}>
            {shopperName(shopper)}
            <span className="shopsocket-muted">
              {" · "}
              {sprintf(
                /* translators: %d: paid orders placed with the guessed email */
                _n("%d order", "%d orders", guess.orders, "shopsocket"),
                guess.orders,
              )}
            </span>{" "}
            <Badge intent="none">{__("Guest", "shopsocket")}</Badge>
          </span>
        );
      } else {
        name = <span className="shopsocket-muted">{shopperName(shopper)}</span>;
      }
      return <span data-visitor={shopper.v || undefined}>{name}</span>;
    },
  };
}
