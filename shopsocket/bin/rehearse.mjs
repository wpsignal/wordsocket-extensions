#!/usr/bin/env node
/*
 * A rehearsal: made-up customers browsing a shop for a while, as the board sees real ones.
 *
 * - Usage: `node bin/rehearse.mjs --url=<shop> [--customers=<n>] [--seconds=<n>] [--force]`, up to
 *   200 customers (the default) for 60 seconds.
 * - Each customer loads one page for its token, opens a socket to the relay the site is connected
 *   to, enters presence as a real tab does, walks the shop's pages, and reports its visit and its
 *   leave to WordPress.
 * - Some put a product in the cart through the Store API, some hide the tab, some leave early; all
 *   are gone when the time is up. Nobody checks out, so no order is written.
 * - Visitor ids start with WordSocket's seed prefix, so `wp wordsocket journeys unseed` removes the
 *   journeys a rehearsal left behind.
 * - A site or a relay that is not on this machine is refused without `--force`.
 * - A local site whose relay is not local is refused outright, since certificate checks are off for local hosts.
 */

import { randomUUID } from "node:crypto";

const MAX_CUSTOMERS = 200;
const MAX_SECONDS = 600;
/** The presence channel ShopSocket's storefront enters (`CARTS_PRESENCE_CHANNEL`). */
const PRESENCE_CHANNEL = "woo:carts:live";
/** The share of the run customers arrive over, so the board fills as a shop does. */
const RAMP_SHARE = 0.3;
/** How long a customer looks at a page of each kind, in seconds: the least and the most. */
const DWELL = {
  home: [2, 6],
  shop: [3, 9],
  category: [3, 9],
  product: [4, 14],
  cart: [3, 10],
  checkout: [5, 15],
  away: [4, 20],
};
/** Where a visit starts, by weight. */
const START = { home: 30, shop: 15, category: 15, product: 40 };
/** Where each page kind leads, by weight; `leave` closes the tab. */
const NEXT = {
  home: { shop: 25, category: 20, product: 35, away: 12, leave: 8 },
  shop: { product: 55, category: 20, away: 13, home: 4, leave: 8 },
  category: { product: 60, category: 12, shop: 5, away: 12, leave: 11 },
  product: { product: 28, cart: 14, category: 14, shop: 8, away: 20, leave: 16 },
  cart: { checkout: 40, product: 25, shop: 10, away: 15, leave: 10 },
  checkout: { cart: 15, product: 10, away: 35, leave: 40 },
};
/** The chance a customer on a product page puts it in the cart. */
const ADD_TO_CART = 0.2;
/** What a rehearsal's visitor ids start with: WordSocket's seed prefix, so `wp wordsocket journeys unseed` removes their journeys. */
const VISITOR_PREFIX = "5eed0000";

/** `--name=value` and `--flag` arguments as an object. */
function readArgs(argv) {
  const args = {};
  for (const arg of argv) {
    const match = /^--([a-z-]+)(?:=(.*))?$/.exec(arg);
    if (match) args[match[1]] = match[2] ?? true;
  }
  return args;
}

/** Whether a host is this machine or a local development name. */
function isLocal(host) {
  return (
    host === "localhost" ||
    host === "127.0.0.1" ||
    /\.(local|test|localhost)$/.test(host)
  );
}

/** A number from `low` to `high`. */
const between = (low, high) => low + Math.random() * (high - low);

/** One key of a table of weights, drawn by weight. */
function pick(weights) {
  let left = Math.random() * Object.values(weights).reduce((a, b) => a + b, 0);
  for (const [key, weight] of Object.entries(weights)) {
    left -= weight;
    if (left <= 0) return key;
  }
  return Object.keys(weights).at(-1);
}

