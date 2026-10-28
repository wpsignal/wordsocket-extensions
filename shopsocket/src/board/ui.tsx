/*
 * The board's building blocks, in the look of WordSocket's settings tabs.
 *
 * - `Section`: a card with a title, an optional aside, a one-sentence lede and its content.
 * - `Stat`: one figure with its label, an arrow when it moved against last week, a detail line and a sparkline.
 * - `BoardTable`: a sortable DataViews table, paged, with a message when it is empty.
 * - `productColumn`: a table's product cell, named from its ref and linked to its edit screen.
 */
import { __ } from "@wordpress/i18n";
import { useMemo, useState } from "@wordpress/element";
import { Card, Link, Stack, Text } from "@wordpress/ui";
import {
  DataViews,
  filterSortAndPaginate,
  type Field,
  type View,
} from "@wordpress/dataviews";
import { adminUrlOrNull } from "./trust";
import { productLabel } from "./useProductNames";

/**
 * A card on the board: its title, an aside beside the title, the lede, then the content.
 *
 * @since 0.8.0
 */
export function Section({
  title,
  aside,
  lede,
  className,
  children,
}: {
  title: string;
  aside?: React.ReactNode;
  lede?: string;
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
          <Card.Title>{title}</Card.Title>
          {aside}
        </Stack>
      </Card.Header>
      <Card.Content>
        <Stack direction="column" gap="md">
          {lede && (
            <Text variant="body-sm" className="shopsocket-muted" render={<p />}>
              {lede}
            </Text>
          )}
          {children}
        </Stack>
      </Card.Content>
    </Card.Root>
  );
}

/** The sparkline's drawing box; the SVG stretches to the tile, the strokes keep their width. */
const SPARK_W = 140;
const SPARK_H = 28;
const SPARK_PAD = 3;
/** A day of the series, named on the UTC calendar the server bucketed it on. */
const DAY_FORMAT = new Intl.DateTimeFormat(undefined, {
  weekday: "short",
  day: "numeric",
  month: "short",
  timeZone: "UTC",
});

/**
 * A two-week sparkline under a figure: last week in grey, this week in the accent, ending in a dot;
 * each day's value in a tooltip.
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
      ? SPARK_H / 2
      : SPARK_H -
        SPARK_PAD -
        ((SPARK_H - 2 * SPARK_PAD) * (v - min)) / (max - min);
  const line = (from: number, to: number) =>
    days
      .slice(from, to + 1)
      .map((v, k) => `${k === 0 ? "M" : "L"}${x(from + k)},${y(v)}`)
      .join("");
  const half = Math.floor(n / 2);
  const dayName = (i: number) =>
    dates[i] ? DAY_FORMAT.format(new Date(`${dates[i]}T00:00:00Z`)) : "";
  return (
    <svg
      className="shopsocket-spark"
      viewBox={`0 0 ${SPARK_W} ${SPARK_H}`}
      preserveAspectRatio="none"
      role="img"
      aria-label={__("The last two weeks, day by day", "shopsocket")}
    >
      <path className="shopsocket-spark__before" d={line(0, half)} />
      <path className="shopsocket-spark__now" d={line(half, n - 1)} />
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
 * How a figure compares with last week: both numbers and which way is better.
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
 * better, red otherwise, the detail line toned the same), the value, a detail line, and a two-week
 * sparkline when `days` are given over `dates`; `name` marks the tile as `is-<name>`.
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
}: {
  name: string;
  value: string;
  label: string;
  detail?: React.ReactNode;
  compare?: Compare;
  days?: number[];
  dates?: string[];
  dayLabel?: (value: number) => string;
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
        <Text variant="body-md" className="shopsocket-stat__label">
          {label}
        </Text>
        {trend && tone && (
          <span
            className={`shopsocket-stat__chip is-${tone}`}
            aria-label={
              trend === "up"
                ? __("Up on last week", "shopsocket")
                : __("Down on last week", "shopsocket")
            }
          >
            {trend === "up" ? "\u2197" : "\u2198"}
          </span>
        )}
      </Stack>
      <Text variant="heading-2xl" className="shopsocket-stat__value">
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
 * A column of a board table: its label, the value it sorts by, and how a cell shows it.
 *
 * @since 0.8.0
 */
export interface Column<T> {
  id: string;
  label: string;
  value: (row: T) => string | number;
  render?: (row: T) => React.ReactNode;
  numeric?: boolean;
}

/** A table row as DataViews takes it: its id beside the row. */
interface Item<T> {
  id: string;
  row: T;
}

/** Rows a page of a board table holds. */
const PER_PAGE = 10;

/**
 * A sortable DataViews table of `rows`, the first column naming the row, PER_PAGE to a page; `empty`
 * when there are none. A numeric cell without its own render is a `.shopsocket-table__num`; `columns` and
 * `rowId` are read by identity, so callers keep them stable.
 *
 * @since 0.8.0
 */
export function BoardTable<T>({
  columns,
  rows,
  rowId,
  empty,
  className,
}: {
  columns: Column<T>[];
  rows: T[];
  rowId: (row: T) => string;
  empty: string;
  className?: string;
}) {
  const [view, setView] = useState<View>({
    type: "table",
    fields: columns.slice(1).map((c) => c.id),
    titleField: columns[0]?.id,
    page: 1,
    perPage: PER_PAGE,
    search: "",
    filters: [],
    layout: { density: "compact" },
  });
  const fields = useMemo<Field<Item<T>>[]>(
    () =>
      columns.map((c) => ({
        id: c.id,
        label: c.label,
        type: c.numeric ? "number" : "text",
        enableSorting: true,
        enableHiding: c !== columns[0],
        getValue: ({ item }) => c.value(item.row),
        render: ({ item }) =>
          c.render ? (
            c.render(item.row)
          ) : (
            <span className={c.numeric ? "shopsocket-table__num" : undefined}>
              {c.value(item.row)}
            </span>
          ),
      })),
    [columns],
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
        onChangeView={setView}
        paginationInfo={paginationInfo}
        defaultLayouts={{ table: {} }}
        search={false}
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
