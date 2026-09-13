/**
 * Live order state: seeded from the server-rendered list, updated by
 * woo.order.* events from window.WPS. Newest first; a row moves to the top
 * when it changes so a busy board always shows the latest activity.
 */
import { useEffect, useState } from "@wordpress/element";

export type OrderRow = WooOrderEvent & { lastEvent: string; updatedAt: number };

const MAX_ROWS = 100;

function upsert(rows: OrderRow[], event: WooOrderEvent, name: string): OrderRow[] {
  const row: OrderRow = { ...event, lastEvent: name, updatedAt: Date.now() };
  const rest = rows.filter((r) => r.order_id !== event.order_id);
  return [row, ...rest].slice(0, MAX_ROWS);
}

export function useLiveOrders(initial: WooOrderEvent[], onNewOrder: (order: WooOrderEvent) => void) {
  const [rows, setRows] = useState<OrderRow[]>(() =>
    initial.map((o) => ({ ...o, lastEvent: "loaded", updatedAt: 0 })),
  );

  useEffect(() => {
    const wps = window.WPS;
    if (!wps) return undefined;
    const offs = ["woo.order.created", "woo.order.paid", "woo.order.status"].map((name) =>
      wps.on(name, (data) => {
        const event = data as unknown as WooOrderEvent;
        setRows((current) => upsert(current, event, name));
        if (name === "woo.order.created") onNewOrder(event);
      }),
    );
    return () => offs.forEach((off) => off());
  }, [onNewOrder]);

  return rows;
}

/** Low-stock and out-of-stock products, most recent first, deduplicated per product/variation. */
export function useStockAlerts() {
  const [alerts, setAlerts] = useState<Array<WooStockEvent & { kind: "low" | "out" }>>([]);

  useEffect(() => {
    const wps = window.WPS;
    if (!wps) return undefined;
    const add = (kind: "low" | "out") => (data: Record<string, unknown>) => {
      const event = data as unknown as WooStockEvent;
      setAlerts((current) => [
        { ...event, kind },
        ...current.filter((a) => !(a.product_id === event.product_id && a.variation_id === event.variation_id)),
      ].slice(0, 20));
    };
    const offs = [wps.on("woo.stock.low", add("low")), wps.on("woo.stock.out", add("out"))];
    // A restock clears the alert: stock changes are public events staff also receive.
    offs.push(
      wps.on("woo.stock.changed", (data) => {
        const event = data as unknown as WooStockEvent;
        if (event.stock_status === "instock" && (event.stock_quantity ?? 0) > 0) {
          setAlerts((current) =>
            current.filter((a) => !(a.product_id === event.product_id && a.variation_id === event.variation_id)),
          );
        }
      }),
    );
    return () => offs.forEach((off) => off());
  }, []);

  return alerts;
}
