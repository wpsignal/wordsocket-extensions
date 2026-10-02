/**
 * Live order state: seeded from the server-rendered list, updated by
 * woo.order.* events from window.WPS. Newest first; a row moves to the top
 * when it changes so a busy board always shows the latest activity.
 */
import { useCallback, useEffect, useRef, useState } from "@wordpress/element";

/** An order on the board; `updatedAt` is 0 for the server-rendered rows and drives the "fresh" flash. */
export type OrderRow = WooOrderEvent & { updatedAt: number };

const MAX_ROWS = 100;

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
function missed(rows: OrderRow[], fetched: WooOrderEvent[], startedAt: number): WooOrderEvent[] {
  return fetched.filter((order) => {
    const row = rows.find((r) => r.order_id === order.order_id);
    return !row || (row.updatedAt < startedAt && !sameOrder(row, order));
  });
}

type Channels = ShopSocketBoardConfig["channels"];

/**
 * Order rows kept current by `woo.order.*` events on the staff feed, and
 * `reconcile` to merge in what a refresh found; `onNewOrder` fires for each
 * order created, heard live or recovered.
 */
export function useLiveOrders(initial: WooOrderEvent[], onNewOrder: (order: WooOrderEvent) => void, channels: Channels) {
  const [rows, setRows] = useState<OrderRow[]>(() => initial.map((o) => ({ ...o, updatedAt: 0 })));
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
      setRows((current) => missed(current, fetched, startedAt).reduceRight(upsert, current));
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
    const offs = ["woo.order.created", "woo.order.paid", "woo.order.status"].map((name) =>
      wps.on(name, (data, channel) => {
        if (!wps.onChannel(channel, channels.orders)) return;
        const event = data as unknown as WooOrderEvent;
        setRows((current) => upsert(current, event));
        if (name === "woo.order.created") onNewOrder(event);
      }),
    );
    return () => offs.forEach((off) => off());
  }, [onNewOrder, channels.orders]);

  return { rows, reconcile };
}

/** Low-stock and out-of-stock products, most recent first, deduplicated per product/variation. */
export function useStockAlerts(channels: Channels) {
  const [alerts, setAlerts] = useState<Array<WooStockEvent & { kind: "low" | "out" }>>([]);

  useEffect(() => {
    const wps = window.WPS;
    if (!wps) return undefined;
    // A handler that files the event as an alert of `kind`, replacing any for the same product.
    const add = (kind: "low" | "out") => (data: Record<string, unknown>, channel: string) => {
      if (!wps.onChannel(channel, channels.orders)) return;
      const event = data as unknown as WooStockEvent;
      setAlerts((current) => [
        { ...event, kind },
        ...current.filter((a) => !(a.product_id === event.product_id && a.variation_id === event.variation_id)),
      ].slice(0, 20));
    };
    const offs = [wps.on("woo.stock.low", add("low")), wps.on("woo.stock.out", add("out"))];
    // A restock clears the alert: stock changes are public events staff also receive.
    offs.push(
      wps.on("woo.stock.changed", (data, channel) => {
        if (!wps.onChannel(channel, channels.stock)) return;
        const event = data as unknown as WooStockEvent;
        if (event.stock_status === "instock" && (event.stock_quantity ?? 0) > 0) {
          setAlerts((current) =>
            current.filter((a) => !(a.product_id === event.product_id && a.variation_id === event.variation_id)),
          );
        }
      }),
    );
    return () => offs.forEach((off) => off());
  }, [channels.orders, channels.stock]);

  return alerts;
}
