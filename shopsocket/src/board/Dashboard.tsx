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
import { useLiveOrders, useStockAlerts } from "./useLiveOrders";
import { useConnectionState } from "./useConnectionState";
import { useDashboardSnapshot } from "./useDashboardSnapshot";
import { useProductNames } from "./useProductNames";
import { adminUrlOrNull } from "./trust";
import { LiveOrders } from "./LiveOrders";
import { playChime } from "./chime";

const config: ShopSocketBoardConfig = window.shopSocket ?? {
  dashboardUrl: "",
  productsUrl: "",
  adminUrl: "",
  nonce: "",
  orders: [],
  statuses: [],
  snapshot: { baskets: [], online: null },
  currencySymbol: "",
  channels: { orders: "", stock: "", presence: "", connections: "" },
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

  const rows = useLiveOrders(config.orders, onNewOrder, config.channels);
  const alerts = useStockAlerts(config.channels);
  const connection = useConnectionState();
  const { snapshot, stale } = useDashboardSnapshot(config);

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

      <Tiles snapshot={snapshot} stale={stale} />
      <LiveProductsPanel products={snapshot.liveProducts} />

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

type BasketSegment = WooDashboardSnapshot["baskets"]["live"];

/** A whole-unit amount in the browser's locale, or a rounded number when the currency is unknown. */
function money(amount: number, currency: string): string {
  try {
    return new Intl.NumberFormat(undefined, {
      style: "currency",
      currency,
      maximumFractionDigits: 0,
    }).format(amount);
  } catch {
    return String(Math.round(amount));
  }
}

/** The "right now" figures: users online, open connections, and the baskets panel. */
function Tiles({
  snapshot,
  stale,
}: {
  snapshot: WooDashboardSnapshot;
  stale: boolean;
}) {
  const { baskets, usersOnline, online } = snapshot;
  return (
    <div
      className={`shopsocket-summary${stale ? " is-stale" : ""}`}
      aria-live="polite"
    >
      <div className="shopsocket-tiles">
        <div className="shopsocket-tile is-users">
          <span className="shopsocket-tile__value">{String(usersOnline)}</span>
          <span className="shopsocket-tile__label">
            {__("Users online", "shopsocket")}
          </span>
          <span className="shopsocket-tile__detail">
            {__("Unique visitors on the storefront right now", "shopsocket")}
          </span>
        </div>
        <div className="shopsocket-tile is-connections">
          <span className="shopsocket-tile__value">
            {online ? String(online.active_connections) : "\u2014"}
          </span>
          <span className="shopsocket-tile__label">
            {__("Open connections", "shopsocket")}
          </span>
          <span className="shopsocket-tile__detail">
            {online
              ? sprintf(
                  /* translators: %d: plan connection limit */
                  __(
                    "All browser connections, of %d on your plan",
                    "shopsocket",
                  ),
                  online.max_connections,
                )
              : __(
                  "Connections between your storefront and wpsignal.io",
                  "shopsocket",
                )}
          </span>
        </div>
      </div>
      <BasketsPanel live={baskets.live} abandoned={baskets.abandoned} />
    </div>
  );
}

/** Shoppers, products, and revenue, split into live and abandoned baskets. */
function BasketsPanel({
  live,
  abandoned,
}: {
  live: BasketSegment;
  abandoned: BasketSegment;
}) {
  return (
    <div className="shopsocket-baskets">
      <table>
        <thead>
          <tr>
            <th scope="col">{__("Baskets", "shopsocket")}</th>
            <th scope="col" className="is-live">
              {__("Live", "shopsocket")}
              <span className="shopsocket-baskets__hint">
                {__("shopper here now", "shopsocket")}
              </span>
            </th>
            <th scope="col" className="is-abandoned">
              {__("Abandoned", "shopsocket")}
              <span className="shopsocket-baskets__hint">
                {__("left, basket still held", "shopsocket")}
              </span>
            </th>
          </tr>
        </thead>
        <tbody>
          <BasketRow
            label={__("Shoppers", "shopsocket")}
            live={String(live.shoppers)}
            abandoned={String(abandoned.shoppers)}
          />
          <BasketRow
            label={__("Products", "shopsocket")}
            live={String(live.products)}
            abandoned={String(abandoned.products)}
          />
          <BasketRow
            label={__("Revenue", "shopsocket")}
            live={money(live.revenue, live.currency)}
            abandoned={money(abandoned.revenue, abandoned.currency)}
          />
        </tbody>
      </table>
    </div>
  );
}

const LIVE_PRODUCTS = 100;

/** Products in live baskets, most held first, at most LIVE_PRODUCTS of them, each linking to its edit screen. */
function LiveProductsPanel({
  products,
}: {
  products: WooDashboardSnapshot["liveProducts"];
}) {
  // Rank by live baskets before asking for names, so the fetch stays bounded.
  const top = useMemo(
    () =>
      [...products]
        .sort(
          (a, b) => b.baskets - a.baskets || b.units - a.units || a.id - b.id,
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
        if (a.baskets !== b.baskets) return b.baskets - a.baskets;
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
        {__("Products in live baskets", "shopsocket")}
      </h2>
      {rows.length === 0 ? (
        <p className="shopsocket-board__empty">
          {__(
            "No shopper with a basket is on the site right now.",
            "shopsocket",
          )}
        </p>
      ) : (
        <table>
          <thead>
            <tr>
              <th scope="col">{__("Product", "shopsocket")}</th>
              <th scope="col" className="shopsocket-live-products__count">
                {__("Live baskets", "shopsocket")}
              </th>
              <th scope="col" className="shopsocket-live-products__count">
                {__("Units", "shopsocket")}
              </th>
            </tr>
          </thead>
          <tbody>
            {rows.map(({ id, baskets, units }) => {
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
                    {String(baskets)}
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

/** One row of the baskets table. */
function BasketRow({
  label,
  live,
  abandoned,
}: {
  label: string;
  live: string;
  abandoned: string;
}) {
  return (
    <tr>
      <th scope="row">{label}</th>
      <td className="is-live">{live}</td>
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
