/**
 * ShopSocket: the screen staff keep open all day. Tiles for
 * what is happening in the store right now, then the live orders board.
 */
import { __, _n, sprintf } from "@wordpress/i18n";
import {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
} from "@wordpress/element";
import { Button, Notice, ToggleControl } from "@wordpress/components";
import { Badge, Link, Text } from "@wordpress/ui";
import { useLiveOrders, useStaffAlerts, useStockAlerts } from "./useLiveOrders";
import { useConnectionState } from "./useConnectionState";
import { useDashboardSnapshot } from "./useDashboardSnapshot";
import { productLabel, useProductNames } from "./useProductNames";
import { money } from "./format";
import { WeekStrip } from "./WeekStrip";
import { adminUrlOrNull } from "./trust";
import { LiveOrders } from "./LiveOrders";
import { playChime } from "./chime";
import { BoardTable, Section, Stat, productColumn, type Column } from "./ui";

const config: ShopSocketBoardConfig = window.shopSocket ?? {
  dashboardUrl: "",
  productsUrl: "",
  adminUrl: "",
  journeysUrl: "",
  nonce: "",
  orders: [],
  statuses: [],
  snapshot: { carts: [], abandoned: [], online: null },
  currencySymbol: "",
  channels: { orders: "", stock: "", presence: "", staff: "", connections: "" },
};

const SOUND_KEY = "shopsocket-sound";

/** The chime is on until this browser turns it off. */
function readSound(): boolean {
  try {
    const stored = window.localStorage.getItem(SOUND_KEY);
    return stored === null ? true : stored === "1";
  } catch {
    return true;
  }
}

/** The board: header controls, tiles, the stock strip, and the live orders table. */
export function Dashboard() {
  const [sound, setSound] = useState(readSound);
  const [fullscreen, setFullscreen] = useState(false);
  const soundRef = useRef(sound);
  soundRef.current = sound;

  const onNewOrder = useCallback(() => {
    if (soundRef.current) playChime();
  }, []);

  const { rows, reconcile } = useLiveOrders(
    config.orders,
    onNewOrder,
    config.channels,
  );
  const alerts = useStockAlerts(config.channels);
  const staffAlerts = useStaffAlerts(config.channels);
  /* One lookup for every alert's products, rather than a request per notice. */
  const alertProducts = useProductNames(
    useMemo(
      () =>
        Array.from(
          new Set(staffAlerts.alerts.flatMap((alert) => alert.products)),
        ).slice(0, LIVE_PRODUCTS),
      [staffAlerts.alerts],
    ),
    config,
  );
  const connection = useConnectionState();
  const { snapshot, stale } = useDashboardSnapshot(config, reconcile);

  useEffect(() => {
    try {
      window.localStorage.setItem(SOUND_KEY, sound ? "1" : "0");
    } catch {
      // Storage unavailable: the toggle still works for this page.
    }
  }, [sound]);

  useEffect(() => {
    document.body.classList.toggle("shopsocket-fullscreen", fullscreen);
    return () => document.body.classList.remove("shopsocket-fullscreen");
  }, [fullscreen]);

  return (
    <div className={`shopsocket-board${fullscreen ? " is-fullscreen" : ""}`}>
      {/* The page title and meta links are PHP-rendered above the board (`render_header()`). */}
      <div className="shopsocket-board__header">
        <div className="shopsocket-board__controls">
          <ConnectionBadge state={connection} />
          <ToggleControl
            label={__("Sound on new order", "shopsocket")}
            checked={sound}
            onChange={setSound}
          />
          <Button variant="secondary" onClick={() => setFullscreen((f) => !f)}>
            {fullscreen
              ? __("Exit full screen", "shopsocket")
              : __("Full screen", "shopsocket")}
          </Button>
        </div>
      </div>

      {staffAlerts.alerts.map((alert) => (
        <AlertNotice
          key={alert.visitor}
          alert={alert}
          name={(id) => alertProducts(id)?.name ?? `#${id}`}
          onDismiss={() => staffAlerts.dismiss(alert.visitor)}
        />
      ))}

      <RightNow snapshot={snapshot} stale={stale} />
      <WeekStrip strip={snapshot.strip} config={config} />
      <AbandonedPanel
        rows={snapshot.abandoned}
        currencySymbol={config.currencySymbol}
      />

      {alerts.length > 0 && (
        <Notice
          status="warning"
          isDismissible={false}
          className="shopsocket-board__stock"
        >
          <strong>{__("Stock", "shopsocket")}:</strong>{" "}
          {alerts.map((a) => (
            <span
              key={`${a.product_id}-${a.variation_id}`}
              className={`shopsocket-stock-alert is-${a.kind}`}
            >
              {a.kind === "out"
                ? sprintf(
                    /* translators: %s: product name */ __(
                      "%s is out of stock",
                      "shopsocket",
                    ),
                    a.name,
                  )
                : sprintf(
                    /* translators: 1: product name, 2: quantity */
                    __("%1$s is low (%2$d left)", "shopsocket"),
                    a.name,
                    a.stock_quantity ?? 0,
                  )}
            </span>
          ))}
        </Notice>
      )}

      <Section
        title={__("Live orders", "shopsocket")}
        className="shopsocket-board__orders"
        aside={
          <Badge intent="none">
            {sprintf(
              /* translators: %d: number of orders on the board */ _n(
                "%d order",
                "%d orders",
                rows.length,
                "shopsocket",
              ),
              rows.length,
            )}
          </Badge>
        }
        lede={__(
          "New orders appear here the moment they are placed.",
          "shopsocket",
        )}
      >
        <LiveOrders
          rows={rows}
          statuses={config.statuses}
          currencySymbol={config.currencySymbol}
          adminUrl={config.adminUrl}
        />
      </Section>
    </div>
  );
}

