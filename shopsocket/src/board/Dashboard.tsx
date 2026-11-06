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
import { Notice, ToggleControl } from "@wordpress/components";
import {
  fullscreen as fullscreenIcon,
  fullscreenExit,
  bellUnread,
  bell,
} from "@wordpress/icons";
import {
  Badge,
  Link,
  Stack,
  Tabs,
  Text,
  Button,
  IconButton,
  Icon,
} from "@wordpress/ui";
import { useBoardAlerts, useLiveOrders } from "./useLiveOrders";
import { useConnectionState } from "./useConnectionState";
import { useDashboardSnapshot } from "./useDashboardSnapshot";
import { LIVE_PRODUCTS, useProductNames } from "./useProductNames";
import { needsLookup, pageLabel, pageUrl, usePageNames } from "./usePageNames";
import { money, price } from "./format";
import { DayStrip } from "./DayStrip";
import { AbandonedCarts } from "./AbandonedCarts";
import { TopNow } from "./TopNow";
import { adminUrlOrNull, siteUrlOrNull } from "./trust";
import { LiveOrders } from "./LiveOrders";
import { LiveJourneys, type RouteNames } from "./LiveJourneys";
import { useGuestNames } from "./useGuestNames";
import { routeSteps } from "./journey";
import { playChime } from "./chime";
import {
  BoardTable,
  Section,
  Stat,
  StatGroup,
  shopperColumn,
  type Column,
} from "./ui";

const config: ShopSocketBoardConfig = window.shopSocket ?? {
  dashboardUrl: "",
  productsUrl: "",
  pagesUrl: "",
  guestsUrl: "",
  homeUrl: "",
  shopUrl: "",
  cartUrl: "",
  checkoutUrl: "",
  adminUrl: "",
  journeysUrl: "",
  nonce: "",
  orders: [],
  boardHours: 24,
  abandonedHours: [24],
  statuses: [],
  snapshot: { carts: [], abandoned: [], online: null },
  currencySymbol: "",
  channels: { orders: "", stock: "", presence: "", staff: "", connections: "" },
};

const SOUND_KEY = "shopsocket-sound";

const ALERTS_KEY = "shopsocket-alerts-min";

/** The alerts are listed until this browser minimizes them. */
function readMinimized(): boolean {
  try {
    return window.localStorage.getItem(ALERTS_KEY) === "1";
  } catch {
    return false;
  }
}

/** The chime is on until this browser turns it off. */
function readSound(): boolean {
  try {
    const stored = window.localStorage.getItem(SOUND_KEY);
    return stored === null ? true : stored === "1";
  } catch {
    return true;
  }
}

/**
 * The board: header controls, the alerts (listed, or minimized to their count), the figures, and the live orders table.
 *
 * @since 0.1.1
 */
