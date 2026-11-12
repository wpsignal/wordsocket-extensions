/**
 * Shapes shared by the reader modules: the relay payloads and the routes' answers.
 *
 * @since 0.1.0
 */

/** A classic script by handle, with the inline code WordPress prints around it. */
interface PostSocketScript {
  id: string;
  src?: string;
  before?: string;
  after?: string;
  /** Run again for each insertion while already on the page (`postsocket_rerun_blocks`). */
  rerun?: boolean;
}

/** A stylesheet by handle. */
interface PostSocketStyle {
  id: string;
  href: string;
}

/** A script module for the import map; `load` when the markup enqueued it rather than depending on it. */
interface PostSocketModule {
  id: string;
  src: string;
  load?: boolean;
}

/** What the inserted markup needs that a page load would have printed, dependencies first. */
interface PostSocketAssets {
  styles?: PostSocketStyle[];
  scripts?: PostSocketScript[];
  modules?: PostSocketModule[];
  /** Interactivity API state and config the render set, by namespace. */
  interactivity?: { state?: Record<string, unknown>; config?: Record<string, unknown> };
  /** CSS WordPress generated while rendering the markup: layout, elements, block style variations, duotone filters. */
  css?: string;
  /** The SVG filter definitions the markup's duotones point at. */
  svg?: string;
}

/** `postsocket.entry.updated`. */
interface EntryUpdated {
  id: number;
  liveblog: number;
  html?: string;
  fetch?: boolean;
  headline?: string;
  date: string;
  modified: string;
  pinned: boolean;
  author?: { name: string; avatar: string };
  assets?: PostSocketAssets;
}

/** `postsocket.entry.removed`. */
interface EntryRemoved {
  id: number;
  liveblog: number;
}

/** `postsocket.liveblog.state`. */
interface LiveblogState {
  id: number;
  state: "live" | "ended";
  ended: number;
}

/** `postsocket.post.updated`. */
interface PostUpdated {
  id: number;
  modified: string;
  hash: string;
  /** The post's title as plain text. */
  title?: string;
  html?: string;
  fetch?: boolean;
  assets?: PostSocketAssets;
  /** The post's live fields as HTML, by name. */
  fields?: Record<string, string>;
  /** The payload carries no content: reload the page. */
  reload?: boolean;
  /** Nothing the payload carries changed: fetch the page again and morph the part `postsocket_refresh_selector` names. */
  refresh?: boolean;
}

/** `GET /postsocket/v1/content/{id}`. */
interface ContentResponse {
  id: number;
  modified: string;
  hash: string;
  title?: string;
  html: string;
  assets?: PostSocketAssets;
  fields?: Record<string, string>;
}

/** The detail of the `postsocket:updated` DOM event, dispatched on `document` after a living post's update is applied. */
interface PostSocketUpdatedDetail {
  id: number;
  title: string;
  fields: Record<string, string>;
  /** True when the update was a page refresh. */
  refreshed?: boolean;
}

/** One item of `GET /postsocket/v1/liveblog/{id}/entries`. */
interface EntryItem {
  id: number;
  html: string;
  date: string;
  modified: string;
  pinned: boolean;
  assets?: PostSocketAssets;
}

/** `GET /postsocket/v1/liveblog/{id}/entries`. */
interface EntriesResponse {
  liveblog: number;
  state: "live" | "ended";
  entries: EntryItem[];
  more: boolean;
}
