/**
 * Live orders as a board table: the orders the board holds, newest activity first.
 *
 * - Searchable by order number and customer, filterable by status, sortable by column.
 * - The order number and customer share the first column.
 * - On a phone only time, total and status stay beside it; the view menu brings the rest back.
 */
import { __, sprintf } from "@wordpress/i18n";
import { useMemo } from "@wordpress/element";
import type { OrderRow } from "./useLiveOrders";
import { adminUrlOrNull } from "./trust";
import { price } from "./format";
import { BoardTable, useNarrow, type Column } from "./ui";

/** How long a row that just changed stays marked, in milliseconds. */
const FRESH_MS = 4000;

/** Orders a page of the table holds. */
const PER_PAGE = 10;

/** An order's id as its table key. */
const byOrderId = (row: OrderRow) => String(row.order_id);

type Props = {
  rows: OrderRow[];
  statuses: ShopSocketBoardConfig["statuses"];
  currencySymbol: string;
  adminUrl: string;
};

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

/**
 * The orders table; a row opens its order's edit screen when that is on this site's admin.
 *
 * @since 0.1.1
 * @since 0.8.0 Drawn by the board's shared table.
 */
export function LiveOrders({
  rows,
  statuses,
  currencySymbol,
  adminUrl,
}: Props) {
  const narrow = useNarrow();
  const columns = useMemo<Column<OrderRow>[]>(
    () => [
      {
        id: "number",
        label: __("Order", "shopsocket"),
        value: (row) => row.number,
        sortable: false,
        searchable: true,
        render: (row) => (
          <a
            key={row.updatedAt}
            href={adminUrlOrNull(row.edit_url, adminUrl) ?? undefined}
            data-order-id={row.order_id}
            className={`shopsocket-order${row.updatedAt && Date.now() - row.updatedAt < FRESH_MS ? " is-fresh" : ""}`}
          >
            #{row.number}
          </a>
        ),
      },
      {
        id: "customer",
        label: __("Customer", "shopsocket"),
        value: (row) => row.customer,
        searchable: true,
      },
      {
        id: "created_at",
        label: __("Time", "shopsocket"),
        value: (row) => row.created_at ?? "",
        datetime: true,
        style: { width: "11em" },
        narrow: { width: "6em" },
        render: (row) => (
          <span
            className="shopsocket-order__time"
            title={
              row.created_at
                ? new Date(row.created_at).toLocaleString()
                : undefined
            }
          >
            {timeOf(row, !narrow)}
          </span>
        ),
      },
      {
        id: "item_count",
        label: __("Items", "shopsocket"),
        value: (row) => row.item_count,
        numeric: true,
        style: { width: "5em", align: "end" },
        narrow: false,
      },
      {
        id: "total",
        label: __("Total", "shopsocket"),
        value: (row) => row.total,
        numeric: true,
        style: { width: "8em", align: "end" },
        narrow: { width: "6em", align: "end" },
        render: (row) => (
          <span className="shopsocket-order__total">
            {price(row.total, row.currency, currencySymbol)}
          </span>
        ),
      },
      {
        id: "status",
        label: __("Status", "shopsocket"),
        value: (row) => row.status,
        options: statuses.map((s) => ({ value: s.slug, label: s.label })),
        style: { width: "10em" },
        narrow: { width: "8em" },
        render: (row) => (
          <span className={`shopsocket-status status-${row.status}`}>
            {statuses.find((s) => s.slug === row.status)?.label ?? row.status}
          </span>
        ),
      },
      {
        id: "payment_method",
        label: __("Payment", "shopsocket"),
        value: (row) => row.payment_method,
        style: { width: "12em" },
        narrow: false,
      },
    ],
    [statuses, currencySymbol, adminUrl, narrow],
  );
  return (
    <BoardTable
      columns={columns}
      rows={rows}
      rowId={byOrderId}
      description="customer"
      density="balanced"
      perPage={PER_PAGE}
      searchLabel={__("Search orders", "shopsocket")}
      onOpen={(row) => {
        const url = adminUrlOrNull(row.edit_url, adminUrl);
        if (url) window.location.assign(url);
      }}
      empty={__(
        "No recent orders. New orders appear here the moment they are placed.",
        "shopsocket",
      )}
      noMatch={sprintf(
        /* translators: %d: number of orders on the board */
        __("No orders match. %d on the board.", "shopsocket"),
        rows.length,
      )}
    />
  );
}