export function Dashboard() {
  const [sound, setSound] = useState(readSound);
  const [fullscreen, setFullscreen] = useState(false);
  const soundRef = useRef(sound);
  soundRef.current = sound;

  const [minimized, setMinimized] = useState(readMinimized);
  const { alerts, dismiss, orderPlaced } = useBoardAlerts(config.channels);
  const onNewOrder = useCallback(
    (order: WooOrderEvent) => {
      if (soundRef.current) playChime();
      orderPlaced(order);
    },
    [orderPlaced],
  );

  const { rows, reconcile } = useLiveOrders(
    config.orders,
    onNewOrder,
    config.channels,
    config.boardHours,
  );
  /* One lookup for every staff alert's products, rather than a request per notice. */
  const alertProducts = useProductNames(
    useMemo(
      () =>
        Array.from(
          new Set(
            alerts.flatMap((alert) =>
              alert.type === "staff" ? alert.event.products : [],
            ),
          ),
        ).slice(0, LIVE_PRODUCTS),
      [alerts],
    ),
    config,
  );
  const connection = useConnectionState();
  const { snapshot, stale, hours, setHours } = useDashboardSnapshot(
    config,
    reconcile,
  );

  useEffect(() => {
    try {
      window.localStorage.setItem(SOUND_KEY, sound ? "1" : "0");
    } catch {
      // Storage unavailable: the toggle still works for this page.
    }
  }, [sound]);

  useEffect(() => {
    try {
      window.localStorage.setItem(ALERTS_KEY, minimized ? "1" : "0");
    } catch {
      // Storage unavailable: the alerts still minimize for this page.
    }
  }, [minimized]);

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
          <Button
            variant="minimal"
            size="small"
            className="shopsocket-board__alerts-toggle"
            aria-expanded={!minimized}
            disabled={!alerts.length}
            aria-label={sprintf(
              minimized
                ? /* translators: %d: alerts */
                  _n(
                    "Show %d alert",
                    "Show %d alerts",
                    alerts.length,
                    "shopsocket",
                  )
                : /* translators: %d: alerts */
                  _n(
                    "Hide %d alert",
                    "Hide %d alerts",
                    alerts.length,
                    "shopsocket",
                  ),
              alerts.length,
            )}
            onClick={() => setMinimized((m) => !m)}
          >
            <Icon icon={alerts.length ? bellUnread : bell} />{" "}
            {minimized && alerts.length ? `(${alerts.length})` : ""}
          </Button>
          <IconButton
            variant="minimal"
            size="small"
            icon={fullscreen ? fullscreenExit : fullscreenIcon}
            onClick={() => setFullscreen((f) => !f)}
            label={
              fullscreen
                ? __("Exit full screen", "shopsocket")
                : __("Full screen", "shopsocket")
            }
          />
        </div>
      </div>

      <Stack
        direction="column"
        gap="sm"
        className="shopsocket-board__alerts"
        style={{ display: alerts.length > 0 && !minimized ? "flex" : "none" }}
      >
        {!minimized &&
          alerts.map((alert) =>
            alert.type === "staff" ? (
              <AlertNotice
                key={alert.id}
                alert={alert.event}
                name={(id) => alertProducts(id)?.name ?? `#${id}`}
                onDismiss={() => dismiss(alert.id)}
              />
            ) : alert.type === "order" ? (
              <OrderNotice
                key={alert.id}
                order={
                  rows.find((row) => row.order_id === alert.event.order_id) ??
                  alert.event
                }
                onDismiss={() => dismiss(alert.id)}
              />
            ) : (
              <StockNotice
                key={alert.id}
                alert={alert.event}
                onDismiss={() => dismiss(alert.id)}
              />
            ),
          )}
      </Stack>

      <RightNow snapshot={snapshot} stale={stale} />
      <DayStrip strip={snapshot.strip} config={config} />
      <AbandonedCarts
        snapshot={snapshot}
        config={config}
        hours={hours}
        onHours={setHours}
      />

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
        description={sprintf(
          /* translators: %d: hours */
          __(
            "Orders placed in the last %d hours. New ones appear here the moment they are placed.",
            "shopsocket",
          ),
          config.boardHours,
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

/** "Right now": the shoppers on the site split by where they are, what their carts are worth, the pages and products they are on most, then who they are and their routes. */
function RightNow({
  snapshot,
  stale,
}: {
  snapshot: WooDashboardSnapshot;
  stale: boolean;
}) {
  const { carts, usersOnline, online, staff, awayUsers } = snapshot;
  const names = useShopperNames(snapshot.shoppers);
  /* A guest nobody has named yet may have a best guess waiting on the server. */
  const unnamed = useMemo(
    () =>
      snapshot.shoppers
        .filter((s) => !s.staff && !s.cart?.customer)
        .map((s) => s.v),
    [snapshot.shoppers],
  );
  const guests = useGuestNames(unnamed, config);
  const shoppers = useMemo(
    () =>
      snapshot.shoppers.map((s) => ({
        ...s,
        guess: s.staff || s.cart?.customer ? null : (guests(s.v) ?? null),
      })),
    [snapshot.shoppers, guests],
  );
  /* translators: %d: how many of the figure above are staff (people or tabs) */
  const staffNote = (count: number) =>
    count > 0
      ? ", " + sprintf(__("%d of them staff", "shopsocket"), count)
      : "";
  const looking = Math.max(0, usersOnline - awayUsers);
  /* What the carts of the shoppers connected now hold between them. */
  const heldCarts = carts.live.shoppers + carts.away.shoppers;
  const heldValue = carts.live.revenue + carts.away.revenue;
  const heldCurrency = carts.live.currency || carts.away.currency;
  return (
    <Section
      title={__("Right now", "shopsocket")}
      className={stale ? "is-stale" : undefined}
      description={__(
        "Who is in your store this minute and what their carts hold.",
        "shopsocket",
      )}
    >
      <div className="shopsocket-groups" aria-live="polite">
        <StatGroup
          name="shoppers"
          summary={sprintf(
            /* translators: 1: shoppers with a tab in view, 2: shoppers with every tab hidden */
            __("%1$d looking now, %2$d with every tab hidden", "shopsocket"),
            looking,
            awayUsers,
          )}
          shares={[
            {
              name: "looking",
              size: looking,
              /* translators: %d: shoppers with a tab in view */
              title: sprintf(__("Looking now: %d", "shopsocket"), looking),
            },
            {
              name: "idle",
              size: awayUsers,
              /* translators: %d: shoppers with every tab hidden */
              title: sprintf(__("Tab hidden: %d", "shopsocket"), awayUsers),
            },
          ]}
          headline={
            <Stat
              name="users"
              value={String(usersOnline)}
              label={__("Shoppers on the site", "shopsocket")}
              detail={
                __("Different people browsing now", "shopsocket") +
                staffNote(staff.users)
              }
            />
          }
        >
          <Stat
            compact
            swatch
            name="looking"
            value={String(looking)}
            label={__("Looking now", "shopsocket")}
            detail={__("A tab of theirs in view", "shopsocket")}
          />
          <Stat
            compact
            swatch
            name="idle"
            value={String(awayUsers)}
            label={__("Tab hidden", "shopsocket")}
            detail={__("Every tab of theirs hidden", "shopsocket")}
          />
          <Stat
            compact
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
          <Stat
            compact
            name="revenue"
            value={
              heldCurrency
                ? money(heldValue, heldCurrency)
                : `${config.currencySymbol}0`
            }
            label={__("Revenue in carts", "shopsocket")}
            detail={sprintf(
              /* translators: %d: carts held by shoppers on the site */
              _n("%d cart held", "%d carts held", heldCarts, "shopsocket"),
              heldCarts,
            )}
          />
        </StatGroup>
        <TopNow shoppers={shoppers} names={names} config={config} />
      </div>
      <Tabs.Root defaultValue="shoppers" className="shopsocket-tabs">
        <Tabs.List
          variant="minimal"
          aria-label={__("The shoppers on the site", "shopsocket")}
        >
          <Tabs.Tab value="shoppers">{__("Shoppers", "shopsocket")}</Tabs.Tab>
          <Tabs.Tab value="routes">{__("Journeys", "shopsocket")}</Tabs.Tab>
        </Tabs.List>
        <Tabs.Panel value="shoppers" tabIndex={-1} keepMounted>
          <ShoppersPanel shoppers={shoppers} names={names} />
        </Tabs.Panel>
        <Tabs.Panel value="routes" tabIndex={-1} keepMounted>
          <Stack direction="column" gap="lg">
            <Text variant="body-sm" className="shopsocket-muted" render={<p />}>
              {shoppers.length === 0 &&
                __("No shopper is on the site right now.", "shopsocket")}
            </Text>
            {shoppers.length > 0 && (
              <LiveJourneys shoppers={shoppers} names={names} />
            )}
          </Stack>
        </Tabs.Panel>
      </Tabs.Root>
    </Section>
  );
}

/** A shopper's visitor id as their row key. */
const byVisitorId = (row: WooShopper) => row.v;

/**
 * Names and links for every page in the shoppers' routes.
 *
 * @since 0.8.0
 */
export interface ShopperNames extends RouteNames {
  url: (kind: string, id: number) => string | null;
}

/**
 * The names and links of every page the shoppers' routes hold, each fetched once per page per step
 * and shared by the shoppers table and the routes diagram.
 *
 * @since 0.8.0
 */
function useShopperNames(shoppers: WooShopper[]): ShopperNames {
  /* The pages the shoppers' tabs are on first, so the table's cells are named before the routes' nodes. */
  const all = useMemo(
    () => [
      ...shoppers.flatMap((s) =>
        s.tabs.map((t) => ({ kind: t.kind, id: t.id })),
      ),
      ...shoppers.flatMap((s) => s.tabs.flatMap((t) => routeSteps(t.j))),
    ],
    [shoppers],
  );
  const steps = useMemo(
    () =>
      Array.from(
        new Set(
          all
            .filter((s) => needsLookup(s.kind))
            .map((s) => `${s.kind}:${s.id}`),
        ),
      ).slice(0, LIVE_PRODUCTS),
    [all],
  );
  const page = usePageNames(steps, config);
  const productIds = useMemo(
    () =>
      Array.from(
        new Set(all.filter((s) => s.kind === "product").map((s) => s.id)),
      ).slice(0, LIVE_PRODUCTS),
    [all],
  );
  const product = useProductNames(productIds, config);
  return useMemo(
    () => ({
      label: (kind, id) =>
        pageLabel(kind, id, page(`${kind}:${id}`), product(id)),
      url: (kind, id) =>
        pageUrl(kind, page(`${kind}:${id}`), product(id), config),
    }),
    [page, product],
  );
}

/**
 * Every connected visitor: who they are when logged in, the page of each of
 * their tabs linked to it, a hidden tab's marked, and what their cart holds.
 *
 * @since 0.8.0
 */
function ShoppersPanel({
  shoppers,
  names,
}: {
  shoppers: WooShopper[];
  names: ShopperNames;
}) {
  const columns = useMemo<Column<WooShopper>[]>(() => {
    const label = (row: WooShopper) => names.label(row.kind, row.id);
    return [
      shopperColumn<WooShopper>(
        (row) => ({
          v: row.v,
          customer: row.cart?.customer,
          customerUrl: row.cart?.customer_url,
          guess: row.guess,
          staff: row.staff,
        }),
        config.adminUrl,
      ),
      {
        id: "page",
        label: __("Pages", "shopsocket"),
        style: { width: "50%" },
        value: label,
        render: (row) => (
          <span className="shopsocket-shoppers__pages">
            {row.tabs.map((tab) => {
              const url = names.url(tab.kind, tab.id);
              const href = url ? siteUrlOrNull(url, config.homeUrl) : null;
              const name = names.label(tab.kind, tab.id);
              return (
                <span
                  key={tab.key}
                  className={`shopsocket-shoppers__page${tab.away ? " is-away" : ""}`}
                  title={
                    tab.away
                      ? __("A hidden tab", "shopsocket")
                      : __("The tab they are looking at", "shopsocket")
                  }
                >
                  {href ? (
                    <Link
                      variant="default"
                      tone={tab.away ? "neutral" : "brand"}
                      href={href}
                      openInNewTab
                    >
                      {name}
                    </Link>
                  ) : (
                    name
                  )}
                </span>
              );
            })}
          </span>
        ),
      },
      {
        id: "cart",
        label: __("Cart", "shopsocket"),
        style: { width: "130px" },
        value: (row) => row.cart?.value ?? 0,
        render: (row) =>
          row.cart ? (
            <>
              {sprintf(
                /* translators: %d: products in the cart */
                _n(
                  "%d product",
                  "%d products",
                  row.cart.products.length,
                  "shopsocket",
                ),
                row.cart.products.length,
              )}
              {", "}
              {money(
                row.cart.value,
                row.cart.currency || config.currencySymbol,
              )}
            </>
          ) : (
            ""
          ),
        numeric: true,
      },
    ];
  }, [names]);
  return (
    <BoardTable
      className="shopsocket-shoppers"
      rows={shoppers}
      rowId={byVisitorId}
      empty={__("No shopper is on the site right now.", "shopsocket")}
      columns={columns}
    />
  );
}

/** An order just placed, as the board holds it now: its number linked to its edit screen, who placed it, how many items and what it came to. */
function OrderNotice({
  order,
  onDismiss,
}: {
  order: WooOrderEvent;
  onDismiss: () => void;
}) {
  const href = adminUrlOrNull(order.edit_url, config.adminUrl);
  const total = price(order.total, order.currency, config.currencySymbol);
  const items = sprintf(
    /* translators: %d: items in the order */
    _n("%d item", "%d items", order.item_count, "shopsocket"),
    order.item_count,
  );
  return (
    <Notice
      status="success"
      className="shopsocket-board__order"
      onRemove={onDismiss}
    >
      <strong>{__("New order", "shopsocket")}: </strong>
      {href ? <a href={href}>#{order.number}</a> : `#${order.number}`}{" "}
      {[order.customer, items, total].filter(Boolean).join(", ")}
    </Notice>
  );
}

/** A product low on or out of stock, linked to its edit screen. */
function StockNotice({
  alert,
  onDismiss,
}: {
  alert: WooStockEvent & { kind: "low" | "out" };
  onDismiss: () => void;
}) {
  return (
    <Notice
      status="warning"
      className="shopsocket-board__stock"
      onRemove={onDismiss}
    >
      <strong>{__("Stock", "shopsocket")}: </strong>
      <a
        href={`${config.adminUrl}post.php?post=${alert.product_id}&action=edit`}
        target="_blank"
        rel="noreferrer"
        className={`shopsocket-stock-alert is-${alert.kind}`}
      >
        {alert.kind === "out"
          ? sprintf(
              /* translators: %s: product name */
              __("%s is out of stock", "shopsocket"),
              alert.name,
            )
          : sprintf(
              /* translators: 1: product name, 2: quantity */
              __("%1$s is low (%2$d left)", "shopsocket"),
              alert.name,
              alert.stock_quantity ?? 0,
            )}
      </a>
    </Notice>
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
      <span className="shopsocket-connection-badge is-off">
        {__("Realtime client not loaded", "shopsocket")}
      </span>
    );
  }
  if (state.connected) {
    return (
      <span className="shopsocket-connection-badge is-on">
        {__("Live", "shopsocket")}
      </span>
    );
  }
  if (state.error?.code === "authentication-failed") {
    return (
      <span className="shopsocket-connection-badge is-off">
        {__("Not delivering: reconnect WordSocket", "shopsocket")}
      </span>
    );
  }
  if (state.retryInMs) {
    return (
      <span className="shopsocket-connection-badge is-off">
        {sprintf(
          /* translators: %d: seconds */
          __("Reconnecting in %ds", "shopsocket"),
          Math.ceil(state.retryInMs / 1000),
        )}
      </span>
    );
  }
  return (
    <span className="shopsocket-connection-badge is-off">
      {__("Connecting", "shopsocket")}
    </span>
  );
}