/** One item of a list, any of them as likely. */
const oneOf = (list) => list[Math.floor(Math.random() * list.length)];

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/** The object printed after `name =` in a page, read by its braces so a `};` inside a string does not end it. */
function printedObject(html, name) {
  const at = html.search(new RegExp(`\\b${name}\\s*=\\s*\\{`));
  if (at < 0) return null;
  const start = html.indexOf("{", at);
  let depth = 0;
  let quoted = false;
  for (let i = start; i < html.length; i++) {
    const char = html[i];
    if (quoted) {
      if (char === "\\") i++;
      else if (char === '"') quoted = false;
    } else if (char === '"') quoted = true;
    else if (char === "{") depth++;
    else if (char === "}" && --depth === 0) {
      try {
        return JSON.parse(html.slice(start, i + 1));
      } catch {
        return null;
      }
    }
  }
  return null;
}

/** A browser's cookies for one site: kept from each response and sent with each request. */
class Jar {
  cookies = new Map();

  /** Fetch with the jar's cookies, keeping what the response sets. */
  async fetch(url, init = {}) {
    const headers = { ...(init.headers ?? {}) };
    if (this.cookies.size) {
      headers.Cookie = [...this.cookies].map(([k, v]) => `${k}=${v}`).join("; ");
    }
    const res = await fetch(url, { ...init, headers, redirect: "follow" });
    for (const line of res.headers.getSetCookie()) {
      const [pair] = line.split(";");
      const eq = pair.indexOf("=");
      if (eq > 0) this.cookies.set(pair.slice(0, eq).trim(), pair.slice(eq + 1).trim());
    }
    return res;
  }
}

/** A page as a browser gets it: the WordSocket config it prints, or null when it prints none. */
async function loadPage(jar, url) {
  const res = await jar.fetch(url);
  if (!res.ok) return null;
  return printedObject(await res.text(), "wpSignalConfig");
}

/** The shop's pages a customer can walk: one list of `{ kind, id, url }` per kind, the products with their ids to add. */
async function scout(base) {
  const jar = new Jar();
  const home = await loadPage(jar, `${base}/`);
  if (!home?.token || !home.endpoints?.ws || !home.presence?.visitUrl) {
    throw new Error(
      `${base} prints no WordSocket config: is WordSocket active and connected there?`,
    );
  }
  const rest = home.presence.visitUrl.replace(/wpsignal\/v1\/visit.*$/, "");
  const json = async (path) => {
    const res = await jar.fetch(`${rest}${path}`);
    return res.ok ? res.json() : [];
  };
  const pages = {
    home: [{ kind: home.presence.kind, id: home.presence.id, url: `${base}/` }],
    shop: [],
    category: [],
    product: [],
    cart: [],
    checkout: [],
  };
  for (const kind of ["shop", "cart", "checkout"]) {
    const url = `${base}/${kind}/`;
    const page = await loadPage(jar, url);
    if (page?.presence?.kind === kind) pages[kind].push({ kind, id: page.presence.id, url });
  }
  for (const term of await json("wc/store/v1/products/categories?per_page=20")) {
    if (term.count > 0) pages.category.push({ kind: "category", id: term.id, url: term.permalink });
  }
  for (const product of await json("wc/store/v1/products?per_page=50")) {
    if (product.type === "simple" && product.is_purchasable && product.is_in_stock) {
      pages.product.push({ kind: "product", id: product.id, url: product.permalink });
    }
  }
  if (pages.product.length === 0) {
    throw new Error("The shop has no simple product in stock to look at.");
  }
  /* An empty cart sends the checkout page back to the cart, so the scout never sees it as itself. */
  if (pages.cart.length && pages.checkout.length === 0) {
    pages.checkout.push({ kind: "checkout", id: 0, url: `${base}/checkout/` });
  }
  return { pages, rest, relay: new URL(home.endpoints.ws).host, reports: home.presence.report === true };
}

/** A page of `kind`, or of a kind the shop does have when it has none of that one. */
function pageOf(pages, kind) {
  const list = pages[kind]?.length ? pages[kind] : pages.product;
  return oneOf(list);
}

/**
 * One customer's visit: arrive, connect, walk until the time is up or they leave, then say so and close.
 * Counts what happened on `tally`.
 */