type CartSegment = WooDashboardSnapshot["carts"]["live"];

/** "Right now": shoppers on the site, open tabs, the carts split by where their shopper is, and the products in live carts. */
function RightNow({
  snapshot,
  stale,
}: {
  snapshot: WooDashboardSnapshot;
  stale: boolean;
}) {
  const { carts, usersOnline, online, staff, awayUsers } = snapshot;
  /* translators: %d: how many of the figure above are staff (people or tabs) */
  const staffNote = (count: number) =>
    count > 0
      ? ", " + sprintf(__("%d of them staff", "shopsocket"), count)
      : "";
  /* translators: %d: how many shoppers have every tab hidden */
  const awayNote = (count: number) =>
    count > 0
      ? ", " + sprintf(__("%d with the tab hidden", "shopsocket"), count)
      : "";
  const cartDetail = (segment: CartSegment) => (
    <>
      {sprintf(
        /* translators: %d: products in those carts */
        _n("%d product", "%d products", segment.products, "shopsocket"),
        segment.products,
      )}
      {", "}
      <span className="shopsocket-stat__money">
        {segment.currency
          ? money(segment.revenue, segment.currency)
          : `${config.currencySymbol}0`}
      </span>
    </>
  );
  return (
    <Section
      title={__("Right now", "shopsocket")}
      className={stale ? "is-stale" : undefined}
      lede={__(
        "Who is in your store this minute and what their carts hold.",
        "shopsocket",
      )}
    >
      <div className="shopsocket-stats" aria-live="polite">
        <Stat
          name="users"
          value={String(usersOnline)}
          label={__("Shoppers on the site", "shopsocket")}
          detail={
            __("Different people browsing now", "shopsocket") +
            staffNote(staff.users) +
            awayNote(awayUsers)
          }
        />
        <Stat
          name="live"
          value={String(carts.live.shoppers)}
          label={__("Carts in use", "shopsocket")}
          detail={cartDetail(carts.live)}
        />
        <Stat
          name="away"
          value={String(carts.away.shoppers)}
          label={__("Carts on a hidden tab", "shopsocket")}
          detail={cartDetail(carts.away)}
        />
        <Stat
          name="abandoned"
          value={String(carts.abandoned.shoppers)}
          label={__("Carts left behind", "shopsocket")}
          detail={cartDetail(carts.abandoned)}
        />
        <Stat
          name="connections"
          value={online ? String(online.active_connections) : "\u2014"}
          label={__("Open tabs", "shopsocket")}
          detail={
            online
              ? sprintf(
                  /* translators: 1: open tabs, 2: plan connection limit */
                  __("%1$d of %2$d on your plan", "shopsocket"),
                  online.active_connections,
                  online.max_connections,
                ) + staffNote(staff.tabs)
              : __("Browser tabs connected to your store", "shopsocket")
          }
        />
      </div>
      <Text variant="heading-md" render={<h3 />} className="shopsocket-subhead">
        {__("Products in carts right now", "shopsocket")}
      </Text>
      <LiveProductsPanel products={snapshot.liveProducts} />
    </Section>
  );
}

