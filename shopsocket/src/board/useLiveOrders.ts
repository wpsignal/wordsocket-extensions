/**
 * Live order state: seeded from the server-rendered list, updated by
 * woo.order.* events from window.WPS. Newest first; a row moves to the top
 * when it changes so a busy board always shows the latest activity.
 */
import {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
} from "@wordpress/element";

/**
 * An order on the board; `updatedAt` is 0 for the server-rendered rows and drives the "fresh" flash.
 *
 * @since 0.1.1
 */
export type OrderRow = WooOrderEvent & { updatedAt: number };

const MAX_ROWS = 100;

/** How often the orders' window is read again, in milliseconds. */
const WINDOW_TICK_MS = 60_000;

/** Put the event's order at the top, replacing its existing row, capped at MAX_ROWS. */
function upsert(rows: OrderRow[], event: WooOrderEvent): OrderRow[] {
  const row: OrderRow = { ...event, updatedAt: Date.now() };
  const rest = rows.filter((r) => r.order_id !== event.order_id);
  return [row, ...rest].slice(0, MAX_ROWS);
}

/** Whether the row already shows everything the fetched order says. */
function sameOrder(row: OrderRow, order: WooOrderEvent): boolean {
  return (
    row.status === order.status &&
    row.total === order.total &&
    row.item_count === order.item_count &&
    row.customer === order.customer &&
    row.payment_method === order.payment_method &&
    row.number === order.number
  );
}

/**
 * The fetched orders this board has not caught up with: absent, or showing something older.
 *
 * @param rows - The current order rows.
 * @param fetched - The orders the server says changed since the last refresh.
 * @param startedAt - The time the request began.
 * @returns The orders that are missing.
 */
function missed(
  rows: OrderRow[],
  fetched: WooOrderEvent[],
  startedAt: number,
): WooOrderEvent[] {
  return fetched.filter((order) => {
    const row = rows.find((r) => r.order_id === order.order_id);
    return !row || (row.updatedAt < startedAt && !sameOrder(row, order));
  });
}

type Channels = ShopSocketBoardConfig["channels"];

/**
 * The orders placed in the last `hours`, kept current by `woo.order.*` events on the staff feed, and
 * `reconcile` to merge in what a refresh found; `onNewOrder` fires for each order created, heard live or recovered.
 *
 * @since 0.1.1
 * @since 0.8.0 Takes `hours` and returns only the orders placed within them.
 */
export function useLiveOrders(
  initial: WooOrderEvent[],
  onNewOrder: (order: WooOrderEvent) => void,
  channels: Channels,
  hours: number,
) {
  const [rows, setRows] = useState<OrderRow[]>(() =>
    initial.map((o) => ({ ...o, updatedAt: 0 })),
  );
  const rowsRef = useRef(rows);
  rowsRef.current = rows;

  /*
   * `fetched` is newest first, as the server lists it, and covers the orders created or changed since `since` (server seconds); `startedAt`
   * is when the request left (this browser's clock).
   */
  const reconcile = useCallback(
    (fetched: WooOrderEvent[], startedAt: number, since: number) => {
      const fresh = missed(rowsRef.current, fetched, startedAt);
      if (fresh.length === 0) return;
      setRows((current) =>
        missed(current, fetched, startedAt).reduceRight(upsert, current),
      );
      const created = fresh.find(
        (order) =>
          !rowsRef.current.some((r) => r.order_id === order.order_id) &&
          order.created_at !== null &&
          Date.parse(order.created_at) / 1000 >= since,
      );
      if (created) onNewOrder(created);
    },
    [onNewOrder],
  );

  useEffect(() => {
    const wps = window.WPS;
    if (!wps) return undefined;
    const offs = [
      "woo.order.created",
      "woo.order.paid",
      "woo.order.status",
    ].map((name) =>
      wps.on(name, (data, channel) => {
        if (!wps.onChannel(channel, channels.orders)) return;
        const event = data as unknown as WooOrderEvent;
        setRows((current) => upsert(current, event));
        if (name === "woo.order.created") onNewOrder(event);
      }),
    );
    return () => offs.forEach((off) => off());
  }, [onNewOrder, channels.orders]);

  /* An order leaves the board once it is older than the window, whatever happens to it later. */
  const [now, setNow] = useState(() => Date.now());
  useEffect(() => {
    const timer = window.setInterval(() => setNow(Date.now()), WINDOW_TICK_MS);
    return () => window.clearInterval(timer);
  }, []);
  const recent = useMemo(() => {
    const floor = now - hours * 3_600_000;
    return rows.filter(
      (row) => !(row.created_at && Date.parse(row.created_at) < floor),
    );
  }, [rows, now, hours]);

  return { rows: recent, reconcile };
}

