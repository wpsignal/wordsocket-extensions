/**
 * The dashboard figures. Cart rows come from WordPress (polled, pushed on
 * change); whether a cart is live comes from relay presence on the carts
 * channel, so the split follows arrivals and departures as they happen. The
 * two are crossed here by cart id. "Online now" is the relay's own count.
 */
import { useEffect, useMemo, useRef, useState } from "@wordpress/element";

/**
 * Internal dependencies.
 */
import { openStep } from "./journey";
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

/** An hour, in milliseconds. */
const HOUR_MS = 3_600_000;

type Config = Pick<
  ShopSocketBoardConfig,
  | "dashboardUrl"
  | "nonce"
  | "snapshot"
  | "channels"
  | "boardHours"
  | "abandonedHours"
>;

/*
 * A shopper's presence state: `v` and `b` are the visitor and cart ids, `j`
 * the journey WordSocket's tracker keeps (0.30), whose last step is `away`
 * while the tab is hidden.
 */
type Member = { v?: string; b?: string | null; j?: string };

/** The most of a journey string the board keeps, WordSocket's own limit on one. */
const JOURNEY_MAX = 4000;

/** A member's state as the board reads it: strings only, whatever the browser sent. */
function member(state: unknown): Member {
  const s = (state ?? {}) as Record<string, unknown>;
  return {
    ...(typeof s.v === "string" ? { v: s.v } : {}),
    b: typeof s.b === "string" ? s.b : null,
    j: typeof s.j === "string" ? s.j.slice(-JOURNEY_MAX) : "",
  };
}

/** Whether a member's tab is hidden: the journey's last step is `away`. */
function isAway(m: Member): boolean {
  const last = (m.j ?? "").split(">").pop() ?? "";
  return last.startsWith("away:");
}

/** A connected visitor before the cart row is joined on. */
type Roster = Omit<WooShopper, "cart">;

/*
 * One entry per visitor from the members in announce order, every tab of
 * theirs listed: a live visible tab leads over a live hidden one, and the last
 * announced among equals, so a navigation shows the new page the moment it
 * joins, hidden or not. A connection waiting out its leave grace is listed
 * only while the visitor has no other, since a navigation's old connection
 * would otherwise read as a second tab. Any tab's cart id serves, since a
 * visitor's tabs share the cart.
 */
function byVisitor(
  members: Map<string, Member>,
  leaving: Set<string>,
  staffVisitors: Set<string>,
): Roster[] {
  type Ranked = { tab: WooTab; rank: number; order: number };
  const grouped = new Map<string, { b: string | null; tabs: Ranked[] }>();
  let order = 0;
  members.forEach((m, key) => {
    if (!m.v) return;
    const away = isAway(m);
    const step = openStep(m.j ?? "");
    const group = grouped.get(m.v) ?? { b: null, tabs: [] };
    if (!group.b && m.b) group.b = m.b;
    group.tabs.push({
      tab: { key, kind: step.kind, id: step.id, j: m.j ?? "", away },
      rank: leaving.has(key) ? 0 : away ? 1 : 2,
      order: order++,
    });
    grouped.set(m.v, group);
  });
  return [...grouped.entries()].map(([v, { b, tabs: ranked }]) => {
    const live = ranked.filter((t) => t.rank > 0);
    const tabs = (live.length > 0 ? live : ranked)
      .sort((a, c) => c.rank - a.rank || c.order - a.order)
      .map((t) => t.tab);
    const lead = tabs[0];
    return {
      v,
      b,
      kind: lead.kind,
      id: lead.id,
      j: lead.j,
      tabs,
      away: tabs.every((t) => t.away),
      staff: staffVisitors.has(v),
    };
  });
}

/*
 * Cross the cart rows with presence into the carts of connected shoppers:
 * live (a visible tab) and away (every tab hidden).
 */
