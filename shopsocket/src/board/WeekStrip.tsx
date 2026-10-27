/*
 * The board's "This week" strip: carts left behind, recovered orders and checkout conversion against
 * last week, where leavers go, and the products most often left in a cart.
 */
import { __, _n, sprintf } from "@wordpress/i18n";
import { useMemo } from "@wordpress/element";
import { money } from "./format";
import { useProductNames } from "./useProductNames";

type Config = Pick<
  ShopSocketBoardConfig,
  "productsUrl" | "nonce" | "journeysUrl"
>;

/** "+3 vs last week", "same as last week"; `unit` follows the number. */
function delta(now: number, before: number, unit = ""): string {
  const d = now - before;
  if (d === 0) return __("same as last week", "shopsocket");
  /* translators: %s: a signed difference, such as +3 or -2 */
  return sprintf(
    __("%s vs last week", "shopsocket"),
    `${d > 0 ? "+" : "−"}${Math.abs(d)}${unit}`,
  );
}

/** Milliseconds as "48 s" or "2 min 10 s". */
function duration(ms: number): string {
  const total = Math.round(ms / 1000);
  const minutes = Math.floor(total / 60);
  const seconds = total % 60;
  /* translators: %d: seconds */
  if (minutes === 0) return sprintf(__("%d s", "shopsocket"), seconds);
  /* translators: 1: minutes, 2: seconds */
  return sprintf(__("%1$d min %2$d s", "shopsocket"), minutes, seconds);
}

/** The page kind a journey step names, as a place in a sentence. */
function placeOf(kind: string): string {
  const places: Record<string, string> = {
    product: __("a product page", "shopsocket"),
    category: __("a category page", "shopsocket"),
    cart: __("the cart", "shopsocket"),
    checkout: __("checkout", "shopsocket"),
    home: __("the home page", "shopsocket"),
    shop: __("the shop", "shopsocket"),
    search: __("a search", "shopsocket"),
    thanks: __("the order page", "shopsocket"),
  };
  return places[kind] ?? __("a page", "shopsocket");
}

/** WordSocket's Journeys tab with the network on this week's leavers: outcome left, through the leak's page, least 2 per edge. */
function leaversUrl(base: string, kind: string): string {
  const url = new URL(base, window.location.origin);
  url.searchParams.set("outcome", "left");
  url.searchParams.set("through", kind);
  url.searchParams.set("min", "2");
  return url.toString();
}

/**
 * The strip, or one line pointing at WordSocket's Journeys tab while its History is off; the leak sentence
 * links into that tab.
 *
 * @since 0.8.0
 */
