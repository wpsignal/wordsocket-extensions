/**
 * The dashboard figures. Basket rows come from WordPress (polled, pushed on
 * change); whether a basket is live comes from relay presence on the carts
 * channel, so the split follows arrivals and departures as they happen. The
 * two are crossed here by basket id. "Online now" is the relay's own count.
 */
import { useEffect, useMemo, useState } from "@wordpress/element";
import apiFetch from "@wordpress/api-fetch";
import { onChannel } from "./trust";

const POLL_MS = 30_000;
const AFTER_EVENT_MS = 1_500;
const BASKETS_EVENT = "woo.baskets";
const CONNECTIONS_EVENT = "wps.connections";
const PRESENCE_EVENT = "wps.presence";

type Config = Pick<ShopSocketBoardConfig, "dashboardUrl" | "nonce" | "snapshot" | "channels">;

/** Cross the basket rows with the set of present basket ids into two segments, counting live baskets per product. */
function splitBaskets(
  rows: WooBasketRow[],
  present: Set<string>,
): Pick<WooDashboardSnapshot, "baskets" | "liveProducts"> {
  const live = { shoppers: 0, products: new Set<number>(), revenue: 0, currency: "" };
  const abandoned = { shoppers: 0, products: new Set<number>(), revenue: 0, currency: "" };
  const liveCounts = new Map<number, { baskets: number; units: number }>();
  for (const row of rows) {
    const isLive = present.has(row.id);
    const seg = isLive ? live : abandoned;
    seg.shoppers += 1;
    seg.revenue += row.value;
    seg.currency = seg.currency || row.currency;
    row.products.forEach((id) => {
      seg.products.add(id);
      if (isLive) {
        const count = liveCounts.get(id) ?? { baskets: 0, units: 0 };
        count.baskets += 1;
        count.units += Math.max(1, Number(row.quantities?.[id]) || 1);
        liveCounts.set(id, count);
      }
    });
  }
  // Close a segment: distinct products, revenue rounded to cents.
  const finish = (s: typeof live): WooBasketSegment => ({
    shoppers: s.shoppers,
    products: s.products.size,
    revenue: Math.round(s.revenue * 100) / 100,
    currency: s.currency,
  });
  return {
    baskets: { live: finish(live), abandoned: finish(abandoned) },
    liveProducts: [...liveCounts].map(([id, count]) => ({ id, ...count })),
  };
}

/** The board's tile figures, and whether the last fetch of them failed. */
export function useDashboardSnapshot(config: Config) {
  const [rows, setRows] = useState<WooBasketRow[]>(config.snapshot.baskets);
  const [online, setOnline] = useState<WooDashboardData["online"]>(config.snapshot.online);
  const [present, setPresent] = useState<Set<string>>(() => new Set());
  const [usersOnline, setUsersOnline] = useState(0);
  const [stale, setStale] = useState(false);

  useEffect(() => {
    let alive = true;
    let inflight = false;
    let timer: ReturnType<typeof setTimeout> | undefined;

    // Fetch the basket rows and connection count, one request at a time.
    const refresh = async () => {
      if (inflight) return;
      inflight = true;
      try {
        const fresh = await apiFetch<WooDashboardData>({
          url: config.dashboardUrl,
          headers: { "X-WP-Nonce": config.nonce },
        });
        if (alive) {
          setRows(fresh.baskets);
          setOnline(fresh.online);
          setStale(false);
        }
      } catch {
        if (alive) setStale(true);
      } finally {
        inflight = false;
      }
    };

    // Refresh after `ms`, then keep polling; a new call replaces the pending one.
    const schedule = (ms: number) => {
      clearTimeout(timer);
      timer = setTimeout(() => {
        refresh().then(() => alive && schedule(POLL_MS));
      }, ms);
    };

    schedule(POLL_MS);

    const wps = window.WPS;
    const offs: Array<() => void> = [];
    /*
     * connection id -> { v: visitor id, b: basket id | null }. A shopper's tabs
     * share v, so distinct v is "users online"; distinct b is the live-basket split.
     */
    type Member = { v?: string; b?: string | null };
    const members = new Map<string, Member>();
    // Derive the live basket ids and the distinct visitor count from the members.
    const applyPresent = () => {
      if (!alive) return;
      const baskets = new Set<string>();
      const users = new Set<string>();
      members.forEach((m) => {
        if (m.b) baskets.add(m.b);
        if (m.v) users.add(m.v);
      });
      setPresent(baskets);
      setUsersOnline(users.size);
    };

    if (wps) {
      /*
       * Staff tokens auto-subscribe to the presence, baskets, and connections
       * channels (`wpsignal_token_channels`, inc/channels.php): only listen.
       */
      offs.push(
        wps.on(PRESENCE_EVENT, (data, channel) => {
          if (!onChannel(channel, config.channels.presence)) return;
          const p = data as {
            action?: string;
            id?: string;
            state?: Member;
            members?: Array<{ id: string; state?: Member }>;
          };
          if (p.action === "sync") {
            members.clear();
            (p.members ?? []).forEach((m) => m.id && members.set(m.id, m.state ?? {}));
          } else if (p.action === "join" && p.id) {
            members.set(p.id, p.state ?? {});
          } else if (p.action === "leave" && p.id) {
            members.delete(p.id);
          } else {
            return;
          }
          applyPresent();
        }),
      );
      offs.push(
        wps.on(BASKETS_EVENT, (data, channel) => {
          if (!onChannel(channel, config.channels.orders)) return;
          const payload = data as { baskets?: WooBasketRow[] };
          if (Array.isArray(payload.baskets)) {
            setRows(payload.baskets);
            setStale(false);
          }
        }),
      );
      offs.push(
        wps.on(CONNECTIONS_EVENT, (data, channel) => {
          if (!onChannel(channel, config.channels.connections)) return;
          const o = data as unknown as NonNullable<WooDashboardData["online"]>;
          if (typeof o.active_connections === "number") {
            setOnline({ ...o });
            setStale(false);
          }
        }),
      );
      // The first-paint snapshot predates the dashboard's own connection: reconcile once up.
      offs.push(wps.onStateChange((s) => s.connected && schedule(AFTER_EVENT_MS)));
    }

    // Refresh at once when the tab comes back into view.
    const onVisibility = () => {
      if (!document.hidden) schedule(0);
    };
    document.addEventListener("visibilitychange", onVisibility);

    return () => {
      alive = false;
      clearTimeout(timer);
      offs.forEach((off) => off());
      document.removeEventListener("visibilitychange", onVisibility);
    };
  }, [config.dashboardUrl, config.nonce, config.channels]);

  const snapshot = useMemo<WooDashboardSnapshot>(
    () => ({ ...splitBaskets(rows, present), usersOnline, online }),
    [rows, present, usersOnline, online],
  );

  return { snapshot, stale };
}
