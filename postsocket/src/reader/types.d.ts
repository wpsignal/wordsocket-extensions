/**
 * Shapes shared by the reader modules: the relay payloads and the routes' answers.
 *
 * @since 0.1.0
 */

/** Stylesheet and script URLs the inserted markup needs. */
interface PostSocketAssets {
  styles?: string[];
  scripts?: string[];
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
  html?: string;
  fetch?: boolean;
  assets?: PostSocketAssets;
}

/** `GET /postsocket/v1/content/{id}`. */
interface ContentResponse {
  id: number;
  modified: string;
  hash: string;
  html: string;
  assets?: PostSocketAssets;
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
