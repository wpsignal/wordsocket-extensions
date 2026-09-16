/**
 * Live orders as a DataViews table: newest activity first, searchable by
 * order number and customer, filterable by status, sortable by column.
 * The order number and customer share the first column; on a phone only
 * time, total, and status stay beside it (the view menu brings the rest back).
 */
import { __, sprintf } from "@wordpress/i18n";
import { useEffect, useMemo, useState } from "@wordpress/element";
import {
  DataViews,
  filterSortAndPaginate,
  type Field,
  type View,
} from "@wordpress/dataviews";
import type { OrderRow } from "./useLiveOrders";
import { adminUrlOrNull } from "./trust";

const FRESH_MS = 4000;
/** Only the table layout is offered, so the view is always a table view. */
type TableView = Extract<View, { type: "table" }>;
const NARROW = "(max-width: 782px)";
const WIDE_FIELDS = [
  "created_at",
  "item_count",
  "total",
  "status",
  "payment_method",
];
const NARROW_FIELDS = ["created_at", "total", "status"];
const WIDE_STYLES: NonNullable<TableView["layout"]>["styles"] = {
  created_at: { width: "11em" },
  item_count: { width: "5em", align: "end" },
  total: { width: "8em", align: "end" },
  status: { width: "10em" },
  payment_method: { width: "12em" },
};
const NARROW_STYLES: typeof WIDE_STYLES = {
  created_at: { width: "6em" },
  total: { width: "6em", align: "end" },
  status: { width: "8em" },
};

const DEFAULT_VIEW: TableView = {
  type: "table",
  titleField: "number",
  descriptionField: "customer",
  fields: WIDE_FIELDS,
  page: 1,
  perPage: 25,
  search: "",
  filters: [],
  layout: { density: "balanced", styles: WIDE_STYLES },
};

/** Whether the viewport matches the media query, tracked live. */
function useMedia(query: string): boolean {
  const [matches, setMatches] = useState(
    () => window.matchMedia?.(query).matches ?? false,
  );
  useEffect(() => {
    const media = window.matchMedia?.(query);
    if (!media) return undefined;
    const update = () => setMatches(media.matches);
    update();
    media.addEventListener("change", update);
    return () => media.removeEventListener("change", update);
  }, [query]);
  return matches;
}

type Props = {
  rows: OrderRow[];
  statuses: ShopSocketBoardConfig["statuses"];
  currencySymbol: string;
  adminUrl: string;
};

/** An amount in the browser's locale, or symbol plus two decimals when the currency is unknown. */
function formatMoney(
  amount: number,
  currency: string,
  fallbackSymbol: string,
): string {
  try {
    return new Intl.NumberFormat(undefined, {
      style: "currency",
      currency,
    }).format(amount);
  } catch {
    return `${fallbackSymbol}${amount.toFixed(2)}`;
  }
}

/**
 * When the order was placed: the time today, the date and time otherwise
 * (time only when there is no room for the date), empty when unknown.
 */
function timeOf(row: OrderRow, withDate: boolean): string {
  const when = row.created_at ? new Date(row.created_at) : null;
  if (!when || Number.isNaN(when.getTime())) return "";
  const time = when.toLocaleTimeString(undefined, {
    hour: "2-digit",
    minute: "2-digit",
  });
  const today = new Date();
  const sameDay =
    when.getFullYear() === today.getFullYear() &&
    when.getMonth() === today.getMonth() &&
    when.getDate() === today.getDate();
  return sameDay || !withDate
    ? time
    : `${when.toLocaleDateString(undefined, { month: "short", day: "numeric" })} ${time}`;
}

/** The orders table; the view (search, filters, sort, page) is component state. */
export function LiveOrders({
  rows,
  statuses,
  currencySymbol,
  adminUrl,
}: Props) {
  const [view, setView] = useState<TableView>(DEFAULT_VIEW);
  const narrow = useMedia(NARROW);
  useEffect(() => {
    setView((v) => ({
      ...v,
      fields: narrow ? NARROW_FIELDS : WIDE_FIELDS,
      layout: { ...v.layout, styles: narrow ? NARROW_STYLES : WIDE_STYLES },
    }));
  }, [narrow]);
  // Edit links come from event data, so only follow them into this site's admin.
  const editUrl = (row: OrderRow) => adminUrlOrNull(row.edit_url, adminUrl);
  const open = (row: OrderRow) => {
    const url = editUrl(row);
    if (url) window.location.assign(url);
  };

  const fields = useMemo<Field<OrderRow>[]>(
    () => [
      {
        id: "number",
        label: __("Order", "shopsocket"),
        enableGlobalSearch: true,
        enableSorting: false,
        render: ({ item }) => (
          <a
            key={item.updatedAt}
            href={editUrl(item) ?? undefined}
            data-order-id={item.order_id}
            className={`shopsocket-order${item.updatedAt && Date.now() - item.updatedAt < FRESH_MS ? " is-fresh" : ""}`}
          >
            #{item.number}
          </a>
        ),
      },
      {
        id: "created_at",
        type: "datetime",
        label: __("Time", "shopsocket"),
        render: ({ item }) => (
          <span
            className="shopsocket-order__time"
            title={
              item.created_at
                ? new Date(item.created_at).toLocaleString()
                : undefined
            }
          >
            {timeOf(item, !narrow)}
          </span>
        ),
      },
      {
        id: "customer",
        type: "text",
        label: __("Customer", "shopsocket"),
        enableGlobalSearch: true,
      },
      {
        id: "item_count",
        type: "integer",
        label: __("Items", "shopsocket"),
      },
      {
        id: "total",
        type: "number",
        label: __("Total", "shopsocket"),
        render: ({ item }) => (
          <span className="shopsocket-order__total">
            {formatMoney(item.total, item.currency, currencySymbol)}
          </span>
        ),
      },
      {
        id: "status",
        type: "text",
        label: __("Status", "shopsocket"),
        elements: statuses.map((s) => ({ value: s.slug, label: s.label })),
        filterBy: { operators: ["isAny"] },
        render: ({ item }) => (
          <span className={`shopsocket-status status-${item.status}`}>
            {statuses.find((s) => s.slug === item.status)?.label ?? item.status}
          </span>
        ),
      },
      {
        id: "payment_method",
        type: "text",
        label: __("Payment", "shopsocket"),
      },
    ],
    [statuses, currencySymbol, adminUrl, narrow],
  );

  const { data, paginationInfo } = useMemo(
    () => filterSortAndPaginate(rows, view, fields),
    [rows, view, fields],
  );

  return (
    <DataViews<OrderRow>
      data={data}
      fields={fields}
      view={view}
      onChangeView={(next) => setView(next as TableView)}
      paginationInfo={paginationInfo}
      getItemId={(row) => String(row.order_id)}
      defaultLayouts={{ table: {} }}
      searchLabel={__("Search orders", "shopsocket")}
      isItemClickable={() => true}
      onClickItem={open}
      empty={
        <p className="shopsocket-board__empty">
          {rows.length === 0
            ? __(
                "No orders yet. New orders appear here the moment they are placed.",
                "shopsocket",
              )
            : sprintf(
                /* translators: %d: number of orders on the board */
                __("No orders match. %d on the board.", "shopsocket"),
                rows.length,
              )}
        </p>
      }
    />
  );
}
