/**
 * The dashboard figures. Cart rows come from WordPress (polled, pushed on
 * change); whether a cart is live comes from relay presence on the carts
 * channel, so the split follows arrivals and departures as they happen. The
 * two are crossed here by cart id. "Online now" is the relay's own count.
 */
import { useEffect, useMemo, useRef, useState } from "@wordpress/element";
import apiFetch from "@wordpress/api-fetch";

const POLL_MS = 30_000;
const AFTER_EVENT_MS = 1_500;
const CARTS_EVENT = "woo.carts";
const CONNECTIONS_EVENT = "wps.connections";
const PRESENCE_EVENT = "wps.presence";
/*
 * A page navigation closes the shopper's socket before the next page has
 * connected and rejoined, so the relay's leave arrives a second or two ahead
 * of the join that reverses it. A leaving member is held this long first.
 */
const LEAVE_GRACE_MS = 6_000;

type Config = Pick<ShopSocketBoardConfig, "dashboardUrl" | "nonce" | "snapshot" | "channels">;

/*
 * Cross the cart rows with presence into three segments: live (a visible tab),
 * away (present, every tab hidden), abandoned (gone). Live carts per product
 * count the first two, since the shopper is still on the site.
 */
function splitCarts(
  rows: WooCartRow[],
  present: Set<string>,
  awayIds: Set<string>,
): Pick<WooDashboardSnapshot, "carts" | "liveProducts"> {
  const live = { shoppers: 0, products: new Set<number>(), revenue: 0, currency: "" };
  const away = { shoppers: 0, products: new Set<number>(), revenue: 0, currency: "" };
  const abandoned = { shoppers: 0, products: new Set<number>(), revenue: 0, currency: "" };
  const liveCounts = new Map<number, { carts: number; units: number }>();
  for (const row of rows) {
    const isLive = present.has(row.id);
    const seg = isLive ? (awayIds.has(row.id) ? away : live) : abandoned;
    seg.shoppers += 1;
    seg.revenue += row.value;
    seg.currency = seg.currency || row.currency;
    row.products.forEach((id) => {
      seg.products.add(id);
      if (isLive) {
        const count = liveCounts.get(id) ?? { carts: 0, units: 0 };
        count.carts += 1;
        count.units += Math.max(1, Number(row.quantities?.[id]) || 1);
        liveCounts.set(id, count);
      }
    });
  }
  // Close a segment: distinct products, revenue rounded to cents.
  const finish = (s: typeof live): WooCartSegment => ({
    shoppers: s.shoppers,
    products: s.products.size,
    revenue: Math.round(s.revenue * 100) / 100,
    currency: s.currency,
  });
  return {
    carts: { live: finish(live), away: finish(away), abandoned: finish(abandoned) },
    liveProducts: [...liveCounts].map(([id, count]) => ({ id, ...count })),
  };
}

/*
 * The left carts the panel shows: the shopper is not present (a present one
 * is back, and a live or away cart again) and the leave is older than the
 * grace, so a navigation never flashes a cart through the panel.
 */
function recentlyAbandoned(rows: WooAbandonedRow[], present: Set<string>, now: number): WooAbandonedRow[] {
  return rows.filter((row) => !present.has(row.id) && now - Date.parse(row.left_at) >= LEAVE_GRACE_MS);
}

/** Called with the orders a refresh found changed: the orders, when the request left, and the `since` it asked for. */
type OnOrders = (orders: WooOrderEvent[], startedAt: number, since: number) => void;

/** 
 * The board's tile figures, and whether the last fetch of them failed. 
 * 
 * @param config - The board configuration.
 * @param onOrders - A callback to call with the orders that changed since the last refresh.
 */
