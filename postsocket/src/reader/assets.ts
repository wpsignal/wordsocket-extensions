/**
 * Assets for markup inserted without a page load: stylesheets, scripts and script modules the page lacks, and the scripts inside the markup itself.
 *
 * - Scripts load in order with their inline code, as WordPress prints them.
 * - Ready handlers a late or re-run script registers run once the markup is in the document, their document queries limited to it.
 * - Interactivity API islands in the markup are hydrated through WordPress's private hydration helpers when this version has them.
 *
 * @since 0.1.0
 */

/** Pathname plus search of a URL, so `http` and `https` spellings of one file match. */
function key(url: string): string {
  try {
    const u = new URL(url, window.location.href);
    return u.pathname + u.search;
  } catch {
    return url;
  }
}

/** A URL on this host takes the page's protocol, so an `http` spelling from the save request is not mixed content. */
function sameScheme(url: string): string {
  try {
    const u = new URL(url, window.location.href);
    if (u.host === window.location.host) u.protocol = window.location.protocol;
    return u.toString();
  } catch {
    return url;
  }
}

function load(el: HTMLLinkElement | HTMLScriptElement): Promise<void> {
  return new Promise((resolve) => {
    el.addEventListener("load", () => resolve(), { once: true });
    el.addEventListener("error", () => resolve(), { once: true });
    document.head.appendChild(el);
  });
}

function inline(id: string, code: string | undefined): void {
  if (!code) return;
  const script = document.createElement("script");
  script.id = id;
  script.textContent = code;
  document.head.appendChild(script);
}

/** Whether the page has a script or stylesheet, by WordPress's element id or by URL. */
function has(id: string, url: string | undefined, selector: string, attr: string): boolean {
  if (document.getElementById(id)) return true;
  if (!url) return false;
  const wanted = key(url);
  return Array.from(document.querySelectorAll(selector)).some((el) => key(el.getAttribute(attr) ?? "") === wanted);
}

/* Ready handlers registered by scripts loaded after the page was ready, run once the markup is in. */
const deferred: Array<() => void> = [];
let holding = 0;
let restore: (() => void) | null = null;

/** Hold `DOMContentLoaded` and `load` listeners added while scripts load, since those events have passed. */
function holdReadyHandlers(): void {
  if (holding++ > 0 || document.readyState === "loading") return;
  const doc = document.addEventListener;
  const win = window.addEventListener;
  const hold = (target: Document | Window, original: typeof doc) =>
    function (this: unknown, type: string, listener: EventListenerOrEventListenerObject | null, options?: boolean | AddEventListenerOptions) {
      const passed = type === "DOMContentLoaded" || (type === "load" && target === window && document.readyState === "complete");
      if (passed && listener) {
        deferred.push(() => {
          const event = new Event(type);
          if (typeof listener === "function") listener.call(target, event);
          else listener.handleEvent(event);
        });
        return;
      }
      if (listener) original.call(this, type, listener, options);
    };
  document.addEventListener = hold(document, doc);
  window.addEventListener = hold(window, win);
  restore = () => {
    document.addEventListener = doc;
    window.addEventListener = win;
  };
}

function releaseReadyHandlers(): void {
  if (--holding > 0) return;
  restore?.();
  restore = null;
}

/** Run `work` with `document.querySelectorAll` limited to elements inside `roots`. */
function within<T>(roots: Element[], work: () => T): T {
  const all = Document.prototype.querySelectorAll;
  Object.defineProperty(document, "querySelectorAll", {
    configurable: true,
    value: (selectors: string) => Array.from(all.call(document, selectors)).filter((el) => roots.some((root) => root.contains(el))),
  });
  try {
    return work();
  } finally {
    delete (document as { querySelectorAll?: unknown }).querySelectorAll;
  }
}

/** Run the held ready handlers, their document queries limited to the inserted markup; with none inserted they wait. */
function runReadyHandlers(roots: Element[]): void {
  if (!roots.length) return;
  for (const run of deferred.splice(0)) {
    try {
      within(roots, run);
    } catch (err) {
      console.error(err);
    }
  }
}

/* A re-run script's source, fetched once per URL. */
const sources = new Map<string, Promise<string | null>>();