const LIVE_PRODUCTS = 100;

/** A row's id as its table key. */
const byId = (row: { id: number | string }) => String(row.id);

/** Products in live carts, most held first, at most LIVE_PRODUCTS of them, each linking to its edit screen. */
function LiveProductsPanel({
  products,
}: {
  products: WooDashboardSnapshot["liveProducts"];
}) {
  // Rank by live carts before asking for names, so the fetch stays bounded.
  const top = useMemo(
    () =>
      [...products]
        .sort((a, b) => b.carts - a.carts || b.units - a.units || a.id - b.id)
        .slice(0, LIVE_PRODUCTS),
    [products],
  );
  const ref = useProductNames(
    useMemo(() => top.map((p) => p.id), [top]),
    config,
  );
  const rows = useMemo(
    () =>
      [...top].sort((a, b) => {
        if (a.carts !== b.carts) return b.carts - a.carts;
        if (a.units !== b.units) return b.units - a.units;
        const an = ref(a.id)?.name;
        const bn = ref(b.id)?.name;
        if (an && bn) return an.localeCompare(bn);
        if (an || bn) return an ? -1 : 1;
        return a.id - b.id;
      }),
    [top, ref],
  );

  const columns = useMemo<Column<(typeof rows)[number]>[]>(
    () => [
      productColumn(ref, config.adminUrl),
      {
        id: "carts",
        label: __("Carts", "shopsocket"),
        value: (row) => row.carts,
        numeric: true,
      },
      {
        id: "units",
        label: __("Units", "shopsocket"),
        value: (row) => row.units,
        numeric: true,
      },
    ],
    [ref],
  );
  return (
    <BoardTable
      className="shopsocket-live-products"
      rows={rows}
      rowId={byId}
      empty={__(
        "No shopper with a cart is on the site right now.",
        "shopsocket",
      )}
      columns={columns}
    />
  );
}

/** When a cart was left, in the browser's locale, with the date only when it is not today. */
function leftAt(iso: string): string {
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return iso;
  const today = new Date().toDateString() === date.toDateString();
  return new Intl.DateTimeFormat(
    undefined,
    today
      ? { timeStyle: "short" }
      : { dateStyle: "medium", timeStyle: "short" },
  ).format(date);
}

/**
 * Carts whose shopper left and is not back, newest first: what they held, who
 * they are when logged in, the value, when, and the order when one followed. The historical record next to the live
 * split above; with history off it empties as sessions expire.
 */
function AbandonedPanel({
  rows,
  currencySymbol,
}: {
  rows: WooAbandonedRow[];
  currencySymbol: string;
}) {
  const ids = useMemo(
    () =>
      Array.from(new Set(rows.flatMap((r) => r.products))).slice(
        0,
        LIVE_PRODUCTS,
      ),
    [rows],
  );
  const ref = useProductNames(ids, config);
  const columns = useMemo<Column<WooAbandonedRow>[]>(() => {
    const products = (row: WooAbandonedRow) =>
      row.products
        .map(
          (id) =>
            `${productLabel(ref(id), id)}${(row.quantities?.[id] ?? 1) > 1 ? ` \u00d7${row.quantities[id]}` : ""}`,
        )
        .join(", ");
    return [
      { id: "products", label: __("Cart", "shopsocket"), value: products },
      {
        id: "shopper",
        label: __("Shopper", "shopsocket"),
        value: (row) => row.customer || __("Guest", "shopsocket"),
        render: (row) => {
          if (!row.customer)
            return (
              <span className="shopsocket-muted">
                {__("Guest", "shopsocket")}
              </span>
            );
          const profile = row.customer_url
            ? adminUrlOrNull(row.customer_url, config.adminUrl)
            : null;
          return profile ? (
            <Link href={profile}>{row.customer}</Link>
          ) : (
            row.customer
          );
        },
      },
      {
        id: "value",
        label: __("Value", "shopsocket"),
        value: (row) => row.value,
        render: (row) => money(row.value, row.currency || currencySymbol),
        numeric: true,
      },
      {
        id: "left",
        label: __("Left", "shopsocket"),
        value: (row) => row.left_at,
        render: (row) => <span title={row.left_at}>{leftAt(row.left_at)}</span>,
      },
      {
        id: "order",
        label: __("Ordered later", "shopsocket"),
        value: (row) => row.order_id ?? 0,
        render: (row) => {
          const href = row.order_url
            ? adminUrlOrNull(row.order_url, config.adminUrl)
            : null;
          const label = row.order_id ? `#${row.order_id}` : "";
          return href ? <Link href={href}>{label}</Link> : label;
        },
      },
    ];
  }, [ref, currencySymbol]);
  return (
    <Section
      title={__("Recently abandoned", "shopsocket")}
      lede={__(
        "Shoppers who left with something in their cart and have not come back, newest first.",
        "shopsocket",
      )}
    >
      <BoardTable
        className="shopsocket-abandoned"
        rows={rows}
        rowId={byId}
        empty={__("No shopper has left a cart behind recently.", "shopsocket")}
        columns={columns}
      />
    </Section>
  );
}