export function useDashboardSnapshot(config: Config, onOrders?: OnOrders) {
  const onOrdersRef = useRef(onOrders);
  onOrdersRef.current = onOrders;
  const [rows, setRows] = useState<WooCartRow[]>(config.snapshot.carts);
  const [abandoned, setAbandoned] = useState<WooAbandonedRow[]>(config.snapshot.abandoned ?? []);
  const [awayUsers, setAwayUsers] = useState(0);
  const [awayIds, setAwayIds] = useState<Set<string>>(() => new Set());
  // Re-evaluated when the youngest leave crosses the grace, so a row appears without a refresh.
  const [now, setNow] = useState(() => Date.now());
  useEffect(() => {
    const pending = abandoned.map((row) => Date.parse(row.left_at) + LEAVE_GRACE_MS - Date.now()).filter((ms) => ms > 0);
    if (pending.length === 0) return undefined;
    const timer = setTimeout(() => setNow(Date.now()), Math.min(...pending) + 50);
    return () => clearTimeout(timer);
  }, [abandoned, now]);
  const [online, setOnline] = useState<WooDashboardData["online"]>(config.snapshot.online);
  const [present, setPresent] = useState<Set<string>>(() => new Set());
  const [usersOnline, setUsersOnline] = useState(0);
  const [staffShare, setStaffShare] = useState({ users: 0, tabs: 0 });
  const [stale, setStale] = useState(false);

  useEffect(() => {
    let alive = true;
    let inflight = false;
    let timer: ReturnType<typeof setTimeout> | undefined;

    /*
     * Connections dip for a moment whenever a shopper navigates: the old
     * page's socket closes before the new page's opens. Hold a lower count
     * for the presence grace before showing it, so a navigation reads as a
     * steady tab rather than a departure and a return; a higher count lands
     * at once.
     */
    let shownConnections = config.snapshot.online?.active_connections ?? 0;
    let holdTimer: ReturnType<typeof setTimeout> | undefined;
    const showOnline = (o: NonNullable<WooDashboardData["online"]>) => {
      clearTimeout(holdTimer);
      if (o.active_connections < shownConnections) {
        holdTimer = setTimeout(() => {
          shownConnections = o.active_connections;
          setOnline(o);
        }, LEAVE_GRACE_MS);
        return;
      }
      shownConnections = o.active_connections;
      setOnline(o);
    };

    /*
     * The server's clock at the last successful read
     */
    let since = config.snapshot.as_of;

    // Fetch the cart rows, connection count and changed orders, one request at a time.
    const refresh = async () => {
      if (inflight) return;
      inflight = true;
      try {
        const asked = since;
        const startedAt = Date.now();
        const url = new URL(config.dashboardUrl, window.location.href);
        if (asked !== undefined) url.searchParams.set("since", String(asked));
        const fresh = await apiFetch<WooDashboardData>({
          url: url.toString(),
          headers: { "X-WP-Nonce": config.nonce },
        });
        if (alive) {
          setRows(fresh.carts);
          if (fresh.abandoned) setAbandoned(fresh.abandoned);
          if (fresh.online) showOnline(fresh.online);
          else setOnline(fresh.online);
          setStale(false);
          if (fresh.as_of !== undefined) since = fresh.as_of;
          if (asked !== undefined && fresh.orders?.length) onOrdersRef.current?.(fresh.orders, startedAt, asked);
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
     * One roster per presence channel: connection id -> state, with leaves
     * held for the grace so a navigation does not read as a departure. The
     * shoppers' roster carries { v: visitor id, b: cart id | null }; a
     * shopper's tabs share v, so distinct v is "users online" and distinct b
     * is the live-cart split. The staff roster carries { v } from every admin
     * and storefront page a staff member has open.
     */
    /*
 * A shopper's presence state: `v` and `b` are the visitor and cart ids, `j`
 * the journey WordSocket's tracker keeps (0.30), whose last step is `away`
 * while the tab is hidden.
 */
type Member = { v?: string; b?: string | null; j?: string };

/** Whether a member's tab is hidden: the journey's last step is `away`. */
function isAway(m: Member): boolean {
  const last = (m.j ?? "").split(">").pop() ?? "";
  return last.startsWith("away:");
}
    type PresenceFrame = {
      action?: string;
      id?: string;
      state?: Member;
      members?: Array<{ id: string; state?: Member }>;
    };
    const roster = (onChange: () => void) => {
      const members = new Map<string, Member>();
      // Leaves waiting out their grace period, by connection id.
      const pendingLeaves = new Map<string, ReturnType<typeof setTimeout>>();
      const cancelLeave = (id: string) => {
        const timer = pendingLeaves.get(id);
        if (timer !== undefined) {
          clearTimeout(timer);
          pendingLeaves.delete(id);
        }
      };
      const handle = (p: PresenceFrame) => {
        if (p.action === "sync") {
          // The relay's authoritative list: nothing is pending any more.
          pendingLeaves.forEach((timer) => clearTimeout(timer));
          pendingLeaves.clear();
          members.clear();
          (p.members ?? []).forEach((m) => m.id && members.set(m.id, m.state ?? {}));
        } else if (p.action === "join" && p.id) {
          cancelLeave(p.id);
          members.set(p.id, p.state ?? {});
        } else if (p.action === "leave" && p.id) {
          /*
           * Hold the member: if the same person is back on the next page
           * within the grace, the sets in applyPresent() dedupe the overlap
           * by visitor and cart id, so nothing flickers and nothing doubles.
           */
          const id = p.id;
          cancelLeave(id);
          pendingLeaves.set(
            id,
            setTimeout(() => {
              pendingLeaves.delete(id);
              members.delete(id);
              onChange();
            }, LEAVE_GRACE_MS),
          );
          return;
        } else {
          return;
        }
        onChange();
      };
      const dispose = () => {
        pendingLeaves.forEach((timer) => clearTimeout(timer));
        pendingLeaves.clear();
      };
      return { members, handle, dispose };
    };
    const shoppers = roster(() => applyPresent());
    const staff = roster(() => applyPresent());
    // Derive the live cart ids, the distinct visitor count, and the staff share of both figures.
    function applyPresent() {
      if (!alive) return;
      const carts = new Set<string>();
      const users = new Set<string>();
      // Away only when every tab of theirs is hidden: one visible tab is a present shopper.
      const activeUsers = new Set<string>();
      const activeCarts = new Set<string>();
      shoppers.members.forEach((m) => {
        if (m.b) carts.add(m.b);
        if (m.v) users.add(m.v);
        if (isAway(m)) return;
        if (m.b) activeCarts.add(m.b);
        if (m.v) activeUsers.add(m.v);
      });
      setAwayUsers(users.size - activeUsers.size);
      setAwayIds(new Set([...carts].filter((b) => !activeCarts.has(b))));
      const staffVisitors = new Set<string>();
      staff.members.forEach((m) => {
        if (m.v) staffVisitors.add(m.v);
      });
      let staffUsers = 0;
      users.forEach((v) => {
        if (staffVisitors.has(v)) staffUsers += 1;
      });
      setPresent(carts);
      setUsersOnline(users.size);
      setStaffShare({ users: staffUsers, tabs: staff.members.size });
    }

    if (wps) {
      /*
       * Staff tokens auto-subscribe to the presence, staff, carts, and
       * connections channels (`wpsignal_token_channels`, inc/channels.php):
       * only listen.
       */
      offs.push(
        wps.on(PRESENCE_EVENT, (data, channel) => {
          if (wps.onChannel(channel, config.channels.presence)) {
            shoppers.handle(data as PresenceFrame);
          } else if (wps.onChannel(channel, config.channels.staff)) {
            staff.handle(data as PresenceFrame);
          }
        }),
      );
      offs.push(
        wps.on(CARTS_EVENT, (data, channel) => {
          if (!wps.onChannel(channel, config.channels.orders)) return;
          const payload = data as { carts?: WooCartRow[]; abandoned?: WooAbandonedRow[] };
          if (Array.isArray(payload.carts)) {
            setRows(payload.carts);
            setStale(false);
          }
          if (Array.isArray(payload.abandoned)) setAbandoned(payload.abandoned);
        }),
      );
      offs.push(
        wps.on(CONNECTIONS_EVENT, (data, channel) => {
          if (!wps.onChannel(channel, config.channels.connections)) return;
          const o = data as unknown as NonNullable<WooDashboardData["online"]>;
          if (typeof o.active_connections === "number") {
            showOnline({ ...o });
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
      clearTimeout(holdTimer);
      shoppers.dispose();
      staff.dispose();
      offs.forEach((off) => off());
      document.removeEventListener("visibilitychange", onVisibility);
    };
  }, [config.dashboardUrl, config.nonce, config.channels, config.snapshot.as_of]);

  const snapshot = useMemo<WooDashboardSnapshot>(
    () => ({
      ...splitCarts(rows, present, awayIds),
      usersOnline,
      staff: staffShare,
      awayUsers,
      abandoned: recentlyAbandoned(abandoned, present, now),
      online,
    }),
    [rows, present, awayIds, usersOnline, staffShare, awayUsers, abandoned, now, online],
  );

  return { snapshot, stale };
}