/** Run an already loaded script again; its ready handlers are held like a new script's. */
async function rerun(src: string): Promise<void> {
  const url = sameScheme(src);
  let text = sources.get(url);
  if (!text) {
    text = fetch(url, { credentials: "same-origin" })
      .then((response) => (response.ok ? response.text() : null))
      .catch(() => null);
    sources.set(url, text);
  }
  const code = await text;
  const el = document.createElement("script");
  const blob = code === null ? "" : URL.createObjectURL(new Blob([`${code}\n//# sourceURL=${url}`], { type: "text/javascript" }));
  el.src = blob || `${url}${url.includes("?") ? "&" : "?"}postsocket-rerun=${Date.now()}`;
  await load(el);
  el.remove();
  if (blob) URL.revokeObjectURL(blob);
}

/* Interactivity API state and config from every payload so far, applied before islands hydrate. */
const islandData: { state: Record<string, unknown>; config: Record<string, unknown> } = { state: {}, config: {} };
let islandDataChanged = false;

function addIslandData(data: PostSocketAssets["interactivity"]): void {
  if (!data) return;
  Object.assign(islandData.state, data.state);
  Object.assign(islandData.config, data.config);
  islandDataChanged = true;
}

const LOCK = "I acknowledge that using private APIs means my theme or plugin will inevitably break in the next version of WordPress.";

interface Hydration {
  toVdom: (node: Element) => unknown;
  render: (vdom: unknown, fragment: unknown) => void;
  getRegionRootFragment: (node: Element) => unknown;
  parseServerData?: () => { state?: Record<string, unknown>; config?: Record<string, unknown> };
  populateServerData?: (data: unknown) => void;
  initialVdomPromise?: Promise<WeakMap<Element, unknown>>;
}

let hydration: Promise<Hydration | null> | null = null;

/** The Interactivity API's private hydration helpers, or null when this WordPress lacks the ones used here. */
function hydrationApi(): Promise<Hydration | null> {
  hydration ??= import("@wordpress/interactivity")
    .then(({ privateApis }) => {
      const api = (privateApis?.(LOCK) ?? null) as Partial<Hydration> | null;
      return api && typeof api.toVdom === "function" && typeof api.render === "function" && typeof api.getRegionRootFragment === "function" ? (api as Hydration) : null;
    })
    .catch(() => null);
  return hydration;
}

/* Islands this module hydrated. */
const hydrated = new WeakSet<Element>();

/** Hydrate the outermost Interactivity API islands inside inserted markup, which the runtime hydrates only on DOM ready. */
async function hydrateIslands(roots: Element[]): Promise<void> {
  const islands = roots.flatMap((root) =>
    [root, ...Array.from(root.querySelectorAll("[data-wp-interactive]"))].filter((el) => {
      if (!el.matches("[data-wp-interactive]") || !el.isConnected) return false;
      const outer = el.parentElement?.closest("[data-wp-interactive]");
      return !outer || !root.contains(outer);
    }),
  );
  if (!islands.length) return;
  const api = await hydrationApi();
  if (!api) return;
  const initial = api.initialVdomPromise ? await api.initialVdomPromise : null;
  if (islandDataChanged && api.parseServerData && api.populateServerData) {
    const page = api.parseServerData();
    api.populateServerData({ state: { ...page.state, ...islandData.state }, config: { ...page.config, ...islandData.config } });
    islandDataChanged = false;
  }
  for (const island of islands) {
    if (hydrated.has(island) || initial?.has(island) || !island.isConnected) continue;
    hydrated.add(island);
    try {
      api.render(api.toVdom(island), api.getRegionRootFragment(island));
    } catch (err) {
      console.error(err);
    }
  }
}

/** Map the modules the page's import maps lack, and import the ones the markup enqueued. */
async function ensureModules(modules: PostSocketModule[]): Promise<void> {
  const mapped = new Set<string>();
  document.querySelectorAll('script[type="importmap"]').forEach((el) => {
    try {
      Object.keys(JSON.parse(el.textContent ?? "{}").imports ?? {}).forEach((id) => mapped.add(id));
    } catch {
      /* An unreadable map adds nothing. */
    }
  });
  const imports: Record<string, string> = {};
  for (const module of modules) if (!mapped.has(module.id)) imports[module.id] = sameScheme(module.src);
  if (Object.keys(imports).length) {
    const map = document.createElement("script");
    map.type = "importmap";
    map.textContent = JSON.stringify({ imports });
    document.head.appendChild(map);
  }
  const loads = modules
    .filter((module) => module.load && !document.getElementById(`${module.id}-js-module`))
    .map((module) => import(module.id).catch(() => import(sameScheme(module.src))));
  await Promise.allSettled(loads);
}

