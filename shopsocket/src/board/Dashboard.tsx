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
import { useLiveOrders, useStaffAlerts, useStockAlerts } from "./useLiveOrders";
import { useConnectionState } from "./useConnectionState";
import { useDashboardSnapshot } from "./useDashboardSnapshot";
import { useProductNames } from "./useProductNames";
import { money } from "./format";
import { WeekStrip } from "./WeekStrip";
import { adminUrlOrNull } from "./trust";
import { LiveOrders } from "./LiveOrders";
import { playChime } from "./chime";

const config: ShopSocketBoardConfig = window.shopSocket ?? {
  dashboardUrl: "",
  productsUrl: "",
  adminUrl: "",
  historyUrl: "",
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

  const { rows, reconcile } = useLiveOrders(config.orders, onNewOrder, config.channels);
  const alerts = useStockAlerts(config.channels);
  const staffAlerts = useStaffAlerts(config.channels);
  /* One lookup for every alert's products, rather than a request per notice. */
  const alertProducts = useProductNames(
    useMemo(
      () => Array.from(new Set(staffAlerts.alerts.flatMap((alert) => alert.products))).slice(0, LIVE_PRODUCTS),
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

      <WeekStrip strip={snapshot.strip} config={config} />
      <Tiles snapshot={snapshot} stale={stale} />
      <LiveProductsPanel products={snapshot.liveProducts} />
      <AbandonedPanel rows={snapshot.abandoned} currencySymbol={config.currencySymbol} />

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

      <section
        className="shopsocket-board__orders"
        aria-labelledby="shopsocket-orders-heading"
      >
        <div className="shopsocket-board__orders-head">
          <h2 id="shopsocket-orders-heading">
            {__("Live orders", "shopsocket")}
          </h2>
          <span className="shopsocket-board__orders-count">
            {sprintf(
              /* translators: %d: number of orders on the board */ _n(
                "%d order",
                "%d orders",
                rows.length,
                "shopsocket",
              ),
              rows.length,
            )}
          </span>
        </div>
        <LiveOrders
          rows={rows}
          statuses={config.statuses}
          currencySymbol={config.currencySymbol}
          adminUrl={config.adminUrl}
        />
      </section>
    </div>
  );
}

type CartSegment = WooDashboardSnapshot["carts"]["live"];

/** The "right now" figures: users online, open connections, and the carts panel. */
function Tiles({
  snapshot,
  stale,
}: {
  snapshot: WooDashboardSnapshot;
  stale: boolean;
}) {
  const { carts, usersOnline, online, staff, awayUsers } = snapshot;
  /* translators: %d: how many of the figure above are staff (people or tabs) */
  const staffNote = (count: number) => (count > 0 ? " " + sprintf(__("(%d staff)", "shopsocket"), count) : "");
  /* translators: %d: how many of the figure above have every tab hidden */
  const awayNote = (count: number) => (count > 0 ? " " + sprintf(__("(%d away)", "shopsocket"), count) : "");
  return (
    <div
      className={`shopsocket-summary${stale ? " is-stale" : ""}`}
      aria-live="polite"
    >
      <div className="shopsocket-tiles">
        <div className="shopsocket-tile is-users">
          <span className="shopsocket-tile__value">{String(usersOnline)}</span>
          <span className="shopsocket-tile__label">
            {__("Storefront Customers", "shopsocket")}
          </span>
          <span className="shopsocket-tile__detail">
            {__("Unique visitors on the storefront right now", "shopsocket") + staffNote(staff.users) + awayNote(awayUsers)}
          </span>
        </div>
        <div className="shopsocket-tile is-connections">
          <span className="shopsocket-tile__value">
            {online ? String(online.active_connections) : "\u2014"}
          </span>
          <span className="shopsocket-tile__label">
            {__("Open Tabs", "shopsocket")}
          </span>
          <span className="shopsocket-tile__detail">
            {online
              ? sprintf(
                  /* translators: %d: plan connection limit */
                  __(
                    "All browser tabs, of %d/%d on your plan",
                    "shopsocket",
                  ),
                  online.active_connections,
                  online.max_connections,
                ) + staffNote(staff.tabs)
              : __(
                  "Connections between your storefront and wpsignal.io",
                  "shopsocket",
                )}
          </span>
        </div>
      </div>
      <CartsPanel live={carts.live} away={carts.away} abandoned={carts.abandoned} />
    </div>
  );
}

/** Customers, products, and revenue, split into live, away and abandoned carts. */
function CartsPanel({ live, away, abandoned }: { live: CartSegment; away: CartSegment; abandoned: CartSegment }) {
  return (
    <div className="shopsocket-carts">
      <table>
        <thead>
          <tr>
            <th scope="col"></th>
            <th scope="col" className="is-live">
              {__("Live", "shopsocket")}
              <span className="shopsocket-carts__hint">{__("shopper here now", "shopsocket")}</span>
            </th>
            <th scope="col" className="is-away">
              {__("Away", "shopsocket")}
              <span className="shopsocket-carts__hint">{__("here, tab hidden", "shopsocket")}</span>
            </th>
            <th scope="col" className="is-abandoned">
              {__("Abandoned", "shopsocket")}
              <span className="shopsocket-carts__hint">{__("left, cart still held", "shopsocket")}</span>
            </th>
          </tr>
        </thead>
        <tbody>
          <CartRow
            label={__("Customers", "shopsocket")}
            live={String(live.shoppers)}
            away={String(away.shoppers)}
            abandoned={String(abandoned.shoppers)}
          />
          <CartRow
            label={__("Products", "shopsocket")}
            live={String(live.products)}
            away={String(away.products)}
            abandoned={String(abandoned.products)}
          />
          <CartRow
            label={__("Revenue", "shopsocket")}
            live={money(live.revenue, live.currency)}
            away={money(away.revenue, away.currency)}
            abandoned={money(abandoned.revenue, abandoned.currency)}
          />
        </tbody>
      </table>
    </div>
  );
}

const LIVE_PRODUCTS = 100;

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
        .sort(
          (a, b) => b.carts - a.carts || b.units - a.units || a.id - b.id,
        )
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

  return (
    <section
      className="shopsocket-live-products"
      aria-labelledby="shopsocket-live-products-heading"
    >
      <h2 id="shopsocket-live-products-heading">
        {__("Products in live carts", "shopsocket")}
      </h2>
      {rows.length === 0 ? (
        <p className="shopsocket-board__empty">
          {__(
            "No shopper with a cart is on the site right now.",
            "shopsocket",
          )}
        </p>
      ) : (
        <table>
          <thead>
            <tr>
              <th scope="col">{__("Product", "shopsocket")}</th>
              <th scope="col" className="shopsocket-live-products__count">
                {__("Live carts", "shopsocket")}
              </th>
              <th scope="col" className="shopsocket-live-products__count">
                {__("Units", "shopsocket")}
              </th>
            </tr>
          </thead>
          <tbody>
            {rows.map(({ id, carts, units }) => {
              const product = ref(id);
              const label = product
                ? product.name
                : product === null
                  ? sprintf(
                      /* translators: %d: product id */ __(
                        "Product #%d (removed)",
                        "shopsocket",
                      ),
                      id,
                    )
                  : `#${id}`;
              const href = product
                ? adminUrlOrNull(product.edit_url, config.adminUrl)
                : null;
              return (
                <tr key={id}>
                  <th scope="row">
                    {href ? <a href={href}>{label}</a> : label}
                  </th>
                  <td className="shopsocket-live-products__count">
                    {String(carts)}
                  </td>
                  <td className="shopsocket-live-products__count">
                    {String(units)}
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      )}
    </section>
  );
}

/** When a cart was left, in the browser's locale, with the date only when it is not today. */
function leftAt(iso: string): string {
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return iso;
  const today = new Date().toDateString() === date.toDateString();
  return new Intl.DateTimeFormat(undefined, today ? { timeStyle: "short" } : { dateStyle: "medium", timeStyle: "short" }).format(date);
}

/**
 * Carts whose shopper left and is not back, newest first: what they held, who
 * they are when logged in, the value, when, and the order when one followed. The historical record next to the live
 * split above; with history off it empties as sessions expire.
 */
function AbandonedPanel({ rows, currencySymbol }: { rows: WooAbandonedRow[]; currencySymbol: string }) {
  const ids = useMemo(() => Array.from(new Set(rows.flatMap((r) => r.products))).slice(0, LIVE_PRODUCTS), [rows]);
  const ref = useProductNames(ids, config);
  const name = (id: number) => {
    const product = ref(id);
    if (product) return product.name;
    /* translators: %d: product id */
    return product === null ? sprintf(__("Product #%d (removed)", "shopsocket"), id) : `#${id}`;
  };
  return (
    <section className="shopsocket-live-products shopsocket-abandoned" aria-labelledby="shopsocket-abandoned-heading">
      <h2 id="shopsocket-abandoned-heading">{__("Recently abandoned", "shopsocket")}</h2>
      {rows.length === 0 ? (
        <p className="shopsocket-board__empty">{__("No shopper has left a cart behind recently.", "shopsocket")}</p>
      ) : (
        <table>
          <thead>
            <tr>
              <th scope="col">{__("Products", "shopsocket")}</th>
              <th scope="col">{__("Shopper", "shopsocket")}</th>
              <th scope="col" className="shopsocket-live-products__count">{__("Value", "shopsocket")}</th>
              <th scope="col" className="shopsocket-live-products__count">{__("Left", "shopsocket")}</th>
              <th scope="col" className="shopsocket-live-products__count">{__("Order", "shopsocket")}</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => {
              const href = row.order_url ? adminUrlOrNull(row.order_url, config.adminUrl) : null;
              const label = row.order_id ? `#${row.order_id}` : "";
              const profile = row.customer_url ? adminUrlOrNull(row.customer_url, config.adminUrl) : null;
              return (
                <tr key={row.id} data-cart={row.id} data-journey={row.journey} data-user={row.user_id ?? ""}>
                  <th scope="row" title={row.journey}>
                    {row.products.map((id) => `${name(id)}${(row.quantities?.[id] ?? 1) > 1 ? ` \u00d7${row.quantities[id]}` : ""}`).join(", ")}
                  </th>
                  <td>
                    {row.customer ? (
                      profile ? <a href={profile}>{row.customer}</a> : row.customer
                    ) : (
                      <span className="shopsocket-abandoned__guest">{__("Guest", "shopsocket")}</span>
                    )}
                  </td>
                  <td className="shopsocket-live-products__count">{money(row.value, row.currency || currencySymbol)}</td>
                  <td className="shopsocket-live-products__count" title={row.left_at}>{leftAt(row.left_at)}</td>
                  <td className="shopsocket-live-products__count">{href ? <a href={href}>{label}</a> : label}</td>
                </tr>
              );
            })}
          </tbody>
        </table>
      )}
    </section>
  );
}

/** "Right now": a shopper a rule asked staff to look at, with their cart, where they are and who they are when logged in. */
function AlertNotice({ alert, name, onDismiss }: { alert: WooAlertEvent; name: (id: number) => string; onDismiss: () => void }) {
  const names = alert.products.map(name).join(", ");
  return (
    <Notice status="info" className="shopsocket-board__alert" onRemove={onDismiss}>
      <strong>{alert.message || __("A shopper may need a hand", "shopsocket")}</strong>{" "}
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

/** One row of the carts table. */
function CartRow({ label, live, away, abandoned }: { label: string; live: string; away: string; abandoned: string }) {
  return (
    <tr>
      <th scope="row">{label}</th>
      <td className="is-live">{live}</td>
      <td className="is-away">{away}</td>
      <td className="is-abandoned">{abandoned}</td>
    </tr>
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