function splitCarts(
  rows: WooCartRow[],
  present: Set<string>,
  awayIds: Set<string>,
): Pick<WooDashboardSnapshot, "carts"> {
  const live = {
    shoppers: 0,
    products: new Set<number>(),
    revenue: 0,
    currency: "",
  };
  const away = {
    shoppers: 0,
    products: new Set<number>(),
    revenue: 0,
    currency: "",
  };
  for (const row of rows) {
    if (!present.has(row.id)) continue;
    const seg = awayIds.has(row.id) ? away : live;
    seg.shoppers += 1;
    seg.revenue += row.value;
    seg.currency = seg.currency || row.currency;
    row.products.forEach((id) => seg.products.add(id));
  }
  // Close a segment: distinct products, revenue rounded to cents.
  const finish = (s: typeof live): WooCartSegment => ({
    shoppers: s.shoppers,
    products: s.products.size,
    revenue: Math.round(s.revenue * 100) / 100,
    currency: s.currency,
  });
  return {
    carts: {
      live: finish(live),
      away: finish(away),
    },
  };
}

/*
 * The left carts the board lists: the shopper is not present (a present one is back, and a live or
 * away cart again), the leave is older than the grace, so a navigation never flashes a cart through
 * the list, and no older than the time frame.
 */
function recentlyAbandoned(
  rows: WooAbandonedRow[],
  present: Set<string>,
  now: number,
  windowMs: number,
): WooAbandonedRow[] {
  return rows.filter((row) => {
    const ago = now - Date.parse(row.left_at);
    return !present.has(row.id) && ago >= LEAVE_GRACE_MS && ago < windowMs;
  });
}

/** The carts among `rows` not ordered since they were left: how many, their distinct products and what they are worth. */
function unordered(rows: WooAbandonedRow[]): WooCartSegment {
  const open = rows.filter((row) => !row.order_id);
  return {
    shoppers: open.length,
    products: new Set(open.flatMap((row) => row.products)).size,
    revenue: open.reduce((sum, row) => sum + row.value, 0),
    currency: open[0]?.currency ?? "",
  };
}

/** The carts the server counted beyond the rows it listed whose shopper is present: how many, and what they were worth. */
function backBeyond(
  listed: WooAbandonedRow[],
  returning: WooAbandonedReturning,
  present: Set<string>,
  now: number,
  windowMs: number,
): { carts: number; value: number } {
  const rows = new Set(listed.map((row) => row.id));
  const back = { carts: 0, value: 0 };
  present.forEach((id) => {
    const cart = returning[id];
    if (!cart || rows.has(id) || now - Date.parse(cart.left_at) >= windowMs)
      return;
    back.carts += 1;
    back.value += cart.value;
  });
  return back;
}

/*
 * The carts left behind: those shown, plus what the server counted beyond the rows it listed, less
 * those of them whose shopper is `back`. A listed row that is not shown, its shopper back or its
 * leave within the grace, is not counted.
 */
function leftBehind(
  shown: WooAbandonedRow[],
  listed: WooAbandonedRow[],
  total: WooAbandonedTotals[string] | undefined,
  back: { carts: number; value: number },
): WooCartSegment {
  const seen = unordered(shown);
  const all = unordered(listed);
  const beyond = Math.max(0, (total?.carts ?? 0) - all.shoppers - back.carts);
  const worth =
    seen.revenue +
    (beyond ? Math.max(0, (total?.value ?? 0) - all.revenue - back.value) : 0);
  return {
    shoppers: seen.shoppers + beyond,
    products: beyond ? (total?.products ?? seen.products) : seen.products,
    revenue: Math.round(worth * 100) / 100,
    currency: seen.currency || total?.currency || "",
  };
}

/** The rows held with a relay frame's rows over them: a frame names the carts left in the last `boardHours` only. */
function withFrame(
  held: WooAbandonedRow[],
  frame: WooAbandonedRow[],
): WooAbandonedRow[] {
  const named = new Set(frame.map((row) => row.id));
  return [...frame, ...held.filter((row) => !named.has(row.id))].sort(
    (a, b) => Date.parse(b.left_at) - Date.parse(a.left_at),
  );
}

/** Called with the orders a refresh found changed: the orders, when the request left, and the `since` it asked for. */
type OnOrders = (
  orders: WooOrderEvent[],
  startedAt: number,
  since: number,
) => void;

/**
 * The board's tile figures, whether the last fetch of them failed, and the abandoned carts' time frame with its setter.
 *
 * @since 0.1.1
 * @since 0.8.0 Returns `hours` and `setHours`.
 *
 * @param config - The board configuration.
 * @param onOrders - A callback to call with the orders that changed since the last refresh.
 */