/** "Right now": a shopper a rule asked staff to look at, with their cart, where they are and who they are when logged in. */
function AlertNotice({
  alert,
  name,
  onDismiss,
}: {
  alert: WooAlertEvent;
  name: (id: number) => string;
  onDismiss: () => void;
}) {
  const names = alert.products.map(name).join(", ");
  return (
    <Notice
      status="info"
      className="shopsocket-board__alert"
      onRemove={onDismiss}
    >
      <strong>
        {alert.message || __("A shopper may need a hand", "shopsocket")}
      </strong>{" "}
      {alert.customer
        ? alert.step
          ? sprintf(
              /* translators: 1: the shopper's name, 2: where they are (a page kind), 3: the cart's products, 4: the cart's value */
              __("%1$s is on %2$s with %3$s (%4$s).", "shopsocket"),
              alert.customer,
              alert.step,
              names || __("an empty cart", "shopsocket"),
              money(alert.value, alert.currency),
            )
          : sprintf(
              /* translators: 1: the shopper's name, 2: the cart's products, 3: the cart's value */
              __("%1$s has %2$s in their cart (%3$s).", "shopsocket"),
              alert.customer,
              names || __("nothing", "shopsocket"),
              money(alert.value, alert.currency),
            )
        : alert.step
          ? sprintf(
              /* translators: 1: where the shopper is (a page kind), 2: the cart's products, 3: the cart's value */
              __("On %1$s with %2$s (%3$s).", "shopsocket"),
              alert.step,
              names || __("an empty cart", "shopsocket"),
              money(alert.value, alert.currency),
            )
          : sprintf(
              /* translators: 1: the cart's products, 2: the cart's value */
              __("Cart: %1$s (%2$s).", "shopsocket"),
              names || __("empty", "shopsocket"),
              money(alert.value, alert.currency),
            )}
    </Notice>
  );
}

/** The WordSocket connection in a word, with the retry countdown while down. */
function ConnectionBadge({ state }: { state: WPSConnectionState | null }) {
  if (!state) {
    return (
      <span className="shopsocket-conn is-off">
        {__("Realtime client not loaded", "shopsocket")}
      </span>
    );
  }
  if (state.connected) {
    return (
      <span className="shopsocket-conn is-on">{__("Live", "shopsocket")}</span>
    );
  }
  if (state.error?.code === "authentication-failed") {
    return (
      <span className="shopsocket-conn is-off">
        {__("Not delivering: reconnect WordSocket", "shopsocket")}
      </span>
    );
  }
  if (state.retryInMs) {
    return (
      <span className="shopsocket-conn is-off">
        {sprintf(
          /* translators: %d: seconds */
          __("Reconnecting in %ds", "shopsocket"),
          Math.ceil(state.retryInMs / 1000),
        )}
      </span>
    );
  }
  return (
    <span className="shopsocket-conn is-off">
      {__("Connecting", "shopsocket")}
    </span>
  );
}
