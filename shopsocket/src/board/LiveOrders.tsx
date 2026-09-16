/**
 * Live orders as a DataViews table: newest activity first, searchable by
 * order number and customer, filterable by status, sortable by column.
 */
import { __, sprintf } from "@wordpress/i18n";
import { useMemo, useState } from "@wordpress/element";
import { DataViews, filterSortAndPaginate, type Field, type View } from "@wordpress/dataviews";
import type { OrderRow } from "./useLiveOrders";
import { adminUrlOrNull } from "./trust";

const FRESH_MS = 4000;

const DEFAULT_VIEW: View = {
  type: "table",
  titleField: "number",
  fields: ["created_at", "customer", "item_count", "total", "status", "payment_method"],
  page: 1,
  perPage: 25,
  search: "",
  filters: [],
  layout: { density: "compact" },
};

type Props = {
  rows: OrderRow[];
  statuses: ShopSocketBoardConfig["statuses"];
  currencySymbol: string;
  adminUrl: string;
};

/** An amount in the browser's locale, or symbol plus two decimals when the currency is unknown. */
function formatMoney(amount: number, currency: string, fallbackSymbol: string): string {
  try {
    return new Intl.NumberFormat(undefined, { style: "currency", currency }).format(amount);
  } catch {
    return `${fallbackSymbol}${amount.toFixed(2)}`;
  }
}

/** The order's creation time as hours and minutes, or empty when unknown. */
function timeOf(row: OrderRow): string {
  const when = row.created_at ? new Date(row.created_at) : null;
  return when ? when.toLocaleTimeString(undefined, { hour: "2-digit", minute: "2-digit" }) : "";
}

/** The orders table; the view (search, filters, sort, page) is component state. */
export function LiveOrders({ rows, statuses, currencySymbol, adminUrl }: Props) {
  const [view, setView] = useState<View>(DEFAULT_VIEW);
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
        render: ({ item }) => <>{timeOf(item)}</>,
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
        render: ({ item }) => <>{formatMoney(item.total, item.currency, currencySymbol)}</>,
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
    [statuses, currencySymbol, adminUrl],
  );

  const { data, paginationInfo } = useMemo(() => filterSortAndPaginate(rows, view, fields), [rows, view, fields]);

  return (
    <DataViews<OrderRow>
      data={data}
      fields={fields}
      view={view}
      onChangeView={setView}
      paginationInfo={paginationInfo}
      getItemId={(row) => String(row.order_id)}
      defaultLayouts={{ table: {} }}
      searchLabel={__("Search orders", "shopsocket")}
      isItemClickable={() => true}
      onClickItem={open}
      actions={[
        {
          id: "open",
          label: __("Open order", "shopsocket"),
          isPrimary: true,
          callback: ([row]) => {
            if (row) open(row);
          },
        },
      ]}
      empty={
        <p className="shopsocket-board__empty">
          {rows.length === 0
            ? __("No orders yet. New orders appear here the moment they are placed.", "shopsocket")
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
