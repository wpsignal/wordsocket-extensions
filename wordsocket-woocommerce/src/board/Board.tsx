/**
 * Live Orders: the screen staff keep open all day.
 */
import { __, sprintf } from "@wordpress/i18n";
import { useCallback, useEffect, useRef, useState } from "@wordpress/element";
import { Button, Notice, ToggleControl } from "@wordpress/components";
import { useLiveOrders, useStockAlerts, type OrderRow } from "./useLiveOrders";
import { useConnectionState } from "./useConnectionState";
import { playChime } from "./chime";

const config: WordSocketWooBoardConfig = window.wordsocketWoo ?? {
  restUrl: "",
  nonce: "",
  orders: [],
  statuses: [],
  currencySymbol: "",
  soundDefault: true,
};

const SOUND_KEY = "wordsocket-woo-sound";

function readSound(): boolean {
  try {
    const stored = window.localStorage.getItem(SOUND_KEY);
    return stored === null ? config.soundDefault : stored === "1";
  } catch {
    return config.soundDefault;
  }
}

function formatMoney(amount: number, currency: string): string {
  try {
    return new Intl.NumberFormat(undefined, { style: "currency", currency }).format(amount);
  } catch {
    return `${config.currencySymbol}${amount.toFixed(2)}`;
  }
}

function statusLabel(slug: string): string {
  return config.statuses.find((s) => s.slug === slug)?.label ?? slug;
}

function timeOf(row: OrderRow): string {
  const when = row.created_at ? new Date(row.created_at) : null;
  return when ? when.toLocaleTimeString(undefined, { hour: "2-digit", minute: "2-digit" }) : "";
}

export function Board() {
  const [sound, setSound] = useState(readSound);
  const [fullscreen, setFullscreen] = useState(false);
  const soundRef = useRef(sound);
  soundRef.current = sound;

  const onNewOrder = useCallback(() => {
    if (soundRef.current) playChime();
  }, []);

  const rows = useLiveOrders(config.orders, onNewOrder);
  const alerts = useStockAlerts();
  const connection = useConnectionState();

  useEffect(() => {
    try {
      window.localStorage.setItem(SOUND_KEY, sound ? "1" : "0");
    } catch {
      // Storage unavailable: the toggle still works for this page.
    }
  }, [sound]);

  useEffect(() => {
    document.body.classList.toggle("wordsocket-woo-fullscreen", fullscreen);
    return () => document.body.classList.remove("wordsocket-woo-fullscreen");
  }, [fullscreen]);

  return (
    <div className={`wordsocket-woo-board${fullscreen ? " is-fullscreen" : ""}`}>
      <header className="wordsocket-woo-board__header">
        <h1>{__("Live Orders", "wordsocket-woocommerce")}</h1>
        <div className="wordsocket-woo-board__controls">
          <ConnectionBadge state={connection} />
          <ToggleControl
            __nextHasNoMarginBottom
            label={__("Sound on new order", "wordsocket-woocommerce")}
            checked={sound}
            onChange={setSound}
          />
          <Button variant="secondary" onClick={() => setFullscreen((f) => !f)}>
            {fullscreen ? __("Exit full screen", "wordsocket-woocommerce") : __("Full screen", "wordsocket-woocommerce")}
          </Button>
        </div>
      </header>

      {alerts.length > 0 && (
        <Notice status="warning" isDismissible={false} className="wordsocket-woo-board__stock">
          <strong>{__("Stock", "wordsocket-woocommerce")}:</strong>{" "}
          {alerts.map((a) => (
            <span key={`${a.product_id}-${a.variation_id}`} className={`wordsocket-woo-stock-alert is-${a.kind}`}>
              {a.kind === "out"
                ? sprintf(/* translators: %s: product name */ __("%s is out of stock", "wordsocket-woocommerce"), a.name)
                : sprintf(
                    /* translators: 1: product name, 2: quantity */
                    __("%1$s is low (%2$d left)", "wordsocket-woocommerce"),
                    a.name,
                    a.stock_quantity ?? 0,
                  )}
            </span>
          ))}
        </Notice>
      )}

      {rows.length === 0 ? (
        <p className="wordsocket-woo-board__empty">
          {__("No orders yet. New orders appear here the moment they are placed.", "wordsocket-woocommerce")}
        </p>
      ) : (
        <table className="wordsocket-woo-board__table widefat striped">
          <thead>
            <tr>
              <th>{__("Order", "wordsocket-woocommerce")}</th>
              <th>{__("Time", "wordsocket-woocommerce")}</th>
              <th>{__("Customer", "wordsocket-woocommerce")}</th>
              <th>{__("Items", "wordsocket-woocommerce")}</th>
              <th>{__("Total", "wordsocket-woocommerce")}</th>
              <th>{__("Status", "wordsocket-woocommerce")}</th>
              <th>{__("Payment", "wordsocket-woocommerce")}</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr
                key={row.order_id}
                className={`wordsocket-woo-row status-${row.status}${row.updatedAt && Date.now() - row.updatedAt < 4000 ? " is-fresh" : ""}`}
                data-order-id={row.order_id}
              >
                <td>
                  <a href={row.edit_url}>#{row.number}</a>
                </td>
                <td>{timeOf(row)}</td>
                <td>{row.customer}</td>
                <td>{row.item_count}</td>
                <td>{formatMoney(row.total, row.currency)}</td>
                <td>
                  <span className={`wordsocket-woo-status status-${row.status}`}>{statusLabel(row.status)}</span>
                </td>
                <td>{row.payment_method}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  );
}

function ConnectionBadge({ state }: { state: WPSConnectionState | null }) {
  if (!state) {
    return <span className="wordsocket-woo-conn is-off">{__("Realtime client not loaded", "wordsocket-woocommerce")}</span>;
  }
  if (state.connected) {
    return <span className="wordsocket-woo-conn is-on">{__("Live", "wordsocket-woocommerce")}</span>;
  }
  if (state.error?.code === "authentication-failed") {
    return (
      <span className="wordsocket-woo-conn is-off">
        {__("Not delivering: reconnect WordSocket", "wordsocket-woocommerce")}
      </span>
    );
  }
  if (state.retryInMs) {
    return (
      <span className="wordsocket-woo-conn is-off">
        {sprintf(
          /* translators: %d: seconds */
          __("Reconnecting in %ds", "wordsocket-woocommerce"),
          Math.ceil(state.retryInMs / 1000),
        )}
      </span>
    );
  }
  return <span className="wordsocket-woo-conn is-off">{__("Connecting", "wordsocket-woocommerce")}</span>;
}
