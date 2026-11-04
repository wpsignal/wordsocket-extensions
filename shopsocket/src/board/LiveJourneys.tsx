/*
 * "Where shoppers are coming from": every shopper on the site drawn as a route through the pages
 * they opened this visit, ending where they are now. Columns are pages back from now, rows are page
 * kinds, a node's size is the shoppers through it, and nothing moves unless hovered: a route lights
 * alone, a node lights every route through it.
 */
import { __, _n, sprintf } from "@wordpress/i18n";
import { memo, useCallback, useMemo, useRef, useState } from "@wordpress/element";

/**
 * Internal dependencies.
 */
import { routeSteps } from "./journey";
import { kindLabel } from "./usePageNames";

/** The fewest and the most recent pages of a route the diagram draws; the width decides between them. */
const LIVE_STEPS_MIN = 8;
const LIVE_STEPS_MAX = 24;
/** Room before the first column, the right margin, the column headers and the bottom; the row labels sit outside the drawing. */
const LEFT = 20;
const RIGHT = 24;
const TOP = 30;
const BOTTOM = 12;
const ROW_H = 40;
/** The column width the diagram fits as many steps as it can at, and the narrowest before it scrolls. */
const COL_W = 72;
const MIN_COL_W = 56;
const R_MAX = 9;
const R_MIN = 3.5;
/** How many routes draw at full ink; past it the ink thins by the square root of the excess. */
const FULL_INK_ROUTES = 10;
const MIN_INK = 0.1;

/** The page kinds in row order; any other kind follows, alphabetically. */
const KIND_ORDER = [
  "home",
  "shop",
  "category",
  "product",
  "cart",
  "checkout",
  "thanks",
  "search",
  "page",
  "post",
  "archive",
  "other",
  "admin",
];

/* The hues WordSocket's Journeys tab gives these kinds. */
const KIND_COLOR: Record<string, string> = {
  home: "#2a78d6",
  category: "#eb6834",
  product: "#1baf7a",
  cart: "#eda100",
  checkout: "#e87ba4",
  thanks: "#008300",
  shop: "#6250d6",
  page: "#e34948",
  post: "#6250d6",
};
const GREY = "#8c8f94";

/** A kind's hue, grey for one the table does not name. */
const color = (kind: string): string =>
  Object.prototype.hasOwnProperty.call(KIND_COLOR, kind)
    ? KIND_COLOR[kind]
    : GREY;

/** The kinds in row order: the known ones first as listed, the rest alphabetically. */
function orderKinds(kinds: Iterable<string>): string[] {
  const set = new Set(kinds);
  const known = KIND_ORDER.filter((k) => set.has(k));
  const rest = [...set].filter((k) => !KIND_ORDER.includes(k)).sort();
  return [...known, ...rest];
}

/** A cubic curve from `a` to `b`, level at both ends. */
function curve(
  a: { x: number; y: number },
  b: { x: number; y: number },
): string {
  const mx = (a.x + b.x) / 2;
  return `M${a.x},${a.y}C${mx},${a.y} ${mx},${b.y} ${b.x},${b.y}`;
}

/** What the diagram knows about a page beyond its kind. */
export interface RouteNames {
  label: (kind: string, id: number) => string;
}

interface Node {
  col: number;
  kind: string;
  x: number;
  y: number;
  count: number;
}

/** The plot's width, followed from whenever the plot is mounted and as it changes. */
function useWidth(): [(el: HTMLDivElement | null) => void, number] {
  const [width, setWidth] = useState(0);
  const observer = useRef<ResizeObserver | null>(null);
  const ref = useCallback((el: HTMLDivElement | null) => {
    observer.current?.disconnect();
    observer.current = null;
    if (!el) return;
    const read = () => setWidth(el.clientWidth);
    read();
    observer.current = new ResizeObserver(read);
    observer.current.observe(el);
  }, []);
  return [ref, width];
}

/**
 * The routes of the shoppers on the site now.
 *
 * @since 0.8.0
 */
