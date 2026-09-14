/**
 * The dashboard figures. Basket contents come from WordPress (the rows: what is
 * in each cart, polled and pushed on change). Whether a basket is live or
 * abandoned comes from the relay's presence membership on the carts channel:
 * a shopper is present exactly while their tab holds an open connection, so the
 * split follows arrivals and departures the instant they happen. The two are
 * crossed here, by the shared basket id, into the live/abandoned segments the
 * panel renders. "Online now" is the relay's connection count.
 */
import { useEffect, useMemo, useState } from "@wordpress/element";
import apiFetch from "@wordpress/api-fetch";

const POLL_MS = 30_000;
const AFTER_EVENT_MS = 1_500;
const BASKETS_EVENT = "woo.baskets";
const CONNECTIONS_EVENT = "wps.connections";
const PRESENCE_EVENT = "wps.presence";

type Config = Pick<ShopSocketBoardConfig, "dashboardUrl" | "nonce" | "snapshot">;

/** Cross the basket rows with the set of present basket ids into two segments. */
function splitBaskets(rows: WooBasketRow[], present: Set<string>): WooDashboardSnapshot["baskets"] {
  const live = { shoppers: 0, products: new Set<number>(), revenue: 0, currency: "" };
  const abandoned = { shoppers: 0, products: new Set<number>(), revenue: 0, currency: "" };
  for (const row of rows) {
    const seg = present.has(row.id) ? live : abandoned;
    seg.shoppers += 1;
    seg.revenue += row.value;
    seg.currency = seg.currency || row.currency;
    row.products.forEach((id) => seg.products.add(id));
  }
  const finish = (s: typeof live): WooBasketSegment => ({
    shoppers: s.shoppers,
    products: s.products.size,
    revenue: Math.round(s.revenue * 100) / 100,
    currency: s.currency,
  });
  return { live: finish(live), abandoned: finish(abandoned) };
}

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

    const schedule = (ms: number) => {
      clearTimeout(timer);
      timer = setTimeout(() => {
        refresh().then(() => alive && schedule(POLL_MS));
      }, ms);
    };

    schedule(POLL_MS);

    const wps = window.WPS;
    const offs: Array<() => void> = [];
    // connection id -> { v: visitor id, b: basket id | null }. A shopper's tabs
    // share a visitor id, so distinct v is "users online" and distinct non-null b
    // (crossed with the basket rows) is the live-basket split.
    type Member = { v?: string; b?: string | null };
    const members = new Map<string, Member>();
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
      // Staff tokens auto-subscribe to the presence, baskets, and connections
      // channels (`wpsignal_token_channels`, inc/channels.php): only listen.
      offs.push(
        wps.on(PRESENCE_EVENT, (data) => {
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
        wps.on(BASKETS_EVENT, (data) => {
          const payload = data as { baskets?: WooBasketRow[] };
          if (Array.isArray(payload.baskets)) {
            setRows(payload.baskets);
            setStale(false);
          }
        }),
      );
      offs.push(
        wps.on(CONNECTIONS_EVENT, (data) => {
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
  }, [config.dashboardUrl, config.nonce]);

  const snapshot = useMemo<WooDashboardSnapshot>(
    () => ({ baskets: splitBaskets(rows, present), usersOnline, online }),
    [rows, present, usersOnline, online],
  );

  return { snapshot, stale };
}