export function useDashboardSnapshot(config: Config, onOrders?: OnOrders) {
  const onOrdersRef = useRef(onOrders);
  onOrdersRef.current = onOrders;
  const [rows, setRows] = useState<WooCartRow[]>(config.snapshot.carts);
  const [abandoned, setAbandoned] = useState<WooAbandonedRow[]>(
    config.snapshot.abandoned ?? [],
  );
  const [totals, setTotals] = useState<WooAbandonedTotals>(
    config.snapshot.abandoned_totals ?? {},
  );
  const [returning, setReturning] = useState<WooAbandonedReturning>(
    config.snapshot.abandoned_returning ?? {},
  );
  const [abandonment, setAbandonment] = useState<WooAbandonment | null>(
    config.snapshot.abandonment ?? null,
  );
  /* The time frame chosen, and the one the rows held are of: they differ while a choice is being fetched. */
  const [hours, setHours] = useState(config.boardHours);
  const [heldHours, setHeldHours] = useState(config.boardHours);
  const hoursRef = useRef(hours);
  hoursRef.current = hours;
  const heldHoursRef = useRef(heldHours);
  heldHoursRef.current = heldHours;
  const scheduleRef = useRef<(ms: number) => void>();
  useEffect(() => {
    if (hours !== heldHoursRef.current) scheduleRef.current?.(0);
  }, [hours]);
  const windowMs = heldHours * HOUR_MS;
  const [awayUsers, setAwayUsers] = useState(0);
  const [awayIds, setAwayIds] = useState<Set<string>>(() => new Set());
  const [roster, setRoster] = useState<Roster[]>([]);
  // Re-evaluated when the next leave crosses the grace or a time frame, so a row comes and goes without a refresh.
  const [now, setNow] = useState(() => Date.now());
  useEffect(() => {
    const waits = abandoned
      .flatMap((row) => {
        const left = Date.parse(row.left_at) - Date.now();
        return [
          left + LEAVE_GRACE_MS,
          ...config.abandonedHours.map((frame) => left + frame * HOUR_MS),
        ];
      })
      .filter((ms) => ms > 0);
    if (waits.length === 0) return undefined;
    const timer = setTimeout(() => setNow(Date.now()), Math.min(...waits) + 50);
    return () => clearTimeout(timer);
  }, [abandoned, now, config.abandonedHours]);
  const [online, setOnline] = useState<WooDashboardData["online"]>(
    config.snapshot.online,
  );
  const [strip, setStrip] = useState<WooDayStrip | null>(
    config.snapshot.strip ?? null,
  );
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

    // Fetch the cart rows, connection count and changed orders, one request at a time; true when the time frame changed meanwhile.
    const refresh = async (): Promise<boolean> => {
      if (inflight) return false;
      inflight = true;
      const frame = hoursRef.current;
      try {
        const asked = since;
        const startedAt = Date.now();
        const url = new URL(config.dashboardUrl, window.location.href);
        if (asked !== undefined) url.searchParams.set("since", String(asked));
        url.searchParams.set("hours", String(frame));
        const fresh = await apiFetch<WooDashboardData>({
          url: url.toString(),
          headers: { "X-WP-Nonce": config.nonce },
        });
        if (alive) {
          setRows(fresh.carts);
          if (fresh.abandoned_totals) setTotals(fresh.abandoned_totals);
          if (fresh.abandoned_returning)
            setReturning(fresh.abandoned_returning);
          if (frame === hoursRef.current) {
            if (fresh.abandoned) setAbandoned(fresh.abandoned);
            if (fresh.abandonment !== undefined)
              setAbandonment(fresh.abandonment);
            setHeldHours(frame);
          }
          if (fresh.strip !== undefined) setStrip(fresh.strip);
          if (fresh.online) showOnline(fresh.online);
          else setOnline(fresh.online);
          setStale(false);
          if (fresh.as_of !== undefined) since = fresh.as_of;
          if (asked !== undefined && fresh.orders?.length)
            onOrdersRef.current?.(fresh.orders, startedAt, asked);
        }
      } catch {
        if (alive) setStale(true);
      } finally {
        inflight = false;
      }
      return frame !== hoursRef.current;
    };

    // Refresh after `ms`, then keep polling, at once when the time frame changed meanwhile; a new call replaces the pending one.
    const schedule = (ms: number) => {
      clearTimeout(timer);
      timer = setTimeout(() => {
        refresh().then((again) => alive && schedule(again ? 0 : POLL_MS));
      }, ms);
    };
    scheduleRef.current = schedule;

    schedule(POLL_MS);

    const wps = window.WPS;
    const offs: Array<() => void> = [];
    /*
     * One roster per presence channel: connection id -> state, with leaves
     * held for the grace so a navigation does not read as a departure. The
     * shoppers' roster carries { v: visitor id, b: cart id | null }; a
     * shopper's tabs share v, so distinct v is "users online" and distinct b
     * is the live-cart split. The staff roster carries { v } from every admin
     * and storefront page a staff member has open. The map's order is announce
     * order, which the per-visitor roster reads.
     */
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
          (p.members ?? []).forEach(
            (m) => m.id && members.set(m.id, member(m.state)),
          );
        } else if (p.action === "join" && p.id) {
          cancelLeave(p.id);
          // A re-announce moves the member to the end.
          members.delete(p.id);
          members.set(p.id, member(p.state));
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
      return { members, leaving: pendingLeaves, handle, dispose };
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
      setRoster(
        byVisitor(
          shoppers.members,
          new Set(shoppers.leaving.keys()),
          staffVisitors,
        ),
      );
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
          const payload = data as {
            carts?: WooCartRow[];
            abandoned?: WooAbandonedRow[];
            abandoned_totals?: WooAbandonedTotals;
            abandoned_returning?: WooAbandonedReturning;
          };
          if (Array.isArray(payload.carts)) {
            setRows(payload.carts);
            setStale(false);
          }
          if (payload.abandoned_totals) setTotals(payload.abandoned_totals);
          if (payload.abandoned_returning)
            setReturning(payload.abandoned_returning);
          const frame = payload.abandoned;
          if (Array.isArray(frame)) {
            setAbandoned((held) =>
              heldHoursRef.current === config.boardHours
                ? frame
                : withFrame(held, frame),
            );
          }
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
      offs.push(
        wps.onStateChange((s) => s.connected && schedule(AFTER_EVENT_MS)),
      );
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
  }, [
    config.dashboardUrl,
    config.nonce,
    config.channels,
    config.snapshot.as_of,
    config.boardHours,
  ]);

  /* The roster joined to the cart rows by cart id, kept while neither changes. */
  const byCart = useMemo(() => new Map(rows.map((row) => [row.id, row])), [rows]);
  const shoppers = useMemo(
    () =>
      roster.map((s) => ({
        ...s,
        cart: (s.b && byCart.get(s.b)) || null,
      })),
    [roster, byCart],
  );
  const snapshot = useMemo<WooDashboardSnapshot>(() => {
    const shown = recentlyAbandoned(abandoned, present, now, windowMs);
    const recovered = abandoned.filter(
      (row) => row.order_id && now - Date.parse(row.left_at) < windowMs,
    );
    return {
      ...splitCarts(rows, present, awayIds),
      usersOnline,
      staff: staffShare,
      awayUsers,
      abandonedHours: heldHours,
      abandoned: shown.filter((row) => !row.order_id),
      recovered,
      recoveredCount: Math.max(
        recovered.length,
        totals[String(heldHours)]?.recovered ?? 0,
      ),
      leftBehind: config.abandonedHours.map((frame) => {
        const ms = frame * HOUR_MS;
        const listed = abandoned.filter(
          (row) => now - Date.parse(row.left_at) < ms,
        );
        return {
          hours: frame,
          ...leftBehind(
            recentlyAbandoned(listed, present, now, ms),
            listed,
            totals[String(frame)],
            backBeyond(listed, returning, present, now, ms),
          ),
        };
      }),
      abandonment,
      online,
      strip,
      shoppers,
    };
  }, [
    rows,
    present,
    awayIds,
    usersOnline,
    staffShare,
    awayUsers,
    abandoned,
    abandonment,
    totals,
    returning,
    config.abandonedHours,
    heldHours,
    now,
    windowMs,
    online,
    strip,
    shoppers,
  ]);

  return { snapshot, stale, hours, setHours };
}