async function customer(shop, endsAt, tally) {
  const jar = new Jar();
  const v = VISITOR_PREFIX + randomUUID().slice(VISITOR_PREFIX.length);
  const n = randomUUID();
  const steps = [];
  let cartId = null;
  let nonce = null;
  let hidden = false;
  let page = pageOf(shop.pages, pick(START));

  const config = await loadPage(jar, page.url);
  if (!config?.token) {
    tally.failed++;
    return;
  }
  const { visitUrl, leaveUrl, writeKinds = [] } = config.presence;
  const journey = () => steps.map((s) => `${s.kind}:${s.id}:${s.seconds}`).join(">");
  const report = async (url) => {
    try {
      const res = await jar.fetch(url, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ v, n, journey: journey(), data: { b: cartId } }),
      });
      tally.reports++;
      return res.ok ? await res.json() : null;
    } catch {
      return null;
    }
  };

  const socket = new WebSocket(`${config.endpoints.ws}?token=${encodeURIComponent(config.token)}`);
  const send = (frame) => {
    if (socket.readyState === WebSocket.OPEN) socket.send(JSON.stringify(frame));
  };
  const announce = () =>
    send({ type: "presence", channel: PRESENCE_CHANNEL, state: { v, n, j: journey(), b: cartId } });
  const closed = new Promise((resolve) => {
    socket.addEventListener("close", (event) => resolve(event.code));
    socket.addEventListener("error", () => resolve(0));
  });
  socket.addEventListener("message", (event) => {
    try {
      if (JSON.parse(String(event.data)).type === "ping") send({ type: "pong" });
    } catch {
      // Not a frame this customer answers.
    }
  });
  const opened = await Promise.race([
    new Promise((resolve) => socket.addEventListener("open", () => resolve(true))),
    closed.then(() => false),
  ]);
  if (!opened) {
    const code = await closed;
    if (code === 4029) tally.refused++;
    else tally.failed++;
    return;
  }
  tally.connected++;
  tally.online++;
  send({ type: "subscribe", channels: config.channels ?? [] });

  /* Put the product on this page in the cart, as the storefront's button does, and learn the cart's id. */
  const addToCart = async (productId) => {
    try {
      if (!nonce) {
        const cart = await jar.fetch(`${shop.rest}wc/store/v1/cart`);
        nonce = cart.headers.get("Nonce");
      }
      const added = await jar.fetch(`${shop.rest}wc/store/v1/cart/add-item`, {
        method: "POST",
        headers: { "Content-Type": "application/json", Nonce: nonce ?? "" },
        body: JSON.stringify({ id: productId, quantity: 1 }),
      });
      if (!added.ok) return;
      const answer = await report(visitUrl);
      const id = answer?.shopsocket?.id;
      if (typeof id === "string" && id) {
        if (!cartId) {
          tally.carts++;
          tally.holding++;
        }
        cartId = id;
        announce();
      }
    } catch {
      // The cart stays as it was; the visit goes on.
    }
  };

  let first = true;
  let done = false;
  while (!done) {
    const kind = hidden ? "away" : page.kind;
    const step = { kind, id: hidden ? 0 : page.id, seconds: 0 };
    steps.push(step);
    announce();
    /* A visit is written on its first step, on a page kind the site names, and when the tab hides. */
    if (shop.reports || cartId) {
      if (first || hidden || writeKinds.includes(kind)) void report(visitUrl);
    }
    first = false;
    if (kind === "product" && Math.random() < ADD_TO_CART) void addToCart(page.id);

    const [low, high] = DWELL[kind] ?? DWELL.product;
    const stay = Math.min(between(low, high) * 1000, Math.max(0, endsAt - Date.now()));
    const arrived = Date.now();
    const gone = await Promise.race([sleep(stay).then(() => false), closed.then(() => true)]);
    step.seconds = Math.max(1, Math.round((Date.now() - arrived) / 1000));
    if (gone) {
      tally.dropped++;
      break;
    }
    if (Date.now() >= endsAt) break;

    if (hidden) {
      hidden = false;
      tally.hidden--;
      continue;
    }
    const to = pick(NEXT[page.kind] ?? NEXT.product);
    if (to === "leave") {
      done = true;
    } else if (to === "away") {
      hidden = true;
      tally.hidden++;
    } else if ((to === "cart" || to === "checkout") && !cartId) {
      page = pageOf(shop.pages, "product");
    } else {
      page = pageOf(shop.pages, to);
    }
  }

  if (hidden) tally.hidden--;
  if (cartId) tally.holding--;
  tally.online--;
  tally.left++;
  await report(leaveUrl);
  socket.close();
}

