/**
 * Assets for markup inserted without a page load: stylesheets and scripts the page lacks, and the scripts inside the markup itself.
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

function present(selector: string, attr: string): Set<string> {
  const keys = new Set<string>();
  document.querySelectorAll<HTMLElement>(selector).forEach((el) => {
    const value = el.getAttribute(attr);
    if (value) keys.add(key(value));
  });
  return keys;
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

/**
 * Add the stylesheets and scripts the document does not have yet, resolving once they have loaded.
 *
 * @since 0.1.0
 */
export async function ensureAssets(assets: PostSocketAssets | undefined): Promise<void> {
  if (!assets) return;
  const styles = present('link[rel="stylesheet"]', "href");
  const scripts = present("script[src]", "src");
  const waits: Promise<void>[] = [];
  for (const href of assets.styles ?? []) {
    if (styles.has(key(href))) continue;
    styles.add(key(href));
    const link = document.createElement("link");
    link.rel = "stylesheet";
    link.href = sameScheme(href);
    waits.push(load(link));
  }
  for (const src of assets.scripts ?? []) {
    if (scripts.has(key(src))) continue;
    scripts.add(key(src));
    const script = document.createElement("script");
    script.src = sameScheme(src);
    script.async = false;
    waits.push(load(script));
  }
  await Promise.all(waits);
}

/**
 * Re-create the `<script>` elements inside inserted markup so they run; `innerHTML` leaves them inert.
 *
 * @since 0.1.0
 */
export function activateScripts(root: ParentNode): void {
  root.querySelectorAll("script").forEach((old) => {
    const fresh = document.createElement("script");
    for (const { name, value } of Array.from(old.attributes)) fresh.setAttribute(name, value);
    fresh.textContent = old.textContent;
    old.replaceWith(fresh);
  });
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