export function WeekStrip({
  strip,
  config,
}: {
  strip: WooWeekStrip | null | undefined;
  config: Config;
}) {
  const ids = useMemo(() => (strip?.products ?? []).map((p) => p.id), [strip]);
  const product = useProductNames(ids, config);
  if (!strip) {
    return (
      <p className="shopsocket-week__off">
        {__(
          "Turn on WordSocket's History to see this week's figures.",
          "shopsocket",
        )}{" "}
        <a href={config.journeysUrl}>{__("Journeys tab", "shopsocket")}</a>
      </p>
    );
  }
  const { left, recovered, conversion, leak, products } = strip;
  const tone = (good: boolean) => (good ? " is-good" : " is-bad");
  return (
    <section
      className="shopsocket-week"
      aria-labelledby="shopsocket-week-heading"
    >
      <h2 id="shopsocket-week-heading">{__("This week", "shopsocket")}</h2>
      <div className="shopsocket-week__tiles">
        <div className="shopsocket-tile is-left">
          <span className="shopsocket-tile__value">{String(left.count)}</span>
          <span className="shopsocket-tile__label">
            {__("Carts left behind", "shopsocket")}
          </span>
          <span
            className={`shopsocket-tile__detail${tone(left.count <= left.previous)}`}
          >
            {sprintf(
              /* translators: 1: the change against last week, 2: the value of those carts */
              __("%1$s, %2$s in them", "shopsocket"),
              delta(left.count, left.previous),
              money(left.value, left.currency),
            )}
          </span>
        </div>
        <div className="shopsocket-tile is-recovered">
          <span className="shopsocket-tile__value">
            {String(recovered.count)}
          </span>
          <span className="shopsocket-tile__label">
            {__("Recovered orders", "shopsocket")}
          </span>
          <span
            className={`shopsocket-tile__detail${tone(recovered.count >= recovered.previous)}`}
          >
            {sprintf(
              /* translators: 1: the value of the recovered orders, 2: the change against last week */
              __("%1$s after a moment fired, %2$s", "shopsocket"),
              money(recovered.value, left.currency),
              delta(recovered.count, recovered.previous),
            )}
          </span>
        </div>
        <div className="shopsocket-tile is-conversion">
          <span className="shopsocket-tile__value">{`${conversion.percent}%`}</span>
          <span className="shopsocket-tile__label">
            {__("Checkout conversion", "shopsocket")}
          </span>
          <span
            className={`shopsocket-tile__detail${tone(conversion.percent >= conversion.previous_percent)}`}
          >
            {sprintf(
              /* translators: 1: orders, 2: journeys that reached checkout, 3: the change against last week in points */
              __("%1$d of %2$d who reached checkout, %3$s", "shopsocket"),
              conversion.orders,
              conversion.reached,
              delta(
                conversion.percent,
                conversion.previous_percent,
                __(" pts", "shopsocket"),
              ),
            )}
          </span>
        </div>
      </div>
      {strip.journeys === false && (
        <p className="shopsocket-week__off">
          {__(
            "Switch on WordSocket's History for the leak, the conversion and the recovered orders.",
            "shopsocket",
          )}{" "}
          <a href={config.journeysUrl}>{__("Journeys tab", "shopsocket")}</a>
        </p>
      )}
      {leak && (
        <p className="shopsocket-week__leak">
          {sprintf(
            /* translators: 1: where most leavers left from, 2: the median time there, 3: how many, 4: their share of everyone who left */
            __(
              "Most shoppers who leave with a cart do so from %1$s, after a median %2$s there: %3$d this week, %4$d%% of everyone who left.",
              "shopsocket",
            ),
            placeOf(leak.kind),
            duration(leak.median_ms),
            leak.count,
            Math.round((100 * leak.count) / Math.max(1, leak.leavers)),
          )}{" "}
          {config.journeysUrl && (
            <a href={leaversUrl(config.journeysUrl, leak.kind)}>
              {__("See them", "shopsocket")}
            </a>
          )}
        </p>
      )}
      <div className="shopsocket-live-products shopsocket-week__products">
        <h2>{__("Products most often left in a cart", "shopsocket")}</h2>
        {products.length === 0 ? (
          <p className="shopsocket-board__empty">
            {__("No cart was left behind this week.", "shopsocket")}
          </p>
        ) : (
          <table>
            <thead>
              <tr>
                <th scope="col">{__("Product", "shopsocket")}</th>
                <th scope="col" className="shopsocket-live-products__count">
                  {__("Left in carts", "shopsocket")}
                </th>
                <th scope="col" className="shopsocket-live-products__count">
                  {__("Bought", "shopsocket")}
                </th>
              </tr>
            </thead>
            <tbody>
              {products.map((row) => {
                const ref = product(row.id);
                const label =
                  ref?.name ??
                  (ref === null
                    ? sprintf(__("Product #%d (removed)", "shopsocket"), row.id)
                    : `#${row.id}`);
                return (
                  <tr key={row.id}>
                    <th scope="row">
                      {ref?.edit_url ? (
                        <a href={ref.edit_url}>{label}</a>
                      ) : (
                        label
                      )}
                    </th>
                    <td className="shopsocket-live-products__count">
                      {String(row.left)}
                    </td>
                    <td className="shopsocket-live-products__count">
                      {String(row.bought)}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        )}
      </div>
    </section>
  );
}