export const LiveJourneys = memo(function LiveJourneys({
  shoppers,
  names,
}: {
  shoppers: WooShopper[];
  names: RouteNames;
}) {
  const [frame, width] = useWidth();
  /* The hovered node, `col:kind`, whose routes are lit. */
  const [focus, setFocus] = useState<string | null>(null);
  /* As many steps as the width holds at COL_W each, within the bounds. */
  const limit = Math.min(
    LIVE_STEPS_MAX,
    Math.max(LIVE_STEPS_MIN, Math.floor((width - LEFT - RIGHT) / COL_W)),
  );
  const routes = useMemo(
    () =>
      shoppers
        .map((shopper) => ({ shopper, steps: routeSteps(shopper.j, limit) }))
        .filter((r) => r.steps.length > 0),
    [shoppers, limit],
  );
  const columns = Math.max(1, ...routes.map((r) => r.steps.length));
  const rows = useMemo(
    () => orderKinds(routes.flatMap((r) => r.steps.map((s) => s.kind))),
    [routes],
  );
  const W = Math.max(width, LEFT + RIGHT + MIN_COL_W * columns);
  const H = TOP + Math.max(1, rows.length) * ROW_H + BOTTOM;
  const colX = (col: number) =>
    columns === 1
      ? LEFT + (W - LEFT - RIGHT) / 2
      : LEFT + ((W - LEFT - RIGHT) * col) / (columns - 1);
  const rowY = (kind: string) => TOP + rows.indexOf(kind) * ROW_H + ROW_H / 2;

  /* Every (column, kind) a route passes through, counted, and each route's path and title. */
  const { nodes, drawn, maxCount } = useMemo(() => {
    const nodes = new Map<string, Node>();
    const drawn = routes.map(({ shopper, steps }) => {
    const keys = new Set<string>();
    const points = steps.map((step, k) => {
      const col = columns - steps.length + k;
      const key = `${col}:${step.kind}`;
      keys.add(key);
      const node = nodes.get(key) ?? {
        col,
        kind: step.kind,
        x: colX(col),
        y: rowY(step.kind),
        count: 0,
      };
      node.count += 1;
      nodes.set(key, node);
      return { x: node.x, y: node.y };
    });
    const d = points
      .slice(1)
      .map((p, k) => {
        const seg = curve(points[k], p);
        return k === 0 ? seg : seg.slice(seg.indexOf("C"));
      })
      .join("");
    const who =
      shopper.cart?.customer ||
      (shopper.guess
        ? sprintf(
            /* translators: %s: the name or email guessed for a guest */
            __("%s (best guess)", "shopsocket"),
            shopper.guess.name || shopper.guess.email,
          )
        : shopper.staff
          ? __("Staff", "shopsocket")
          : __("Guest", "shopsocket"));
    const pages = steps.map((s) => names.label(s.kind, s.id)).join(" › ");
    return { shopper, d, keys, title: `${who} · ${pages}` };
    });
    const maxCount = Math.max(1, ...[...nodes.values()].map((n) => n.count));
    return { nodes, drawn, maxCount };
  }, [routes, columns, rows, W, names]);
  const radius = (n: Node) =>
    Math.max(R_MIN, R_MAX * Math.sqrt(n.count / maxCount));
  const back = (col: number) => columns - 1 - col;
  const header = (col: number) => {
    if (back(col) === 0) return __("Now", "shopsocket");
    if (columns > 5) return `−${back(col)}`;
    return sprintf(
      /* translators: %d: pages before the one the shopper is on */
      _n("%d page back", "%d pages back", back(col), "shopsocket"),
      back(col),
    );
  };
  const nodeTitle = (n: Node) =>
    back(n.col) === 0
      ? sprintf(
          /* translators: 1: a kind of page, 2: shoppers on it now */
          _n(
            "%1$s · %2$d shopper here now",
            "%1$s · %2$d shoppers here now",
            n.count,
            "shopsocket",
          ),
          kindLabel(n.kind),
          n.count,
        )
      : sprintf(
          /* translators: 1: a kind of page, 2: shoppers who came through it, 3: pages before the one they are on */
          _n(
            "%1$s · %2$d shopper came through here, %3$d back",
            "%1$s · %2$d shoppers came through here, %3$d back",
            n.count,
            "shopsocket",
          ),
          kindLabel(n.kind),
          n.count,
          back(n.col),
        );

  const ink = Math.max(
    MIN_INK,
    1 / Math.sqrt(Math.max(1, routes.length / FULL_INK_ROUTES)),
  );

  return (
    <div
      className={`shopsocket-journeys${focus ? " is-focused" : ""}`}
      style={{ "--shopsocket-ink": ink } as React.CSSProperties}
    >
      {routes.length > 0 && (
        <>
          <div
            className="shopsocket-journeys__labels"
            style={
              {
                "--shopsocket-top": `${TOP}px`,
                "--shopsocket-row": `${ROW_H}px`,
                "--shopsocket-rows": rows.length,
              } as React.CSSProperties
            }
            aria-hidden="true"
          >
            {rows.map((kind) => (
              <span key={kind}>{kindLabel(kind)}</span>
            ))}
          </div>
          <div className="shopsocket-journeys__plot" ref={frame}>
            <svg
              viewBox={`0 0 ${W} ${H}`}
              width={W}
              height={H}
              role="img"
              aria-label={__(
                "Each shopper on the site drawn as a route through your pages, ending where they are now",
                "shopsocket",
              )}
            >
              <g>
                {Array.from({ length: columns }, (_, col) => (
                  <text
                    key={col}
                    x={colX(col)}
                    y={16}
                    textAnchor="middle"
                    className={back(col) === 0 ? "is-strong" : undefined}
                  >
                    {header(col)}
                  </text>
                ))}
              </g>
              <g>
                {drawn.map(({ shopper, d, keys, title }) => (
                  <g
                    key={shopper.v}
                    className={`shopsocket-journeys__shopper${shopper.away ? " is-away" : ""}${focus && keys.has(focus) ? " is-on" : ""}`}
                    data-visitor={shopper.v}
                  >
                    <title>{title}</title>
                    {d && <path className="shopsocket-journeys__route" d={d} />}
                    {d && <path className="shopsocket-journeys__hit" d={d} />}
                  </g>
                ))}
              </g>
              <g>
                {[...nodes.values()].map((n) => (
                  <g
                    key={`${n.col}:${n.kind}`}
                    onMouseEnter={() => setFocus(`${n.col}:${n.kind}`)}
                    onMouseLeave={() => setFocus(null)}
                  >
                    <title>{nodeTitle(n)}</title>
                    {back(n.col) === 0 && (
                      <circle
                        className="shopsocket-journeys__now"
                        cx={n.x}
                        cy={n.y}
                        r={radius(n) + 3}
                        stroke={color(n.kind)}
                      />
                    )}
                    <circle
                      className="shopsocket-journeys__node"
                      cx={n.x}
                      cy={n.y}
                      r={radius(n)}
                      fill={color(n.kind)}
                    />
                  </g>
                ))}
              </g>
            </svg>
          </div>
        </>
      )}
    </div>
  );
});