async function main() {
  const args = readArgs(process.argv.slice(2));
  if (!args.url || args.url === true) {
    console.error(
      "Usage: node bin/rehearse.mjs --url=<shop> [--customers=<n>] [--seconds=<n>] [--force]\n" +
        "  for example: node bin/rehearse.mjs --url=http://nossl.woo.wpsignal.local",
    );
    process.exit(1);
  }
  const base = String(args.url).replace(/\/+$/, "");
  const customers = Math.min(MAX_CUSTOMERS, Math.max(1, Number(args.customers ?? MAX_CUSTOMERS) || MAX_CUSTOMERS));
  const seconds = Math.min(MAX_SECONDS, Math.max(10, Number(args.seconds ?? 60) || 60));
  const site = new URL(base).hostname;
  if (!isLocal(site) && !args.force) {
    console.error(`${site} is not a local site. Pass --force to rehearse against it anyway.`);
    process.exit(1);
  }
  /* A local site and relay serve certificates this machine's own authority signed. */
  if (isLocal(site)) process.env.NODE_TLS_REJECT_UNAUTHORIZED = "0";

  const shop = await scout(base);
  const relay = shop.relay.replace(/:\d+$/, "");
  if (!isLocal(relay) && !args.force) {
    console.error(
      `${site} is connected to ${relay}, which is not a local relay: ${customers} connections would count against that account. Pass --force to go ahead.`,
    );
    process.exit(1);
  }
  if (isLocal(site) && !isLocal(relay)) {
    console.error(
      `${site} is local but its relay ${relay} is not: certificate checks are off for the local site and would be off for the relay too. Point the site at a local relay first.`,
    );
    process.exit(1);
  }
  const kinds = Object.entries(shop.pages)
    .filter(([, list]) => list.length)
    .map(([kind, list]) => `${list.length} ${kind}`)
    .join(", ");
  console.log(`Rehearsing ${customers} customers on ${base} for ${seconds} s, through ${shop.relay}.`);
  console.log(`Pages: ${kinds}. Visits ${shop.reports ? "are" : "are not"} kept: History is ${shop.reports ? "on" : "off"}.`);

  const tally = {
    connected: 0,
    online: 0,
    hidden: 0,
    holding: 0,
    carts: 0,
    left: 0,
    dropped: 0,
    refused: 0,
    failed: 0,
    reports: 0,
  };
  const startedAt = Date.now();
  const endsAt = startedAt + seconds * 1000;
  const ramp = seconds * 1000 * RAMP_SHARE;
  const line = () =>
    `${String(Math.round((Date.now() - startedAt) / 1000)).padStart(3)} s  on the site ${tally.online}` +
    `  tab hidden ${tally.hidden}  holding a cart ${tally.holding}  left ${tally.left}`;
  const ticker = setInterval(() => console.log(line()), 5000);

  await Promise.all(
    Array.from({ length: customers }, async (_, i) => {
      await sleep((ramp * i) / customers + between(0, 400));
      if (Date.now() >= endsAt) return;
      try {
        await customer(shop, endsAt, tally);
      } catch {
        tally.failed++;
      }
    }),
  );
  clearInterval(ticker);

  console.log(
    `Done: ${tally.connected} of ${customers} connected, ${tally.carts} put something in a cart, ` +
      `${tally.reports} reports sent to WordPress.`,
  );
  if (tally.refused) console.log(`${tally.refused} were refused by the relay: the site's connection limit was reached.`);
  if (tally.dropped) console.log(`${tally.dropped} lost their connection during the visit.`);
  if (tally.failed) console.log(`${tally.failed} could not load a page or open a socket.`);
  if (shop.reports) console.log("Remove the journeys they left with `wp wordsocket journeys unseed`.");
}

main().catch((error) => {
  console.error(error.message ?? error);
  process.exit(1);
});