/** A short, stable key for a block of CSS. */
function cssKey(css: string): string {
  let h = 0x811c9dc5;
  for (let i = 0; i < css.length; i++) h = Math.imul(h ^ css.charCodeAt(i), 0x01000193);
  return (h >>> 0).toString(36);
}

/** Add CSS WordPress generated for inserted markup, once per distinct block of it. */
function addGeneratedCss(css: string | undefined): void {
  if (!css) return;
  const id = cssKey(css);
  if (document.head.querySelector(`style[data-postsocket-css="${id}"]`)) return;
  const style = document.createElement("style");
  style.dataset.postsocketCss = id;
  style.textContent = css;
  document.head.appendChild(style);
}

/** Add SVG filter definitions to the body, once per distinct markup. */
function addSvg(svg: string | undefined): void {
  if (!svg) return;
  const id = cssKey(svg);
  if (document.querySelector(`svg[data-postsocket-svg="${id}"]`)) return;
  for (const el of Array.from(fragment(svg).children)) {
    if (el.tagName.toLowerCase() !== "svg") continue;
    el.setAttribute("data-postsocket-svg", id);
    document.body.appendChild(el);
  }
}

/**
 * Add the stylesheets, scripts, script modules, generated CSS and SVG filters the document does not have yet, resolving once they have run.
 *
 * @since 0.1.0
 */
export async function ensureAssets(assets: PostSocketAssets | undefined): Promise<void> {
  if (!assets) return;
  addIslandData(assets.interactivity);
  const waits: Promise<void>[] = [];
  for (const style of assets.styles ?? []) {
    if (has(`${style.id}-css`, style.href, 'link[rel="stylesheet"]', "href")) continue;
    const link = document.createElement("link");
    link.rel = "stylesheet";
    link.id = `${style.id}-css`;
    link.href = sameScheme(style.href);
    waits.push(load(link));
  }
  const present = (script: PostSocketScript): boolean => has(`${script.id}-js`, script.src, "script[src]", "src") || Boolean(document.getElementById(`${script.id}-js-after`));
  const scripts = (assets.scripts ?? []).filter((script) => !present(script) || (script.rerun && script.src));
  for (const script of scripts) {
    if (!script.src || present(script)) continue;
    const hint = document.createElement("link");
    hint.rel = "preload";
    hint.as = "script";
    hint.href = sameScheme(script.src);
    document.head.appendChild(hint);
  }
  holdReadyHandlers();
  try {
    for (const script of scripts) {
      if (present(script)) {
        await rerun(script.src!);
        continue;
      }
      inline(`${script.id}-js-before`, script.before);
      if (script.src) {
        const el = document.createElement("script");
        el.id = `${script.id}-js`;
        el.src = sameScheme(script.src);
        el.async = false;
        await load(el);
      }
      inline(`${script.id}-js-after`, script.after);
    }
    await ensureModules(assets.modules ?? []);
  } finally {
    releaseReadyHandlers();
  }
  await Promise.all(waits);
  addGeneratedCss(assets.css);
  addSvg(assets.svg);
}

/**
 * Start inserted markup: run its inline scripts, the held ready handlers with document queries limited to it, hydrate its Interactivity API islands, and dispatch `postsocket:inserted` on each root.
 *
 * @since 0.1.0
 */
export function activateMarkup(target: Element | Element[]): void {
  const roots = Array.isArray(target) ? target : [target];
  for (const root of roots) {
    root.querySelectorAll("script").forEach((old) => {
      const fresh = document.createElement("script");
      for (const { name, value } of Array.from(old.attributes)) fresh.setAttribute(name, value);
      fresh.textContent = old.textContent;
      old.replaceWith(fresh);
    });
  }
  runReadyHandlers(roots);
  for (const root of roots) root.dispatchEvent(new CustomEvent("postsocket:inserted", { bubbles: true, detail: { root } }));
  void hydrateIslands(roots);
}

/**
 * Parse an HTML string into a fragment.
 *
 * @since 0.1.0
 */
export function fragment(html: string): DocumentFragment {
  const template = document.createElement("template");
  template.innerHTML = html;
  return template.content;
}