/**
 * An alert on the board: a new order, a product low on or out of stock, or a shopper a moment asked staff to look at.
 *
 * @since 0.8.0
 */
export type BoardAlert =
  | { id: string; type: "order"; event: WooOrderEvent }
  | {
      id: string;
      type: "stock";
      event: WooStockEvent & { kind: "low" | "out" };
    }
  | { id: string; type: "staff"; event: WooAlertEvent };

/** The alerts of each type the board holds at most. */
const ALERT_CAPS = { order: 10, stock: 20, staff: 10 } as const;

/** The id a product's stock alert goes by. */
const stockId = (event: WooStockEvent) =>
  `stock:${event.product_id}-${event.variation_id}`;

/** `alerts` with `alert` on top in place of the one of its id, its type kept within its cap. */
function put(alerts: BoardAlert[], alert: BoardAlert): BoardAlert[] {
  const rest = alerts.filter((a) => a.id !== alert.id);
  const kept = rest
    .filter((a) => a.type === alert.type)
    .slice(0, ALERT_CAPS[alert.type] - 1);
  return [
    alert,
    ...rest.filter((a) => a.type !== alert.type || kept.includes(a)),
  ];
}

/**
 * The board's alerts, newest first and one per order, product or shopper: an order handed to
 * `orderPlaced`, stock running low or out (a restock clears it) and the Alert staff moment action's,
 * each dismissed by its id until its next event.
 *
 * @since 0.8.0
 */
export function useBoardAlerts(channels: Channels) {
  const [alerts, setAlerts] = useState<BoardAlert[]>([]);

  useEffect(() => {
    const wps = window.WPS;
    if (!wps) return undefined;
    // A handler that files the event as a stock alert of `kind`.
    const stock =
      (kind: "low" | "out") =>
      (data: Record<string, unknown>, channel: string) => {
        if (!wps.onChannel(channel, channels.orders)) return;
        const event = data as unknown as WooStockEvent;
        setAlerts((current) =>
          put(current, {
            id: stockId(event),
            type: "stock",
            event: { ...event, kind },
          }),
        );
      };
    const offs = [
      wps.on("woo.stock.low", stock("low")),
      wps.on("woo.stock.out", stock("out")),
      // A restock clears the alert: stock changes are public events staff also receive.
      wps.on("woo.stock.changed", (data, channel) => {
        if (!wps.onChannel(channel, channels.stock)) return;
        const event = data as unknown as WooStockEvent;
        if (
          event.stock_status === "instock" &&
          (event.stock_quantity ?? 0) > 0
        ) {
          setAlerts((current) =>
            current.filter((a) => a.id !== stockId(event)),
          );
        }
      }),
      // Published by PHP on the orders feed, which only PHP writes.
      wps.on("woo.alert", (data, channel) => {
        if (!wps.onChannel(channel, channels.orders)) return;
        const event = data as unknown as WooAlertEvent;
        setAlerts((current) =>
          put(current, { id: `staff:${event.visitor}`, type: "staff", event }),
        );
      }),
    ];
    return () => offs.forEach((off) => off());
  }, [channels.orders, channels.stock]);

  const dismiss = useCallback(
    (id: string) =>
      setAlerts((current) => current.filter((a) => a.id !== id)),
    [],
  );
  const orderPlaced = useCallback(
    (order: WooOrderEvent) =>
      setAlerts((current) =>
        put(current, {
          id: `order:${order.order_id}`,
          type: "order",
          event: order,
        }),
      ),
    [],
  );
  return { alerts, dismiss, orderPlaced };
}
